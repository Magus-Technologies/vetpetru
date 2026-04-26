<?php
$page = 'personal'; $pageTitle = 'Personal';
require_once __DIR__ . '/../includes/header.php';
if(!hasRole(['admin'])) { echo '<div class="alert alert-warn">⚠️ Solo los administradores pueden gestionar el personal.</div>'; require_once __DIR__.'/../includes/footer.php'; exit; }
$db = getDB();
$action = $_GET['action'] ?? 'list';
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pa = $_POST['action'] ?? '';
    if ($pa === 'save') {
        $id = (int)($_POST['id']??0);
        $fields = ['nombre','email','rol','especialidad','telefono','turno'];
        $data=[]; foreach($fields as $f) $data[$f]=trim($_POST[$f]??'');
        $data['activo'] = isset($_POST['activo']) ? 1 : 0;
        $data['sede_id'] = $user['sede_id']??1;
        if ($id) {
            $sets = implode(',', array_map(fn($f)=>"$f=:$f", $fields));
            $sets .= ",activo=:activo";
            $st = $db->prepare("UPDATE usuarios SET $sets WHERE id=:id"); $data['id']=$id;
            if (!empty($_POST['password'])) { $data['password']=password_hash($_POST['password'],PASSWORD_BCRYPT); $sets.=",password=:password"; $st=$db->prepare("UPDATE usuarios SET $sets WHERE id=:id"); }
        } else {
            if (empty($_POST['password'])) { $msg='error_pass'; } else {
                $data['password'] = password_hash($_POST['password'],PASSWORD_BCRYPT);
                $cols = implode(',', array_merge($fields,['activo','sede_id','password']));
                $pls  = implode(',', array_map(fn($f)=>":$f", array_merge($fields,['activo','sede_id','password'])));
                $st = $db->prepare("INSERT INTO usuarios ($cols) VALUES ($pls)");
            }
        }
        if ($msg !== 'error_pass') { $st->execute($data); $msg='success'; $action='list'; }
    }
    if ($pa === 'toggle') {
        $db->prepare("UPDATE usuarios SET activo=NOT activo WHERE id=?")->execute([(int)$_POST['id']]);
        $msg='success'; $action='list';
    }
}

$editing=null;
if (in_array($action,['editar']) && isset($_GET['id'])) {
    $st=$db->prepare("SELECT * FROM usuarios WHERE id=?"); $st->execute([(int)$_GET['id']]); $editing=$st->fetch();
}

$personal = $db->query("SELECT u.*, s.nombre as sede_nombre FROM usuarios u LEFT JOIN sedes s ON s.id=u.sede_id ORDER BY u.rol, u.nombre")->fetchAll();
$rol_badge = ['admin'=>'b-red','veterinario'=>'b-teal','asistente'=>'b-blue','recepcionista'=>'b-purple'];
$rol_labels= ['admin'=>'Administrador','veterinario'=>'Veterinario','asistente'=>'Asistente','recepcionista'=>'Recepcionista'];
?>
<?php if($msg==='success'): ?><div class="alert alert-success mb-2">✅ Personal guardado correctamente.</div><?php endif; ?>
<?php if($msg==='error_pass'): ?><div class="alert alert-warn mb-2">⚠️ Debes ingresar una contraseña para el nuevo usuario.</div><?php endif; ?>

<?php if(in_array($action,['nueva','editar'])): ?>
<div class="card" style="max-width:680px">
  <div class="sec-header"><div class="sec-title"><?= $action==='editar'?'Editar':'Nuevo'?> Personal</div><a href="?p=personal" class="btn btn-sm">← Volver</a></div>
  <form method="POST">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= $editing['id']??'' ?>">
    <div class="form-row">
      <div class="form-group"><label class="form-label">Nombre completo *</label><input class="form-input" name="nombre" value="<?= clean($editing['nombre']??'') ?>" required></div>
      <div class="form-group"><label class="form-label">Email *</label><input class="form-input" type="email" name="email" value="<?= clean($editing['email']??'') ?>" required></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Rol *</label>
        <select class="form-input" name="rol" required>
          <?php foreach($rol_labels as $k=>$v): ?><option value="<?= $k ?>" <?= ($editing['rol']??'recepcionista')===$k?'selected':'' ?>><?= $v ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label class="form-label">Especialidad</label><input class="form-input" name="especialidad" value="<?= clean($editing['especialidad']??'') ?>"></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Teléfono</label><input class="form-input" name="telefono" value="<?= clean($editing['telefono']??'') ?>"></div>
      <div class="form-group"><label class="form-label">Turno</label><input class="form-input" name="turno" value="<?= clean($editing['turno']??'') ?>" placeholder="Ej: L-V 8:00-17:00"></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Contraseña <?= $editing?'(dejar en blanco para no cambiar)':'*' ?></label><input class="form-input" type="password" name="password" placeholder="••••••••"></div>
      <div class="form-group" style="display:flex;align-items:flex-end;padding-bottom:14px"><label style="display:flex;align-items:center;gap:8px;font-size:14px;cursor:pointer"><input type="checkbox" name="activo" <?= ($editing['activo']??1)?'checked':'' ?>> Usuario activo</label></div>
    </div>
    <div class="flex gap-1"><button type="submit" class="btn btn-primary">💾 Guardar</button><a href="?p=personal" class="btn">Cancelar</a></div>
  </form>
</div>

<?php else: ?>
<div class="sec-header">
  <div><div class="sec-title">Equipo de trabajo</div><div class="sec-sub"><?= count($personal) ?> colaboradores</div></div>
  <a href="?p=personal&action=nueva" class="btn btn-primary">+ Nuevo Personal</a>
</div>
<div class="grid g2">
  <?php foreach($personal as $p): ?>
  <div class="card card-sm" style="opacity:<?= $p['activo']?'1':'0.6' ?>">
    <div class="flex items-center gap-2 mb-2">
      <div class="avatar" style="width:44px;height:44px;font-size:15px;background:<?= $p['rol']==='admin'?'var(--red)':($p['rol']==='veterinario'?'var(--teal)':'var(--blue)') ?>">
        <?= strtoupper(substr($p['nombre'],0,1).substr(strstr($p['nombre'],' ') ?: ' ',1,1)) ?>
      </div>
      <div class="flex-1">
        <div class="font-bold"><?= clean($p['nombre']) ?></div>
        <div class="text-xs text-muted"><?= clean($p['email']) ?></div>
      </div>
      <span class="badge <?= $rol_badge[$p['rol']]??'b-gray' ?>"><?= $rol_labels[$p['rol']]??$p['rol'] ?></span>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;background:var(--bg3);border-radius:8px;padding:10px;margin-bottom:10px">
      <div><div class="text-xs text-muted">Especialidad</div><div class="text-sm font-med"><?= clean($p['especialidad']??'—') ?></div></div>
      <div><div class="text-xs text-muted">Turno</div><div class="text-sm font-med"><?= clean($p['turno']??'—') ?></div></div>
      <div><div class="text-xs text-muted">Teléfono</div><div class="text-sm"><?= clean($p['telefono']??'—') ?></div></div>
      <div><div class="text-xs text-muted">Sede</div><div class="text-sm"><?= clean($p['sede_nombre']??'—') ?></div></div>
    </div>
    <?php if($p['ultimo_login']): ?><div class="text-xs text-muted mb-2">Último ingreso: <?= date('d/m/Y H:i',strtotime($p['ultimo_login'])) ?></div><?php endif; ?>
    <div class="flex gap-1">
      <a href="?p=personal&action=editar&id=<?= $p['id'] ?>" class="btn btn-xs flex-1" style="justify-content:center">✏️ Editar</a>
      <?php if($p['id'] != $user['id']): ?>
      <form method="POST" style="flex:1"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $p['id'] ?>"><button type="submit" class="btn btn-xs w-full" style="color:<?= $p['activo']?'var(--red)':'var(--green)' ?>"><?= $p['activo']?'🔒 Desactivar':'✅ Activar' ?></button></form>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
