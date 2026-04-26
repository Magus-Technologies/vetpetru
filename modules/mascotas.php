<?php
$page = 'mascotas'; $pageTitle = 'Mascotas';
require_once __DIR__ . '/../includes/header.php';
$db = getDB();

$action = $_GET['action'] ?? 'list';
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $pa = $_POST['action'];

    if ($pa === 'save') {
        $id = (int)($_POST['id']??0);
        $fields = ['cliente_id','nombre','especie','raza','sexo','fecha_nacimiento','peso','color','chip_numero','alergias','condiciones','estado'];
        $data=[]; foreach($fields as $f) $data[$f] = trim($_POST[$f]??'') ?: null;

        // Manejar foto de perfil
        $foto_nueva = null;
        if (!empty($_FILES['foto']['tmp_name']) && $_FILES['foto']['error']===UPLOAD_ERR_OK) {
            $mime = mime_content_type($_FILES['foto']['tmp_name']);
            if (in_array($mime,['image/jpeg','image/png','image/webp','image/gif']) && $_FILES['foto']['size'] <= 5*1024*1024) {
                $dir = UPLOADS_PATH . '/mascotas/';
                if (!is_dir($dir)) mkdir($dir,0755,true);
                // Eliminar foto anterior si existe
                if ($id) {
                    $old=$db->prepare("SELECT foto FROM mascotas WHERE id=?"); $old->execute([$id]);
                    $oldrow=$old->fetch();
                    if ($oldrow && $oldrow['foto'] && file_exists(UPLOADS_PATH.'/'.$oldrow['foto'])) unlink(UPLOADS_PATH.'/'.$oldrow['foto']);
                }
                $ext = $mime==='image/png'?'png':($mime==='image/webp'?'webp':'jpg');
                $fname = 'mascota_'.($id?:time()).'_'.uniqid().'.'.$ext;
                // Redimensionar si es posible
                if (function_exists('imagecreatefromstring')) {
                    $src = imagecreatefromstring(file_get_contents($_FILES['foto']['tmp_name']));
                    if ($src) {
                        $w=imagesx($src); $h=imagesy($src);
                        $max=400; $nw=$w; $nh=$h;
                        if($w>$max||$h>$max){$r=$w>$h?$max/$w:$max/$h; $nw=round($w*$r); $nh=round($h*$r);}
                        $dst=imagecreatetruecolor($nw,$nh);
                        imagecopyresampled($dst,$src,0,0,0,0,$nw,$nh,$w,$h);
                        imagejpeg($dst,$dir.$fname,85);
                        imagedestroy($src); imagedestroy($dst);
                        $foto_nueva = 'mascotas/'.$fname;
                    }
                }
                if (!$foto_nueva && move_uploaded_file($_FILES['foto']['tmp_name'],$dir.$fname)) {
                    $foto_nueva = 'mascotas/'.$fname;
                }
            }
        }

        if ($id) {
            $sets = implode(',', array_map(fn($f)=>"$f=:$f", $fields));
            if ($foto_nueva) { $sets .= ",foto=:foto"; $data['foto']=$foto_nueva; }
            $st = $db->prepare("UPDATE mascotas SET $sets WHERE id=:id"); $data['id']=$id;
        } else {
            $flds = $foto_nueva ? array_merge($fields,['foto']) : $fields;
            if ($foto_nueva) $data['foto']=$foto_nueva;
            $cols = implode(',', $flds); $pls = implode(',', array_map(fn($f)=>":$f", $flds));
            $st = $db->prepare("INSERT INTO mascotas ($cols) VALUES ($pls)");
        }
        $st->execute($data); $msg='success'; $action='list';
    }

    if ($pa === 'delete_foto') {
        $id=(int)$_POST['id'];
        $st=$db->prepare("SELECT foto FROM mascotas WHERE id=?"); $st->execute([$id]); $row=$st->fetch();
        if($row&&$row['foto']){$ruta=UPLOADS_PATH.'/'.$row['foto']; if(file_exists($ruta))unlink($ruta);}
        $db->prepare("UPDATE mascotas SET foto=NULL WHERE id=?")->execute([$id]);
        jsonResponse(['ok'=>true]);
    }
}

if ($action === 'delete' && isset($_GET['id'])) {
    $db->prepare("UPDATE mascotas SET estado='dado_en_adopcion' WHERE id=?")->execute([(int)$_GET['id']]); $action='list';
}

$editing=null;
if (in_array($action,['editar']) && isset($_GET['id'])) {
    $st=$db->prepare("SELECT * FROM mascotas WHERE id=?"); $st->execute([(int)$_GET['id']]); $editing=$st->fetch();
}

$clientes_sel = $db->query("SELECT id,nombre,telefono FROM clientes WHERE activo=1 ORDER BY nombre")->fetchAll();

$search     = trim($_GET['q'] ?? '');
$esp_filtro = $_GET['especie'] ?? '';
$cli_filtro = (int)($_GET['cliente_id'] ?? 0);
$where = "m.estado='activo'"; $params=[];
if ($search)    { $where .= " AND (m.nombre LIKE ? OR c.nombre LIKE ? OR m.raza LIKE ?)"; $like="%$search%"; $params=[$like,$like,$like]; }
if ($esp_filtro){ $where .= " AND m.especie=?"; $params[]=$esp_filtro; }
if ($cli_filtro){ $where .= " AND m.cliente_id=?"; $params[]=$cli_filtro; }

$st = $db->prepare("SELECT m.*,c.nombre as dueno,c.telefono,
    (SELECT COUNT(*) FROM examenes_auxiliares WHERE mascota_id=m.id) as n_examenes
    FROM mascotas m JOIN clientes c ON c.id=m.cliente_id WHERE $where ORDER BY m.nombre ASC");
$st->execute($params); $mascotas=$st->fetchAll();

$especie_icons =['perro'=>'🐕','gato'=>'🐈','conejo'=>'🐰','ave'=>'🐦','reptil'=>'🦎','roedor'=>'🐭','otro'=>'🐾'];
$especie_labels=['perro'=>'Perro','gato'=>'Gato','conejo'=>'Conejo','ave'=>'Ave','reptil'=>'Reptil','roedor'=>'Roedor','otro'=>'Otro'];
?>

<?php if($msg==='success'): ?><div class="alert alert-success mb-2">✅ Mascota guardada correctamente.</div><?php endif; ?>

<?php if(in_array($action,['nueva','editar'])): ?>
<div class="card" style="max-width:680px">
  <div class="sec-header">
    <div class="sec-title"><?= $action==='editar'?'Editar':'Nueva'?> Mascota</div>
    <a href="?p=mascotas" class="btn btn-sm">← Volver</a>
  </div>
  <form method="POST" enctype="multipart/form-data">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= $editing['id']??'' ?>">

    <!-- FOTO DE PERFIL -->
    <div style="display:flex;align-items:flex-start;gap:20px;margin-bottom:20px">
      <div>
        <div id="foto-preview" style="width:100px;height:100px;border-radius:16px;overflow:hidden;border:2px solid var(--border);background:var(--bg3);display:flex;align-items:center;justify-content:center;font-size:40px;cursor:pointer;position:relative" onclick="document.getElementById('inp-foto').click()">
          <?php if(!empty($editing['foto']) && file_exists(UPLOADS_PATH.'/'.$editing['foto'])): ?>
          <img src="<?= UPLOADS_URL.'/'.$editing['foto'] ?>" id="foto-img" style="width:100%;height:100%;object-fit:cover">
          <?php else: ?>
          <span id="foto-emoji"><?= $especie_icons[$editing['especie']??'perro']??'🐾' ?></span>
          <?php endif; ?>
          <div style="position:absolute;bottom:0;left:0;right:0;background:rgba(0,0,0,.45);color:#fff;font-size:10px;text-align:center;padding:3px">📷 Cambiar</div>
        </div>
        <input type="file" id="inp-foto" name="foto" accept="image/*" style="display:none" onchange="previewFoto(this)">
        <div class="text-xs text-muted text-center mt-1">JPG/PNG · Máx 5MB</div>
        <?php if(!empty($editing['foto'])): ?>
        <button type="button" class="btn btn-xs" style="color:var(--red);width:100%;margin-top:4px" onclick="deleteFoto(<?= $editing['id'] ?>)">✕ Quitar foto</button>
        <?php endif; ?>
      </div>
      <div class="flex-1">
        <div class="form-row">
          <div class="form-group"><label class="form-label">Dueño / Cliente *</label>
            <select class="form-input" name="cliente_id" required>
              <option value="">— Seleccionar —</option>
              <?php foreach($clientes_sel as $c): ?><option value="<?= $c['id'] ?>" <?= ($editing['cliente_id']??$_GET['cliente_id']??'')==$c['id']?'selected':'' ?>><?= clean($c['nombre']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label class="form-label">Nombre *</label>
            <input class="form-input" name="nombre" value="<?= clean($editing['nombre']??'') ?>" required>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Especie *</label>
            <select class="form-input" name="especie" required onchange="document.getElementById('foto-emoji') && (document.getElementById('foto-emoji').textContent = {'perro':'🐕','gato':'🐈','conejo':'🐰','ave':'🐦','reptil':'🦎','roedor':'🐭','otro':'🐾'}[this.value]||'🐾')">
              <?php foreach($especie_labels as $k=>$v): ?><option value="<?= $k ?>" <?= ($editing['especie']??'perro')===$k?'selected':'' ?>><?= $v ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label class="form-label">Raza</label>
            <input class="form-input" name="raza" value="<?= clean($editing['raza']??'') ?>">
          </div>
        </div>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group"><label class="form-label">Sexo</label>
        <select class="form-input" name="sexo"><option value="macho" <?= ($editing['sexo']??'')==='macho'?'selected':'' ?>>Macho</option><option value="hembra" <?= ($editing['sexo']??'')==='hembra'?'selected':'' ?>>Hembra</option></select>
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
      <div class="form-group"><label class="form-label">Estado</label>
        <select class="form-input" name="estado">
          <option value="activo" <?= ($editing['estado']??'activo')==='activo'?'selected':'' ?>>Activo</option>
          <option value="fallecido" <?= ($editing['estado']??'')==='fallecido'?'selected':'' ?>>Fallecido</option>
          <option value="dado_en_adopcion" <?= ($editing['estado']??'')==='dado_en_adopcion'?'selected':'' ?>>Dado en adopción</option>
        </select>
      </div>
    </div>
    <div class="form-group"><label class="form-label">Alergias</label>
      <textarea class="form-input" name="alergias" style="min-height:60px"><?= clean($editing['alergias']??'') ?></textarea>
    </div>
    <div class="form-group"><label class="form-label">Condiciones / Enfermedades crónicas</label>
      <textarea class="form-input" name="condiciones" style="min-height:60px"><?= clean($editing['condiciones']??'') ?></textarea>
    </div>
    <div class="flex gap-1">
      <button type="submit" class="btn btn-primary">💾 Guardar mascota</button>
      <a href="?p=mascotas" class="btn">Cancelar</a>
    </div>
  </form>
</div>
<script>
function previewFoto(input) {
  const file = input.files[0]; if (!file) return;
  const reader = new FileReader();
  reader.onload = e => {
    const box = document.getElementById('foto-preview');
    let img = document.getElementById('foto-img');
    if (!img) { img = document.createElement('img'); img.id='foto-img'; img.style.cssText='width:100%;height:100%;object-fit:cover'; const emoji=document.getElementById('foto-emoji'); if(emoji)emoji.style.display='none'; box.insertBefore(img,box.firstChild); }
    img.src = e.target.result;
  };
  reader.readAsDataURL(file);
}
async function deleteFoto(id) {
  if (!confirm('¿Quitar la foto de esta mascota?')) return;
  const r = await fetch('?p=mascotas', {method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=delete_foto&id='+id});
  const d = await r.json(); if (d.ok) location.reload();
}
</script>

<?php else: ?>
<div class="sec-header">
  <div><div class="sec-title">Mascotas registradas</div><div class="sec-sub"><?= count($mascotas) ?> pacientes activos</div></div>
  <div class="flex gap-1">
    <form method="GET" class="flex gap-1"><input type="hidden" name="p" value="mascotas">
      <input class="form-input" name="q" value="<?= clean($search) ?>" placeholder="Buscar..." style="width:200px">
      <select class="form-input" name="especie" style="width:130px"><option value="">Todas</option><?php foreach($especie_labels as $k=>$v): ?><option value="<?= $k ?>" <?= $esp_filtro===$k?'selected':'' ?>><?= $v ?></option><?php endforeach; ?></select>
      <button type="submit" class="btn">Buscar</button>
    </form>
    <a href="?p=mascotas&action=nueva" class="btn btn-primary">+ Nueva Mascota</a>
  </div>
</div>

<div class="grid g3">
  <?php foreach($mascotas as $m):
    $edad = '';
    if($m['fecha_nacimiento']) {
      $diff=(new DateTime())->diff(new DateTime($m['fecha_nacimiento']));
      $edad=$diff->y>0?$diff->y.' año'.($diff->y>1?'s':''):$diff->m.' mes'.($diff->m>1?'es':'');
    }
    $tiene_foto = !empty($m['foto']) && file_exists(UPLOADS_PATH.'/'.$m['foto']);
  ?>
  <div class="card card-sm" style="transition:transform .15s" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform=''">
    <div class="flex items-center gap-2 mb-2">
      <!-- Foto de perfil o emoji -->
      <div style="width:56px;height:56px;border-radius:12px;overflow:hidden;border:2px solid var(--border);flex-shrink:0;background:var(--bg3);display:flex;align-items:center;justify-content:center">
        <?php if($tiene_foto): ?>
        <img src="<?= UPLOADS_URL.'/'.$m['foto'] ?>" style="width:100%;height:100%;object-fit:cover" alt="<?= clean($m['nombre']) ?>">
        <?php else: ?>
        <span style="font-size:28px"><?= $especie_icons[$m['especie']]??'🐾' ?></span>
        <?php endif; ?>
      </div>
      <div class="flex-1">
        <div style="font-size:15px;font-weight:700;color:var(--text)"><?= clean($m['nombre']) ?></div>
        <div class="text-xs text-muted"><?= clean($m['raza']??$especie_labels[$m['especie']]) ?></div>
      </div>
      <span class="badge <?= $m['estado']==='activo'?'b-teal':($m['estado']==='fallecido'?'b-red':'b-gray') ?>"><?= ucfirst(str_replace('_',' ',$m['estado'])) ?></span>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;background:var(--bg3);border-radius:8px;padding:10px;margin-bottom:10px">
      <div><div class="text-xs text-muted">Sexo</div><div class="text-sm font-med"><?= ucfirst($m['sexo']??'—') ?></div></div>
      <div><div class="text-xs text-muted">Edad</div><div class="text-sm font-med"><?= $edad?:'—' ?></div></div>
      <div><div class="text-xs text-muted">Peso</div><div class="text-sm font-med"><?= $m['peso']?$m['peso'].' kg':'—' ?></div></div>
      <div><div class="text-xs text-muted">Chip</div><div class="text-sm font-med truncate"><?= clean($m['chip_numero']??'Sin chip') ?></div></div>
    </div>

    <?php if($m['alergias']): ?><div class="alert alert-warn" style="padding:6px 10px;margin-bottom:8px;font-size:11px">⚠️ <?= clean($m['alergias']) ?></div><?php endif; ?>
    <?php if($m['condiciones']): ?><div class="alert alert-info" style="padding:6px 10px;margin-bottom:8px;font-size:11px">🔵 <?= clean($m['condiciones']) ?></div><?php endif; ?>

    <div class="flex items-center gap-1 mb-2">
      <span style="font-size:12px">👤</span>
      <span class="text-xs text-muted">Dueño:</span>
      <span class="text-xs font-med" style="color:var(--blue)"><?= clean($m['dueno']) ?></span>
    </div>

    <!-- Badges de contadores -->
    <?php if($m['n_examenes']>0): ?>
    <div class="flex gap-1 mb-2"><span class="badge b-purple">🔬 <?= $m['n_examenes'] ?> examen<?= $m['n_examenes']>1?'es':'' ?></span></div>
    <?php endif; ?>

    <div class="flex gap-1">
      <a href="?p=historial&mascota_id=<?= $m['id'] ?>" class="btn btn-xs flex-1" style="justify-content:center">🏥 Historia</a>
      <a href="?p=examenes&mascota_id=<?= $m['id'] ?>" class="btn btn-xs flex-1" style="justify-content:center">🔬 Exámenes</a>
      <a href="?p=mascotas&action=editar&id=<?= $m['id'] ?>" class="btn btn-xs">✏️</a>
      <a href="?p=mascotas&action=delete&id=<?= $m['id'] ?>" class="btn btn-xs" style="color:var(--red)" onclick="return confirm('¿Dar de baja esta mascota?')">✕</a>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if(empty($mascotas)): ?><div class="card text-center text-muted" style="grid-column:1/-1;padding:48px">No se encontraron mascotas.</div><?php endif; ?>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
