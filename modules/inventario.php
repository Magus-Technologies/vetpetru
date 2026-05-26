<?php
$page = 'inventario'; $pageTitle = 'Inventario';
require_once __DIR__ . '/../includes/header.php';
$db = getDB();

// Crear tablas necesarias
$db->exec("CREATE TABLE IF NOT EXISTS proveedores (
  id INT AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(200) NOT NULL,
  ruc VARCHAR(20), contacto VARCHAR(150), telefono VARCHAR(30),
  email VARCHAR(150), direccion TEXT, notas TEXT, activo TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
$db->exec("CREATE TABLE IF NOT EXISTS compras (
  id INT AUTO_INCREMENT PRIMARY KEY, proveedor_id INT, usuario_id INT,
  fecha DATETIME DEFAULT CURRENT_TIMESTAMP, numero_factura VARCHAR(50),
  subtotal DECIMAL(10,2) DEFAULT 0, igv DECIMAL(10,2) DEFAULT 0,
  total DECIMAL(10,2) DEFAULT 0, estado ENUM('pendiente','recibido','cancelado') DEFAULT 'pendiente',
  notas TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (proveedor_id) REFERENCES proveedores(id),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
)");

$tab = $_GET['tab'] ?? 'resumen';
$action = $_GET['action'] ?? 'list';
$msg = '';

// Acciones POST
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $pa=$_POST['action']??'';
  if ($pa==='save_proveedor') {
    $id=(int)($_POST['id']??0);
    $fields=['nombre','ruc','contacto','telefono','email','direccion','notas'];
    $data=[]; foreach($fields as $f) $data[$f]=trim($_POST[$f]??'')?:null;
    if ($id) {
      $sets=implode(',',array_map(fn($f)=>"$f=:$f",$fields));
      $db->prepare("UPDATE proveedores SET $sets WHERE id=:id")->execute(array_merge($data,['id'=>$id]));
    } else {
      $cols=implode(',',$fields); $pls=implode(',',array_map(fn($f)=>":$f",$fields));
      $db->prepare("INSERT INTO proveedores ($cols) VALUES ($pls)")->execute($data);
    }
    $msg='success_prov';
  }

  // Editar producto
  if ($pa==='edit_producto' && can('inventario','editar')) {
    $id = (int)($_POST['id']??0);
    $fields = ['nombre','presentacion','laboratorio','lote','stock','stock_minimo',
               'precio_costo','precio_venta','fecha_vencimiento','categoria_id'];
    $data=[]; foreach($fields as $f) $data[$f]=trim($_POST[$f]??'')?:null;
    $data['id'] = $id;
    $sets = implode(',', array_map(fn($f)=>"$f=:$f", $fields));
    try {
        $db->prepare("UPDATE productos SET $sets WHERE id=:id")->execute($data);
        $msg='edit_ok';
    } catch(Exception $e){ $msg='edit_err'; }
    $tab='productos';
  }

  // Soft delete producto (activo=0, no borra de BD)
  if ($pa==='delete_producto' && can('inventario','eliminar')) {
    $id = (int)($_POST['id']??0);
    try {
        $db->prepare("UPDATE productos SET activo=0 WHERE id=?")->execute([$id]);
        $msg='delete_ok';
    } catch(Exception $e){ $msg='delete_err'; }
    $tab='productos';
  }
}

// Filtro sede para inventario
$_inv_sw = "";
try {
    $_r=$db->query("SHOW COLUMNS FROM `productos` LIKE 'sede_id'")->fetchAll();
    if(empty($_r)) { $db->exec("ALTER TABLE productos ADD COLUMN sede_id INT DEFAULT 1"); }
    // Actualizar registros sin sede asignada
    if(!verTodasSedes()) { $_inv_sw = " AND sede_id=".getSede(); }
} catch(Exception $e) {}

$total_productos=$db->query("SELECT COUNT(*) FROM productos WHERE activo=1$_inv_sw")->fetchColumn();
$valor_inv=$db->query("SELECT COALESCE(SUM(stock*precio_costo),0) FROM productos WHERE activo=1$_inv_sw")->fetchColumn();
$criticos=$db->query("SELECT COUNT(*) FROM productos WHERE stock<=stock_minimo/2 AND activo=1$_inv_sw")->fetchColumn();
$vencen_pronto=$db->query("SELECT COUNT(*) FROM productos WHERE fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY) AND activo=1$_inv_sw")->fetchColumn();
$vencidos=$db->query("SELECT COUNT(*) FROM productos WHERE fecha_vencimiento < CURDATE() AND activo=1$_inv_sw")->fetchColumn();

$proveedores=$db->query("SELECT * FROM proveedores WHERE activo=1 ORDER BY nombre")->fetchAll();
$productos_inventario=$db->query("SELECT p.*,c.nombre as cat_nombre FROM productos p LEFT JOIN categorias_producto c ON c.id=p.categoria_id WHERE p.activo=1$_inv_sw ORDER BY p.nombre ASC LIMIT 100")->fetchAll();
$vencimientos=$db->query("SELECT * FROM productos WHERE fecha_vencimiento <= DATE_ADD(CURDATE(),INTERVAL 60 DAY) AND activo=1$_inv_sw ORDER BY fecha_vencimiento ASC")->fetchAll();
// kardex: filtrar por sede del producto (no del kardex)
$_kardex_sw = $_inv_sw ? str_replace(' AND sede_id=', ' AND p.sede_id=', $_inv_sw) : '';
try { $kardex=$db->query("SELECT k.*,p.nombre as producto,u.nombre as usuario FROM kardex k JOIN productos p ON p.id=k.producto_id LEFT JOIN usuarios u ON u.id=k.usuario_id WHERE 1=1$_kardex_sw ORDER BY k.created_at DESC LIMIT 50")->fetchAll(); } catch(Exception $e) { $kardex=[]; }
?>
<div class="page">
<?php if($msg==='success_prov'): ?><div class="alert alert-success"><span class="alert-icon">✅</span>Proveedor guardado.</div><?php endif; ?>

<!-- Tabs -->
<div class="tabs-pills mb-3">
  <a href="?p=inventario&tab=resumen"   class="pill-btn <?= $tab==='resumen'?'active':'' ?>">📊 Resumen</a>
  <a href="?p=inventario&tab=productos" class="pill-btn <?= $tab==='productos'?'active':'' ?>">📦 Productos</a>
  <a href="?p=inventario&tab=kardex"    class="pill-btn <?= $tab==='kardex'?'active':'' ?>">📋 Kardex</a>
  <a href="?p=inventario&tab=vencimientos" class="pill-btn <?= $tab==='vencimientos'?'active':'' ?>">⏰ Vencimientos</a>
  <a href="?p=inventario&tab=proveedores" class="pill-btn <?= $tab==='proveedores'?'active':'' ?>">🏭 Proveedores</a>
</div>

<?php if($tab==='resumen'): ?>
<!-- ── RESUMEN ── -->
<div class="grid g4 mb-3">
  <div class="stat-card"><div class="stat-icon si-accent">📦</div><div class="stat-value"><?= $total_productos ?></div><div class="stat-label">Productos activos</div></div>
  <div class="stat-card"><div class="stat-icon si-success">💰</div><div class="stat-value">S/. <?= number_format($valor_inv,0) ?></div><div class="stat-label">Valor del inventario</div></div>
  <div class="stat-card" style="<?= $criticos>0?'border-color:var(--danger)':'' ?>"><div class="stat-icon si-danger">🚨</div><div class="stat-value"><?= $criticos ?></div><div class="stat-label">Stock crítico</div></div>
  <div class="stat-card" style="<?= $vencidos>0?'border-color:var(--warning)':'' ?>"><div class="stat-icon si-warning">⏰</div><div class="stat-value"><?= $vencidos ?></div><div class="stat-label">Productos vencidos</div></div>
</div>
<?php if($criticos>0||$vencidos>0): ?>
<div class="alert alert-danger"><span class="alert-icon">🚨</span><div>
  <?php if($criticos>0): ?><strong><?= $criticos ?> producto(s) en stock crítico.</strong> <?php endif; ?>
  <?php if($vencidos>0): ?><strong><?= $vencidos ?> producto(s) vencidos</strong> requieren revisión inmediata.<?php endif; ?>
</div></div>
<?php endif; ?>
<div class="grid g2">
  <div class="card">
    <div class="sec-title mb-2">📦 Stock por categoría</div>
    <?php
    $cats=$db->query("SELECT c.nombre,COUNT(p.id) as n,SUM(p.stock*p.precio_costo) as valor FROM categorias_producto c LEFT JOIN productos p ON p.categoria_id=c.id AND p.activo=1".($_inv_sw ? str_replace(' AND sede_id=', ' AND p.sede_id=', $_inv_sw) : '')." GROUP BY c.id ORDER BY valor DESC LIMIT 6")->fetchAll();
    $max_val=max(array_column($cats,'valor')?:[1]);
    foreach($cats as $cat): ?>
    <div style="margin-bottom:14px">
      <div class="flex justify-between mb-1"><span class="text-sm font-semi"><?= clean($cat['nombre']) ?></span><span class="text-xs text-muted"><?= $cat['n'] ?> prods · S/. <?= number_format($cat['valor'],0) ?></span></div>
      <div class="progress-bar"><div class="progress-fill pf-primary" style="width:<?= round($cat['valor']/$max_val*100) ?>%"></div></div>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="card">
    <div class="sec-title mb-2">⚠️ Próximos a vencer (30 días)</div>
    <?php foreach(array_slice($vencimientos,0,6) as $v):
      $dias=ceil((strtotime($v['fecha_vencimiento'])-time())/86400);
    ?>
    <div class="flex items-center gap-2 mb-2" style="padding:8px;background:<?= $dias<0?'var(--danger-l)':($dias<=7?'var(--warning-l)':'var(--bg3)') ?>;border-radius:var(--r-sm)">
      <div class="flex-1"><div class="text-sm font-semi"><?= clean($v['nombre']) ?></div><div class="text-xs text-muted">Vence: <?= date('d/m/Y',strtotime($v['fecha_vencimiento'])) ?></div></div>
      <span class="badge <?= $dias<0?'b-danger':($dias<=7?'b-warning':'b-info') ?>"><?= $dias<0?'Vencido':"$dias d." ?></span>
    </div>
    <?php endforeach; ?>
    <?php if(empty($vencimientos)): ?><div class="text-muted text-center" style="padding:24px">Sin productos próximos a vencer ✅</div><?php endif; ?>
  </div>
</div>

<?php elseif($tab==='productos'): ?>
<!-- ── PRODUCTOS ── -->
<?php if($msg==='edit_ok'): ?><div class="alert alert-success mb-3">✅ Producto actualizado correctamente.</div><?php endif; ?>
<?php if($msg==='delete_ok'): ?><div class="alert alert-success mb-3">✅ Producto desactivado del inventario.</div><?php endif; ?>

<div class="flex items-center justify-between mb-3">
  <div class="page-title">Inventario de Productos</div>
  <a href="<?= BASE_URL ?>/index.php?p=farmacia&action=nueva" class="btn btn-primary">＋ Agregar Producto</a>
</div>
<div class="card" style="padding:0">
  <div class="table-wrap">
    <table class="vtable">
      <thead><tr><th>Producto</th><th>Categoría</th><th>Stock</th><th>Mínimo</th><th>Costo</th><th>Venta</th><th>Vencimiento</th><th>Estado</th><th>Acciones</th></tr></thead>
      <tbody>
        <?php foreach($productos_inventario as $p):
          $crit=$p['stock']<=$p['stock_minimo']/2;
          $bajo=$p['stock']<=$p['stock_minimo'];
          $venc=$p['fecha_vencimiento']&&strtotime($p['fecha_vencimiento'])<time();
          $pct=min(100,$p['stock_minimo']>0?round($p['stock']/$p['stock_minimo']*50):100);
        ?>
        <tr>
          <td><div class="td-main"><?= clean($p['nombre']) ?></div><div class="text-xs text-muted"><?= clean($p['presentacion']??'') ?> · <?= clean($p['lote']??'') ?></div></td>
          <td><span class="badge b-accent"><?= clean($p['cat_nombre']??'—') ?></span></td>
          <td>
            <div class="font-bold" style="color:<?= $crit?'var(--danger)':($bajo?'var(--warning)':'var(--text)') ?>"><?= $p['stock'] ?></div>
            <div class="progress-bar mt-1" style="width:60px"><div class="progress-fill" style="width:<?= $pct ?>%;background:<?= $crit?'var(--danger)':($bajo?'var(--warning)':'var(--primary)') ?>"></div></div>
          </td>
          <td class="text-muted"><?= $p['stock_minimo'] ?></td>
          <td>S/. <?= number_format($p['precio_costo'],2) ?></td>
          <td class="font-semi" style="color:var(--success)">S/. <?= number_format($p['precio_venta'],2) ?></td>
          <td class="<?= $venc?'color-danger':'' ?>"><?= $p['fecha_vencimiento']?date('d/m/Y',strtotime($p['fecha_vencimiento'])):'—' ?></td>
          <td><span class="badge <?= $crit?'b-danger':($bajo?'b-warning':'b-success') ?>"><?= $crit?'Crítico':($bajo?'Bajo':'OK') ?></span></td>
          <td>
            <div class="flex gap-1">
              <button onclick="editarProducto(<?= htmlspecialchars(json_encode($p)) ?>)"
                      class="btn btn-xs btn-ghost" title="Editar">✏️ Editar</button>
              <form method="POST" style="display:inline"
                    onsubmit="return confirm('¿Desactivar <?= addslashes(clean($p['nombre'])) ?>? Seguirá en la base de datos pero no aparecerá en el inventario.')">
                <input type="hidden" name="action" value="delete_producto">
                <input type="hidden" name="id" value="<?= $p['id'] ?>">
                <button type="submit" class="btn btn-xs btn-ghost" style="color:var(--danger)" title="Desactivar">🗑️ Eliminar</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($productos_inventario)): ?>
        <tr><td colspan="9" style="text-align:center;padding:40px;color:var(--text3)">Sin productos en inventario</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal editar producto -->
<div id="modal-edit-prod" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:2000;align-items:center;justify-content:center" onclick="if(event.target===this)this.style.display='none'">
  <div style="background:var(--bg2);border-radius:16px;padding:28px;width:600px;max-width:96vw;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2)">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px">
      <div style="font-size:16px;font-weight:700">✏️ Editar Producto</div>
      <button onclick="document.getElementById('modal-edit-prod').style.display='none'" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--text3)">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="edit_producto">
      <input type="hidden" name="id" id="ep-id">
      <?php
      $cats_inv=[];
      try{$cats_inv=$db->query("SELECT id,nombre FROM categorias_producto ORDER BY nombre")->fetchAll();}catch(Exception $e){}
      ?>
      <div class="form-row">
        <div class="form-group"><label class="form-label required">Nombre</label><input class="form-input" name="nombre" id="ep-nombre" required></div>
        <div class="form-group"><label class="form-label">Presentación</label><input class="form-input" name="presentacion" id="ep-presenta"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Laboratorio</label><input class="form-input" name="laboratorio" id="ep-lab"></div>
        <div class="form-group"><label class="form-label">Lote</label><input class="form-input" name="lote" id="ep-lote"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label required">Stock actual</label><input class="form-input" type="number" name="stock" id="ep-stock" min="0" required></div>
        <div class="form-group"><label class="form-label required">Stock mínimo</label><input class="form-input" type="number" name="stock_minimo" id="ep-stk-min" min="0" required></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label required">Precio costo S/.</label><input class="form-input" type="number" step="0.01" name="precio_costo" id="ep-costo" required></div>
        <div class="form-group"><label class="form-label required">Precio venta S/.</label><input class="form-input" type="number" step="0.01" name="precio_venta" id="ep-venta" required></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Categoría</label>
          <select class="form-input" name="categoria_id" id="ep-cat">
            <option value="">— Sin categoría —</option>
            <?php foreach($cats_inv as $c): ?><option value="<?= $c['id'] ?>"><?= clean($c['nombre']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Fecha vencimiento</label><input class="form-input" type="date" name="fecha_vencimiento" id="ep-vence"></div>
      </div>
      <div class="flex gap-2 mt-3">
        <button type="submit" class="btn btn-primary btn-lg" style="flex:1">💾 Guardar cambios</button>
        <button type="button" onclick="document.getElementById('modal-edit-prod').style.display='none'" class="btn btn-ghost">Cancelar</button>
      </div>
    </form>
  </div>
</div>

<script>
function editarProducto(p) {
  document.getElementById('ep-id').value      = p.id;
  document.getElementById('ep-nombre').value  = p.nombre||'';
  document.getElementById('ep-presenta').value= p.presentacion||'';
  document.getElementById('ep-lab').value     = p.laboratorio||'';
  document.getElementById('ep-lote').value    = p.lote||'';
  document.getElementById('ep-stock').value   = p.stock||0;
  document.getElementById('ep-stk-min').value = p.stock_minimo||0;
  document.getElementById('ep-costo').value   = p.precio_costo||0;
  document.getElementById('ep-venta').value   = p.precio_venta||0;
  document.getElementById('ep-vence').value   = p.fecha_vencimiento||'';
  var cat = document.getElementById('ep-cat');
  for(var i=0;i<cat.options.length;i++){
    cat.options[i].selected = (cat.options[i].value == p.categoria_id);
  }
  document.getElementById('modal-edit-prod').style.display='flex';
}
</script>

<?php elseif($tab==='kardex'): ?>
<!-- ── KARDEX ── -->
<div class="page-title mb-3">Kardex de movimientos</div>
<div class="card" style="padding:0">
  <div class="table-wrap">
    <table class="vtable">
      <thead><tr><th>Fecha</th><th>Producto</th><th>Tipo</th><th>Cantidad</th><th>Stock anterior</th><th>Stock nuevo</th><th>Usuario</th><th>Notas</th></tr></thead>
      <tbody>
        <?php foreach($kardex as $k): ?>
        <tr>
          <td class="text-muted"><?= date('d/m/Y H:i',strtotime($k['created_at'])) ?></td>
          <td class="td-main"><?= clean($k['producto']) ?></td>
          <td><span class="badge <?= $k['tipo']==='entrada'?'b-success':($k['tipo']==='salida'?'b-danger':'b-warning') ?>"><?= $k['tipo']==='entrada'?'↓ Entrada':($k['tipo']==='salida'?'↑ Salida':'⇄ Ajuste') ?></span></td>
          <td class="font-bold <?= $k['tipo']==='salida'?'color-danger':'color-success' ?>"><?= $k['tipo']==='entrada'?'+':'-' ?><?= $k['cantidad'] ?></td>
          <td class="text-muted"><?= $k['stock_anterior'] ?></td>
          <td class="font-semi"><?= $k['stock_nuevo'] ?></td>
          <td class="text-muted"><?= clean($k['usuario']??'Sistema') ?></td>
          <td class="text-muted text-xs"><?= clean(substr($k['notas']??'',0,40)) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($kardex)): ?><tr><td colspan="8" class="text-center text-muted" style="padding:40px">Sin movimientos registrados.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif($tab==='vencimientos'): ?>
<!-- ── VENCIMIENTOS ── -->
<div class="page-title mb-3">Control de Vencimientos</div>
<div class="grid g4 mb-3">
  <div class="stat-card"><div class="stat-icon si-danger">❌</div><div class="stat-value"><?= $vencidos ?></div><div class="stat-label">Vencidos</div></div>
  <div class="stat-card"><div class="stat-icon si-warning">⚠️</div><div class="stat-value"><?= $db->query("SELECT COUNT(*) FROM productos WHERE fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY) AND activo=1")->fetchColumn() ?></div><div class="stat-label">Vencen en 7 días</div></div>
  <div class="stat-card"><div class="stat-icon si-info">📦</div><div class="stat-value"><?= $vencen_pronto ?></div><div class="stat-label">Vencen en 30 días</div></div>
  <div class="stat-card"><div class="stat-icon si-success">✅</div><div class="stat-value"><?= $total_productos-$vencidos-$vencen_pronto ?></div><div class="stat-label">Sin vencer pronto</div></div>
</div>
<div class="card" style="padding:0">
  <div class="table-wrap">
    <table class="vtable">
      <thead><tr><th>Producto</th><th>Stock</th><th>Vencimiento</th><th>Días</th><th>Alerta</th></tr></thead>
      <tbody>
        <?php foreach($vencimientos as $v):
          $dias=ceil((strtotime($v['fecha_vencimiento'])-time())/86400);
        ?>
        <tr>
          <td class="td-main"><?= clean($v['nombre']) ?></td>
          <td><?= $v['stock'] ?></td>
          <td><?= date('d/m/Y',strtotime($v['fecha_vencimiento'])) ?></td>
          <td class="font-bold" style="color:<?= $dias<0?'var(--danger)':($dias<=7?'var(--warning)':'var(--text)') ?>"><?= $dias<0?'Vencido hace '.abs($dias).'d':$dias.' días' ?></td>
          <td><span class="badge <?= $dias<0?'b-danger':($dias<=7?'b-warning':($dias<=30?'b-info':'b-success')) ?>"><?= $dias<0?'❌ VENCIDO':($dias<=7?'⚠️ URGENTE':($dias<=30?'📋 Próximo':'✅ OK')) ?></span></td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($vencimientos)): ?><tr><td colspan="5" class="text-center text-muted" style="padding:40px">✅ Sin productos próximos a vencer.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif($tab==='proveedores'): ?>
<!-- ── PROVEEDORES ── -->
<div class="flex items-center justify-between mb-3">
  <div class="page-title">Proveedores</div>
  <button class="btn btn-primary" onclick="document.getElementById('modalProv').style.display='flex'">＋ Nuevo Proveedor</button>
</div>
<div class="grid g3">
  <?php foreach($proveedores as $p): ?>
  <div class="card card-sm">
    <div class="flex items-center gap-2 mb-2">
      <div style="width:40px;height:40px;border-radius:var(--r-sm);background:var(--accent-l);display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0">🏭</div>
      <div class="flex-1"><div class="font-semi"><?= clean($p['nombre']) ?></div><?php if($p['ruc']): ?><div class="text-xs text-muted">RUC: <?= clean($p['ruc']) ?></div><?php endif; ?></div>
    </div>
    <?php if($p['contacto']): ?><div class="text-xs text-muted mb-1">👤 <?= clean($p['contacto']) ?></div><?php endif; ?>
    <?php if($p['telefono']): ?><div class="text-xs text-muted mb-1">📞 <?= clean($p['telefono']) ?></div><?php endif; ?>
    <?php if($p['email']): ?><div class="text-xs text-muted">✉️ <?= clean($p['email']) ?></div><?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php if(empty($proveedores)): ?><div class="card text-center text-muted" style="padding:48px;grid-column:1/-1">Sin proveedores registrados.</div><?php endif; ?>
</div>

<!-- Modal nuevo proveedor -->
<div class="modal-overlay" id="modalProv" style="display:none">
  <div class="modal">
    <div class="modal-header"><div class="modal-title">🏭 Nuevo Proveedor</div><button class="modal-close" onclick="document.getElementById('modalProv').style.display='none'">✕</button></div>
    <div class="modal-body">
      <form method="POST">
        <input type="hidden" name="action" value="save_proveedor">
        <div class="form-row"><div class="form-group"><label class="form-label required">Nombre / Razón social</label><input class="form-input" name="nombre" required></div><div class="form-group"><label class="form-label">RUC</label><input class="form-input" name="ruc" maxlength="11"></div></div>
        <div class="form-row"><div class="form-group"><label class="form-label">Contacto</label><input class="form-input" name="contacto"></div><div class="form-group"><label class="form-label">Teléfono</label><input class="form-input" name="telefono"></div></div>
        <div class="form-group"><label class="form-label">Email</label><input class="form-input" type="email" name="email"></div>
        <div class="form-group"><label class="form-label">Dirección</label><input class="form-input" name="direccion"></div>
        <div class="modal-footer"><a href="#" class="btn btn-ghost" onclick="document.getElementById('modalProv').style.display='none'">Cancelar</a><button type="submit" class="btn btn-primary">💾 Guardar</button></div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
