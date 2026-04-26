<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?= $tipo_label[$v['tipo_comprobante']]??'Comprobante' ?> <?= $num_fmt ?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Courier New',Courier,monospace;font-size:9pt;color:#000;background:#fff}
.voucher{width:80mm;margin:0 auto;padding:3mm 4mm}
.center{text-align:center}
.bold{font-weight:bold}
.right{text-align:right}
.sep{border-top:1px dashed #000;margin:2.5mm 0}
.sep-solid{border-top:1px solid #000;margin:2mm 0}
h1{font-size:11pt;font-weight:bold;text-align:center;margin:1mm 0}
h2{font-size:10pt;font-weight:bold;text-align:center;margin:1mm 0}
.ruc{font-size:9pt;text-align:center;margin:1mm 0}
.num{font-size:11pt;font-weight:bold;text-align:center;margin:2mm 0}
.campo{display:flex;gap:4px;font-size:8.5pt;margin:0.5mm 0}
.campo .lbl{font-weight:bold;white-space:nowrap}
table.t{width:100%;border-collapse:collapse;font-size:8pt;margin:1mm 0}
table.t th{font-weight:bold;border-bottom:1px solid #000;padding:1px 2px;font-size:7.5pt}
table.t td{padding:1.5px 2px;vertical-align:top}
table.t td.r{text-align:right}
table.t td.c{text-align:center}
.tot-row{display:flex;justify-content:space-between;font-size:9pt;margin:1mm 0}
.tot-row.grande{font-size:13pt;font-weight:bold;margin:2mm 0}
.qr{text-align:center;margin:3mm 0}
.qr img{width:60mm;height:60mm}
.footer{text-align:center;font-size:7.5pt;color:#333;margin-top:2mm;line-height:1.6}
.despedida{text-align:center;font-size:8pt;font-weight:bold;margin:2mm 0}
@media print{
  body{margin:0}
  .voucher{margin:0;width:100%}
  @page{margin:3mm;size:80mm auto}
}
</style>
</head>
<body>
<div class="voucher">

  <!-- LOGO -->
  <?php if(cfg($cfg,'comprobante_mostrar_logo','1') && $logo_url): ?>
  <div class="center" style="margin:2mm 0"><img src="<?= $logo_url ?>" style="max-width:50mm;max-height:20mm;object-fit:contain"></div>
  <?php endif; ?>

  <!-- DATOS EMPRESA -->
  <h1><?= htmlspecialchars(cfg($cfg,'nombre_clinica','VetPro')) ?></h1>
  <div class="ruc">R.U.C. <?= htmlspecialchars(cfg($cfg,'ruc_clinica')) ?></div>
  <div class="center" style="font-size:8pt;line-height:1.5">
    <?= htmlspecialchars(cfg($cfg,'direccion_clinica')) ?><br>
    <?php if(cfg($cfg,'telefono_clinica')): ?>Tel: <?= htmlspecialchars(cfg($cfg,'telefono_clinica')) ?><br><?php endif; ?>
    <?php if(cfg($cfg,'email_clinica')): ?><?= htmlspecialchars(cfg($cfg,'email_clinica')) ?><?php endif; ?>
  </div>

  <!-- CABECERA PLANTILLA -->
  <?php if(cfg($cfg,'plantilla_cabecera_activo','1') && cfg($cfg,'plantilla_cabecera')): ?>
  <div class="sep"></div>
  <div class="center" style="font-size:8pt"><?= nl2br(htmlspecialchars(cfg($cfg,'plantilla_cabecera'))) ?></div>
  <?php endif; ?>

  <div class="sep"></div>

  <!-- TIPO + NÚMERO -->
  <h2><?= $tipo_label[$v['tipo_comprobante']]??'COMPROBANTE' ?></h2>
  <div class="num"><?= $num_fmt ?></div>

  <div class="sep"></div>

  <!-- DATOS CLIENTE -->
  <div class="campo"><span class="lbl">FECHA:</span><span><?= date('d/m/Y',strtotime($v['fecha'])) ?></span></div>
  <div class="campo"><span class="lbl">CLIENTE:</span><span><?= htmlspecialchars($v['cli_nombre']) ?></span></div>
  <?php if($v['tipo_comprobante']==='factura' && $v['cli_ruc']): ?>
  <div class="campo"><span class="lbl">RUC:</span><span><?= htmlspecialchars($v['cli_ruc']) ?></span></div>
  <?php elseif($v['dni']): ?>
  <div class="campo"><span class="lbl">DOC:</span><span><?= htmlspecialchars($v['dni']) ?></span></div>
  <?php endif; ?>
  <?php if($v['mascota_nombre']): ?><div class="campo"><span class="lbl">MASCOTA:</span><span><?= htmlspecialchars($v['mascota_nombre']) ?></span></div><?php endif; ?>

  <div class="sep"></div>

  <!-- ÍTEMS -->
  <table class="t">
    <thead>
      <tr><th style="text-align:left">Producto</th><th class="c">Cant</th><th class="r">P.U.</th><th class="r">Total</th></tr>
    </thead>
    <tbody>
      <?php foreach($items as $it): ?>
      <tr>
        <td><?= htmlspecialchars($it['descripcion']) ?></td>
        <td class="c"><?= $it['cantidad'] ?></td>
        <td class="r"><?= number_format($it['precio_unitario'],2) ?></td>
        <td class="r"><?= number_format($it['subtotal'],2) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="sep"></div>

  <!-- TOTALES -->
  <div class="tot-row"><span>SUBTOTAL:</span></div>
  <div class="tot-row" style="justify-content:flex-end"><span>PEN <?= number_format($v['subtotal']-($v['descuento']??0),2) ?></span></div>
  <div class="tot-row"><span>IGV (18%):</span></div>
  <div class="tot-row" style="justify-content:flex-end"><span>PEN <?= number_format($v['igv'],2) ?></span></div>
  <?php if(($v['descuento']??0)>0): ?>
  <div class="tot-row"><span>DESCUENTO:</span><span>-PEN <?= number_format($v['descuento'],2) ?></span></div>
  <?php endif; ?>

  <div class="sep-solid"></div>
  <div class="tot-row grande"><span>TOTAL:</span></div>
  <div class="tot-row grande" style="justify-content:flex-end">PEN <?= number_format($v['total'],2) ?></div>
  <div class="sep-solid"></div>

  <div class="campo" style="margin-top:2mm"><span class="lbl">PAGO:</span><span>CONTADO</span></div>
  <div class="campo"><span class="lbl">MÉTODO:</span><span><?= $metodo_labels[$v['metodo_pago']]??strtoupper($v['metodo_pago']) ?></span></div>

  <!-- CUENTAS BANCARIAS -->
  <?php if(cfg($cfg,'plantilla_cuentas_bancarias')): ?>
  <div class="sep"></div>
  <div style="font-size:7.5pt;line-height:1.6"><?= nl2br(htmlspecialchars(cfg($cfg,'plantilla_cuentas_bancarias'))) ?></div>
  <?php endif; ?>

  <!-- MENSAJE INFERIOR -->
  <?php if(cfg($cfg,'plantilla_inferior_activo','1') && cfg($cfg,'plantilla_inferior')): ?>
  <div class="sep"></div>
  <div class="center" style="font-size:8pt"><?= nl2br(htmlspecialchars(cfg($cfg,'plantilla_inferior'))) ?></div>
  <?php endif; ?>

  <!-- QR -->
  <?php if(cfg($cfg,'comprobante_mostrar_qr','1')): ?>
  <div class="sep"></div>
  <div class="qr">
    <img src="https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=<?= urlencode($qr_data) ?>" alt="QR">
  </div>
  <?php endif; ?>

  <!-- DESPEDIDA -->
  <?php if(cfg($cfg,'plantilla_despedida_activo','1') && cfg($cfg,'plantilla_despedida')): ?>
  <div class="sep"></div>
  <div class="despedida"><?= htmlspecialchars(cfg($cfg,'plantilla_despedida')) ?></div>
  <?php endif; ?>

  <!-- FOOTER -->
  <div class="sep"></div>
  <div class="footer">
    Representación impresa de la <?= strtoupper($v['tipo_comprobante']??'') ?><br>
    <?php if(cfg($cfg,'plantilla_inferior_activo','1') && cfg($cfg,'plantilla_inferior')): ?>
    <?= nl2br(htmlspecialchars(cfg($cfg,'plantilla_inferior'))) ?>
    <?php endif; ?>
  </div>

  <!-- Espacio final para corte de papel -->
  <div style="height:10mm"></div>
</div>

<script>window.onload=()=>window.print();</script>
</body>
</html>
