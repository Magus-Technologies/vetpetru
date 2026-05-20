<?php
$page = 'plantillas'; $pageTitle = 'Plantillas de Impresión';
require_once __DIR__ . '/../includes/header.php';
$db = getDB();
$msg = '';

// Cargar config actual
$cfg_raw = $db->query("SELECT clave,valor FROM configuracion")->fetchAll(PDO::FETCH_KEY_PAIR);
$cfg = $cfg_raw;

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='save') {
    $campos = [
        'nombre_clinica','ruc_clinica','telefono_clinica','email_clinica','direccion_clinica',
        'plantilla_cabecera','plantilla_cabecera_activo',
        'plantilla_inferior','plantilla_inferior_activo',
        'plantilla_despedida','plantilla_despedida_activo',
        'plantilla_cuentas_bancarias',
        'comprobante_mostrar_qr','comprobante_mostrar_logo',
    ];
    // checkboxes que si no están presentes = 0
    $checks = ['plantilla_cabecera_activo','plantilla_inferior_activo','plantilla_despedida_activo','comprobante_mostrar_qr','comprobante_mostrar_logo'];
    foreach($checks as $c) if(!isset($_POST[$c])) $_POST[$c]='0';

    $st = $db->prepare("INSERT INTO configuracion (clave,valor) VALUES(?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)");
    foreach($campos as $c) {
        $val = trim($_POST[$c] ?? '');
        $st->execute([$c, $val]);
        $cfg[$c] = $val;
    }

    // Subir logo si viene archivo
    if (!empty($_FILES['logo_file']['tmp_name']) && $_FILES['logo_file']['error']===UPLOAD_ERR_OK) {
        $mime = mime_content_type($_FILES['logo_file']['tmp_name']);
        if (in_array($mime,['image/jpeg','image/png','image/webp','image/gif','image/svg+xml']) && $_FILES['logo_file']['size']<=2*1024*1024) {
            $dir = UPLOADS_PATH.'/logo/';
            if(!is_dir($dir)) mkdir($dir,0755,true);
            $ext = $mime==='image/png'?'png':($mime==='image/svg+xml'?'svg':($mime==='image/webp'?'webp':'jpg'));
            // Borrar logos anteriores de cualquier extensión
            foreach(['png','jpg','jpeg','webp','gif','svg'] as $e) {
                $old = $dir.'logo.'.$e;
                if(file_exists($old)) unlink($old);
            }
            $fname = 'logo_'.time().'.'.$ext; // timestamp evita cache del navegador
            if(move_uploaded_file($_FILES['logo_file']['tmp_name'],$dir.$fname)) {
                $logo_path = 'logo/'.$fname;
                $db->prepare("INSERT INTO configuracion (clave,valor) VALUES('logo_path',?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)")->execute([$logo_path]);
                $cfg['logo_path'] = $logo_path;
            }
        }
    }
    $msg = 'success';
    // Recargar config completa para que la preview use los datos actualizados
    $cfg = $db->query("SELECT clave,valor FROM configuracion")->fetchAll(PDO::FETCH_KEY_PAIR);
}

// Helper get config
function cfg(array $c, string $k, string $def=''): string { return $c[$k] ?? $def; }

$logo_url = '';
if (!empty($cfg['logo_path'])) {
    $logo_abs = UPLOADS_PATH.'/'.$cfg['logo_path'];
    if (file_exists($logo_abs)) {
        $logo_url = UPLOADS_URL.'/'.$cfg['logo_path'].'?v='.filemtime($logo_abs);
    }
}
?>

<?php if($msg==='success'): ?><div class="alert alert-success mb-2">✅ Plantilla guardada correctamente.</div><?php endif; ?>

<div class="sec-header mb-2">
  <div><div class="sec-title">Plantillas de Impresión</div><div class="sec-sub">Personaliza cómo se ven tus comprobantes impresos</div></div>
  <div class="flex gap-1">
    <a href="?p=facturacion" class="btn btn-sm">← Facturación</a>
    <a href="javascript:window.open('<?= BASE_URL ?>/print/preview.php?tipo=boleta','_blank','width=900,height=700')" class="btn btn-sm">👁️ Vista previa</a>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 380px;gap:20px;align-items:start">

<!-- ── FORMULARIO ── -->
<div>
<form method="POST" enctype="multipart/form-data" id="form-plantilla">
  <input type="hidden" name="action" value="save">

  <!-- DATOS DE LA EMPRESA -->
  <div class="card mb-2">
    <div class="sec-title mb-2">🏢 Datos de la empresa</div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Razón social / Nombre</label>
        <input class="form-input" name="nombre_clinica" value="<?= clean(cfg($cfg,'nombre_clinica')) ?>" oninput="previewUpdate()">
      </div>
      <div class="form-group"><label class="form-label">RUC</label>
        <input class="form-input" name="ruc_clinica" value="<?= clean(cfg($cfg,'ruc_clinica')) ?>" oninput="previewUpdate()">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Dirección</label>
        <input class="form-input" name="direccion_clinica" value="<?= clean(cfg($cfg,'direccion_clinica')) ?>" oninput="previewUpdate()">
      </div>
      <div class="form-group"><label class="form-label">Teléfono</label>
        <input class="form-input" name="telefono_clinica" value="<?= clean(cfg($cfg,'telefono_clinica')) ?>" oninput="previewUpdate()">
      </div>
    </div>
    <div class="form-group"><label class="form-label">Email</label>
      <input class="form-input" type="email" name="email_clinica" value="<?= clean(cfg($cfg,'email_clinica')) ?>" oninput="previewUpdate()">
    </div>
    <!-- Logo -->
    <div class="form-group">
      <label class="form-label">Logo de la empresa</label>
      <div class="flex items-center gap-3">
        <div id="logo-box" style="width:80px;height:56px;border:1px solid var(--border);border-radius:8px;background:var(--bg3);display:flex;align-items:center;justify-content:center;overflow:hidden;cursor:pointer" onclick="document.getElementById('inp-logo').click()">
          <?php if($logo_url): ?>
          <img id="logo-img" src="<?= $logo_url ?>" style="max-width:100%;max-height:100%;object-fit:contain">
          <?php else: ?>
          <span id="logo-placeholder" style="font-size:10px;color:var(--text3);text-align:center;padding:4px">📷 Logo</span>
          <?php endif; ?>
        </div>
        <div>
          <label class="btn btn-sm" style="cursor:pointer">📤 Subir logo <input type="file" id="inp-logo" name="logo_file" accept="image/*" style="display:none" onchange="previewLogo(this)"></label>
          <div class="text-xs text-muted mt-1">PNG, JPG · Máx 2MB · Recomendado: fondo blanco</div>
        </div>
      </div>
    </div>
    <div class="flex gap-2">
      <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer"><input type="checkbox" name="comprobante_mostrar_logo" value="1" <?= cfg($cfg,'comprobante_mostrar_logo','1')?'checked':'' ?>> Mostrar logo en comprobante</label>
      <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer"><input type="checkbox" name="comprobante_mostrar_qr" value="1" <?= cfg($cfg,'comprobante_mostrar_qr','1')?'checked':'' ?>> Mostrar código QR</label>
    </div>
  </div>

  <!-- MENSAJE CABECERA -->
  <div class="card mb-2">
    <div class="flex items-center justify-between mb-2">
      <div><div class="sec-title">📌 Mensaje de Cabecera</div><div class="text-xs text-muted">Aparece debajo del logo, antes de los datos del cliente</div></div>
      <label class="toggle-wrap" style="display:flex;align-items:center;gap:8px;font-size:13px">
        <span>Activo</span>
        <input type="checkbox" name="plantilla_cabecera_activo" value="1" id="chk-cab" <?= cfg($cfg,'plantilla_cabecera_activo','1')?'checked':'' ?> onchange="toggleSection('cab-body',this.checked)">
        <span class="toggle-slider" id="toggle-cab"></span>
      </label>
    </div>
    <div id="cab-body" <?= !cfg($cfg,'plantilla_cabecera_activo','1')?'style="display:none"':'' ?>>
      <div class="text-xs text-muted mb-1">Puedes usar texto plano o HTML básico (&lt;b&gt;, &lt;br&gt;, &lt;span style&gt;)</div>
      <textarea class="form-input" name="plantilla_cabecera" rows="4" oninput="previewUpdate()" style="font-size:12px;font-family:monospace"><?= clean(cfg($cfg,'plantilla_cabecera')) ?></textarea>
    </div>
  </div>

  <!-- CUENTAS BANCARIAS -->
  <div class="card mb-2">
    <div class="sec-title mb-1">🏦 Cuentas Bancarias</div>
    <div class="text-xs text-muted mb-2">Aparece en la parte inferior izquierda (igual que en la FOTO3). Una cuenta por línea.</div>
    <textarea class="form-input" name="plantilla_cuentas_bancarias" rows="5" oninput="previewUpdate()" style="font-size:12px;font-family:monospace" placeholder="BCP Cta Cte soles: 191-XXXXXXXX&#10;CCI Soles: 002-191-XXXXXXXXXX&#10;BBVA Cta cte SOLES: 0011-XXXX&#10;CCI: 011-103-XXXXXXXXX&#10;CÓDIGO DE RECAUDO: XXXXX SOLES"><?= clean(cfg($cfg,'plantilla_cuentas_bancarias')) ?></textarea>
  </div>

  <!-- MENSAJE INFERIOR -->
  <div class="card mb-2">
    <div class="flex items-center justify-between mb-2">
      <div><div class="sec-title">📝 Mensaje Inferior</div><div class="text-xs text-muted">Texto que aparece debajo del total</div></div>
      <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer">
        Activo <input type="checkbox" name="plantilla_inferior_activo" value="1" <?= cfg($cfg,'plantilla_inferior_activo','1')?'checked':'' ?> onchange="toggleSection('inf-body',this.checked)">
      </label>
    </div>
    <div id="inf-body" <?= !cfg($cfg,'plantilla_inferior_activo','1')?'style="display:none"':'' ?>>
      <textarea class="form-input" name="plantilla_inferior" rows="3" oninput="previewUpdate()" style="font-size:12px;font-family:monospace"><?= clean(cfg($cfg,'plantilla_inferior')) ?></textarea>
    </div>
  </div>

  <!-- MENSAJE DESPEDIDA -->
  <div class="card mb-2">
    <div class="flex items-center justify-between mb-2">
      <div><div class="sec-title">👋 Mensaje de Despedida</div><div class="text-xs text-muted">Aparece al final del comprobante (en mayúsculas)</div></div>
      <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer">
        Activo <input type="checkbox" name="plantilla_despedida_activo" value="1" <?= cfg($cfg,'plantilla_despedida_activo','1')?'checked':'' ?> onchange="toggleSection('des-body',this.checked)">
      </label>
    </div>
    <div id="des-body" <?= !cfg($cfg,'plantilla_despedida_activo','1')?'style="display:none"':'' ?>>
      <textarea class="form-input" name="plantilla_despedida" rows="2" oninput="previewUpdate()" style="font-size:12px;font-family:monospace"><?= clean(cfg($cfg,'plantilla_despedida','¡Gracias por su preferencia!')) ?></textarea>
    </div>
  </div>

  <button type="submit" class="btn btn-primary" style="width:100%">💾 Guardar cambios</button>
</form>
</div>

<!-- ── VISTA PREVIA ── -->
<div style="position:sticky;top:20px">
  <div class="card" style="padding:14px">
    <div class="sec-title mb-2 text-center">Vista previa del comprobante</div>
    <div style="display:flex;gap:6px;margin-bottom:10px">
      <button class="btn btn-sm flex-1" id="prev-a4" onclick="setPrevFormat('a4')" style="background:var(--teal);color:#fff;border-color:var(--teal)">A4</button>
      <button class="btn btn-sm flex-1" id="prev-voucher" onclick="setPrevFormat('voucher')">Voucher 80mm</button>
    </div>
    <!-- MINI PREVIEW EMBEBIDA -->
    <div id="preview-container" style="background:#f8f8f8;border:1px solid #ddd;border-radius:8px;overflow:auto;max-height:580px;padding:10px">
      <?php
      $p_nombre  = htmlspecialchars(cfg($cfg,'nombre_clinica','VetPro'));
      $p_ruc     = htmlspecialchars(cfg($cfg,'ruc_clinica','20123456789'));
      $p_dir     = htmlspecialchars(cfg($cfg,'direccion_clinica','Av. Principal 234'));
      $p_tel     = htmlspecialchars(cfg($cfg,'telefono_clinica','01-444-5678'));
      $p_email   = htmlspecialchars(cfg($cfg,'email_clinica','info@vetpro.pe'));
      $p_cab     = cfg($cfg,'plantilla_cabecera','');
      $p_cab_on  = cfg($cfg,'plantilla_cabecera_activo','1');
      $p_inf     = cfg($cfg,'plantilla_inferior','');
      $p_inf_on  = cfg($cfg,'plantilla_inferior_activo','1');
      $p_des     = cfg($cfg,'plantilla_despedida','¡Gracias por su preferencia!');
      $p_des_on  = cfg($cfg,'plantilla_despedida_activo','1');
      $p_ctas    = cfg($cfg,'plantilla_cuentas_bancarias','');
      $p_logo_on = cfg($cfg,'comprobante_mostrar_logo','1');
      $p_qr_on   = cfg($cfg,'comprobante_mostrar_qr','1');
      ?>

      <!-- ═══ A4 PREVIEW ═══ -->
      <div id="prev-a4-content">
        <div style="background:#fff;border:1px solid #ccc;border-radius:4px;padding:14px;font-family:Arial,sans-serif;font-size:11px;box-shadow:0 2px 8px rgba(0,0,0,.08)">

          <!-- Cabecera: logo+empresa | tipo comprobante -->
          <div style="display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #111;padding-bottom:8px;margin-bottom:8px;gap:8px">
            <!-- Izquierda: logo y empresa -->
            <div style="flex:1;min-width:0">
              <?php if($p_logo_on && $logo_url): ?>
              <img src="<?= $logo_url ?>" style="max-height:36px;max-width:100px;object-fit:contain;display:block;margin-bottom:4px">
              <?php endif; ?>
              <div style="font-weight:800;font-size:12px;color:#111;line-height:1.2"><?= $p_nombre ?></div>
              <div style="font-size:9px;color:#444;line-height:1.6;margin-top:2px">
                <?= $p_dir ?><br>
                Tel: <?= $p_tel ?><?= $p_email?' &nbsp;·&nbsp; '.$p_email:'' ?>
              </div>
              <?php if($p_cab_on && $p_cab): ?>
              <div style="margin-top:4px;font-size:9px;color:#cc0000;font-weight:600"><?= nl2br(htmlspecialchars($p_cab)) ?></div>
              <?php endif; ?>
            </div>
            <!-- Derecha: tipo comprobante -->
            <div style="border:2px solid #111;border-radius:3px;min-width:120px;text-align:center;overflow:hidden;flex-shrink:0">
              <div style="font-size:8px;font-weight:700;padding:3px 6px;border-bottom:1px solid #111">R.U.C. <?= $p_ruc ?></div>
              <div style="background:#f59e0b;color:#fff;font-size:8px;font-weight:700;padding:4px 6px">BOLETA DE VENTA</div>
              <div style="font-size:10px;font-weight:800;padding:4px 6px">B001-000111</div>
            </div>
          </div>

          <!-- Datos del cliente -->
          <div style="display:grid;grid-template-columns:1fr 1fr;border:1px solid #ccc;margin-bottom:8px;font-size:8.5px">
            <div style="padding:5px 7px;border-right:1px solid #ccc">
              <div><b>CLIENTE:</b> EJEMPLO CLIENTE</div>
              <div><b>DNI:</b> 12345678</div>
              <div><b>DIRECCIÓN:</b> —</div>
            </div>
            <div style="padding:5px 7px">
              <div><b>FECHA:</b> <?= date('d/m/Y') ?></div>
              <div><b>MONEDA:</b> SOLES</div>
              <div><b>MÉTODO:</b> EFECTIVO</div>
            </div>
          </div>

          <!-- Tabla items -->
          <table style="width:100%;border-collapse:collapse;font-size:8px;margin-bottom:6px">
            <thead>
              <tr style="background:#f3f4f6">
                <?php foreach(['N°','CANT.','UNID.','CÓDIGO','DESCRIPCIÓN','V.UNIT.','IGV','P.UNIT.','TOTAL'] as $h): ?>
                <th style="border:1px solid #bbb;padding:3px 4px;font-weight:700;font-size:7.5px"><?= $h ?></th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <tr>
                <?php foreach(['1','1.000','NIU','—','Consulta veterinaria general','18.64','3.36','22.00','22.00'] as $td): ?>
                <td style="border:1px solid #bbb;padding:3px 4px;text-align:center"><?= $td ?></td>
                <?php endforeach; ?>
              </tr>
            </tbody>
          </table>

          <!-- Son + totales -->
          <div style="font-weight:700;font-size:8px;border:1px solid #bbb;padding:4px;text-align:center;margin-bottom:6px;background:#fafafa">
            SON: VEINTIDÓS CON 00/100 SOLES
          </div>
          <div style="display:flex;gap:8px;align-items:flex-start">
            <!-- Cuentas bancarias -->
            <div style="flex:1">
              <?php if($p_ctas): ?>
              <div style="font-size:7.5px;line-height:1.6;color:#333"><?= nl2br(htmlspecialchars($p_ctas)) ?></div>
              <?php else: ?>
              <div style="font-size:7.5px;color:#bbb;font-style:italic">[Cuentas bancarias]</div>
              <?php endif; ?>
            </div>
            <!-- Totales -->
            <table style="font-size:8px;border-collapse:collapse;min-width:110px">
              <tr><td style="border:1px solid #bbb;padding:2px 5px">OP. GRAVADAS S/</td><td style="border:1px solid #bbb;padding:2px 5px;text-align:right;font-weight:600">18.64</td></tr>
              <tr><td style="border:1px solid #bbb;padding:2px 5px">IGV 18%: S/</td><td style="border:1px solid #bbb;padding:2px 5px;text-align:right;font-weight:600">3.36</td></tr>
              <tr style="background:#f0fdf4"><td style="border:1px solid #bbb;padding:2px 5px;font-weight:700">TOTAL: S/</td><td style="border:1px solid #bbb;padding:2px 5px;text-align:right;font-weight:800;color:#065f46">22.00</td></tr>
            </table>
          </div>

          <!-- Despedida -->
          <?php if($p_des_on && $p_des): ?>
          <div style="text-align:center;font-size:8px;font-weight:700;border-top:1px dashed #bbb;border-bottom:1px dashed #bbb;padding:4px;margin:6px 0"><?= htmlspecialchars(strtoupper($p_des)) ?></div>
          <?php endif; ?>

          <!-- QR -->
          <?php if($p_qr_on): ?>
          <div style="text-align:center;margin:4px 0">
            <img src="https://api.qrserver.com/v1/create-qr-code/?size=50x50&data=VetPro-B001-000111" style="width:36px;height:36px">
          </div>
          <?php endif; ?>

          <!-- Mensaje inferior -->
          <?php if($p_inf_on && $p_inf): ?>
          <div style="text-align:center;font-size:7.5px;color:#555;margin-top:3px"><?= nl2br(htmlspecialchars($p_inf)) ?></div>
          <?php endif; ?>
        </div>
      </div>

      <!-- ═══ VOUCHER PREVIEW ═══ -->
      <div id="prev-voucher-content" style="display:none">
        <div style="max-width:210px;margin:0 auto;background:#fff;border:1px solid #ccc;padding:8px;font-family:'Courier New',monospace;font-size:8px;box-shadow:0 2px 8px rgba(0,0,0,.08)">
          <?php if($p_logo_on && $logo_url): ?>
          <div style="text-align:center;margin-bottom:5px"><img src="<?= $logo_url ?>" style="max-height:22px;max-width:130px;object-fit:contain"></div>
          <?php endif; ?>
          <div style="text-align:center;font-weight:bold;font-size:9.5px"><?= $p_nombre ?></div>
          <div style="text-align:center;font-size:7.5px">R.U.C. <?= $p_ruc ?></div>
          <div style="text-align:center;font-size:7px;line-height:1.4"><?= $p_dir ?></div>
          <div style="text-align:center;font-size:7px">Tel: <?= $p_tel ?></div>
          <?php if($p_cab_on && $p_cab): ?><div style="text-align:center;font-size:7px;color:#c00"><?= nl2br(htmlspecialchars($p_cab)) ?></div><?php endif; ?>
          <div style="border-top:1px dashed #000;margin:4px 0"></div>
          <div style="text-align:center;font-weight:bold;font-size:8.5px">BOLETA DE VENTA</div>
          <div style="text-align:center;font-weight:bold;font-size:10px">B001-000111</div>
          <div style="border-top:1px dashed #000;margin:4px 0"></div>
          <div><b>FECHA:</b> <?= date('d/m/Y') ?></div>
          <div><b>CLIENTE:</b> EJEMPLO CLIENTE</div>
          <div style="border-top:1px dashed #000;margin:4px 0"></div>
          <div style="display:flex;justify-content:space-between"><span>Consulta veterinaria</span><span>22.00</span></div>
          <div style="border-top:1px solid #000;margin:4px 0"></div>
          <div style="display:flex;justify-content:space-between"><span>SUBTOTAL:</span><span>18.64</span></div>
          <div style="display:flex;justify-content:space-between"><span>IGV (18%):</span><span>3.36</span></div>
          <div style="border-top:1px solid #000;margin:3px 0"></div>
          <div style="display:flex;justify-content:space-between;font-weight:bold;font-size:10px"><span>TOTAL:</span><span>22.00</span></div>
          <div style="border-top:1px solid #000;margin:3px 0"></div>
          <div><b>MÉTODO:</b> EFECTIVO</div>
          <?php if($p_ctas): ?><div style="border-top:1px dashed #000;margin:4px 0"></div><div style="font-size:7px;line-height:1.5"><?= nl2br(htmlspecialchars($p_ctas)) ?></div><?php endif; ?>
          <?php if($p_qr_on): ?><div style="text-align:center;margin:5px 0"><img src="https://api.qrserver.com/v1/create-qr-code/?size=50x50&data=VetPro" style="width:44px;height:44px"></div><?php endif; ?>
          <?php if($p_des_on && $p_des): ?><div style="text-align:center;font-weight:bold;border-top:1px dashed #000;padding-top:3px;margin-top:4px"><?= htmlspecialchars(strtoupper($p_des)) ?></div><?php endif; ?>
          <?php if($p_inf_on && $p_inf): ?><div style="text-align:center;font-size:7px;border-top:1px dashed #000;padding-top:3px;margin-top:4px"><?= nl2br(htmlspecialchars($p_inf)) ?></div><?php endif; ?>
        </div>
      </div>
    </div>
    <!-- Link para abrir en pestaña real -->
    <div class="text-center mt-2">
      <a href="<?= BASE_URL ?>/print/preview.php?fmt=a4" target="_blank" id="btn-open-preview" class="btn btn-sm btn-primary" style="width:100%;justify-content:center">🔍 Abrir vista previa real en nueva pestaña</a>
    </div>
  </div>
</div>

</div><!-- grid -->

<script>
var previewFmt = 'a4';
function setPrevFormat(fmt) {
  previewFmt = fmt;
  // Toggle buttons
  ['a4','voucher'].forEach(f=>{
    var b=document.getElementById('prev-'+f);
    b.style.background=f===fmt?'var(--teal)':'';
    b.style.color=f===fmt?'#fff':'';
    b.style.borderColor=f===fmt?'var(--teal)':'';
  });
  // Toggle preview sections
  document.getElementById('prev-a4-content').style.display = fmt==='a4'?'block':'none';
  document.getElementById('prev-voucher-content').style.display = fmt==='voucher'?'block':'none';
  // Update open link
  document.getElementById('btn-open-preview').href = '<?= BASE_URL ?>/print/preview.php?fmt='+fmt;
}
function toggleSection(id,show) { document.getElementById(id).style.display=show?'block':'none'; }
function previewLogo(input) {
  const file=input.files[0]; if(!file) return;
  const reader=new FileReader();
  reader.onload=e=>{
    const box=document.getElementById('logo-box');
    let img=document.getElementById('logo-img');
    if(!img){img=document.createElement('img');img.id='logo-img';img.style.cssText='max-width:100%;max-height:100%;object-fit:contain';const ph=document.getElementById('logo-placeholder');if(ph)ph.style.display='none';box.appendChild(img);}
    img.src=e.target.result;
  };
  reader.readAsDataURL(file);
}
</script>

<style>
.toggle-slider{position:relative;display:inline-block;width:36px;height:20px;background:#ccc;border-radius:10px;cursor:pointer;transition:.2s}
input[type=checkbox]:checked+.toggle-slider{background:var(--teal)}
.toggle-slider::after{content:'';position:absolute;width:16px;height:16px;background:#fff;border-radius:50%;top:2px;left:2px;transition:.2s}
input[type=checkbox]:checked+.toggle-slider::after{left:18px}
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>