<?php
include 'db.php'; // Incluir la conexión a la base de datos

// Obtener y sanitizar el término de búsqueda
$query = isset($_POST['query']) ? $conn->real_escape_string($_POST['query']) : '';
$query = trim($query);

// Consulta SQL optimizada con JOIN a categorías
$sql = "SELECT 
            p.nombre, 
            p.precio 
        FROM productos p
        INNER JOIN categorias c ON p.categoria_id = c.id
        WHERE p.nombre LIKE '%$query%' 
        ORDER BY p.nombre ASC
        LIMIT 10";

$result = $conn->query($sql);

// Generar sugerencias HTML
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo '<div class="sugerencia" onclick="seleccionarSugerencia(this)">';
        echo    htmlspecialchars($row['nombre']) . ' - $' . number_format($row['precio'], 2);
        echo '</div>';
    }
} else {
    echo '<div class="sugerencia text-muted">No se encontraron productos</div>';
}

// Cerrar conexión
$conn->close();
?>