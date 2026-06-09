<?php
/**
 * VetPro — Configuración SUNAT
 * ─────────────────────────────
 * Auto-detecta entorno (LOCAL vs PRODUCCIÓN) por hostname.
 *
 * Ahora lee de la BD (tabla `configuracion`) si existe.
 * Si no existe, usa los valores por defecto hardcodeados (para local/testing).
 */

$__host = $_SERVER['HTTP_HOST'] ?? gethostname();
$__isLocal = (
    str_contains($__host, 'localhost') ||
    str_contains($__host, '127.0.0.1') ||
    str_contains($__host, '.test')     ||
    str_contains($__host, '.local')
);

if ($__isLocal) {
    // ════════ LOCAL ════════
    define('SUNAT_API_URL', 'http://api-sunat-laravel.test/api/v1');
} else {
    // ════════ PRODUCCIÓN ════════
    define('SUNAT_API_URL', 'https://magus-qa.com/api-sunat-laravel/api/v1');
}

define('SUNAT_API_TIMEOUT', 60);

// ─── Leer de BD si existe ──────────────────────────────────────
function loadSunatConfigFromDB(?PDO $db = null): array {
    if ($db === null) {
        if (!isset($GLOBALS['__sunat_cfg_loaded'])) {
            $GLOBALS['__sunat_cfg_loaded'] = true;
            try {
                $pdo = getDB();
                $rows = $pdo->query("SELECT clave, valor FROM configuracion")->fetchAll(PDO::FETCH_KEY_PAIR);
                $GLOBALS['__sunat_cfg_db'] = $rows;
            } catch (Exception $e) {
                $GLOBALS['__sunat_cfg_db'] = [];
            }
        }
        return $GLOBALS['__sunat_cfg_db'] ?? [];
    }

    try {
        $rows = $db->query("SELECT clave, valor FROM configuracion")->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Exception $e) {
        $rows = [];
    }
    return $rows;
}

$__cfg = loadSunatConfigFromDB();

// ─── Modo: beta | produccion ──────────────────────────────────
// En entorno LOCAL siempre forzamos beta + credenciales de prueba SUNAT,
// ignorando los valores de producción que pueda traer la BD importada.
if ($__isLocal) {
    define('SUNAT_ENDPOINT',    'beta');
    define('SUNAT_RUC',         '20000000001');
    define('SUNAT_USUARIO_SOL', 'MODDATOS');
    define('SUNAT_CLAVE_SOL',   'MODDATOS');
} else {
    $__modo = $__cfg['sunat_modo'] ?? 'produccion';
    define('SUNAT_ENDPOINT',    $__modo);
    define('SUNAT_RUC',         $__cfg['clinica_ruc']       ?? '20000000001');
    define('SUNAT_USUARIO_SOL', $__cfg['sunat_usuario_sol'] ?? 'MODDATOS');
    define('SUNAT_CLAVE_SOL',   $__cfg['sunat_clave_sol']   ?? 'MODDATOS');
}

// ─── Datos empresa emisora (desde configuracion BD o defaults) ─
define('SUNAT_RAZON_SIAL',     $__cfg['clinica_nombre'] ?? 'EMPRESA DE PRUEBAS S.A.C.');
define('SUNAT_RAZON_SOCIAL',  $__cfg['clinica_nombre'] ?? 'EMPRESA DE PRUEBAS S.A.C.');
define('SUNAT_NOMBRE_COMERCIAL', $__cfg['clinica_nombre'] ?? 'VetPro');
define('SUNAT_DIRECCION',      $__cfg['clinica_direccion'] ?? 'AV. PRUEBA 123');
define('SUNAT_UBIGEO',         $__cfg['ubigeo'] ?? '150101');
define('SUNAT_DISTRITO',        $__cfg['distrito'] ?? 'LIMA');
define('SUNAT_PROVINCIA',       $__cfg['provincia'] ?? 'LIMA');
define('SUNAT_DEPARTAMENTO',    $__cfg['departamento'] ?? 'LIMA');

// ─── Series por defecto (sede 1) ───────────────────────────────
define('SUNAT_SERIE_FACTURA', $__cfg['serie_factura'] ?: 'F001');
define('SUNAT_SERIE_BOLETA',  $__cfg['serie_boleta'] ?: 'B001');

// ─── Series Notas de Crédito / Débito ─────────────────────────
if (!defined('SUNAT_SERIE_NC_FACTURA')) define('SUNAT_SERIE_NC_FACTURA', 'FC01');
if (!defined('SUNAT_SERIE_NC_BOLETA'))  define('SUNAT_SERIE_NC_BOLETA',  'BC01');
if (!defined('SUNAT_SERIE_ND_FACTURA')) define('SUNAT_SERIE_ND_FACTURA', 'FD01');
if (!defined('SUNAT_SERIE_ND_BOLETA'))  define('SUNAT_SERIE_ND_BOLETA',  'BD01');

// ─── helpers de estado del certificado ──────────────────────────
function sunatCertSubido(): bool {
    $c = loadSunatConfigFromDB();
    return !empty($c['certificado_subido']);
}

function sunatCertFecha(): ?string {
    $c = loadSunatConfigFromDB();
    return $c['certificado_fecha'] ?? null;
}
