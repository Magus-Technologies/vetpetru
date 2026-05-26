<?php
$page = 'personal'; $pageTitle = 'Personal';
require_once __DIR__ . '/../includes/header.php';
if (!hasRole(['admin'])) {
    echo '<div class="alert alert-warn">Solo administradores.</div>';
    require_once __DIR__.'/../includes/footer.php'; exit;
}
$db = getDB();

// Auto-crear tabla usuario_sedes para multi-sede
try {
    $db->exec("CREATE TABLE IF NOT EXISTS usuario_sedes (
        usuario_id INT NOT NULL,
        sede_id INT NOT NULL,
        PRIMARY KEY (usuario_id, sede_id)
    )");
} catch(Exception $e){}

// Auto-crear columnas para firma y colegiatura del veterinario (idempotente)
try {
    $_ucols = $db->query("SHOW COLUMNS FROM `usuarios`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('colegiatura', $_ucols)) $db->exec("ALTER TABLE `usuarios` ADD COLUMN `colegiatura` VARCHAR(50) DEFAULT NULL");
    if (!in_array('firma', $_ucols))       $db->exec("ALTER TABLE `usuarios` ADD COLUMN `firma` VARCHAR(255) DEFAULT NULL");
} catch(Exception $e){}

$action = $_GET['action'] ?? 'list';
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pa = $_POST['action'] ?? '';

    if ($pa === 'save') {
        $id  = (int)($_POST['id'] ?? 0);
        $pw  = trim($_POST['password'] ?? '');
        $fields = ['nombre','email','rol','especialidad','telefono','turno','colegiatura'];
        $data = []; foreach ($fields as $f) $data[$f] = trim($_POST[$f] ?? '');
        $data['activo']  = isset($_POST['activo']) ? 1 : 0;
        // Sede principal (primera sede o la seleccionada)
        $sedes_sel = $_POST['sedes_ids'] ?? [];
        if (empty($sedes_sel)) $sedes_sel = [$_POST['sede_id'] ?? $user['sede_id'] ?? 1];
        $data['sede_id'] = (int)$sedes_sel[0];
        try {
            if ($id) {
                if ($pw) {
                    $data['password'] = password_hash($pw, PASSWORD_BCRYPT);
                    $all  = array_merge($fields, ['activo','sede_id','password']);
                } else {
                    $all  = array_merge($fields, ['activo','sede_id']);
                }
                $sets = implode(',', array_map(fn($f)=>"$f=:$f", $all));
                $data['id'] = $id;
                $db->prepare("UPDATE usuarios SET $sets WHERE id=:id")->execute($data);
            } else {
                if (!$pw) { $msg = 'error_pass'; goto fin; }
                $dup = $db->prepare("SELECT id FROM usuarios WHERE email=?");
                $dup->execute([$data['email']]);
                if ($dup->fetch()) { $msg = 'error_dup'; goto fin; }
                $data['password'] = password_hash($pw, PASSWORD_BCRYPT);
                $all = array_merge($fields, ['activo','sede_id','password']);
                $cols = implode(',', $all);
                $pls  = implode(',', array_map(fn($f)=>":$f", $all));
                $db->prepare("INSERT INTO usuarios ($cols) VALUES ($pls)")->execute($data);
                $id = (int)$db->lastInsertId();
            }
            // Guardar sedes múltiples
            $db->prepare("DELETE FROM usuario_sedes WHERE usuario_id=?")->execute([$id]);
            foreach ($sedes_sel as $sid) {
                $sid = (int)$sid;
                if ($sid) $db->prepare("INSERT IGNORE INTO usuario_sedes (usuario_id,sede_id) VALUES (?,?)")->execute([$id,$sid]);
            }
            // Subir firma del veterinario (opcional)
            if (!empty($_FILES['firma']['tmp_name']) && $_FILES['firma']['error']===0) {
                $mime = mime_content_type($_FILES['firma']['tmp_name']);
                $exts = ['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'];
                if (isset($exts[$mime])) {
                    $dir = UPLOADS_PATH . '/firmas';
                    if (!is_dir($dir)) @mkdir($dir, 0775, true);
                    $fname = 'firma_'.$id.'_'.time().'.'.$exts[$mime];
                    if (move_uploaded_file($_FILES['firma']['tmp_name'], $dir.'/'.$fname)) {
                        $db->prepare("UPDATE usuarios SET firma=? WHERE id=?")->execute(['firmas/'.$fname, $id]);
                    }
                }
            }
            $msg = 'success'; $action = 'list';
        } catch(Exception $e) { $msg = 'error:'.$e->getMessage(); }
        fin:
    }

    if ($pa === 'toggle') {
        try {
            $db->prepare("UPDATE usuarios SET activo=NOT activo WHERE id=?")->execute([(int)$_POST['id']]);
            $msg = 'success'; $action = 'list';
        } catch(Exception $e) { $msg = 'error:'.$e->getMessage(); }
    }

    if ($pa === 'reset_pass') {
        $id  = (int)($_POST['id'] ?? 0);
        $pw  = trim($_POST['nueva_pass'] ?? '');
        $pw2 = trim($_POST['nueva_pass2'] ?? '');
        if (!$pw || strlen($pw) < 6) { $msg = 'error:Minimo 6 caracteres.'; }
        elseif ($pw !== $pw2) { $msg = 'error:Las contrasenas no coinciden.'; }
        else {
            try {
                $db->prepare("UPDATE usuarios SET password=? WHERE id=?")->execute([password_hash($pw,PASSWORD_BCRYPT),$id]);
                $msg = 'pass_ok';
            } catch(Exception $e) { $msg = 'error:'.$e->getMessage(); }
        }
        $action = 'list';
    }
}

$editing = null;
$editing_sedes = [];
if ($action === 'editar' && isset($_GET['id'])) {
    $st = $db->prepare("SELECT * FROM usuarios WHERE id=?");
    $st->execute([(int)$_GET['id']]); $editing = $st->fetch();
    if (!$editing) { $action = 'list'; }
    else {
        $es = $db->prepare("SELECT sede_id FROM usuario_sedes WHERE usuario_id=?");
        $es->execute([$editing['id']]);
        $editing_sedes = array_column($es->fetchAll(), 'sede_id');
        if (empty($editing_sedes)) $editing_sedes = [$editing['sede_id']];
    }
}

$sedes = [];
try { $sedes = $db->query("SELECT id,nombre,color FROM sedes ORDER BY nombre")->fetchAll(); } catch(Exception $e){}
$personal = [];
try { $personal = $db->query("SELECT u.*, s.nombre as sede_nombre, s.color as sede_color FROM usuarios u LEFT JOIN sedes s ON s.id=u.sede_id ORDER BY u.rol,u.nombre")->fetchAll(); } catch(Exception $e){}

// Cargar sedes de cada usuario
$usuario_sedes_map = [];
try {
    $rows = $db->query("SELECT usuario_id, sede_id FROM usuario_sedes")->fetchAll();
    foreach($rows as $r) $usuario_sedes_map[$r['usuario_id']][] = $r['sede_id'];
} catch(Exception $e){}

$rol_badge  = ['admin'=>'b-danger','veterinario'=>'b-success','asistente'=>'b-info','recepcionista'=>'b-warning'];
$rol_labels = ['admin'=>'Administrador','veterinario'=>'Veterinario','asistente'=>'Asistente','recepcionista'=>'Recepcionista'];
?>
<?php if($msg==='success'): ?><div class="alert alert-success mb-3">&#10003; Cambios guardados correctamente.</div>
<?php elseif($msg==='pass_ok'): ?><div class="alert alert-success mb-3">&#10003; Contrasena actualizada.</div>
<?php elseif($msg==='error_pass'): ?><div class="alert alert-danger mb-3">Ingresa una contrasena para el nuevo usuario.</div>
<?php elseif($msg==='error_dup'): ?><div class="alert alert-danger mb-3">Ya existe un usuario con ese email.</div>
<?php elseif(substr($msg,0,6)==='error:'): ?><div class="alert alert-danger mb-3"><?= clean(substr($msg,6)) ?></div>
<?php endif; ?>

<?php if (in_array($action, ['nueva','editar'])): ?>
<div class="card" style="max-width:700px">
  <div class="sec-header mb-3">
    <div class="sec-title"><?= $action==='editar'?'Editar':'Nuevo'?> Personal</div>
    <a href="?p=personal" class="btn btn-ghost btn-sm">Volver</a>
  </div>
  <form method="POST" enctype="multipart/form-data">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= $editing['id']??'' ?>">
    <div class="form-row">
      <div class="form-group"><label class="form-label required">Nombre completo</label><input class="form-input" name="nombre" value="<?= clean($editing['nombre']??'') ?>" required></div>
      <div class="form-group"><label class="form-label required">Email</label><input class="form-input" type="email" name="email" value="<?= clean($editing['email']??'') ?>" required></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label required">Rol</label>
        <select class="form-input" name="rol" required>
          <?php foreach($rol_labels as $k=>$v): ?><option value="<?= $k ?>" <?= ($editing['rol']??'recepcionista')===$k?'selected':'' ?>><?= $v ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label class="form-label">Especialidad</label><input class="form-input" name="especialidad" value="<?= clean($editing['especialidad']??'') ?>"></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Telefono</label><input class="form-input" name="telefono" value="<?= clean($editing['telefono']??'') ?>"></div>
      <div class="form-group"><label class="form-label">Turno</label><input class="form-input" name="turno" value="<?= clean($editing['turno']??'') ?>" placeholder="Ej: L-V 8:00-17:00"></div>
    </div>

    <!-- COLEGIATURA Y FIRMA (para recetas médicas) -->
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">N° de colegiatura (CMVP)</label>
        <input class="form-input" name="colegiatura" value="<?= clean($editing['colegiatura']??'') ?>" placeholder="Ej: 12345">
        <div style="font-size:11px;color:var(--text3);margin-top:4px">Aparecerá en las recetas médicas firmadas por este profesional.</div>
      </div>
      <div class="form-group">
        <label class="form-label">Firma digital</label>
        <?php $firma_actual = $editing['firma'] ?? ''; ?>
        <?php if($firma_actual): ?>
        <div style="margin-bottom:8px;padding:8px;background:#fff;border:1px solid var(--border);border-radius:8px;text-align:center">
          <img src="<?= UPLOADS_URL.'/'.clean($firma_actual) ?>" alt="Firma actual" style="max-height:60px;max-width:200px;object-fit:contain">
          <div style="font-size:11px;color:var(--text3);margin-top:4px">Firma actual</div>
        </div>
        <?php endif; ?>
        <input class="form-input" type="file" name="firma" accept="image/png,image/jpeg,image/webp" style="cursor:pointer">
        <div style="font-size:11px;color:var(--text3);margin-top:4px">PNG con fondo transparente recomendado. Se mostrará sobre la línea de firma en la receta.</div>
      </div>
    </div>

    <!-- SEDES ASIGNADAS -->
    <div class="form-group">
      <label class="form-label required">Sedes a las que tiene acceso</label>
      <div style="display:flex;flex-wrap:wrap;gap:10px;padding:12px;background:var(--bg3);border-radius:10px;border:1.5px solid var(--border)">
        <?php foreach($sedes as $s):
          $checked = in_array($s['id'], $editing_sedes) || ($action==='nueva' && $s['id']==(getSede()));
        ?>
        <label style="display:flex;align-items:center;gap:8px;padding:8px 14px;background:var(--bg2);border-radius:8px;border:1.5px solid <?= $checked?($s['color']??'var(--primary)'):'var(--border)' ?>;cursor:pointer;font-size:13px;font-weight:600;transition:all .15s" id="lbl-sede-<?= $s['id'] ?>">
          <input type="checkbox" name="sedes_ids[]" value="<?= $s['id'] ?>" <?= $checked?'checked':'' ?>
            onchange="toggleSedeLbl(this,<?= $s['id'] ?>,'<?= addslashes($s['color']??'var(--primary)') ?>')"
            style="width:16px;height:16px;accent-color:<?= $s['color']??'#1ea8a1' ?>">
          <span style="width:8px;height:8px;border-radius:50%;background:<?= $s['color']??'#1ea8a1' ?>;display:inline-block"></span>
          <?= clean($s['nombre']) ?>
        </label>
        <?php endforeach; ?>
      </div>
      <div style="font-size:11px;color:var(--text3);margin-top:6px">Selecciona una o mas sedes. La primera sede seleccionada sera la sede principal.</div>
    </div>

    <div class="form-group">
      <label class="form-label"><?= $editing?'Nueva contrasena <span style="font-weight:400;color:var(--text3)">(vaciar para no cambiar)</span>':'Contrasena *' ?></label>
      <input class="form-input" type="password" name="password" placeholder="..." <?= !$editing?'required':'' ?> autocomplete="new-password">
    </div>
    <div class="form-group">
      <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:14px">
        <input type="checkbox" name="activo" <?= ($editing['activo']??1)?'checked':'' ?> style="width:16px;height:16px">
        <span>Usuario activo</span>
      </label>
    </div>
    <div class="flex gap-2">
      <button type="submit" class="btn btn-primary btn-lg">Guardar cambios</button>
      <a href="?p=personal" class="btn btn-ghost">Cancelar</a>
    </div>
  </form>
</div>
<script>
function toggleSedeLbl(cb, id, color) {
  var lbl = document.getElementById('lbl-sede-' + id);
  lbl.style.borderColor = cb.checked ? color : 'var(--border)';
}
</script>

<?php else: ?>
<div class="sec-header mb-3">
  <div><div class="page-title">Personal</div><div class="page-desc"><?= count($personal) ?> usuarios registrados</div></div>
  <a href="?p=personal&action=nueva" class="btn btn-primary">+ Nuevo usuario</a>
</div>

<!-- Modal cambio de contrasena -->
<div id="modal-pass" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:2000;align-items:center;justify-content:center" onclick="if(event.target===this)this.style.display='none'">
  <div style="background:var(--bg2);border-radius:16px;padding:28px;width:420px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,.2)">
    <div style="font-size:16px;font-weight:700;margin-bottom:6px">Cambiar contrasena</div>
    <div id="modal-pass-nombre" style="font-size:13px;color:var(--text3);margin-bottom:16px"></div>
    <form method="POST">
      <input type="hidden" name="action" value="reset_pass">
      <input type="hidden" name="id" id="modal-pass-id" value="">
      <div class="form-group"><label class="form-label required">Nueva contrasena</label><input class="form-input" type="password" name="nueva_pass" id="nueva-pass" placeholder="Minimo 6 caracteres" required autocomplete="new-password"></div>
      <div class="form-group"><label class="form-label required">Confirmar contrasena</label><input class="form-input" type="password" name="nueva_pass2" placeholder="Repetir" required autocomplete="new-password"></div>
      <div class="flex gap-2 mt-2">
        <button type="submit" class="btn btn-primary" style="flex:1">Actualizar contrasena</button>
        <button type="button" onclick="document.getElementById('modal-pass').style.display='none'" class="btn btn-ghost">Cancelar</button>
      </div>
    </form>
  </div>
</div>

<div class="card" style="padding:0">
  <table class="vtable">
    <thead><tr><th>Usuario</th><th>Rol</th><th>Sedes con acceso</th><th>Turno</th><th>Estado</th><th>Acciones</th></tr></thead>
    <tbody>
    <?php foreach($personal as $p):
      $badge = $rol_badge[$p['rol']] ?? 'b-info';
      $label = $rol_labels[$p['rol']] ?? ucfirst($p['rol']);
      $psedes = $usuario_sedes_map[$p['id']] ?? [$p['sede_id']];
      // Obtener nombres de sedes
      $sede_names = [];
      foreach($sedes as $s) { if(in_array($s['id'], $psedes)) $sede_names[] = ['nombre'=>$s['nombre'],'color'=>$s['color']??'#1ea8a1']; }
    ?>
    <tr>
      <td>
        <div style="display:flex;align-items:center;gap:10px">
          <div style="width:38px;height:38px;border-radius:50%;background:var(--primary-l);display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:700;color:var(--primary);flex-shrink:0"><?= strtoupper(substr($p['nombre'],0,1)) ?></div>
          <div>
            <div style="font-weight:600;color:var(--text)"><?= clean($p['nombre']) ?></div>
            <div style="font-size:11px;color:var(--text3)"><?= clean($p['email']) ?></div>
            <?php if($p['especialidad']): ?><div style="font-size:11px;color:var(--text3)"><?= clean($p['especialidad']) ?></div><?php endif; ?>
          </div>
        </div>
      </td>
      <td><span class="badge <?= $badge ?>"><?= $label ?></span></td>
      <td>
        <div style="display:flex;flex-wrap:wrap;gap:4px">
          <?php if(empty($sede_names)): ?>
          <span style="font-size:11px;color:var(--text3)">Sin sede</span>
          <?php else: foreach($sede_names as $sn): ?>
          <span style="padding:2px 10px;border-radius:999px;font-size:11px;font-weight:600;background:<?= $sn['color'] ?>22;color:<?= $sn['color'] ?>;border:1px solid <?= $sn['color'] ?>44"><?= clean($sn['nombre']) ?></span>
          <?php endforeach; endif; ?>
        </div>
      </td>
      <td class="text-muted"><?= clean($p['turno']??'') ?></td>
      <td><?= $p['activo'] ? '<span class="badge b-success">Activo</span>' : '<span class="badge b-danger">Inactivo</span>' ?></td>
      <td>
        <div class="flex gap-1">
          <a href="?p=personal&action=editar&id=<?= $p['id'] ?>" class="btn btn-xs btn-ghost">Editar</a>
          <button onclick="abrirCambioPass(<?= $p['id'] ?>, '<?= addslashes(clean($p['nombre'])) ?>')" class="btn btn-xs btn-ghost" style="color:var(--accent)">Contrasena</button>
          <?php if($p['id'] != $user['id']): ?>
          <form method="POST" style="display:inline" onsubmit="return confirm('Cambiar estado de <?= addslashes(clean($p['nombre'])) ?>?')">
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?= $p['id'] ?>">
            <button type="submit" class="btn btn-xs btn-ghost" style="color:<?= $p['activo']?'var(--danger)':'var(--success)' ?>"><?= $p['activo']?'Desactivar':'Activar' ?></button>
          </form>
          <?php endif; ?>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if(empty($personal)): ?>
    <tr><td colspan="6" style="text-align:center;padding:48px;color:var(--text3)">Sin usuarios. <a href="?p=personal&action=nueva" class="btn btn-primary btn-sm">Agregar</a></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<script>
function abrirCambioPass(id, nombre) {
  document.getElementById('modal-pass-id').value = id;
  document.getElementById('modal-pass-nombre').textContent = 'Usuario: ' + nombre;
  document.getElementById('nueva-pass').value = '';
  document.getElementById('modal-pass').style.display = 'flex';
  setTimeout(function(){ document.getElementById('nueva-pass').focus(); }, 100);
}
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
