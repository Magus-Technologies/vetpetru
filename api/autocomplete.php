<?php
require_once __DIR__ . '/../includes/config.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) {
    echo json_encode(['ok'=>true,'mascotas'=>[],'clientes'=>[],'consultas'=>[],'facturas'=>[],'citas'=>[]]);
    exit;
}

$db   = getDB();
$like = "%$q%";

// 1. Mascotas
$st = $db->prepare("SELECT m.id, m.nombre, m.especie, m.raza, m.foto, c.nombre as dueno, c.telefono FROM mascotas m JOIN clientes c ON c.id=m.cliente_id WHERE m.estado='activo' AND (m.nombre LIKE ? OR m.raza LIKE ? OR c.nombre LIKE ?) ORDER BY m.nombre ASC LIMIT 6");
$st->execute([$like,$like,$like]);
$mascotas = $st->fetchAll(PDO::FETCH_ASSOC);
foreach ($mascotas as &$m) {
    $m['foto_url'] = ($m['foto'] && file_exists(UPLOADS_PATH.'/'.$m['foto'])) ? UPLOADS_URL.'/'.$m['foto'] : null;
}
unset($m);

// 2. Clientes
$st = $db->prepare("SELECT id, nombre, telefono, dni FROM clientes WHERE activo=1 AND (nombre LIKE ? OR telefono LIKE ? OR dni LIKE ?) ORDER BY nombre ASC LIMIT 4");
$st->execute([$like,$like,$like]);
$clientes = $st->fetchAll(PDO::FETCH_ASSOC);

// 3. Consultas / Historia clínica
$st = $db->prepare("SELECT con.id, con.diagnostico, con.fecha, m.id as mascota_id, m.nombre as mascota, m.especie FROM consultas con JOIN mascotas m ON m.id=con.mascota_id WHERE con.diagnostico LIKE ? OR con.sintomas LIKE ? ORDER BY con.fecha DESC LIMIT 3");
$st->execute([$like,$like]);
$consultas = $st->fetchAll(PDO::FETCH_ASSOC);

// 4. Facturas / Comprobantes
$facturas = [];
try {
    $st = $db->prepare("SELECT v.id, v.serie, v.numero, v.total, v.fecha, v.estado, v.tipo_comprobante, c.nombre as cliente FROM ventas v JOIN clientes c ON c.id=v.cliente_id WHERE c.nombre LIKE ? OR CONCAT(v.serie,'-',v.numero) LIKE ? ORDER BY v.fecha DESC LIMIT 3");
    $st->execute([$like, $like]);
    $facturas = $st->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {}

// 5. Citas
$citas_r = [];
try {
    $st = $db->prepare("SELECT ci.id, ci.fecha, ci.hora, ci.estado, ci.tipo_servicio, m.nombre as mascota, m.especie, c.nombre as cliente FROM citas ci JOIN mascotas m ON m.id=ci.mascota_id JOIN clientes c ON c.id=m.cliente_id WHERE m.nombre LIKE ? OR c.nombre LIKE ? ORDER BY ci.fecha DESC LIMIT 3");
    $st->execute([$like,$like]);
    $citas_r = $st->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {}

echo json_encode([
    'ok'       => true,
    'q'        => $q,
    'mascotas' => $mascotas,
    'clientes' => $clientes,
    'consultas'=> $consultas,
    'facturas' => $facturas,
    'citas'    => $citas_r,
], JSON_UNESCAPED_UNICODE);
