<?php
$page = 'examenes'; $pageTitle = 'Exámenes Auxiliares';
require_once __DIR__ . '/../includes/header.php';
$db = getDB();

$action     = $_GET['action'] ?? 'list';
$mascota_id = (int)($_GET['mascota_id'] ?? 0);
$consulta_id= (int)($_GET['consulta_id'] ?? 0);
$msg = ''; $err = '';

// ─── GUARDAR EXAMEN ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $pa = $_POST['action'] ?? '';

    if ($pa === 'save_examen') {
        $fields = ['mascota_id','veterinario_id','tipo','nombre','fecha','resultado','interpretacion','laboratorio','estado','notas'];
        $data = []; foreach($fields as $f) $data[$f] = trim($_POST[$f]??'') ?: null;
        $data['consulta_id'] = (int)($_POST['consulta_id']??0) ?: null;

        $cols = implode(',', array_merge($fields,['consulta_id']));
        $pls  = implode(',', array_map(fn($f)=>":$f", array_merge($fields,['consulta_id'])));
        $st = $db->prepare("INSERT INTO examenes_auxiliares ($cols) VALUES ($pls)");
        $st->execute($data);
        $examen_id = $db->lastInsertId();

        // Subir archivos adjuntos
        if (!empty($_FILES['archivos']['name'][0])) {
            subirArchivos($_FILES['archivos'], $examen_id, (int)$data['mascota_id'], null, $db);
        }
        $msg = 'success';
        $mascota_id = (int)$data['mascota_id'];
        $action = 'list';
    }

    if ($pa === 'update_resultado') {
        $id = (int)$_POST['id'];
        $db->prepare("UPDATE examenes_auxiliares SET resultado=?,interpretacion=?,estado=? WHERE id=?")
           ->execute([trim($_POST['resultado']), trim($_POST['interpretacion']), $_POST['estado'], $id]);
        // Subir nuevos archivos
        if (!empty($_FILES['archivos']['name'][0])) {
            $st = $db->prepare("SELECT mascota_id FROM examenes_auxiliares WHERE id=?");
            $st->execute([$id]); $row=$st->fetch();
            subirArchivos($_FILES['archivos'], $id, $row['mascota_id'], null, $db);
        }
        $msg = 'actualizado';
        $action = 'list';
    }

    if ($pa === 'delete_examen') {
        $id = (int)$_POST['id'];
        // Eliminar archivos físicos
        $archivos = $db->prepare("SELECT ruta FROM archivos_clinicos WHERE examen_id=?");
        $archivos->execute([$id]);
        foreach($archivos->fetchAll() as $a) {
            $ruta = UPLOADS_PATH . '/' . $a['ruta'];
            if (file_exists($ruta)) unlink($ruta);
        }
        $db->prepare("DELETE FROM examenes_auxiliares WHERE id=?")->execute([$id]);
        $msg = 'eliminado';
        $action = 'list';
    }

    if ($pa === 'delete_archivo') {
        $aid = (int)$_POST['archivo_id'];
        $st = $db->prepare("SELECT ruta FROM archivos_clinicos WHERE id=?");
        $st->execute([$aid]); $row = $st->fetch();
        if ($row) {
            $ruta = UPLOADS_PATH . '/' . $row['ruta'];
            if (file_exists($ruta)) unlink($ruta);
            $db->prepare("DELETE FROM archivos_clinicos WHERE id=?")->execute([$aid]);
        }
        jsonResponse(['ok'=>true]);
    }
}

// ─── HELPER: subir archivos ───────────────────────────────
function subirArchivos(array $files, int $examen_id, int $mascota_id, ?int $consulta_id, PDO $db): void {
    $allowed_mime = ['image/jpeg','image/png','image/gif','image/webp','application/pdf'];
    $max_size = 10 * 1024 * 1024; // 10MB

    $st = $db->prepare("INSERT INTO archivos_clinicos (examen_id,consulta_id,mascota_id,tipo,nombre,ruta,tamanio,mime_type,subido_por) VALUES (?,?,?,?,?,?,?,?,?)");
    global $user;

    foreach ($files['tmp_name'] as $i => $tmp) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
        if ($files['size'][$i] > $max_size) continue;

        $mime = mime_content_type($tmp);
        if (!in_array($mime, $allowed_mime)) continue;

        $ext  = $mime === 'application/pdf' ? 'pdf' : pathinfo($files['name'][$i], PATHINFO_EXTENSION);
        $ext  = strtolower(preg_replace('/[^a-zA-Z0-9]/','',$ext));
        $fname = 'exam_' . $examen_id . '_' . uniqid() . '.' . $ext;
        $dir  = UPLOADS_PATH . '/examenes/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        if (move_uploaded_file($tmp, $dir . $fname)) {
            $tipo = $mime === 'application/pdf' ? 'analisis' :
                    (strpos($mime,'image')===0 ? 'radiografia' : 'otro');
            $st->execute([
                $examen_id, $consulta_id, $mascota_id, $tipo,
                htmlspecialchars($files['name'][$i]),
                'examenes/' . $fname,
                $files['size'][$i], $mime,
                $user['id'] ?? 1
            ]);
        }
    }
}

// ─── DATOS ───────────────────────────────────────────────
$mascotas_sel = $db->query("SELECT m.id,CONCAT(m.nombre,' (',c.nombre,')') as label FROM mascotas m JOIN clientes c ON c.id=m.cliente_id WHERE m.estado='activo' ORDER BY m.nombre")->fetchAll();
$vets_sel     = $db->query("SELECT id,nombre FROM usuarios WHERE rol IN ('veterinario','admin') AND activo=1")->fetchAll();

// Mascota activa (desde contexto)
$mascota = null;
if ($mascota_id) {
    $st=$db->prepare("SELECT m.*,c.nombre as dueno FROM mascotas m JOIN clientes c ON c.id=m.cliente_id WHERE m.id=?");
    $st->execute([$mascota_id]); $mascota=$st->fetch();
}

// Lista exámenes
$where = "1=1"; $params=[];
$search = trim($_GET['q']??'');
if ($mascota_id) { $where .= " AND e.mascota_id=?"; $params[]=$mascota_id; }
elseif ($search) {
    $where .= " AND (m.nombre LIKE ? OR cl.nombre LIKE ? OR e.nombre LIKE ? OR e.resultado LIKE ?)";
    $like="%$search%"; $params=[$like,$like,$like,$like];
}
$tipo_f = $_GET['tipo']??'';
if ($tipo_f) { $where .= " AND e.tipo=?"; $params[]=$tipo_f; }
$estado_f = $_GET['estado']??'';
if ($estado_f) { $where .= " AND e.estado=?"; $params[]=$estado_f; }

$examenes = $db->prepare("
    SELECT e.*, m.nombre as mascota, m.especie, u.nombre as veterinario,
           cl.nombre as dueno,
           (SELECT COUNT(*) FROM archivos_clinicos a WHERE a.examen_id=e.id) as n_archivos
    FROM examenes_auxiliares e
    JOIN mascotas m ON m.id=e.mascota_id
    JOIN usuarios u ON u.id=e.veterinario_id
    JOIN clientes cl ON cl.id=m.cliente_id
    WHERE $where ORDER BY e.fecha DESC, e.id DESC LIMIT 60
");
$examenes->execute($params); $examenes=$examenes->fetchAll();

$tipo_labels  = ['hemograma'=>'Hemograma','bioquimica'=>'Bioquímica','orina'=>'Urianálisis','heces'=>'Coproparasitológico','radiografia'=>'Radiografía','ecografia'=>'Ecografía','electrocardiograma'=>'ECG','cultivo'=>'Cultivo','biopsia'=>'Biopsia','otro'=>'Otro'];
$tipo_icons   = ['hemograma'=>'🩸','bioquimica'=>'🧪','orina'=>'🟡','heces'=>'🔬','radiografia'=>'🦴','ecografia'=>'📡','electrocardiograma'=>'💓','cultivo'=>'🧫','biopsia'=>'🔬','otro'=>'📋'];
$tipo_badge   = ['hemograma'=>'b-red','bioquimica'=>'b-blue','orina'=>'b-amber','heces'=>'b-green','radiografia'=>'b-purple','ecografia'=>'b-blue','electrocardiograma'=>'b-red','cultivo'=>'b-teal','biopsia'=>'b-red','otro'=>'b-gray'];
$estado_badge = ['pendiente'=>'b-amber','resultado_parcial'=>'b-blue','completado'=>'b-teal'];
$especie_icons= ['perro'=>'🐕','gato'=>'🐈','conejo'=>'🐰','ave'=>'🐦','reptil'=>'🦎','roedor'=>'🐭','otro'=>'🐾'];

// Stats
$stats_tipos = $db->prepare("SELECT tipo,COUNT(*) as n FROM examenes_auxiliares e WHERE ".($mascota_id?"e.mascota_id=$mascota_id":"1=1")." GROUP BY tipo");
$stats_tipos->execute(); $st_map=[];
foreach($stats_tipos->fetchAll() as $r) $st_map[$r['tipo']]=$r['n'];
?>

<?php if($msg==='success'): ?><div class="alert alert-success mb-2">✅ Examen registrado correctamente.</div><?php endif; ?>
<?php if($msg==='actualizado'): ?><div class="alert alert-success mb-2">✅ Resultado actualizado.</div><?php endif; ?>
<?php if($msg==='eliminado'): ?><div class="alert alert-warn mb-2">🗑️ Examen eliminado.</div><?php endif; ?>

<?php if($action==='nuevo'): ?>
<!-- ══════════════════ FORMULARIO NUEVO EXAMEN ══════════════════ -->
<div class="card" style="max-width:720px">
  <div class="sec-header">
    <div><div class="sec-title">Registrar Examen Auxiliar</div><div class="sec-sub">Laboratorio, imagen diagnóstica, etc.</div></div>
    <a href="?p=examenes<?= $mascota_id?"&mascota_id=$mascota_id":'' ?>" class="btn btn-sm">← Volver</a>
  </div>
  <form method="POST" enctype="multipart/form-data">
    <input type="hidden" name="action" value="save_examen">
    <input type="hidden" name="consulta_id" value="<?= $consulta_id ?>">
    <div class="form-row">
      <div class="form-group"><label class="form-label">Mascota / Paciente *</label>
        <select class="form-input" name="mascota_id" required>
          <option value="">— Seleccionar —</option>
          <?php foreach($mascotas_sel as $m): ?><option value="<?= $m['id'] ?>" <?= $mascota_id==$m['id']?'selected':'' ?>><?= clean($m['label']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label class="form-label">Veterinario responsable *</label>
        <select class="form-input" name="veterinario_id" required>
          <?php foreach($vets_sel as $v): ?><option value="<?= $v['id'] ?>" <?= $v['id']==$user['id']?'selected':'' ?>><?= clean($v['nombre']) ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Tipo de examen *</label>
        <select class="form-input" name="tipo" id="sel-tipo" required onchange="updateTipoIcon(this.value)">
          <?php foreach($tipo_labels as $k=>$v): ?><option value="<?= $k ?>"><?= $tipo_icons[$k]??'📋' ?> <?= $v ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label class="form-label">Nombre / Descripción *</label>
        <input class="form-input" name="nombre" id="inp-nombre-exam" required placeholder="Ej: Hemograma completo + plaquetas">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Fecha *</label>
        <input class="form-input" type="date" name="fecha" value="<?= date('Y-m-d') ?>" required>
      </div>
      <div class="form-group"><label class="form-label">Laboratorio / Centro</label>
        <input class="form-input" name="laboratorio" placeholder="Ej: Vet Lab, Hospital Clínica">
      </div>
    </div>
    <div class="form-group"><label class="form-label">Resultado</label>
      <textarea class="form-input" name="resultado" style="min-height:90px" placeholder="Ingresa los valores obtenidos. Ej: Leucocitos: 8,500/µL (Normal: 6,000-17,000)&#10;Eritrocitos: 7.2 x10⁶/µL (Normal: 5.5-8.5)&#10;Hemoglobina: 15.2 g/dL (Normal: 12.0-18.0)"></textarea>
    </div>
    <div class="form-group"><label class="form-label">Interpretación / Diagnóstico</label>
      <textarea class="form-input" name="interpretacion" style="min-height:70px" placeholder="Ej: Valores dentro de rangos normales. Sin signos de anemia ni proceso inflamatorio activo."></textarea>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Estado</label>
        <select class="form-input" name="estado">
          <option value="pendiente">⏳ Pendiente de resultado</option>
          <option value="resultado_parcial">🔄 Resultado parcial</option>
          <option value="completado">✅ Completado</option>
        </select>
      </div>
      <div class="form-group"><label class="form-label">Notas adicionales</label>
        <input class="form-input" name="notas" placeholder="Ej: Muestra tomada en ayunas">
      </div>
    </div>

    <!-- ADJUNTOS -->
    <div style="border:2px dashed var(--border);border-radius:10px;padding:20px;margin-bottom:16px;transition:border-color .2s" id="drop-zone">
      <div class="text-center">
        <div style="font-size:32px;margin-bottom:8px">📎</div>
        <div class="font-bold text-sm mb-1">Adjuntar archivos (opcional)</div>
        <div class="text-xs text-muted mb-3">PDF, JPG, PNG, GIF · Máx. 10MB por archivo · Múltiples archivos permitidos</div>
        <label class="btn" style="cursor:pointer">
          Seleccionar archivos
          <input type="file" name="archivos[]" multiple accept=".pdf,.jpg,.jpeg,.png,.gif,.webp" id="inp-files" style="display:none" onchange="previewFiles(this)">
        </label>
      </div>
      <div id="files-preview" style="margin-top:12px;display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:8px"></div>
    </div>

    <div class="flex gap-1">
      <button type="submit" class="btn btn-primary">💾 Guardar examen</button>
      <a href="?p=examenes<?= $mascota_id?"&mascota_id=$mascota_id":'' ?>" class="btn">Cancelar</a>
    </div>
  </form>
</div>

<?php else: ?>
<!-- ══════════════════ LISTA ══════════════════ -->

<?php if($mascota): ?>
<!-- Banner mascota -->
<div class="card mb-2" style="padding:14px 18px;background:var(--blue-l);border-color:var(--blue)">
  <div class="flex items-center gap-3">
    <span style="font-size:36px"><?= $especie_icons[$mascota['especie']]??'🐾' ?></span>
    <div class="flex-1"><div style="font-size:16px;font-weight:700"><?= clean($mascota['nombre']) ?></div><div class="text-xs text-muted"><?= clean($mascota['raza']??'') ?> · Dueño: <?= clean($mascota['dueno']) ?></div></div>
    <div class="flex gap-1">
      <a href="?p=historial&mascota_id=<?= $mascota_id ?>" class="btn btn-sm">🏥 Historia</a>
      <a href="?p=vacunas&mascota_id=<?= $mascota_id ?>" class="btn btn-sm">💉 Vacunas</a>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Stats rápidas por tipo -->
<?php if(!empty($st_map)): ?>
<div class="flex gap-2 mb-2" style="flex-wrap:wrap">
  <?php foreach($tipo_labels as $k=>$v): if(empty($st_map[$k])) continue; ?>
  <a href="?p=examenes<?= $mascota_id?"&mascota_id=$mascota_id":'' ?>&tipo=<?= $k ?>" style="text-decoration:none">
    <div class="flex items-center gap-1" style="background:var(--bg2);border:1px solid var(--border);border-radius:20px;padding:5px 12px">
      <span><?= $tipo_icons[$k] ?></span><span class="text-sm font-med"><?= $v ?></span><span class="badge <?= $tipo_badge[$k] ?>" style="margin-left:2px"><?= $st_map[$k] ?></span>
    </div>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="sec-header">
  <div><div class="sec-title">Exámenes auxiliares</div><div class="sec-sub"><?= count($examenes) ?> registros</div></div>
  <div class="flex gap-1">
    <?php if(!$mascota_id): ?>
    <form method="GET" class="flex gap-1"><input type="hidden" name="p" value="examenes">
      <input class="form-input" name="q" value="<?= clean($search) ?>" placeholder="Buscar..." style="width:200px">
      <select class="form-input" name="tipo" style="width:150px"><option value="">Todos los tipos</option><?php foreach($tipo_labels as $k=>$v): ?><option value="<?= $k ?>" <?= $tipo_f===$k?'selected':'' ?>><?= $tipo_icons[$k] ?> <?= $v ?></option><?php endforeach; ?></select>
      <select class="form-input" name="estado" style="width:150px"><option value="">Todos los estados</option><option value="pendiente" <?= $estado_f==='pendiente'?'selected':'' ?>>Pendientes</option><option value="completado" <?= $estado_f==='completado'?'selected':'' ?>>Completados</option></select>
      <button type="submit" class="btn">Filtrar</button>
    </form>
    <?php endif; ?>
    <a href="?p=examenes&action=nuevo<?= $mascota_id?"&mascota_id=$mascota_id":'' ?><?= $consulta_id?"&consulta_id=$consulta_id":'' ?>" class="btn btn-primary">+ Nuevo Examen</a>
  </div>
</div>

<?php foreach($examenes as $ex):
  // Archivos de este examen
  $archivos_ex = $db->prepare("SELECT * FROM archivos_clinicos WHERE examen_id=? ORDER BY created_at DESC");
  $archivos_ex->execute([$ex['id']]); $archivos_ex=$archivos_ex->fetchAll();
?>
<div class="card mb-2" id="exam-<?= $ex['id'] ?>">
  <!-- Header examen -->
  <div class="flex items-center gap-2 mb-2">
    <span style="font-size:24px"><?= $tipo_icons[$ex['tipo']]??'📋' ?></span>
    <div class="flex-1">
      <div class="flex items-center gap-2 flex-wrap">
        <span class="font-bold" style="font-size:15px"><?= clean($ex['nombre']) ?></span>
        <span class="badge <?= $tipo_badge[$ex['tipo']]??'b-gray' ?>"><?= $tipo_labels[$ex['tipo']]??$ex['tipo'] ?></span>
        <span class="badge <?= $estado_badge[$ex['estado']] ?>">
          <?= $ex['estado']==='pendiente'?'⏳':($ex['estado']==='completado'?'✅':'🔄') ?>
          <?= ucfirst(str_replace('_',' ',$ex['estado'])) ?>
        </span>
        <?php if($ex['n_archivos']>0): ?><span class="badge b-gray">📎 <?= $ex['n_archivos'] ?> archivo<?= $ex['n_archivos']>1?'s':'' ?></span><?php endif; ?>
      </div>
      <div class="text-xs text-muted mt-1">
        <?php if(!$mascota_id): ?>
        <?= $especie_icons[$ex['especie']]??'🐾' ?> <strong><?= clean($ex['mascota']) ?></strong> · <?= clean($ex['dueno']) ?> ·
        <?php endif; ?>
        📅 <?= date('d/m/Y',strtotime($ex['fecha'])) ?> · 👨‍⚕️ <?= clean($ex['veterinario']) ?>
        <?php if($ex['laboratorio']): ?> · 🏥 <?= clean($ex['laboratorio']) ?><?php endif; ?>
      </div>
    </div>
    <div class="flex gap-1">
      <button class="btn btn-xs btn-primary" onclick="toggleResultado(<?= $ex['id'] ?>)">✏️ Editar resultado</button>
      <form method="POST" style="display:inline"><input type="hidden" name="action" value="delete_examen"><input type="hidden" name="id" value="<?= $ex['id'] ?>"><button type="submit" class="btn btn-xs" style="color:var(--red)" onclick="return confirm('¿Eliminar este examen y sus archivos?')">🗑️</button></form>
    </div>
  </div>

  <!-- Resultado -->
  <?php if($ex['resultado']): ?>
  <div style="background:var(--bg3);border-radius:8px;padding:12px;margin-bottom:10px">
    <div class="text-xs text-muted mb-1 font-bold uppercase" style="letter-spacing:.5px">📊 Resultado</div>
    <pre style="font-size:12px;line-height:1.7;white-space:pre-wrap;color:var(--text);font-family:inherit"><?= clean($ex['resultado']) ?></pre>
  </div>
  <?php endif; ?>
  <?php if($ex['interpretacion']): ?>
  <div style="background:var(--blue-l);border:1px solid #bfdbfe;border-radius:8px;padding:10px;margin-bottom:10px">
    <div class="text-xs font-bold mb-1" style="color:var(--blue-d);text-transform:uppercase;letter-spacing:.5px">🔍 Interpretación</div>
    <div class="text-sm" style="color:var(--blue-d)"><?= nl2br(clean($ex['interpretacion'])) ?></div>
  </div>
  <?php endif; ?>

  <!-- Archivos adjuntos -->
  <?php if(!empty($archivos_ex)): ?>
  <div class="mb-2">
    <div class="text-xs text-muted mb-2 font-bold uppercase" style="letter-spacing:.5px">📎 Archivos adjuntos</div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <?php foreach($archivos_ex as $af):
        $es_imagen = strpos($af['mime_type']??'','image')===0;
        $es_pdf    = ($af['mime_type']??'')==='application/pdf';
        $url_arch  = UPLOADS_URL . '/' . $af['ruta'];
      ?>
      <div style="position:relative;border:1px solid var(--border);border-radius:8px;overflow:hidden;width:120px;flex-shrink:0">
        <?php if($es_imagen): ?>
        <a href="<?= $url_arch ?>" target="_blank">
          <img src="<?= $url_arch ?>" alt="<?= clean($af['nombre']) ?>" style="width:120px;height:80px;object-fit:cover;display:block">
        </a>
        <?php else: ?>
        <a href="<?= $url_arch ?>" target="_blank" style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:80px;background:var(--bg3);text-decoration:none">
          <span style="font-size:28px"><?= $es_pdf?'📄':'📁' ?></span>
        </a>
        <?php endif; ?>
        <div style="padding:4px 6px;font-size:10px;color:var(--text2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="<?= clean($af['nombre']) ?>"><?= clean($af['nombre']) ?></div>
        <button onclick="eliminarArchivo(<?= $af['id'] ?>,this)" style="position:absolute;top:3px;right:3px;width:18px;height:18px;border-radius:50%;border:none;background:rgba(0,0,0,.5);color:#fff;font-size:10px;cursor:pointer;line-height:1">✕</button>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Form editar resultado (oculto) -->
  <div id="form-resultado-<?= $ex['id'] ?>" style="display:none;border-top:1px solid var(--border);padding-top:14px;margin-top:8px">
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action" value="update_resultado">
      <input type="hidden" name="id" value="<?= $ex['id'] ?>">
      <div class="form-group"><label class="form-label">Resultado</label>
        <textarea class="form-input" name="resultado" style="min-height:100px;font-family:monospace;font-size:12px"><?= clean($ex['resultado']??'') ?></textarea>
      </div>
      <div class="form-group"><label class="form-label">Interpretación</label>
        <textarea class="form-input" name="interpretacion" style="min-height:70px"><?= clean($ex['interpretacion']??'') ?></textarea>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Estado</label>
          <select class="form-input" name="estado">
            <option value="pendiente" <?= $ex['estado']==='pendiente'?'selected':'' ?>>⏳ Pendiente</option>
            <option value="resultado_parcial" <?= $ex['estado']==='resultado_parcial'?'selected':'' ?>>🔄 Parcial</option>
            <option value="completado" <?= $ex['estado']==='completado'?'selected':'' ?>>✅ Completado</option>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Agregar más archivos</label>
          <input type="file" name="archivos[]" multiple accept=".pdf,.jpg,.jpeg,.png,.gif" class="form-input" onchange="previewFiles(this)">
        </div>
      </div>
      <div class="flex gap-1"><button type="submit" class="btn btn-primary">💾 Guardar</button><button type="button" class="btn" onclick="toggleResultado(<?= $ex['id'] ?>)">Cancelar</button></div>
    </form>
  </div>
</div>
<?php endforeach; ?>
<?php if(empty($examenes)): ?><div class="card text-center text-muted" style="padding:48px"><div style="font-size:40px;margin-bottom:12px">🔬</div><div>No hay exámenes registrados<?= $mascota?" para {$mascota['nombre']}":'' ?>.</div><a href="?p=examenes&action=nuevo<?= $mascota_id?"&mascota_id=$mascota_id":'' ?>" class="btn btn-primary" style="margin-top:16px">+ Registrar primer examen</a></div><?php endif; ?>
<?php endif; ?>

<script>
// Preview de archivos antes de subir
function previewFiles(input) {
  const container = document.getElementById('files-preview') || input.closest('form').querySelector('[id^="files-preview"]');
  if (!container) return;
  container.innerHTML = '';
  Array.from(input.files).forEach(file => {
    const div = document.createElement('div');
    div.style.cssText = 'border:1px solid var(--border);border-radius:8px;overflow:hidden;text-align:center';
    if (file.type.startsWith('image/')) {
      const img = document.createElement('img');
      img.style.cssText = 'width:100%;height:80px;object-fit:cover;display:block';
      img.src = URL.createObjectURL(file);
      div.appendChild(img);
    } else {
      const icon = document.createElement('div');
      icon.style.cssText = 'height:80px;display:flex;align-items:center;justify-content:center;background:var(--bg3);font-size:28px';
      icon.textContent = file.name.endsWith('.pdf') ? '📄' : '📁';
      div.appendChild(icon);
    }
    const name = document.createElement('div');
    name.style.cssText = 'font-size:10px;padding:4px 6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--text2)';
    name.title = file.name;
    name.textContent = file.name;
    div.appendChild(name);
    container.appendChild(div);
  });
}

// Drag & drop zona
const dz = document.getElementById('drop-zone');
if (dz) {
  dz.addEventListener('dragover', e => { e.preventDefault(); dz.style.borderColor = 'var(--teal)'; dz.style.background = 'var(--teal-l)'; });
  dz.addEventListener('dragleave', () => { dz.style.borderColor = 'var(--border)'; dz.style.background = ''; });
  dz.addEventListener('drop', e => {
    e.preventDefault(); dz.style.borderColor = 'var(--border)'; dz.style.background = '';
    const inp = document.getElementById('inp-files');
    if (inp) { inp.files = e.dataTransfer.files; previewFiles(inp); }
  });
}

// Toggle form resultado
function toggleResultado(id) {
  const el = document.getElementById('form-resultado-' + id);
  if (el) el.style.display = el.style.display === 'none' ? 'block' : 'none';
}

// Autocompletar nombre según tipo
document.getElementById('sel-tipo')?.addEventListener('change', function() {
  const nombres = {hemograma:'Hemograma completo',bioquimica:'Perfil bioquímico completo',orina:'Urianálisis completo',heces:'Examen coproparasitológico',radiografia:'Radiografía de tórax',ecografia:'Ecografía abdominal',electrocardiograma:'Electrocardiograma',cultivo:'Cultivo y antibiograma',biopsia:'Biopsia y análisis histopatológico'};
  const inp = document.getElementById('inp-nombre-exam');
  if (inp && !inp.value && nombres[this.value]) inp.value = nombres[this.value];
});

// Eliminar archivo
async function eliminarArchivo(id, btn) {
  if (!confirm('¿Eliminar este archivo?')) return;
  btn.closest('div[style*="width:120px"]').style.opacity = '.4';
  try {
    const resp = await fetch('<?= BASE_URL ?>/modules/examenes.php', {
      method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:'action=delete_archivo&archivo_id='+id
    });
    const d = await resp.json();
    if (d.ok) btn.closest('div[style*="width:120px"]').remove();
  } catch(e) { btn.closest('div[style*="width:120px"]').style.opacity='1'; }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
