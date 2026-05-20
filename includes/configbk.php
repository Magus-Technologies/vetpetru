<?php
// ============================================================
// VetPro - Configuración del Sistema
// ─────────────────────────────────────────────────────────
// Auto-detecta entorno (LOCAL vs PRODUCCIÓN) por hostname.
// No hace falta cambiar nada al subir al servidor.
// ============================================================

// ─── Detección de entorno ─────────────────────────────────────
// Si la petición viene de localhost (o estamos en CLI dentro de
// la máquina del dev), usamos config local. Si no, producción.
$__host = $_SERVER['HTTP_HOST'] ?? gethostname();
$__isLocal = (
    str_contains($__host, 'localhost') ||
    str_contains($__host, '127.0.0.1') ||
    str_contains($__host, '.test')     ||
    str_contains($__host, '.local')
);

if ($__isLocal) {
    // ════════ LOCAL (Laragon) ════════
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'vetpro');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('BASE_URL', 'http://localhost/vetPro');
    define('APP_ENV',  'development');
    define('MIGRATIONS_TOKEN', 'dev_local_token_no_importa');
} else {
    // ════════ PRODUCCIÓN (magus-ecommerce.com) ════════
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'vetpro');
    define('DB_USER', 'root');
    define('DB_PASS', 'c4p1cu4$$');
    define('BASE_URL', 'https://magus-ecommerce.com/vetpro');
    define('APP_ENV',  'production');
    define('MIGRATIONS_TOKEN', 'CAMBIAR_POR_TOKEN_LARGO_Y_ALEATORIO');
}

define('DB_CHARSET', 'utf8mb4');
define('BASE_PATH',   dirname(__DIR__));
define('UPLOADS_PATH', BASE_PATH . '/public/uploads');
define('UPLOADS_URL',  BASE_URL  . '/public/uploads');

define('APP_NAME', 'VetPro');
define('APP_VERSION', '1.0.0');

define('SESSION_NAME', 'vetpro_session');
define('SESSION_LIFETIME', 28800); // 8 horas

date_default_timezone_set('America/Lima');

session_name(SESSION_NAME);
session_start();

// Conexión PDO
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            if (APP_ENV === 'development') {
                die('Error de conexión: ' . $e->getMessage());
            } else {
                die('Error de conexión con la base de datos. Contacta al administrador.');
            }
        }
    }
    return $pdo;
}

function getUser()      { return $_SESSION['user'] ?? null; }
function isLogged()     { return isset($_SESSION['user']); }
function requireLogin() {
    if (!isLogged()) { header('Location: ' . BASE_URL . '/login.php'); exit; }
}
function hasRole($roles) {
    $user = getUser();
    if (!$user) return false;
    if (is_string($roles)) $roles = [$roles];
    return in_array($user['rol'], $roles);
}

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function clean($str) {
    return htmlspecialchars(trim($str ?? ''), ENT_QUOTES, 'UTF-8');
}

function formatMoney($amount) {
    return 'S/. ' . number_format($amount, 2);
}

function formatDate($date) {
    if (!$date) return '—';
    $ts = strtotime($date);
    $dias  = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];
    $meses = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    return $dias[date('w',$ts)] . ', ' . date('d',$ts) . ' de ' . $meses[(int)date('n',$ts)] . ' ' . date('Y',$ts);
}
