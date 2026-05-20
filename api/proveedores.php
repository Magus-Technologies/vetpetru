<?php
require_once __DIR__ . '/../includes/config.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');
$db  = getDB();
$act = $_POST['action'] ?? $_GET['action'] ?? 'list';

// Auto-crear tabla
try {
    $db->exec("CREATE TABLE IF NOT EXISTS proveedores (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(200) NOT NULL,
        ruc VARCHAR(20), contacto VARCHAR(150),
        telefono VARCHAR(30), email VARCHAR(150),
        direccion TEXT, activo TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch(Exception $e){}

if ($act === 'list') {
    try {
        $q = trim($_GET['q'] ?? '');
        if ($q) {
            $st = $db->prepare("SELECT * FROM proveedores WHERE activo=1 AND (nombre LIKE ? OR ruc LIKE ?) ORDER BY nombre LIMIT 10");
            $st->execute(["%$q%", "%$q%"]);
        } else {
            $st = $db->query("SELECT * FROM proveedores WHERE activo=1 ORDER BY nombre LIMIT 50");
        }
        echo json_encode(['ok'=>true,'data'=>$st->fetchAll()]);
    } catch(Exception $e) { echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
    exit;
}

if ($act === 'save') {
    $id     = (int)($_POST['id'] ?? 0);
    $nombre = trim($_POST['nombre'] ?? '');
    $ruc    = trim($_POST['ruc'] ?? '');
    if (!$nombre) { echo json_encode(['ok'=>false,'error'=>'Nombre requerido']); exit; }

    // Validar duplicado por nombre
    $dup = $db->prepare("SELECT id FROM proveedores WHERE nombre=? AND activo=1 AND id!=?");
    $dup->execute([$nombre, $id]);
    if ($dup->fetch()) { echo json_encode(['ok'=>false,'error'=>'Ya existe un proveedor con ese nombre: "'.$nombre.'"']); exit; }

    // Validar duplicado por RUC si se ingresó
    if ($ruc) {
        $dup2 = $db->prepare("SELECT id,nombre FROM proveedores WHERE ruc=? AND activo=1 AND id!=?");
        $dup2->execute([$ruc, $id]);
        $existe_ruc = $dup2->fetch();
        if ($existe_ruc) { echo json_encode(['ok'=>false,'error'=>'RUC '.$ruc.' ya está registrado para: "'.$existe_ruc['nombre'].'"']); exit; }
    }

    $f = ['nombre','ruc','contacto','telefono','email','direccion'];
    $d = []; foreach($f as $k) $d[$k] = trim($_POST[$k]??'')?:null;
    try {
        if ($id) {
            $sets = implode(',', array_map(fn($k)=>"$k=:$k", $f));
            $db->prepare("UPDATE proveedores SET $sets WHERE id=:id")->execute(array_merge($d,['id'=>$id]));
        } else {
            $cols = implode(',', $f); $pls = implode(',', array_map(fn($k)=>":$k", $f));
            $db->prepare("INSERT INTO proveedores ($cols) VALUES ($pls)")->execute($d);
            $id = (int)$db->lastInsertId();
        }
        $prov = $db->prepare("SELECT * FROM proveedores WHERE id=?"); $prov->execute([$id]); $prov=$prov->fetch();
        echo json_encode(['ok'=>true,'id'=>$id,'nombre'=>$prov['nombre'],'ruc'=>$prov['ruc']??'']);
    } catch(Exception $e) { echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
    exit;
}

if ($act === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    try { $db->prepare("UPDATE proveedores SET activo=0 WHERE id=?")->execute([$id]); echo json_encode(['ok'=>true]); }
    catch(Exception $e) { echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
    exit;
}

echo json_encode(['ok'=>false,'error'=>'Acción desconocida']);
