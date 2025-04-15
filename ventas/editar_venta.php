<?php
session_start();
// Ajustar la ruta de inclusión según tu estructura de carpetas
include __DIR__ . '/../includes/db.php';



// Validar ID de venta
$venta_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($venta_id <= 0) {
    header("Location: ../index.php");
    exit;
}

// Obtener información base de la venta
$venta = [];
$detalles = [];
$productos_disponibles = [];

try {
    // Consulta para obtener datos de la venta
    $stmt = $conn->prepare("
        SELECT id, fecha_hora, total 
        FROM ventas 
        WHERE id = ?
    ");
    $stmt->bind_param('i', $venta_id);
    $stmt->execute();
    $venta = $stmt->get_result()->fetch_assoc();

    if (!$venta) {
        throw new Exception("Venta no encontrada");
    }

    // Consulta para detalles de la venta
    $stmt_detalles = $conn->prepare("
        SELECT 
            dv.id,
            p.id AS producto_id,
            p.nombre AS producto,
            p.precio AS precio_original,
            dv.cantidad,
            dv.precio,
            c.nombre AS categoria
        FROM detalles_venta dv
        JOIN productos p ON dv.producto_id = p.id
        JOIN categorias c ON p.categoria_id = c.id
        WHERE dv.venta_id = ?
    ");
    $stmt_detalles->bind_param('i', $venta_id);
    $stmt_detalles->execute();
    $detalles = $stmt_detalles->get_result()->fetch_all(MYSQLI_ASSOC);

    // Consulta para productos disponibles
    $productos_disponibles = $conn->query("
        SELECT p.id, p.nombre, p.precio, c.nombre AS categoria 
        FROM productos p
        JOIN categorias c ON p.categoria_id = c.id
        ORDER BY p.nombre
    ")->fetch_all(MYSQLI_ASSOC);

} catch (Exception $e) {
    die("<div class='alert alert-danger'>Error: " . $e->getMessage() . "</div>");
}

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn->begin_transaction();
    
    try {
        // Procesar eliminación de venta
        if (isset($_POST['eliminar_venta'])) {
            $stmt = $conn->prepare("DELETE FROM ventas WHERE id = ?");
            $stmt->bind_param('i', $venta_id);
            $stmt->execute();
            
            $conn->commit();
            $_SESSION['mensaje'] = "Venta eliminada correctamente";
            header("Location: ../index.php");
            exit;
        }

        // Procesar actualización de detalles existentes
        if (isset($_POST['detalles'])) {
            foreach ($_POST['detalles'] as $detalle_id => $detalle) {
                $stmt = $conn->prepare("
                    UPDATE detalles_venta 
                    SET cantidad = ?, precio = ? 
                    WHERE id = ? AND venta_id = ?
                ");
                $stmt->bind_param(
                    'ddii',
                    $detalle['cantidad'],
                    $detalle['precio'],
                    $detalle_id,
                    $venta_id
                );
                $stmt->execute();
            }
        }

        // Procesar eliminación de detalles
        if (isset($_POST['eliminar_detalles'])) {
            foreach ($_POST['eliminar_detalles'] as $detalle_id) {
                $stmt = $conn->prepare("
                    DELETE FROM detalles_venta 
                    WHERE id = ? AND venta_id = ?
                ");
                $stmt->bind_param('ii', $detalle_id, $venta_id);
                $stmt->execute();
            }
        }

        // Procesar nuevos productos
        if (isset($_POST['nuevos_productos'])) {
            foreach ($_POST['nuevos_productos'] as $producto) {
                if (!empty($producto['producto_id']) && $producto['cantidad'] > 0) {
                    $stmt = $conn->prepare("
                        INSERT INTO detalles_venta 
                        (venta_id, producto_id, cantidad, precio) 
                        VALUES (?, ?, ?, ?)
                    ");
                    $stmt->bind_param(
                        'iidd',
                        $venta_id,
                        $producto['producto_id'],
                        $producto['cantidad'],
                        $producto['precio']
                    );
                    $stmt->execute();
                }
            }
        }

        $conn->commit();
        $_SESSION['mensaje'] = "Venta actualizada correctamente";
        header("Location: ../ventas/editar_venta.php?id=$venta_id");
        exit;

    } catch (Exception $e) {
        $conn->rollback();
        $error = "Error al actualizar: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Venta #<?= $venta_id ?></title>
    <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        .producto-row:hover {
            background-color: #f8f9fa;
        }
        .precio-original {
            text-decoration: line-through;
            color: #6c757d;
            font-size: 0.8em;
        }
        .clickable-row {
            cursor: pointer;
        }
    </style>
</head>
<body>
    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>
                <i class="fas fa-edit"></i> Editar Venta #<?= $venta_id ?>
                <small class="text-muted">
                    <?= date('d/m/Y H:i', strtotime($venta['fecha_hora'])) ?>
                </small>
            </h2>
            <a href="../index.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Volver
            </a>
        </div>

        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?= $error ?></div>
        <?php elseif (isset($_SESSION['mensaje'])): ?>
            <div class="alert alert-success"><?= $_SESSION['mensaje'] ?></div>
            <?php unset($_SESSION['mensaje']); ?>
        <?php endif; ?>

        <form method="POST">
            <!-- Productos actuales -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">Productos en la Venta</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($detalles)): ?>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Producto</th>
                                        <th>Categoría</th>
                                        <th>Cantidad</th>
                                        <th>Precio Unitario</th>
                                        <th>Subtotal</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($detalles as $detalle): ?>
                                        <tr class="producto-row">
                                            <td><?= htmlspecialchars($detalle['producto']) ?></td>
                                            <td><?= ucfirst($detalle['categoria']) ?></td>
                                            <td>
                                                <input type="number" name="detalles[<?= $detalle['id'] ?>][cantidad]" 
                                                       value="<?= $detalle['cantidad'] ?>" min="1" step="1" 
                                                       class="form-control form-control-sm">
                                            </td>
                                            <td>
                                                <div class="input-group input-group-sm">
                                                    <div class="input-group-prepend">
                                                        <span class="input-group-text">$</span>
                                                    </div>
                                                    <input type="number" name="detalles[<?= $detalle['id'] ?>][precio]" 
                                                           value="<?= $detalle['precio'] ?>" min="0.01" step="0.01" 
                                                           class="form-control">
                                                </div>
                                                <small class="precio-original">
                                                    Precio original: $<?= number_format($detalle['precio_original'], 2) ?>
                                                </small>
                                            </td>
                                            <td>$<?= number_format($detalle['cantidad'] * $detalle['precio'], 2) ?></td>
                                            <td>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" 
                                                           name="eliminar_detalles[]" value="<?= $detalle['id'] ?>" 
                                                           id="eliminar_<?= $detalle['id'] ?>">
                                                    <label class="form-check-label" for="eliminar_<?= $detalle['id'] ?>">
                                                        Eliminar
                                                    </label>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning">
                            No hay productos en esta venta
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Agregar nuevos productos -->
            <div class="card mb-4">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0">Agregar Productos</h5>
                </div>
                <div class="card-body">
                    <div id="nuevos-productos-container">
                        <div class="form-row mb-3 nuevo-producto">
                            <div class="col-md-5">
                                <select name="nuevos_productos[0][producto_id]" class="form-control select-producto">
                                    <option value="">Seleccionar producto...</option>
                                    <?php foreach ($productos_disponibles as $producto): ?>
                                        <option value="<?= $producto['id'] ?>" 
                                                data-precio="<?= $producto['precio'] ?>">
                                            <?= htmlspecialchars($producto['nombre']) ?> (<?= $producto['categoria'] ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <input type="number" name="nuevos_productos[0][cantidad]" 
                                       placeholder="Cantidad" min="1" step="1" 
                                       class="form-control">
                            </div>
                            <div class="col-md-3">
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text">$</span>
                                    </div>
                                    <input type="number" name="nuevos_productos[0][precio]" 
                                           placeholder="Precio" min="0.01" step="0.01" 
                                           class="form-control precio-producto">
                                </div>
                            </div>
                            <div class="col-md-2">
                                <button type="button" class="btn btn-danger btn-block quitar-producto">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <button type="button" id="agregar-producto" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Agregar otro producto
                    </button>
                </div>
            </div>

            <!-- Acciones -->
            <div class="row mb-5">
                <div class="col-md-4">
                    <button type="submit" name="eliminar_venta" class="btn btn-danger btn-lg btn-block"
                            onclick="return confirm('¿Está seguro de eliminar esta venta completamente?')">
                        <i class="fas fa-trash"></i> Eliminar Venta
                    </button>
                </div>
                <div class="col-md-4">
                    <a href="../index.php" class="btn btn-secondary btn-lg btn-block">
                        <i class="fas fa-times"></i> Cancelar
                    </a>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-success btn-lg btn-block">
                        <i class="fas fa-save"></i> Guardar Cambios
                    </button>
                </div>
            </div>
        </form>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    $(document).ready(function() {
        // Contador para nuevos productos
        let contadorProductos = 1;
        
        // Agregar nuevo campo de producto
        $('#agregar-producto').click(function() {
            const nuevoProducto = `
                <div class="form-row mb-3 nuevo-producto">
                    <div class="col-md-5">
                        <select name="nuevos_productos[${contadorProductos}][producto_id]" class="form-control select-producto">
                            <option value="">Seleccionar producto...</option>
                            <?php foreach ($productos_disponibles as $producto): ?>
                                <option value="<?= $producto['id'] ?>" 
                                        data-precio="<?= $producto['precio'] ?>">
                                    <?= htmlspecialchars($producto['nombre']) ?> (<?= $producto['categoria'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <input type="number" name="nuevos_productos[${contadorProductos}][cantidad]" 
                               placeholder="Cantidad" min="1" step="1" 
                               class="form-control">
                    </div>
                    <div class="col-md-3">
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text">$</span>
                            </div>
                            <input type="number" name="nuevos_productos[${contadorProductos}][precio]" 
                                   placeholder="Precio" min="0.01" step="0.01" 
                                   class="form-control precio-producto">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <button type="button" class="btn btn-danger btn-block quitar-producto">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            `;
            $('#nuevos-productos-container').append(nuevoProducto);
            contadorProductos++;
        });
        
        // Quitar producto
        $(document).on('click', '.quitar-producto', function() {
            $(this).closest('.nuevo-producto').remove();
        });
        
        // Autocompletar precio al seleccionar producto
        $(document).on('change', '.select-producto', function() {
            const precio = $(this).find(':selected').data('precio');
            const campoPrecio = $(this).closest('.form-row').find('.precio-producto');
            if (precio && !campoPrecio.val()) {
                campoPrecio.val(parseFloat(precio).toFixed(2));
            }
        });
    });
    </script>
</body>
</html>