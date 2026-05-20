<?php
$page = 'compras'; $pageTitle = 'Compras';
require_once __DIR__ . '/../includes/header.php';
$db = getDB();

// ── Auto-crear tablas ──────────────────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS proveedores (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(200) NOT NULL,
        ruc VARCHAR(20),
        contacto VARCHAR(150),
        telefono VARCHAR(30),
        email VARCHAR(150),
        direccion TEXT,
        activo TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS compras (
        id INT AUTO_INCREMENT PRIMARY KEY,
        fecha DATE NOT NULL,
        subtotal DECIMAL(12,2) DEFAULT 0,
        igv DECIMAL(12,2) DEFAULT 0,
        total DECIMAL(12,2) DEFAULT 0,
        notas TEXT,
        usuario_id INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    // Agregar columnas faltantes — try-catch por columna (MariaDB 10.5)
    $alter_cols = [
        "numero VARCHAR(30)",
        "proveedor_id INT",
        "proveedor_nombre VARCHAR(200)",
        "fecha_vencimiento_doc DATE",
        "tipo_doc VARCHAR(20) DEFAULT 'factura'",
        "nro_doc VARCHAR(50)",
        "estado VARCHAR(20) DEFAULT 'recibida'",
        "sede_id INT DEFAULT 1",
    ];
    foreach ($alter_cols as $col_def) {
        try { $db->exec("ALTER TABLE compras ADD COLUMN $col_def"); }
        catch(Exception $e) { /* columna ya existe */ }
    }
    $db->exec("CREATE TABLE IF NOT EXISTS compra_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        compra_id INT NOT NULL,
        producto_id INT,
        nombre VARCHAR(200) NOT NULL,
        presentacion VARCHAR(100),
        laboratorio VARCHAR(100),
        categoria_id INT,
        codigo_barras VARCHAR(50),
        lote VARCHAR(50),
        fecha_vencimiento DATE,
        cantidad INT NOT NULL DEFAULT 1,
        precio_costo DECIMAL(10,2) NOT NULL,
        precio_venta DECIMAL(10,2) DEFAULT 0,
        subtotal DECIMAL(12,2) DEFAULT 0,
        stock_minimo INT DEFAULT 5,
        es_nuevo TINYINT(1) DEFAULT 0,
        FOREIGN KEY (compra_id) REFERENCES compras(id) ON DELETE CASCADE
    )");
} catch(Exception $e) {}

$msg = ''; $action = $_GET['action'] ?? 'list';

// ── POST: Guardar compra ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pa = $_POST['action'] ?? '';

    // Guardar proveedor
    if ($pa === 'save_proveedor') {
        header('Content-Type: application/json');
        $id = (int)($_POST['id'] ?? 0);
        $f = ['nombre','ruc','contacto','telefono','email','direccion'];
        $d = []; foreach($f as $k) $d[$k] = trim($_POST[$k]??'')?:null;
        try {
            if ($id) { $sets=implode(',',array_map(fn($k)=>"$k=:$k",$f)); $db->prepare("UPDATE proveedores SET $sets WHERE id=:id")->execute(array_merge($d,['id'=>$id])); }
            else { $cols=implode(',',$f); $pls=implode(',',array_map(fn($k)=>":$k",$f)); $db->prepare("INSERT INTO proveedores ($cols) VALUES ($pls)")->execute($d); $id=(int)$db->lastInsertId(); }
            $prov = $db->prepare("SELECT * FROM proveedores WHERE id=?"); $prov->execute([$id]); $prov=$prov->fetch();
            echo json_encode(['ok'=>true,'id'=>$id,'nombre'=>$prov['nombre']]);
        } catch(Exception $e) { echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
        exit;
    }

    // Guardar compra completa
    if ($pa === 'save_compra') {
        $proveedor_id = (int)($_POST['proveedor_id'] ?? 0);
        $proveedor_nombre = trim($_POST['proveedor_nombre'] ?? '');
        $fecha = $_POST['fecha'] ?? date('Y-m-d');
        $tipo_doc = $_POST['tipo_doc'] ?? 'factura';
        $nro_doc  = trim($_POST['nro_doc'] ?? '');
        $notas    = trim($_POST['notas'] ?? '');
        $sede_id  = getSede(); // sede activa del selector, no la del usuario

        // Calcular totales de los items
        $nombres    = $_POST['item_nombre']    ?? [];
        $cantidades = $_POST['item_cantidad']  ?? [];
        $precios    = $_POST['item_costo']     ?? [];
        $pventas    = $_POST['item_pventa']    ?? [];
        $lotes      = $_POST['item_lote']      ?? [];
        $vences     = $_POST['item_vence']     ?? [];
        $presentas  = $_POST['item_presenta']  ?? [];
        $laborats   = $_POST['item_laborat']   ?? [];
        $cod_barras = $_POST['item_codbar']    ?? [];
        $cat_ids    = $_POST['item_categoria'] ?? [];
        $stk_mins   = $_POST['item_stk_min']   ?? [];
        $prod_ids   = $_POST['item_producto_id'] ?? [];

        $subtotal_total = 0;
        $items_validos = [];
        foreach ($nombres as $i => $nom) {
            if (!trim($nom)) continue;
            $cant = max(1, (int)($cantidades[$i] ?? 1));
            $costo = (float)($precios[$i] ?? 0);
            $sub = round($cant * $costo, 2);
            $subtotal_total += $sub;
            $items_validos[] = [
                'nombre'          => trim($nom),
                'cantidad'        => $cant,
                'precio_costo'    => $costo,
                'precio_venta'    => (float)($pventas[$i] ?? 0),
                'lote'            => trim($lotes[$i] ?? '') ?: null,
                'fecha_vencimiento'=> trim($vences[$i] ?? '') ?: null,
                'presentacion'    => trim($presentas[$i] ?? '') ?: null,
                'laboratorio'     => trim($laborats[$i] ?? '') ?: null,
                'codigo_barras'   => trim($cod_barras[$i] ?? '') ?: null,
                'categoria_id'    => (int)($cat_ids[$i] ?? 0) ?: null,
                'stock_minimo'    => max(1,(int)($stk_mins[$i] ?? 5)),
                'producto_id'     => (int)($prod_ids[$i] ?? 0) ?: null,
                'subtotal'        => $sub,
            ];
        }

        $igv = round($subtotal_total * 0.18, 2);
        $total = round($subtotal_total + $igv, 2);

        // Numero correlativo
        $ultimo_num = 0;
        try { $ultimo_num = (int)$db->query("SELECT COUNT(*) FROM compras")->fetchColumn(); } catch(Exception $e){}
        $numero = 'OC-'.str_pad($ultimo_num+1, 5, '0', STR_PAD_LEFT);

        try {
            $db->prepare("INSERT INTO compras (numero,proveedor_id,proveedor_nombre,fecha,tipo_doc,nro_doc,subtotal,igv,total,estado,notas,usuario_id,sede_id) VALUES (?,?,?,?,?,?,?,?,?,'recibida',?,?,?)")
               ->execute([$numero,$proveedor_id?:null,$proveedor_nombre,$fecha,$tipo_doc,$nro_doc,$subtotal_total,$igv,$total,$notas,$user['id'],$sede_id]);
            $compra_id = (int)$db->lastInsertId();

            foreach ($items_validos as $item) {
                $prod_id = $item['producto_id'];

                // Si tiene producto_id → actualizar stock existente
                if ($prod_id) {
                    $st = $db->prepare("SELECT stock FROM productos WHERE id=?");
                    $st->execute([$prod_id]); $row = $st->fetch();
                    $stock_ant = $row['stock'] ?? 0;
                    $stock_nuevo = $stock_ant + $item['cantidad'];
                    $db->prepare("UPDATE productos SET stock=?, precio_costo=?, lote=?, fecha_vencimiento=?, updated_at=NOW() WHERE id=?")
                       ->execute([$stock_nuevo, $item['precio_costo'], $item['lote'], $item['fecha_vencimiento'], $prod_id]);
                    // Kardex
                    try {
                        $db->prepare("INSERT INTO kardex (producto_id,usuario_id,tipo,cantidad,stock_anterior,stock_nuevo,referencia,sede_id) VALUES (?,?,'entrada',?,?,?,?,?)")
                           ->execute([$prod_id,$user['id'],$item['cantidad'],$stock_ant,$stock_nuevo,'Compra '.$numero,$sede_id]);
                    } catch(Exception $e) {}
                    $item['es_nuevo'] = 0;
                } else {
                    // Producto nuevo → buscar si ya existe por nombre
                    $existe = $db->prepare("SELECT id,stock FROM productos WHERE nombre=? AND activo=1 AND sede_id=? LIMIT 1");
                    $existe->execute([$item['nombre'],$sede_id]);
                    $prod_exist = $existe->fetch();
                    if ($prod_exist) {
                        // Existe: sumar stock
                        $prod_id = $prod_exist['id'];
                        $stock_ant = $prod_exist['stock'];
                        $stock_nuevo = $stock_ant + $item['cantidad'];
                        $db->prepare("UPDATE productos SET stock=?, precio_costo=?, lote=?, fecha_vencimiento=?, updated_at=NOW() WHERE id=?")
                           ->execute([$stock_nuevo, $item['precio_costo'], $item['lote'], $item['fecha_vencimiento'], $prod_id]);
                        try {
                            $db->prepare("INSERT INTO kardex (producto_id,usuario_id,tipo,cantidad,stock_anterior,stock_nuevo,referencia,sede_id) VALUES (?,?,'entrada',?,?,?,?,?)")
                               ->execute([$prod_id,$user['id'],$item['cantidad'],$stock_ant,$stock_nuevo,'Compra '.$numero,$sede_id]);
                        } catch(Exception $e) {}
                        $item['es_nuevo'] = 0;
                    } else {
                        // No existe: crear producto nuevo
                        $db->prepare("INSERT INTO productos (sede_id,categoria_id,nombre,descripcion,presentacion,laboratorio,codigo_barras,stock,stock_minimo,precio_costo,precio_venta,lote,fecha_vencimiento,activo) VALUES (?,?,?,NULL,?,?,?,?,?,?,?,?,?,1)")
                           ->execute([$sede_id,$item['categoria_id'],$item['nombre'],$item['presentacion'],$item['laboratorio'],$item['codigo_barras'],$item['cantidad'],$item['stock_minimo'],$item['precio_costo'],$item['precio_venta'],$item['lote'],$item['fecha_vencimiento']]);
                        $prod_id = (int)$db->lastInsertId();
                        try {
                            $db->prepare("INSERT INTO kardex (producto_id,usuario_id,tipo,cantidad,stock_anterior,stock_nuevo,referencia,sede_id) VALUES (?,?,'entrada',?,0,?,?,?)")
                               ->execute([$prod_id,$user['id'],$item['cantidad'],$item['cantidad'],'Compra '.$numero.' (nuevo)',$sede_id]);
                        } catch(Exception $e) {}
                        $item['es_nuevo'] = 1;
                    }
                }

                // Guardar item de compra
                $db->prepare("INSERT INTO compra_items (compra_id,producto_id,nombre,presentacion,laboratorio,categoria_id,codigo_barras,lote,fecha_vencimiento,cantidad,precio_costo,precio_venta,subtotal,stock_minimo,es_nuevo) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                   ->execute([$compra_id,$prod_id,$item['nombre'],$item['presentacion'],$item['laboratorio'],$item['categoria_id'],$item['codigo_barras'],$item['lote'],$item['fecha_vencimiento'],$item['cantidad'],$item['precio_costo'],$item['precio_venta'],$item['subtotal'],$item['stock_minimo'],$item['es_nuevo']]);
            }

            // Notificación
            try {
                $db->prepare("INSERT INTO notificaciones (tipo,titulo,mensaje,icono,color,link,usuario_id,sede_id) VALUES ('sistema',?,?,?,?,?,?,?)")
                   ->execute(['Compra registrada: '.$numero,"Se registraron ".count($items_validos)." productos. Total: S/ ".number_format($total,2),'bell','#10b981','?p=compras',$user['id'],$sede_id]);
            } catch(Exception $e) {}

            $msg = 'ok'; $action = 'ver'; $_GET['id'] = $compra_id;
        } catch(Exception $e) {
            $msg = 'err:'.$e->getMessage(); $action = 'nueva';
        }
    }

    // Anular compra
    if ($pa === 'anular') {
        $cid = (int)($_POST['id'] ?? 0);
        try {
            // Revertir stock
            $items = $db->prepare("SELECT * FROM compra_items WHERE compra_id=?"); $items->execute([$cid]); $items=$items->fetchAll();
            foreach ($items as $it) {
                if ($it['producto_id']) {
                    $st=$db->prepare("SELECT stock FROM productos WHERE id=?"); $st->execute([$it['producto_id']]); $row=$st->fetch();
                    $nuevo=max(0,($row['stock']??0)-$it['cantidad']);
                    $db->prepare("UPDATE productos SET stock=? WHERE id=?")->execute([$nuevo,$it['producto_id']]);
                    try { $db->prepare("INSERT INTO kardex (producto_id,usuario_id,tipo,cantidad,stock_anterior,stock_nuevo,referencia,sede_id) VALUES (?,?,'ajuste',?,?,?,?,?)")->execute([$it['producto_id'],$user['id'],$it['cantidad'],$row['stock']??0,$nuevo,'Anulación compra ',$user['sede_id']??1]); }catch(Exception $e){}
                }
            }
            $db->prepare("UPDATE compras SET estado='anulada' WHERE id=?")->execute([$cid]);
            $msg='ok_anulada';
        } catch(Exception $e) { $msg='err:'.$e->getMessage(); }
        $action='list';
    }
}

// ── Datos para formularios ─────────────────────────────────
$proveedores = [];
try { $proveedores = $db->query("SELECT * FROM proveedores WHERE activo=1 ORDER BY nombre")->fetchAll(); } catch(Exception $e){}
$categorias = [];
try { $categorias = $db->query("SELECT * FROM categorias_producto ORDER BY nombre")->fetchAll(); } catch(Exception $e){}
$productos_list = [];
try {
    $_cp_sw = verTodasSedes() ? "" : " AND sede_id=".getSede();
    // Farmacia/inventario
    $prods_farm = $db->query("SELECT id, nombre, presentacion, precio_costo, precio_venta, stock, lote, fecha_vencimiento, codigo_barras, laboratorio, categoria_id, stock_minimo, 'farmacia' as origen FROM productos WHERE activo=1$_cp_sw ORDER BY nombre")->fetchAll();
    // Pet Shop
    $prods_pet = [];
    try {
        $_cp_swp = verTodasSedes() ? "" : " AND sede_id=".getSede();
        $prods_pet = $db->query("SELECT id, nombre, '' as presentacion, precio_costo, precio_venta, stock, '' as lote, NULL as fecha_vencimiento, COALESCE(codigo_barras,'') as codigo_barras, '' as laboratorio, NULL as categoria_id, stock_minimo, 'petshop' as origen FROM petshop_productos WHERE activo=1$_cp_swp ORDER BY nombre")->fetchAll();
    } catch(Exception $e){}
    // Combinar y ordenar por nombre
    $productos_list = array_merge($prods_farm, $prods_pet);
    usort($productos_list, fn($a,$b) => strcmp($a['nombre'], $b['nombre']));
} catch(Exception $e){}

// ── VER COMPRA ─────────────────────────────────────────────
if ($action === 'ver' && isset($_GET['id'])) {
    $cid = (int)$_GET['id'];
    $compra=null; $citems=[];
    try { $st=$db->prepare("SELECT c.*,u.nombre as usuario FROM compras c LEFT JOIN usuarios u ON u.id=c.usuario_id WHERE c.id=?"); $st->execute([$cid]); $compra=$st->fetch(); } catch(Exception $e){}
    try { $citems=$db->prepare("SELECT ci.*,p.categoria_id as cat FROM compra_items ci LEFT JOIN productos p ON p.id=ci.producto_id WHERE ci.compra_id=?")->execute([$cid]) ? $db->prepare("SELECT ci.*,cat.nombre as cat_nombre FROM compra_items ci LEFT JOIN categorias_producto cat ON cat.id=ci.categoria_id WHERE ci.compra_id=?"): null; $citems_st=$db->prepare("SELECT ci.*,cat.nombre as cat_nombre FROM compra_items ci LEFT JOIN categorias_producto cat ON cat.id=ci.categoria_id WHERE ci.compra_id=?"); $citems_st->execute([$cid]); $citems=$citems_st->fetchAll(); } catch(Exception $e){}
    if (!$compra) { header('Location: ?p=compras'); exit; }
?>
<div class="page">
<?php if($msg==='ok_anulada'): ?><div class="alert alert-danger mb-3">✅ Compra anulada y stock revertido.</div><?php endif; ?>
<div class="sec-header mb-3">
  <div>
    <div class="page-title">🛒 Compra <?= clean($compra['numero']) ?></div>
    <div class="page-desc"><?= date('d/m/Y',strtotime($compra['fecha'])) ?> · <?= clean($compra['proveedor_nombre']??'Sin proveedor') ?></div>
  </div>
  <div class="flex gap-2">
    <a href="?p=compras" class="btn btn-ghost btn-sm">← Volver</a>
    <?php if($compra['estado']==='recibida'): ?>
    <form method="POST" onsubmit="return confirm('¿Anular esta compra? Se revertirá el stock.')">
      <input type="hidden" name="action" value="anular"><input type="hidden" name="id" value="<?= $compra['id'] ?>">
      <button type="submit" class="btn btn-sm" style="color:var(--danger);border-color:var(--danger)">🚫 Anular</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:16px;margin-bottom:16px">
  <!-- Info compra -->
  <div class="card">
    <div class="grid g2" style="gap:12px;font-size:13px">
      <div><span style="color:var(--text3)">N° Orden:</span> <strong><?= clean($compra['numero']) ?></strong></div>
      <div><span style="color:var(--text3)">Tipo doc:</span> <strong><?= ucfirst($compra['tipo_doc']) ?></strong><?= $compra['nro_doc']?' · '.clean($compra['nro_doc']):'' ?></div>
      <div><span style="color:var(--text3)">Proveedor:</span> <strong><?= clean($compra['proveedor_nombre']??'—') ?></strong></div>
      <div><span style="color:var(--text3)">Fecha:</span> <strong><?= date('d/m/Y',strtotime($compra['fecha'])) ?></strong></div>
      <div><span style="color:var(--text3)">Registrado por:</span> <?= clean($compra['usuario']??'—') ?></div>
      <div><span style="color:var(--text3)">Estado:</span> <span class="badge <?= $compra['estado']==='recibida'?'b-success':($compra['estado']==='anulada'?'b-danger':'b-warning') ?>"><?= ucfirst($compra['estado']) ?></span></div>
    </div>
    <?php if($compra['notas']): ?><div style="margin-top:12px;font-size:12px;color:var(--text2);background:var(--bg3);padding:10px;border-radius:8px">📝 <?= nl2br(clean($compra['notas'])) ?></div><?php endif; ?>
  </div>
  <!-- Totales -->
  <div class="card" style="text-align:right">
    <div style="font-size:12px;color:var(--text3);margin-bottom:6px">Subtotal</div>
    <div style="font-size:18px;font-weight:700;margin-bottom:8px">S/ <?= number_format($compra['subtotal'],2) ?></div>
    <div style="font-size:12px;color:var(--text3);margin-bottom:4px">IGV (18%)</div>
    <div style="font-size:15px;font-weight:600;margin-bottom:12px">S/ <?= number_format($compra['igv'],2) ?></div>
    <div style="border-top:2px solid var(--border);padding-top:12px">
      <div style="font-size:12px;color:var(--text3)">TOTAL</div>
      <div style="font-size:26px;font-weight:800;color:var(--success)">S/ <?= number_format($compra['total'],2) ?></div>
    </div>
  </div>
</div>

<!-- Items de la compra -->
<div class="card" style="padding:0">
  <div style="padding:14px 18px;border-bottom:1px solid var(--border);font-size:14px;font-weight:700">
    📦 Productos recibidos (<?= count($citems) ?>)
  </div>
  <table class="vtable">
    <thead><tr>
      <th>Producto</th><th>Presentación</th><th>Lote</th><th>Vencimiento</th>
      <th style="text-align:center">Cant.</th><th style="text-align:right">P. Costo</th>
      <th style="text-align:right">P. Venta</th><th style="text-align:right">Subtotal</th><th>Estado</th>
    </tr></thead>
    <tbody>
    <?php foreach($citems as $it): ?>
    <tr>
      <td class="td-main"><?= clean($it['nombre']) ?><?php if($it['cat_nombre']??''): ?><div class="text-xs text-muted"><?= clean($it['cat_nombre']) ?></div><?php endif; ?></td>
      <td class="text-muted"><?= clean($it['presentacion']??'—') ?></td>
      <td class="text-muted" style="font-family:monospace"><?= clean($it['lote']??'—') ?></td>
      <td class="<?= ($it['fecha_vencimiento']&&strtotime($it['fecha_vencimiento'])<strtotime('+90 days'))?'color-danger':'' ?>"><?= $it['fecha_vencimiento']?date('d/m/Y',strtotime($it['fecha_vencimiento'])):'—' ?></td>
      <td style="text-align:center;font-weight:700"><?= $it['cantidad'] ?></td>
      <td style="text-align:right">S/ <?= number_format($it['precio_costo'],2) ?></td>
      <td style="text-align:right;color:var(--success)">S/ <?= number_format($it['precio_venta'],2) ?></td>
      <td style="text-align:right;font-weight:700">S/ <?= number_format($it['subtotal'],2) ?></td>
      <td><?php if($it['es_nuevo']): ?><span class="badge b-success">✨ Nuevo</span><?php else: ?><span class="badge b-info">📦 Actualizado</span><?php endif; ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
</div>
<?php require_once __DIR__.'/../includes/footer.php'; return;
}

// ── FORMULARIO NUEVA COMPRA ────────────────────────────────
if ($action === 'nueva') { ?>
<style>
.compra-item-row { display:grid; grid-template-columns:2.5fr 1fr 1fr 1fr 1fr 1fr 80px; gap:6px; align-items:end; margin-bottom:8px; padding:10px 12px; background:var(--bg3); border-radius:10px; border:1px solid var(--border); }
@media(max-width:768px){ .compra-item-row { grid-template-columns:1fr 1fr; } }
.compra-item-row .form-label { font-size:10px; margin-bottom:3px; }
</style>

<div class="page">
<?php if(substr($msg??'',0,3)==='err'): ?><div class="alert alert-danger mb-3">❌ <?= clean(substr($msg,4)) ?></div><?php endif; ?>

<div class="sec-header mb-3">
  <div><div class="page-title">🛒 Nueva Compra</div><div class="page-desc">Registrar entrada de productos</div></div>
  <a href="?p=compras" class="btn btn-ghost btn-sm">← Volver</a>
</div>

<form method="POST" id="form-compra">
<input type="hidden" name="action" value="save_compra">

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">
  <!-- Datos del proveedor -->
  <div class="card">
    <div style="font-size:13px;font-weight:700;color:var(--text2);margin-bottom:12px;display:flex;align-items:center;justify-content:space-between">
      🏭 Proveedor
      <button type="button" onclick="abrirModalProveedor()" class="btn btn-xs" style="color:var(--primary);border-color:var(--primary)">＋ Nuevo proveedor</button>
    </div>
    <div class="form-group" style="position:relative">
      <label class="form-label">Buscar proveedor</label>
      <input type="text" id="inp-proveedor-nombre" name="proveedor_nombre"
        class="form-input" placeholder="Escribe el nombre o RUC del proveedor..."
        oninput="buscarProveedor(this.value)"
        onblur="setTimeout(function(){document.getElementById('prov-drop').style.display='none'},200)"
        autocomplete="off">
      <input type="hidden" name="proveedor_id" id="inp-proveedor-id" value="">
      <div id="prov-drop" style="display:none;position:absolute;top:100%;left:0;right:0;background:var(--bg2);border:1px solid var(--border);border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:400;max-height:220px;overflow-y:auto"></div>
    </div>
    <div style="font-size:11px;color:var(--text3);margin-top:4px">💡 Si no existe, escribe el nombre y haz clic en "＋ Nuevo proveedor"</div>
  </div>
  <!-- Datos del documento -->
  <div class="card">
    <div style="font-size:13px;font-weight:700;color:var(--text2);margin-bottom:12px">📄 Datos del documento</div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Tipo doc.</label>
        <select class="form-input" name="tipo_doc">
          <option value="factura">Factura</option>
          <option value="boleta">Boleta</option>
          <option value="nota_credito">Nota de crédito</option>
          <option value="ticket">Ticket</option>
          <option value="otro">Otro</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">N° Documento</label>
        <input class="form-input" name="nro_doc" placeholder="F001-00123">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label required">Fecha compra</label>
        <input class="form-input" type="date" name="fecha" value="<?= date('Y-m-d') ?>" required>
      </div>
      <div class="form-group">
        <label class="form-label">Notas</label>
        <input class="form-input" name="notas" placeholder="Observaciones opcionales">
      </div>
    </div>
  </div>
</div>

<!-- ITEMS DE LA COMPRA -->
<div class="card" style="padding:0;margin-bottom:16px">
  <div style="padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
    <div style="font-size:14px;font-weight:700">📦 Productos a comprar</div>
    <div style="display:flex;gap:8px">
      <!-- Buscador rápido de producto existente -->
      <div style="position:relative">
        <input type="text" id="busq-prod" placeholder="🔍 Buscar producto existente..." oninput="buscarProducto(this.value)"
          style="padding:7px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:12px;width:250px;outline:none"
          onfocus="this.style.borderColor='var(--primary)'" onblur="setTimeout(()=>document.getElementById('prod-drop').style.display='none',200)">
        <div id="prod-drop" style="display:none;position:absolute;top:100%;left:0;right:0;background:var(--bg2);border:1px solid var(--border);border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:300;max-height:240px;overflow-y:auto;min-width:320px"></div>
      </div>
      <button type="button" onclick="addItem()" class="btn btn-sm btn-primary">＋ Agregar producto</button>
    </div>
  </div>

  <div style="padding:12px 16px">
    <!-- Cabecera -->
    <div style="display:grid;grid-template-columns:2.5fr 1fr 1fr 1fr 1fr 1fr 80px;gap:6px;padding:0 12px;margin-bottom:4px">
      <?php foreach(['Producto / Nombre','Presentación','Laboratorio','Lote','Vence','Cant. / Costos',''] as $h): ?>
      <div style="font-size:10px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.5px"><?= $h ?></div>
      <?php endforeach; ?>
    </div>
    <div id="items-compra"></div>
    <div id="items-empty" style="text-align:center;padding:40px;color:var(--text3)">
      <div style="font-size:32px;margin-bottom:8px;opacity:.3">📦</div>
      <div style="font-size:13px">Agrega productos usando el botón o buscador</div>
    </div>
  </div>

  <!-- Totales -->
  <div style="padding:16px 18px;border-top:1px solid var(--border);background:var(--bg3);display:flex;justify-content:flex-end">
    <div style="min-width:240px">
      <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px"><span style="color:var(--text3)">Subtotal:</span><span id="tot-sub" style="font-weight:600">S/ 0.00</span></div>
      <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px"><span style="color:var(--text3)">IGV (18%):</span><span id="tot-igv" style="font-weight:600">S/ 0.00</span></div>
      <div style="display:flex;justify-content:space-between;font-size:16px;font-weight:800;border-top:2px solid var(--border);padding-top:8px;margin-top:8px"><span>TOTAL:</span><span id="tot-total" style="color:var(--success)">S/ 0.00</span></div>
    </div>
  </div>
</div>

<div class="flex gap-2">
  <button type="submit" class="btn btn-primary btn-lg" onclick="return validarCompra()">💾 Registrar compra</button>
  <a href="?p=compras" class="btn btn-ghost">Cancelar</a>
</div>
</form>

<!-- Modal Proveedor -->
<div id="modal-prov" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:2000;align-items:center;justify-content:center" onclick="if(event.target===this)this.style.display='none'">
  <div style="background:var(--bg2);border-radius:16px;padding:24px;width:480px;max-width:95vw">
    <div style="font-size:15px;font-weight:700;margin-bottom:16px">🏭 Nuevo Proveedor</div>
    <div class="form-row"><div class="form-group"><label class="form-label required">Nombre</label><input class="form-input" id="pv-nombre" required></div><div class="form-group"><label class="form-label">RUC</label><input class="form-input" id="pv-ruc" maxlength="11"></div></div>
    <div class="form-row"><div class="form-group"><label class="form-label">Contacto</label><input class="form-input" id="pv-contacto"></div><div class="form-group"><label class="form-label">Teléfono</label><input class="form-input" id="pv-telefono"></div></div>
    <div class="form-group"><label class="form-label">Email</label><input class="form-input" type="email" id="pv-email"></div>
    <div class="form-group"><label class="form-label">Dirección</label><input class="form-input" id="pv-direccion"></div>
    <div class="flex gap-2 mt-3">
      <button type="button" id="btn-guardar-prov" onclick="guardarProveedor()" class="btn btn-primary">💾 Guardar</button>
      <button type="button" onclick="document.getElementById('modal-prov').style.display='none'" class="btn btn-ghost">Cancelar</button>
    </div>
  </div>
</div>

<script>
var _cats  = <?= json_encode(array_values($categorias)) ?>;
var _prods = <?= json_encode(array_values($productos_list)) ?>;
var _provs = <?= json_encode(array_values($proveedores)) ?>;
var _idx   = 0;
var _prodStore = {};
var _PROV_API  = '<?= BASE_URL ?>/api/proveedores.php';

// ── Categorías select ──
function buildCatOpts(selCatId) {
  var html = '<option value="">— Sin categoría —</option>';
  _cats.forEach(function(c) {
    html += '<option value="' + c.id + '"' + (selCatId == c.id ? ' selected' : '') + '>' + c.nombre + '</option>';
  });
  return html;
}

// ── Agregar fila de producto ──
function addItem(prod) {
  prod = prod || {};
  var idx = _idx++;
  _prodStore[idx] = prod;

  var nombre     = (prod.nombre||'').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
  var presenta   = (prod.presentacion||'').replace(/"/g,'&quot;');
  var laborat    = (prod.laboratorio||'').replace(/"/g,'&quot;');
  var codbar     = (prod.codigo_barras||'').replace(/"/g,'&quot;');
  var lote       = (prod.lote||'').replace(/"/g,'&quot;');
  var vence      = prod.fecha_vencimiento||'';
  var costo      = prod.precio_costo||'';
  var pventa     = prod.precio_venta||'';
  var stock      = prod.stock||0;
  var stkMin     = prod.stock_minimo||5;
  var prodId     = prod.id||'';

  var catOpts = buildCatOpts(prod.categoria_id||0);

  var row = document.createElement('div');
  row.className = 'compra-item-row';
  row.id = 'row-' + idx;

  row.innerHTML =
    '<div>' +
      '<div style="margin-bottom:6px">' +
        '<div class="form-label">Nombre del producto *</div>' +
        '<input class="form-input" name="item_nombre[]" value="' + nombre + '" placeholder="Nombre del producto" required oninput="calcTotales()">' +
      '</div>' +
      '<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:6px">' +
        '<div><div class="form-label">Presentación</div><input class="form-input" name="item_presenta[]" value="' + presenta + '" placeholder="Ej: Tab x100"></div>' +
        '<div><div class="form-label">Laboratorio</div><input class="form-input" name="item_laborat[]" value="' + laborat + '" placeholder="Ej: Bayer"></div>' +
      '</div>' +
      '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px">' +
        '<div><div class="form-label">Cód. barras</div><input class="form-input" name="item_codbar[]" value="' + codbar + '"></div>' +
        '<div><div class="form-label">Categoría</div><select class="form-input" name="item_categoria[]">' + catOpts + '</select></div>' +
        '<div><div class="form-label">Stock mín.</div><input class="form-input" type="number" name="item_stk_min[]" value="' + stkMin + '" min="1"></div>' +
      '</div>' +
      '<input type="hidden" name="item_producto_id[]" value="' + prodId + '">' +
    '</div>' +

    '<div>' +
      '<div class="form-label">Lote</div>' +
      '<input class="form-input" name="item_lote[]" value="' + lote + '" placeholder="L2025-01">' +
    '</div>' +

    '<div>' +
      '<div class="form-label">Vencimiento</div>' +
      '<input class="form-input" type="date" name="item_vence[]" value="' + vence + '">' +
    '</div>' +

    '<div>' +
      '<div class="form-label">Cantidad *</div>' +
      '<input class="form-input" type="number" name="item_cantidad[]" value="1" min="1" oninput="calcTotales()" style="font-size:15px;font-weight:700;color:var(--primary)">' +
      '<div style="font-size:10px;color:var(--text3);margin-top:3px">Stock actual: <strong>' + stock + '</strong></div>' +
    '</div>' +

    '<div>' +
      '<div class="form-label">Precio costo *</div>' +
      '<input class="form-input" type="number" step="0.01" name="item_costo[]" value="' + costo + '" placeholder="0.00" oninput="calcTotales()">' +
      '<div class="form-label" style="margin-top:6px">Precio venta</div>' +
      '<input class="form-input" type="number" step="0.01" name="item_pventa[]" value="' + pventa + '" placeholder="0.00">' +
    '</div>' +

    '<div>' +
      '<div class="form-label">Subtotal</div>' +
      '<div id="sub-' + idx + '" style="font-size:14px;font-weight:800;color:var(--success);padding:8px 0">S/ 0.00</div>' +
      '<button type="button" onclick="removeItem(\'row-' + idx + '\')" class="btn btn-sm" style="color:var(--danger);border-color:var(--danger);width:100%;margin-top:6px">✕ Quitar</button>' +
    '</div>';

  document.getElementById('items-compra').appendChild(row);
  document.getElementById('items-empty').style.display = 'none';
  // Focus en el nombre si está vacío
  if (!prod.nombre) {
    var inp = row.querySelector('[name="item_nombre[]"]');
    if (inp) inp.focus();
  }
  calcTotales();
}

function removeItem(id) {
  var el = document.getElementById(id);
  if (el) el.remove();
  if (!document.querySelectorAll('.compra-item-row').length) {
    document.getElementById('items-empty').style.display = 'block';
  }
  calcTotales();
}

// ── Calcular totales ──
function calcTotales() {
  var rows = document.querySelectorAll('.compra-item-row');
  var subtotal = 0;
  rows.forEach(function(row) {
    var cant  = parseFloat(row.querySelector('[name="item_cantidad[]"]').value) || 0;
    var costo = parseFloat(row.querySelector('[name="item_costo[]"]').value) || 0;
    var sub   = Math.round(cant * costo * 100) / 100;
    subtotal += sub;
    var subEl = row.querySelector('[id^="sub-"]');
    if (subEl) subEl.textContent = 'S/ ' + sub.toFixed(2);
  });
  var igv   = Math.round(subtotal * 0.18 * 100) / 100;
  var total = Math.round((subtotal + igv) * 100) / 100;
  document.getElementById('tot-sub').textContent   = 'S/ ' + subtotal.toFixed(2);
  document.getElementById('tot-igv').textContent   = 'S/ ' + igv.toFixed(2);
  document.getElementById('tot-total').textContent = 'S/ ' + total.toFixed(2);
}

// ── Buscador de productos ──
var _busqTimer = null;
function buscarProducto(val) {
  var drop = document.getElementById('prod-drop');
  clearTimeout(_busqTimer);
  if (!val || val.length < 1) { drop.style.display = 'none'; return; }
  _busqTimer = setTimeout(function() {
    var matches = _prods.filter(function(p) {
      return p.nombre.toLowerCase().indexOf(val.toLowerCase()) >= 0;
    }).slice(0, 10);
    if (!matches.length) {
      drop.innerHTML = '<div style="padding:12px 14px;font-size:12px;color:var(--text3)">Sin resultados — puedes agregarlo como producto nuevo</div>';
      drop.style.display = 'block';
      return;
    }
    var html = '';
    matches.forEach(function(p, i) {
      var badge = p.origen === 'petshop'
        ? '<span style="font-size:10px;padding:1px 7px;border-radius:999px;background:#ede9fe;color:#6d28d9;font-weight:700">🛒 Pet Shop</span>'
        : '<span style="font-size:10px;padding:1px 7px;border-radius:999px;background:rgba(30,168,161,.12);color:var(--primary);font-weight:700">💊 Farmacia</span>';
      html += '<div class="prod-drop-item" data-idx="' + i + '" style="padding:10px 14px;cursor:pointer;border-bottom:1px solid var(--border)"'
        + ' onmouseover="this.style.background=\'var(--bg3)\'"'
        + ' onmouseout="this.style.background=\'\'">'
        + '<div style="display:flex;align-items:center;gap:6px;margin-bottom:2px">'
        + '<span style="font-size:13px;font-weight:600">' + p.nombre + '</span>' + badge + '</div>'
        + '<div style="font-size:11px;color:var(--text3)">Stock: <strong>' + p.stock + '</strong>'
        + (p.presentacion ? ' · ' + p.presentacion : '')
        + ' · Costo: S/' + parseFloat(p.precio_costo||0).toFixed(2)
        + ' · Venta: S/' + parseFloat(p.precio_venta||0).toFixed(2)
        + '</div>'
        + '</div>';
    });
    drop.innerHTML = html;
    drop.style.display = 'block';
    drop.querySelectorAll('.prod-drop-item').forEach(function(el, i) {
      el.addEventListener('mousedown', function(e) {
        e.preventDefault();
        addItem(matches[i]);
        document.getElementById('busq-prod').value = '';
        drop.style.display = 'none';
      });
    });
  }, 150);
}

function renderProvDrop(matches, drop) {
  if (!matches.length) { drop.style.display = 'none'; return; }
  var html = '';
  matches.forEach(function(p) {
    html += '<div class="prov-drop-item" style="padding:10px 14px;cursor:pointer;border-bottom:1px solid var(--border)"'
      + ' onmouseover="this.style.background=\'var(--bg3)\'"'
      + ' onmouseout="this.style.background=\'\'">'
      + '<div style="font-size:13px;font-weight:600">' + p.nombre + '</div>'
      + (p.ruc ? '<div style="font-size:11px;color:var(--text3)">RUC: ' + p.ruc + (p.telefono?' · Tel: '+p.telefono:'') + '</div>' : '')
      + '</div>';
  });
  drop.innerHTML = html;
  drop.style.display = 'block';
  drop.querySelectorAll('.prov-drop-item').forEach(function(el, i) {
    el.addEventListener('mousedown', function(e) {
      e.preventDefault();
      document.getElementById('inp-proveedor-nombre').value = matches[i].nombre;
      document.getElementById('inp-proveedor-id').value     = matches[i].id;
      drop.style.display = 'none';
    });
  });
}

// ── Buscador de proveedor (autocomplete via API) ──
var _provTimer = null;
function buscarProveedor(val) {
  var drop = document.getElementById('prov-drop');
  document.getElementById('inp-proveedor-id').value = '';
  clearTimeout(_provTimer);
  if (!val || val.length < 1) { drop.style.display = 'none'; return; }
  // Primero buscar en caché local
  var matches = _provs.filter(function(p) {
    return p.nombre.toLowerCase().indexOf(val.toLowerCase()) >= 0
      || (p.ruc && p.ruc.indexOf(val) >= 0);
  }).slice(0, 8);
  renderProvDrop(matches, drop);
}

// ── Modal nuevo proveedor ──
function abrirModalProveedor() {
  ['pv-nombre','pv-ruc','pv-contacto','pv-telefono','pv-email','pv-direccion'].forEach(function(id) {
    document.getElementById(id).value = '';
  });
  document.getElementById('modal-prov').style.display = 'flex';
}

function guardarProveedor() {
  var nombre = document.getElementById('pv-nombre').value.trim();
  if (!nombre) { alert('El nombre del proveedor es requerido'); return; }
  var btn = document.getElementById('btn-guardar-prov');
  btn.disabled = true; btn.textContent = 'Guardando...';
  var fd = new FormData();
  fd.append('action',    'save');
  fd.append('nombre',    nombre);
  fd.append('ruc',       document.getElementById('pv-ruc').value);
  fd.append('contacto',  document.getElementById('pv-contacto').value);
  fd.append('telefono',  document.getElementById('pv-telefono').value);
  fd.append('email',     document.getElementById('pv-email').value);
  fd.append('direccion', document.getElementById('pv-direccion').value);
  fetch(_PROV_API, {method:'POST', body:fd})
    .then(function(r){ return r.json(); })
    .then(function(d) {
      if (d.ok) {
        _provs.push({id:d.id, nombre:d.nombre, ruc:d.ruc||'', telefono:''});
        document.getElementById('inp-proveedor-nombre').value = d.nombre;
        document.getElementById('inp-proveedor-id').value     = d.id;
        document.getElementById('modal-prov').style.display   = 'none';
        // Flash de confirmación
        var inp = document.getElementById('inp-proveedor-nombre');
        inp.style.borderColor = '#10b981';
        setTimeout(function(){ inp.style.borderColor=''; }, 2000);
      } else {
        alert('Error: ' + (d.error||'No se pudo guardar'));
        btn.disabled = false; btn.textContent = '💾 Guardar';
      }
    })
    .catch(function(e) {
      alert('Error de conexión con el servidor. Verifica que no tengas un bloqueador de anuncios activo o intenta en modo incógnito.');
      btn.disabled = false; btn.textContent = '💾 Guardar';
    });
}

function validarCompra() {
  var rows = document.querySelectorAll('.compra-item-row');
  if (!rows.length) { alert('Agrega al menos un producto antes de registrar la compra.'); return false; }
  var ok = true;
  rows.forEach(function(row) {
    var nom   = row.querySelector('[name="item_nombre[]"]').value.trim();
    var costo = parseFloat(row.querySelector('[name="item_costo[]"]').value)||0;
    if (!nom)   { alert('Todos los productos deben tener nombre.'); ok=false; }
    if (!costo) { alert('El precio costo de "'+nom+'" no puede ser 0.'); ok=false; }
  });
  return ok;
}
</script>
</div>
<?php require_once __DIR__.'/../includes/footer.php'; return; }

// ── LISTA DE COMPRAS ───────────────────────────────────────
$_csw = ""; // filtro sede compras
try {
    $_r=$db->query("SHOW COLUMNS FROM `compras` LIKE 'sede_id'")->fetchAll();
    if(!empty($_r) && !verTodasSedes()) { $_csw = " AND c.sede_id=".getSede(); }
} catch(Exception $e) {}
$compras=[];
try {
    $compras=$db->query("SELECT c.*,u.nombre as usuario FROM compras c LEFT JOIN usuarios u ON u.id=c.usuario_id WHERE 1=1$_csw ORDER BY c.fecha DESC,c.id DESC LIMIT 100")->fetchAll();
}catch(Exception $e){}
// Estadísticas
$tot_mes=0; $tot_productos=0; $compras_mes=0;
$_csw2 = str_replace(" AND c.", " AND ", $_csw); // sin alias para queries simples
try { $tot_mes=(float)$db->query("SELECT COALESCE(SUM(total),0) FROM compras WHERE MONTH(fecha)=MONTH(CURDATE()) AND estado='recibida'$_csw2")->fetchColumn(); }catch(Exception $e){}
try { $compras_mes=(int)$db->query("SELECT COUNT(*) FROM compras WHERE MONTH(fecha)=MONTH(CURDATE()) AND estado='recibida'$_csw2")->fetchColumn(); }catch(Exception $e){}
try { $tot_productos=(int)$db->query("SELECT COALESCE(SUM(cantidad),0) FROM compra_items ci JOIN compras c ON c.id=ci.compra_id WHERE MONTH(c.fecha)=MONTH(CURDATE()) AND c.estado='recibida'$_csw")->fetchColumn(); }catch(Exception $e){}
?>
<div class="page">
<?php if($msg==='ok'): ?><div class="alert alert-success mb-3">✅ Compra registrada y stock actualizado correctamente.</div><?php elseif($msg==='ok_anulada'): ?><div class="alert alert-danger mb-3">✅ Compra anulada.</div><?php endif; ?>

<div class="sec-header mb-3">
  <div><div class="page-title">🛒 Compras</div><div class="page-desc">Registro de entradas y proveedores</div></div>
  <a href="?p=compras&action=nueva" class="btn btn-primary">＋ Nueva Compra</a>
</div>

<!-- KPIs -->
<div class="grid g3 mb-3">
  <div class="stat-card">
    <div class="stat-icon si-success">🛒</div>
    <div class="stat-value"><?= $compras_mes ?></div>
    <div class="stat-label">Compras este mes</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon si-warning">💰</div>
    <div class="stat-value">S/ <?= number_format($tot_mes,0) ?></div>
    <div class="stat-label">Gasto del mes</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon si-info">📦</div>
    <div class="stat-value"><?= $tot_productos ?></div>
    <div class="stat-label">Productos ingresados</div>
  </div>
</div>

<!-- Tabla de compras -->
<div class="card" style="padding:0">
  <table class="vtable">
    <thead><tr>
      <th>N° Orden</th><th>Fecha</th><th>Proveedor</th><th>Tipo doc.</th>
      <th style="text-align:center">Items</th><th style="text-align:right">Total</th>
      <th>Estado</th><th>Registrado por</th><th>Acciones</th>
    </tr></thead>
    <tbody>
    <?php foreach($compras as $c):
      $n_items=0; try{$n_items=(int)$db->query("SELECT COUNT(*) FROM compra_items WHERE compra_id={$c['id']}")->fetchColumn();}catch(Exception $e){}
    ?>
    <tr>
      <td class="td-main" style="font-family:monospace;color:var(--primary)"><?= clean($c['numero']) ?></td>
      <td><?= date('d/m/Y',strtotime($c['fecha'])) ?></td>
      <td><?= clean($c['proveedor_nombre']??'—') ?></td>
      <td><span class="badge b-info"><?= ucfirst($c['tipo_doc']) ?></span><?= $c['nro_doc']?'<div class="text-xs text-muted">'.clean($c['nro_doc']).'</div>':'' ?></td>
      <td style="text-align:center;font-weight:700"><?= $n_items ?></td>
      <td style="text-align:right;font-weight:700;color:var(--success)">S/ <?= number_format($c['total'],2) ?></td>
      <td><span class="badge <?= $c['estado']==='recibida'?'b-success':($c['estado']==='anulada'?'b-danger':'b-warning') ?>"><?= ucfirst($c['estado']) ?></span></td>
      <td class="text-muted"><?= clean($c['usuario']??'—') ?></td>
      <td>
        <div class="flex gap-1">
          <a href="?p=compras&action=ver&id=<?= $c['id'] ?>" class="btn btn-xs btn-outline-primary">Ver</a>
          <?php if($c['estado']==='recibida'): ?>
          <form method="POST" onsubmit="return confirm('¿Anular esta compra?')">
            <input type="hidden" name="action" value="anular"><input type="hidden" name="id" value="<?= $c['id'] ?>">
            <button type="submit" class="btn btn-xs" style="color:var(--danger)">✕</button>
          </form>
          <?php endif; ?>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if(empty($compras)): ?>
    <tr><td colspan="9" style="text-align:center;padding:48px;color:var(--text3)">
      <div style="font-size:40px;margin-bottom:12px;opacity:.3">🛒</div>
      <div style="font-size:14px;font-weight:600">Sin compras registradas</div>
      <a href="?p=compras&action=nueva" class="btn btn-primary btn-sm" style="margin-top:12px">Registrar primera compra</a>
    </td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
</div>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
