<?php
/**
 * VetPro — Configuración SUNAT
 * ─────────────────────────────
 * Auto-detecta entorno (LOCAL vs PRODUCCIÓN) por hostname.
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
    define('SUNAT_API_URL', 'http://84.247.162.204/api-sunat-laravel/api/v1');
}

define('SUNAT_API_TIMEOUT', 60);

// ─── Endpoint SUNAT ───────────────────────────────────────────
// 'beta' = pruebas | 'produccion' = ambiente real
define('SUNAT_ENDPOINT', 'beta');

// ─── Credenciales SOL (RUC de prueba SUNAT) ──────────────────
// Para emitir con datos reales, cambia por el RUC del cliente,
// su usuario y clave SOL.
define('SUNAT_RUC',         '20000000001');
define('SUNAT_USUARIO_SOL', 'MODDATOS');
define('SUNAT_CLAVE_SOL',   'MODDATOS');

// ─── Datos de la empresa emisora ─────────────────────────────
define('SUNAT_RAZON_SOCIAL',     'EMPRESA DE PRUEBAS S.A.C.');
define('SUNAT_NOMBRE_COMERCIAL', 'VetPro');
define('SUNAT_DIRECCION',        'AV. PRUEBA 123');
define('SUNAT_UBIGEO',           '150101');
define('SUNAT_DISTRITO',         'LIMA');
define('SUNAT_PROVINCIA',        'LIMA');
define('SUNAT_DEPARTAMENTO',     'LIMA');

// ─── Series ──────────────────────────────────────────────────
define('SUNAT_SERIE_FACTURA', 'F001');
define('SUNAT_SERIE_BOLETA',  'B001');
