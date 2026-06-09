<?php
/* ============================================================
   API de Notificaciones — VetPro
   Genera notificaciones "al vuelo" desde datos reales:
   - Citas de hoy (pendientes/confirmadas)
   - Vacunas próximas a vencer (7 días)
   - Productos con stock bajo (farmacia y petshop)
   Devuelve JSON. Endpoints:
     GET  ?action=list&limit=20   → lista + sin_leer
     GET  ?action=count           → solo el conteo sin leer
     POST action=marcar_leida&id=
     POST action=marcar_todas
   ============================================================ */
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json; charset=utf-8');

try {
    if (function_exists('requireLogin')) requireLogin();
} catch (Exception $e) {
    echo json_encode(['ok'=>false,'error'=>'no auth']); exit;
}

$db = getDB();

// Tabla para recordar qué notificaciones marcó leídas cada usuario.
// Se auto-crea. Guardamos una "clave" estable por notificación generada.
try {
    $db->exec("CREATE TABLE IF NOT EXISTS notif_leidas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        usuario_id INT NOT NULL,
        notif_clave VARCHAR(120) NOT NULL,
        leida_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user_clave (usuario_id, notif_clave)
    )");
} catch (Exception $e) {}

$user = function_exists('getUser') ? getUser() : ($GLOBALS['user'] ?? []);
$uid  = (int)($user['id'] ?? 0);

// Filtro por sede
$sede_sql = '';
try { if (function_exists('verTodasSedes') && !verTodasSedes()) $sede_sql = ' AND sede_id='.(int)getSede(); } catch(Exception $e){}

// ── POST: marcar leída(s) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['action'] ?? '';
    if ($accion === 'marcar_leida') {
        $clave = $_POST['id'] ?? ''; // usamos la "clave" como id
        if ($clave !== '') {
            try {
                $db->prepare("INSERT IGNORE INTO notif_leidas (usuario_id,notif_clave) VALUES (?,?)")
                   ->execute([$uid, $clave]);
            } catch(Exception $e){}
        }
        echo json_encode(['ok'=>true]); exit;
    }
    if ($accion === 'marcar_todas') {
        // Marcamos todas las claves actualmente generadas
        $claves = generar_notifs($db, $sede_sql, $uid, true);
        try {
            $ins = $db->prepare("INSERT IGNORE INTO notif_leidas (usuario_id,notif_clave) VALUES (?,?)");
            foreach ($claves as $c) $ins->execute([$uid, $c['clave']]);
        } catch(Exception $e){}
        echo json_encode(['ok'=>true]); exit;
    }
    echo json_encode(['ok'=>false,'error'=>'accion no valida']); exit;
}

// ── GET: generar y devolver ──
$accion = $_GET['action'] ?? 'list';
$todas  = generar_notifs($db, $sede_sql, $uid, false);

// Marcar cuáles están leídas
$leidas = [];
try {
    $st = $db->prepare("SELECT notif_clave FROM notif_leidas WHERE usuario_id=?");
    $st->execute([$uid]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) $leidas[$c] = true;
} catch(Exception $e){}

$sin_leer = 0;
foreach ($todas as &$n) {
    $n['leida'] = isset($leidas[$n['clave']]) ? 1 : 0;
    $n['id'] = $n['clave']; // el footer usa "id" para marcar; usamos la clave
    if (!$n['leida']) $sin_leer++;
}
unset($n);

if ($accion === 'count') {
    echo json_encode(['ok'=>true,'count'=>$sin_leer]); exit;
}

// action=list
$limit = (int)($_GET['limit'] ?? 20);
$todas = array_slice($todas, 0, $limit);
echo json_encode(['ok'=>true,'sin_leer'=>$sin_leer,'notifs'=>$todas]); exit;


/* ── Genera la lista de notificaciones desde datos reales ── */
function generar_notifs($db, $sede_sql, $uid, $solo_claves) {
    $out = [];
    $hoy = date('Y-m-d');

    // 1) Citas de HOY pendientes/confirmadas
    try {
        $sql = "SELECT c.id, c.hora, c.tipo, m.nombre AS mascota
                FROM citas c JOIN mascotas m ON m.id=c.mascota_id
                WHERE c.fecha='$hoy' AND c.estado IN ('pendiente','confirmada')"
                . str_replace('sede_id','c.sede_id',$sede_sql) . "
                ORDER BY c.hora ASC LIMIT 15";
        foreach ($db->query($sql)->fetchAll() as $c) {
            $hora = $c['hora'] ? substr($c['hora'],0,5) : '';
            $out[] = [
                'clave'      => 'cita_'.$c['id'],
                'titulo'     => 'Cita hoy: '.$c['mascota'],
                'mensaje'    => trim(($hora?($hora.' · '):'').ucfirst($c['tipo'] ?? 'consulta')),
                'icono'      => 'cita',
                'color'      => '#3b82f6',
                'link'       => BASE_URL.'/index.php?p=citas',
                'created_at' => $hoy.' 08:00:00',
                'orden'      => 1,
            ];
        }
    } catch(Exception $e){}

    // 2) Vacunas por vencer (próximos 7 días)
    try {
        $sql = "SELECT v.id, v.proxima_dosis, m.nombre AS mascota
                FROM vacunas v JOIN mascotas m ON m.id=v.mascota_id
                WHERE v.proxima_dosis IS NOT NULL
                  AND v.proxima_dosis BETWEEN '$hoy' AND DATE_ADD('$hoy', INTERVAL 7 DAY)"
                . str_replace('sede_id','v.sede_id',$sede_sql) . "
                ORDER BY v.proxima_dosis ASC LIMIT 15";
        foreach ($db->query($sql)->fetchAll() as $v) {
            $f = date('d/m', strtotime($v['proxima_dosis']));
            $out[] = [
                'clave'      => 'vac_'.$v['id'].'_'.$v['proxima_dosis'],
                'titulo'     => 'Vacuna por vencer: '.$v['mascota'],
                'mensaje'    => 'Próxima dosis el '.$f,
                'icono'      => 'vacuna',
                'color'      => '#10b981',
                'link'       => BASE_URL.'/index.php?p=vacunas',
                'created_at' => $v['proxima_dosis'].' 08:00:00',
                'orden'      => 2,
            ];
        }
    } catch(Exception $e){}

    // 3) Stock bajo en farmacia
    try {
        $sql = "SELECT id, nombre, stock, stock_minimo FROM productos
                WHERE activo=1 AND stock <= stock_minimo" . $sede_sql . "
                ORDER BY stock ASC LIMIT 10";
        foreach ($db->query($sql)->fetchAll() as $p) {
            $out[] = [
                'clave'      => 'stockfarm_'.$p['id'],
                'titulo'     => 'Stock bajo: '.$p['nombre'],
                'mensaje'    => 'Quedan '.(int)$p['stock'].' uds (mínimo '.(int)$p['stock_minimo'].')',
                'icono'      => 'stock',
                'color'      => '#f59e0b',
                'link'       => BASE_URL.'/index.php?p=farmacia',
                'created_at' => date('Y-m-d H:i:s'),
                'orden'      => 3,
            ];
        }
    } catch(Exception $e){}

    // 4) Stock bajo en petshop
    try {
        $sql = "SELECT id, nombre, stock, stock_minimo FROM petshop_productos
                WHERE activo=1 AND stock <= stock_minimo" . $sede_sql . "
                ORDER BY stock ASC LIMIT 10";
        foreach ($db->query($sql)->fetchAll() as $p) {
            $out[] = [
                'clave'      => 'stockps_'.$p['id'],
                'titulo'     => 'Stock bajo (Pet Shop): '.$p['nombre'],
                'mensaje'    => 'Quedan '.(int)$p['stock'].' uds (mínimo '.(int)$p['stock_minimo'].')',
                'icono'      => 'stock',
                'color'      => '#f97316',
                'link'       => BASE_URL.'/index.php?p=petshop',
                'created_at' => date('Y-m-d H:i:s'),
                'orden'      => 3,
            ];
        }
    } catch(Exception $e){}

    // Ordenar por prioridad y fecha
    usort($out, fn($a,$b) => ($a['orden'] <=> $b['orden']) ?: strcmp($b['created_at'],$a['created_at']));

    if ($solo_claves) return array_map(fn($n)=>['clave'=>$n['clave']], $out);
    return $out;
}
