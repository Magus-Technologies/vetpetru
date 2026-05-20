<?php
$page = 'recetas'; $pageTitle = 'Recetas Médicas';
require_once __DIR__ . '/../includes/header.php';
$db = getDB();

$action = $_GET['action'] ?? 'list';
$mascota_id = (int)($_GET['mascota_id'] ?? 0);
$msg = '';

// Datos
$mascotas_sel=$db->query("SELECT m.id,CONCAT(m.nombre,' (',c.nombre,')') as label FROM mascotas m JOIN clientes c ON c.id=m.cliente_id WHERE m.estado='activo' ORDER BY m.nombre")->fetchAll();
$vets_sel=$db->query("SELECT id,nombre FROM usuarios WHERE rol IN ('veterinario','admin') AND activo=1")->fetchAll();

$where="r.mascota_id IS NOT NULL"; $params=[];
if ($mascota_id){$where.=" AND r.mascota_id=?";$params[]=$mascota_id;}
// Filtro sede
try { $_r=$db->query("SHOW COLUMNS FROM `mascotas` LIKE 'sede_id'")->fetchAll(); if(!empty($_r)&&!verTodasSedes()){$where.=" AND m.sede_id=".getSede();} } catch(Exception $e){}
$recetas=$db->prepare("SELECT r.*,m.nombre as mascota,m.especie,u.nombre as vet,c.nombre as dueno,c.telefono,
  (SELECT COUNT(*) FROM receta_items ri WHERE ri.receta_id=r.id) as n_items
  FROM recetas r JOIN mascotas m ON m.id=r.mascota_id JOIN usuarios u ON u.id=r.veterinario_id
  JOIN clientes c ON c.id=m.cliente_id WHERE $where ORDER BY r.fecha DESC LIMIT 60");
$recetas->execute($params); $recetas=$recetas->fetchAll();

// Imprimir receta
if ($action==='imprimir' && isset($_GET['id'])) {
  $rec_id=(int)$_GET['id'];
  $rec=$db->prepare("SELECT r.*,m.nombre as mascota,m.especie,m.raza,m.peso,u.nombre as vet,c.nombre as dueno,c.telefono FROM recetas r JOIN mascotas m ON m.id=r.mascota_id JOIN usuarios u ON u.id=r.veterinario_id JOIN clientes c ON c.id=m.cliente_id WHERE r.id=?");
  $rec->execute([$rec_id]); $rec=$rec->fetch();
  $items=$db->prepare("SELECT * FROM receta_items WHERE receta_id=?"); $items->execute([$rec_id]); $items=$items->fetchAll();
  $cfg=$db->query("SELECT clave,valor FROM configuracion")->fetchAll(PDO::FETCH_KEY_PAIR);
  ?><!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Receta #<?= $rec_id ?></title>
  <style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:Arial,sans-serif;font-size:11pt;padding:20mm 18mm}
  .header{display:flex;justify-content:space-between;border-bottom:2px solid #0d9f7a;padding-bottom:12px;margin-bottom:12px}
  .logo-text{font-size:22px;font-weight:800;color:#0d9f7a}.num{font-size:13px;color:#555}
  .patient-box{background:#f8f9fa;border:1px solid #e2e5eb;border-radius:8px;padding:12px;margin-bottom:14px;display:grid;grid-template-columns:1fr 1fr}
  .field{margin-bottom:6px}.field label{font-size:9px;text-transform:uppercase;color:#888;font-weight:700;letter-spacing:.5px;display:block}
  .field span{font-size:11pt;font-weight:600;color:#0f172a}
  table{width:100%;border-collapse:collapse;margin-bottom:14px}
  th{background:#0d9f7a;color:#fff;padding:8px 10px;text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.5px}
  td{padding:9px 10px;border-bottom:1px solid #e2e5eb;font-size:10.5pt}
  .firma-line{margin-top:40px;padding-top:10px;border-top:1px solid #aaa;width:200px;text-align:center;font-size:9px;color:#888}
  .indic{background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:10px;font-size:10pt;margin-bottom:14px}
  @media print{@page{margin:15mm;size:A4}}
  </style></head><body>
  <div class="header">
    <div><div class="logo-text">🐾 VetPro</div><div class="num"><?= clean($cfg['nombre_clinica']??'Clínica Veterinaria') ?></div><div class="num"><?= clean($cfg['direccion_clinica']??'') ?></div></div>
    <div style="text-align:right"><div style="font-size:14px;font-weight:700;color:#0d9f7a">RECETA MÉDICA</div><div class="num">N° <?= str_pad($rec_id,6,'0',STR_PAD_LEFT) ?></div><div class="num">Fecha: <?= date('d/m/Y',strtotime($rec['fecha'])) ?></div></div>
  </div>
  <div class="patient-box">
    <div><div class="field"><label>Paciente</label><span><?= clean($rec['mascota']) ?></span></div><div class="field"><label>Especie / Raza</label><span><?= ucfirst($rec['especie']) ?><?= $rec['raza']?" — {$rec['raza']}":'' ?></span></div></div>
    <div><div class="field"><label>Propietario</label><span><?= clean($rec['dueno']) ?></span></div><div class="field"><label>Peso</label><span><?= $rec['peso']?$rec['peso'].' kg':'—' ?></span></div></div>
  </div>
  <table><thead><tr><th>#</th><th>Medicamento</th><th>Dosis</th><th>Frecuencia</th><th>Duración</th><th>Vía</th></tr></thead>
  <tbody><?php foreach($items as $i=>$it): ?>
  <tr><td><?= $i+1 ?></td><td><strong><?= clean($it['medicamento']) ?></strong></td><td><?= clean($it['dosis']) ?></td><td><?= clean($it['frecuencia']) ?></td><td><?= clean($it['duracion']) ?></td><td><?= clean($it['via']) ?></td></tr>
  <?php endforeach; ?></tbody></table>
  <?php if($rec['indicaciones']): ?><div class="indic"><strong>📋 Indicaciones:</strong><br><?= nl2br(clean($rec['indicaciones'])) ?></div><?php endif; ?>
  <div class="firma-line"><?= clean($rec['vet']) ?><br>Veterinario responsable</div>
  <script>window.onload=()=>window.print();</script></body></html><?php exit; }

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
