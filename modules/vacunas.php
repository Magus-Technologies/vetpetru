<?php
$page = 'vacunas'; $pageTitle = 'Vacunación';
require_once __DIR__ . '/../includes/header.php';
$db = getDB();
$action = $_GET['action'] ?? 'list';
$msg = '';

// ─────────────────────────────────────────────────────────────
// Tipos de vacuna gestionables (con eliminación lógica)
// ─────────────────────────────────────────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS tipos_vacuna (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(100) NOT NULL,
        estado ENUM('activo','suspendido') NOT NULL DEFAULT 'activo',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    // Sembrar con los tipos por defecto la primera vez
    $cnt = (int)$db->query("SELECT COUNT(*) FROM tipos_vacuna")->fetchColumn();
    if ($cnt === 0) {
        $semilla = ['Antirrábica','Óctuple','Séxtuple','Triple Felina','Leucemia Felina','Parvovirus','Mixomatosis','Enfermedad de Newcastle','Otra'];
        $ins = $db->prepare("INSERT INTO tipos_vacuna (nombre) VALUES (?)");
        foreach ($semilla as $s) $ins->execute([$s]);
    }
} catch (Exception $e) {}

// Acciones del gestor de tipos (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action']??''), ['tv_add','tv_edit'], true)) {
    $nombre = trim($_POST['nombre'] ?? '');
    if ($nombre !== '') {
        if (($_POST['action']) === 'tv_add') {
            // Evitar duplicados activos con el mismo nombre
            $dup = $db->prepare("SELECT id FROM tipos_vacuna WHERE nombre=? AND estado='activo'");
            $dup->execute([$nombre]);
            if (!$dup->fetch()) {
                $db->prepare("INSERT INTO tipos_vacuna (nombre,estado) VALUES (?,'activo')")->execute([$nombre]);
            }
        } else {
            $db->prepare("UPDATE tipos_vacuna SET nombre=? WHERE id=?")->execute([$nombre, (int)($_POST['id']??0)]);
        }
    }
    header('Location: ?p=vacunas&action=tipos'); exit;
}
// Suspender (eliminación lógica) / reactivar — vía GET
if ($action === 'tv_suspender' && isset($_GET['id'])) {
    $db->prepare("UPDATE tipos_vacuna SET estado='suspendido' WHERE id=?")->execute([(int)$_GET['id']]);
    header('Location: ?p=vacunas&action=tipos'); exit;
}
if ($action === 'tv_reactivar' && isset($_GET['id'])) {
    $db->prepare("UPDATE tipos_vacuna SET estado='activo' WHERE id=?")->execute([(int)$_GET['id']]);
    header('Location: ?p=vacunas&action=tipos'); exit;
}

// Tipos activos (los que ve el cliente) y todos (para el gestor)
$tipos_activos = $db->query("SELECT id,nombre FROM tipos_vacuna WHERE estado='activo' ORDER BY nombre")->fetchAll();
$tipos_todos   = $db->query("SELECT id,nombre,estado FROM tipos_vacuna ORDER BY estado, nombre")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'save') {
    $fields = ['mascota_id','veterinario_id','tipo_vacuna','laboratorio','lote','fecha_aplicacion','fecha_vencimiento','proxima_dosis','notas'];
    $data=[]; foreach($fields as $f) $data[$f] = trim($_POST[$f]??'') ?: null;
    $cols = implode(',', $fields); $pls = implode(',', array_map(fn($f)=>":$f", $fields));
    $db->prepare("INSERT INTO vacunas ($cols) VALUES ($pls)")->execute($data);
    $msg='success'; $action='list';
}
if ($action==='delete' && isset($_GET['id'])) {
    $db->prepare("DELETE FROM vacunas WHERE id=?")->execute([(int)$_GET['id']]); $action='list';
}

$mascotas_sel = $db->query("SELECT m.id,CONCAT(m.nombre,' (',c.nombre,')') as label FROM mascotas m JOIN clientes c ON c.id=m.cliente_id WHERE m.estado='activo' ORDER BY m.nombre")->fetchAll();
$vets_sel = $db->query("SELECT id,nombre FROM usuarios WHERE rol IN ('veterinario','admin') AND activo=1")->fetchAll();

$mascota_id = (int)($_GET['mascota_id']??0);
$where = "1=1"; $params=[];
if ($mascota_id) { $where .= " AND v.mascota_id=?"; $params[]=$mascota_id; }
$search = trim($_GET['q']??'');
if ($search) { $where .= " AND (m.nombre LIKE ? OR cl.nombre LIKE ? OR v.tipo_vacuna LIKE ?)"; $like="%$search%"; $params=array_merge($params,[$like,$like,$like]); }
$estado_f = $_GET['estado']??'';
if ($estado_f==='vigente')    $where .= " AND v.proxima_dosis > DATE_ADD(CURDATE(),INTERVAL 7 DAY)";
elseif($estado_f==='por_vencer') $where .= " AND v.proxima_dosis BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY)";
elseif($estado_f==='vencida')    $where .= " AND v.proxima_dosis < CURDATE()";
try {
    $_r=$db->query("SHOW COLUMNS FROM `mascotas` LIKE 'sede_id'")->fetchAll();
    if(!empty($_r)) {
        if(!verTodasSedes()) { $where.=" AND m.sede_id=".getSede(); }
    }
} catch(Exception $e) {}

$st = $db->prepare("SELECT v.*,m.nombre as mascota,m.especie,u.nombre as veterinario,cl.nombre as dueno,cl.telefono FROM vacunas v JOIN mascotas m ON m.id=v.mascota_id JOIN usuarios u ON u.id=v.veterinario_id JOIN clientes cl ON cl.id=m.cliente_id WHERE $where ORDER BY v.proxima_dosis ASC");
$st->execute($params); $vacunas = $st->fetchAll();

// Contadores
$st_stats = $db->query("SELECT
  SUM(proxima_dosis > DATE_ADD(CURDATE(),INTERVAL 7 DAY)) as vigente,
  SUM(proxima_dosis BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY)) as por_vencer,
  SUM(proxima_dosis < CURDATE()) as vencida FROM vacunas");
$stats = $st_stats->fetch();

$especie_icons=['perro'=>'🐕','gato'=>'🐈','conejo'=>'🐰','ave'=>'🐦','reptil'=>'🦎','roedor'=>'🐭','otro'=>'🐾'];
?>
<?php if($msg==='success'): ?><div class="alert alert-success mb-2">✅ Vacuna registrada correctamente.</div><?php endif; ?>

<?php if($action==='tipos'):
  $activos = array_filter($tipos_todos, fn($t)=>$t['estado']==='activo');
  $suspendidos = array_filter($tipos_todos, fn($t)=>$t['estado']==='suspendido');
?>
<div class="card" style="max-width:620px">
  <div class="sec-header">
    <div class="sec-title">⚙️ Gestionar tipos de vacuna</div>
    <a href="?p=vacunas&action=nueva" class="btn btn-sm">← Volver</a>
  </div>

  <!-- Agregar nuevo tipo -->
  <form method="POST" style="display:flex;gap:8px;margin:14px 0 18px;align-items:flex-end">
    <input type="hidden" name="action" value="tv_add">
    <div class="form-group" style="flex:1;margin:0">
      <label class="form-label">Nuevo tipo de vacuna</label>
      <input class="form-input" name="nombre" placeholder="Ej: Bordetella" required>
    </div>
    <button type="submit" class="btn btn-primary">＋ Agregar</button>
  </form>

  <!-- Tipos activos -->
  <div style="font-size:12px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">Tipos activos (<?= count($activos) ?>)</div>
  <div style="display:flex;flex-direction:column;gap:6px;margin-bottom:20px">
    <?php if(empty($activos)): ?>
      <div style="font-size:12px;color:var(--text3);padding:8px 0">No hay tipos activos. Agrega uno arriba.</div>
    <?php else: foreach($activos as $t): ?>
    <div style="display:flex;align-items:center;gap:8px;padding:8px 12px;background:var(--bg3);border-radius:8px">
      <form method="POST" style="display:flex;flex:1;gap:6px;align-items:center;margin:0">
        <input type="hidden" name="action" value="tv_edit">
        <input type="hidden" name="id" value="<?= $t['id'] ?>">
        <input class="form-input" name="nombre" value="<?= clean($t['nombre']) ?>" style="flex:1;height:34px;font-size:13px">
        <button type="submit" class="btn btn-sm" title="Guardar cambios">💾</button>
      </form>
      <a href="?p=vacunas&action=tv_suspender&id=<?= $t['id'] ?>"
         onclick="return confirm('¿Eliminar este tipo de la lista? Quedará suspendido (no se borra de la base de datos) y dejará de aparecer al registrar vacunas.')"
         class="btn btn-sm" style="color:var(--danger)" title="Eliminar (suspender)">🗑️</a>
    </div>
    <?php endforeach; endif; ?>
  </div>

  <!-- Tipos suspendidos -->
  <?php if(!empty($suspendidos)): ?>
  <div style="font-size:12px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">Suspendidos (<?= count($suspendidos) ?>) — ocultos para el cliente</div>
  <div style="display:flex;flex-direction:column;gap:6px">
    <?php foreach($suspendidos as $t): ?>
    <div style="display:flex;align-items:center;gap:8px;padding:8px 12px;border:1px dashed var(--border);border-radius:8px;opacity:.75">
      <span style="flex:1;font-size:13px;color:var(--text3);text-decoration:line-through"><?= clean($t['nombre']) ?></span>
      <span class="badge b-gray">Suspendido</span>
      <a href="?p=vacunas&action=tv_reactivar&id=<?= $t['id'] ?>" class="btn btn-sm" style="color:var(--primary)" title="Reactivar">↩️ Reactivar</a>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div style="font-size:11px;color:var(--text3);margin-top:16px;line-height:1.5">
    💡 Al eliminar un tipo, este se marca como <strong>suspendido</strong> en la base de datos
    (no se borra). Las vacunas ya registradas con ese tipo conservan su información.
  </div>
</div>

<?php elseif($action==='nueva'): ?>
<div class="card" style="max-width:680px">
  <div class="sec-header"><div class="sec-title">Registrar Vacuna</div><a href="?p=vacunas" class="btn btn-sm">← Volver</a></div>
  <form method="POST">
    <input type="hidden" name="action" value="save">
    <div class="form-row">
      <div class="form-group" style="position:relative"><label class="form-label required">Mascota</label>
        <input type="text" id="inp-mas-vac" class="form-input" placeholder="🐾 Buscar mascota..." autocomplete="off">
        <input type="hidden" name="mascota_id" id="hid-mas-vac" value="" required>
        <div id="drop-mas-vac" style="display:none;position:absolute;top:100%;left:0;right:0;background:var(--bg2);border:1px solid var(--border);border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:300;max-height:220px;overflow-y:auto"></div>
      </div>
      <div class="form-group" style="position:relative"><label class="form-label required">Veterinario</label>
        <input type="text" id="inp-vet-vac" class="form-input" placeholder="👨‍⚕️ Buscar veterinario..." autocomplete="off">
        <input type="hidden" name="veterinario_id" id="hid-vet-vac" value="" required>
        <div id="drop-vet-vac" style="display:none;position:absolute;top:100%;left:0;right:0;background:var(--bg2);border:1px solid var(--border);border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:300;max-height:200px;overflow-y:auto"></div>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Tipo de vacuna *
          <a href="?p=vacunas&action=tipos" style="font-size:11px;font-weight:600;color:var(--primary);text-decoration:none;margin-left:6px">⚙️ Gestionar</a>
        </label>
        <select class="form-input" name="tipo_vacuna" required>
          <?php if(empty($tipos_activos)): ?><option value="">— Sin tipos, agrega uno en Gestionar —</option><?php endif; ?>
          <?php foreach($tipos_activos as $t): ?><option value="<?= clean($t['nombre']) ?>"><?= clean($t['nombre']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label class="form-label">Laboratorio</label><input class="form-input" name="laboratorio" placeholder="Ej: Zoetis, Nobivac"></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">N° de lote</label><input class="form-input" name="lote" placeholder="Ej: L2025-01"></div>
      <div class="form-group"><label class="form-label">Fecha de vencimiento (vacuna)</label><input class="form-input" type="date" name="fecha_vencimiento"></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Fecha de aplicación *</label><input class="form-input" type="date" name="fecha_aplicacion" value="<?= date('Y-m-d') ?>" required></div>
      <div class="form-group"><label class="form-label">Próxima dosis</label><input class="form-input" type="date" name="proxima_dosis"></div>
    </div>
    <div class="form-group"><label class="form-label">Notas</label><textarea class="form-input" name="notas" style="min-height:60px"></textarea></div>
    <div class="flex gap-1"><button type="submit" class="btn btn-primary">💾 Registrar vacuna</button><a href="?p=vacunas" class="btn">Cancelar</a></div>
  </form>
</div>

<?php else: ?>
<div class="grid g3 mb-2">
  <div class="stat-card"><div class="stat-icon si-teal">✅</div><div class="stat-value"><?= $stats['vigente']??0 ?></div><div class="stat-label">Vacunas vigentes</div></div>
  <div class="stat-card"><div class="stat-icon si-amber">⏰</div><div class="stat-value"><?= $stats['por_vencer']??0 ?></div><div class="stat-label">Por vencer (7 días)</div></div>
  <div class="stat-card" style="border-color:var(--red)"><div class="stat-icon si-red">❌</div><div class="stat-value"><?= $stats['vencida']??0 ?></div><div class="stat-label">Vencidas</div></div>
</div>

<?php if(($stats['por_vencer']??0)+($stats['vencida']??0) > 0): ?>
<div class="alert alert-warn mb-2"><span>⚠️</span><div><strong><?= ($stats['por_vencer']??0)+($stats['vencida']??0) ?> vacuna(s) requieren atención.</strong> Envía recordatorios por WhatsApp a los dueños.</div></div>
<?php endif; ?>

<div class="sec-header">
  <div class="sec-title">Registro de vacunas</div>
  <div class="flex gap-1">
    <form method="GET" class="flex gap-1"><input type="hidden" name="p" value="vacunas">
      <input class="form-input" name="q" value="<?= clean($search) ?>" placeholder="Buscar..." style="width:200px">
      <select class="form-input" name="estado" style="width:150px">
        <option value="">Todos</option>
        <option value="vigente" <?= $estado_f==='vigente'?'selected':'' ?>>Vigentes</option>
        <option value="por_vencer" <?= $estado_f==='por_vencer'?'selected':'' ?>>Por vencer</option>
        <option value="vencida" <?= $estado_f==='vencida'?'selected':'' ?>>Vencidas</option>
      </select>
      <button type="submit" class="btn">Filtrar</button>
    </form>
    <a href="?p=vacunas&action=nueva" class="btn btn-primary">+ Registrar Vacuna</a>
  </div>
</div>

<div class="card" style="padding:0">
  <div class="table-wrap">
    <table class="vtable">
      <thead><tr><th>Mascota</th><th>Dueño</th><th>Vacuna</th><th>Laboratorio / Lote</th><th>Aplicada</th><th>Próxima dosis</th><th>Estado</th><th>Acciones</th></tr></thead>
      <tbody>
        <?php foreach($vacunas as $v):
          $proxima_ts = strtotime($v['proxima_dosis']);
          $dias = ($proxima_ts - time()) / 86400;
          $estado = $dias < 0 ? 'Vencida' : ($dias <= 7 ? 'Por vencer' : 'Vigente');
          $badge = $estado==='Vencida' ? 'b-red' : ($estado==='Por vencer' ? 'b-amber' : 'b-teal');
          $tel = preg_replace('/[^0-9]/','',ltrim($v['telefono'],'+'));
          if(strlen($tel)<11) $tel='51'.$tel;
          $wa_msg="💉 *Alerta de Vacuna — VetPro*\n\nHola {$v['dueno']} 👋\n\nLa vacuna de *{$v['mascota']}* ".($dias<0?'ha vencido':'vence pronto').":\n🗓️ *Vencimiento:* ".date('d/m/Y',$proxima_ts)."\n💉 {$v['tipo_vacuna']}\n\n👉 Agenda su cita respondiendo este mensaje.\n\nVetPro 🐾";
        ?>
        <tr>
          <td><div class="flex items-center gap-1"><span style="font-size:18px"><?= $especie_icons[$v['especie']]??'🐾' ?></span><span class="td-main"><?= clean($v['mascota']) ?></span></div></td>
          <td><?= clean($v['dueno']) ?></td>
          <td class="font-med"><?= clean($v['tipo_vacuna']) ?></td>
          <td class="text-muted text-xs"><?= clean($v['laboratorio']??'—') ?><br><?= clean($v['lote']??'—') ?></td>
          <td class="text-muted"><?= date('d/m/Y',strtotime($v['fecha_aplicacion'])) ?></td>
          <td class="<?= $estado==='Vencida'?'font-bold':'font-med' ?>" style="color:<?= $estado==='Vencida'?'var(--red)':($estado==='Por vencer'?'var(--amber)':'var(--text)') ?>"><?= date('d/m/Y',$proxima_ts) ?></td>
          <td><span class="badge <?= $badge ?>"><span class="dot"></span> <?= $estado ?></span></td>
          <td><div class="flex gap-1">
            <a href="https://wa.me/<?= $tel ?>?text=<?= rawurlencode($wa_msg) ?>" target="_blank" class="btn btn-xs btn-wa" title="Recordatorio WA">💬</a>
            <a href="?p=vacunas&action=delete&id=<?= $v['id'] ?>" class="btn btn-xs" style="color:var(--red)" onclick="return confirm('¿Eliminar este registro?')">✕</a>
          </div></td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($vacunas)): ?><tr><td colspan="8" class="text-center text-muted" style="padding:32px">No se encontraron vacunas.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<?php
$_js_mas = array_map(fn($m)=>['id'=>$m['id'],'label'=>$m['label']], $mascotas_sel??[]);
$_js_vet = array_map(fn($v)=>['id'=>$v['id'],'label'=>$v['nombre']], $vets_sel??[]);
?>
<script>
document.addEventListener('DOMContentLoaded',function(){
    var _M=<?= json_encode(array_values($_js_mas)) ?>;
    var _V=<?= json_encode(array_values($_js_vet)) ?>;
    vetSearchSelect('inp-mas-vac','drop-mas-vac','hid-mas-vac',_M,'label');
    vetSearchSelect('inp-vet-vac','drop-vet-vac','hid-vet-vac',_V,'label');
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
