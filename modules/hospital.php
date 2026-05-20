<?php
$page = 'hospital'; $pageTitle = 'Hospital / Emergencias';
require_once __DIR__ . '/../includes/header.php';
$db = getDB();

$db->exec("CREATE TABLE IF NOT EXISTS hospitalizacion (
  id INT AUTO_INCREMENT PRIMARY KEY,
  mascota_id INT NOT NULL,
  veterinario_id INT NOT NULL,
  consulta_id INT NULL,
  fecha_ingreso DATETIME DEFAULT CURRENT_TIMESTAMP,
  fecha_alta DATETIME NULL,
  motivo VARCHAR(300) NOT NULL,
  diagnostico TEXT,
  estado ENUM('estable','observacion','emergencia','critico','alta') DEFAULT 'observacion',
  prioridad TINYINT DEFAULT 2,
  tratamiento_actual TEXT,
  medicacion TEXT,
  proxima_evaluacion DATETIME NULL,
  temperatura DECIMAL(4,1),
  frecuencia_cardiaca INT,
  frecuencia_respiratoria INT,
  presion_arterial VARCHAR(20),
  peso_actual DECIMAL(5,2),
  observaciones TEXT,
  activo TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (mascota_id) REFERENCES mascotas(id),
  FOREIGN KEY (veterinario_id) REFERENCES usuarios(id)
)");

$action = $_GET['action'] ?? 'list';
$msg = '';

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $pa=$_POST['action']??'';
  if ($pa==='save') {
    $id=(int)($_POST['id']??0);
    $fields=['mascota_id','veterinario_id','motivo','diagnostico','estado','prioridad',
             'tratamiento_actual','medicacion','proxima_evaluacion','temperatura',
             'frecuencia_cardiaca','frecuencia_respiratoria','presion_arterial','peso_actual','observaciones'];
    $data=[]; foreach($fields as $f) $data[$f]=trim($_POST[$f]??'')?:null;
    if ($id) {
      $sets=implode(',',array_map(fn($f)=>"$f=:$f",$fields));
      $st=$db->prepare("UPDATE hospitalizacion SET $sets WHERE id=:id"); $data['id']=$id;
    } else {
      $cols=implode(',',$fields); $pls=implode(',',array_map(fn($f)=>":$f",$fields));
      $st=$db->prepare("INSERT INTO hospitalizacion ($cols) VALUES ($pls)");
    }
    $st->execute($data); $msg='success'; $action='list';
  }
  if ($pa==='alta') {
    $db->prepare("UPDATE hospitalizacion SET estado='alta',activo=0,fecha_alta=NOW() WHERE id=?")->execute([(int)$_POST['id']]);
    $msg='alta';
  }
  if ($pa==='update_estado') {
    $db->prepare("UPDATE hospitalizacion SET estado=? WHERE id=?")->execute([$_POST['estado'],(int)$_POST['id']]);
    header('Content-Type: application/json'); echo json_encode(['ok'=>true]); exit;
  }
}

$editing=null;
if (in_array($action,['editar']) && isset($_GET['id'])) {
  $st=$db->prepare("SELECT * FROM hospitalizacion WHERE id=?"); $st->execute([(int)$_GET['id']]); $editing=$st->fetch();
}

$mascotas_sel=$db->query("SELECT m.id,CONCAT(m.nombre,' (',c.nombre,')') as label FROM mascotas m JOIN clientes c ON c.id=m.cliente_id WHERE m.estado='activo' ORDER BY m.nombre")->fetchAll();
$vets_sel=$db->query("SELECT id,nombre FROM usuarios WHERE rol IN ('veterinario','admin') AND activo=1")->fetchAll();

// Pacientes internados activos, ordenados por prioridad/estado
$pacientes=$db->query("SELECT h.*,m.nombre as mascota,m.especie,m.foto,u.nombre as vet,c.nombre as dueno,c.telefono
  FROM hospitalizacion h JOIN mascotas m ON m.id=h.mascota_id
  JOIN usuarios u ON u.id=h.veterinario_id JOIN clientes c ON c.id=m.cliente_id
  WHERE h.activo=1
  ORDER BY FIELD(h.estado,'critico','emergencia','observacion','estable') ASC, h.prioridad DESC")->fetchAll();

$historial=$db->query("SELECT h.*,m.nombre as mascota,u.nombre as vet FROM hospitalizacion h
  JOIN mascotas m ON m.id=h.mascota_id JOIN usuarios u ON u.id=h.veterinario_id
  WHERE h.activo=0 ORDER BY h.fecha_alta DESC LIMIT 20")->fetchAll();

$ei=['perro'=>'🐕','gato'=>'🐈','conejo'=>'🐰','ave'=>'🐦','reptil'=>'🦎','roedor'=>'🐭','otro'=>'🐾'];
$estado_cfg=[
  'estable'    =>['color'=>'#d1fae5','text'=>'#065f46','border'=>'#6ee7b7','label'=>'Estable','icon'=>'💚'],
  'observacion'=>['color'=>'#fef3c7','text'=>'#78350f','border'=>'#fde68a','label'=>'Observación','icon'=>'🟡'],
  'emergencia' =>['color'=>'#fee2e2','text'=>'#7f1d1d','border'=>'#fca5a5','label'=>'Emergencia','icon'=>'🔴'],
  'critico'    =>['color'=>'#1f2937','text'=>'#f9fafb','border'=>'#374151','label'=>'Crítico','icon'=>'⚫'],
];
?>
<div class="page">
<?php if($msg==='success'): ?><div class="alert alert-success"><span class="alert-icon">✅</span>Paciente registrado/actualizado.</div><?php endif; ?>
<?php if($msg==='alta'): ?><div class="alert alert-info"><span class="alert-icon">🏠</span>Paciente dado de alta.</div><?php endif; ?>

<?php if(in_array($action,['nuevo','editar'])): ?>
<div class="card" style="max-width:720px">
  <div class="sec-header">
    <div>
      <div class="sec-title"><?= $action==='editar'?'Actualizar':'Ingresar'?> Paciente</div>
      <div class="sec-sub">Hospital / Área de internamiento</div>
    </div>
    <a href="?p=hospital" class="btn btn-ghost btn-sm">← Volver</a>
  </div>
  <form method="POST">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= $editing['id']??'' ?>">
    <div class="form-row">
      <div class="form-group" style="position:relative"><label class="form-label required">Paciente</label>
        <input type="text" id="inp-mas-hos" class="form-input" placeholder="🐾 Buscar mascota..." autocomplete="off">
        <input type="hidden" name="mascota_id" id="hid-mas-hos" value="" required>
        <div id="drop-mas-hos" style="display:none;position:absolute;top:100%;left:0;right:0;background:var(--bg2);border:1px solid var(--border);border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:300;max-height:220px;overflow-y:auto"></div>
      </div>
      <div class="form-group" style="position:relative"><label class="form-label required">Veterinario</label>
        <input type="text" id="inp-vet-hos" class="form-input" placeholder="👨‍⚕️ Buscar veterinario..." autocomplete="off">
        <input type="hidden" name="veterinario_id" id="hid-vet-hos" value="" required>
        <div id="drop-vet-hos" style="display:none;position:absolute;top:100%;left:0;right:0;background:var(--bg2);border:1px solid var(--border);border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:300;max-height:200px;overflow-y:auto"></div>
      </div>
    </div>
    <div class="form-group"><label class="form-label required">Motivo de ingreso</label>
      <input class="form-input" name="motivo" value="<?= clean($editing['motivo']??'') ?>" required placeholder="Motivo principal del internamiento">
    </div>

    <!-- Estado con colores -->
    <div class="form-group">
      <label class="form-label">Estado / Prioridad</label>
      <div class="grid g4 gap-2" style="margin-top:6px">
        <?php foreach($estado_cfg as $k=>$cfg): ?>
        <label style="cursor:pointer">
          <input type="radio" name="estado" value="<?= $k ?>" <?= ($editing['estado']??'observacion')===$k?'checked':'' ?> style="display:none" onchange="updateEstadoUI()">
          <div class="estado-opt" data-val="<?= $k ?>" style="padding:12px;border-radius:var(--r-sm);border:2px solid <?= $cfg['border'] ?>;background:<?= $cfg['color'] ?>;text-align:center;transition:all .15s">
            <div style="font-size:20px;margin-bottom:4px"><?= $cfg['icon'] ?></div>
            <div style="font-size:11px;font-weight:700;color:<?= $cfg['text'] ?>"><?= $cfg['label'] ?></div>
          </div>
        </label>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Signos vitales -->
    <div style="background:var(--bg3);border-radius:var(--r-sm);padding:16px;margin-bottom:16px">
      <div class="font-semi text-sm mb-2" style="color:var(--text2)">📊 Signos vitales</div>
      <div class="grid g4">
        <div class="form-group mb-0"><label class="form-label">Temperatura °C</label><input class="form-input" type="number" step="0.1" name="temperatura" value="<?= clean($editing['temperatura']??'') ?>"></div>
        <div class="form-group mb-0"><label class="form-label">F. Cardíaca rpm</label><input class="form-input" type="number" name="frecuencia_cardiaca" value="<?= clean($editing['frecuencia_cardiaca']??'') ?>"></div>
        <div class="form-group mb-0"><label class="form-label">F. Respiratoria</label><input class="form-input" type="number" name="frecuencia_respiratoria" value="<?= clean($editing['frecuencia_respiratoria']??'') ?>"></div>
        <div class="form-group mb-0"><label class="form-label">Peso actual (kg)</label><input class="form-input" type="number" step="0.1" name="peso_actual" value="<?= clean($editing['peso_actual']??'') ?>"></div>
      </div>
    </div>

    <div class="form-group"><label class="form-label">Diagnóstico</label><textarea class="form-input" name="diagnostico"><?= clean($editing['diagnostico']??'') ?></textarea></div>
    <div class="form-group"><label class="form-label">Tratamiento actual</label><textarea class="form-input" name="tratamiento_actual"><?= clean($editing['tratamiento_actual']??'') ?></textarea></div>
    <div class="form-group"><label class="form-label">Medicación</label><textarea class="form-input" name="medicacion" style="min-height:60px"><?= clean($editing['medicacion']??'') ?></textarea></div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Próxima evaluación</label><input class="form-input" type="datetime-local" name="proxima_evaluacion" value="<?= clean(str_replace(' ','T',substr($editing['proxima_evaluacion']??'',0,16))) ?>"></div>
      <div class="form-group"><label class="form-label">Prioridad (1-10)</label><input class="form-input" type="number" min="1" max="10" name="prioridad" value="<?= $editing['prioridad']??'5' ?>"></div>
    </div>
    <div class="form-group"><label class="form-label">Observaciones</label><textarea class="form-input" name="observaciones"><?= clean($editing['observaciones']??'') ?></textarea></div>
    <div class="flex gap-2"><button type="submit" class="btn btn-primary">💾 Guardar</button><a href="?p=hospital" class="btn btn-ghost">Cancelar</a></div>
  </form>
</div>

<?php else: ?>
<!-- ── TABLERO UCI ── -->
<div class="page-header">
  <div class="flex items-center justify-between">
    <div>
      <div class="page-title">🚑 Hospital / UCI</div>
      <div class="page-desc"><?= count($pacientes) ?> paciente<?= count($pacientes)!=1?'s':'' ?> internado<?= count($pacientes)!=1?'s':'' ?> — actualización en tiempo real</div>
    </div>
    <a href="?p=hospital&action=nuevo" class="btn btn-danger">＋ Ingresar Paciente</a>
  </div>
</div>

<?php if(empty($pacientes)): ?>
<div class="card text-center" style="padding:80px">
  <div style="font-size:60px;margin-bottom:16px;opacity:.3">🏥</div>
  <div style="font-size:16px;font-weight:600;margin-bottom:8px">Sin pacientes internados</div>
  <div class="text-muted mb-3">Todos los pacientes han sido dados de alta</div>
  <a href="?p=hospital&action=nuevo" class="btn btn-primary">Ingresar primer paciente</a>
</div>
<?php else: ?>

<!-- Kanban por estado -->
<div class="grid" style="grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px">
  <?php
  $grupos=['critico'=>[],'emergencia'=>[],'observacion'=>[],'estable'=>[]];
  foreach($pacientes as $p) $grupos[$p['estado']][] = $p;
  foreach($grupos as $estado=>$pacs):
    $cfg=$estado_cfg[$estado];
  ?>
  <div style="background:<?= $cfg['color'] ?>;border:2px solid <?= $cfg['border'] ?>;border-radius:var(--r-lg);padding:14px">
    <div class="flex items-center gap-2 mb-3">
      <span style="font-size:18px"><?= $cfg['icon'] ?></span>
      <div style="font-weight:700;color:<?= $cfg['text'] ?>;font-size:13px"><?= $cfg['label'] ?></div>
      <span style="margin-left:auto;background:<?= $cfg['border'] ?>;color:<?= $cfg['text'] ?>;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700"><?= count($pacs) ?></span>
    </div>
    <?php foreach($pacs as $p):
      $foto_url = !empty($p['foto']) ? BASE_URL.'/public/uploads/'.$p['foto'] : null;
      $hrs = round((time()-strtotime($p['fecha_ingreso']))/3600);
    ?>
    <div style="background:rgba(255,255,255,.7);border-radius:var(--r-sm);padding:12px;margin-bottom:8px;border:1px solid rgba(255,255,255,.5)">
      <div class="flex items-center gap-2 mb-2">
        <?php if($foto_url): ?>
        <img src="<?= $foto_url ?>" style="width:36px;height:36px;border-radius:50%;object-fit:cover;border:2px solid white">
        <?php else: ?>
        <span style="font-size:24px"><?= $ei[$p['especie']]??'🐾' ?></span>
        <?php endif; ?>
        <div class="flex-1">
          <div style="font-weight:700;font-size:13px;color:<?= $cfg['text'] ?>"><?= clean($p['mascota']) ?></div>
          <div style="font-size:10px;color:<?= $cfg['text'] ?>;opacity:.7"><?= clean($p['dueno']) ?></div>
        </div>
        <?php if($estado==='critico'||$estado==='emergencia'): ?>
        <div style="width:8px;height:8px;border-radius:50%;background:<?= $cfg['text'] ?>;animation:badge-blink .7s infinite"></div>
        <?php endif; ?>
      </div>
      <div style="font-size:11px;color:<?= $cfg['text'] ?>;opacity:.8;margin-bottom:8px"><?= clean(substr($p['motivo'],0,60)) ?></div>
      <div style="font-size:10px;color:<?= $cfg['text'] ?>;opacity:.6;margin-bottom:8px">
        ⏱ <?= $hrs ?>h internado · 👨‍⚕️ <?= clean(explode(' ',$p['vet'])[0]) ?>
        <?php if($p['temperatura']): ?> · 🌡️<?= $p['temperatura'] ?>°C<?php endif; ?>
      </div>
      <div class="flex gap-1">
        <a href="?p=hospital&action=editar&id=<?= $p['id'] ?>" class="btn btn-xs" style="flex:1;justify-content:center;background:rgba(255,255,255,.8)">Actualizar</a>
        <form method="POST" style="display:inline"><input type="hidden" name="action" value="alta"><input type="hidden" name="id" value="<?= $p['id'] ?>">
          <button type="submit" class="btn btn-xs" style="background:rgba(255,255,255,.8)" onclick="return confirm('¿Dar de alta a <?= clean($p['mascota']) ?>?')" title="Dar de alta">🏠</button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
    <?php if(empty($pacs)): ?>
    <div style="text-align:center;padding:20px;font-size:12px;color:<?= $cfg['text'] ?>;opacity:.5">Sin pacientes</div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<?php endif; ?>

<!-- Historial altas -->
<?php if(!empty($historial)): ?>
<div class="card" style="padding:0">
  <div style="padding:16px 20px;border-bottom:1px solid var(--border)" class="flex items-center justify-between">
    <div class="sec-title">Historial de altas recientes</div>
  </div>
  <div class="table-wrap">
    <table class="vtable">
      <thead><tr><th>Paciente</th><th>Ingreso</th><th>Alta</th><th>Veterinario</th><th>Motivo</th></tr></thead>
      <tbody>
        <?php foreach($historial as $h): ?>
        <tr>
          <td class="td-main"><?= clean($h['mascota']) ?></td>
          <td class="text-muted"><?= date('d/m/Y H:i',strtotime($h['fecha_ingreso'])) ?></td>
          <td><span class="badge b-success"><?= date('d/m/Y H:i',strtotime($h['fecha_alta']??'')) ?></span></td>
          <td class="text-muted"><?= clean($h['vet']) ?></td>
          <td class="text-muted"><?= clean(substr($h['motivo'],0,50)) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>
</div>
<script>
function updateEstadoUI(){
  document.querySelectorAll('.estado-opt').forEach(d=>{d.style.opacity='.5';d.style.transform='scale(.97)';});
  const sel=document.querySelector('input[name="estado"]:checked');
  if(sel){const d=document.querySelector('.estado-opt[data-val="'+sel.value+'"]');if(d){d.style.opacity='1';d.style.transform='scale(1.03)';}}
}
document.addEventListener('DOMContentLoaded',updateEstadoUI);
document.querySelectorAll('input[name="estado"]').forEach(r=>r.addEventListener('change',updateEstadoUI));
</script>
<?php
$_js_mas = array_map(fn($m)=>['id'=>$m['id'],'label'=>$m['label']], $mascotas_sel??[]);
$_js_vet = array_map(fn($v)=>['id'=>$v['id'],'label'=>$v['nombre']], $vets_sel??[]);
?>
<script>
document.addEventListener('DOMContentLoaded',function(){
    var _M=<?= json_encode(array_values($_js_mas)) ?>;
    var _V=<?= json_encode(array_values($_js_vet)) ?>;
    vetSearchSelect('inp-mas-hos','drop-mas-hos','hid-mas-hos',_M,'label');
    vetSearchSelect('inp-vet-hos','drop-vet-hos','hid-vet-hos',_V,'label');
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
