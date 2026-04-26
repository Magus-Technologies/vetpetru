<?php
$page = 'caja'; $pageTitle = 'Caja / Finanzas';
require_once __DIR__ . '/../includes/header.php';
$db = getDB();
$action = $_GET['action'] ?? 'list';
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pa = $_POST['action'] ?? '';
    if ($pa === 'abrir') {
        $db->prepare("INSERT INTO cajas (sede_id,usuario_id,monto_apertura,estado) VALUES (?,?,?,'abierta')")->execute([$user['sede_id']??1,$user['id'],(float)$_POST['monto_apertura']]);
        $msg='abierta'; $action='list';
    }
    if ($pa === 'cerrar') {
        $db->prepare("UPDATE cajas SET estado='cerrada',fecha_cierre=NOW(),monto_cierre=? WHERE id=?")->execute([(float)$_POST['monto_cierre'],(int)$_POST['caja_id']]);
        $msg='cerrada'; $action='list';
    }
    if ($pa === 'movimiento') {
        $caja_id = (int)$_POST['caja_id'];
        $db->prepare("INSERT INTO movimientos_caja (caja_id,usuario_id,tipo,concepto,monto,metodo_pago,categoria) VALUES (?,?,?,?,?,?,?)")->execute([$caja_id,$user['id'],$_POST['tipo'],trim($_POST['concepto']),(float)$_POST['monto'],$_POST['metodo_pago'],$_POST['categoria']]);
        $msg='success'; $action='list';
    }
}

// Caja activa
$caja = $db->query("SELECT ca.*,u.nombre as cajero FROM cajas ca JOIN usuarios u ON u.id=ca.usuario_id WHERE ca.estado='abierta' ORDER BY ca.id DESC LIMIT 1")->fetch();

$movimientos = [];
$ingresos = $egresos = 0;
if ($caja) {
    $st = $db->prepare("SELECT * FROM movimientos_caja WHERE caja_id=? ORDER BY created_at DESC");
    $st->execute([$caja['id']]); $movimientos = $st->fetchAll();
    $ingresos = array_sum(array_column(array_filter($movimientos,fn($m)=>$m['tipo']==='ingreso'),'monto'));
    $egresos  = array_sum(array_column(array_filter($movimientos,fn($m)=>$m['tipo']==='egreso'),'monto'));
}

// Resumen por método de pago
$resumen_metodo = [];
if ($caja) {
    $st = $db->prepare("SELECT metodo_pago,SUM(monto) as total FROM movimientos_caja WHERE caja_id=? AND tipo='ingreso' GROUP BY metodo_pago");
    $st->execute([$caja['id']]); $resumen_metodo=$st->fetchAll();
}

// Historial de cajas
$historial_cajas = $db->query("SELECT ca.*,u.nombre as cajero, (SELECT COALESCE(SUM(monto),0) FROM movimientos_caja WHERE caja_id=ca.id AND tipo='ingreso') as total_ingresos, (SELECT COALESCE(SUM(monto),0) FROM movimientos_caja WHERE caja_id=ca.id AND tipo='egreso') as total_egresos FROM cajas ca JOIN usuarios u ON u.id=ca.usuario_id ORDER BY ca.id DESC LIMIT 15")->fetchAll();
$max_ingreso = max(array_column($historial_cajas,'total_ingresos') ?: [1]);

$metodo_icons = ['efectivo'=>'💵','yape'=>'📱','plin'=>'📱','tarjeta_debito'=>'💳','tarjeta_credito'=>'💳','transferencia'=>'🏦'];
$cat_labels = ['servicio'=>'Servicio','producto'=>'Producto','gasto_administrativo'=>'Gasto Admin.','compra_insumos'=>'Compra Insumos','otro'=>'Otro'];
?>
<?php if($msg): ?><div class="alert alert-success mb-2">✅ Operación realizada correctamente.</div><?php endif; ?>

<?php if(!$caja): ?>
<div class="card" style="max-width:480px;text-align:center;padding:40px">
  <div style="font-size:48px;margin-bottom:12px">🏦</div>
  <div class="sec-title mb-1">No hay caja abierta</div>
  <div class="text-muted text-sm mb-2">Para registrar movimientos, primero debes abrir la caja del día.</div>
  <form method="POST" class="flex gap-1 justify-center items-end">
    <input type="hidden" name="action" value="abrir">
    <div class="form-group text-left" style="min-width:180px">
      <label class="form-label">Monto de apertura (S/.)</label>
      <input class="form-input" type="number" step="0.01" name="monto_apertura" value="500" required>
    </div>
    <button type="submit" class="btn btn-primary" style="margin-bottom:14px">Abrir caja</button>
  </form>
</div>

<?php else: ?>
<!-- CAJA ABIERTA -->
<div class="alert alert-success mb-2"><span>✅</span><div><strong>Caja abierta</strong> desde <?= date('d/m/Y H:i',strtotime($caja['fecha_apertura'])) ?> · Cajero: <?= clean($caja['cajero']) ?> · Apertura: S/. <?= number_format($caja['monto_apertura'],2) ?></div></div>

<div class="grid g4 mb-2">
  <div class="stat-card"><div class="stat-icon si-teal">💵</div><div class="stat-value">S/. <?= number_format($ingresos,2) ?></div><div class="stat-label">Ingresos del día</div></div>
  <div class="stat-card" style="border-color:var(--red)"><div class="stat-icon si-red">📤</div><div class="stat-value">S/. <?= number_format($egresos,2) ?></div><div class="stat-label">Egresos</div></div>
  <div class="stat-card"><div class="stat-icon si-teal">📊</div><div class="stat-value">S/. <?= number_format($ingresos-$egresos,2) ?></div><div class="stat-label">Saldo neto</div></div>
  <div class="stat-card"><div class="stat-icon si-blue">🏦</div><div class="stat-value">S/. <?= number_format($caja['monto_apertura']+$ingresos-$egresos,2) ?></div><div class="stat-label">Cierre estimado</div></div>
</div>

<div class="grid g2">
  <!-- MOVIMIENTOS -->
  <div>
    <div class="card">
      <div class="sec-header"><div class="sec-title">Movimientos del día</div>
        <button class="btn btn-sm btn-primary" onclick="document.getElementById('mov-modal').style.display='flex'">+ Registrar</button>
      </div>
      <?php if(empty($movimientos)): ?><div class="text-center text-muted" style="padding:24px">Sin movimientos aún.</div><?php endif; ?>
      <?php foreach($movimientos as $m): ?>
      <div class="flex items-center gap-2" style="padding:9px 0;border-bottom:0.5px solid var(--border)">
        <div style="width:28px;height:28px;border-radius:8px;background:<?= $m['tipo']==='ingreso'?'var(--green-l)':'var(--red-l)' ?>;display:flex;align-items:center;justify-content:center;font-size:14px"><?= $m['tipo']==='ingreso'?'↓':'↑' ?></div>
        <div class="flex-1">
          <div class="text-sm font-med"><?= clean($m['concepto']) ?></div>
          <div class="text-xs text-muted"><?= date('H:i',strtotime($m['created_at'])) ?> · <?= $metodo_icons[$m['metodo_pago']]??'' ?> <?= ucfirst(str_replace('_',' ',$m['metodo_pago'])) ?> · <?= $cat_labels[$m['categoria']]??$m['categoria'] ?></div>
        </div>
        <span style="font-size:14px;font-weight:700;color:<?= $m['tipo']==='ingreso'?'var(--green)':'var(--red)' ?>"><?= $m['tipo']==='ingreso'?'+':'-' ?>S/. <?= number_format($m['monto'],2) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- RESUMEN + CIERRE -->
  <div>
    <div class="card mb-2">
      <div class="sec-header"><div class="sec-title">Ingresos por método de pago</div></div>
      <?php if(empty($resumen_metodo)): ?><div class="text-muted text-sm">Sin ingresos aún.</div><?php endif; ?>
      <?php foreach($resumen_metodo as $rm): $pct = $ingresos>0?round($rm['total']/$ingresos*100):0; ?>
      <div class="flex items-center gap-2 mb-2">
        <span style="font-size:20px"><?= $metodo_icons[$rm['metodo_pago']]??'💰' ?></span>
        <div class="flex-1">
          <div class="flex justify-between mb-1"><span class="text-sm font-med"><?= ucfirst(str_replace('_',' ',$rm['metodo_pago'])) ?></span><span class="text-sm font-bold">S/. <?= number_format($rm['total'],2) ?></span></div>
          <div class="progress-bar"><div class="progress-fill" style="width:<?= $pct ?>%;background:var(--teal)"></div></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="card">
      <div class="sec-header"><div class="sec-title">Cerrar caja</div></div>
      <form method="POST">
        <input type="hidden" name="action" value="cerrar">
        <input type="hidden" name="caja_id" value="<?= $caja['id'] ?>">
        <div class="form-group"><label class="form-label">Monto real en caja (S/.)</label><input class="form-input" type="number" step="0.01" name="monto_cierre" value="<?= number_format($caja['monto_apertura']+$ingresos-$egresos,2) ?>" required></div>
        <button type="submit" class="btn btn-red w-full" onclick="return confirm('¿Cerrar la caja del día? Esta acción no se puede deshacer.')">🔒 Cerrar caja del día</button>
      </form>
    </div>
  </div>
</div>

<!-- MODAL MOVIMIENTO -->
<div id="mov-modal" class="modal-overlay" style="display:none">
  <div class="modal">
    <div class="modal-header"><div class="modal-title">Registrar Movimiento</div><button class="modal-close" onclick="document.getElementById('mov-modal').style.display='none'">✕</button></div>
    <div class="modal-body">
      <form method="POST">
        <input type="hidden" name="action" value="movimiento">
        <input type="hidden" name="caja_id" value="<?= $caja['id'] ?>">
        <div class="form-row">
          <div class="form-group"><label class="form-label">Tipo *</label><select class="form-input" name="tipo"><option value="ingreso">Ingreso</option><option value="egreso">Egreso</option></select></div>
          <div class="form-group"><label class="form-label">Monto (S/.) *</label><input class="form-input" type="number" step="0.01" name="monto" required></div>
        </div>
        <div class="form-group"><label class="form-label">Concepto *</label><input class="form-input" name="concepto" required></div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Método de pago</label><select class="form-input" name="metodo_pago"><option value="efectivo">Efectivo</option><option value="yape">Yape</option><option value="plin">Plin</option><option value="tarjeta_debito">Tarjeta</option><option value="transferencia">Transferencia</option></select></div>
          <div class="form-group"><label class="form-label">Categoría</label><select class="form-input" name="categoria"><?php foreach($cat_labels as $k=>$v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="modal-footer" style="padding:0;border:0"><button type="submit" class="btn btn-primary">💾 Guardar</button></div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- HISTORIAL DE CAJAS -->
<?php if(!empty($historial_cajas)): ?>
<div class="card mt-2">
  <div class="sec-header"><div class="sec-title">Historial de cajas</div></div>
  <div class="table-wrap">
    <table class="vtable">
      <thead><tr><th>Apertura</th><th>Cierre</th><th>Cajero</th><th>Apertura (S/.)</th><th>Ingresos</th><th>Egresos</th><th>Balance</th><th>Estado</th></tr></thead>
      <tbody>
        <?php foreach($historial_cajas as $h): ?>
        <tr>
          <td class="text-muted"><?= date('d/m/Y H:i',strtotime($h['fecha_apertura'])) ?></td>
          <td class="text-muted"><?= $h['fecha_cierre'] ? date('d/m/Y H:i',strtotime($h['fecha_cierre'])) : '—' ?></td>
          <td><?= clean($h['cajero']) ?></td>
          <td>S/. <?= number_format($h['monto_apertura'],2) ?></td>
          <td class="font-med" style="color:var(--green)">S/. <?= number_format($h['total_ingresos'],2) ?></td>
          <td class="font-med" style="color:var(--red)">S/. <?= number_format($h['total_egresos'],2) ?></td>
          <td class="font-bold">S/. <?= number_format($h['monto_apertura']+$h['total_ingresos']-$h['total_egresos'],2) ?></td>
          <td><span class="badge <?= $h['estado']==='abierta'?'b-teal':'b-gray' ?>"><?= ucfirst($h['estado']) ?></span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
