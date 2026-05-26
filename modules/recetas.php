<?php
$page = 'recetas'; $pageTitle = 'Recetas Médicas';

// ── VISTA DE IMPRESIÓN (independiente, SIN el menú del sistema) ──
// Debe ir ANTES de incluir el header para que la receta salga sola, sin
// barra lateral ni topbar, y se imprima completa y cuadrada.
if (($_GET['action'] ?? '') === 'imprimir' && isset($_GET['id'])) {
  require_once __DIR__ . '/../includes/config.php';
  $db = getDB();
  if (function_exists('requireLogin')) requireLogin();
  $rec_id=(int)$_GET['id'];
  // Detectar columnas opcionales del veterinario (firma / colegiatura) de forma segura
  $u_cols = [];
  try { $u_cols = $db->query("SHOW COLUMNS FROM `usuarios`")->fetchAll(PDO::FETCH_COLUMN); } catch(Exception $e){}
  $sel_firma = in_array('firma',$u_cols) ? "u.firma" : "NULL as firma";
  $sel_cole  = in_array('colegiatura',$u_cols) ? "u.colegiatura" : "NULL as colegiatura";
  $rec=$db->prepare("SELECT r.*,m.nombre as mascota,m.especie,m.raza,m.peso,
      u.nombre as vet,u.especialidad as vet_esp,$sel_firma,$sel_cole,
      c.nombre as dueno,c.telefono
    FROM recetas r
    JOIN mascotas m ON m.id=r.mascota_id
    JOIN usuarios u ON u.id=r.veterinario_id
    JOIN clientes c ON c.id=m.cliente_id WHERE r.id=?");
  $rec->execute([$rec_id]); $rec=$rec->fetch();
  $items=$db->prepare("SELECT * FROM receta_items WHERE receta_id=?"); $items->execute([$rec_id]); $items=$items->fetchAll();
  $cfg=$db->query("SELECT clave,valor FROM configuracion")->fetchAll(PDO::FETCH_KEY_PAIR);

  // Logo dinámico desde configuración
  $logo_rel = $cfg['logo_path'] ?? '';
  $logo_url = $logo_rel ? UPLOADS_URL.'/'.$logo_rel : '';
  // Firma del veterinario (imagen subida en su perfil)
  $firma_rel = $rec['firma'] ?? '';
  $firma_url = $firma_rel ? UPLOADS_URL.'/'.$firma_rel : '';
  $clinica = clean($cfg['nombre_clinica'] ?? $cfg['clinica_nombre'] ?? 'Clínica Veterinaria');
  $direccion = clean($cfg['direccion_clinica'] ?? $cfg['clinica_direccion'] ?? '');
  $telefono = clean($cfg['telefono_clinica'] ?? $cfg['clinica_telefono'] ?? '');
  $ruc = clean($cfg['ruc_clinica'] ?? $cfg['clinica_ruc'] ?? '');
  $email = clean($cfg['email_clinica'] ?? $cfg['clinica_email'] ?? '');
  ?><!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Receta N° <?= str_pad($rec_id,6,'0',STR_PAD_LEFT) ?></title>
  <style>
  *{margin:0;padding:0;box-sizing:border-box}
  :root{--brand:#0d9f7a;--brand-d:#0a7d60;--ink:#1f2a37;--muted:#6b7280;--line:#e3e8ee}
  html,body{background:#eef1f4}
  body{font-family:'Segoe UI',Arial,Helvetica,sans-serif;color:var(--ink);-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .sheet{position:relative;width:210mm;min-height:297mm;margin:14px auto;background:#fff;padding:18mm 16mm 16mm;box-shadow:0 6px 28px rgba(0,0,0,.12);overflow:hidden}
  /* Marca de agua de huellitas */
  .paws{position:absolute;inset:0;width:100%;height:100%;z-index:0;pointer-events:none;opacity:.05}
  .content{position:relative;z-index:1}
  /* Cabecera */
  .header{display:flex;justify-content:space-between;align-items:flex-start;gap:18px;border-bottom:3px solid var(--brand);padding-bottom:14px}
  .brand{display:flex;gap:14px;align-items:center;min-width:0}
  .brand-logo{height:62px;max-width:160px;object-fit:contain;flex-shrink:0}
  .brand-fallback{width:58px;height:58px;border-radius:14px;background:#e2f5ee;color:var(--brand-d);display:flex;align-items:center;justify-content:center;font-size:30px;flex-shrink:0}
  .brand-info h1{font-size:21px;font-weight:800;color:var(--brand-d);letter-spacing:-.3px;line-height:1.1}
  .brand-info p{font-size:10.5px;color:var(--muted);line-height:1.5;margin-top:2px;max-width:330px}
  .doc-meta{text-align:right;flex-shrink:0}
  .doc-meta .title{display:inline-block;background:var(--brand);color:#fff;font-size:12px;font-weight:700;letter-spacing:1px;padding:5px 12px;border-radius:6px}
  .doc-meta .n{font-size:13px;font-weight:700;color:var(--ink);margin-top:8px}
  .doc-meta .d{font-size:11px;color:var(--muted);margin-top:2px}
  /* Datos paciente */
  .patient{display:grid;grid-template-columns:1fr 1fr;gap:10px 24px;background:#f7faf9;border:1px solid #e1efe9;border-radius:12px;padding:16px 18px;margin-top:18px}
  .field label{font-size:9px;text-transform:uppercase;color:#9aa3af;font-weight:700;letter-spacing:.6px;display:block;margin-bottom:2px}
  .field span{font-size:13.5px;font-weight:600;color:#111827}
  /* Rx */
  .rx{display:flex;align-items:center;gap:8px;margin:22px 0 10px}
  .rx .sym{font-family:Georgia,'Times New Roman',serif;font-size:30px;font-weight:700;color:var(--brand);line-height:1}
  .rx h2{font-size:13px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:var(--ink)}
  table{width:100%;border-collapse:separate;border-spacing:0;border:1px solid var(--line);border-radius:10px;overflow:hidden}
  thead th{background:var(--brand);color:#fff;padding:10px 12px;text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.5px;font-weight:700}
  thead th.c,tbody td.c{text-align:center}
  tbody td{padding:11px 12px;border-bottom:1px solid var(--line);font-size:12.5px;vertical-align:top}
  tbody tr:nth-child(even) td{background:#f8fbfa}
  tbody tr:last-child td{border-bottom:none}
  tbody td .med{font-weight:700;color:#0f172a;font-size:13px}
  .num-pill{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;background:#e2f5ee;color:var(--brand-d);font-weight:700;font-size:11px}
  /* Indicaciones */
  .indic{background:#f0fdf4;border:1px solid #bbf0d4;border-left:4px solid var(--brand);border-radius:0 10px 10px 0;padding:13px 16px;margin-top:18px}
  .indic .h{font-size:11px;font-weight:700;color:var(--brand-d);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px}
  .indic .b{font-size:12.5px;color:#374151;line-height:1.6}
  /* Firma */
  .sign-zone{display:flex;justify-content:flex-end;margin-top:54px}
  .sign{width:260px;text-align:center}
  .sign-img{height:64px;max-width:230px;object-fit:contain;margin:0 auto 2px;display:block}
  .sign-gap{height:64px}
  .sign-line{border-top:1.5px solid #374151;padding-top:7px}
  .sign-line .nm{font-size:13px;font-weight:700;color:#111827}
  .sign-line .rl{font-size:10.5px;color:var(--muted);margin-top:1px}
  .sign-line .cl{font-size:10.5px;color:var(--muted)}
  /* Pie */
  .foot{position:absolute;left:16mm;right:16mm;bottom:12mm;border-top:1px solid var(--line);padding-top:8px;display:flex;justify-content:space-between;font-size:9.5px;color:#9aa3af;z-index:1}
  /* Botón imprimir (no se imprime) */
  .toolbar{position:fixed;top:16px;right:16px;z-index:9;display:flex;gap:8px}
  .toolbar button{font-family:inherit;font-size:13px;font-weight:600;border:none;border-radius:8px;padding:9px 16px;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,.15)}
  .btn-pr{background:var(--brand);color:#fff}.btn-cl{background:#fff;color:#374151;border:1px solid #d1d5db}
  @media print{
    html,body{background:#fff !important;margin:0 !important;padding:0 !important}
    /* Ocultar TODO lo que no sea el documento al imprimir */
    .toolbar,.no-print{display:none !important}
    /* La hoja ocupa el ancho completo, sin sombras. Mantenemos un padding
       interno suave para que el encabezado y las huellitas no se descuadren */
    .sheet{margin:0 !important;box-shadow:none !important;width:100% !important;max-width:100% !important;min-height:auto !important;padding:6mm 4mm !important;border:none !important}
    /* El pie vuelve al flujo normal para que NO se superponga ni corte */
    .foot{position:static !important;left:auto;right:auto;bottom:auto;margin-top:28px}
    /* Evitar que las filas de la tabla se partan entre páginas */
    tr,.med-row{page-break-inside:avoid}
    @page{margin:14mm;size:A4}
  }
  </style></head><body>
  <div class="toolbar">
    <button class="btn-pr" onclick="window.print()">🖨️ Imprimir</button>
    <button class="btn-cl" onclick="window.close()">Cerrar</button>
  </div>
  <div class="sheet">
    <!-- Marca de agua: huellitas de perro y gato -->
    <svg class="paws" viewBox="0 0 600 850" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid slice" aria-hidden="true">
      <defs>
        <g id="paw-dog">
          <ellipse cx="0" cy="9" rx="10" ry="12.5"/>
          <ellipse cx="-13" cy="-8" rx="4.4" ry="6.4"/>
          <ellipse cx="-4.5" cy="-15" rx="4.6" ry="6.8"/>
          <ellipse cx="4.5" cy="-15" rx="4.6" ry="6.8"/>
          <ellipse cx="13" cy="-8" rx="4.4" ry="6.4"/>
        </g>
        <g id="paw-cat">
          <ellipse cx="0" cy="7" rx="8" ry="9"/>
          <ellipse cx="-10" cy="-5" rx="3.4" ry="4.8"/>
          <ellipse cx="-3.4" cy="-11" rx="3.5" ry="5"/>
          <ellipse cx="3.4" cy="-11" rx="3.5" ry="5"/>
          <ellipse cx="10" cy="-5" rx="3.4" ry="4.8"/>
        </g>
      </defs>
      <g fill="#0d9f7a">
        <use href="#paw-dog" transform="translate(70,90) rotate(18) scale(1.1)"/>
        <use href="#paw-cat" transform="translate(180,150) rotate(-12) scale(1)"/>
        <use href="#paw-dog" transform="translate(310,100) rotate(24) scale(.9)"/>
        <use href="#paw-cat" transform="translate(450,160) rotate(-20) scale(1.1)"/>
        <use href="#paw-dog" transform="translate(540,90) rotate(14) scale(1)"/>
        <use href="#paw-cat" transform="translate(110,300) rotate(-24) scale(.95)"/>
        <use href="#paw-dog" transform="translate(250,270) rotate(16) scale(1.15)"/>
        <use href="#paw-cat" transform="translate(400,320) rotate(-10) scale(1)"/>
        <use href="#paw-dog" transform="translate(530,300) rotate(28) scale(.9)"/>
        <use href="#paw-cat" transform="translate(60,460) rotate(12) scale(1.1)"/>
        <use href="#paw-dog" transform="translate(200,440) rotate(-18) scale(1)"/>
        <use href="#paw-cat" transform="translate(350,490) rotate(22) scale(.95)"/>
        <use href="#paw-dog" transform="translate(500,470) rotate(-14) scale(1.1)"/>
        <use href="#paw-cat" transform="translate(120,620) rotate(20) scale(1)"/>
        <use href="#paw-dog" transform="translate(280,600) rotate(-22) scale(.9)"/>
        <use href="#paw-cat" transform="translate(430,650) rotate(16) scale(1.1)"/>
        <use href="#paw-dog" transform="translate(70,770) rotate(12) scale(1)"/>
        <use href="#paw-cat" transform="translate(230,760) rotate(-16) scale(.95)"/>
        <use href="#paw-dog" transform="translate(390,790) rotate(24) scale(1.05)"/>
        <use href="#paw-cat" transform="translate(530,760) rotate(-12) scale(1)"/>
      </g>
    </svg>

    <div class="content">
      <div class="header">
        <div class="brand">
          <?php if($logo_url): ?>
            <img class="brand-logo" src="<?= $logo_url ?>" alt="Logo">
          <?php else: ?>
            <div class="brand-fallback">🐾</div>
          <?php endif; ?>
          <div class="brand-info">
            <h1><?= $clinica ?></h1>
            <p>
              <?= $direccion ?>
              <?php if($ruc): ?><br>RUC: <?= $ruc ?><?php endif; ?>
              <?php if($telefono): ?> &nbsp;·&nbsp; Tel: <?= $telefono ?><?php endif; ?>
              <?php if($email): ?><br><?= $email ?><?php endif; ?>
            </p>
          </div>
        </div>
        <div class="doc-meta">
          <div class="title">RECETA MÉDICA</div>
          <div class="n">N° <?= str_pad($rec_id,6,'0',STR_PAD_LEFT) ?></div>
          <div class="d">Fecha: <?= date('d/m/Y',strtotime($rec['fecha'])) ?></div>
        </div>
      </div>

      <div class="patient">
        <div class="field"><label>Paciente</label><span><?= clean($rec['mascota']) ?></span></div>
        <div class="field"><label>Propietario</label><span><?= clean($rec['dueno']) ?></span></div>
        <div class="field"><label>Especie / Raza</label><span><?= ucfirst($rec['especie']) ?><?= $rec['raza']?" — ".clean($rec['raza']):'' ?></span></div>
        <div class="field"><label>Peso</label><span><?= $rec['peso']?clean($rec['peso']).' kg':'—' ?></span></div>
      </div>

      <div class="rx"><span class="sym">℞</span><h2>Prescripción</h2></div>
      <table>
        <thead><tr><th style="width:42px">#</th><th>Medicamento</th><th class="c" style="width:70px">Dosis</th><th class="c" style="width:90px">Frecuencia</th><th class="c" style="width:80px">Duración</th><th class="c" style="width:70px">Vía</th></tr></thead>
        <tbody>
        <?php foreach($items as $i=>$it): ?>
          <tr>
            <td><span class="num-pill"><?= $i+1 ?></span></td>
            <td><span class="med"><?= clean($it['medicamento']) ?></span></td>
            <td class="c"><?= clean($it['dosis']) ?: '—' ?></td>
            <td class="c"><?= clean($it['frecuencia']) ?: '—' ?></td>
            <td class="c"><?= clean($it['duracion']) ?: '—' ?></td>
            <td class="c"><?= clean($it['via']) ?: '—' ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if(empty($items)): ?>
          <tr><td colspan="6" class="c" style="padding:24px;color:#9aa3af">Sin medicamentos registrados</td></tr>
        <?php endif; ?>
        </tbody>
      </table>

      <?php if($rec['indicaciones']): ?>
      <div class="indic">
        <div class="h">📋 Indicaciones</div>
        <div class="b"><?= nl2br(clean($rec['indicaciones'])) ?></div>
      </div>
      <?php endif; ?>

      <div class="sign-zone">
        <div class="sign">
          <?php if($firma_url): ?>
            <img class="sign-img" src="<?= $firma_url ?>" alt="Firma">
          <?php else: ?>
            <div class="sign-gap"></div>
          <?php endif; ?>
          <div class="sign-line">
            <div class="nm"><?= clean($rec['vet']) ?></div>
            <div class="rl">Médico Veterinario<?= $rec['vet_esp']? ' — '.clean($rec['vet_esp']) : '' ?></div>
            <?php if(!empty($rec['colegiatura'])): ?><div class="cl">CMVP N° <?= clean($rec['colegiatura']) ?></div><?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <div class="foot">
      <span><?= $clinica ?><?= $ruc?' · RUC '.$ruc:'' ?></span>
      <span>Documento generado por VetPro · <?= date('d/m/Y H:i') ?></span>
    </div>
  </div>
  <script>window.addEventListener('load',function(){setTimeout(function(){window.print();},350);});</script>
  </body></html><?php exit; }

// ── VISTA NORMAL (listado) — aquí sí cargamos el menú del sistema ──
require_once __DIR__ . '/../includes/header.php';
$db = getDB();
$action = $_GET['action'] ?? 'list';
$mascota_id = (int)($_GET['mascota_id'] ?? 0);
$msg = '';
$mascotas_sel=$db->query("SELECT m.id,CONCAT(m.nombre,' (',c.nombre,')') as label FROM mascotas m JOIN clientes c ON c.id=m.cliente_id WHERE m.estado='activo' ORDER BY m.nombre")->fetchAll();
$vets_sel=$db->query("SELECT id,nombre FROM usuarios WHERE rol IN ('veterinario','admin') AND activo=1")->fetchAll();
$where="r.mascota_id IS NOT NULL"; $params=[];
if ($mascota_id){$where.=" AND r.mascota_id=?";$params[]=$mascota_id;}
try { $_r=$db->query("SHOW COLUMNS FROM `mascotas` LIKE 'sede_id'")->fetchAll(); if(!empty($_r)&&!verTodasSedes()){$where.=" AND m.sede_id=".getSede();} } catch(Exception $e){}
$recetas=$db->prepare("SELECT r.*,m.nombre as mascota,m.especie,u.nombre as vet,c.nombre as dueno,c.telefono,
  (SELECT COUNT(*) FROM receta_items ri WHERE ri.receta_id=r.id) as n_items
  FROM recetas r JOIN mascotas m ON m.id=r.mascota_id JOIN usuarios u ON u.id=r.veterinario_id
  JOIN clientes c ON c.id=m.cliente_id WHERE $where ORDER BY r.fecha DESC LIMIT 60");
$recetas->execute($params); $recetas=$recetas->fetchAll();

$ei=['perro'=>'🐕','gato'=>'🐈','conejo'=>'🐰','ave'=>'🐦','reptil'=>'🦎','roedor'=>'🐭','otro'=>'🐾'];
?>
<div class="page">
<div class="sec-header">
  <div><div class="page-title">💊 Recetas Médicas</div><div class="page-desc"><?= count($recetas) ?> recetas registradas</div></div>
  <a href="<?= BASE_URL ?>/index.php?p=historial&action=nueva" class="btn btn-primary">＋ Nueva Consulta con Receta</a>
</div>

<div class="card" style="padding:0">
  <div class="table-wrap">
    <table class="vtable">
      <thead><tr><th>Fecha</th><th>Paciente</th><th>Dueño</th><th>Veterinario</th><th>Ítems</th><th>Acciones</th></tr></thead>
      <tbody>
        <?php foreach($recetas as $r): ?>
        <tr>
          <td class="text-muted"><?= date('d/m/Y',strtotime($r['fecha'])) ?></td>
          <td>
            <div class="flex items-center gap-2">
              <span style="font-size:18px"><?= $ei[$r['especie']]??'🐾' ?></span>
              <span class="td-main"><?= clean($r['mascota']) ?></span>
            </div>
          </td>
          <td class="text-muted"><?= clean($r['dueno']) ?></td>
          <td class="text-muted"><?= clean($r['vet']) ?></td>
          <td><span class="badge b-success">💊 <?= $r['n_items'] ?> medicamento<?= $r['n_items']!=1?'s':'' ?></span></td>
          <td>
            <div class="flex gap-1">
              <a href="?p=recetas&action=imprimir&id=<?= $r['id'] ?>" target="_blank" class="btn btn-xs btn-primary">🖨️ Imprimir</a>
              <?php
              $tel=preg_replace('/[^0-9]/','',ltrim($r['telefono'],'+'));
              if(strlen($tel)<11)$tel='51'.$tel;
              $wa="💊 *Receta Médica — VetPro*\n\nPaciente: *{$r['mascota']}*\nDueño: {$r['dueno']}\nFecha: ".date('d/m/Y',strtotime($r['fecha']))."\n\nVetPro 🐾";
              ?>
              <a href="https://wa.me/<?= $tel ?>?text=<?= rawurlencode($wa) ?>" target="_blank" class="btn btn-xs btn-wa" title="Enviar por WA">💬</a>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($recetas)): ?><tr><td colspan="6" class="text-center text-muted" style="padding:48px">Sin recetas registradas. Las recetas se crean desde Historia Clínica.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
