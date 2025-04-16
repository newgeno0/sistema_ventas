<?php
$host = 'localhost';
$user = 'root';
$password = '';       // Si tienes contraseña, colócala aquí
$database = 'sistema_ventas';

// Crear conexión
$conn = new mysqli($host, $user, $password, $database);

// Verificar errores de conexión
if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}

// Establecer charset UTF-8 (después de crear la conexión)
$conn->set_charset("utf8mb4"); // 👈 Esta línea debe ir DESPUÉS de crear $conn

$db_host = $host;
$db_user = $user;
$db_pass = $password;
$db_name = $database;

// Configuración de seguridad
//define('MAX_LOGIN_ATTEMPTS', 5);
//define('LOGIN_LOCKOUT_TIME', 15 * 60); // 15 minutos en segundos

// Tabla usuarios requerida:
// CREATE TABLE usuarios (
//     id INT AUTO_INCREMENT PRIMARY KEY,
//     username VARCHAR(50) UNIQUE NOT NULL,
//     email VARCHAR(100) UNIQUE NOT NULL,
//     password_hash VARCHAR(255) NOT NULL,
//     rol ENUM('admin', 'ventas', 'reportes') DEFAULT 'ventas' NOT NULL,
//     intentos_fallidos INT DEFAULT 0,
//     bloqueado_until DATETIME,
//     creado_en DATETIME DEFAULT CURRENT_TIMESTAMP
// );
?>
