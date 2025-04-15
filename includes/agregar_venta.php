<?php
session_start();
include '../includes/db.php';

header('Content-Type: application/json');

// Validar CSRF
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    http_response_code(403);
    die(json_encode(['success' => false, 'error' => 'Token CSRF inválido']));
}

// Validar datos
if (!isset($_POST['productos']) || !isset($_POST['total'])) {
    http_response_code(400);
    die(json_encode(['success' => false, 'error' => 'Datos incompletos']));
}

$productos = json_decode($_POST['productos'], true);
$total = floatval($_POST['total']);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    die(json_encode(['success' => false, 'error' => 'Formato de productos inválido']));
}

if (empty($productos) || $total <= 0) {
    http_response_code(400);
    die(json_encode(['success' => false, 'error' => 'Datos de venta inválidos']));
}

// Iniciar transacción
$conn->begin_transaction();

try {
    // 1. Insertar venta principal
    $stmt = $conn->prepare("INSERT INTO ventas (total, fecha_hora) VALUES (?, NOW())");
    $stmt->bind_param("d", $total);
    $stmt->execute();
    $venta_id = $conn->insert_id;
    
    // 2. Insertar detalles de venta
    $stmt = $conn->prepare("INSERT INTO detalles_venta (venta_id, producto_id, cantidad, precio) VALUES (?, ?, ?, ?)");
    
    foreach ($productos as $producto) {
        // Buscar o crear producto
        $producto_id = obtenerProductoId($conn, $producto);
        
        // Insertar detalle
        $stmt->bind_param("iidd", $venta_id, $producto_id, $producto['cantidad'], $producto['precio']);
        $stmt->execute();
    }
    
    // Confirmar transacción
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'venta_id' => $venta_id,
        'message' => 'Venta registrada correctamente'
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error al guardar venta: ' . $e->getMessage()
    ]);
}

// Función para obtener/crear producto (actualizada)
function obtenerProductoId($conn, $producto) {
    // Normalizar el nombre para comparación (eliminar espacios extras, etc.)
    $nombreNormalizado = trim(mb_strtolower($producto['nombre']));
    
    // 1. Buscar producto existente (comparación insensible a mayúsculas y espacios)
    $stmt = $conn->prepare("SELECT id FROM productos WHERE LOWER(TRIM(nombre)) = ?");
    $stmt->bind_param("s", $nombreNormalizado);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        return $result->fetch_assoc()['id'];
    }
    
    // 2. Si no existe, obtener categoría
    $stmt = $conn->prepare("SELECT id FROM categorias WHERE nombre = ?");
    $stmt->bind_param("s", $producto['categoria']);
    $stmt->execute();
    $categoria_result = $stmt->get_result();
    
    if ($categoria_result->num_rows === 0) {
        throw new Exception("Categoría no encontrada: " . $producto['categoria']);
    }
    
    $categoria_id = $categoria_result->fetch_assoc()['id'];
    
    // 3. Insertar nuevo producto con el nombre exacto proporcionado
    $stmt = $conn->prepare("INSERT INTO productos (nombre, precio, categoria_id) VALUES (?, ?, ?)");
    $stmt->bind_param("sdi", $producto['nombre'], $producto['precio'], $categoria_id);
    $stmt->execute();
    
    return $conn->insert_id;
}
?>