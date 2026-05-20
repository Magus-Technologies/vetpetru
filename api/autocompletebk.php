<?php
/**
 * VetPro — API Autocomplete
 * /vetpro/api/autocomplete.php
 * Retorna JSON puro para el buscador en tiempo real
 */
require_once __DIR__ . '/../includes/config.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) {
    echo json_encode(['ok'=>true,'mascotas'=>[],'clientes'=>[],'consultas'=>[]]);
    exit;
}

$db   = getDB();
$like = "%$q%";

// Mascotas (con foto para mostrar en el dropdown)
$st = $db->prepare("
    SELECT m.id, m.nombre, m.especie, m.raza, m.foto,
           c.nombre as dueno, c.telefono
    FROM mascotas m
    JOIN clientes c ON c.id = m.cliente_id
    WHERE m.estado='activo'
      AND (m.nombre LIKE ? OR m.raza LIKE ? OR c.nombre LIKE ?)
    ORDER BY m.nombre ASC
    LIMIT 7
");
$st->execute([$like, $like, $like]);
$mascotas = $st->fetchAll(PDO::FETCH_ASSOC);

// Quitar la ruta completa del servidor en foto — dejar solo nombre relativo
foreach ($mascotas as &$m) {
    if ($m['foto'] && file_exists(UPLOADS_PATH . '/' . $m['foto'])) {
        $m['foto_url'] = UPLOADS_URL . '/' . $m['foto'];
    } else {
        $m['foto_url'] = null;
    }
}
unset($m);

// Clientes
$st = $db->prepare("
    SELECT id, nombre, telefono, dni
    FROM clientes
    WHERE activo=1
      AND (nombre LIKE ? OR telefono LIKE ? OR dni LIKE ?)
    ORDER BY nombre ASC
    LIMIT 4
");
$st->execute([$like, $like, $like]);
$clientes = $st->fetchAll(PDO::FETCH_ASSOC);

// Consultas recientes con ese diagnóstico
$st = $db->prepare("
    SELECT con.id, con.diagnostico, con.fecha,
           m.id as mascota_id, m.nombre as mascota, m.especie
    FROM consultas con
    JOIN mascotas m ON m.id = con.mascota_id
    WHERE con.diagnostico LIKE ? OR con.sintomas LIKE ?
    ORDER BY con.fecha DESC
    LIMIT 3
");
$st->execute([$like, $like]);
$consultas = $st->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'ok'       => true,
    'q'        => $q,
    'mascotas' => $mascotas,
    'clientes' => $clientes,
    'consultas'=> $consultas,
], JSON_UNESCAPED_UNICODE);
