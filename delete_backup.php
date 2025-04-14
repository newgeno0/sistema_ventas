<?php
include 'includes/db.php';

// Configuración
$backup_dir = __DIR__ . '/backups/';

// Procesar eliminación
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['file'])) {
    $file = basename($_POST['file']);
    $file_path = $backup_dir . $file;
    
    if (file_exists($file_path) && is_file($file_path)) {
        if (unlink($file_path)) {
            header("Location: backup.php?mensaje=" . urlencode("Respaldo eliminado correctamente"));
        } else {
            header("Location: backup.php?error=" . urlencode("Error al eliminar el archivo"));
        }
    } else {
        header("Location: backup.php?error=" . urlencode("El archivo no existe"));
    }
    exit;
}

header("Location: backup.php");
exit;
?>