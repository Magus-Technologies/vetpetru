<?php
/* ============================================================
   API de Autocompletado / Búsqueda global — VetPro
   GET ?q=texto  → busca en mascotas, clientes, facturas, citas
   Devuelve JSON: {mascotas:[], clientes:[], facturas:[], citas:[]}
   ============================================================ */
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json; charset=utf-8');

try { if (function_exists('requireLogin')) requireLogin(); }
catch (Exception $e) { echo json_encode(['error'=>'no auth']); exit; }

$db = getDB();
$q  = trim($_GET['q'] ?? '');
if (mb_strlen($q) < 2) { echo json_encode(['mascotas'=>[],'clientes'=>[],'facturas'=>[],'citas'=>[]]); exit; }

$like = '%'.$q.'%';

// Filtro de sede opcional
$sedeM = $sedeV = $sedeC = '';
try {
    if (function_exists('verTodasSedes') && !verTodasSedes()) {
        $sid = (int)getSede();
        $sedeM = " AND m.sede_id=$sid";
        $sedeV = " AND v.sede_id=$sid";
        $sedeC = " AND ci.sede_id=$sid";
    }
} catch(Exception $e){}

$res = ['mascotas'=>[], 'clientes'=>[], 'facturas'=>[], 'citas'=>[]];

// ── Mascotas ──
try {
    $st = $db->prepare(
        "SELECT m.id, m.nombre, m.especie, m.raza, m.foto, c.nombre AS dueno
         FROM mascotas m LEFT JOIN clientes c ON c.id=m.cliente_id
         WHERE m.estado='activo' AND (m.nombre LIKE ? OR c.nombre LIKE ? OR m.especie LIKE ?)$sedeM
         ORDER BY m.nombre ASC LIMIT 6"
    );
    $st->execute([$like,$like,$like]);
    foreach ($st->fetchAll() as $m) {
        $foto_url = (!empty($m['foto']) && file_exists(UPLOADS_PATH.'/'.$m['foto']))
            ? BASE_URL.'/public/uploads/'.$m['foto'] : null;
        $res['mascotas'][] = [
            'id'=>(int)$m['id'], 'nombre'=>$m['nombre'], 'especie'=>$m['especie'],
            'raza'=>$m['raza'], 'dueno'=>$m['dueno'] ?: '—', 'foto_url'=>$foto_url,
        ];
    }
} catch(Exception $e){}

// ── Clientes ──
try {
    $st = $db->prepare(
        "SELECT id, nombre, telefono, dni FROM clientes
         WHERE activo=1 AND (nombre LIKE ? OR telefono LIKE ? OR dni LIKE ?)
         ORDER BY nombre ASC LIMIT 6"
    );
    $st->execute([$like,$like,$like]);
    foreach ($st->fetchAll() as $c) {
        $res['clientes'][] = [
            'id'=>(int)$c['id'], 'nombre'=>$c['nombre'],
            'telefono'=>$c['telefono'], 'dni'=>$c['dni'],
        ];
    }
} catch(Exception $e){}

// ── Facturas / ventas (por número o cliente) ──
try {
    $st = $db->prepare(
        "SELECT v.id, v.serie, v.numero, v.total, COALESCE(c.nombre,'—') AS cliente
         FROM ventas v LEFT JOIN clientes c ON c.id=v.cliente_id
         WHERE (CONCAT(v.serie,'-',v.numero) LIKE ? OR v.numero LIKE ? OR c.nombre LIKE ?)
         ORDER BY v.fecha DESC LIMIT 5"
    );
    $st->execute([$like,$like,$like]);
    foreach ($st->fetchAll() as $f) {
        $res['facturas'][] = [
            'id'=>(int)$f['id'], 'serie'=>$f['serie'], 'numero'=>(int)$f['numero'],
            'cliente'=>$f['cliente'], 'total'=>$f['total'],
        ];
    }
} catch(Exception $e){}

// ── Citas (por mascota o cliente) ──
try {
    $st = $db->prepare(
        "SELECT ci.id, ci.fecha, ci.hora, ci.estado, m.nombre AS mascota, COALESCE(c.nombre,'—') AS cliente
         FROM citas ci
         JOIN mascotas m ON m.id=ci.mascota_id
         LEFT JOIN clientes c ON c.id=m.cliente_id
         WHERE (m.nombre LIKE ? OR c.nombre LIKE ?)$sedeC
         ORDER BY ci.fecha DESC LIMIT 5"
    );
    $st->execute([$like,$like]);
    foreach ($st->fetchAll() as $c) {
        $res['citas'][] = [
            'id'=>(int)$c['id'], 'mascota'=>$c['mascota'], 'cliente'=>$c['cliente'],
            'fecha'=>$c['fecha'], 'hora'=>$c['hora'], 'estado'=>$c['estado'],
        ];
    }
} catch(Exception $e){}

echo json_encode($res);
