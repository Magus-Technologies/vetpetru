<?php
/**
 * VetPro — Consulta pública de comprobante
 * URL: /vetpro/print/ver.php?serie=B001&num=1
 * Acceso público — no requiere login
 */
require_once __DIR__ . '/../includes/config.php';
session_write_close(); // no requiere sesión

$serie = preg_replace('/[^A-Za-z0-9]/', '', $_GET['serie'] ?? '');
$num   = (int)($_GET['num'] ?? 0);

if (!$serie || !$num) { http_response_code(404); die('Comprobante no encontrado.'); }

$db = getDB();

$st = $db->prepare("
    SELECT v.*,
           c.nombre as cli_nombre, c.dni, c.ruc as cli_ruc, c.telefono, c.direccion as cli_dir,
           m.nombre as mascota_nombre, u.nombre as vendedor
    FROM ventas v
    JOIN clientes c ON c.id=v.cliente_id
    LEFT JOIN mascotas m ON m.id=v.mascota_id
    LEFT JOIN usuarios u ON u.id=v.usuario_id
    WHERE v.serie=? AND v.numero=?
");
$st->execute([$serie, $num]); $v = $st->fetch();
if (!$v) { http_response_code(404); die('Comprobante no encontrado.'); }

$items = $db->prepare("SELECT * FROM venta_items WHERE venta_id=? ORDER BY id");
$items->execute([$v['id']]); $items = $items->fetchAll();

$cfg = $db->query("SELECT clave,valor FROM configuracion")->fetchAll(PDO::FETCH_KEY_PAIR);
function cfg(array $c, string $k, string $def=''): string { return $c[$k] ?? $def; }

$logo_url  = !empty($cfg['logo_path']) && file_exists(UPLOADS_PATH.'/'.$cfg['logo_path']) ? UPLOADS_URL.'/'.$cfg['logo_path'] : '';
$tipo_label= ['boleta'=>'BOLETA DE VENTA','factura'=>'FACTURA ELECTRÓNICA','ticket'=>'NOTA DE VENTA'];
$tipo_color= ['boleta'=>'#f59e0b','factura'=>'#f59e0b','ticket'=>'#555'];
$metodo_labels=['efectivo'=>'EFECTIVO','yape'=>'YAPE','plin'=>'PLIN','tarjeta_debito'=>'TARJETA DÉBITO','tarjeta_credito'=>'TARJETA CRÉDITO','transferencia'=>'TRANSFERENCIA'];
$num_fmt   = $v['serie'].'-'.str_pad($v['numero'],6,'0',STR_PAD_LEFT);
$qr_data   = BASE_URL.'/print/ver.php?serie='.$serie.'&num='.$num;
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $tipo_label[$v['tipo_comprobante']]??'Comprobante' ?> <?= $num_fmt ?> — <?= htmlspecialchars(cfg($cfg,'nombre_clinica')) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Plus Jakarta Sans',Arial,sans-serif;background:#f0f2f5;color:#1a1d23;font-size:14px;padding:20px}
.wrap{max-width:700px;margin:0 auto}
/* TOP BAR */
.topbar{background:#111827;border-radius:14px;padding:14px 20px;display:flex;align-items:center;gap:10px;margin-bottom:16px}
.logo-icon{width:36px;height:36px;background:#0d9f7a;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:18px}
.logo-text{font-size:17px;font-weight:800;color:#fff}
.logo-sub{font-size:11px;color:rgba(255,255,255,.4)}
/* COMPROBANTE */
.comp{background:#fff;border:1px solid #e2e5eb;border-radius:14px;overflow:hidden}
/* HEADER */
.comp-header{display:flex;justify-content:space-between;align-items:flex-start;padding:20px 24px;border-bottom:1px solid #e2e5eb;gap:16px}
.empresa-info h1{font-size:13pt;font-weight:800}
.empresa-info p{font-size:9pt;color:#5a6072;line-height:1.6;margin-top:4px}
.tipo-box{text-align:center;border:2px solid #000;border-radius:8px;overflow:hidden;min-width:160px;flex-shrink:0}
.tipo-color{background:<?= $tipo_color[$v['tipo_comprobante']]??'#f59e0b' ?>;color:#fff;font-size:10pt;font-weight:700;padding:6px 14px}
.tipo-ruc{font-size:9pt;font-weight:700;padding:4px 14px;border-bottom:1px solid #eee}
.tipo-num{font-size:13pt;font-weight:800;padding:6px 14px}
/* ESTADO */
.estado-bar{padding:10px 24px;font-size:12pt;font-weight:700;text-align:center;background:<?= $v['estado']==='pagado'?'#f0fdf4':($v['estado']==='anulado'?'#fef2f2':'#fffbeb') ?>;color:<?= $v['estado']==='pagado'?'#16a34a':($v['estado']==='anulado'?'#dc2626':'#d97706') ?>;border-bottom:1px solid #e2e5eb}
/* DATOS */
.datos-grid{display:grid;grid-template-columns:1fr 1fr;border-bottom:1px solid #e2e5eb}
.datos-col{padding:14px 24px}
.datos-col:first-child{border-right:1px solid #e2e5eb}
.dato{display:flex;gap:8px;margin-bottom:6px;font-size:12px}
.dato-lbl{font-weight:700;color:#5a6072;white-space:nowrap;min-width:90px}
.dato-val{color:#1a1d23}
/* ITEMS */
.items-wrap{padding:0 24px}
table.items{width:100%;border-collapse:collapse;margin:16px 0;font-size:12px}
table.items th{text-align:left;font-size:10px;font-weight:700;color:#9299a8;text-transform:uppercase;letter-spacing:.5px;padding:8px 10px;border-bottom:1px solid #e2e5eb;background:#f8f9fb}
table.items td{padding:10px 10px;border-bottom:1px solid #f0f2f5;vertical-align:top}
table.items td.r{text-align:right}
table.items td.c{text-align:center}
table.items tr:last-child td{border-bottom:none}
/* TOTALES */
.totales{padding:14px 24px;border-top:1px solid #e2e5eb}
.tot-row{display:flex;justify-content:space-between;font-size:13px;color:#5a6072;margin-bottom:4px}
.tot-total{font-size:22px;font-weight:800;color:#0d9f7a;display:flex;justify-content:space-between;margin-top:8px;padding-top:8px;border-top:2px solid #e2e5eb}
.metodo{font-size:12px;color:#5a6072;text-align:right;margin-top:4px}
/* FOOTER */
.footer-section{padding:16px 24px;border-top:1px solid #e2e5eb;background:#f8f9fb}
.cuentas{font-size:11px;line-height:1.8;color:#5a6072;margin-bottom:12px}
.despedida{text-align:center;font-size:12px;font-weight:700;color:#1a1d23;padding:8px;border-top:1px dashed #e2e5eb;border-bottom:1px dashed #e2e5eb;margin-bottom:12px}
.qr-wrap{text-align:center;margin:10px 0}
.legal{text-align:center;font-size:10px;color:#9299a8;line-height:1.7}
/* ANULADO OVERLAY */
<?php if($v['estado']==='anulado'): ?>
.comp{position:relative}.comp::after{content:'ANULADO';position:absolute;top:50%;left:50%;transform:translate(-50%,-50%) rotate(-35deg);font-size:80px;font-weight:900;color:rgba(220,38,38,.1);pointer-events:none;letter-spacing:8px}
<?php endif; ?>
/* ACTIONS */
.actions{display:flex;gap:10px;margin-top:16px;flex-wrap:wrap}
.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 18px;border-radius:9px;font-size:13px;font-weight:600;text-decoration:none;border:1px solid #e2e5eb;background:#f8f9fb;color:#5a6072;cursor:pointer;transition:all .15s}
.btn:hover{background:#e2e5eb;color:#1a1d23}
.btn-teal{background:#0d9f7a;color:#fff;border-color:#0d9f7a}
.btn-teal:hover{background:#0a7a5e;color:#fff}
.btn-wa{background:#25D366;color:#fff;border-color:#25D366}
.btn-wa:hover{background:#128C7E;color:#fff}
@media(max-width:600px){.datos-grid{grid-template-columns:1fr}.datos-col:first-child{border-right:none;border-bottom:1px solid #e2e5eb}.comp-header{flex-direction:column}.tipo-box{min-width:100%;text-align:center}.actions{flex-direction:column}.btn{justify-content:center}}
@media print{body{background:#fff;padding:0}.topbar,.actions{display:none!important}.comp{border:none;border-radius:0}@page{margin:10mm}}
</style>
</head>
<body>
<div class="wrap">

  <!-- TOP BAR -->
  <div class="topbar">
    <div class="logo-icon">🐾</div>
    <div>
      <div class="logo-text"><?= htmlspecialchars(cfg($cfg,'nombre_clinica','VetPro')) ?></div>
      <div class="logo-sub">Consulta de comprobante</div>
    </div>
    <div style="margin-left:auto;display:flex;gap:8px">
      <a href="<?= BASE_URL ?>/print/ver.php?serie=<?= urlencode($serie) ?>&num=<?= $num ?>&print=1" onclick="window.print();return false" class="btn" style="border-color:rgba(255,255,255,.2);background:rgba(255,255,255,.1);color:#fff;font-size:12px">🖨️ Imprimir</a>
    </div>
  </div>

  <!-- COMPROBANTE -->
  <div class="comp">

    <!-- HEADER -->
    <div class="comp-header">
      <div class="empresa-info">
        <?php if($logo_url && cfg($cfg,'comprobante_mostrar_logo','1')): ?>
        <img src="<?= $logo_url ?>" style="max-height:50px;max-width:160px;object-fit:contain;margin-bottom:8px;display:block" alt="Logo">
        <?php endif; ?>
        <h1><?= htmlspecialchars(cfg($cfg,'nombre_clinica','VetPro')) ?></h1>
        <p>
          <?= htmlspecialchars(cfg($cfg,'direccion_clinica')) ?><br>
          <?php if(cfg($cfg,'telefono_clinica')): ?>Tel.: <?= htmlspecialchars(cfg($cfg,'telefono_clinica')) ?><br><?php endif; ?>
          <?php if(cfg($cfg,'email_clinica')): ?><?= htmlspecialchars(cfg($cfg,'email_clinica')) ?><?php endif; ?>
        </p>
        <?php if(cfg($cfg,'plantilla_cabecera_activo','1') && cfg($cfg,'plantilla_cabecera')): ?>
        <p style="color:#cc0000;font-size:9pt;font-weight:600;margin-top:6px"><?= nl2br(htmlspecialchars(cfg($cfg,'plantilla_cabecera'))) ?></p>
        <?php endif; ?>
      </div>
      <div class="tipo-box">
        <div class="tipo-ruc">R.U.C. <?= htmlspecialchars(cfg($cfg,'ruc_clinica')) ?></div>
        <div class="tipo-color"><?= $tipo_label[$v['tipo_comprobante']]??'COMPROBANTE' ?></div>
        <div class="tipo-num"><?= $num_fmt ?></div>
      </div>
    </div>

    <!-- ESTADO -->
    <div class="estado-bar">
      <?= $v['estado']==='pagado'?'✅ PAGADO':($v['estado']==='anulado'?'❌ ANULADO':'⏳ PENDIENTE') ?>
    </div>

    <!-- DATOS CLIENTE / EMISIÓN -->
    <div class="datos-grid">
      <div class="datos-col">
        <div class="dato"><span class="dato-lbl">Cliente:</span><span class="dato-val"><?= htmlspecialchars($v['cli_nombre']) ?></span></div>
        <?php if($v['tipo_comprobante']==='factura'&&$v['cli_ruc']): ?><div class="dato"><span class="dato-lbl">RUC:</span><span class="dato-val"><?= htmlspecialchars($v['cli_ruc']) ?></span></div>
        <?php elseif($v['dni']): ?><div class="dato"><span class="dato-lbl">DNI:</span><span class="dato-val"><?= htmlspecialchars($v['dni']) ?></span></div><?php endif; ?>
        <?php if($v['cli_dir']&&$v['cli_dir']!=='-'): ?><div class="dato"><span class="dato-lbl">Dirección:</span><span class="dato-val"><?= htmlspecialchars($v['cli_dir']) ?></span></div><?php endif; ?>
        <?php if($v['mascota_nombre']): ?><div class="dato"><span class="dato-lbl">Mascota:</span><span class="dato-val">🐾 <?= htmlspecialchars($v['mascota_nombre']) ?></span></div><?php endif; ?>
      </div>
      <div class="datos-col">
        <div class="dato"><span class="dato-lbl">Fecha emisión:</span><span class="dato-val"><?= date('d/m/Y H:i',strtotime($v['fecha'])) ?></span></div>
        <div class="dato"><span class="dato-lbl">Moneda:</span><span class="dato-val">SOLES (PEN)</span></div>
        <div class="dato"><span class="dato-lbl">Método pago:</span><span class="dato-val"><?= $metodo_labels[$v['metodo_pago']]??strtoupper($v['metodo_pago']) ?></span></div>
        <div class="dato"><span class="dato-lbl">Vendedor:</span><span class="dato-val"><?= htmlspecialchars($v['vendedor']??'Sistema') ?></span></div>
      </div>
    </div>

    <!-- ÍTEMS -->
    <div class="items-wrap">
      <table class="items">
        <thead>
          <tr>
            <th style="width:36px">#</th>
            <th>Descripción</th>
            <th class="c" style="width:60px">Cant.</th>
            <th class="r" style="width:90px">Precio u.</th>
            <th class="r" style="width:90px">Subtotal</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($items as $i=>$it): ?>
          <tr>
            <td class="c" style="color:#9299a8"><?= $i+1 ?></td>
            <td><?= htmlspecialchars($it['descripcion']) ?></td>
            <td class="c"><?= $it['cantidad'] ?></td>
            <td class="r">S/. <?= number_format($it['precio_unitario'],2) ?></td>
            <td class="r" style="font-weight:600">S/. <?= number_format($it['subtotal'],2) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if(empty($items)): ?>
          <tr><td colspan="5" style="text-align:center;color:#9299a8;padding:24px">Sin ítems registrados.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- TOTALES -->
    <div class="totales">
      <div class="tot-row"><span>Subtotal:</span><span>S/. <?= number_format($v['subtotal'],2) ?></span></div>
      <?php if(($v['descuento']??0)>0): ?><div class="tot-row"><span>Descuento:</span><span style="color:#dc2626">-S/. <?= number_format($v['descuento'],2) ?></span></div><?php endif; ?>
      <div class="tot-row"><span>IGV (18%):</span><span>S/. <?= number_format($v['igv'],2) ?></span></div>
      <div class="tot-total"><span>Total:</span><span>S/. <?= number_format($v['total'],2) ?></span></div>
      <div class="metodo">Forma de pago: <?= $metodo_labels[$v['metodo_pago']]??strtoupper($v['metodo_pago']) ?></div>
    </div>

    <!-- FOOTER -->
    <div class="footer-section">
      <!-- Cuentas bancarias -->
      <?php if(cfg($cfg,'plantilla_cuentas_bancarias')): ?>
      <div class="cuentas"><?= nl2br(htmlspecialchars(cfg($cfg,'plantilla_cuentas_bancarias'))) ?></div>
      <?php endif; ?>

      <!-- Mensaje inferior -->
      <?php if(cfg($cfg,'plantilla_inferior_activo','1') && cfg($cfg,'plantilla_inferior')): ?>
      <div style="font-size:11px;color:#5a6072;margin-bottom:10px;text-align:center"><?= nl2br(htmlspecialchars(cfg($cfg,'plantilla_inferior'))) ?></div>
      <?php endif; ?>

      <!-- Despedida -->
      <?php if(cfg($cfg,'plantilla_despedida_activo','1') && cfg($cfg,'plantilla_despedida')): ?>
      <div class="despedida"><?= htmlspecialchars(strtoupper(cfg($cfg,'plantilla_despedida'))) ?></div>
      <?php endif; ?>

      <!-- QR -->
      <?php if(cfg($cfg,'comprobante_mostrar_qr','1')): ?>
      <div class="qr-wrap">
        <img src="https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=<?= urlencode($qr_data) ?>" alt="QR" style="width:80px;height:80px">
        <div style="font-size:10px;color:#9299a8;margin-top:4px">Escanea para verificar</div>
      </div>
      <?php endif; ?>

      <!-- Legal -->
      <div class="legal">
        Representación impresa de la <?= strtoupper($v['tipo_comprobante']??'') ?>.<br>
        Consulte su comprobante en: <strong><?= $qr_data ?></strong>
      </div>
    </div>

  </div><!-- .comp -->

  <!-- ACCIONES -->
  <div class="actions">
    <a href="<?= BASE_URL ?>/print/comprobante.php?id=<?= $v['id'] ?>&fmt=a4" target="_blank" class="btn btn-teal">📄 Imprimir A4</a>
    <a href="<?= BASE_URL ?>/print/comprobante.php?id=<?= $v['id'] ?>&fmt=voucher" target="_blank" class="btn">🖨️ Imprimir Voucher 80mm</a>
    <?php
    $tel2=preg_replace('/[^0-9]/','',ltrim($v['telefono'],'+'));
    if(strlen($tel2)<11) $tel2='51'.$tel2;
    $wa2="🧾 *".strtoupper($tipo_label[$v['tipo_comprobante']]??'Comprobante')."*\nN° $num_fmt\n\n👤 {$v['cli_nombre']}\n📅 ".date('d/m/Y',strtotime($v['fecha']))."\n💰 *Total: S/. ".number_format($v['total'],2)."* ✅\n\n📄 Ver comprobante:\n$qr_data\n\n".cfg($cfg,'plantilla_despedida','¡Gracias por su preferencia!');
    ?>
    <a href="https://wa.me/<?= $tel2 ?>?text=<?= rawurlencode($wa2) ?>" target="_blank" class="btn btn-wa">💬 Enviar por WhatsApp</a>
  </div>

</div><!-- .wrap -->
</body>
</html>
