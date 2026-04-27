<?php
/**
 * Migrador casero — corre los .sql de esta carpeta en orden alfabético.
 *
 *  USO (CLI):    php migrations/migrate.php
 *  USO (web):    http://localhost/vetPro/migrations/migrate.php
 *
 * Crea automáticamente la tabla `_migrations` para no re-ejecutar las ya aplicadas.
 * Las migraciones DEBEN llamarse `NNN_descripcion.sql` (ej. `001_init.sql`).
 */

require_once __DIR__ . '/../includes/config.php';

header('Content-Type: text/plain; charset=utf-8');

// ─── Seguridad: solo CLI o con ?token= que coincida con MIGRATIONS_TOKEN ───
$esCli = (PHP_SAPI === 'cli');
if (!$esCli) {
    $token = $_GET['token'] ?? '';
    if (!defined('MIGRATIONS_TOKEN') || MIGRATIONS_TOKEN === '' || !hash_equals(MIGRATIONS_TOKEN, $token)) {
        http_response_code(403);
        echo "403 — Acceso denegado.\n";
        echo "Ejecuta vía CLI:  php migrations/migrate.php\n";
        echo "O vía web:        ?token=TU_TOKEN_DE_CONFIG\n";
        exit;
    }
}

$db = getDB();

$db->exec("
    CREATE TABLE IF NOT EXISTS _migrations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        archivo VARCHAR(255) NOT NULL UNIQUE,
        ejecutada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$ya = $db->query("SELECT archivo FROM _migrations")->fetchAll(PDO::FETCH_COLUMN);
$ya = array_flip($ya);

$archivos = glob(__DIR__ . '/[0-9]*.sql');
sort($archivos, SORT_STRING);

if (!$archivos) {
    echo "No hay archivos .sql en /migrations\n";
    exit;
}

$ejecutadas = 0;
foreach ($archivos as $ruta) {
    $nombre = basename($ruta);
    if (isset($ya[$nombre])) {
        echo "•  $nombre   (ya aplicada)\n";
        continue;
    }

    $sql = file_get_contents($ruta);
    try {
        $db->exec($sql);
        $db->prepare("INSERT INTO _migrations (archivo) VALUES (?)")->execute([$nombre]);
        echo "✔  $nombre\n";
        $ejecutadas++;
    } catch (Throwable $e) {
        echo "✘  $nombre\n   ERROR: " . $e->getMessage() . "\n";
        exit(1);
    }
}

echo "\n$ejecutadas migración(es) aplicada(s).\n";
