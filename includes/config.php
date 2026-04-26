<?php
// ============================================================
// VetPro - Configuración del Sistema
// Ajusta estos valores según tu servidor
// ============================================================

define('DB_HOST', 'localhost');
define('DB_NAME', 'vetpro');
define('DB_USER', 'root');   // Cambia por tu usuario MySQL
define('DB_PASS', 'c4p1cu4$$');   // Cambia por tu contraseña MySQL
define('DB_CHARSET', 'utf8mb4');

define('BASE_URL',    'https://magus-ecommerce.com/vetpro');
define('BASE_PATH',   dirname(__DIR__));                          // raíz de /vetpro/
define('UPLOADS_PATH', BASE_PATH . '/public/uploads');
define('UPLOADS_URL',  BASE_URL  . '/public/uploads');

define('APP_NAME', 'VetPro');
define('APP_VERSION', '1.0.0');
define('APP_ENV', 'production'); // 'development' o 'production'

define('SESSION_NAME', 'vetpro_session');
define('SESSION_LIFETIME', 28800); // 8 horas

// Timezone
date_default_timezone_set('America/Lima');

// Iniciar sesión
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

// Helper: usuario logueado
function getUser() {
    return $_SESSION['user'] ?? null;
}

function isLogged() {
    return isset($_SESSION['user']);
}

function requireLogin() {
    if (!isLogged()) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
}

function hasRole($roles) {
    $user = getUser();
    if (!$user) return false;
    if (is_string($roles)) $roles = [$roles];
    return in_array($user['rol'], $roles);
}

// Helper: respuesta JSON para API
function jsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Helper: sanitize
function clean($str) {
    return htmlspecialchars(trim($str ?? ''), ENT_QUOTES, 'UTF-8');
}

// Helper: formatear moneda peruana
function formatMoney($amount) {
    return 'S/. ' . number_format($amount, 2);
}

// Helper: formatear fecha en español
function formatDate($date) {
    if (!$date) return '—';
    $ts = strtotime($date);
    $dias = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];
    $meses = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    return $dias[date('w',$ts)] . ', ' . date('d',$ts) . ' de ' . $meses[(int)date('n',$ts)] . ' ' . date('Y',$ts);
}
