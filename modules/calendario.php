<?php
$page = 'calendario'; $pageTitle = 'Calendario';
require_once __DIR__ . '/../includes/header.php';
$db = getDB();

// Citas del mes actual para el calendario
$mes   = (int)($_GET['mes'] ?? date('n'));
$anio  = (int)($_GET['anio'] ?? date('Y'));
if ($mes<1)  { $mes=12; $anio--; }
if ($mes>12) { $mes=1;  $anio++; }

$mes2  = str_pad($mes,2,'0',STR_PAD_LEFT);
$primerDia = "$anio-$mes2-01";
$ultimoDia = date('Y-m-t', strtotime($primerDia));

// Citas del mes
$citas_mes = [];
try {
    $rows = $db->query("
        SELECT c.*,
               m.nombre as mascota, m.especie, m.foto,
               u.nombre as vet,
               cl.nombre as dueno, cl.telefono
        FROM citas c
        JOIN mascotas m ON m.id=c.mascota_id
        JOIN usuarios u ON u.id=c.veterinario_id
        JOIN clientes cl ON cl.id=m.cliente_id
        WHERE c.fecha BETWEEN '$primerDia' AND '$ultimoDia'
        ORDER BY c.fecha, c.hora ASC
    ")->fetchAll();
    foreach ($rows as $r) {
        $citas_mes[date('j', strtotime($r['fecha']))][] = $r;
    }
} catch(Exception $e) {}

// Citas del día seleccionado
$dia_sel = (int)($_GET['dia'] ?? date('j'));
if ($anio==date('Y') && $mes==(int)date('n') && !isset($_GET['dia'])) $dia_sel=(int)date('j');
$citas_dia = $citas_mes[$dia_sel] ?? [];

// Veterinarios para filtro
$vets = [];
try { $vets = $db->query("SELECT id,nombre FROM usuarios WHERE rol IN ('veterinario','admin') AND activo=1 ORDER BY nombre")->fetchAll(); } catch(Exception $e){}

// Estadísticas del mes
$total_mes  = array_sum(array_map('count', $citas_mes));
$dias_meses = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];

$estado_cfg = [
    'pendiente'  =>['color'=>'#f59e0b','bg'=>'#fef3c7','dot'=>'#f59e0b'],
    'confirmada' =>['color'=>'#3b82f6','bg'=>'#dbeafe','dot'=>'#3b82f6'],
    'atendida'   =>['color'=>'#10b981','bg'=>'#d1fae5','dot'=>'#10b981'],
    'cancelada'  =>['color'=>'#ef4444','bg'=>'#fee2e2','dot'=>'#ef4444'],
    'no_asistio' =>['color'=>'#94a3b8','bg'=>'#f1f5f9','dot'=>'#94a3b8'],
];
$tipo_icons=['consulta'=>'🩺','vacuna'=>'💉','control'=>'🔄','cirugia'=>'✂️','bano'=>'🛁','grooming'=>'✨','emergencia'=>'🚨','otro'=>'📋'];
$ei=['perro'=>'🐕','gato'=>'🐈','conejo'=>'🐰','ave'=>'🐦','reptil'=>'🦎','roedor'=>'🐭','otro'=>'🐾'];
?>

<style>
/* ── CALENDARIO LAYOUT ── */
.cal-wrap { display:grid; grid-template-columns:1fr 320px; gap:16px; align-items:start; }
@media(max-width:900px){ .cal-wrap { grid-template-columns:1fr; } }

/* Header del calendario */
.cal-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; }
.cal-nav-btn { width:36px; height:36px; border-radius:var(--r-sm); border:1.5px solid var(--border); background:var(--bg2); cursor:pointer; font-size:16px; display:flex; align-items:center; justify-content:center; transition:all .15s; color:var(--text2); }
.cal-nav-btn:hover { background:var(--primary-l); border-color:var(--primary); color:var(--primary); }
.cal-mes-titulo { font-size:18px; font-weight:800; color:var(--text); }

/* Grid del calendario */
.cal-grid-head { display:grid; grid-template-columns:repeat(7,1fr); gap:2px; margin-bottom:2px; }
.cal-dow { text-align:center; font-size:11px; font-weight:700; color:var(--text3); padding:6px 0; text-transform:uppercase; letter-spacing:.5px; }
.cal-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:2px; }
.cal-cell {
  min-height:86px; padding:6px; border-radius:8px; border:1.5px solid transparent;
  background:var(--bg2); cursor:pointer; transition:all .15s; position:relative;
  display:flex; flex-direction:column;
}
.cal-cell:hover { border-color:var(--primary); background:var(--primary-l); }
.cal-cell.otro-mes { opacity:.35; cursor:default; background:var(--bg); }
.cal-cell.otro-mes:hover { border-color:transparent; background:var(--bg); }
.cal-cell.hoy { border-color:var(--primary); background:var(--primary-l); }
.cal-cell.hoy .cal-num { background:var(--primary); color:#fff; }
.cal-cell.selected { border-color:var(--accent); background:rgba(99,102,241,.08); }
.cal-num { width:24px; height:24px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:700; color:var(--text2); margin-bottom:4px; flex-shrink:0; }
.cal-cell.hoy:not(.selected) .cal-num { background:var(--primary); color:#fff; }
.cal-cell.selected .cal-num { background:var(--accent); color:#fff; }

/* Chips de citas en el día */
.cal-chip { font-size:10px; font-weight:600; padding:2px 5px; border-radius:4px; margin-bottom:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:100%; }
.cal-more { font-size:9px; color:var(--text3); font-weight:600; margin-top:1px; }

/* Panel derecho — citas del día */
.cal-panel { background:var(--bg2); border:1px solid var(--border); border-radius:14px; overflow:hidden; position:sticky; top:80px; }
.cal-panel-head { padding:14px 16px; border-bottom:1px solid var(--border); background:var(--bg3); }
.cal-panel-titulo { font-size:14px; font-weight:700; color:var(--text); }
.cal-panel-sub { font-size:11px; color:var(--text3); margin-top:2px; }
.cal-cita-item { padding:12px 16px; border-bottom:1px solid var(--border); transition:background .1s; cursor:pointer; }
.cal-cita-item:hover { background:var(--bg3); }
.cal-cita-item:last-child { border-bottom:none; }
.cal-cita-hora { font-size:13px; font-weight:800; color:var(--primary); font-variant-numeric:tabular-nums; }
.cal-cita-mascota { font-size:13px; font-weight:600; color:var(--text); }
.cal-cita-info { font-size:11px; color:var(--text3); margin-top:2px; }

/* Barra de estadísticas */
.cal-stats { display:flex; gap:8px; margin-bottom:14px; flex-wrap:wrap; }
.cal-stat { flex:1; min-width:80px; background:var(--bg2); border:1px solid var(--border); border-radius:10px; padding:10px 12px; text-align:center; }
.cal-stat-val { font-size:18px; font-weight:800; color:var(--text); }
.cal-stat-lbl { font-size:10px; color:var(--text3); margin-top:2px; }
</style>

<div class="page">

<!-- Header -->
<div class="sec-header mb-3">
  <div>
    <div class="page-title">📅 Calendario de Citas</div>
    <div class="page-desc"><?= $dias_meses[$mes] ?> <?= $anio ?> · <?= $total_mes ?> citas programadas</div>
  </div>
  <div class="flex gap-2">
    <a href="?p=citas&action=nueva" class="btn btn-primary btn-sm">＋ Nueva cita</a>
    <a href="?p=citas" class="btn btn-ghost btn-sm">📋 Lista</a>
  </div>
</div>

<!-- Stats del mes -->
<div class="cal-stats">
  <?php
  $por_estado = [];
  foreach ($citas_mes as $dia_citas) {
      foreach ($dia_citas as $c) $por_estado[$c['estado']] = ($por_estado[$c['estado']] ?? 0) + 1;
  }
  $stats_show = ['pendiente'=>'📋 Pendientes','confirmada'=>'✅ Confirmadas','atendida'=>'🩺 Atendidas','cancelada'=>'❌ Canceladas'];
  foreach ($stats_show as $est => $lbl): $n = $por_estado[$est] ?? 0; $ec = $estado_cfg[$est]; ?>
  <div class="cal-stat" style="border-color:<?= $ec['dot'] ?>22">
    <div class="cal-stat-val" style="color:<?= $ec['dot'] ?>"><?= $n ?></div>
    <div class="cal-stat-lbl"><?= $lbl ?></div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Calendario + Panel -->
<div class="cal-wrap">

  <!-- CALENDARIO -->
  <div>
    <!-- Navegación mes -->
    <div class="cal-header">
      <div class="flex gap-2 items-center">
        <a href="?p=calendario&mes=<?= $mes==1?12:$mes-1 ?>&anio=<?= $mes==1?$anio-1:$anio ?>" class="cal-nav-btn">‹</a>
        <a href="?p=calendario&mes=<?= $mes==12?1:$mes+1 ?>&anio=<?= $mes==12?$anio+1:$anio ?>" class="cal-nav-btn">›</a>
      </div>
      <div class="cal-mes-titulo"><?= $dias_meses[$mes] ?> <?= $anio ?></div>
      <a href="?p=calendario&mes=<?= date('n') ?>&anio=<?= date('Y') ?>&dia=<?= date('j') ?>" class="btn btn-sm btn-ghost">Hoy</a>
    </div>

    <!-- Días de la semana -->
    <div class="cal-grid-head">
      <?php foreach(['Lun','Mar','Mié','Jue','Vie','Sáb','Dom'] as $d): ?>
      <div class="cal-dow"><?= $d ?></div>
      <?php endforeach; ?>
    </div>

    <!-- Celdas del mes -->
    <?php
    // Calcular inicio de la grilla (Lunes=1)
    $inicio = date('N', strtotime($primerDia)); // 1=Lun, 7=Dom
    $dias_en_mes = (int)date('t', strtotime($primerDia));
    $mes_ant = $mes==1?12:$mes-1;
    $anio_ant = $mes==1?$anio-1:$anio;
    $dias_mes_ant = (int)date('t', mktime(0,0,0,$mes_ant,1,$anio_ant));
    $hoy_num = (int)date('j');
    $hoy_mes = (int)date('n');
    $hoy_anio = (int)date('Y');
    ?>
    <div class="cal-grid">
      <?php
      $celdas = 35; // 5 filas × 7
      for($i=1; $i<=$celdas; $i++):
        $offset = $i - $inicio;
        $es_mes_actual = $offset >= 1 && $offset <= $dias_en_mes;
        $num = $es_mes_actual ? $offset : ($offset < 1 ? $dias_mes_ant + $offset : $offset - $dias_en_mes);
        $es_hoy = $es_mes_actual && $num==$hoy_num && $mes==$hoy_mes && $anio==$hoy_anio;
        $es_sel = $es_mes_actual && $num==$dia_sel;
        $citas_celda = $es_mes_actual ? ($citas_mes[$num] ?? []) : [];
        $dow = ($i-1)%7; // 0=Lun ... 6=Dom
        $es_finde = $dow >= 5;
        $url_dia = "?p=calendario&mes=$mes&anio=$anio&dia=$num";
      ?>
      <div class="cal-cell <?= !$es_mes_actual?'otro-mes':'' ?> <?= $es_hoy?'hoy':'' ?> <?= $es_sel&&!$es_hoy?'selected':'' ?>"
           <?php if($es_mes_actual): ?>
           onclick="selDia(<?= $num ?>)"
           ondblclick="agendarCita('<?= sprintf('%04d-%02d-%02d',$anio,$mes,$num) ?>')"
           title="Clic: ver citas · Doble clic: agendar"
           <?php endif; ?>
           style="<?= $es_finde&&$es_mes_actual?'background:rgba(99,102,241,.04)':'' ?>;user-select:none">
        <div class="cal-num" style="<?= $es_finde&&!$es_hoy&&!$es_sel?'color:var(--accent)':'' ?>"><?= $num ?></div>
        <?php
        $max_chips = 2;
        $chips_shown = 0;
        foreach($citas_celda as $cc):
          if($chips_shown >= $max_chips) break;
          $ec2 = $estado_cfg[$cc['estado']] ?? ['bg'=>'#f1f5f9','color'=>'#475569'];
          $ti = $tipo_icons[$cc['tipo_servicio']??'otro'] ?? '📋';
        ?>
        <div class="cal-chip" style="background:<?= $ec2['bg'] ?>;color:<?= $ec2['color'] ?>">
          <?= $ti ?> <?= substr($cc['hora'],0,5) ?> <?= clean(explode(' ',$cc['mascota'])[0]) ?>
        </div>
        <?php $chips_shown++; endforeach; ?>
        <?php
        $resto = count($citas_celda) - $max_chips;
        if($resto > 0): ?>
        <div class="cal-more">+<?= $resto ?> más</div>
        <?php endif; ?>
      </div>
      <?php endfor; ?>
    </div>

    <!-- Leyenda -->
    <div style="display:flex;gap:14px;margin-top:12px;flex-wrap:wrap;align-items:center">
      <?php foreach($estado_cfg as $est=>$ec): ?>
      <div style="display:flex;align-items:center;gap:5px;font-size:11px;color:var(--text3)">
        <div style="width:8px;height:8px;border-radius:2px;background:<?= $ec['dot'] ?>"></div>
        <?= ucfirst($est) ?>
      </div>
      <?php endforeach; ?>
      <div style="margin-left:auto;font-size:11px;color:var(--text3);display:flex;align-items:center;gap:5px">
        <div style="width:10px;height:10px;border-radius:50%;background:var(--primary)"></div> Hoy
        &nbsp;·&nbsp;
        <span style="background:var(--primary-l);color:var(--primary-d);padding:2px 8px;border-radius:6px;font-weight:600">💡 Doble clic en un día para agendar</span>
      </div>
    </div>
  </div>

  <!-- PANEL DERECHO: citas del día seleccionado -->
  <div class="cal-panel">
    <div class="cal-panel-head">
      <div class="cal-panel-titulo">
        📋 <?= $dia_sel ?> de <?= $dias_meses[$mes] ?>
      </div>
      <div class="cal-panel-sub">
        <?= count($citas_dia) ?> cita<?= count($citas_dia)!=1?'s':'' ?> · <?= $dias_meses[$mes] ?> <?= $anio ?>
      </div>
    </div>

    <?php if(empty($citas_dia)): ?>
    <div style="padding:40px 20px;text-align:center;color:var(--text3)">
      <div style="font-size:36px;margin-bottom:10px;opacity:.3">📅</div>
      <div style="font-size:13px;font-weight:600;margin-bottom:6px">Sin citas este día</div>
      <a href="?p=citas&action=nueva" class="btn btn-primary btn-sm">＋ Agendar cita</a>
    </div>
    <?php else: ?>
    <?php foreach($citas_dia as $c):
      $ec = $estado_cfg[$c['estado']] ?? ['bg'=>'#f1f5f9','color'=>'#475569','dot'=>'#94a3b8'];
      $foto = !empty($c['foto']) && file_exists(UPLOADS_PATH.'/'.$c['foto']) ? BASE_URL.'/public/uploads/'.$c['foto'] : null;
      $tel = preg_replace('/[^0-9]/','',ltrim($c['telefono']??'','+'));
      if(strlen($tel)<11) $tel='51'.$tel;
      $ti = $tipo_icons[$c['tipo_servicio']??'otro'] ?? '📋';
    ?>
    <div class="cal-cita-item" onclick="window.location.href='?p=citas&action=editar&id=<?= $c['id'] ?>'">
      <div style="display:flex;align-items:center;gap:10px">
        <!-- Foto o emoji -->
        <?php if($foto): ?>
        <img src="<?= $foto ?>" style="width:38px;height:38px;border-radius:9px;object-fit:cover;border:1.5px solid var(--border);flex-shrink:0">
        <?php else: ?>
        <div style="width:38px;height:38px;border-radius:9px;background:var(--primary-l);display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0"><?= $ei[$c['especie']]??'🐾' ?></div>
        <?php endif; ?>
        <div style="flex:1;min-width:0">
          <div style="display:flex;align-items:center;gap:6px">
            <div class="cal-cita-hora"><?= substr($c['hora'],0,5) ?></div>
            <span style="background:<?= $ec['bg'] ?>;color:<?= $ec['color'] ?>;padding:1px 7px;border-radius:999px;font-size:10px;font-weight:700"><?= ucfirst($c['estado']) ?></span>
          </div>
          <div class="cal-cita-mascota"><?= $ti ?> <?= clean($c['mascota']) ?></div>
          <div class="cal-cita-info">👤 <?= clean($c['dueno']) ?> · 🩺 <?= clean(explode(' ',$c['vet']??'')[0]) ?></div>
          <?php if($c['duracion_minutos']??0): ?>
          <div class="cal-cita-info">⏱ <?= $c['duracion_minutos'] ?> min</div>
          <?php endif; ?>
        </div>
        <!-- Acciones rápidas -->
        <div style="display:flex;flex-direction:column;gap:4px;flex-shrink:0">
          <a href="https://wa.me/<?= $tel ?>" target="_blank" onclick="event.stopPropagation()"
             style="width:28px;height:28px;border-radius:7px;background:#dcfce7;display:flex;align-items:center;justify-content:center;font-size:13px;text-decoration:none"
             title="WhatsApp">💬</a>
          <a href="?p=citas&action=editar&id=<?= $c['id'] ?>" onclick="event.stopPropagation()"
             style="width:28px;height:28px;border-radius:7px;background:var(--bg3);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:12px;text-decoration:none"
             title="Editar">✏️</a>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
    <!-- Botón agregar cita en este día -->
    <div style="padding:10px 16px;border-top:1px solid var(--border);background:var(--bg3)">
      <a href="?p=citas&action=nueva" class="btn btn-primary btn-sm" style="width:100%;justify-content:center">
        ＋ Agendar en este día
      </a>
    </div>
    <?php endif; ?>
  </div>

</div><!-- .cal-wrap -->
</div><!-- .page -->

<script>
// Clic simple: navegar al día seleccionado
function selDia(num) {
  var url = '?p=calendario&mes=<?= $mes ?>&anio=<?= $anio ?>&dia=' + num;
  window.location.href = url;
}

// Doble clic: abrir formulario nueva cita con fecha pre-rellena
function agendarCita(fecha) {
  window.location.href = '?p=citas&action=nueva&fecha=' + fecha;
}

// Marcar celda activa al hover sin navegar
document.querySelectorAll('.cal-cell:not(.otro-mes)').forEach(function(cell) {
  cell.addEventListener('mouseenter', function() {
    if (!this.classList.contains('selected') && !this.classList.contains('hoy')) {
      this.style.borderColor = 'var(--primary)';
      this.style.background  = 'var(--primary-l)';
    }
  });
  cell.addEventListener('mouseleave', function() {
    if (!this.classList.contains('selected') && !this.classList.contains('hoy')) {
      this.style.borderColor = '';
      this.style.background  = '';
    }
  });
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
