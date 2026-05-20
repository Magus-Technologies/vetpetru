<?php
require_once __DIR__ . '/../includes/config.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');
$db   = getDB();
$user = getUser();
$act  = $_GET['action'] ?? $_POST['action'] ?? 'list';

// Auto-crear tabla si no existe
try {
    $db->exec("CREATE TABLE IF NOT EXISTS notificaciones (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tipo VARCHAR(30) DEFAULT 'sistema',
        titulo VARCHAR(200) NOT NULL,
        mensaje TEXT,
        icono VARCHAR(20) DEFAULT 'bell',
        color VARCHAR(20) DEFAULT '#3b82f6',
        link VARCHAR(500),
        usuario_id INT,
        sede_id INT DEFAULT 1,
        leida TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch(Exception $e) {}

// ── Generar notificaciones automáticas ──────────────────────
function generarAlertas($db, $user) {
    $sede_id = $user['sede_id'] ?? 1;
    $generadas = 0;

    // 1. Citas en los próximos 30 minutos
    try {
        $citas = $db->query("
            SELECT c.id, m.nombre as mascota, c.hora, c.fecha
            FROM citas c JOIN mascotas m ON m.id=c.mascota_id
            WHERE c.fecha=CURDATE()
            AND c.hora BETWEEN TIME(NOW()) AND ADDTIME(TIME(NOW()),'00:30:00')
            AND c.estado IN ('pendiente','confirmada')
        ")->fetchAll();
        foreach ($citas as $cita) {
            $key = 'cita_'.$cita['id'].'_'.date('Y-m-d');
            $existe = $db->prepare("SELECT id FROM notificaciones WHERE link LIKE ? AND DATE(created_at)=CURDATE()");
            $existe->execute(['%citas%id='.$cita['id'].'%']);
            if (!$existe->fetch()) {
                $db->prepare("INSERT INTO notificaciones (tipo,titulo,mensaje,icono,color,link,sede_id) VALUES (?,?,?,?,?,?,?)")
                   ->execute(['cita',"Cita en 30 min: {$cita['mascota']}","La cita de {$cita['mascota']} es a las ".substr($cita['hora'],0,5),'📅','#3b82f6','?p=calendario',$sede_id]);
                $generadas++;
            }
        }
    } catch(Exception $e) {}

    // 2. Vacunas vencidas o por vencer hoy
    try {
        $vacs = $db->query("
            SELECT v.id, m.nombre as mascota, v.tipo_vacuna, v.proxima_dosis
            FROM vacunas v JOIN mascotas m ON m.id=v.mascota_id
            WHERE v.proxima_dosis <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)
            AND v.proxima_dosis >= DATE_SUB(CURDATE(), INTERVAL 1 DAY)
        ")->fetchAll();
        foreach ($vacs as $vac) {
            $existe = $db->prepare("SELECT id FROM notificaciones WHERE tipo='vacuna' AND titulo LIKE ? AND DATE(created_at)=CURDATE()");
            $existe->execute(['%'.$vac['mascota'].'%']);
            if (!$existe->fetch()) {
                $vencida = strtotime($vac['proxima_dosis']) < strtotime(date('Y-m-d'));
                $db->prepare("INSERT INTO notificaciones (tipo,titulo,mensaje,icono,color,link,sede_id) VALUES (?,?,?,?,?,?,?)")
                   ->execute(['vacuna',
                     ($vencida?'Vacuna VENCIDA':'Vacuna próxima').': '.$vac['mascota'],
                     "La vacuna {$vac['tipo_vacuna']} de {$vac['mascota']} ".($vencida?'venció el':'vence el').' '.date('d/m/Y',strtotime($vac['proxima_dosis'])),
                     '💉', $vencida?'#ef4444':'#f59e0b',
                     '?p=vacunas', $sede_id]);
                $generadas++;
            }
        }
    } catch(Exception $e) {}

    // 3. Stock crítico
    try {
        $stocks = $db->query("
            SELECT id, nombre, stock, stock_minimo
            FROM productos WHERE stock <= stock_minimo AND activo=1 LIMIT 5
        ")->fetchAll();
        foreach ($stocks as $prod) {
            $existe = $db->prepare("SELECT id FROM notificaciones WHERE tipo='stock' AND titulo LIKE ? AND DATE(created_at)=CURDATE()");
            $existe->execute(['%'.$prod['nombre'].'%']);
            if (!$existe->fetch()) {
                $db->prepare("INSERT INTO notificaciones (tipo,titulo,mensaje,icono,color,link,sede_id) VALUES (?,?,?,?,?,?,?)")
                   ->execute(['stock',"Stock crítico: {$prod['nombre']}","Quedan {$prod['stock']} unidades (mínimo: {$prod['stock_minimo']})",'📦','#ef4444','?p=farmacia',$sede_id]);
                $generadas++;
            }
        }
    } catch(Exception $e) {}

    // 4. Partos bovinos próximos (7 días)
    try {
        $partos = $db->query("
            SELECT p.fecha_probable_parto, a.nombre, a.numero_arete
            FROM gv_prenez p JOIN gv_animales a ON a.id=p.animal_id
            WHERE p.resultado='prenada'
            AND p.fecha_probable_parto BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        ")->fetchAll();
        foreach ($partos as $p) {
            $existe = $db->prepare("SELECT id FROM notificaciones WHERE tipo='parto' AND titulo LIKE ? AND DATE(created_at)=CURDATE()");
            $existe->execute(['%'.($p['nombre']??$p['numero_arete']).'%']);
            if (!$existe->fetch()) {
                $dias = (int)ceil((strtotime($p['fecha_probable_parto'])-time())/86400);
                $db->prepare("INSERT INTO notificaciones (tipo,titulo,mensaje,icono,color,link,sede_id) VALUES (?,?,?,?,?,?,?)")
                   ->execute(['parto',"Parto próximo: ".($p['nombre']??$p['numero_arete']),"Se espera el parto en $dias día".($dias!=1?'s':'').' ('.date('d/m/Y',strtotime($p['fecha_probable_parto'])).')','🐄','#10b981','?p=ganado&sub=reproduccion&rtab=partos',$sede_id]);
                $generadas++;
            }
        }
    } catch(Exception $e) {}

    return $generadas;
}

// ── ACCIONES ──────────────────────────────────────────────────
if ($act === 'list') {
    // Generar alertas automáticas primero
    try { generarAlertas($db, $user); } catch(Exception $e) {}

    $limit = min((int)($_GET['limit']??20), 50);
    $solo_no_leidas = ($_GET['sin_leer']??'0') === '1';

    try {
        $where = $solo_no_leidas ? "AND leida=0" : "";
        $notifs = $db->query("
            SELECT * FROM notificaciones
            WHERE (usuario_id={$user['id']} OR usuario_id IS NULL)
            AND (sede_id={$user['sede_id']} OR sede_id IS NULL)
            $where
            ORDER BY created_at DESC LIMIT $limit
        ")->fetchAll();
        $sin_leer = (int)$db->query("SELECT COUNT(*) FROM notificaciones WHERE leida=0 AND (usuario_id={$user['id']} OR usuario_id IS NULL)")->fetchColumn();
        echo json_encode(['ok'=>true,'notifs'=>$notifs,'sin_leer'=>$sin_leer]);
    } catch(Exception $e) {
        echo json_encode(['ok'=>true,'notifs'=>[],'sin_leer'=>0]);
    }
    exit;
}

if ($act === 'marcar_leida') {
    $id = (int)($_POST['id']??0);
    try {
        if ($id) $db->prepare("UPDATE notificaciones SET leida=1 WHERE id=?")->execute([$id]);
        else $db->exec("UPDATE notificaciones SET leida=1 WHERE usuario_id={$user['id']} OR usuario_id IS NULL");
        echo json_encode(['ok'=>true]);
    } catch(Exception $e) { echo json_encode(['ok'=>false]); }
    exit;
}

if ($act === 'marcar_todas') {
    try {
        $db->exec("UPDATE notificaciones SET leida=1 WHERE (usuario_id={$user['id']} OR usuario_id IS NULL)");
        echo json_encode(['ok'=>true]);
    } catch(Exception $e) { echo json_encode(['ok'=>false]); }
    exit;
}

if ($act === 'crear') {
    $titulo  = trim($_POST['titulo']??'');
    $mensaje = trim($_POST['mensaje']??'');
    $tipo    = $_POST['tipo']??'custom';
    $icono   = $_POST['icono']??'🔔';
    $color   = $_POST['color']??'#3b82f6';
    $link    = $_POST['link']??'';
    if ($titulo) {
        try {
            $db->prepare("INSERT INTO notificaciones (tipo,titulo,mensaje,icono,color,link,usuario_id,sede_id) VALUES (?,?,?,?,?,?,?,?)")
               ->execute([$tipo,$titulo,$mensaje,$icono,$color,$link,$user['id'],$user['sede_id']??1]);
            echo json_encode(['ok'=>true]);
        } catch(Exception $e) { echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
    } else {
        echo json_encode(['ok'=>false,'error'=>'Título requerido']);
    }
    exit;
}

if ($act === 'count') {
    try {
        generarAlertas($db, $user);
        $n = (int)$db->query("SELECT COUNT(*) FROM notificaciones WHERE leida=0 AND (usuario_id={$user['id']} OR usuario_id IS NULL)")->fetchColumn();
        echo json_encode(['ok'=>true,'count'=>$n]);
    } catch(Exception $e) { echo json_encode(['ok'=>true,'count'=>0]); }
    exit;
}

echo json_encode(['ok'=>false,'error'=>'Acción desconocida']);
