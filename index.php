<?php
// Establecer la zona horaria correcta al inicio del script
date_default_timezone_set('America/Mexico_City'); // Ajusta según tu ubicación
session_start();
include 'includes/db.php';

// Generar token CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Obtener resumen diario
$sql_ventas = "SELECT id, DATE_FORMAT(fecha_hora, '%H:%i') AS hora, total 
               FROM ventas 
               WHERE DATE(fecha_hora) = CURDATE() 
               ORDER BY fecha_hora DESC";
$ventas = $conn->query($sql_ventas);

// Obtener totales diarios
$sql_totales = "SELECT SUM(total) AS total_dia FROM ventas 
                WHERE DATE(fecha_hora) = CURDATE()";
$totales = $conn->query($sql_totales)->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Ventas</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    
    <!-- Estilos personalizados -->
    <link rel="stylesheet" href="assets/css/styles.css">
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="assets/img/favicon.png">
</head>
<body>

    <div class="container">
        <div class="row mb-4">
            <div class="col-md-8">
                <h1 class="display-4"><i class="fas fa-cash-register"></i> Nueva Venta</h1>
				<a href="resumen_dia.php" class="btn btn-info">
					<i class="fas fa-chart-bar"></i> Ventas del Día
                </a>
				<a href="backup.php" class="btn btn-warning">
                    <i class="far fa-save"></i> Respaldos
                </a>
                <a href="reportes.php" class="btn btn-info">
                    <i class="fas fa-chart-bar"></i> Ver Reportes
                </a>
            </div>
            
            
        </div>

        <!-- Formulario de Venta -->
        <form id="formVenta" onsubmit="event.preventDefault(); agregarProducto();" class="mb-5">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="producto"><i class="fas fa-search"></i> Buscar Producto</label>
                        <input type="text" class="form-control" id="producto" 
                               placeholder="Escribe al menos 2 caracteres..." 
                               autocomplete="off">
                        <div id="sugerencias" class="sugerencias-container"></div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="precio"><i class="fas fa-tag"></i> Precio Unitario</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text">$</span>
                                    </div>
                                    <input type="number" class="form-control" id="precio" 
                                           step="0.01" min="0" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="cantidad"><i class="fas fa-cubes"></i> Cantidad</label>
                                <input type="number" class="form-control" id="cantidad" 
                                       value="1" min="1" required>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="categoria"><i class="fas fa-tags"></i> Categoría</label>
                        <select class="form-control" id="categoria" required>
                            <option value="producto">Producto</option>
                            <option value="servicio">Servicio</option>
                            <option value="impresion">Impresión</option>
                            <option value="aportacion">Aportación</option>
                        </select>
                    </div>

                    <button type="button" class="btn btn-primary btn-lg btn-block" onclick="agregarProducto()">
						<i class="fas fa-cart-plus"></i> Agregar Producto (Enter)
					</button>
                </div>
            </div>
        </form>

        <!-- Lista de Productos -->
        <div class="card mb-4">
            <div class="card-header bg-success text-white">
                <h4 class="mb-0"><i class="fas fa-shopping-cart"></i> Carrito de Compra</h4>
            </div>
            <div class="card-body p-0">
                <table class="table table-striped mb-0">
                    <thead class="thead-dark">
                        <tr>
                            <th>Producto</th>
                            <th>Precio Unitario</th>
                            <th>Cantidad</th>
                            <th>Subtotal</th>
                            <th>Categoría</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="lista-productos">
                        <!-- Productos se insertan dinámicamente aquí -->
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Total y Acciones -->
        <div class="row mb-5">
            <div class="col-md-6">
                <div class="alert alert-primary total-container">
                    <h4 class="mb-0">Total General: 
                        <span class="badge badge-pill badge-success" id="total">0.00</span>
                    </h4>
                </div>
            </div>
            <div class="col-md-6 text-right">
                <button class="btn btn-danger btn-lg" onclick="vaciarCarrito()">
                    <i class="fas fa-trash"></i> Vaciar Carrito
                </button>
                <button class="btn btn-success btn-lg" onclick="guardarNota()">
                    <i class="fas fa-save"></i> Guardar Venta
                </button>
            </div>
        </div>

        <!-- Resumen Diario -->
        <div class="card">
            <div class="card-header bg-info text-white">
                <h4 class="mb-0">
                    <i class="fas fa-chart-line"></i> Resumen del Día
                    <button class="btn btn-sm btn-light float-right" 
                            type="button" 
                            data-toggle="collapse" 
                            data-target="#resumenDia">
                        Mostrar/Ocultar
                    </button>
                </h4>
            </div>
            <div class="collapse show" id="resumenDia">
                <div class="card-body">
                    <?php include 'ventas/resumen_diario.php' ?>;
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/scripts.js"></script>
    
</body>
</html>