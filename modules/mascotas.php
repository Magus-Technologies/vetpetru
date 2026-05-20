<?php
$page = 'mascotas'; $pageTitle = 'Mascotas';
require_once __DIR__ . '/../includes/header.php';
$db = getDB();

$action = $_GET['action'] ?? 'list';
$msg = '';

// ── POST: guardar ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $pa = $_POST['action'];
    if ($pa === 'save') {
        $id = (int)($_POST['id']??0);
        $fields = ['cliente_id','nombre','especie','raza','sexo','fecha_nacimiento','peso',
                   'color','chip_numero','alergias','condiciones','estado',
                   'grupo_sanguineo','personalidad','esterilizado','microchip','alimentacion','observaciones'];
        $data=[]; foreach($fields as $f) $data[$f]=trim($_POST[$f]??'')?:null;
        // Asignar sede activa al crear mascota nueva
        $data['sede_id'] = getSede();
        $foto_nueva=null;
        if (!empty($_FILES['foto']['tmp_name']) && $_FILES['foto']['error']===UPLOAD_ERR_OK) {
            $mime=mime_content_type($_FILES['foto']['tmp_name']);
            if (in_array($mime,['image/jpeg','image/png','image/webp','image/gif']) && $_FILES['foto']['size']<=5*1024*1024) {
                $dir=UPLOADS_PATH.'/mascotas/';
                if(!is_dir($dir)) mkdir($dir,0755,true);
                if ($id) { $old=$db->prepare("SELECT foto FROM mascotas WHERE id=?"); $old->execute([$id]); $oldrow=$old->fetch(); if($oldrow&&$oldrow['foto']&&file_exists(UPLOADS_PATH.'/'.$oldrow['foto'])) unlink(UPLOADS_PATH.'/'.$oldrow['foto']); }
                $ext=$mime==='image/png'?'png':($mime==='image/webp'?'webp':'jpg');
                $fname='mascota_'.($id?:time()).'_'.uniqid().'.'.$ext;
                if (function_exists('imagecreatefromstring')) {
                    $src=imagecreatefromstring(file_get_contents($_FILES['foto']['tmp_name']));
                    if($src){$w=imagesx($src);$h=imagesy($src);$max=120;$nw=$w;$nh=$h;if($w>$max||$h>$max){$r=$w>$h?$max/$w:$max/$h;$nw=round($w*$r);$nh=round($h*$r);}$dst=imagecreatetruecolor($nw,$nh);imagecopyresampled($dst,$src,0,0,0,0,$nw,$nh,$w,$h);imagejpeg($dst,$dir.$fname,90);imagedestroy($src);imagedestroy($dst);$foto_nueva='mascotas/'.$fname;}
                }
                if(!$foto_nueva && move_uploaded_file($_FILES['foto']['tmp_name'],$dir.$fname)) $foto_nueva='mascotas/'.$fname;
            }
        }
        // Agregar columnas extra si no existen (MariaDB 10.5 compatible)
        foreach(['grupo_sanguineo VARCHAR(10)','personalidad VARCHAR(200)','esterilizado TINYINT(1) DEFAULT 0','microchip TINYINT(1) DEFAULT 0','alimentacion TEXT','observaciones TEXT','sede_id INT DEFAULT 1'] as $col) {
            $colname = explode(' ', trim($col))[0];
            try {
                $existing = $db->query("SHOW COLUMNS FROM mascotas LIKE '$colname'")->fetchAll();
                if (empty($existing)) $db->exec("ALTER TABLE mascotas ADD COLUMN $col");
            } catch(Exception $e) {}
        }
        if ($id) {
            // UPDATE — no incluir sede_id en fields para no sobreescribir
            $sets=implode(',',array_map(fn($f)=>"$f=:$f",$fields));
            if($foto_nueva){$sets.=",foto=:foto";$data['foto']=$foto_nueva;}
            $st=$db->prepare("UPDATE mascotas SET $sets WHERE id=:id"); $data['id']=$id;
            unset($data['sede_id']); // No cambiar sede en edicion
        } else {
            // INSERT — incluir sede_id
            $insert_fields = array_merge($fields, ['sede_id']);
            $flds=$foto_nueva?array_merge($insert_fields,['foto']):$insert_fields;
            if($foto_nueva)$data['foto']=$foto_nueva;
            $cols=implode(',',$flds);$pls=implode(',',array_map(fn($f)=>":$f",$flds));
            $st=$db->prepare("INSERT INTO mascotas ($cols) VALUES ($pls)");
        }
        $st->execute($data); $msg='success';
        if ($id) { $action='ver'; $_GET['id']=$id; } else { $action='list'; }
    }
    if ($pa==='delete_foto') {
        $id=(int)$_POST['id'];$st=$db->prepare("SELECT foto FROM mascotas WHERE id=?");$st->execute([$id]);$row=$st->fetch();
        if($row&&$row['foto']){$ruta=UPLOADS_PATH.'/'.$row['foto'];if(file_exists($ruta))unlink($ruta);}
        $db->prepare("UPDATE mascotas SET foto=NULL WHERE id=?")->execute([$id]);
        header('Content-Type: application/json'); echo json_encode(['ok'=>true]); exit;
    }
}
if ($action==='delete' && isset($_GET['id'])) {
    $db->prepare("UPDATE mascotas SET estado='dado_en_adopcion' WHERE id=?")->execute([(int)$_GET['id']]); $action='list';
}

$clientes_sel=$db->query("SELECT id,nombre,telefono FROM clientes WHERE activo=1 ORDER BY nombre")->fetchAll();
$especie_icons=['perro'=>'🐕','gato'=>'🐈','conejo'=>'🐰','ave'=>'🐦','reptil'=>'🦎','roedor'=>'🐭','otro'=>'🐾'];
$especie_labels=['perro'=>'Perino','gato'=>'Gato','conejo'=>'Conejo','ave'=>'Ave','reptil'=>'Reptil','roedor'=>'Roedor','otro'=>'Otro'];

// ── VER perfil completo de mascota (imagen 2) ──
if ($action==='ver' && isset($_GET['id'])) {
    $mid=(int)$_GET['id'];
    $st=$db->prepare("SELECT m.*,c.nombre as dueno,c.telefono,c.email,c.dni FROM mascotas m JOIN clientes c ON c.id=m.cliente_id WHERE m.id=?");
    $st->execute([$mid]); $m=$st->fetch();
    if (!$m) { $action='list'; goto list_view; }
    $foto_url = !empty($m['foto'])&&file_exists(UPLOADS_PATH.'/'.$m['foto']) ? BASE_URL.'/public/uploads/'.$m['foto'] : null;
    // Historial actividad reciente
    $actividad=$db->prepare("
        (SELECT 'consulta' as tipo, con.fecha, con.diagnostico as desc1, u.nombre as vet FROM consultas con JOIN usuarios u ON u.id=con.veterinario_id WHERE con.mascota_id=? ORDER BY con.fecha DESC LIMIT 3)
        UNION ALL
        (SELECT 'vacuna', v.fecha_aplicacion, v.tipo_vacuna, u.nombre FROM vacunas v JOIN usuarios u ON u.id=v.veterinario_id WHERE v.mascota_id=? ORDER BY v.fecha_aplicacion DESC LIMIT 2)
        UNION ALL
        (SELECT 'examen', e.fecha, e.nombre, u.nombre FROM examenes_auxiliares e JOIN usuarios u ON u.id=e.veterinario_id WHERE e.mascota_id=? ORDER BY e.fecha DESC LIMIT 2)
        ORDER BY fecha DESC LIMIT 6");
    try { $actividad->execute([$mid,$mid,$mid]); $actividad=$actividad->fetchAll(); } catch(Exception $e){ $actividad=[]; }
    // Stats
    $n_consultas=$db->prepare("SELECT COUNT(*) FROM consultas WHERE mascota_id=?");$n_consultas->execute([$mid]);$n_consultas=(int)$n_consultas->fetchColumn();
    $n_vacunas=$db->prepare("SELECT COUNT(*) FROM vacunas WHERE mascota_id=?");$n_vacunas->execute([$mid]);$n_vacunas=(int)$n_vacunas->fetchColumn();
    try{$n_examenes=$db->prepare("SELECT COUNT(*) FROM examenes_auxiliares WHERE mascota_id=?");$n_examenes->execute([$mid]);$n_examenes=(int)$n_examenes->fetchColumn();}catch(Exception $e){$n_examenes=0;}
    // Próximas acciones
    $prox_cita=$db->prepare("SELECT * FROM citas WHERE mascota_id=? AND fecha>=CURDATE() AND estado IN ('pendiente','confirmada') ORDER BY fecha ASC LIMIT 1");$prox_cita->execute([$mid]);$prox_cita=$prox_cita->fetch();
    $prox_vac=$db->prepare("SELECT * FROM vacunas WHERE mascota_id=? AND proxima_dosis>=CURDATE() ORDER BY proxima_dosis ASC LIMIT 1");$prox_vac->execute([$mid]);$prox_vac=$prox_vac->fetch();
    // Documentos
    try{$docs=$db->prepare("SELECT * FROM archivos_clinicos WHERE mascota_id=? ORDER BY created_at DESC LIMIT 4");$docs->execute([$mid]);$docs=$docs->fetchAll();}catch(Exception $e){$docs=[];}
    // Edad
    $edad=''; if($m['fecha_nacimiento']){$diff=(new DateTime())->diff(new DateTime($m['fecha_nacimiento']));$edad=$diff->y>0?$diff->y.' año'.($diff->y>1?'s':'').' y '.$diff->m.' meses':$diff->m.' mes'.($diff->m>1?'es':'');}
    $tel=preg_replace('/[^0-9]/','',ltrim($m['telefono'],'+'));if(strlen($tel)<11)$tel='51'.$tel;
    ?>

<style>
.mas-profile { display:grid; grid-template-columns:300px 1fr 260px; gap:16px; }
/* Dropdown menu items */
.mas-dmenu-item {
  display:flex;align-items:center;gap:9px;padding:9px 13px;
  font-size:13px;color:var(--text);text-decoration:none;border-radius:8px;
  transition:background .12s;font-weight:500;
}
.mas-dmenu-item:hover { background:var(--bg3); color:var(--primary); }
.mas-left { display:flex; flex-direction:column; gap:14px; }
.mas-center { display:flex; flex-direction:column; gap:14px; }
.mas-right { display:flex; flex-direction:column; gap:14px; }
.mas-photo-card { background:var(--bg2); border:1px solid var(--border); border-radius:14px; overflow:hidden; }
.mas-photo-wrap { width:100%; aspect-ratio:1; background:var(--bg3); display:flex; align-items:center; justify-content:center; position:relative; overflow:hidden; }
.mas-photo-wrap img { width:100%; height:100%; object-fit:cover; }
.mas-photo-emoji { font-size:80px; opacity:.6; }
.mas-photo-badge { position:absolute; top:10px; right:10px; }
.mas-info-card { background:var(--bg2); border:1px solid var(--border); border-radius:14px; padding:0; overflow:hidden; }
.mas-ic-head { padding:14px 16px; border-bottom:1px solid var(--border); font-size:12px; font-weight:700; color:var(--text2); text-transform:uppercase; letter-spacing:.5px; }
.mas-ic-body { padding:14px 16px; }
.mas-field { display:flex; justify-content:space-between; align-items:center; padding:6px 0; border-bottom:1px solid var(--border); }
.mas-field:last-child { border-bottom:none; }
.mas-field-label { font-size:12px; color:var(--text3); font-weight:500; }
.mas-field-val { font-size:12px; font-weight:600; color:var(--text); text-align:right; }
.mas-tabs { display:flex; gap:2px; border-bottom:2px solid var(--border); margin-bottom:16px; overflow-x:auto; }
.mas-tab { padding:9px 16px; border-radius:8px 8px 0 0; font-size:12px; font-weight:600; color:var(--text3); cursor:pointer; border:none; background:none; white-space:nowrap; transition:all .15s; margin-bottom:-2px; border-bottom:2px solid transparent; }
.mas-tab.active { color:var(--primary); border-bottom-color:var(--primary); background:transparent; }
.mas-tab:hover { color:var(--text2); }
.mas-tab-content { display:none; } .mas-tab-content.active { display:block; }
.act-item { display:flex; align-items:center; gap:10px; padding:10px 0; border-bottom:1px solid var(--border); }
.act-item:last-child { border-bottom:none; }
.act-icon { width:34px; height:34px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:15px; flex-shrink:0; }
.prox-item { display:flex; align-items:center; gap:10px; padding:10px 14px; border:1px solid var(--border); border-radius:10px; margin-bottom:8px; }
.prox-dias { font-size:10px; font-weight:700; padding:2px 8px; border-radius:999px; }
.resumen-med-stat { padding:10px 16px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; }
.resumen-med-stat:last-child { border-bottom:none; }
.nota-card { background:#fefce8; border:1px solid #fde68a; border-radius:10px; padding:12px 14px; font-size:12px; color:#78350f; line-height:1.6; }
@media(max-width:1100px) { .mas-profile { grid-template-columns:240px 1fr; } .mas-right { display:none; } }
</style>

<?php if($msg==='success'): ?><div class="alert alert-success mb-2"><span class="alert-icon">✅</span>Mascota actualizada correctamente.</div><?php endif; ?>

<!-- BREADCRUMB -->
<div style="display:flex;align-items:center;gap:8px;font-size:12px;color:var(--text3);margin-bottom:16px">
  <a href="<?= BASE_URL ?>/index.php?p=mascotas" style="color:var(--text3);text-decoration:none">Mascotas</a>
  <span>›</span>
  <span style="color:var(--text);font-weight:600"><?= clean($m['nombre']) ?></span>
</div>

<!-- HEADER RÁPIDO -->
<div style="background:var(--bg2);border:1px solid var(--border);border-radius:14px;padding:16px 20px;margin-bottom:16px;display:flex;align-items:center;gap:16px">
  <div style="width:52px;height:52px;border-radius:12px;overflow:hidden;flex-shrink:0;background:var(--bg3);display:flex;align-items:center;justify-content:center">
    <?php if($foto_url): ?><img src="<?= $foto_url ?>" style="width:100%;height:100%;object-fit:cover"><?php else: ?><span style="font-size:26px"><?= $especie_icons[$m['especie']]??'🐾' ?></span><?php endif; ?>
  </div>
  <div class="flex-1">
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
      <span style="font-size:20px;font-weight:800;color:var(--text)"><?= clean($m['nombre']) ?></span>
      <span style="font-size:16px"><?= $m['sexo']==='macho'?'♂️':'♀️' ?></span>
      <span class="badge <?= $m['estado']==='activo'?'b-success':'b-danger' ?>"><?= ucfirst($m['estado']) ?></span>
    </div>
    <div style="font-size:12px;color:var(--text3);margin-top:3px">
      <?= ucfirst($m['especie']) ?><?= $m['raza']?" · ".clean($m['raza']):'' ?> <?= $edad?" · $edad":'' ?> <?= $m['peso']?" · ".clean($m['peso'])." kg":'' ?>
    </div>
  </div>
  <div class="flex gap-2" style="position:relative">
    <a href="<?= BASE_URL ?>/index.php?p=mascotas&action=editar&id=<?= $m['id'] ?>" class="btn btn-ghost btn-sm">✏️ Editar</a>
    <a href="<?= BASE_URL ?>/index.php?p=historial&mascota_id=<?= $m['id'] ?>" class="btn btn-primary btn-sm">🏥 Historia Clínica</a>
    <button class="btn btn-ghost btn-sm" id="btnMenu<?= $m['id'] ?>"
            onclick="toggleMasMenu(<?= $m['id'] ?>, event)" style="padding:8px 12px">⋯</button>
    <!-- Dropdown fuera del flujo del botón -->
    <div id="dropMenu<?= $m['id'] ?>"
         style="display:none;position:absolute;top:100%;right:0;margin-top:4px;background:var(--bg2);border:1px solid var(--border);border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.12);z-index:500;min-width:190px;padding:5px;animation:slideUp .15s ease">
      <a href="<?= BASE_URL ?>/index.php?p=citas&action=nueva" class="mas-dmenu-item">📅 Agendar cita</a>
      <a href="<?= BASE_URL ?>/index.php?p=vacunas&action=nueva&mascota_id=<?= $m['id'] ?>" class="mas-dmenu-item">💉 Registrar vacuna</a>
      <a href="<?= BASE_URL ?>/index.php?p=examenes&action=nuevo&mascota_id=<?= $m['id'] ?>" class="mas-dmenu-item">🔬 Nuevo examen</a>
      <a href="https://wa.me/<?= $tel ?>" target="_blank" class="mas-dmenu-item">💬 WhatsApp dueño</a>
    </div>
  </div>
</div>

<!-- PERFIL GRID -->
<div class="mas-profile">

  <!-- ── IZQUIERDA ── -->
  <div class="mas-left">

    <!-- Foto + datos chip -->
    <div class="mas-photo-card">
      <div class="mas-photo-wrap" style="height:220px">
        <?php if($foto_url): ?>
        <img src="<?= $foto_url ?>" alt="<?= clean($m['nombre']) ?>">
        <?php else: ?>
        <div style="display:flex;flex-direction:column;align-items:center;gap:8px">
          <span class="mas-photo-emoji"><?= $especie_icons[$m['especie']]??'🐾' ?></span>
          <span style="font-size:11px;color:var(--text3)">Sin foto</span>
        </div>
        <?php endif; ?>
        <label style="position:absolute;bottom:10px;right:10px;background:rgba(0,0,0,.55);color:#fff;font-size:10px;font-weight:600;padding:4px 10px;border-radius:6px;cursor:pointer;display:flex;align-items:center;gap:4px">
          📷 Cambiar
          <input type="file" style="display:none" accept="image/*" onchange="uploadFoto(this,<?= $m['id'] ?>)">
        </label>
      </div>
      <div style="padding:14px 16px">
        <div style="font-size:11px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px">Identificación</div>
        <div class="mas-field"><span class="mas-field-label">Chip</span><span class="mas-field-val" style="font-family:monospace"><?= clean($m['chip_numero']??'—') ?></span></div>
        <div class="mas-field"><span class="mas-field-label">Microchip</span><span class="mas-field-val"><?= ($m['microchip']??0)?'<span class="badge b-success">✅ Registrado</span>':'<span style="color:var(--text3);font-size:12px">No</span>' ?></span></div>
        <div class="mas-field"><span class="mas-field-label">Esterilizado</span><span class="mas-field-val"><?= ($m['esterilizado']??0)?'<span class="badge b-info">Sí</span>':'<span style="color:var(--danger);font-size:12px">No</span>' ?></span></div>
        <div class="mas-field"><span class="mas-field-label">Alérgico</span><span class="mas-field-val"><?= $m['alergias']?'<span class="badge b-warning">Sí</span>':'<span style="color:var(--text3);font-size:12px">No</span>' ?></span></div>
        <div class="mas-field"><span class="mas-field-label">Fecha registro</span><span class="mas-field-val"><?= date('d/m/Y',strtotime($m['created_at']??date('Y-m-d'))) ?></span></div>
        <?php $ultima=$db->prepare("SELECT MAX(fecha) FROM consultas WHERE mascota_id=?");$ultima->execute([$m['id']]);$ultima=$ultima->fetchColumn(); ?>
        <div class="mas-field"><span class="mas-field-label">Última visita</span><span class="mas-field-val" style="color:var(--primary)"><?= $ultima?date('d/m/Y',strtotime($ultima)):'—' ?></span></div>
      </div>
    </div>

    <!-- Dueño -->
    <div class="mas-info-card">
      <div class="mas-ic-head">👤 Dueño / Propietario</div>
      <div class="mas-ic-body">
        <div style="font-size:14px;font-weight:700;color:var(--text);margin-bottom:4px"><?= clean($m['dueno']) ?></div>
        <a href="https://wa.me/<?= $tel ?>" target="_blank" style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--wa);text-decoration:none;font-weight:600;margin-bottom:4px">
          💬 <?= clean($m['telefono']??'—') ?>
        </a>
        <?php if($m['email']??''): ?><div style="font-size:12px;color:var(--text3)"><?= clean($m['email']) ?></div><?php endif; ?>
        <?php if($m['dni']??''): ?><div style="font-size:11px;color:var(--text3);margin-top:4px">DNI: <?= clean($m['dni']) ?></div><?php endif; ?>
        <a href="<?= BASE_URL ?>/index.php?p=clientes&action=editar&id=<?= $m['cliente_id'] ?>" class="btn btn-ghost btn-xs" style="margin-top:10px;width:100%;justify-content:center">Ver ficha del cliente →</a>
      </div>
    </div>

  </div><!-- fin left -->

  <!-- ── CENTRO ── -->
  <div class="mas-center">
    <div class="mas-info-card" style="flex:1">
      <!-- Tabs -->
      <div style="padding:14px 16px 0">
        <div class="mas-tabs">
          <button class="mas-tab active" onclick="showMasTab('tab-resumen',this)">📋 Resumen</button>
          <button class="mas-tab" onclick="showMasTab('tab-info',this)">ℹ️ Información</button>
          <button class="mas-tab" onclick="showMasTab('tab-historial',this)">📜 Historial</button>
          <button class="mas-tab" onclick="showMasTab('tab-vacunas',this)">💉 Vacunas</button>
          <button class="mas-tab" onclick="showMasTab('tab-examenes',this)">🔬 Exámenes</button>
          <button class="mas-tab" onclick="showMasTab('tab-notas',this)">📝 Notas</button>
        </div>
      </div>

      <!-- Tab: Resumen -->
      <div id="tab-resumen" class="mas-tab-content active" style="padding:16px">
        <div class="grid g2 mb-3" style="gap:12px">
          <div>
            <div style="font-size:11px;color:var(--text3);font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px">Información general</div>
            <div class="mas-field"><span class="mas-field-label">Especie</span><span class="mas-field-val"><?= ucfirst($m['especie']) ?></span></div>
            <div class="mas-field"><span class="mas-field-label">Raza</span><span class="mas-field-val"><?= clean($m['raza']??'Criollo') ?></span></div>
            <div class="mas-field"><span class="mas-field-label">Color</span><span class="mas-field-val"><?= clean($m['color']??'—') ?></span></div>
            <div class="mas-field"><span class="mas-field-label">Fecha de nacimiento</span><span class="mas-field-val"><?= $m['fecha_nacimiento']?date('d/m/Y',strtotime($m['fecha_nacimiento'])):'—' ?></span></div>
            <div class="mas-field"><span class="mas-field-label">Sexo</span><span class="mas-field-val"><?= $m['sexo']?ucfirst($m['sexo']):'—' ?></span></div>
            <div class="mas-field"><span class="mas-field-label">Peso actual</span>
              <span class="mas-field-val" style="display:flex;align-items:center;gap:6px">
                <?= $m['peso']?clean($m['peso']).' kg':'—' ?>
                <?php if($m['peso']):
                  $peso_f=(float)$m['peso'];
                  $status=$peso_f<3?'Bajo':'Normal';$sc=$peso_f<3?'b-warning':'b-success';
                ?><span class="badge <?= $sc ?>"><?= $status ?></span><?php endif; ?>
              </span>
            </div>
            <div class="mas-field"><span class="mas-field-label">Grupo sanguíneo</span><span class="mas-field-val"><?= clean($m['grupo_sanguineo']??'—') ?></span></div>
          </div>
          <div>
            <div style="font-size:11px;color:var(--text3);font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px">Estado reproductivo</div>
            <div class="mas-field"><span class="mas-field-label">Esterilizado</span><span class="mas-field-val"><?= ($m['esterilizado']??0)?'<span class="badge b-info">Sí</span>':'<span class="badge b-danger">No</span>' ?></span></div>
            <div class="mas-field"><span class="mas-field-label">Alérgico</span><span class="mas-field-val"><?= $m['alergias']?'<span class="badge b-warning">Sí</span>':'<span class="badge b-success">No</span>' ?></span></div>
            <div class="mas-field"><span class="mas-field-label">Microchip</span><span class="mas-field-val"><?= ($m['microchip']??0)?'<span class="badge b-success">Registrado</span>':'<span class="badge b-gray">No</span>' ?></span></div>
            <div class="mas-field"><span class="mas-field-label">Personalidad</span><span class="mas-field-val" style="max-width:120px;text-align:right"><?= clean($m['personalidad']??'—') ?></span></div>
            <div class="mas-field"><span class="mas-field-label">Alimentación</span><span class="mas-field-val" style="max-width:120px;text-align:right"><?= clean($m['alimentacion']??'—') ?></span></div>
          </div>
        </div>
        <!-- Alertas médicas -->
        <?php if($m['alergias']): ?>
        <div class="alert alert-warn mb-2"><span class="alert-icon">⚠️</span><div><strong>Alergias:</strong> <?= clean($m['alergias']) ?></div></div>
        <?php endif; ?>
        <?php if($m['condiciones']): ?>
        <div class="alert alert-info mb-2"><span class="alert-icon">🔵</span><div><strong>Condiciones:</strong> <?= clean($m['condiciones']) ?></div></div>
        <?php endif; ?>
        <!-- Actividad reciente -->
        <div style="font-size:12px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.4px;margin-bottom:12px">Actividad reciente</div>
        <?php if(empty($actividad)): ?>
        <div style="text-align:center;padding:20px;color:var(--text3);font-size:12px">Sin actividad registrada.</div>
        <?php else: ?>
        <?php
        $act_cfg=['consulta'=>['icon'=>'🩺','bg'=>'#dbeafe','label'=>'Consulta'],'vacuna'=>['icon'=>'💉','bg'=>'#d1fae5','label'=>'Vacuna'],'examen'=>['icon'=>'🔬','bg'=>'#ede9fe','label'=>'Examen'],'grooming'=>['icon'=>'✨','bg'=>'#fce7f3','label'=>'Grooming'],'internado'=>['icon'=>'🏥','bg'=>'#fee2e2','label'=>'Internado']];
        foreach($actividad as $act):
          $cfg=$act_cfg[$act['tipo']]??['icon'=>'📋','bg'=>'#f1f5f9','label'=>ucfirst($act['tipo'])];
        ?>
        <div class="act-item">
          <div class="act-icon" style="background:<?= $cfg['bg'] ?>"><?= $cfg['icon'] ?></div>
          <div class="flex-1">
            <div style="font-size:12px;font-weight:600;color:var(--text)"><?= clean(substr($act['desc1'],0,60)) ?></div>
            <div style="font-size:11px;color:var(--text3)"><?= clean($act['vet']) ?></div>
          </div>
          <div style="text-align:right;flex-shrink:0">
            <span class="badge b-gray" style="font-size:10px"><?= $cfg['label'] ?></span>
            <div style="font-size:10px;color:var(--text3);margin-top:3px"><?= date('d/m/Y',strtotime($act['fecha'])) ?></div>
          </div>
        </div>
        <?php endforeach; ?>
        <a href="<?= BASE_URL ?>/index.php?p=historial&mascota_id=<?= $m['id'] ?>" style="display:block;text-align:center;font-size:12px;color:var(--primary);font-weight:600;padding:10px;text-decoration:none;border-top:1px solid var(--border);margin-top:8px">Ver historial completo →</a>
        <?php endif; ?>
      </div>

      <!-- Tab: Información -->
      <div id="tab-info" class="mas-tab-content" style="padding:16px">
        <a href="<?= BASE_URL ?>/index.php?p=mascotas&action=editar&id=<?= $m['id'] ?>" class="btn btn-primary btn-sm mb-3">✏️ Editar información</a>
        <div class="grid g2" style="gap:12px">
          <div>
            <?php foreach(['Nombre'=>clean($m['nombre']),'Especie'=>ucfirst($m['especie']),'Raza'=>clean($m['raza']??'Criollo'),'Sexo'=>ucfirst($m['sexo']??'—'),'Color/Pelaje'=>clean($m['color']??'—'),'Nacimiento'=>($m['fecha_nacimiento']?date('d/m/Y',strtotime($m['fecha_nacimiento'])):'—'),'Peso actual'=>($m['peso']?clean($m['peso']).' kg':'—')] as $k=>$v): ?>
            <div class="mas-field"><span class="mas-field-label"><?= $k ?></span><span class="mas-field-val"><?= $v ?></span></div>
            <?php endforeach; ?>
          </div>
          <div>
            <?php foreach(['Grupo sanguíneo'=>clean($m['grupo_sanguineo']??'—'),'Chip N°'=>clean($m['chip_numero']??'—'),'Microchip'=>(($m['microchip']??0)?'Registrado':'No'),'Esterilizado'=>(($m['esterilizado']??0)?'Sí':'No'),'Personalidad'=>clean($m['personalidad']??'—'),'Alimentación'=>clean($m['alimentacion']??'—'),'Estado'=>ucfirst($m['estado'])] as $k=>$v): ?>
            <div class="mas-field"><span class="mas-field-label"><?= $k ?></span><span class="mas-field-val"><?= $v ?></span></div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php if($m['alergias']): ?><div class="alert alert-warn mt-2"><span class="alert-icon">⚠️</span><div><strong>Alergias:</strong> <?= clean($m['alergias']) ?></div></div><?php endif; ?>
        <?php if($m['condiciones']): ?><div class="alert alert-info mt-2"><span class="alert-icon">🔵</span><div><strong>Condiciones crónicas:</strong> <?= clean($m['condiciones']) ?></div></div><?php endif; ?>
      </div>

      <!-- Tab: Historial -->
      <div id="tab-historial" class="mas-tab-content" style="padding:16px">
        <div class="flex justify-between mb-3">
          <div style="font-size:13px;font-weight:600">Historia Clínica</div>
          <a href="<?= BASE_URL ?>/index.php?p=historial&action=nueva&mascota_id=<?= $m['id'] ?>" class="btn btn-primary btn-xs">+ Nueva consulta</a>
        </div>
        <?php
        $consultas=$db->prepare("SELECT con.*,u.nombre as vet FROM consultas con JOIN usuarios u ON u.id=con.veterinario_id WHERE con.mascota_id=? ORDER BY con.fecha DESC LIMIT 8");
        $consultas->execute([$m['id']]); $consultas=$consultas->fetchAll();
        if(empty($consultas)): ?>
        <div style="text-align:center;padding:32px;color:var(--text3);font-size:12px">Sin consultas registradas.</div>
        <?php else: foreach($consultas as $con): ?>
        <div style="border:1px solid var(--border);border-radius:10px;padding:12px 14px;margin-bottom:10px">
          <div class="flex justify-between mb-1">
            <span class="badge b-primary"><?= ucfirst($con['tipo']) ?></span>
            <span style="font-size:11px;color:var(--text3)"><?= date('d/m/Y H:i',strtotime($con['fecha'])) ?> · <?= clean($con['vet']) ?></span>
          </div>
          <div style="font-size:12px;font-weight:600;color:var(--text);margin-bottom:4px"><?= clean(substr($con['diagnostico'],0,80)) ?></div>
          <?php if($con['tratamiento']): ?><div style="font-size:11px;color:var(--text3)"><?= clean(substr($con['tratamiento'],0,60)) ?></div><?php endif; ?>
        </div>
        <?php endforeach; endif; ?>
        <a href="<?= BASE_URL ?>/index.php?p=historial&mascota_id=<?= $m['id'] ?>" class="btn btn-ghost btn-sm btn-block">Ver historial completo →</a>
      </div>

      <!-- Tab: Vacunas -->
      <div id="tab-vacunas" class="mas-tab-content" style="padding:16px">
        <div class="flex justify-between mb-3">
          <div style="font-size:13px;font-weight:600">Vacunación</div>
          <a href="<?= BASE_URL ?>/index.php?p=vacunas&action=nueva&mascota_id=<?= $m['id'] ?>" class="btn btn-primary btn-xs">+ Registrar</a>
        </div>
        <?php $vacs=$db->prepare("SELECT * FROM vacunas WHERE mascota_id=? ORDER BY fecha_aplicacion DESC");$vacs->execute([$m['id']]);$vacs=$vacs->fetchAll();
        if(empty($vacs)): ?><div style="text-align:center;padding:32px;color:var(--text3);font-size:12px">Sin vacunas registradas.</div>
        <?php else: foreach($vacs as $v):$dias=ceil((strtotime($v['proxima_dosis'])-time())/86400);$vc=$dias<0?'b-danger':($dias<=7?'b-warning':'b-success'); ?>
        <div class="flex items-center gap-10 mb-2" style="gap:10px;padding:10px;border:1px solid var(--border);border-radius:8px">
          <div style="flex:1"><div style="font-size:12px;font-weight:600"><?= clean($v['tipo_vacuna']) ?></div><div style="font-size:11px;color:var(--text3)">Aplicada: <?= date('d/m/Y',strtotime($v['fecha_aplicacion'])) ?></div></div>
          <span class="badge <?= $vc ?>"><?= $dias<0?'Vencida':"Vence: ".date('d/m/Y',strtotime($v['proxima_dosis'])) ?></span>
        </div>
        <?php endforeach; endif; ?>
      </div>

      <!-- Tab: Exámenes -->
      <div id="tab-examenes" class="mas-tab-content" style="padding:16px">
        <div class="flex justify-between mb-3">
          <div style="font-size:13px;font-weight:600">Exámenes Auxiliares</div>
          <a href="<?= BASE_URL ?>/index.php?p=examenes&action=nuevo&mascota_id=<?= $m['id'] ?>" class="btn btn-primary btn-xs">+ Nuevo</a>
        </div>
        <?php try{ $exams=$db->prepare("SELECT * FROM examenes_auxiliares WHERE mascota_id=? ORDER BY fecha DESC LIMIT 8");$exams->execute([$m['id']]);$exams=$exams->fetchAll();
        if(empty($exams)): ?><div style="text-align:center;padding:32px;color:var(--text3);font-size:12px">Sin exámenes registrados.</div>
        <?php else: foreach($exams as $ex): $esc=['pendiente'=>'b-warning','completado'=>'b-success','resultado_parcial'=>'b-info'][$ex['estado']]??'b-gray'; ?>
        <div class="flex items-center gap-10 mb-2" style="gap:10px;padding:10px;border:1px solid var(--border);border-radius:8px">
          <div style="flex:1"><div style="font-size:12px;font-weight:600"><?= clean($ex['nombre']) ?></div><div style="font-size:11px;color:var(--text3)"><?= date('d/m/Y',strtotime($ex['fecha'])) ?> · <?= clean($ex['tipo']) ?></div></div>
          <span class="badge <?= $esc ?>"><?= ucfirst(str_replace('_',' ',$ex['estado'])) ?></span>
        </div>
        <?php endforeach;endif;}catch(Exception $e){echo '<div style="text-align:center;padding:24px;color:var(--text3);font-size:12px">Módulo de exámenes no disponible.</div>';} ?>
      </div>

      <!-- Tab: Notas -->
      <div id="tab-notas" class="mas-tab-content" style="padding:16px">
        <?php if($m['observaciones']??''): ?>
        <div class="nota-card mb-3"><?= nl2br(clean($m['observaciones'])) ?></div>
        <?php else: ?>
        <div style="text-align:center;padding:24px;color:var(--text3);font-size:12px">Sin notas registradas.</div>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/index.php?p=mascotas&action=editar&id=<?= $m['id'] ?>" class="btn btn-ghost btn-xs">✏️ Editar nota</a>
      </div>

    </div>
  </div>

  <!-- ── DERECHA ── -->
  <div class="mas-right">

    <!-- Resumen médico -->
    <div class="mas-info-card">
      <div class="mas-ic-head">🏥 Resumen médico</div>
      <div class="resumen-med-stat"><span style="font-size:12px;color:var(--text3)">Total consultas</span><span style="font-size:13px;font-weight:700;color:var(--text)"><?= $n_consultas ?></span></div>
      <div class="resumen-med-stat"><span style="font-size:12px;color:var(--text3)">Total vacunas</span><span style="font-size:13px;font-weight:700;color:var(--text)"><?= $n_vacunas ?></span></div>
      <div class="resumen-med-stat"><span style="font-size:12px;color:var(--text3)">Total exámenes</span><span style="font-size:13px;font-weight:700;color:var(--text)"><?= $n_examenes ?></span></div>
      <div class="resumen-med-stat"><span style="font-size:12px;color:var(--text3)">Total hospitalizaciones</span>
        <?php try{$nh=$db->prepare("SELECT COUNT(*) FROM hospitalizacion WHERE mascota_id=?");$nh->execute([$m['id']]);echo '<span style="font-size:13px;font-weight:700;color:var(--text)">'.(int)$nh->fetchColumn().'</span>';}catch(Exception $e){echo '<span style="font-size:13px;font-weight:700;color:var(--text)">0</span>';} ?>
      </div>
      <div style="padding:10px 16px">
        <a href="<?= BASE_URL ?>/index.php?p=historial&mascota_id=<?= $m['id'] ?>" class="btn btn-ghost btn-xs btn-block">Ver historial completo →</a>
      </div>
    </div>

    <!-- Próximas acciones -->
    <?php if($prox_cita||$prox_vac): ?>
    <div class="mas-info-card">
      <div class="mas-ic-head">⏰ Próximas acciones</div>
      <div style="padding:12px">
        <?php if($prox_cita): $d_cita=ceil((strtotime($prox_cita['fecha'])-time())/86400); ?>
        <div class="prox-item">
          <span style="font-size:20px">📅</span>
          <div class="flex-1">
            <div style="font-size:12px;font-weight:600"><?= ucfirst($prox_cita['tipo']) ?></div>
            <div style="font-size:11px;color:var(--text3)"><?= date('d/m/Y',strtotime($prox_cita['fecha'])) ?></div>
          </div>
          <span class="prox-dias" style="background:<?= $d_cita<=1?'#fee2e2':'#dbeafe' ?>;color:<?= $d_cita<=1?'#7f1d1d':'#1e3a8a' ?>">
            <?= $d_cita<=0?'Hoy':($d_cita==1?'Mañana':"$d_cita días") ?>
          </span>
        </div>
        <?php endif; ?>
        <?php if($prox_vac): $d_vac=ceil((strtotime($prox_vac['proxima_dosis'])-time())/86400); ?>
        <div class="prox-item">
          <span style="font-size:20px">💉</span>
          <div class="flex-1">
            <div style="font-size:12px;font-weight:600"><?= clean($prox_vac['tipo_vacuna']) ?></div>
            <div style="font-size:11px;color:var(--text3)"><?= date('d/m/Y',strtotime($prox_vac['proxima_dosis'])) ?></div>
          </div>
          <span class="prox-dias" style="background:<?= $d_vac<=7?'#fef3c7':'#d1fae5' ?>;color:<?= $d_vac<=7?'#78350f':'#065f46' ?>">
            <?= $d_vac<=0?'Vencida':"$d_vac días" ?>
          </span>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Documentos recientes -->
    <?php if(!empty($docs)): ?>
    <div class="mas-info-card">
      <div class="mas-ic-head">📄 Últimos documentos</div>
      <div style="padding:10px 14px">
        <?php foreach($docs as $doc):
          $is_img=strpos($doc['mime_type']??'','image')===0;
          $url_doc=BASE_URL.'/public/uploads/'.$doc['ruta'];
        ?>
        <a href="<?= $url_doc ?>" target="_blank" style="display:flex;align-items:center;gap:8px;padding:8px 0;border-bottom:1px solid var(--border);text-decoration:none;">
          <span style="font-size:18px"><?= $is_img?'🖼️':($doc['tipo']==='analisis'?'🧪':'📄') ?></span>
          <div class="flex-1" style="min-width:0">
            <div style="font-size:12px;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= clean($doc['nombre']) ?></div>
            <div style="font-size:10px;color:var(--text3)"><?= date('d/m/Y',strtotime($doc['created_at'])) ?></div>
          </div>
        </a>
        <?php endforeach; ?>
        <a href="<?= BASE_URL ?>/index.php?p=examenes&mascota_id=<?= $m['id'] ?>" style="display:block;font-size:11px;color:var(--primary);font-weight:600;text-align:center;padding:8px;text-decoration:none;">Ver todos los documentos →</a>
      </div>
    </div>
    <?php endif; ?>

  </div><!-- fin right -->

</div><!-- fin profile grid -->

<script>
function showMasTab(id, btn) {
    document.querySelectorAll('.mas-tab-content').forEach(t=>t.classList.remove('active'));
    document.querySelectorAll('.mas-tab').forEach(b=>b.classList.remove('active'));
    document.getElementById(id)?.classList.add('active');
    btn?.classList.add('active');
}

// Dropdown ⋯ menu
let _masOpenMenu = null;
function toggleMasMenu(id, e) {
    e.stopPropagation();
    const menu = document.getElementById('dropMenu' + id);
    if (!menu) return;
    const isOpen = menu.style.display === 'block';
    // Cerrar cualquier otro abierto
    if (_masOpenMenu && _masOpenMenu !== menu) _masOpenMenu.style.display = 'none';
    menu.style.display = isOpen ? 'none' : 'block';
    _masOpenMenu = isOpen ? null : menu;
}
document.addEventListener('click', () => {
    if (_masOpenMenu) { _masOpenMenu.style.display = 'none'; _masOpenMenu = null; }
});

async function uploadFoto(input, id) {
    if (!input.files[0]) return;
    const fd = new FormData();
    fd.append('action','save'); fd.append('id',id);
    fd.append('foto',input.files[0]);
    fd.append('mascota_id',id);
    const r = await fetch(window.location.href, {method:'POST', body:fd});
    location.reload();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
<?php
    return; // evitar que caiga al list_view
}

// ═══════════════════════════════════════
// FORM: nuevo / editar
// ═══════════════════════════════════════
if (in_array($action,['nuevo','editar'])) {
    $editing=null;
    if ($action==='editar' && isset($_GET['id'])) {
        $st=$db->prepare("SELECT * FROM mascotas WHERE id=?"); $st->execute([(int)$_GET['id']]); $editing=$st->fetch();
    }
    ?>
<div class="card" style="max-width:700px">
  <div class="sec-header">
    <div><div class="sec-title"><?= $action==='editar'?'Editar':'Nueva'?> Mascota</div></div>
    <a href="?p=mascotas" class="btn btn-ghost btn-sm">← Volver</a>
  </div>
  <form method="POST" enctype="multipart/form-data">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= $editing['id']??'' ?>">

    <!-- Foto -->
    <div style="display:flex;align-items:flex-start;gap:20px;margin-bottom:20px">
      <div>
        <div id="foto-preview" style="width:120px;height:120px;border-radius:14px;overflow:hidden;border:2px solid var(--border);background:var(--bg3);display:flex;align-items:center;justify-content:center;font-size:36px;cursor:pointer;position:relative;flex-shrink:0" onclick="document.getElementById('inp-foto').click()">
          <?php if(!empty($editing['foto'])&&file_exists(UPLOADS_PATH.'/'.$editing['foto'])): ?><img src="<?= BASE_URL.'/public/uploads/'.$editing['foto'] ?>" id="foto-img" style="width:100%;height:100%;object-fit:cover"><?php else: ?><span id="foto-emoji"><?= $especie_icons[$editing['especie']??'perro']??'🐾' ?></span><?php endif; ?>
          <div style="position:absolute;bottom:0;left:0;right:0;background:rgba(0,0,0,.45);color:#fff;font-size:10px;text-align:center;padding:4px">📷 Foto</div>
        </div>
        <input type="file" id="inp-foto" name="foto" accept="image/*" style="display:none" onchange="previewFoto(this)">
        <div class="text-xs text-muted text-center mt-1">JPG/PNG · Máx 5MB</div>
      </div>
      <div class="flex-1">
        <div class="form-row">
          <div class="form-group"><label class="form-label required">Dueño</label>
            <select class="form-input" name="cliente_id" required>
              <option value="">— Seleccionar —</option>
              <?php foreach($clientes_sel as $c): ?><option value="<?= $c['id'] ?>" <?= ($editing['cliente_id']??$_GET['cliente_id']??'')==$c['id']?'selected':'' ?>><?= clean($c['nombre']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label class="form-label required">Nombre</label>
            <input class="form-input" name="nombre" value="<?= clean($editing['nombre']??'') ?>" required>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group"><label class="form-label required">Especie</label>
            <select class="form-input" name="especie" required onchange="document.getElementById('foto-emoji').textContent={'perro':'🐕','gato':'🐈','conejo':'🐰','ave':'🐦','reptil':'🦎','roedor':'🐭','otro':'🐾'}[this.value]||'🐾'">
              <?php foreach($especie_labels as $k=>$v): ?><option value="<?= $k ?>" <?= ($editing['especie']??'perro')===$k?'selected':'' ?>><?= $v ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label class="form-label">Raza</label>
            <input class="form-input" name="raza" value="<?= clean($editing['raza']??'') ?>" placeholder="Criollo">
          </div>
        </div>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group"><label class="form-label">Sexo</label>
        <select class="form-input" name="sexo"><option value="macho" <?= ($editing['sexo']??'')==='macho'?'selected':'' ?>>Macho ♂️</option><option value="hembra" <?= ($editing['sexo']??'')==='hembra'?'selected':'' ?>>Hembra ♀️</option></select>
      </div>
      <div class="form-group"><label class="form-label">Fecha de nacimiento</label>
        <input class="form-input" type="date" name="fecha_nacimiento" value="<?= clean($editing['fecha_nacimiento']??'') ?>">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Peso (kg)</label>
        <input class="form-input" type="number" step="0.1" name="peso" value="<?= clean($editing['peso']??'') ?>">
      </div>
      <div class="form-group"><label class="form-label">Color / Pelaje</label>
        <input class="form-input" name="color" value="<?= clean($editing['color']??'') ?>">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">N° de chip</label>
        <input class="form-input" name="chip_numero" value="<?= clean($editing['chip_numero']??'') ?>">
      </div>
      <div class="form-group"><label class="form-label">Grupo sanguíneo</label>
        <input class="form-input" name="grupo_sanguineo" value="<?= clean($editing['grupo_sanguineo']??'') ?>" placeholder="Ej: A, B, AB, DEA 1.1">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Personalidad</label>
        <input class="form-input" name="personalidad" value="<?= clean($editing['personalidad']??'') ?>" placeholder="Ej: Juguetón, tranquilo, agresivo...">
      </div>
      <div class="form-group"><label class="form-label">Alimentación</label>
        <input class="form-input" name="alimentacion" value="<?= clean($editing['alimentacion']??'') ?>" placeholder="Ej: Croquetas premium, 2 veces al día">
      </div>
    </div>
    <!-- Checkboxes -->
    <div class="flex gap-3 mb-3" style="flex-wrap:wrap">
      <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;background:var(--bg3);padding:10px 14px;border-radius:var(--r-sm);border:1px solid var(--border)">
        <input type="checkbox" name="esterilizado" value="1" style="accent-color:var(--primary)" <?= ($editing['esterilizado']??0)?'checked':'' ?>>
        ✂️ Esterilizado
      </label>
      <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;background:var(--bg3);padding:10px 14px;border-radius:var(--r-sm);border:1px solid var(--border)">
        <input type="checkbox" name="microchip" value="1" style="accent-color:var(--primary)" <?= ($editing['microchip']??0)?'checked':'' ?>>
        📡 Microchip registrado
      </label>
    </div>
    <div class="form-group"><label class="form-label">Alergias</label>
      <textarea class="form-input" name="alergias" style="min-height:55px"><?= clean($editing['alergias']??'') ?></textarea>
    </div>
    <div class="form-group"><label class="form-label">Condiciones / Enfermedades crónicas</label>
      <textarea class="form-input" name="condiciones" style="min-height:55px"><?= clean($editing['condiciones']??'') ?></textarea>
    </div>
    <div class="form-group"><label class="form-label">Observaciones / Notas</label>
      <textarea class="form-input" name="observaciones" style="min-height:65px"><?= clean($editing['observaciones']??'') ?></textarea>
    </div>
    <div class="form-group"><label class="form-label">Estado</label>
      <select class="form-input" name="estado">
        <option value="activo" <?= ($editing['estado']??'activo')==='activo'?'selected':'' ?>>Activo</option>
        <option value="fallecido" <?= ($editing['estado']??'')==='fallecido'?'selected':'' ?>>Fallecido</option>
        <option value="dado_en_adopcion" <?= ($editing['estado']??'')==='dado_en_adopcion'?'selected':'' ?>>Dado en adopción</option>
      </select>
    </div>
    <div class="flex gap-2"><button type="submit" class="btn btn-primary">💾 Guardar mascota</button><a href="?p=mascotas" class="btn btn-ghost">Cancelar</a></div>
  </form>
</div>
<script>
function previewFoto(input){const f=input.files[0];if(!f)return;const r=new FileReader();r.onload=e=>{const box=document.getElementById('foto-preview');let img=document.getElementById('foto-img');if(!img){img=document.createElement('img');img.id='foto-img';img.style.cssText='width:100%;height:100%;object-fit:cover';const em=document.getElementById('foto-emoji');if(em)em.style.display='none';box.insertBefore(img,box.firstChild);}img.src=e.target.result;};r.readAsDataURL(f);}
</script>
<?php
    require_once __DIR__ . '/../includes/footer.php';
    return;
}

// ═══════════════════════════════════════
// LIST VIEW
// ═══════════════════════════════════════
list_view:
if ($msg==='success'): ?>
<div class="alert alert-success"><span class="alert-icon">✅</span>Mascota guardada correctamente.</div>
<?php endif;

$search=trim($_GET['q']??'');
$esp_f=$_GET['especie']??'';
$cli_f=(int)($_GET['cliente_id']??0);
$where="m.estado='activo'"; $params=[];
if($search){$where.=" AND (m.nombre LIKE ? OR c.nombre LIKE ? OR m.raza LIKE ?)";$like="%$search%";$params=[$like,$like,$like];}
if($esp_f){$where.=" AND m.especie=?";$params[]=$esp_f;}
if($cli_f){$where.=" AND m.cliente_id=?";$params[]=$cli_f;}
// Filtro de sede
try {
    $_r=$db->query("SHOW COLUMNS FROM `mascotas` LIKE 'sede_id'")->fetchAll();
    if(!empty($_r)) {
        if(!verTodasSedes()) { $where.=" AND m.sede_id=".getSede(); }
    }
} catch(Exception $e) {}

$st=$db->prepare("SELECT m.*,c.nombre as dueno,c.telefono,
    (SELECT COUNT(*) FROM examenes_auxiliares WHERE mascota_id=m.id) as n_examenes,
    (SELECT MAX(fecha) FROM consultas WHERE mascota_id=m.id) as ultima_visita
    FROM mascotas m JOIN clientes c ON c.id=m.cliente_id WHERE $where ORDER BY m.id DESC");
$st->execute($params); $mascotas=$st->fetchAll();
?>
<div class="page">

<div class="sec-header">
  <div>
    <div class="page-title">🐾 Mascotas</div>
    <div class="page-desc"><?= count($mascotas) ?> pacientes activos</div>
  </div>
  <div class="flex gap-2 items-center">
    <!-- Buscador con autocomplete -->
    <div style="position:relative" id="masSearchWrap">
      <div style="display:flex;align-items:center;background:var(--bg2);border:1.5px solid var(--border);border-radius:var(--r-full);padding:0 14px;gap:8px;transition:border-color .2s;width:280px"
           id="masSearchBox">
        <span style="color:var(--text3);font-size:14px;flex-shrink:0">🔍</span>
        <input id="masSearchInput" type="text"
               value="<?= clean($search) ?>"
               placeholder="Buscar nombre, raza, dueño..."
               autocomplete="off"
               style="border:none;background:transparent;outline:none;font-size:13px;color:var(--text);width:100%;padding:9px 0;font-family:var(--font)"
               oninput="masAC(this.value)"
               onkeydown="masKD(event)"
               onfocus="this.parentElement.parentElement.style.borderColor='var(--primary)';if(this.value.length>=2)masAC(this.value)"
               onblur="setTimeout(()=>{this.parentElement.parentElement.style.borderColor='var(--border)'},200)">
        <button onclick="masSubmit()" style="background:none;border:none;cursor:pointer;color:var(--text3);font-size:12px;flex-shrink:0;padding:0" title="Buscar">↵</button>
      </div>
      <!-- Dropdown -->
      <div id="masDropdown" style="
        display:none;position:absolute;top:calc(100% + 6px);left:0;right:0;
        background:var(--bg2);border:1px solid var(--border);border-radius:12px;
        box-shadow:0 8px 32px rgba(0,0,0,.12);z-index:500;max-height:320px;
        overflow-y:auto;min-width:280px;
      "></div>
    </div>
    <select class="form-input" name="especie" id="masEspecieSel" style="width:130px" onchange="masFilterEspecie(this.value)">
      <option value="">Todas</option>
      <?php foreach($especie_labels as $k=>$v): ?>
      <option value="<?= $k ?>" <?= $esp_f===$k?'selected':'' ?>><?= $v ?></option>
      <?php endforeach; ?>
    </select>
    <a href="?p=mascotas&action=nuevo" class="btn btn-primary">＋ Nueva Mascota</a>
  </div>
</div>

<script>
const _masApi  = '<?= BASE_URL ?>/api/autocomplete.php';
const _masBase = '<?= BASE_URL ?>';
const _masEi   = {perro:'🐕',gato:'🐈',conejo:'🐰',ave:'🐦',reptil:'🦎',roedor:'🐭',otro:'🐾'};
let _masTimer  = null;
let _masFocus  = -1;

function masAC(val) {
  clearTimeout(_masTimer);
  const drop = document.getElementById('masDropdown');
  if (!val || val.trim().length < 2) { drop.style.display='none'; _masFocus=-1; return; }
  _masTimer = setTimeout(async () => {
    try {
      const r = await fetch(_masApi + '?q=' + encodeURIComponent(val.trim()));
      const d = await r.json();
      masRender(d, val.trim());
    } catch(e) {}
  }, 220);
}

function masRender(d, val) {
  const drop = document.getElementById('masDropdown');
  const hl = txt => {
    if (!txt) return '';
    const re = new RegExp('('+val.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')+')', 'gi');
    return String(txt).replace(re,'<strong style="color:var(--primary)">$1</strong>');
  };
  let html = '';
  (d.mascotas||[]).forEach(m => {
    const foto = m.foto_url
      ? `<img src="${m.foto_url}" style="width:38px;height:38px;border-radius:9px;object-fit:cover;border:1px solid var(--border);flex-shrink:0">`
      : `<div style="width:38px;height:38px;border-radius:9px;background:var(--primary-l);display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0">${_masEi[m.especie]||'🐾'}</div>`;
    html += `<div class="mas-ac-item" onclick="window.location.href='${_masBase}/index.php?p=mascotas&action=ver&id=${m.id}'">
      ${foto}
      <div style="flex:1;min-width:0">
        <div style="font-size:13px;font-weight:700;color:var(--text)">${hl(m.nombre)}</div>
        <div style="font-size:11px;color:var(--text3)">${hl(m.raza||ucFirst(m.especie))} · ${hl(m.dueno)}</div>
      </div>
      <div style="display:flex;flex-direction:column;gap:3px;align-items:flex-end;flex-shrink:0">
        <a href="${_masBase}/index.php?p=mascotas&action=ver&id=${m.id}" onclick="event.stopPropagation()"
           style="padding:2px 8px;background:var(--primary-l);color:var(--primary-d);border-radius:6px;font-size:10px;font-weight:700;text-decoration:none">Ver ficha</a>
        <a href="${_masBase}/index.php?p=historial&mascota_id=${m.id}" onclick="event.stopPropagation()"
           style="padding:2px 8px;background:var(--accent-l);color:var(--accent-d);border-radius:6px;font-size:10px;font-weight:700;text-decoration:none">Historia</a>
      </div>
    </div>`;
  });
  (d.clientes||[]).slice(0,3).forEach(c => {
    html += `<div class="mas-ac-item" onclick="window.location.href='${_masBase}/index.php?p=mascotas&cliente_id=${c.id}'" style="background:var(--bg3)">
      <div style="width:38px;height:38px;border-radius:9px;background:var(--accent-l);display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:700;color:var(--accent-d);flex-shrink:0">${(c.nombre||'?').charAt(0).toUpperCase()}</div>
      <div style="flex:1"><div style="font-size:12px;font-weight:600;color:var(--text)">👤 ${hl(c.nombre)}</div><div style="font-size:11px;color:var(--text3)">${hl(c.telefono||'')}</div></div>
      <span style="padding:2px 8px;background:var(--accent-l);color:var(--accent-d);border-radius:6px;font-size:10px;font-weight:700">Mascotas</span>
    </div>`;
  });
  if (!html) {
    html = `<div style="padding:16px;text-align:center;color:var(--text3);font-size:12px">Sin resultados para "<strong>${val}</strong>"</div>`;
  }
  drop.innerHTML = html;
  drop.style.display = 'block';
  _masFocus = -1;
  drop.querySelectorAll('.mas-ac-item').forEach(el => {
    el.style.cssText += 'display:flex;align-items:center;gap:10px;padding:9px 12px;cursor:pointer;border-bottom:1px solid var(--border);transition:background .1s';
    el.addEventListener('mouseenter',()=>el.style.background='var(--bg3)');
    el.addEventListener('mouseleave',()=>el.style.background='');
  });
}

function ucFirst(s){ return s?(s.charAt(0).toUpperCase()+s.slice(1)):''; }

function masKD(e) {
  const drop = document.getElementById('masDropdown');
  const items = drop.querySelectorAll('.mas-ac-item');
  if (drop.style.display==='none'||!items.length) {
    if (e.key==='Enter') masSubmit();
    return;
  }
  if (e.key==='ArrowDown') { e.preventDefault(); _masFocus=Math.min(_masFocus+1,items.length-1); items.forEach((it,i)=>it.style.background=i===_masFocus?'var(--primary-l)':''); }
  else if (e.key==='ArrowUp') { e.preventDefault(); _masFocus=Math.max(_masFocus-1,0); items.forEach((it,i)=>it.style.background=i===_masFocus?'var(--primary-l)':''); }
  else if (e.key==='Enter') { e.preventDefault(); if(_masFocus>=0&&items[_masFocus])items[_masFocus].click();else masSubmit(); }
  else if (e.key==='Escape') drop.style.display='none';
}

function masSubmit() {
  const q = document.getElementById('masSearchInput')?.value.trim();
  const esp = document.getElementById('masEspecieSel')?.value||'';
  window.location.href='<?= BASE_URL ?>/index.php?p=mascotas&q='+encodeURIComponent(q)+(esp?'&especie='+esp:'');
}

function masFilterEspecie(val) {
  const q = document.getElementById('masSearchInput')?.value.trim()||'';
  window.location.href='<?= BASE_URL ?>/index.php?p=mascotas'+(q?'&q='+encodeURIComponent(q):'')+(val?'&especie='+val:'');
}

document.addEventListener('click', e => {
  const wrap = document.getElementById('masSearchWrap');
  if (wrap && !wrap.contains(e.target))
    document.getElementById('masDropdown').style.display='none';
});
</script>

<style>
/* Mascotas grid responsive */
.mas-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; }
@media(max-width:1024px){ .mas-grid { grid-template-columns:repeat(3,1fr); } }
@media(max-width:768px){
  .mas-grid { grid-template-columns:1fr; gap:10px; }
  .mas-card { display:flex !important; flex-direction:row !important; align-items:stretch !important; }
  .mas-card-foto { width:90px !important; min-width:90px !important; height:auto !important; flex-shrink:0 !important; }
  .mas-card-foto img { height:100% !important; }
  .mas-card-foto .mas-emoji { height:90px !important; }
  .mas-card-body { flex:1; padding:12px !important; }
  .mas-card-nombre { font-size:14px !important; }
  .mas-card-botones a:first-child { font-size:11px !important; padding:5px 8px !important; }
}
</style>

<div class="mas-grid">
  <?php foreach($mascotas as $m):
    $foto_url=!empty($m['foto'])&&file_exists(UPLOADS_PATH.'/'.$m['foto'])?BASE_URL.'/public/uploads/'.$m['foto']:null;
    $edad='';if($m['fecha_nacimiento']){$diff=(new DateTime())->diff(new DateTime($m['fecha_nacimiento']));$edad=$diff->y>0?$diff->y.'a ':''.$diff->m.'m';$edad=trim($edad);}
    $tel2=preg_replace('/[^0-9]/','',ltrim($m['telefono'],'+'));if(strlen($tel2)<11)$tel2='51'.$tel2;
    $especie_color=['perro'=>'#10b981','gato'=>'#6366f1','conejo'=>'#f59e0b','ave'=>'#3b82f6','reptil'=>'#84cc16','roedor'=>'#f97316','otro'=>'#8b5cf6'];
    $ec=$especie_color[$m['especie']]??'#10b981';
  ?>
  <div class="mas-card" style="background:var(--bg2);border:1px solid var(--border);border-radius:16px;overflow:hidden;transition:all .18s;cursor:pointer;display:flex;flex-direction:column"
       onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 6px 24px rgba(0,0,0,.09)'"
       onmouseout="this.style.transform='';this.style.boxShadow=''">
    <!-- Foto -->
    <a href="?p=mascotas&action=ver&id=<?= $m['id'] ?>" style="display:block;position:relative;text-decoration:none" class="mas-card-foto">
      <div style="width:100%;height:130px;position:relative;background:var(--bg3);overflow:hidden">
        <?php if($foto_url): ?>
        <img src="<?= $foto_url ?>" style="width:100%;height:100%;object-fit:cover;object-position:center 20%" alt="<?= clean($m['nombre']) ?>">
        <?php else: ?>
        <div class="mas-emoji" style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#f0fdf4,#e0f2fe)">
          <span style="font-size:52px;filter:drop-shadow(0 2px 8px rgba(0,0,0,.08))"><?= $especie_icons[$m['especie']]??'🐾' ?></span>
        </div>
        <?php endif; ?>
        <div style="position:absolute;top:8px;right:8px;background:<?= $ec ?>;color:#fff;font-size:10px;font-weight:700;padding:3px 9px;border-radius:999px"><?= ucfirst($m['especie']) ?></div>
        <?php if($edad): ?>
        <div style="position:absolute;top:8px;left:8px;background:rgba(0,0,0,.5);color:#fff;font-size:10px;font-weight:700;padding:3px 8px;border-radius:999px"><?= $edad ?></div>
        <?php endif; ?>
      </div>
    </a>
    <!-- Info -->
    <div class="mas-card-body" style="padding:13px 15px;flex:1;display:flex;flex-direction:column">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:2px">
        <a href="?p=mascotas&action=ver&id=<?= $m['id'] ?>" class="mas-card-nombre" style="font-size:15px;font-weight:700;color:var(--text);text-decoration:none"><?= clean($m['nombre']) ?></a>
        <span style="font-size:15px;color:<?= $m['sexo']==='macho'?'#3b82f6':'#ec4899' ?>"><?= $m['sexo']==='macho'?'♂':'♀' ?></span>
      </div>
      <div style="font-size:12px;color:var(--text3);margin-bottom:6px"><?= clean($m['raza']??ucfirst($m['especie'])) ?></div>
      <div style="font-size:12px;color:var(--text2);margin-bottom:<?= $m['ultima_visita']?'3px':'8px' ?>">👤 <?= clean($m['dueno']) ?></div>
      <?php if($m['ultima_visita']): ?>
      <div style="font-size:11px;color:var(--text3);margin-bottom:8px">Última visita: <strong><?= date('d/m/Y',strtotime($m['ultima_visita'])) ?></strong></div>
      <?php endif; ?>
      <!-- Botones -->
      <div class="mas-card-botones" style="display:flex;gap:5px;margin-top:auto">
        <a href="?p=mascotas&action=ver&id=<?= $m['id'] ?>"
           style="flex:1;display:flex;align-items:center;justify-content:center;padding:7px 8px;border:1.5px solid var(--border);border-radius:8px;font-size:12px;font-weight:600;color:var(--text2);text-decoration:none;background:var(--bg3);transition:all .15s"
           onmouseover="this.style.borderColor='var(--primary)';this.style.color='var(--primary)';this.style.background='var(--primary-l)'"
           onmouseout="this.style.borderColor='var(--border)';this.style.color='var(--text2)';this.style.background='var(--bg3)'">Ver ficha</a>
        <a href="https://wa.me/<?= $tel2 ?>" target="_blank"
           style="width:33px;height:33px;display:flex;align-items:center;justify-content:center;border:1.5px solid var(--border);border-radius:8px;font-size:15px;text-decoration:none;background:var(--bg3);transition:all .15s"
           onmouseover="this.style.borderColor='#25d366';this.style.background='#dcfce7'"
           onmouseout="this.style.borderColor='var(--border)';this.style.background='var(--bg3)'" title="WhatsApp">💬</a>
        <a href="?p=citas&action=nueva"
           style="width:33px;height:33px;display:flex;align-items:center;justify-content:center;border:1.5px solid var(--border);border-radius:8px;font-size:15px;text-decoration:none;background:var(--bg3);transition:all .15s"
           onmouseover="this.style.borderColor='var(--primary)';this.style.background='var(--primary-l)'"
           onmouseout="this.style.borderColor='var(--border)';this.style.background='var(--bg3)'" title="Agendar cita">📅</a>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if(empty($mascotas)): ?>
  <div class="card text-center" style="grid-column:1/-1;padding:60px">
    <div style="font-size:48px;margin-bottom:14px;opacity:.3">🐾</div>
    <div style="font-size:15px;font-weight:600;margin-bottom:8px">No se encontraron mascotas</div>
    <a href="?p=mascotas&action=nuevo" class="btn btn-primary">Nueva mascota</a>
  </div>
  <?php endif; ?>
</div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
