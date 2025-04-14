<?php
// Habilitar visualización de errores
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Incluir configuración de la base de datos
$db_config = 'includes/db.php';
if (!file_exists($db_config)) {
    die('<div class="alert alert-danger">Error: No se encontró el archivo includes/db.php</div>');
}

require_once $db_config;

// Verificar variables de conexión
if (!isset($db_host, $db_user, $db_pass, $db_name)) {
    die('<div class="alert alert-danger">Error: Configuración incompleta de la base de datos</div>');
}

// Configuración específica para WAMP
$mysql_path = "C:\\wamp64\\bin\\mysql\\mysql9.1.0\\bin\\"; // AJUSTA ESTA RUTA CON TU VERSIÓN
set_include_path(get_include_path() . PATH_SEPARATOR . $mysql_path);

// Configuración de backups
$backup_dir = __DIR__ . '/backups/';
$max_backups = 10;

// Crear directorio si no existe
if (!file_exists($backup_dir)) {
    if (!mkdir($backup_dir, 0755, true)) {
        die('<div class="alert alert-danger">Error: No se pudo crear el directorio de backups</div>');
    }
}

// Procesar solicitud de respaldo
// Establecer la zona horaria correcta al inicio del script
date_default_timezone_set('America/Mexico_City'); // Ajusta según tu ubicación

// En la parte donde generas el nombre del archivo:
if (isset($_POST['crear_respaldo'])) {
    $fecha = date('Y-m-d_H-i-s'); // Ahora usará la zona horaria correcta
    $backup_file = $backup_dir . 'backup_' . $fecha . '.sql';
    
    // Comando seguro para WAMP
    $command = "\"{$mysql_path}mysqldump\" --user={$db_user} --password=\"{$db_pass}\" --host={$db_host} {$db_name} > \"{$backup_file}\" 2>&1";
    system($command, $output);
    
    if ($output === 0) {
        $mensaje = "Respaldo creado exitosamente: " . basename($backup_file);
        
        // Limitar número de respaldos
        $backups = glob($backup_dir . '*.sql');
        if (count($backups) > $max_backups) {
            usort($backups, function($a, $b) {
                return filemtime($a) - filemtime($b);
            });
            unlink($backups[0]);
        }
        header("Location: backup.php?mensaje=" . urlencode($mensaje));
    } else {
        // Capturar el error detallado
        $error_detail = shell_exec($command . ' 2>&1');
        header("Location: backup.php?error=" . urlencode("Error al crear respaldo. Detalle: " . $error_detail));
    }
    exit;
}

// [Las primeras partes del archivo permanecen igual hasta la sección de restauración...]

// Procesar solicitud de restauración
// Procesar solicitud de restauración
if (isset($_POST['restaurar_respaldo']) && isset($_FILES['archivo_respaldo'])) {
    $archivo = $_FILES['archivo_respaldo'];
    
    if ($archivo['error'] === UPLOAD_ERR_OK) {
        $tmp_name = $archivo['tmp_name'];
        $file_ext = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
        
        if ($file_ext === 'sql') {
            $pdo = null;
            $transactionActive = false; // Control explícito del estado de la transacción
            
            try {
                // Conexión PDO
                $pdo = new PDO("mysql:host={$db_host};dbname={$db_name}", $db_user, $db_pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::MYSQL_ATTR_LOCAL_INFILE => true,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]);
                
                // Desactivar verificación de claves foráneas
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
                
                // Leer y procesar el archivo SQL
                $sql = file_get_contents($tmp_name);
                
                // Limpieza del SQL (conserva solo lo esencial)
                $sql = preg_replace([
                    '/\/\*!50003 DEFINER=`[^`]+`@`[^`]+`\*\/ /i',
                    '/CREATE\s+.*?DEFINER=`[^`]+`@`[^`]+` /i',
                    '/DELIMITER \$\$.*?\$\$\s*DELIMITER ;/is',
                    '/^\s*DELIMITER .+$/im',
                    '/^mysqldump:.+$/im',
                    '/^--.*$/m',
                    '/^\/\*.+?\*\/;?/ims'
                ], '', $sql);
                
                // Dividir consultas de manera segura
                $queries = [];
                $currentQuery = '';
                $inString = false;
                $stringChar = '';
                
                for ($i = 0; $i < strlen($sql); $i++) {
                    $char = $sql[$i];
                    
                    // Manejo de cadenas entre comillas
                    if (($char === "'" || $char === '"') && ($i === 0 || $sql[$i-1] !== "\\")) {
                        if ($inString && $stringChar === $char) {
                            $inString = false;
                        } elseif (!$inString) {
                            $inString = true;
                            $stringChar = $char;
                        }
                    }
                    
                    $currentQuery .= $char;
                    
                    // Fin de consulta cuando encontramos ; fuera de cadenas
                    if ($char === ';' && !$inString) {
                        $query = trim($currentQuery);
                        if (!empty($query) && preg_match('/^(INSERT|UPDATE|DELETE|CREATE|ALTER|DROP|TRUNCATE|REPLACE)/i', $query)) {
                            $queries[] = $query;
                        }
                        $currentQuery = '';
                    }
                }
                
                // Añadir la última consulta si es válida
                $lastQuery = trim($currentQuery);
                if (!empty($lastQuery) && preg_match('/^(INSERT|UPDATE|DELETE|CREATE|ALTER|DROP|TRUNCATE|REPLACE)/i', $lastQuery)) {
                    $queries[] = $lastQuery;
                }
                
                // Verificar si hay consultas válidas antes de iniciar transacción
                if (!empty($queries)) {
                    $transactionActive = $pdo->beginTransaction();
                } else {
                    throw new Exception("No se encontraron consultas SQL válidas en el archivo");
                }
                
                // Ejecutar consultas con verificación adicional
                foreach ($queries as $query) {
                    $cleanQuery = trim(preg_replace('/\/\*.*?\*\//s', '', $query));
                    if (!empty($cleanQuery)) {
                        $pdo->exec($cleanQuery);
                    }
                }
                
                // Commit solo si la transacción está activa
                if ($transactionActive && $pdo->inTransaction()) {
                    $pdo->commit();
                    $transactionActive = false;
                }
                
                // Reactivar verificaciones
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
                
                header("Location: backup.php?mensaje=Base+de+datos+restaurada+exitosamente");
                exit;
                
            } catch (Exception $e) {
                // Rollback solo si la transacción está activa
                if ($transactionActive && $pdo && $pdo->inTransaction()) {
                    try {
                        $pdo->rollBack();
                    } catch (PDOException $rollbackEx) {
                        // Registrar error de rollback pero continuar
                        error_log("Error en rollback: " . $rollbackEx->getMessage());
                    }
                }
                
                // Reactivar verificaciones si hay conexión
                if ($pdo) {
                    try {
                        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
                    } catch (PDOException $fkEx) {
                        error_log("Error reactivando FK checks: " . $fkEx->getMessage());
                    }
                }
                
                header("Location: backup.php?error=" . urlencode("Error al restaurar: " . $e->getMessage()));
                exit;
            }
        } else {
            header("Location: backup.php?error=Solo+se+permiten+archivos+.sql");
            exit;
        }
    } else {
        header("Location: backup.php?error=Error+al+subir+el+archivo");
        exit;
    }
}

// [El resto del archivo permanece igual...]

// Obtener lista de respaldos existentes
$backups = [];
if (is_dir($backup_dir)) {
    $backups = glob($backup_dir . '*.sql');
    usort($backups, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });
}

// Procesar mensajes GET
$mensaje = isset($_GET['mensaje']) ? htmlspecialchars($_GET['mensaje']) : null;
$error = isset($_GET['error']) ? htmlspecialchars($_GET['error']) : null;
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Respaldo y Restauración</title>
    <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        .backup-card {
            transition: all 0.3s;
        }
        .backup-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        .file-info {
            font-size: 0.9rem;
            color: #6c757d;
        }
    </style>
</head>
<body>
    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-database"></i> Respaldo y Restauración</h2>
            <a href="index.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Volver
            </a>
        </div>

        <?php if ($mensaje): ?>
            <div class="alert alert-success"><?= $mensaje ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= $error ?></div>
        <?php endif; ?>

        <div class="row">
            <div class="col-md-6 mb-4">
                <div class="card backup-card h-100">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-plus-circle"></i> Crear Nuevo Respaldo</h5>
                    </div>
                    <div class="card-body">
                        <p>Crea una copia de seguridad completa de la base de datos en este momento.</p>
                        <form method="POST">
                            <button type="submit" name="crear_respaldo" class="btn btn-success btn-block">
                                <i class="fas fa-save"></i> Generar Respaldo
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-md-6 mb-4">
                <div class="card backup-card h-100">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="mb-0"><i class="fas fa-history"></i> Restaurar Respaldo</h5>
                    </div>
                    <div class="card-body">
                        <p>Restaura la base de datos desde un archivo de respaldo.</p>
                        <form method="POST" enctype="multipart/form-data">
                            <div class="form-group">
                                <div class="custom-file">
                                    <input type="file" class="custom-file-input" id="archivo_respaldo" name="archivo_respaldo" accept=".sql" required>
                                    <label class="custom-file-label" for="archivo_respaldo">Seleccionar archivo .sql</label>
                                </div>
                            </div>
                            <button type="submit" name="restaurar_respaldo" class="btn btn-danger btn-block" 
                                    onclick="return confirm('¿Estás seguro de restaurar este respaldo? Todos los datos actuales serán reemplazados.')">
                                <i class="fas fa-undo"></i> Restaurar Respaldo
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0"><i class="fas fa-list"></i> Respaldos Disponibles</h5>
            </div>
            <div class="card-body">
                <?php if (empty($backups)): ?>
                    <div class="alert alert-info">No hay respaldos disponibles</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Nombre del Archivo</th>
                                    <th>Tamaño</th>
                                    <th>Fecha de Creación</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($backups as $backup): ?>
                                    <tr>
                                        <td><?= basename($backup) ?></td>
                                        <td><?= formatSizeUnits(filesize($backup)) ?></td>
                                        <td><?= date('d/m/Y H:i:s', filemtime($backup)) ?></td>
                                        <td>
                                            <a href="download_backup.php?file=<?= basename($backup) ?>" class="btn btn-sm btn-primary">
                                                <i class="fas fa-download"></i> Descargar
                                            </a>
                                            <form method="POST" action="delete_backup.php" style="display: inline;">
                                                <input type="hidden" name="file" value="<?= basename($backup) ?>">
                                                <button type="submit" class="btn btn-sm btn-danger" 
                                                        onclick="return confirm('¿Eliminar este respaldo permanentemente?')">
                                                    <i class="fas fa-trash"></i> Eliminar
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Mostrar nombre de archivo seleccionado
    $('.custom-file-input').on('change', function() {
        let fileName = $(this).val().split('\\').pop();
        $(this).next('.custom-file-label').addClass("selected").html(fileName);
    });
    </script>
</body>
</html>

<?php
// Función para formatear tamaños de archivo
function formatSizeUnits($bytes) {
    if ($bytes >= 1073741824) {
        $bytes = number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        $bytes = number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        $bytes = number_format($bytes / 1024, 2) . ' KB';
    } elseif ($bytes > 1) {
        $bytes = $bytes . ' bytes';
    } elseif ($bytes == 1) {
        $bytes = $bytes . ' byte';
    } else {
        $bytes = '0 bytes';
    }
    return $bytes;
}
?>