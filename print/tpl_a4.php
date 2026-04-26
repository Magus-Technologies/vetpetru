<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?= $tipo_label[$v['tipo_comprobante']]??'Comprobante' ?> <?= $num_fmt ?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:Arial,sans-serif;font-size:10pt;color:#111;background:#fff}
.page{width:210mm;min-height:297mm;margin:0 auto;padding:14mm 14mm 10mm}
/* CABECERA */
.header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:4mm;border-bottom:2px solid #000;padding-bottom:4mm}
.logo-zona{display:flex;align-items:center;gap:8px;max-width:55%}
.logo-img{max-width:80px;max-height:60px;object-fit:contain}
.logo-placeholder{width:60px;height:45px;border:1px solid #aaa;display:flex;align-items:center;justify-content:center;font-size:8pt;color:#999}
.empresa-datos{font-size:8.5pt;line-height:1.5}
.empresa-nombre{font-size:11pt;font-weight:bold;margin-bottom:2px}
.ruc-box{border:2px solid #000;padding:3mm 6mm;text-align:center;min-width:130px}
.ruc-box .ruc-lbl{font-size:8pt;font-weight:bold}
.ruc-box .ruc-num{font-size:10pt;font-weight:bold;margin:2px 0}
.tipo-box{background:<?= $tipo_color[$v['tipo_comprobante']]??'#f59e0b' ?>;color:#fff;padding:4px 8px;font-size:10pt;font-weight:bold;text-align:center;margin-top:4px}
.num-box{border:1px solid #000;padding:4px 8px;text-align:center;font-size:11pt;font-weight:bold;margin-top:3px}
/* CABECERA PLANTILLA */
.plantilla-cab{font-size:8pt;margin-bottom:3mm;padding:2mm 0;border-bottom:1px dashed #ccc}
/* DATOS CLIENTE */
.bloque-datos{display:grid;grid-template-columns:1fr 1fr;gap:0;border:1px solid #aaa;margin-bottom:3mm}
.bloque-datos .col{padding:2mm 3mm}
.bloque-datos .col:first-child{border-right:1px solid #aaa}
.dato-row{display:flex;gap:4px;margin-bottom:2px;font-size:8.5pt}
.dato-lbl{font-weight:bold;white-space:nowrap}
/* TABLA ITEMS */
table.items{width:100%;border-collapse:collapse;margin-bottom:3mm;font-size:8.5pt}
table.items th{background:#e5e7eb;padding:3px 4px;border:1px solid #aaa;font-size:8pt;text-align:center}
table.items td{padding:3px 4px;border:1px solid #aaa;vertical-align:top}
table.items td.num{text-align:center}
table.items td.right{text-align:right}
/* TOTALES */
.totales-zona{display:flex;gap:0;margin-bottom:3mm}
.cuentas-zona{flex:1;font-size:7.5pt;line-height:1.6;padding-right:6mm}
.totales-tabla{min-width:160px;border:1px solid #aaa;border-collapse:collapse}
.totales-tabla td{padding:2px 5px;font-size:9pt;border:1px solid #aaa}
.totales-tabla td:last-child{text-align:right}
.total-fila td{font-size:12pt;font-weight:bold;background:#f0f0f0}
.son-text{font-weight:bold;font-size:9pt;text-align:center;border:1px solid #aaa;padding:3px;margin-bottom:2mm}
/* FOOTER */
.qr-zona{text-align:center;margin:3mm 0}
.qr-zona img{width:70px;height:70px}
.footer-text{text-align:center;font-size:7.5pt;color:#555;margin-top:2mm;line-height:1.6;border-top:1px dashed #ccc;padding-top:2mm}
.despedida{text-align:center;font-size:9pt;font-weight:bold;margin:2mm 0;border-top:1px dashed #ccc;border-bottom:1px dashed #ccc;padding:2mm 0}
.mensaje-inf{text-align:center;font-size:8pt;color:#555;margin:2mm 0}
.pago-info{font-size:8.5pt;margin:2mm 0}
@media print{
  body{margin:0}
  .page{padding:8mm;margin:0;width:100%}
  @page{margin:0;size:A4}
}
</style>
</head>
<body>
<div class="page">

  <!-- CABECERA EMPRESA -->
  <div class="header">
    <div class="logo-zona">
      <?php if(cfg($cfg,'comprobante_mostrar_logo','1') && $logo_url): ?>
      <img class="logo-img" src="<?= $logo_url ?>" alt="Logo">
      <?php else: ?>
      <div class="logo-placeholder">Logo</div>
      <?php endif; ?>
      <div class="empresa-datos">
        <?php if(cfg($cfg,'plantilla_cabecera_activo','1') && cfg($cfg,'plantilla_cabecera')): ?>
        <div style="font-size:8pt;color:#cc0000;font-weight:bold"><?= cfg($cfg,'plantilla_cabecera') ?></div>
        <?php else: ?>
        <div class="empresa-nombre"><?= htmlspecialchars(cfg($cfg,'nombre_clinica','VetPro')) ?></div>
        <div><?= htmlspecialchars(cfg($cfg,'direccion_clinica')) ?></div>
        <div>Tel.: <?= htmlspecialchars(cfg($cfg,'telefono_clinica')) ?></div>
        <div>Correo: <?= htmlspecialchars(cfg($cfg,'email_clinica')) ?></div>
        <?php endif; ?>
      </div>
    </div>
    <div class="ruc-box">
      <div class="ruc-lbl">R.U.C. <?= htmlspecialchars(cfg($cfg,'ruc_clinica')) ?></div>
      <div class="tipo-box"><?= $tipo_label[$v['tipo_comprobante']]??'COMPROBANTE' ?></div>
      <div class="num-box"><?= $num_fmt ?></div>
    </div>
  </div>

  <!-- NOMBRE EMPRESA (debajo de cabecera) -->
  <div style="font-weight:bold;font-size:10pt;margin-bottom:1mm"><?= htmlspecialchars(cfg($cfg,'nombre_clinica')) ?></div>
  <div style="font-size:8pt;margin-bottom:3mm">
    <?= htmlspecialchars(cfg($cfg,'direccion_clinica')) ?>
    <?php if(cfg($cfg,'telefono_clinica')): ?> · TELEF.: <?= htmlspecialchars(cfg($cfg,'telefono_clinica')) ?><?php endif; ?>
    <?php if(cfg($cfg,'email_clinica')): ?><br>Correo: <?= htmlspecialchars(cfg($cfg,'email_clinica')) ?><?php endif; ?>
  </div>

  <!-- DATOS CLIENTE / FECHA -->
  <div class="bloque-datos">
    <div class="col">
      <div class="dato-row"><span class="dato-lbl">CLIENTE:</span><span><?= htmlspecialchars($v['cli_nombre']) ?></span></div>
      <?php if($v['tipo_comprobante']==='factura'): ?>
      <div class="dato-row"><span class="dato-lbl">RUC:</span><span><?= htmlspecialchars($v['cli_ruc']??'—') ?></span></div>
      <?php else: ?>
      <div class="dato-row"><span class="dato-lbl">DNI:</span><span><?= htmlspecialchars($v['dni']??'—') ?></span></div>
      <?php endif; ?>
      <div class="dato-row"><span class="dato-lbl">DIRECCIÓN:</span><span><?= htmlspecialchars($v['cli_dir']??'-') ?></span></div>
    </div>
    <div class="col">
      <div class="dato-row"><span class="dato-lbl">FECHA EMISIÓN:</span><span><?= date('d/m/Y',strtotime($v['fecha'])) ?></span></div>
      <div class="dato-row"><span class="dato-lbl">MONEDA:</span><span>SOLES</span></div>
      <div class="dato-row"><span class="dato-lbl">FORMA DE PAGO:</span><span>CONTADO</span></div>
      <div class="dato-row"><span class="dato-lbl">MÉTODO PAGO:</span><span><?= $metodo_labels[$v['metodo_pago']]??strtoupper($v['metodo_pago']) ?></span></div>
      <div class="dato-row"><span class="dato-lbl">VENDEDOR:</span><span><?= htmlspecialchars($v['vendedor']??'Sistema') ?></span></div>
      <?php if($v['mascota_nombre']): ?><div class="dato-row"><span class="dato-lbl">MASCOTA:</span><span><?= htmlspecialchars($v['mascota_nombre']) ?></span></div><?php endif; ?>
    </div>
  </div>

  <!-- TABLA ÍTEMS -->
  <table class="items">
    <thead>
      <tr>
        <th style="width:28px">N°</th>
        <th style="width:38px">CANT.</th>
        <th style="width:36px">UNIDAD</th>
        <th style="width:54px">CODIGO</th>
        <th>DESCRIPCIÓN</th>
        <th style="width:55px">V.UNIT.</th>
        <th style="width:38px">IGV.</th>
        <th style="width:55px">P.UNIT.</th>
        <th style="width:55px">TOTAL</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach($items as $i=>$it):
        $base_u = round($it['precio_unitario']/1.18,4);
        $igv_u  = round($it['precio_unitario']-$base_u,4);
      ?>
      <tr>
        <td class="num"><?= $i+1 ?></td>
        <td class="num"><?= number_format($it['cantidad'],3) ?></td>
        <td class="num">NIU</td>
        <td class="num">-</td>
        <td><?= htmlspecialchars($it['descripcion']) ?></td>
        <td class="right"><?= number_format($base_u,2) ?></td>
        <td class="right"><?= number_format($igv_u,2) ?></td>
        <td class="right"><?= number_format($it['precio_unitario'],2) ?></td>
        <td class="right"><?= number_format($it['subtotal'],2) ?></td>
      </tr>
      <?php endforeach; ?>
      <tr><td colspan="9" style="height:6px"></td></tr>
    </tbody>
  </table>

  <!-- SON: -->
  <?php
  $num_a_letras = function(float $n): string {
      $entero = (int)$n; $dec = round(($n-$entero)*100);
      $unidades=['','UN','DOS','TRES','CUATRO','CINCO','SEIS','SIETE','OCHO','NUEVE','DIEZ','ONCE','DOCE','TRECE','CATORCE','QUINCE','DIECISEIS','DIECISIETE','DIECIOCHO','DIECINUEVE','VEINTE'];
      $decenas=['','','VEINTI','TREINTA','CUARENTA','CINCUENTA','SESENTA','SETENTA','OCHENTA','NOVENTA'];
      $palabras=''; $e=$entero;
      if($e>=1000){$miles=intdiv($e,1000);$palabras.=($miles>1?$unidades[$miles].' MIL':'MIL').' ';$e=$e%1000;}
      if($e>=100){$c=intdiv($e,100);$ss=['','CIEN','DOSCIENTOS','TRESCIENTOS','CUATROCIENTOS','QUINIENTOS','SEISCIENTOS','SETECIENTOS','OCHOCIENTOS','NOVECIENTOS'];$palabras.=$ss[$c].' ';$e=$e%100;}
      if($e>20){$palabras.=$decenas[intdiv($e,10)].($e%10?'I'.$unidades[$e%10]:'').' ';}
      elseif($e>0){$palabras.=$unidades[$e].' ';}
      return 'SON: '.trim($palabras).' CON '.str_pad($dec,2,'0',STR_PAD_LEFT).'/100 SOLES';
  };
  ?>
  <div class="son-text"><?= $num_a_letras($v['total']) ?></div>

  <!-- TOTALES + CUENTAS -->
  <div class="totales-zona">
    <div class="cuentas-zona">
      <?php if(cfg($cfg,'plantilla_cuentas_bancarias')): ?>
      <?= nl2br(htmlspecialchars(cfg($cfg,'plantilla_cuentas_bancarias'))) ?>
      <?php endif; ?>
    </div>
    <table class="totales-tabla">
      <tr><td>OP. GRAVADAS: S/</td><td><?= number_format($v['subtotal']-($v['descuento']??0),2) ?></td></tr>
      <?php if(($v['descuento']??0)>0): ?><tr><td>DESCUENTO: S/</td><td>-<?= number_format($v['descuento'],2) ?></td></tr><?php endif; ?>
      <tr><td>OP. EXONERADAS:</td><td>0.00</td></tr>
      <tr><td>SUB TOTAL: S/</td><td><?= number_format($v['subtotal']-($v['descuento']??0),2) ?></td></tr>
      <tr><td>IGV 18.0%: S/</td><td><?= number_format($v['igv'],2) ?></td></tr>
      <tr class="total-fila"><td>TOTAL: S/</td><td><?= number_format($v['total'],2) ?></td></tr>
    </table>
  </div>

  <!-- MENSAJE INFERIOR -->
  <?php if(cfg($cfg,'plantilla_inferior_activo','1') && cfg($cfg,'plantilla_inferior')): ?>
  <div class="mensaje-inf"><?= nl2br(htmlspecialchars(cfg($cfg,'plantilla_inferior'))) ?></div>
  <?php endif; ?>

  <!-- QR -->
  <?php if(cfg($cfg,'comprobante_mostrar_qr','1')): ?>
  <div class="qr-zona">
    <img src="https://api.qrserver.com/v1/create-qr-code/?size=70x70&data=<?= urlencode($qr_data) ?>" alt="QR">
  </div>
  <?php endif; ?>

  <!-- DESPEDIDA -->
  <?php if(cfg($cfg,'plantilla_despedida_activo','1') && cfg($cfg,'plantilla_despedida')): ?>
  <div class="despedida"><?= htmlspecialchars(strtoupper(cfg($cfg,'plantilla_despedida'))) ?></div>
  <?php endif; ?>

  <!-- FOOTER LEGAL -->
  <div class="footer-text">
    USUARIO: <?= htmlspecialchars($v['vendedor']??'Sistema') ?> <?= date('d/m/Y H:i',strtotime($v['fecha'])) ?><br>
    Representación impresa de la <?= $tipo_label[$v['tipo_comprobante']]??'COMPROBANTE' ?>.<br>
    <?php if(cfg($cfg,'plantilla_inferior_activo','1') && cfg($cfg,'plantilla_inferior')): ?>
    <?= nl2br(htmlspecialchars(cfg($cfg,'plantilla_inferior'))) ?>
    <?php endif; ?>
  </div>

</div><!-- .page -->

<script>window.onload=()=>window.print();</script>
</body>
</html>
