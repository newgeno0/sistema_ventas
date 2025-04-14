<?php
include 'includes/db.php';

// Obtener parámetros de filtrado
$fecha_inicio = isset($_GET['fecha_inicio']) ? $_GET['fecha_inicio'] : date('Y-m-01');
$fecha_fin = isset($_GET['fecha_fin']) ? $_GET['fecha_fin'] : date('Y-m-d');
$top = isset($_GET['top']) ? intval($_GET['top']) : 10;
$categorias_seleccionadas = isset($_GET['categorias']) ? $_GET['categorias'] : ['producto', 'servicio', 'impresion', 'aportacion'];

// Validar categorías
$categorias_validas = ['producto', 'servicio', 'impresion', 'aportacion'];
$categorias_filtro = array_intersect($categorias_seleccionadas, $categorias_validas);
if (empty($categorias_filtro)) {
    $categorias_filtro = $categorias_validas;
}

// Preparar parámetros para consulta
$parametros = [$fecha_inicio, $fecha_fin];
$placeholders = array_merge($parametros, $categorias_filtro);

// Consulta SQL para reporte mensual (CORREGIDA)
$sql_ventas = "SELECT 
                  DATE(v.fecha_hora) AS fecha,
                  SUM(dv.precio * dv.cantidad) AS total_diario,
                  SUM(CASE WHEN c.nombre = 'producto' THEN dv.precio * dv.cantidad ELSE 0 END) AS productos,
                  SUM(CASE WHEN c.nombre = 'servicio' THEN dv.precio * dv.cantidad ELSE 0 END) AS servicios,
                  SUM(CASE WHEN c.nombre = 'impresion' THEN dv.precio * dv.cantidad ELSE 0 END) AS impresiones,
                  SUM(CASE WHEN c.nombre = 'aportacion' THEN dv.precio * dv.cantidad ELSE 0 END) AS aportaciones
               FROM ventas v
               JOIN detalles_venta dv ON v.id = dv.venta_id
               JOIN productos p ON dv.producto_id = p.id
               JOIN categorias c ON p.categoria_id = c.id
               WHERE DATE(v.fecha_hora) BETWEEN ? AND ?
               AND c.nombre IN (".str_repeat('?,', count($categorias_filtro) - 1)."?)
               GROUP BY DATE(v.fecha_hora)
               ORDER BY fecha DESC";

$stmt_ventas = $conn->prepare($sql_ventas);
$stmt_ventas->bind_param(str_repeat('s', count($placeholders)), ...$placeholders);
$stmt_ventas->execute();
$ventas = $stmt_ventas->get_result()->fetch_all(MYSQLI_ASSOC);

// Consulta para productos más vendidos
$sql_productos = "SELECT 
                     p.id,
                     p.nombre,
                     c.nombre AS categoria,
                     SUM(dv.cantidad) AS total_vendido,
                     SUM(dv.cantidad * dv.precio) AS ingreso_total,
                     COUNT(DISTINCT dv.venta_id) AS veces_vendido
                  FROM detalles_venta dv
                  JOIN productos p ON dv.producto_id = p.id
                  JOIN categorias c ON p.categoria_id = c.id
                  JOIN ventas v ON dv.venta_id = v.id
                  WHERE DATE(v.fecha_hora) BETWEEN ? AND ?
                  AND c.nombre IN (".str_repeat('?,', count($categorias_filtro) - 1)."?)
                  GROUP BY p.id
                  ORDER BY total_vendido DESC
                  ".($top > 0 ? "LIMIT $top" : "");

$stmt_productos = $conn->prepare($sql_productos);
$stmt_productos->bind_param(str_repeat('s', count($placeholders)), ...$placeholders);
$stmt_productos->execute();
$productos_vendidos = $stmt_productos->get_result()->fetch_all(MYSQLI_ASSOC);

// Obtener lista de meses disponibles
$sql_meses = "SELECT DISTINCT DATE_FORMAT(fecha_hora, '%Y-%m') AS mes 
              FROM ventas ORDER BY mes DESC";
$meses_disponibles = $conn->query($sql_meses)->fetch_all(MYSQLI_ASSOC);

// Calcular totales verificados (CORRECCIÓN IMPORTANTE)
$total_verificado = 0;
foreach ($ventas as $venta) {
    $total_verificado += $venta['total_diario'];
}

// Calcular totales generales (VERSIÓN CORREGIDA)
$totales = [
    'unidades' => array_sum(array_column($productos_vendidos, 'total_vendido')),
    'ingresos' => $total_verificado, // Usamos el total verificado
    'productos_distintos' => count($productos_vendidos)
];

// Verificar discrepancia con totales de ventas (si existiera)
$mostrar_advertencia = false;
$sql_total_general = "SELECT SUM(total) AS total FROM ventas WHERE DATE(fecha_hora) BETWEEN ? AND ?";
$stmt_total = $conn->prepare($sql_total_general);
$stmt_total->bind_param('ss', $fecha_inicio, $fecha_fin);
$stmt_total->execute();
$total_general = $stmt_total->get_result()->fetch_assoc()['total'];

if (abs($total_verificado - $total_general) > 0.01) {
    $mostrar_advertencia = true;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reportes Avanzados</title>
    <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .card-header {
            font-weight: 600;
        }
        .table th {
            background-color: #343a40;
            color: white;
        }
        .totales-card {
            border-left: 4px solid #28a745;
        }
        .clickable-row {
            cursor: pointer;
        }
        .clickable-row:hover {
            background-color: #f8f9fa;
        }
        .discrepancia-total {
            border: 2px solid #ffc107;
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(255, 193, 7, 0.4); }
            70% { box-shadow: 0 0 0 10px rgba(255, 193, 7, 0); }
            100% { box-shadow: 0 0 0 0 rgba(255, 193, 7, 0); }
        }
    </style>
</head>
			<a href="index.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Volver a Home
            </a>
<body>
    <div class="container-fluid mt-4">
        <h2 class="mb-4"><i class="fas fa-chart-bar"></i> Reportes Avanzados</h2>

        
        <!-- Filtros Avanzados -->
        <div class="card mb-4">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0"><i class="fas fa-filter"></i> Filtros Avanzados</h5>
			
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <!-- Selector de rango predefinido -->
                    <div class="col-md-3">
                        <label class="form-label">Rango Predefinido</label>
                        <select name="rango" class="form-select" onchange="actualizarFechas(this.value)">
                            <option value="semana">Última Semana</option>
                            <option value="mes" selected>Último Mes</option>
                            <option value="trimestre">Último Trimestre</option>
                            <option value="personalizado">Personalizado</option>
                        </select>
                    </div>
                    
                    <!-- Selector de fechas manual -->
                    <div class="col-md-4">
                        <div class="row">
                            <div class="col-md-6">
                                <label class="form-label">Desde</label>
                                <input type="date" name="fecha_inicio" class="form-control" value="<?= $fecha_inicio ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Hasta</label>
                                <input type="date" name="fecha_fin" class="form-control" value="<?= $fecha_fin ?>">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Filtro por categorías -->
                    <div class="col-md-3">
                        <label class="form-label">Filtrar por categorías:</label>
                        <div class="d-flex flex-wrap">
                            <?php foreach ($categorias_validas as $categoria): ?>
                                <div class="form-check mr-3">
                                    <input class="form-check-input" type="checkbox" name="categorias[]" 
                                           value="<?= $categoria ?>" id="check-<?= $categoria ?>"
                                           <?= in_array($categoria, $categorias_filtro) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="check-<?= $categoria ?>">
                                        <?= ucfirst($categoria) ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Filtro por top N -->
                    <div class="col-md-2">
                        <label class="form-label">Top Productos</label>
                        <select name="top" class="form-select">
                            <option value="5" <?= $top == 5 ? 'selected' : '' ?>>Top 5</option>
                            <option value="10" <?= $top == 10 ? 'selected' : '' ?>>Top 10</option>
                            <option value="20" <?= $top == 20 ? 'selected' : '' ?>>Top 20</option>
                            <option value="50" <?= $top == 50 ? 'selected' : '' ?>>Top 50</option>
                            <option value="0" <?= $top == 0 ? 'selected' : '' ?>>Todos</option>
                        </select>
                    </div>
                    
                    <div class="col-md-12 mt-3">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-sync-alt"></i> Aplicar Filtros
                        </button>
                        <a href="reportes.php" class="btn btn-secondary ml-2">
                            <i class="fas fa-times"></i> Limpiar Filtros
                        </a>
                        <a href="exportar.php?tipo=productos&fecha_inicio=<?= $fecha_inicio ?>&fecha_fin=<?= $fecha_fin ?>&top=<?= $top ?>" 
                           class="btn btn-success ml-2">
                            <i class="fas fa-file-excel"></i> Exportar a Excel
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Resumen de Totales -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card totales-card h-100 <?= $mostrar_advertencia ? 'discrepancia-total' : '' ?>">
                    <div class="card-body">
                        <h5 class="card-title"><i class="fas fa-cubes"></i> Resumen de Ventas</h5>
                        <?php if ($mostrar_advertencia): ?>
                        <div class="alert alert-warning mb-3">
                            <i class="fas fa-exclamation-triangle"></i> Nota: Se detectó una discrepancia con los totales almacenados. Mostrando totales calculados desde los detalles.
                        </div>
                        <?php endif; ?>
                        <div class="d-flex justify-content-between">
                            <div>
                                <h6 class="text-muted">Unidades Vendidas</h6>
                                <h3><?= number_format($totales['unidades']) ?></h3>
                            </div>
                            <div>
                                <h6 class="text-muted">Ingresos Totales</h6>
                                <h3>$<?= number_format($totales['ingresos'], 2) ?></h3>
                            </div>
                            <div>
                                <h6 class="text-muted">Productos Distintos</h6>
                                <h3><?= $totales['productos_distintos'] ?></h3>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body">
                        <h5 class="card-title"><i class="fas fa-calendar-alt"></i> Rango Seleccionado</h5>
                        <div class="d-flex justify-content-between">
                            <div>
                                <h6 class="text-muted">Fecha Inicio</h6>
                                <h4><?= date('d/m/Y', strtotime($fecha_inicio)) ?></h4>
                            </div>
                            <div>
                                <h6 class="text-muted">Fecha Fin</h6>
                                <h4><?= date('d/m/Y', strtotime($fecha_fin)) ?></h4>
                            </div>
                            <div>
                                <h6 class="text-muted">Días</h6>
                                <h4><?= (new DateTime($fecha_inicio))->diff(new DateTime($fecha_fin))->days + 1 ?></h4>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Gráfico de productos más vendidos -->
        <div class="card mb-4">
            <div class="card-header bg-warning text-white">
                <h5 class="mb-0"><i class="fas fa-star"></i> Productos Más Vendidos (Top <?= $top > 0 ? $top : 'Todos' ?>)</h5>
            </div>
            <div class="card-body">
                <canvas id="graficoProductos" height="400"></canvas>
            </div>
        </div>

        <!-- Tabla detallada de productos -->
        <div class="card mb-4">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0"><i class="fas fa-table"></i> Detalle por Producto</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Producto</th>
                                <th>Categoría</th>
                                <th class="text-end">Unidades Vendidas</th>
                                <th class="text-end">Ingreso Total</th>
                                <th class="text-end">Veces Vendido</th>
                                <th class="text-end">Promedio por Venta</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($productos_vendidos as $index => $producto): ?>
                            <tr class="clickable-row" data-href="detalle_producto.php?id=<?= $producto['id'] ?>&fecha_inicio=<?= $fecha_inicio ?>&fecha_fin=<?= $fecha_fin ?>">
                                <td><?= $index + 1 ?></td>
                                <td><?= htmlspecialchars($producto['nombre']) ?></td>
                                <td><?= ucfirst($producto['categoria']) ?></td>
                                <td class="text-end"><?= number_format($producto['total_vendido']) ?></td>
                                <td class="text-end">$<?= number_format($producto['ingreso_total'], 2) ?></td>
                                <td class="text-end"><?= number_format($producto['veces_vendido']) ?></td>
                                <td class="text-end"><?= number_format($producto['total_vendido'] / $producto['veces_vendido'], 1) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Gráfico de ventas por día -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="fas fa-chart-line"></i> Ventas por Día</h5>
            </div>
            <div class="card-body">
                <canvas id="graficoVentas" height="400"></canvas>
            </div>
        </div>

        <!-- Tabla de ventas diarias -->
        <div class="card">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0"><i class="fas fa-calendar-day"></i> Detalle por Día</h5>
            </div>
            <div class="card-body">
                <?php if ($mostrar_advertencia): ?>
                <div class="alert alert-warning mb-3">
                    <i class="fas fa-exclamation-triangle"></i> Los totales mostrados son calculados directamente desde los detalles de venta para garantizar precisión con los filtros aplicados.
                </div>
                <?php endif; ?>
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th class="text-end">Total</th>
                                <th class="text-end">Productos</th>
                                <th class="text-end">Servicios</th>
                                <th class="text-end">Impresiones</th>
                                <th class="text-end">Aportaciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ventas as $venta): ?>
                            <tr class="clickable-row" data-href="resumen_dia.php?fecha=<?= $venta['fecha'] ?>">
                                <td><?= date('d/m/Y', strtotime($venta['fecha'])) ?></td>
                                <td class="text-end">$<?= number_format($venta['total_diario'], 2) ?></td>
                                <td class="text-end">$<?= number_format($venta['productos'], 2) ?></td>
                                <td class="text-end">$<?= number_format($venta['servicios'], 2) ?></td>
                                <td class="text-end">$<?= number_format($venta['impresiones'], 2) ?></td>
                                <td class="text-end">$<?= number_format($venta['aportaciones'], 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <!-- Fila de totales -->
                            <tr style="background-color: #f8f9fa; font-weight: bold;">
                                <td>Total General</td>
                                <td class="text-end">$<?= number_format($total_verificado, 2) ?></td>
                                <td class="text-end">$<?= number_format(array_sum(array_column($ventas, 'productos')), 2) ?></td>
                                <td class="text-end">$<?= number_format(array_sum(array_column($ventas, 'servicios')), 2) ?></td>
                                <td class="text-end">$<?= number_format(array_sum(array_column($ventas, 'impresiones')), 2) ?></td>
                                <td class="text-end">$<?= number_format(array_sum(array_column($ventas, 'aportaciones')), 2) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Autoajustar fechas al seleccionar rango
    function actualizarFechas(rango) {
        const fechaFin = new Date();
        let fechaInicio = new Date();
        
        switch(rango) {
            case 'semana':
                fechaInicio.setDate(fechaInicio.getDate() - 7);
                break;
            case 'mes':
                fechaInicio.setMonth(fechaInicio.getMonth() - 1);
                break;
            case 'trimestre':
                fechaInicio.setMonth(fechaInicio.getMonth() - 3);
                break;
            case 'personalizado':
                return; // No cambia las fechas
        }
        
        // Formatear a YYYY-MM-DD
        document.querySelector('[name="fecha_inicio"]').value = fechaInicio.toISOString().split('T')[0];
        document.querySelector('[name="fecha_fin"]').value = fechaFin.toISOString().split('T')[0];
    }

    // Gráfico de productos más vendidos
    const ctxProductos = document.getElementById('graficoProductos').getContext('2d');
    new Chart(ctxProductos, {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_column($productos_vendidos, 'nombre')) ?>,
            datasets: [
                {
                    label: 'Unidades Vendidas',
                    data: <?= json_encode(array_column($productos_vendidos, 'total_vendido')) ?>,
                    backgroundColor: 'rgba(255, 159, 64, 0.7)',
                    borderColor: 'rgba(255, 159, 64, 1)',
                    borderWidth: 1,
                    yAxisID: 'y'
                },
                {
                    label: 'Ingreso Total ($)',
                    data: <?= json_encode(array_column($productos_vendidos, 'ingreso_total')) ?>,
                    backgroundColor: 'rgba(54, 162, 235, 0.7)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 1,
                    type: 'line',
                    yAxisID: 'y1'
                }
            ]
        },
        options: {
            responsive: true,
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) {
                                label += ': ';
                            }
                            if (context.datasetIndex === 0) {
                                label += context.raw.toLocaleString() + ' unidades';
                            } else {
                                label += '$' + context.raw.toLocaleString(undefined, {minimumFractionDigits: 2});
                            }
                            return label;
                        }
                    }
                }
            },
            scales: {
                y: {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    title: {
                        display: true,
                        text: 'Unidades Vendidas'
                    }
                },
                y1: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    title: {
                        display: true,
                        text: 'Ingreso Total ($)'
                    },
                    grid: {
                        drawOnChartArea: false
                    }
                }
            }
        }
    });

    // Gráfico de ventas por día
    const ctxVentas = document.getElementById('graficoVentas').getContext('2d');
    new Chart(ctxVentas, {
        type: 'line',
        data: {
            labels: <?= json_encode(array_map(function($v) { return date('d/m', strtotime($v['fecha'])); }, $ventas)) ?>,
            datasets: [
                {
                    label: 'Total Ventas ($)',
                    data: <?= json_encode(array_column($ventas, 'total_diario')) ?>,
                    backgroundColor: 'rgba(40, 167, 69, 0.2)',
                    borderColor: 'rgba(40, 167, 69, 1)',
                    borderWidth: 2,
                    tension: 0.1
                }
            ]
        },
        options: {
            responsive: true,
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return 'Total: $' + context.raw.toLocaleString(undefined, {minimumFractionDigits: 2});
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return '$' + value.toLocaleString();
                        }
                    }
                }
            }
        }
    });

    // Hacer filas clickeables
    $(document).ready(function() {
        $('.clickable-row').click(function() {
            window.location.href = $(this).data('href');
        });
    });
    </script>
</body>
</html>