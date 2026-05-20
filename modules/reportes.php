<?php
$page = 'reportes'; $pageTitle = 'Reportes';
require_once __DIR__ . '/../includes/header.php';
$db = getDB();

$mes  = $_GET['mes']  ?? date('Y-m');
$year = substr($mes,0,4); $month = substr($mes,5,2);
$inicio = "$year-$month-01";
$fin    = date('Y-m-t', strtotime($inicio));

// Filtro sede
$_sid = getSede(); $_all = verTodasSedes();
$_sv  = $_all ? "" : " AND v.sede_id=$_sid";
$_sc  = $_all ? "" : " AND c.sede_id=$_sid";   // citas c
$_scl = $_all ? "" : " AND cl.sede_id=$_sid";  // clientes cl
$_sm  = $_all ? "" : " AND m.sede_id=$_sid";   // mascotas m
$_scon= $_all ? "" : " AND con.sede_id=$_sid"; // consultas con

// KPIs del mes
$ventas_mes    = $db->prepare("SELECT COALESCE(SUM(total),0) FROM ventas v WHERE v.estado='pagado' AND v.fecha BETWEEN ? AND ?$_sv"); $ventas_mes->execute([$inicio.' 00:00:00',$fin.' 23:59:59']); $ventas_mes=$ventas_mes->fetchColumn();
$ventas_prod   = $db->prepare("SELECT COALESCE(SUM(vi.subtotal),0) FROM venta_items vi JOIN ventas v ON v.id=vi.venta_id WHERE vi.tipo='producto' AND v.estado='pagado' AND v.fecha BETWEEN ? AND ?$_sv"); $ventas_prod->execute([$inicio.' 00:00:00',$fin.' 23:59:59']); $ventas_prod=$ventas_prod->fetchColumn();
$pacientes_mes = $db->prepare("SELECT COUNT(DISTINCT con.mascota_id) FROM consultas con WHERE con.fecha BETWEEN ? AND ?$_scon"); $pacientes_mes->execute([$inicio.' 00:00:00',$fin.' 23:59:59']); $pacientes_mes=$pacientes_mes->fetchColumn();
$nuevos_cli    = $db->prepare("SELECT COUNT(*) FROM clientes cl WHERE cl.created_at BETWEEN ? AND ?$_scl"); $nuevos_cli->execute([$inicio.' 00:00:00',$fin.' 23:59:59']); $nuevos_cli=$nuevos_cli->fetchColumn();

// Mes anterior para deltas
$mes_ant  = date('Y-m', strtotime($inicio.' -1 month'));
$ini_ant  = $mes_ant.'-01'; $fin_ant = date('Y-m-t',strtotime($ini_ant));
$vt_ant   = $db->prepare("SELECT COALESCE(SUM(total),0) FROM ventas v WHERE v.estado='pagado' AND v.fecha BETWEEN ? AND ?$_sv"); $vt_ant->execute([$ini_ant.' 00:00:00',$fin_ant.' 23:59:59']); $vt_ant=$vt_ant->fetchColumn();
$delta_v  = $vt_ant>0 ? round(($ventas_mes-$vt_ant)/$vt_ant*100,1) : 0;

// Ventas diarias del mes
$ventas_diarias = $db->prepare("SELECT DATE(v.fecha) as dia, COALESCE(SUM(v.total),0) as total FROM ventas v WHERE v.estado='pagado' AND v.fecha BETWEEN ? AND ?$_sv GROUP BY DATE(v.fecha) ORDER BY dia");
$ventas_diarias->execute([$inicio.' 00:00:00',$fin.' 23:59:59']); $ventas_diarias=$ventas_diarias->fetchAll(PDO::FETCH_KEY_PAIR);
$max_diario = max(array_values($ventas_diarias) ?: [1]);

// Servicios más solicitados
$servicios_top = $db->prepare("SELECT tipo, COUNT(*) as cnt FROM citas c WHERE c.fecha BETWEEN ? AND ?$_sc GROUP BY tipo ORDER BY cnt DESC LIMIT 6"); $servicios_top->execute([$inicio,$fin]); $servicios_top=$servicios_top->fetchAll();
$max_srv = max(array_column($servicios_top,'cnt') ?: [1]);

// Productos más vendidos
$prods_top = $db->prepare("SELECT vi.descripcion, SUM(vi.cantidad) as qty, SUM(vi.subtotal) as total FROM venta_items vi JOIN ventas v ON v.id=vi.venta_id WHERE vi.tipo='producto' AND v.estado='pagado' AND v.fecha BETWEEN ? AND ?$_sv GROUP BY vi.referencia_id,vi.descripcion ORDER BY qty DESC LIMIT 8");
$prods_top->execute([$inicio.' 00:00:00',$fin.' 23:59:59']); $prods_top=$prods_top->fetchAll();

// Clientes más frecuentes
$clientes_top = $db->prepare("SELECT cl.nombre,cl.telefono,COUNT(DISTINCT con.id) as consultas, COALESCE(SUM(v.total),0) as gasto FROM clientes cl LEFT JOIN mascotas m ON m.cliente_id=cl.id$_sm LEFT JOIN consultas con ON con.mascota_id=m.id AND con.fecha BETWEEN ? AND ? LEFT JOIN ventas v ON v.cliente_id=cl.id AND v.estado='pagado' AND v.fecha BETWEEN ? AND ?$_sv GROUP BY cl.id ORDER BY consultas DESC, gasto DESC LIMIT 8");
$clientes_top->execute([$inicio.' 00:00:00',$fin.' 23:59:59',$inicio.' 00:00:00',$fin.' 23:59:59']); $clientes_top=$clientes_top->fetchAll();

// Veterinarios más activos (por sede de usuario)
$vets_stats = $db->prepare("SELECT u.nombre,COUNT(con.id) as atenciones FROM usuarios u LEFT JOIN consultas con ON con.veterinario_id=u.id AND con.fecha BETWEEN ? AND ? WHERE u.rol IN ('veterinario','admin') AND u.activo=1".($_all?"":" AND u.sede_id=$_sid")." GROUP BY u.id ORDER BY atenciones DESC");
$vets_stats->execute([$inicio.' 00:00:00',$fin.' 23:59:59']); $vets_stats=$vets_stats->fetchAll();
$max_vet = max(array_column($vets_stats,'atenciones') ?: [1]);

// Ventas por mes (últimos 6 meses)
$ventas_6m = [];
for ($i=5;$i>=0;$i--) {
    $m = date('Y-m',strtotime("-$i months"));
    $ini2=$m.'-01'; $fin2=date('Y-m-t',strtotime($ini2));
    $st=$db->prepare("SELECT COALESCE(SUM(total),0) FROM ventas v WHERE v.estado='pagado' AND v.fecha BETWEEN ? AND ?$_sv");
    $st->execute([$ini2.' 00:00:00',$fin2.' 23:59:59']);
    $ventas_6m[$m] = (float)$st->fetchColumn();
}
$max_6m = max(array_values($ventas_6m) ?: [1]);

$tipo_labels=['consulta'=>'Consultas','vacuna'=>'Vacunas','cirugia'=>'Cirugías','bano'=>'Baños','control'=>'Controles','grooming'=>'Grooming','emergencia'=>'Emergencias','hospitalizacion'=>'Hospit.'];
$tipo_colors=['consulta'=>'var(--teal)','vacuna'=>'var(--blue)','cirugia'=>'var(--red)','bano'=>'var(--amber)','control'=>'var(--purple)','grooming'=>'var(--green)','emergencia'=>'var(--red)','hospitalizacion'=>'var(--purple)'];
?>
<div class="page">
<!-- FILTRO MES -->
<div class="flex items-center justify-between mb-2">
  <div class="sec-title">Período: <?= date('F Y',strtotime($inicio)) ?></div>
  <div class="flex gap-1">
    <form method="GET" class="flex gap-1"><input type="hidden" name="p" value="reportes">
      <input class="form-input" type="month" name="mes" value="<?= $mes ?>" style="width:160px">
      <button type="submit" class="btn">Ver período</button>
    </form>
  </div>
</div>

<!-- KPIs -->
<div class="grid g4 mb-2">
  <div class="stat-card"><div class="stat-icon si-teal">💰</div><div class="stat-value">S/. <?= number_format($ventas_mes,0) ?></div><div class="stat-label">Ingresos del mes</div>
    <div class="stat-delta <?= $delta_v>=0?'delta-up':'delta-dn' ?>"><?= $delta_v>=0?'↑':'↓' ?> <?= abs($delta_v) ?>% vs mes anterior</div>
  </div>
  <div class="stat-card"><div class="stat-icon si-blue">🐾</div><div class="stat-value"><?= $pacientes_mes ?></div><div class="stat-label">Pacientes atendidos</div></div>
  <div class="stat-card"><div class="stat-icon si-amber">🛒</div><div class="stat-value">S/. <?= number_format($ventas_prod,0) ?></div><div class="stat-label">Venta de productos</div></div>
  <div class="stat-card"><div class="stat-icon si-teal">👤</div><div class="stat-value"><?= $nuevos_cli ?></div><div class="stat-label">Nuevos clientes</div></div>
</div>

<div class="grid g2 mb-2">
  <!-- VENTAS DIARIAS -->
  <div class="card">
    <div class="sec-header"><div><div class="sec-title">Ventas diarias</div><div class="sec-sub"><?= date('F Y',strtotime($inicio)) ?></div></div></div>
    <div style="display:flex;align-items:flex-end;gap:3px;height:120px;padding-top:8px;overflow-x:auto">
      <?php for($d=1;$d<=date('t',strtotime($inicio));$d++):
        $dia = sprintf("$year-$month-%02d",$d);
        $val = $ventas_diarias[$dia] ?? 0;
        $pct = $max_diario>0 ? max(4,round($val/$max_diario*110)) : 4;
        $isToday = $dia===date('Y-m-d');
      ?>
      <div style="flex:1;min-width:12px;display:flex;flex-direction:column;align-items:center;gap:2px">
        <?php if($val>0): ?><span style="font-size:8px;color:var(--text3);white-space:nowrap"><?= round($val) ?></span><?php endif; ?>
        <div style="width:100%;height:<?= $pct ?>px;border-radius:3px 3px 0 0;background:<?= $isToday?'var(--teal)':($val>0?'var(--teal-l)':'var(--bg3)') ?>;border:<?= $val>0&&!$isToday?'1px solid var(--teal)':'none' ?>"></div>
        <span style="font-size:8px;color:var(--text3)"><?= $d ?></span>
      </div>
      <?php endfor; ?>
    </div>
  </div>

  <!-- VENTAS 6 MESES -->
  <div class="card">
    <div class="sec-header"><div><div class="sec-title">Tendencia últimos 6 meses</div></div></div>
    <div style="display:flex;align-items:flex-end;gap:8px;height:120px;padding-top:8px">
      <?php foreach($ventas_6m as $mm=>$vv):
        $pct=max(4,round($vv/$max_6m*110)); $isCurr=$mm===$mes; ?>
      <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:3px">
        <span style="font-size:9px;color:var(--text3)">S/.<?= round($vv/1000,1) ?>k</span>
        <div style="width:100%;height:<?= $pct ?>px;border-radius:4px 4px 0 0;background:<?= $isCurr?'var(--teal)':'var(--teal-l)' ?>;border:<?= !$isCurr?'1px solid var(--teal)':'none' ?>"></div>
        <span style="font-size:9px;color:var(--text3)"><?= date('M',strtotime($mm.'-01')) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="grid g2 mb-2">
  <!-- SERVICIOS TOP -->
  <div class="card">
    <div class="sec-header"><div><div class="sec-title">Servicios más solicitados</div><div class="sec-sub"><?= date('F Y',strtotime($inicio)) ?></div></div></div>
    <?php foreach($servicios_top as $s): ?>
    <div style="margin-bottom:13px">
      <div class="flex justify-between mb-1"><span class="text-sm font-med"><?= $tipo_labels[$s['tipo']]??$s['tipo'] ?></span><span class="font-bold"><?= $s['cnt'] ?></span></div>
      <div class="progress-bar"><div class="progress-fill" style="width:<?= round($s['cnt']/$max_srv*100) ?>%;background:<?= $tipo_colors[$s['tipo']]??'var(--teal)' ?>"></div></div>
    </div>
    <?php endforeach; ?>
    <?php if(empty($servicios_top)): ?><div class="text-muted text-sm">Sin datos en este período.</div><?php endif; ?>
  </div>

  <!-- VETERINARIOS -->
  <div class="card">
    <div class="sec-header"><div class="sec-title">Atenciones por veterinario</div></div>
    <?php foreach($vets_stats as $v): $pct=$max_vet>0?round($v['atenciones']/$max_vet*100):0; ?>
    <div style="margin-bottom:13px">
      <div class="flex justify-between mb-1"><span class="text-sm font-med"><?= clean($v['nombre']) ?></span><span class="font-bold"><?= $v['atenciones'] ?></span></div>
      <div class="progress-bar"><div class="progress-fill" style="width:<?= $pct ?>%;background:var(--teal)"></div></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="grid g2">
  <!-- PRODUCTOS TOP -->
  <div class="card">
    <div class="sec-header"><div class="sec-title">Productos más vendidos</div></div>
    <div class="table-wrap"><table class="vtable"><thead><tr><th>Producto</th><th>Unidades</th><th>Total</th></tr></thead><tbody>
      <?php foreach($prods_top as $p): ?>
      <tr><td class="td-main"><?= clean($p['descripcion']) ?></td><td><?= $p['qty'] ?></td><td class="font-med">S/. <?= number_format($p['total'],2) ?></td></tr>
      <?php endforeach; ?>
      <?php if(empty($prods_top)): ?><tr><td colspan="3" class="text-muted text-center" style="padding:24px">Sin ventas de productos.</td></tr><?php endif; ?>
    </tbody></table></div>
  </div>

  <!-- CLIENTES TOP -->
  <div class="card">
    <div class="sec-header"><div class="sec-title">Clientes más frecuentes</div></div>
    <?php foreach($clientes_top as $c): if(!$c['consultas'] && !$c['gasto']) continue; ?>
    <div class="flex items-center gap-2" style="padding:9px 0;border-bottom:0.5px solid var(--border)">
      <div class="avatar" style="width:32px;height:32px;font-size:11px"><?= strtoupper(substr($c['nombre'],0,1).substr(strstr($c['nombre'],' ') ?: ' ',1,1)) ?></div>
      <div class="flex-1"><div class="text-sm font-med"><?= clean($c['nombre']) ?></div></div>
      <span class="badge b-teal"><?= $c['consultas'] ?> cons.</span>
      <span class="font-bold text-sm" style="color:var(--teal)">S/. <?= number_format($c['gasto'],0) ?></span>
    </div>
    <?php endforeach; ?>
    <?php $any=false; foreach($clientes_top as $c) if($c['consultas']||$c['gasto']){$any=true;break;} if(!$any): ?><div class="text-muted text-sm" style="padding:24px">Sin datos.</div><?php endif; ?>
  </div>
</div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
