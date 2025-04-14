<?php
include 'includes/db.php';

// Validar fecha recibida
$fecha = isset($_GET['fecha']) ? $_GET['fecha'] : date('Y-m-d');
if (!DateTime::createFromFormat('Y-m-d', $fecha)) {
    die("Fecha inválida");
}

// Consulta para resumen del día
$sql = "SELECT 
            v.id,
            DATE_FORMAT(v.fecha_hora, '%H:%i') AS hora,
            v.total,
            GROUP_CONCAT(CONCAT(p.nombre, ' (', dv.cantidad, ' x $', dv.precio, ')') SEPARATOR ', ') AS productos
        FROM ventas v
        JOIN detalles_venta dv ON v.id = dv.venta_id
        JOIN productos p ON dv.producto_id = p.id
        WHERE DATE(v.fecha_hora) = ?
        GROUP BY v.id
        ORDER BY v.fecha_hora DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $fecha);
$stmt->execute();
$ventas = $stmt->get_result();

// Consulta para totales por categoría
$sql_categorias = "SELECT 
                    c.nombre AS categoria,
                    SUM(dv.precio * dv.cantidad) AS total
                  FROM detalles_venta dv
                  JOIN productos p ON dv.producto_id = p.id
                  JOIN categorias c ON p.categoria_id = c.id
                  JOIN ventas v ON dv.venta_id = v.id
                  WHERE DATE(v.fecha_hora) = ?
                  GROUP BY c.nombre";
$stmt_cat = $conn->prepare($sql_categorias);
$stmt_cat->bind_param('s', $fecha);
$stmt_cat->execute();
$categorias = $stmt_cat->get_result();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Resumen del día <?= $fecha ?></title>
    <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .clickable-row {
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .clickable-row:hover {
            background-color: #f8f9fa;
        }
        .badge-categoria {
            font-size: 0.9em;
            padding: 5px 10px;
        }
    </style>
</head>
<body>
    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Resumen del día <?= date('d/m/Y', strtotime($fecha)) ?></h2>
			<a href="index.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Volver a Home
            </a>
            <a href="reportes.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Volver a reportes
            </a>
        </div>

        <!-- Resumen por categorías -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Totales por Categoría</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <?php while ($cat = $categorias->fetch_assoc()): ?>
                        <div class="col-md-3 mb-2">
                            <div class="card">
                                <div class="card-body">
                                    <h6 class="card-title"><?= ucfirst($cat['categoria']) ?></h6>
                                    <p class="card-text h4">$<?= number_format($cat['total'], 2) ?></p>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            </div>
        </div>

        <!-- Detalle de ventas -->
        <div class="card">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0">Detalle de Ventas</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th>Hora</th>
                                <th>Productos</th>
                                <th class="text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($ventas->num_rows > 0): ?>
                                <?php while ($venta = $ventas->fetch_assoc()): ?>
                                    <tr class="clickable-row" data-href="/ventas/ventas/editar_venta.php?id=<?= $venta['id'] ?>">
                                        <td><?= $venta['hora'] ?></td>
                                        <td><?= $venta['productos'] ?></td>
                                        <td class="text-right">$<?= number_format($venta['total'], 2) ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="3" class="text-center py-4">
                                        <div class="alert alert-warning mb-0">
                                            No hay ventas registradas para este día
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

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Hacer filas clickeables
    $(document).ready(function() {
        $('.clickable-row').click(function() {
            window.location.href = $(this).data('href');
        });
    });
    </script>
</body>
</html>