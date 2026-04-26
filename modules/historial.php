<?php
$page = 'historial'; $pageTitle = 'Historia Clínica';
require_once __DIR__ . '/../includes/header.php';
$db = getDB();

$action     = $_GET['action'] ?? 'list';
$mascota_id = (int)($_GET['mascota_id'] ?? 0);
$cita_id    = (int)($_GET['cita_id'] ?? 0);
$msg = '';

// GUARDAR CONSULTA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_consulta') {
    $fields = ['mascota_id','veterinario_id','tipo','fecha','peso_actual','temperatura','frecuencia_cardiaca','frecuencia_respiratoria','sintomas','diagnostico','tratamiento','observaciones','proximo_control'];
    $data = []; foreach ($fields as $f) $data[$f] = trim($_POST[$f] ?? '') ?: null;
    $data['cita_id'] = (int)($_POST['cita_id'] ?? 0) ?: null;
    $data['sede_id'] = $user['sede_id'] ?? 1;
    $cols = implode(',', array_merge($fields,['cita_id','sede_id']));
    $pls  = implode(',', array_map(fn($f)=>":$f", array_merge($fields,['cita_id','sede_id'])));
    $st = $db->prepare("INSERT INTO consultas ($cols) VALUES ($pls)");
    $st->execute($data);
    $consulta_id = $db->lastInsertId();

    // Marcar cita como atendida
    if ($data['cita_id']) $db->prepare("UPDATE citas SET estado='atendida' WHERE id=?")->execute([$data['cita_id']]);

    // Guardar receta si hay ítems
    if (!empty($_POST['med_nombre'][0])) {
        $st2 = $db->prepare("INSERT INTO recetas (consulta_id,mascota_id,veterinario_id,fecha,indicaciones) VALUES (?,?,?,CURDATE(),?)");
        $st2->execute([$consulta_id, $data['mascota_id'], $data['veterinario_id'], trim($_POST['indicaciones']??'')]);
        $receta_id = $db->lastInsertId();
        $st3 = $db->prepare("INSERT INTO receta_items (receta_id,medicamento,dosis,frecuencia,duracion,via) VALUES (?,?,?,?,?,?)");
        foreach ($_POST['med_nombre'] as $i => $med) {
            if(trim($med)) $st3->execute([$receta_id, trim($med), trim($_POST['med_dosis'][$i]??''), trim($_POST['med_frecuencia'][$i]??''), trim($_POST['med_duracion'][$i]??''), trim($_POST['med_via'][$i]??'')]);
        }
    }
    $msg = 'success'; $action = 'list';
    if ($mascota_id) $action = 'ver_mascota';
}

// Datos para selects
$vets_sel = $db->query("SELECT id,nombre FROM usuarios WHERE rol IN ('veterinario','admin') AND activo=1")->fetchAll();
$mascotas_sel = $db->query("SELECT m.id,CONCAT(m.nombre,' (',c.nombre,')') as label FROM mascotas m JOIN clientes c ON c.id=m.cliente_id WHERE m.estado='activo' ORDER BY m.nombre")->fetchAll();

// Ver historia de una mascota específica
$mascota = null;
if ($mascota_id) {
    $st = $db->prepare("SELECT m.*,c.nombre as dueno,c.telefono FROM mascotas m JOIN clientes c ON c.id=m.cliente_id WHERE m.id=?");
    $st->execute([$mascota_id]); $mascota = $st->fetch();
}

// Consultas filtradas
$where = "1=1"; $params = [];
$search = trim($_GET['q'] ?? '');
if ($mascota_id) { $where .= " AND con.mascota_id=?"; $params[] = $mascota_id; }
elseif ($search) { $where .= " AND (m.nombre LIKE ? OR cl.nombre LIKE ? OR con.diagnostico LIKE ?)"; $like="%$search%"; $params=[$like,$like,$like]; }

$consultas = $db->prepare("
    SELECT con.*, m.nombre as mascota, m.especie, u.nombre as veterinario, cl.nombre as dueno,
    (SELECT COUNT(*) FROM recetas r WHERE r.consulta_id=con.id) as tiene_receta
    FROM consultas con
    JOIN mascotas m ON m.id=con.mascota_id
    JOIN usuarios u ON u.id=con.veterinario_id
    JOIN clientes cl ON cl.id=m.cliente_id
    WHERE $where ORDER BY con.fecha DESC LIMIT 50
");
$consultas->execute($params); $consultas = $consultas->fetchAll();

$especie_icons = ['perro'=>'🐕','gato'=>'🐈','conejo'=>'🐰','ave'=>'🐦','reptil'=>'🦎','roedor'=>'🐭','otro'=>'🐾'];
$tipo_labels   = ['consulta'=>'Consulta','control'=>'Control','emergencia'=>'Emergencia','cirugia'=>'Cirugía','vacuna'=>'Vacuna','hospitalizacion'=>'Hospitalización'];
$tipo_badge    = ['consulta'=>'b-teal','control'=>'b-purple','emergencia'=>'b-red','cirugia'=>'b-red','vacuna'=>'b-blue','hospitalizacion'=>'b-amber'];
?>
<?php if($msg==='success'): ?><div class="alert alert-success mb-2">✅ Consulta registrada correctamente.</div><?php endif; ?>

<?php if($action === 'nueva'): ?>
<!-- FORMULARIO NUEVA CONSULTA -->
<div class="card" style="max-width:780px">
  <div class="sec-header"><div class="sec-title">Nueva Consulta Médica</div><a href="?p=historial<?= $mascota_id?"&mascota_id=$mascota_id":'' ?>" class="btn btn-sm">← Volver</a></div>
  <form method="POST">
    <input type="hidden" name="action" value="save_consulta">
    <input type="hidden" name="cita_id" value="<?= $cita_id ?>">
    <div class="form-row">
      <div class="form-group"><label class="form-label">Paciente *</label>
        <select class="form-input" name="mascota_id" required>
          <option value="">— Seleccionar —</option>
          <?php foreach($mascotas_sel as $m): ?><option value="<?= $m['id'] ?>" <?= $mascota_id==$m['id']?'selected':'' ?>><?= clean($m['label']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label class="form-label">Veterinario *</label>
        <select class="form-input" name="veterinario_id" required>
          <?php foreach($vets_sel as $v): ?><option value="<?= $v['id'] ?>" <?= $v['id']==$user['id']?'selected':'' ?>><?= clean($v['nombre']) ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Tipo *</label>
        <select class="form-input" name="tipo" required>
          <?php foreach($tipo_labels as $k=>$v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label class="form-label">Fecha y hora</label><input class="form-input" type="datetime-local" name="fecha" value="<?= date('Y-m-d\TH:i') ?>"></div>
    </div>
    <div class="form-row-3">
      <div class="form-group"><label class="form-label">Peso (kg)</label><input class="form-input" type="number" step="0.1" name="peso_actual"></div>
      <div class="form-group"><label class="form-label">Temperatura (°C)</label><input class="form-input" type="number" step="0.1" name="temperatura"></div>
      <div class="form-group"><label class="form-label">Freq. cardíaca (rpm)</label><input class="form-input" type="number" name="frecuencia_cardiaca"></div>
    </div>
    <div class="form-group"><label class="form-label">Síntomas / Motivo de consulta *</label><textarea class="form-input" name="sintomas" required></textarea></div>
    <div class="form-group"><label class="form-label">Diagnóstico *</label><textarea class="form-input" name="diagnostico" required></textarea></div>
    <div class="form-group"><label class="form-label">Tratamiento</label><textarea class="form-input" name="tratamiento"></textarea></div>
    <div class="form-group"><label class="form-label">Observaciones</label><textarea class="form-input" name="observaciones" style="min-height:60px"></textarea></div>
    <div class="form-group"><label class="form-label">Próximo control</label><input class="form-input" type="date" name="proximo_control"></div>

    <!-- RECETA -->
    <div style="border:1px solid var(--border);border-radius:10px;padding:16px;margin-top:8px">
      <div class="flex items-center justify-between mb-2"><div class="sec-title">💊 Receta médica (opcional)</div><button type="button" class="btn btn-sm" onclick="addMed()">+ Medicamento</button></div>
      <div id="med-list">
        <div class="med-row" style="display:grid;grid-template-columns:2fr 1.5fr 1.5fr 1.5fr 1fr auto;gap:8px;margin-bottom:8px;align-items:end">
          <div><label class="form-label">Medicamento</label><input class="form-input" name="med_nombre[]" placeholder="Ej: Amoxicilina 500mg"></div>
          <div><label class="form-label">Dosis</label><input class="form-input" name="med_dosis[]" placeholder="Ej: 1 comprimido"></div>
          <div><label class="form-label">Frecuencia</label><input class="form-input" name="med_frecuencia[]" placeholder="Ej: c/12 horas"></div>
          <div><label class="form-label">Duración</label><input class="form-input" name="med_duracion[]" placeholder="Ej: 7 días"></div>
          <div><label class="form-label">Vía</label><select class="form-input" name="med_via[]"><option>Oral</option><option>Tópico</option><option>IM</option><option>IV</option><option>SC</option></select></div>
          <div style="padding-bottom:2px"><button type="button" onclick="this.closest('.med-row').remove()" class="btn btn-xs" style="color:var(--red)">✕</button></div>
        </div>
      </div>
      <div class="form-group mt-1"><label class="form-label">Indicaciones generales</label><textarea class="form-input" name="indicaciones" style="min-height:55px" placeholder="Ej: Administrar con comida. Regresar si no mejora en 48h."></textarea></div>
    </div>

    <div class="flex gap-1 mt-2"><button type="submit" class="btn btn-primary">💾 Guardar consulta</button><a href="?p=historial" class="btn">Cancelar</a></div>
  </form>
</div>

<?php else: ?>

<?php if($mascota && $mascota_id): ?>
<!-- HEADER MASCOTA -->
<div class="card mb-2" style="padding:16px 20px;background:var(--teal-l);border-color:var(--teal)">
  <div class="flex items-center gap-3">
    <div style="font-size:44px"><?= $especie_icons[$mascota['especie']]??'🐾' ?></div>
    <div class="flex-1">
      <div style="font-size:18px;font-weight:700;color:var(--text)"><?= clean($mascota['nombre']) ?></div>
      <div class="text-sm text-muted"><?= clean($mascota['raza']??'') ?> · <?= $mascota['peso'] ? $mascota['peso'].' kg' : '' ?></div>
      <?php if($mascota['alergias']): ?><div class="text-xs" style="color:var(--red)">⚠️ <?= clean($mascota['alergias']) ?></div><?php endif; ?>
    </div>
    <div class="text-right">
      <div class="text-xs text-muted">Dueño</div>
      <div class="font-bold"><?= clean($mascota['dueno']) ?></div>
      <div class="text-xs text-muted"><?= clean($mascota['telefono']) ?></div>
    </div>
    <div class="flex gap-1">
      <a href="?p=historial&action=nueva&mascota_id=<?= $mascota_id ?>" class="btn btn-primary btn-sm">+ Nueva consulta</a>
      <a href="?p=vacunas&mascota_id=<?= $mascota_id ?>" class="btn btn-sm">💉 Vacunas</a>
      <a href="?p=examenes&mascota_id=<?= $mascota_id ?>" class="btn btn-sm">🔬 Exámenes</a>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="sec-header">
  <div><div class="sec-title">Historia Clínica</div><div class="sec-sub"><?= count($consultas) ?> consultas registradas</div></div>
  <div class="flex gap-1">
    <?php if(!$mascota_id): ?>
    <form method="GET" class="flex gap-1"><input type="hidden" name="p" value="historial">
      <input class="form-input" name="q" value="<?= clean($search) ?>" placeholder="Buscar paciente o diagnóstico..." style="width:260px">
      <button type="submit" class="btn">Buscar</button>
    </form>
    <?php endif; ?>
    <a href="?p=historial&action=nueva<?= $mascota_id?"&mascota_id=$mascota_id":'' ?>" class="btn btn-primary">+ Nueva Consulta</a>
  </div>
</div>

<?php foreach($consultas as $con):
  $receta_items = [];
  if($con['tiene_receta']) {
    $st = $db->prepare("SELECT ri.* FROM receta_items ri JOIN recetas r ON r.id=ri.receta_id WHERE r.consulta_id=?");
    $st->execute([$con['id']]); $receta_items = $st->fetchAll();
  }
?>
<div class="card mb-2">
  <div class="flex items-center gap-2 mb-2">
    <span class="badge <?= $tipo_badge[$con['tipo']]??'b-gray' ?>"><?= $tipo_labels[$con['tipo']]??$con['tipo'] ?></span>
    <?php if(!$mascota_id): ?>
    <span style="font-size:18px"><?= $especie_icons[$con['especie']]??'🐾' ?></span>
    <span class="font-bold"><?= clean($con['mascota']) ?></span>
    <span class="text-muted">·</span>
    <span class="text-muted text-sm"><?= clean($con['dueno']) ?></span>
    <?php endif; ?>
    <span class="text-muted text-sm" style="margin-left:auto"><?= date('d/m/Y H:i',strtotime($con['fecha'])) ?> · <?= clean($con['veterinario']) ?></span>
    <?php if($con['tiene_receta']): ?><span class="badge b-green">💊 Con receta</span><?php endif; ?>
  </div>
  <div class="grid g2" style="gap:10px">
    <?php if($con['sintomas']): ?><div style="padding:10px;background:var(--bg3);border-radius:8px"><div class="text-xs text-muted mb-1">SÍNTOMAS / MOTIVO</div><div class="text-sm"><?= nl2br(clean($con['sintomas'])) ?></div></div><?php endif; ?>
    <div style="padding:10px;background:var(--bg3);border-radius:8px"><div class="text-xs text-muted mb-1">DIAGNÓSTICO</div><div class="text-sm font-med"><?= nl2br(clean($con['diagnostico'])) ?></div></div>
    <?php if($con['tratamiento']): ?><div style="padding:10px;background:var(--bg3);border-radius:8px"><div class="text-xs text-muted mb-1">TRATAMIENTO</div><div class="text-sm"><?= nl2br(clean($con['tratamiento'])) ?></div></div><?php endif; ?>
    <?php if($con['peso_actual']||$con['temperatura']||$con['frecuencia_cardiaca']): ?>
    <div style="padding:10px;background:var(--bg3);border-radius:8px">
      <div class="text-xs text-muted mb-1">SIGNOS VITALES</div>
      <div class="flex gap-2 flex-wrap">
        <?php if($con['peso_actual']): ?><span class="badge b-teal">⚖️ <?= $con['peso_actual'] ?> kg</span><?php endif; ?>
        <?php if($con['temperatura']): ?><span class="badge b-amber">🌡️ <?= $con['temperatura'] ?>°C</span><?php endif; ?>
        <?php if($con['frecuencia_cardiaca']): ?><span class="badge b-red">❤️ <?= $con['frecuencia_cardiaca'] ?> rpm</span><?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
  <?php if(!empty($receta_items)): ?>
  <div style="margin-top:12px;padding:12px;border:1px solid var(--border);border-radius:8px">
    <div class="text-xs text-muted mb-2">💊 MEDICAMENTOS RECETADOS</div>
    <div class="table-wrap"><table class="vtable" style="font-size:12px"><thead><tr><th>Medicamento</th><th>Dosis</th><th>Frecuencia</th><th>Duración</th><th>Vía</th></tr></thead><tbody>
      <?php foreach($receta_items as $ri): ?>
      <tr><td class="td-main"><?= clean($ri['medicamento']) ?></td><td><?= clean($ri['dosis']) ?></td><td><?= clean($ri['frecuencia']) ?></td><td><?= clean($ri['duracion']) ?></td><td><?= clean($ri['via']) ?></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
  </div>
  <?php endif; ?>
  <?php if($con['proximo_control']): ?>
  <div class="flex items-center gap-2 mt-2">
    <span class="badge b-amber">📅 Próximo control: <?= date('d/m/Y',strtotime($con['proximo_control'])) ?></span>
    <?php
    $mascota_wa = $db->prepare("SELECT m.*,c.nombre as dueno,c.telefono FROM mascotas m JOIN clientes c ON c.id=m.cliente_id WHERE m.id=?");
    $mascota_wa->execute([$con['mascota_id']]); $mwa=$mascota_wa->fetch();
    if($mwa) {
      $tel=preg_replace('/[^0-9]/','',ltrim($mwa['telefono'],'+'));
      if(strlen($tel)<11) $tel='51'.$tel;
      $wa_msg="🏥 *Recordatorio VetPro*\n\nHola {$mwa['dueno']} 👋\n\nSe acerca el control médico de *{$mwa['nombre']}*:\n📅 ".date('d/m/Y',strtotime($con['proximo_control']))."\n\nEscríbenos para agendar su cita.\n\nVetPro 🐾";
      echo '<a href="https://wa.me/'.$tel.'?text='.rawurlencode($wa_msg).'" target="_blank" class="btn btn-xs btn-wa">💬 Recordar por WA</a>';
    } ?>
  </div>
  <?php endif; ?>
</div>
<?php endforeach; ?>
<?php if(empty($consultas)): ?><div class="card text-center text-muted" style="padding:48px">No se encontraron consultas.</div><?php endif; ?>
<?php endif; ?>

<script>
var medCount = 1;
function addMed() {
  var html = `<div class="med-row" style="display:grid;grid-template-columns:2fr 1.5fr 1.5fr 1.5fr 1fr auto;gap:8px;margin-bottom:8px;align-items:center">
    <input class="form-input" name="med_nombre[]" placeholder="Medicamento">
    <input class="form-input" name="med_dosis[]" placeholder="Dosis">
    <input class="form-input" name="med_frecuencia[]" placeholder="Frecuencia">
    <input class="form-input" name="med_duracion[]" placeholder="Duración">
    <select class="form-input" name="med_via[]"><option>Oral</option><option>Tópico</option><option>IM</option><option>IV</option><option>SC</option></select>
    <button type="button" onclick="this.closest('.med-row').remove()" class="btn btn-xs" style="color:var(--red)">✕</button>
  </div>`;
  document.getElementById('med-list').insertAdjacentHTML('beforeend', html);
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
