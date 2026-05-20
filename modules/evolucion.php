<?php
$page = 'evolucion'; $pageTitle = 'Evolución Clínica';
require_once __DIR__ . '/../includes/header.php';
$db = getDB();

$db->exec("CREATE TABLE IF NOT EXISTS evolucion (
  id INT AUTO_INCREMENT PRIMARY KEY,
  mascota_id INT NOT NULL,
  veterinario_id INT NOT NULL,
  consulta_id INT NULL,
  hospitalizacion_id INT NULL,
  fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
  tipo ENUM('nota_evolucion','control','alta','cambio_tratamiento','urgencia') DEFAULT 'nota_evolucion',
  temperatura DECIMAL(4,1),
  frecuencia_cardiaca INT,
  frecuencia_respiratoria INT,
  peso DECIMAL(5,2),
  condicion_general ENUM('excelente','buena','regular','mala','critica') DEFAULT 'buena',
  descripcion TEXT NOT NULL,
  tratamiento_cambio TEXT,
  indica_alta TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (mascota_id) REFERENCES mascotas(id),
  FOREIGN KEY (veterinario_id) REFERENCES usuarios(id)
)");

$action = $_GET['action'] ?? 'list';
$mascota_id = (int)($_GET['mascota_id'] ?? 0);
$msg = '';

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='save') {
  $fields=['mascota_id','veterinario_id','fecha','tipo','temperatura','frecuencia_cardiaca',
           'frecuencia_respiratoria','peso','condicion_general','descripcion','tratamiento_cambio'];
  $data=[]; foreach($fields as $f) $data[$f]=trim($_POST[$f]??'')?:null;
  $data['indica_alta']=isset($_POST['indica_alta'])?1:0;
  $cols=implode(',',$fields); $pls=implode(',',array_map(fn($f)=>":$f",$fields));
  $db->prepare("INSERT INTO evolucion ($cols,indica_alta) VALUES ($pls,:indica_alta)")->execute($data);
  $msg='success'; $mascota_id=(int)$data['mascota_id']; $action='list';
}

$mascotas_sel=$db->query("SELECT m.id,CONCAT(m.nombre,' (',c.nombre,')') as label FROM mascotas m JOIN clientes c ON c.id=m.cliente_id WHERE m.estado='activo' ORDER BY m.nombre")->fetchAll();
$vets_sel=$db->query("SELECT id,nombre FROM usuarios WHERE rol IN ('veterinario','admin') AND activo=1")->fetchAll();

$mascota=null;
if ($mascota_id){$st=$db->prepare("SELECT m.*,c.nombre as dueno FROM mascotas m JOIN clientes c ON c.id=m.cliente_id WHERE m.id=?");$st->execute([$mascota_id]);$mascota=$st->fetch();}

$where="1=1"; $params=[];
if($mascota_id){$where.=" AND e.mascota_id=?";$params[]=$mascota_id;}
$evoluciones=$db->prepare("SELECT e.*,m.nombre as mascota,m.especie,u.nombre as vet,c.nombre as dueno FROM evolucion e JOIN mascotas m ON m.id=e.mascota_id JOIN usuarios u ON u.id=e.veterinario_id JOIN clientes c ON c.id=m.cliente_id WHERE $where ORDER BY e.fecha DESC LIMIT 50");
$evoluciones->execute($params); $evoluciones=$evoluciones->fetchAll();

$tipo_icon=['nota_evolucion'=>'📝','control'=>'🔄','alta'=>'🏠','cambio_tratamiento'=>'💊','urgencia'=>'🚨'];
$tipo_color=['nota_evolucion'=>'b-info','control'=>'b-primary','alta'=>'b-success','cambio_tratamiento'=>'b-warning','urgencia'=>'b-danger'];
$cond_color=['excelente'=>'var(--success)','buena'=>'var(--primary)','regular'=>'var(--warning)','mala'=>'var(--orange)','critica'=>'var(--danger)'];
$ei=['perro'=>'🐕','gato'=>'🐈','conejo'=>'🐰','ave'=>'🐦','reptil'=>'🦎','roedor'=>'🐭','otro'=>'🐾'];
?>
<div class="page">
<?php if($msg==='success'): ?><div class="alert alert-success"><span class="alert-icon">✅</span>Nota de evolución guardada.</div><?php endif; ?>

<!-- Mascota banner -->
<?php if($mascota): ?>
<div class="card card-sm mb-3" style="background:var(--primary-l);border-color:rgba(30,168,161,.3)">
  <div class="flex items-center gap-3">
    <span style="font-size:36px"><?= $ei[$mascota['especie']]??'🐾' ?></span>
    <div class="flex-1"><div style="font-size:17px;font-weight:700"><?= clean($mascota['nombre']) ?></div><div class="text-sm" style="color:var(--primary-d)"><?= clean($mascota['raza']??'') ?> · Dueño: <?= clean($mascota['dueno']) ?></div></div>
    <div class="flex gap-1">
      <a href="?p=historial&mascota_id=<?= $mascota_id ?>" class="btn btn-sm btn-ghost">🏥 Historia</a>
      <a href="?p=evolucion&action=nueva&mascota_id=<?= $mascota_id ?>" class="btn btn-sm btn-primary">＋ Nueva nota</a>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if($action==='nueva'): ?>
<div class="card" style="max-width:720px">
  <div class="sec-header">
    <div><div class="sec-title">📝 Nueva Nota de Evolución</div></div>
    <a href="?p=evolucion<?= $mascota_id?"&mascota_id=$mascota_id":'' ?>" class="btn btn-ghost btn-sm">← Volver</a>
  </div>
  <form method="POST">
    <input type="hidden" name="action" value="save">
    <div class="form-row">
      <div class="form-group"><label class="form-label required">Paciente</label>
        <select class="form-input" name="mascota_id" required>
          <option value="">— Seleccionar —</option>
          <?php foreach($mascotas_sel as $m): ?><option value="<?= $m['id'] ?>" <?= $mascota_id==$m['id']?'selected':'' ?>><?= clean($m['label']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label class="form-label required">Veterinario</label>
        <select class="form-input" name="veterinario_id" required>
          <?php foreach($vets_sel as $v): ?><option value="<?= $v['id'] ?>" <?= $v['id']==$user['id']?'selected':'' ?>><?= clean($v['nombre']) ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Tipo de nota</label>
        <select class="form-input" name="tipo">
          <?php foreach(['nota_evolucion'=>'Nota de evolución','control'=>'Control','alta'=>'Alta médica','cambio_tratamiento'=>'Cambio de tratamiento','urgencia'=>'Urgencia'] as $k=>$v): ?>
          <option value="<?= $k ?>"><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label class="form-label">Fecha</label>
        <input class="form-input" type="datetime-local" name="fecha" value="<?= date('Y-m-d\TH:i') ?>">
      </div>
    </div>
    <!-- Signos vitales -->
    <div style="background:var(--bg3);border-radius:var(--r-sm);padding:14px;margin-bottom:14px">
      <div class="font-semi text-sm mb-2">📊 Signos vitales (opcional)</div>
      <div class="grid g4">
        <div class="form-group mb-0"><label class="form-label">Temp °C</label><input class="form-input" type="number" step="0.1" name="temperatura" placeholder="38.5"></div>
        <div class="form-group mb-0"><label class="form-label">F. Card. rpm</label><input class="form-input" type="number" name="frecuencia_cardiaca" placeholder="80"></div>
        <div class="form-group mb-0"><label class="form-label">F. Resp.</label><input class="form-input" type="number" name="frecuencia_respiratoria" placeholder="20"></div>
        <div class="form-group mb-0"><label class="form-label">Peso (kg)</label><input class="form-input" type="number" step="0.1" name="peso" placeholder="10.5"></div>
      </div>
    </div>
    <div class="form-group"><label class="form-label">Condición general</label>
      <div class="flex gap-2 flex-wrap" style="margin-top:6px">
        <?php foreach(['excelente'=>'💚 Excelente','buena'=>'💙 Buena','regular'=>'💛 Regular','mala'=>'🟠 Mala','critica'=>'🔴 Crítica'] as $k=>$v): ?>
        <label style="cursor:pointer;display:flex;align-items:center;gap:6px;padding:7px 12px;border:1.5px solid var(--border);border-radius:var(--r-sm);font-size:12px;font-weight:600">
          <input type="radio" name="condicion_general" value="<?= $k ?>" <?= $k==='buena'?'checked':'' ?>> <?= $v ?>
        </label>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="form-group"><label class="form-label required">Descripción / Evolución</label>
      <textarea class="form-input" name="descripcion" required style="min-height:100px" placeholder="Describe el estado actual del paciente, respuesta al tratamiento..."></textarea>
    </div>
    <div class="form-group"><label class="form-label">Cambio de tratamiento</label>
      <textarea class="form-input" name="tratamiento_cambio" style="min-height:60px" placeholder="Modificaciones al tratamiento actual..."></textarea>
    </div>
    <label style="display:flex;align-items:center;gap:10px;padding:12px;background:var(--success-l);border:1px solid var(--success);border-radius:var(--r-sm);cursor:pointer;margin-bottom:16px">
      <input type="checkbox" name="indica_alta" style="accent-color:var(--success)"> <strong style="color:var(--success-d)">🏠 Esta nota indica ALTA del paciente</strong>
    </label>
    <div class="flex gap-2"><button type="submit" class="btn btn-primary">💾 Guardar evolución</button><a href="?p=evolucion" class="btn btn-ghost">Cancelar</a></div>
  </form>
</div>

<?php else: ?>
<div class="sec-header">
  <div><div class="page-title">📈 Evolución Clínica</div><div class="page-desc"><?= count($evoluciones) ?> notas registradas</div></div>
  <div class="flex gap-2">
    <?php if($mascota_id): ?><a href="?p=evolucion" class="btn btn-ghost btn-sm">Ver todas</a><?php endif; ?>
    <a href="?p=evolucion&action=nueva<?= $mascota_id?"&mascota_id=$mascota_id":'' ?>" class="btn btn-primary">＋ Nueva nota</a>
  </div>
</div>

<div class="timeline">
  <?php foreach($evoluciones as $e): ?>
  <div class="timeline-item">
    <div class="timeline-dot dot-<?= $e['tipo']==='urgencia'?'danger':($e['tipo']==='alta'?'success':($e['tipo']==='cambio_tratamiento'?'warning':'')) ?>"></div>
    <div class="timeline-content">
      <div class="flex items-center gap-2 mb-2">
        <span><?= $tipo_icon[$e['tipo']]??'📝' ?></span>
        <span class="badge <?= $tipo_color[$e['tipo']]??'b-info' ?>"><?= ucfirst(str_replace('_',' ',$e['tipo'])) ?></span>
        <?php if(!$mascota_id): ?>
        <span style="font-size:16px"><?= $ei[$e['especie']]??'🐾' ?></span>
        <span class="font-semi"><?= clean($e['mascota']) ?></span>
        <span class="text-muted">·</span>
        <span class="text-muted text-xs"><?= clean($e['dueno']) ?></span>
        <?php endif; ?>
        <span class="text-xs text-muted" style="margin-left:auto"><?= date('d/m/Y H:i',strtotime($e['fecha'])) ?> · <?= clean($e['vet']) ?></span>
      </div>
      <!-- Vitales -->
      <?php if($e['temperatura']||$e['frecuencia_cardiaca']||$e['peso']): ?>
      <div class="flex gap-2 flex-wrap mb-2">
        <?php if($e['temperatura']): ?><span class="badge b-warning">🌡️ <?= $e['temperatura'] ?>°C</span><?php endif; ?>
        <?php if($e['frecuencia_cardiaca']): ?><span class="badge b-danger">❤️ <?= $e['frecuencia_cardiaca'] ?> rpm</span><?php endif; ?>
        <?php if($e['frecuencia_respiratoria']): ?><span class="badge b-info">🫁 <?= $e['frecuencia_respiratoria'] ?> rpm</span><?php endif; ?>
        <?php if($e['peso']): ?><span class="badge b-gray">⚖️ <?= $e['peso'] ?> kg</span><?php endif; ?>
        <?php if($e['condicion_general']): ?><span class="badge b-gray" style="color:<?= $cond_color[$e['condicion_general']] ?>"><?= ucfirst($e['condicion_general']) ?></span><?php endif; ?>
      </div>
      <?php endif; ?>
      <div class="text-sm" style="line-height:1.7"><?= nl2br(clean($e['descripcion'])) ?></div>
      <?php if($e['tratamiento_cambio']): ?><div class="alert alert-warn mt-2 mb-0" style="padding:8px 12px"><span class="alert-icon">💊</span><?= nl2br(clean($e['tratamiento_cambio'])) ?></div><?php endif; ?>
      <?php if($e['indica_alta']): ?><div class="badge b-success mt-2">🏠 Alta médica registrada</div><?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if(empty($evoluciones)): ?>
  <div class="card text-center" style="padding:60px">
    <div style="font-size:48px;margin-bottom:14px;opacity:.3">📈</div>
    <div style="font-size:16px;font-weight:600;margin-bottom:6px">Sin notas de evolución</div>
    <div class="text-muted mb-3">Registra la evolución clínica de tus pacientes internados</div>
    <a href="?p=evolucion&action=nueva" class="btn btn-primary">Crear primera nota</a>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
