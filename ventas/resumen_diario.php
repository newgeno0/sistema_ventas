<?php
// Habilitar visualización de errores
ini_set('display_errors', 1);
error_reporting(E_ALL);

include 'includes/db.php';

// Configuración de fecha
$fecha = isset($_GET['fecha']) ? $_GET['fecha'] : date('Y-m-d');
$fecha_anterior = date('Y-m-d', strtotime($fecha . ' -1 day'));

try {
    // Consulta consolidada para resumen general
    $stmt = $conn->prepare("
        SELECT 
            COUNT(v.id) AS total_ventas,
            SUM(v.total) AS monto_total,
            (
                SELECT SUM(v2.total) 
                FROM ventas v2 
                WHERE DATE(v2.fecha_hora) = ?
            ) AS monto_anterior,
            (
                SELECT COUNT(v2.id)
                FROM ventas v2
                WHERE DATE(v2.fecha_hora) = ?
            ) AS ventas_anterior
        FROM ventas v
        WHERE DATE(v.fecha_hora) = ?
    ");
    if (!$stmt) {
        throw new Exception("Error al preparar consulta de resumen: " . $conn->error);
    }
    $stmt->bind_param('sss', $fecha_anterior, $fecha_anterior, $fecha);
    if (!$stmt->execute()) {
        throw new Exception("Error al ejecutar consulta de resumen: " . $stmt->error);
    }
    $resumen = $stmt->get_result()->fetch_assoc();

    // Ventas por categoría
    $stmt_categorias = $conn->prepare("
        SELECT 
            c.nombre AS categoria,
            SUM(dv.precio * dv.cantidad) AS total,
            COUNT(DISTINCT dv.venta_id) AS cantidad_ventas,
            ROUND(SUM(dv.precio * dv.cantidad) * 100 / ?, 1) AS porcentaje
        FROM detalles_venta dv
        INNER JOIN productos p ON dv.producto_id = p.id
        INNER JOIN categorias c ON p.categoria_id = c.id
        INNER JOIN ventas v ON dv.venta_id = v.id
        WHERE DATE(v.fecha_hora) = ?
        GROUP BY c.nombre
        ORDER BY total DESC
    ");
    if (!$stmt_categorias) {
        throw new Exception("Error al preparar consulta de categorías: " . $conn->error);
    }
    $total_dia = $resumen['monto_total'] ?? 1;
    $stmt_categorias->bind_param('ds', $total_dia, $fecha);
    if (!$stmt_categorias->execute()) {
        throw new Exception("Error al ejecutar consulta de categorías: " . $stmt_categorias->error);
    }
    $categorias = $stmt_categorias->get_result();

    // Ventas por hora (6:00 AM - 10:00 PM)
    $sql_ventas_hora = "
        SELECT 
            HOUR(fecha_hora) AS hora,
            SUM(total) AS total_hora
        FROM ventas
        WHERE DATE(fecha_hora) = ?
        AND HOUR(fecha_hora) BETWEEN 6 AND 22
        GROUP BY HOUR(fecha_hora)
        ORDER BY hora ASC
    ";
    $stmt_horas = $conn->prepare($sql_ventas_hora);
    if (!$stmt_horas) {
        throw new Exception("Error al preparar consulta de horas: " . $conn->error);
    }
    $stmt_horas->bind_param('s', $fecha);
    if (!$stmt_horas->execute()) {
        throw new Exception("Error al ejecutar consulta de horas: " . $stmt_horas->error);
    }
    $ventas_por_hora = $stmt_horas->get_result()->fetch_all(MYSQLI_ASSOC);

    // Preparar datos para gráficos
    $horas = range(6, 22);
    $datos_grafico = array_fill(0, count($horas), 0);
    $hay_datos_horas = !empty($ventas_por_hora);

    foreach ($ventas_por_hora as $venta) {
        $indice = $venta['hora'] - 6;
        $datos_grafico[$indice] = (float)$venta['total_hora'];
    }

    // Últimas transacciones
    $stmt_ventas = $conn->prepare("
        SELECT 
            v.id,
            DATE_FORMAT(v.fecha_hora, '%H:%i') AS hora,
            v.total,
            COUNT(dv.id) AS items
        FROM ventas v
        LEFT JOIN detalles_venta dv ON v.id = dv.venta_id
        WHERE DATE(v.fecha_hora) = ?
        GROUP BY v.id
        ORDER BY v.fecha_hora DESC
        LIMIT 5
    ");
    if (!$stmt_ventas) {
        throw new Exception("Error al preparar consulta de últimas ventas: " . $conn->error);
    }
    $stmt_ventas->bind_param('s', $fecha);
    if (!$stmt_ventas->execute()) {
        throw new Exception("Error al ejecutar consulta de últimas ventas: " . $stmt_ventas->error);
    }
    $ventas = $stmt_ventas->get_result();

} catch (Exception $e) {
    die("<div class='alert alert-danger'>Error crítico: " . $e->getMessage() . "</div>");
}
?>

<div class="row">
    <!-- Resumen General -->
    <div class="col-md-4 mb-4">
        <div class="card h-100">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Resumen del Día</h5>
                <span><?= date('d/m/Y', strtotime($fecha)) ?></span>
            </div>
            <div class="card-body">
                <div class="list-group">
                    <div class="list-group-item">
                        <div class="d-flex justify-content-between mb-2">
                            <strong>Total Ventas:</strong>
                            <span class="badge bg-primary rounded-pill">
                                <?= $resumen['total_ventas'] ?? 0 ?>
                            </span>
                        </div>
                        <small class="text-muted">
                            <?= isset($resumen['ventas_anterior']) ? '↑ ' . ($resumen['total_ventas'] - $resumen['ventas_anterior']) : 'Sin datos previos' ?>
                        </small>
                    </div>
                    
                    <div class="list-group-item">
                        <div class="d-flex justify-content-between mb-2">
                            <strong>Monto Total:</strong>
                            <span class="badge bg-success rounded-pill">
                                $<?= number_format($resumen['monto_total'] ?? 0, 2) ?>
                            </span>
                        </div>
                        <small class="text-muted">
                            <?= isset($resumen['monto_anterior']) ? '↑ $' . number_format($resumen['monto_total'] - $resumen['monto_anterior'], 2) : 'Sin datos previos' ?>
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Ventas por Categoría -->
    <div class="col-md-4 mb-4">
        <div class="card h-100">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0">Desglose por Categoría</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th>Categoría</th>
                                <th class="text-right">Ventas</th>
                                <th class="text-right">Total</th>
                                <th class="text-right">%</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($categorias->num_rows > 0): ?>
                                <?php $categorias->data_seek(0); ?>
                                <?php while ($cat = $categorias->fetch_assoc()): ?>
                                    <tr>
                                        <td><?= htmlspecialchars(ucfirst($cat['categoria'])) ?></td>
                                        <td class="text-right"><?= $cat['cantidad_ventas'] ?></td>
                                        <td class="text-right">$<?= number_format($cat['total'], 2) ?></td>
                                        <td class="text-right"><?= $cat['porcentaje'] ?>%</td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text-center py-4">
                                        <div class="alert alert-warning mb-0">
                                            <i class="fas fa-exclamation-circle"></i> No hay ventas en esta fecha
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Últimas Ventas -->
    <div class="col-md-4 mb-4">
        <div class="card h-100">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0">Últimas Transacciones</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th>Hora</th>
                                <th class="text-right">Items</th>
                                <th class="text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($ventas->num_rows > 0): ?>
                                <?php $ventas->data_seek(0); ?>
                                <?php while ($venta = $ventas->fetch_assoc()): ?>
                                    <tr class="clickable-row" data-href="/ventas/ventas/editar_venta.php?id=<?= $venta['id'] ?>">
                                        <td><?= htmlspecialchars($venta['hora']) ?></td>
                                        <td class="text-right"><?= $venta['items'] ?></td>
                                        <td class="text-right">$<?= number_format($venta['total'], 2) ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="3" class="text-center py-4">
                                        <div class="alert alert-warning mb-0">
                                            <i class="fas fa-exclamation-circle"></i> No hay transacciones
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Gráficos -->
<div class="row">
    <div class="col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-header bg-warning text-white">
                <h5 class="mb-0">Distribución por Categoría</h5>
            </div>
            <div class="card-body">
                <canvas id="chartCategorias" height="250"></canvas>
            </div>
        </div>
    </div>
    
    <div class="col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Ventas por Hora (6AM - 10PM)</h5>
                <form method="get" class="form-inline">
                    <div class="form-group mb-0 ml-2">
                        <input type="date" class="form-control form-control-sm" 
                               name="fecha" value="<?= htmlspecialchars($fecha) ?>"
                               max="<?= date('Y-m-d') ?>">
                    </div>
                    <button type="submit" class="btn btn-light btn-sm ml-2">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                </form>
            </div>
            <div class="card-body">
                <?php if ($hay_datos_horas): ?>
                    <canvas id="chartVentasHora" height="250"></canvas>
                <?php else: ?>
                    <div class="alert alert-info">
                        No hay datos de ventas por hora para <?= date('d/m/Y', strtotime($fecha)) ?>.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Gráfico de categorías
    const categoriasData = [
        <?php 
        $categorias->data_seek(0);
        while ($cat = $categorias->fetch_assoc()): 
            echo "{nombre: '" . addslashes($cat['categoria']) . "', total: " . $cat['total'] . "},";
        endwhile; 
        ?>
    ];

    if (categoriasData.length > 0) {
        const ctxCategorias = document.getElementById('chartCategorias').getContext('2d');
        new Chart(ctxCategorias, {
            type: 'doughnut',
            data: {
                labels: categoriasData.map(c => c.nombre),
                datasets: [{
                    data: categoriasData.map(c => c.total),
                    backgroundColor: [
                        '#4e73df', '#1cc88a', '#36b9cc', '#f6c23e', '#e74a3b', '#6c757d'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.raw || 0;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = Math.round((value / total) * 100);
                                return `${label}: $${value.toFixed(2)} (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });
    }

    // Gráfico de ventas por hora
    <?php if ($hay_datos_horas): ?>
        const ctxVentasHora = document.getElementById('chartVentasHora').getContext('2d');
        new Chart(ctxVentasHora, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_map(fn($h) => ($h < 10 ? '0' : '') . $h . ':00', $horas)) ?>,
                datasets: [{
                    label: 'Ventas ($)',
                    data: <?= json_encode($datos_grafico) ?>,
                    backgroundColor: 'rgba(220, 53, 69, 0.7)',
                    borderColor: 'rgba(220, 53, 69, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '$' + value.toLocaleString();
                            }
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return 'Total: $' + context.raw.toLocaleString();
                            }
                        }
                    }
                }
            }
        });
    <?php endif; ?>

    // Hacer filas clickeables
    document.querySelectorAll('.clickable-row').forEach(row => {
        row.addEventListener('click', () => {
            window.location.href = row.dataset.href;
        });
    });
});
</script>