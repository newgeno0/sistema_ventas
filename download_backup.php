<?php
include 'includes/db.php';

// Configuración
$backup_dir = __DIR__ . '/backups/';

// Validar archivo
if (isset($_GET['file'])) {
    $file = basename($_GET['file']);
    $file_path = $backup_dir . $file;
    
    if (file_exists($file_path) && is_file($file_path)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($file_path));
        readfile($file_path);
        exit;
    }
}

header("Location: backup.php");
exit;
?>