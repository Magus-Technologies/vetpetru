<?php
$page = 'farmacia'; $pageTitle = 'Farmacia / Inventario';
require_once __DIR__ . '/../includes/header.php';
$db = getDB();
$_sid = getSede(); $_all = verTodasSedes();
try {
    $r = $db->query("SHOW COLUMNS FROM productos LIKE 'sede_id'")->fetchAll();
    $_prod_sede = !empty($r);
    if (empty($r)) { $db->exec("ALTER TABLE productos ADD COLUMN sede_id INT DEFAULT 1"); $_prod_sede=true; }
} catch(Exception $e) { $_prod_sede = false; }
$action = $_GET['action'] ?? 'list';
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pa = $_POST['action'] ?? '';
    if ($pa === 'save') {
        $id = (int)($_POST['id']??0);
        $fields = ['categoria_id','nombre','descripcion','presentacion','laboratorio','stock','stock_minimo','precio_costo','precio_venta','lote','fecha_vencimiento'];
        $data=[]; foreach($fields as $f) $data[$f] = trim($_POST[$f]??'') ?: null;
        $data['sede_id'] = $user['sede_id']??1;
        if ($id) {
            $sets = implode(',', array_map(fn($f)=>"$f=:$f", $fields));
            $st = $db->prepare("UPDATE productos SET $sets WHERE id=:id"); $data['id']=$id;
        } else {
            $cols = implode(',', array_merge($fields,['sede_id']));
            $pls  = implode(',', array_map(fn($f)=>":$f", array_merge($fields,['sede_id'])));
            $st = $db->prepare("INSERT INTO productos ($cols) VALUES ($pls)");
        }
        $st->execute($data); $msg='success'; $action='list';
    }
    if ($pa === 'movimiento') {
        $prod_id = (int)$_POST['producto_id'];
        $tipo    = $_POST['tipo'];
        $qty     = (int)$_POST['cantidad'];
        $notas   = trim($_POST['notas']??'');
        $st = $db->prepare("SELECT stock FROM productos WHERE id=?"); $st->execute([$prod_id]);
        $stock_ant = (int)$st->fetchColumn();
        $stock_nuevo = $tipo==='entrada' ? $stock_ant+$qty : max(0,$stock_ant-$qty);
        $db->prepare("UPDATE productos SET stock=? WHERE id=?")->execute([$stock_nuevo,$prod_id]);
        $db->prepare("INSERT INTO kardex (producto_id,usuario_id,tipo,cantidad,stock_anterior,stock_nuevo,notas) VALUES (?,?,?,?,?,?,?)")->execute([$prod_id,$user['id'],$tipo,$qty,$stock_ant,$stock_nuevo,$notas]);
        $msg='success'; $action='list';
    }
}
if ($action==='delete' && isset($_GET['id'])) {
    $db->prepare("UPDATE productos SET activo=0 WHERE id=?")->execute([(int)$_GET['id']]); $action='list';
}

$editing=null;
if (in_array($action,['editar']) && isset($_GET['id'])) {
    $st=$db->prepare("SELECT * FROM productos WHERE id=?"); $st->execute([(int)$_GET['id']]); $editing=$st->fetch();
}

$categorias = $db->query("SELECT * FROM categorias_producto ORDER BY nombre")->fetchAll();
$cat_map = [];foreach($categorias as $c) $cat_map[$c['id']]=$c['nombre'];

// Filtros
$cat_f = (int)($_GET['cat']??0);
$alerta_f = $_GET['alerta']??'';
$search = trim($_GET['q']??'');
$where = "activo=1"; $params=[];
if ($cat_f) { $where .= " AND categoria_id=?"; $params[]=$cat_f; }
if ($search) { $where .= " AND (nombre LIKE ? OR laboratorio LIKE ? OR lote LIKE ?)"; $like="%$search%"; $params=array_merge($params,[$like,$like,$like]); }
if ($alerta_f==='critico')    $where .= " AND stock < stock_minimo/2";
elseif($alerta_f==='bajo')    $where .= " AND stock <= stock_minimo";
elseif($alerta_f==='por_vencer') $where .= " AND fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY)";
// Filtro sede seguro
if ($_prod_sede && !$_all) { $where .= " AND sede_id=$_sid"; }
$_swf = ($_prod_sede && !$_all) ? " AND sede_id=$_sid" : "";

$st = $db->prepare("SELECT * FROM productos WHERE $where ORDER BY nombre ASC"); $st->execute($params); $productos=$st->fetchAll();
$criticos = $db->query("SELECT COUNT(*) FROM productos WHERE stock < stock_minimo/2 AND activo=1$_swf")->fetchColumn();
$bajos    = $db->query("SELECT COUNT(*) FROM productos WHERE stock <= stock_minimo AND activo=1$_swf")->fetchColumn();
$total_val= $db->query("SELECT COALESCE(SUM(stock*precio_costo),0) FROM productos WHERE activo=1$_swf")->fetchColumn();
?>
<?php if($msg==='success'): ?><div class="alert alert-success mb-2">✅ Operación realizada correctamente.</div><?php endif; ?>

<?php if(in_array($action,['nueva','editar'])): ?>
<div class="card" style="max-width:680px">
  <div class="sec-header"><div class="sec-title"><?= $action==='editar'?'Editar':'Nuevo'?> Producto</div><a href="?p=farmacia" class="btn btn-sm">← Volver</a></div>
  <form method="POST">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= $editing['id']??'' ?>">
    <div class="form-row">
      <div class="form-group"><label class="form-label">Nombre *</label><input class="form-input" name="nombre" value="<?= clean($editing['nombre']??'') ?>" required></div>
      <div class="form-group"><label class="form-label">Categoría</label>
        <select class="form-input" name="categoria_id">
          <option value="">— Sin categoría —</option>
          <?php foreach($categorias as $c): ?><option value="<?= $c['id'] ?>" <?= ($editing['categoria_id']??'')==$c['id']?'selected':'' ?>><?= clean($c['nombre']) ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Presentación</label><input class="form-input" name="presentacion" value="<?= clean($editing['presentacion']??'') ?>" placeholder="Ej: Tabletas x100"></div>
      <div class="form-group"><label class="form-label">Laboratorio</label><input class="form-input" name="laboratorio" value="<?= clean($editing['laboratorio']??'') ?>"></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Stock actual *</label><input class="form-input" type="number" name="stock" value="<?= clean($editing['stock']??0) ?>" required></div>
      <div class="form-group"><label class="form-label">Stock mínimo</label><input class="form-input" type="number" name="stock_minimo" value="<?= clean($editing['stock_minimo']??5) ?>"></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Precio de costo (S/.)</label><input class="form-input" type="number" step="0.01" name="precio_costo" value="<?= clean($editing['precio_costo']??0) ?>"></div>
      <div class="form-group"><label class="form-label">Precio de venta (S/.)</label><input class="form-input" type="number" step="0.01" name="precio_venta" value="<?= clean($editing['precio_venta']??0) ?>"></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">N° de lote</label><input class="form-input" name="lote" value="<?= clean($editing['lote']??'') ?>"></div>
      <div class="form-group"><label class="form-label">Fecha de vencimiento</label><input class="form-input" type="date" name="fecha_vencimiento" value="<?= clean($editing['fecha_vencimiento']??'') ?>"></div>
    </div>
    <div class="form-group"><label class="form-label">Descripción</label><textarea class="form-input" name="descripcion" style="min-height:60px"><?= clean($editing['descripcion']??'') ?></textarea></div>
    <div class="flex gap-1"><button type="submit" class="btn btn-primary">💾 Guardar producto</button><a href="?p=farmacia" class="btn">Cancelar</a></div>
  </form>
</div>

<?php elseif($action==='movimiento' && isset($_GET['id'])): ?>
<?php $prod_mov=$db->prepare("SELECT * FROM productos WHERE id=?"); $prod_mov->execute([(int)$_GET['id']]); $pm=$prod_mov->fetch(); ?>
<div class="card" style="max-width:480px">
  <div class="sec-header"><div class="sec-title">Movimiento de inventario</div><a href="?p=farmacia" class="btn btn-sm">← Volver</a></div>
  <div class="alert alert-info mb-2"><span>📦</span><div><strong><?= clean($pm['nombre']) ?></strong> · Stock actual: <strong><?= $pm['stock'] ?> unidades</strong></div></div>
  <form method="POST">
    <input type="hidden" name="action" value="movimiento">
    <input type="hidden" name="producto_id" value="<?= $pm['id'] ?>">
    <div class="form-row">
      <div class="form-group"><label class="form-label">Tipo de movimiento</label>
        <select class="form-input" name="tipo"><option value="entrada">Entrada (compra/recepción)</option><option value="salida">Salida (uso/venta)</option><option value="ajuste">Ajuste de inventario</option></select>
      </div>
      <div class="form-group"><label class="form-label">Cantidad</label><input class="form-input" type="number" name="cantidad" min="1" value="1" required></div>
    </div>
    <div class="form-group"><label class="form-label">Notas / Referencia</label><textarea class="form-input" name="notas" style="min-height:60px"></textarea></div>
    <div class="flex gap-1"><button type="submit" class="btn btn-primary">✅ Registrar movimiento</button><a href="?p=farmacia" class="btn">Cancelar</a></div>
  </form>
</div>

<?php else: ?>
<div class="grid g4 mb-2">
  <div class="stat-card"><div class="stat-icon si-teal">📦</div><div class="stat-value"><?= count($productos) ?></div><div class="stat-label">Productos activos</div></div>
  <div class="stat-card" style="<?= $criticos>0?'border-color:var(--red)':'' ?>"><div class="stat-icon <?= $criticos>0?'si-red':'si-teal' ?>">🚨</div><div class="stat-value"><?= $criticos ?></div><div class="stat-label">Stock crítico</div></div>
  <div class="stat-card" style="<?= $bajos>0?'border-color:var(--amber)':'' ?>"><div class="stat-icon <?= $bajos>0?'si-amber':'si-teal' ?>">⚠️</div><div class="stat-value"><?= $bajos ?></div><div class="stat-label">Bajo el mínimo</div></div>
  <div class="stat-card"><div class="stat-icon si-blue">💰</div><div class="stat-value">S/. <?= number_format($total_val,0) ?></div><div class="stat-label">Valor del inventario</div></div>
</div>

<?php if($criticos > 0): ?>
<div class="alert alert-warn mb-2"><span>⚠️</span><div><strong><?= $criticos ?> producto(s) en stock crítico.</strong> Realiza una compra/reposición pronto.</div></div>
<?php endif; ?>

<div class="card mb-2" style="padding:14px 18px">
  <form method="GET" class="flex items-center gap-2" style="flex-wrap:wrap"><input type="hidden" name="p" value="farmacia">
    <input class="form-input" name="q" value="<?= clean($search) ?>" placeholder="Buscar producto..." style="width:220px">
    <select class="form-input" name="cat" style="width:160px"><option value="">Todas las categorías</option><?php foreach($categorias as $c): ?><option value="<?= $c['id'] ?>" <?= $cat_f==$c['id']?'selected':'' ?>><?= clean($c['nombre']) ?></option><?php endforeach; ?></select>
    <select class="form-input" name="alerta" style="width:160px"><option value="">Todos</option><option value="critico" <?= $alerta_f==='critico'?'selected':'' ?>>Stock crítico</option><option value="bajo" <?= $alerta_f==='bajo'?'selected':'' ?>>Bajo mínimo</option><option value="por_vencer" <?= $alerta_f==='por_vencer'?'selected':'' ?>>Por vencer (30d)</option></select>
    <button type="submit" class="btn">Filtrar</button>
    <a href="?p=farmacia&action=nueva" class="btn btn-primary" style="margin-left:auto">+ Nuevo Producto</a>
  </form>
</div>

<div class="card" style="padding:0">
  <div class="table-wrap">
    <table class="vtable">
      <thead><tr><th>Producto</th><th>Categoría</th><th>Stock</th><th>Mín.</th><th>Precio venta</th><th>Lote</th><th>Vencimiento</th><th>Estado</th><th>Acciones</th></tr></thead>
      <tbody>
        <?php foreach($productos as $p):
          $critico = $p['stock'] < $p['stock_minimo']/2;
          $bajo    = $p['stock'] <= $p['stock_minimo'];
          $estado  = $critico ? 'Crítico' : ($bajo ? 'Bajo' : 'OK');
          $badge   = $critico ? 'b-red' : ($bajo ? 'b-amber' : 'b-teal');
          $venc_warn = $p['fecha_vencimiento'] && strtotime($p['fecha_vencimiento']) < strtotime('+30 days');
          $pct = min(100, $p['stock_minimo'] > 0 ? round($p['stock']/$p['stock_minimo']*50) : 100);
        ?>
        <tr>
          <td><div class="td-main"><?= clean($p['nombre']) ?></div><div class="text-xs text-muted"><?= clean($p['presentacion']??'') ?></div></td>
          <td><span class="badge b-gray"><?= clean($cat_map[$p['categoria_id']]??'—') ?></span></td>
          <td>
            <div class="font-bold" style="color:<?= $critico?'var(--red)':($bajo?'var(--amber)':'var(--text)') ?>"><?= $p['stock'] ?></div>
            <div class="progress-bar mt-1" style="width:70px"><div class="progress-fill" style="width:<?= $pct ?>%;background:<?= $critico?'var(--red)':($bajo?'var(--amber)':'var(--teal)') ?>"></div></div>
          </td>
          <td class="text-muted"><?= $p['stock_minimo'] ?></td>
          <td class="font-med">S/. <?= number_format($p['precio_venta'],2) ?></td>
          <td class="text-xs text-muted"><?= clean($p['lote']??'—') ?></td>
          <td class="<?= $venc_warn?'font-bold':'text-muted' ?>" style="<?= $venc_warn?'color:var(--amber)':'' ?>"><?= $p['fecha_vencimiento'] ? date('d/m/Y',strtotime($p['fecha_vencimiento'])) : '—' ?></td>
          <td><span class="badge <?= $badge ?>"><?= $estado ?></span></td>
          <td><div class="flex gap-1">
            <a href="?p=farmacia&action=movimiento&id=<?= $p['id'] ?>" class="btn btn-xs">📦 Mov.</a>
            <a href="?p=farmacia&action=editar&id=<?= $p['id'] ?>" class="btn btn-xs">✏️</a>
            <a href="?p=farmacia&action=delete&id=<?= $p['id'] ?>" class="btn btn-xs" style="color:var(--red)" onclick="return confirm('¿Dar de baja este producto?')">✕</a>
          </div></td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($productos)): ?><tr><td colspan="9" class="text-center text-muted" style="padding:32px">No se encontraron productos.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
