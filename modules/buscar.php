<?php
$page = 'buscar'; $pageTitle = 'Búsqueda';
require_once __DIR__ . '/../includes/header.php';
$db = getDB();
$q = trim($_GET['q'] ?? '');
$resultados = ['clientes'=>[], 'mascotas'=>[], 'consultas'=>[]];
if (strlen($q) >= 2) {
    $like = "%$q%";
    $st=$db->prepare("SELECT id,nombre,telefono,email,dni FROM clientes WHERE activo=1 AND (nombre LIKE ? OR telefono LIKE ? OR dni LIKE ? OR email LIKE ?) LIMIT 10");
    $st->execute([$like,$like,$like,$like]); $resultados['clientes']=$st->fetchAll();
    $st=$db->prepare("SELECT m.id,m.nombre,m.especie,m.raza,c.nombre as dueno FROM mascotas m JOIN clientes c ON c.id=m.cliente_id WHERE m.estado='activo' AND (m.nombre LIKE ? OR m.raza LIKE ? OR c.nombre LIKE ?) LIMIT 10");
    $st->execute([$like,$like,$like]); $resultados['mascotas']=$st->fetchAll();
    $st=$db->prepare("SELECT con.id,con.diagnostico,con.fecha,m.nombre as mascota,cl.nombre as dueno FROM consultas con JOIN mascotas m ON m.id=con.mascota_id JOIN clientes cl ON cl.id=m.cliente_id WHERE con.diagnostico LIKE ? OR con.sintomas LIKE ? LIMIT 10");
    $st->execute([$like,$like]); $resultados['consultas']=$st->fetchAll();
}
$total = array_sum(array_map('count',$resultados));
$ei=['perro'=>'🐕','gato'=>'🐈','conejo'=>'🐰','ave'=>'🐦','reptil'=>'🦎','roedor'=>'🐭','otro'=>'🐾'];
?>
<div class="page">
<?php if($q): ?>
<div class="sec-header mb-2"><div><div class="sec-title">Resultados para "<?= clean($q) ?>"</div><div class="sec-sub"><?= $total ?> resultado(s)</div></div></div>
<?php if(!empty($resultados['clientes'])): ?>
<div class="card mb-2"><div class="sec-title mb-2">👥 Clientes (<?= count($resultados['clientes']) ?>)</div>
  <?php foreach($resultados['clientes'] as $c): ?>
  <div class="flex items-center gap-2" style="padding:9px 0;border-bottom:0.5px solid var(--border)">
    <div class="avatar" style="width:32px;height:32px;font-size:11px"><?= strtoupper(substr($c['nombre'],0,1).substr(strstr($c['nombre'],' ')??'',1,1)) ?></div>
    <div class="flex-1"><div class="font-med"><?= clean($c['nombre']) ?></div><div class="text-xs text-muted"><?= clean($c['telefono']) ?> · <?= clean($c['email']??'') ?></div></div>
    <a href="?p=clientes&action=editar&id=<?= $c['id'] ?>" class="btn btn-xs">Ver ficha</a>
    <a href="?p=mascotas&cliente_id=<?= $c['id'] ?>" class="btn btn-xs">Mascotas</a>
  </div>
  <?php endforeach; ?></div>
<?php endif; ?>
<?php if(!empty($resultados['mascotas'])): ?>
<div class="card mb-2"><div class="sec-title mb-2">🐾 Mascotas (<?= count($resultados['mascotas']) ?>)</div>
  <?php foreach($resultados['mascotas'] as $m): ?>
  <div class="flex items-center gap-2" style="padding:9px 0;border-bottom:0.5px solid var(--border)">
    <span style="font-size:20px"><?= $ei[$m['especie']]??'🐾' ?></span>
    <div class="flex-1"><div class="font-med"><?= clean($m['nombre']) ?></div><div class="text-xs text-muted"><?= clean($m['raza']??'') ?> · Dueño: <?= clean($m['dueno']) ?></div></div>
    <a href="?p=historial&mascota_id=<?= $m['id'] ?>" class="btn btn-xs">Historia clínica</a>
    <a href="?p=citas&action=nueva" class="btn btn-xs btn-primary">Nueva cita</a>
  </div>
  <?php endforeach; ?></div>
<?php endif; ?>
<?php if(!empty($resultados['consultas'])): ?>
<div class="card"><div class="sec-title mb-2">🏥 Consultas (<?= count($resultados['consultas']) ?>)</div>
  <?php foreach($resultados['consultas'] as $c): ?>
  <div class="flex items-center gap-2" style="padding:9px 0;border-bottom:0.5px solid var(--border)">
    <span class="badge b-teal"><?= date('d/m/Y',strtotime($c['fecha'])) ?></span>
    <div class="flex-1"><div class="font-med"><?= clean($c['mascota']) ?> · <?= clean($c['dueno']) ?></div><div class="text-xs text-muted"><?= clean(substr($c['diagnostico'],0,80)) ?>...</div></div>
    <a href="?p=historial&mascota_id=<?= $c['id'] ?>" class="btn btn-xs">Ver</a>
  </div>
  <?php endforeach; ?></div>
<?php endif; ?>
<?php if($total===0): ?><div class="card text-center text-muted" style="padding:48px">No se encontraron resultados para "<?= clean($q) ?>".</div><?php endif; ?>
<?php else: ?>
<div class="card text-center text-muted" style="padding:64px"><div style="font-size:40px;margin-bottom:12px">🔍</div><div>Escribe en la barra de búsqueda para encontrar clientes, mascotas o consultas.</div></div>
<?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
