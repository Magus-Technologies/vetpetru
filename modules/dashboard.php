<?php
$page = 'dashboard'; $pageTitle = 'Dashboard';
require_once __DIR__ . '/../includes/header.php';
$db = getDB();

// KPIs del día
$hoy = date('Y-m-d');
$st = $db->prepare("SELECT COUNT(*) FROM citas WHERE fecha = ? AND estado IN ('pendiente','confirmada')");
$st->execute([$hoy]); $citas_hoy = $st->fetchColumn();

$st = $db->prepare("SELECT COALESCE(SUM(total),0) FROM ventas WHERE DATE(fecha) = ? AND estado='pagado'");
$st->execute([$hoy]); $ingreso_hoy = $st->fetchColumn();

$st = $db->prepare("SELECT COUNT(*) FROM mascotas WHERE DATE(created_at) = ?");
$st->execute([$hoy]); $nuevas_mascotas = $st->fetchColumn();

$st = $db->prepare("SELECT COUNT(*) FROM productos WHERE stock <= stock_minimo AND activo=1");
$st->execute(); $stock_critico = $st->fetchColumn();

// Citas de hoy
$st = $db->prepare("
  SELECT c.*, m.nombre as mascota, m.especie, u.nombre as veterinario, cl.nombre as dueno
  FROM citas c
  JOIN mascotas m ON m.id=c.mascota_id
  JOIN usuarios u ON u.id=c.veterinario_id
  JOIN clientes cl ON cl.id=m.cliente_id
  WHERE c.fecha = ? ORDER BY c.hora ASC LIMIT 6
");
$st->execute([$hoy]); $citas = $st->fetchAll();

// Ventas últimos 7 días
$st = $db->prepare("
  SELECT DATE(fecha) as dia, COALESCE(SUM(total),0) as total
  FROM ventas WHERE fecha >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND estado='pagado'
  GROUP BY DATE(fecha) ORDER BY dia ASC
");
$st->execute(); $ventas7 = $st->fetchAll(PDO::FETCH_KEY_PAIR);

// Alertas vacunas
$st = $db->prepare("
  SELECT v.*, m.nombre as mascota_nombre, cl.nombre as dueno, cl.telefono
  FROM vacunas v JOIN mascotas m ON m.id=v.mascota_id JOIN clientes cl ON cl.id=m.cliente_id
  WHERE v.proxima_dosis <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
  ORDER BY v.proxima_dosis ASC LIMIT 5
");
$st->execute(); $vacunas_alerta = $st->fetchAll();

// Servicios del mes
$st = $db->prepare("
  SELECT tipo, COUNT(*) as cnt FROM citas
  WHERE MONTH(fecha)=MONTH(CURDATE()) AND YEAR(fecha)=YEAR(CURDATE())
  GROUP BY tipo ORDER BY cnt DESC
");
$st->execute(); $servicios_mes = $st->fetchAll();

$tipo_labels = ['consulta'=>'Consultas','vacuna'=>'Vacunas','cirugia'=>'Cirugías','bano'=>'Baños','control'=>'Controles','grooming'=>'Grooming','emergencia'=>'Emergencias'];
$tipo_colors = ['consulta'=>'var(--teal)','vacuna'=>'var(--blue)','cirugia'=>'var(--red)','bano'=>'var(--amber)','control'=>'var(--purple)','grooming'=>'var(--green)','emergencia'=>'var(--red-d)'];
$max_srv = max(array_column($servicios_mes,'cnt') ?: [1]);

$especie_icons = ['perro'=>'🐕','gato'=>'🐈','conejo'=>'🐰','ave'=>'🐦','reptil'=>'🦎','roedor'=>'🐭','otro'=>'🐾'];
$estado_badge = ['pendiente'=>'b-gray','confirmada'=>'b-blue','atendida'=>'b-teal','cancelada'=>'b-red','no_asistio'=>'b-amber'];
$tipo_badge = ['consulta'=>'b-teal','vacuna'=>'b-blue','cirugia'=>'b-red','bano'=>'b-amber','control'=>'b-purple','grooming'=>'b-green','emergencia'=>'b-red'];
?>

<div class="page">

<!-- QUICK ACTIONS -->
<div class="grid g4 mb-2">
  <a href="?p=citas&action=nueva" class="card card-sm flex items-center gap-2" style="text-decoration:none;cursor:pointer;transition:transform .15s" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform=''">
    <div class="stat-icon si-teal" style="margin:0">📅</div>
    <div><div class="font-bold text-sm">Nueva Cita</div><div class="text-xs text-muted">Agendar atención</div></div>
  </a>
  <a href="?p=clientes&action=nuevo" class="card card-sm flex items-center gap-2" style="text-decoration:none;cursor:pointer;transition:transform .15s" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform=''">
    <div class="stat-icon si-blue" style="margin:0">👤</div>
    <div><div class="font-bold text-sm">Nuevo Cliente</div><div class="text-xs text-muted">Registrar dueño</div></div>
  </a>
  <a href="?p=historial&action=nueva" class="card card-sm flex items-center gap-2" style="text-decoration:none;cursor:pointer;transition:transform .15s" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform=''">
    <div class="stat-icon si-purple" style="margin:0">🏥</div>
    <div><div class="font-bold text-sm">Nueva Consulta</div><div class="text-xs text-muted">Historia clínica</div></div>
  </a>
  <a href="?p=facturacion&action=nueva" class="card card-sm flex items-center gap-2" style="text-decoration:none;cursor:pointer;transition:transform .15s" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform=''">
    <div class="stat-icon si-amber" style="margin:0">🧾</div>
    <div><div class="font-bold text-sm">Nueva Venta</div><div class="text-xs text-muted">Facturar servicio</div></div>
  </a>
</div>

<!-- KPI STATS -->
<div class="grid g4 mb-2">
  <div class="stat-card">
    <div class="stat-icon si-teal">📅</div>
    <div class="stat-value"><?= $citas_hoy ?></div>
    <div class="stat-label">Citas pendientes hoy</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon si-teal">💰</div>
    <div class="stat-value"><?= formatMoney($ingreso_hoy) ?></div>
    <div class="stat-label">Ingresos del día</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon si-blue">🐾</div>
    <div class="stat-value"><?= $nuevas_mascotas ?></div>
    <div class="stat-label">Nuevos pacientes hoy</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon <?= $stock_critico>0?'si-red':'si-green' ?>">💊</div>
    <div class="stat-value"><?= $stock_critico ?></div>
    <div class="stat-label">Productos en stock crítico</div>
    <?php if($stock_critico>0): ?><div class="stat-delta delta-dn">↓ Requiere reposición</div><?php endif; ?>
  </div>
</div>

<!-- ALERTS -->
<?php if($stock_critico > 0): ?>
<div class="alert alert-warn mb-2">
  <span>⚠️</span>
  <div><strong><?= $stock_critico ?> producto(s) bajo stock mínimo.</strong> <a href="?p=farmacia" style="color:var(--amber-d)">Ver inventario →</a></div>
</div>
<?php endif; ?>
<?php if(count($vacunas_alerta) > 0): ?>
<div class="alert alert-info mb-2">
  <span>💉</span>
  <div><strong><?= count($vacunas_alerta) ?> vacuna(s) por vencer o vencidas.</strong> <a href="?p=vacunas" style="color:var(--blue-d)">Ver vacunas →</a></div>
</div>
<?php endif; ?>

<div class="grid g2">
  <!-- CITAS HOY -->
  <div>
    <div class="card">
      <div class="sec-header">
        <div><div class="sec-title">Citas de hoy</div><div class="sec-sub"><?= date('d/m/Y') ?></div></div>
        <a href="?p=citas" class="btn btn-sm">Ver todas →</a>
      </div>
      <?php if(empty($citas)): ?>
        <div class="text-center text-muted" style="padding:32px 0">No hay citas programadas para hoy.</div>
      <?php endif; ?>
      <?php foreach($citas as $c): ?>
      <div class="flex items-center gap-2" style="padding:10px 0;border-bottom:1px solid var(--border)">
        <span style="font-size:11px;font-weight:700;color:var(--text3);min-width:42px"><?= substr($c['hora'],0,5) ?></span>
        <span style="font-size:18px"><?= $especie_icons[$c['especie']] ?? '🐾' ?></span>
        <div class="flex-1 truncate">
          <div class="font-bold text-sm truncate"><?= clean($c['mascota']) ?></div>
          <div class="text-xs text-muted"><?= clean($c['dueno']) ?> · <?= clean($c['veterinario']) ?></div>
        </div>
        <span class="badge <?= $tipo_badge[$c['tipo']] ?? 'b-gray' ?>"><?= $tipo_labels[$c['tipo']] ?? $c['tipo'] ?></span>
        <span class="badge <?= $estado_badge[$c['estado']] ?? 'b-gray' ?>"><?= ucfirst($c['estado']) ?></span>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- VACUNAS ALERTA -->
    <?php if(!empty($vacunas_alerta)): ?>
    <div class="card mt-2">
      <div class="sec-header">
        <div class="sec-title">Vacunas por vencer</div>
        <a href="?p=vacunas" class="btn btn-sm">Ver todas</a>
      </div>
      <?php foreach($vacunas_alerta as $v): 
        $dias = (strtotime($v['proxima_dosis']) - time()) / 86400;
        $vencida = $dias < 0;
      ?>
      <div class="flex items-center gap-2" style="padding:9px 0;border-bottom:1px solid var(--border)">
        <span style="font-size:16px">💉</span>
        <div class="flex-1">
          <div class="font-bold text-sm"><?= clean($v['mascota_nombre']) ?></div>
          <div class="text-xs text-muted"><?= clean($v['tipo_vacuna']) ?> · <?= clean($v['dueno']) ?></div>
        </div>
        <span class="badge <?= $vencida ? 'b-red' : 'b-amber' ?>"><?= $vencida ? 'Vencida' : 'En '.ceil($dias).'d' ?></span>
        <a href="<?= BASE_URL ?>/index.php?p=whatsapp&tipo=vacuna&mascota_id=<?= $v['mascota_id'] ?>" class="btn btn-xs b-wa btn-wa" title="Enviar recordatorio WhatsApp">💬</a>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- GRÁFICOS -->
  <div>
    <div class="card mb-2">
      <div class="sec-header">
        <div><div class="sec-title">Ingresos — últimos 7 días</div></div>
      </div>
      <div style="display:flex;align-items:flex-end;gap:6px;height:110px;padding-top:6px">
        <?php
        for ($i=6; $i>=0; $i--) {
          $dia = date('Y-m-d', strtotime("-$i days"));
          $val = $ventas7[$dia] ?? 0;
          $max = max(array_values($ventas7) ?: [1]);
          $pct = $max > 0 ? round($val/$max*100) : 0;
          $label = $i===0 ? 'Hoy' : date('d/m', strtotime($dia));
          $isToday = $i===0;
        ?>
        <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:3px">
          <span style="font-size:9px;color:var(--text3)">S/.<?= round($val) ?></span>
          <div style="width:100%;height:<?= max(4,$pct) ?>px;border-radius:4px 4px 0 0;background:<?= $isToday?'var(--teal)':'var(--teal-l)' ?>;border:<?= $isToday?'none':'1px solid var(--teal)' ?>"></div>
          <span style="font-size:9px;color:var(--text3)"><?= $label ?></span>
        </div>
        <?php } ?>
      </div>
    </div>

    <div class="card mb-2">
      <div class="sec-header"><div class="sec-title">Servicios del mes</div></div>
      <?php foreach($servicios_mes as $s): ?>
      <div style="margin-bottom:12px">
        <div class="flex justify-between mb-1">
          <span class="text-sm font-med"><?= $tipo_labels[$s['tipo']] ?? $s['tipo'] ?></span>
          <span class="text-sm font-bold"><?= $s['cnt'] ?></span>
        </div>
        <div class="progress-bar">
          <div class="progress-fill" style="width:<?= round($s['cnt']/$max_srv*100) ?>%;background:<?= $tipo_colors[$s['tipo']] ?? 'var(--teal)' ?>"></div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if(empty($servicios_mes)): ?>
        <div class="text-muted text-sm">Sin datos este mes.</div>
      <?php endif; ?>
    </div>
  </div>
</div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
