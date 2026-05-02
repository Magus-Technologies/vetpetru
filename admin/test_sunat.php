<?php
/**
 * test_sunat.php — Prueba aislada de la integración con la API Laravel.
 *
 * Sirve para validar el flujo SIN tocar la BD: arma un comprobante dummy,
 * lo manda a /generar/comprobante y luego a /enviar/documento/electronico.
 *
 * USO:  http://localhost/vetPro/admin/test_sunat.php
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/config_sunat.php';
require_once __DIR__ . '/../includes/sunat/SunatClient.php';
require_once __DIR__ . '/../includes/sunat/SunatBuilder.php';

header('Content-Type: text/plain; charset=utf-8');

$ventaDummy = [
    'tipo_comprobante' => 'boleta',
    'serie'            => SUNAT_SERIE_BOLETA,
    'numero'           => 1,
    'fecha'            => date('Y-m-d\TH:i:sP'),
];

$clienteDummy = [
    'nombre'    => 'JUAN PEREZ TEST',
    'dni'       => '12345678',
    'ruc'       => '',
    'direccion' => 'AV. CLIENTE 999',
];

$itemsDummy = [
    [
        'referencia_id'   => 1,
        'descripcion'     => 'CONSULTA VETERINARIA GENERAL',
        'cantidad'        => 1,
        'precio_unitario' => 50.00,
    ],
    [
        'referencia_id'   => 2,
        'descripcion'     => 'VACUNA QUINTUPLE',
        'cantidad'        => 2,
        'precio_unitario' => 35.00,
    ],
];

echo "═══ VetPro · TEST SUNAT (RUC " . SUNAT_RUC . " · " . SUNAT_ENDPOINT . ") ═══\n\n";

$payload = SunatBuilder::buildComprobante($ventaDummy, $clienteDummy, $itemsDummy);
echo "▶ PAYLOAD enviado a /generar/comprobante:\n";
echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

$client = new SunatClient();

echo "▶ Llamando a /generar/comprobante ...\n";
$gen = $client->generarComprobante($payload);
echo "  HTTP {$gen['http']} · estado=" . var_export($gen['estado'] ?? null, true) . "\n";

if (empty($gen['estado'])) {
    echo "  ✘ Error: " . ($gen['mensaje'] ?? 'sin mensaje') . "\n";
    if (isset($gen['errors'])) echo "  errores: " . json_encode($gen['errors'], JSON_PRETTY_PRINT) . "\n";
    exit;
}

$nombre = $gen['data']['nombre_archivo'];
$xml    = $gen['data']['contenido_xml'];
$qr     = $gen['data']['qr_info'];
echo "  ✔ XML firmado · archivo=$nombre · " . strlen($xml) . " bytes\n";
echo "  qr_info: $qr\n\n";

echo "▶ Llamando a /enviar/documento/electronico ...\n";
$env = $client->enviarDocumento([
    'ruc'                 => SUNAT_RUC,
    'usuario'             => SUNAT_USUARIO_SOL,
    'clave'               => SUNAT_CLAVE_SOL,
    'endpoint'            => SUNAT_ENDPOINT,
    'nombre_documento'    => $nombre,
    'contenido_documento' => $xml,
]);
echo "  HTTP {$env['http']} · estado=" . var_export($env['estado'] ?? null, true) . "\n";
echo "  mensaje: " . ($env['mensaje'] ?? '(sin mensaje)') . "\n";

if (!empty($env['estado'])) {
    echo "\n✅ TODO OK — comprobante aceptado por SUNAT (beta).\n";
    echo "   CDR (base64, primeros 80 chars): " . substr($env['cdr'] ?? '', 0, 80) . "...\n";
} else {
    echo "\n✘ Falló el envío.\n";
}
