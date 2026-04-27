<?php
$page = 'facturacion'; $pageTitle = 'Facturación';
require_once __DIR__ . '/../includes/header.php';
$db = getDB();
$action = $_GET['action'] ?? 'list';
$msg = '';

// ─── POST HANDLER ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pa = $_POST['action'] ?? '';

    // ── GUARDAR VENTA ──
    if ($pa === 'save') {
        $cliente_id = (int)$_POST['cliente_id'];
        $tipo  = $_POST['tipo_comprobante'] ?? 'boleta';
        $serie = $tipo === 'factura' ? 'F001' : ($tipo === 'ticket' ? 'T001' : 'B001');

        $st = $db->prepare("SELECT COALESCE(MAX(numero),0)+1 FROM ventas WHERE serie=?");
        $st->execute([$serie]); $numero = (int)$st->fetchColumn();

        // ── Procesar ítems con campos planos (más robusto que arrays anidados) ──
        $items_ok = [];
        $subtotal = 0;
        $n = count($_POST['item_desc'] ?? []);
        for ($i = 0; $i < $n; $i++) {
            $desc  = trim(($_POST['item_desc'][$i])  ?? '');
            $qty   = max(1, (int)(($_POST['item_qty'][$i])   ?? 1));
            $price = (float)(($_POST['item_precio'][$i]) ?? 0);
            $tipo_it = ($_POST['item_tipo'][$i])  ?? 'servicio';
            $ref_id  = (int)(($_POST['item_ref'][$i])   ?? 0);
            if ($price <= 0 && $desc === '') continue;
            if ($price <= 0) continue;
            $sub = round($qty * $price, 2);
            $subtotal += $sub;
            $items_ok[] = [
                'tipo'  => $tipo_it ?: 'servicio',
                'ref'   => $ref_id,
                'desc'  => $desc ?: 'Servicio veterinario',
                'qty'   => $qty,
                'precio'=> $price,
                'sub'   => $sub,
            ];
        }

        if (empty($items_ok)) {
            $msg = 'error_items';
        } else {
            $descuento = (float)($_POST['descuento'] ?? 0);
            $base  = $subtotal - $descuento;
            $igv   = round($base * 0.18, 2);
            $total = round($base + $igv, 2);

            $st = $db->prepare("INSERT INTO ventas (sede_id,cliente_id,mascota_id,usuario_id,tipo_comprobante,serie,numero,subtotal,igv,descuento,total,metodo_pago,estado,notas) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $st->execute([
                $user['sede_id'] ?? 1,
                $cliente_id,
                (int)($_POST['mascota_id'] ?? 0) ?: null,
                $user['id'],
                $tipo, $serie, $numero,
                $subtotal, $igv, $descuento, $total,
                $_POST['metodo_pago'] ?? 'efectivo',
                'pagado',
                trim($_POST['notas'] ?? '')
            ]);
            $venta_id = (int)$db->lastInsertId();

            $st2 = $db->prepare("INSERT INTO venta_items (venta_id,tipo,referencia_id,descripcion,cantidad,precio_unitario,subtotal) VALUES (?,?,?,?,?,?,?)");
            foreach ($items_ok as $it) {
                $st2->execute([$venta_id, $it['tipo'], $it['ref'], $it['desc'], $it['qty'], $it['precio'], $it['sub']]);
            }

            // Movimiento de caja
            $caja = $db->query("SELECT id FROM cajas WHERE estado='abierta' ORDER BY id DESC LIMIT 1")->fetchColumn();
            if ($caja) {
                $db->prepare("INSERT INTO movimientos_caja (caja_id,usuario_id,tipo,concepto,monto,metodo_pago,categoria,venta_id) VALUES (?,?,'ingreso',?,?,?,'servicio',?)")
                   ->execute([$caja, $user['id'], "Venta $serie-".str_pad($numero,5,'0',STR_PAD_LEFT), $total, $_POST['metodo_pago']??'efectivo', $venta_id]);
            }

            // Emisión electrónica SUNAT (solo factura/boleta)
            $msg_extra = '';
            if (in_array($tipo, ['factura', 'boleta'], true)) {
                require_once __DIR__ . '/../includes/config_sunat.php';
                require_once __DIR__ . '/../includes/sunat/SunatService.php';
                $sunat   = new SunatService($db);
                $resul   = $sunat->emitir($venta_id);
                $msg_extra = '&sunat=' . ($resul['ok'] ? 'ok' : 'err')
                           . '&sunat_msg=' . urlencode($resul['mensaje']);
            }

            header('Location: '.BASE_URL.'/index.php?p=facturacion&action=ver&id='.$venta_id.'&msg=nuevo'.$msg_extra);
            exit;
        }
    }

    if ($pa === 'anular') {
        $db->prepare("UPDATE ventas SET estado='anulado' WHERE id=?")->execute([(int)$_POST['id']]);
        $msg='anulado'; $action='list';
    }
    if ($pa === 'cobrar') {
        $db->prepare("UPDATE ventas SET estado='pagado',metodo_pago=? WHERE id=?")->execute([$_POST['metodo_pago'],(int)$_POST['id']]);
        $msg='cobrado'; $action='list';
    }
}

// ─── CARGAR VENTA PARA VER ──────────────────────────────────────
$venta_detalle = null;
$items_detalle = [];
if ($action === 'ver' && !empty($_GET['id'])) {
    $vid = (int)$_GET['id'];
    $st = $db->prepare("
        SELECT v.*, c.nombre as cliente, c.telefono, c.dni, c.ruc as cli_ruc, c.direccion as cli_dir,
               m.nombre as mascota, u.nombre as vendedor
        FROM ventas v
        JOIN clientes c ON c.id=v.cliente_id
        LEFT JOIN mascotas m ON m.id=v.mascota_id
        LEFT JOIN usuarios u ON u.id=v.usuario_id
        WHERE v.id=?");
    $st->execute([$vid]); $venta_detalle = $st->fetch();

    $st2 = $db->prepare("SELECT * FROM venta_items WHERE venta_id=? ORDER BY id ASC");
    $st2->execute([$vid]); $items_detalle = $st2->fetchAll();
}

// ─── DATOS PARA SELECTS ─────────────────────────────────────────
$clientes_sel  = $db->query("SELECT id,nombre,telefono FROM clientes WHERE activo=1 ORDER BY nombre")->fetchAll();
$mascotas_sel  = $db->query("SELECT m.id,CONCAT(m.nombre,' (',c.nombre,')') as label,m.cliente_id FROM mascotas m JOIN clientes c ON c.id=m.cliente_id WHERE m.estado='activo' ORDER BY m.nombre")->fetchAll();
$servicios_sel = $db->query("SELECT id,nombre,precio FROM servicios WHERE activo=1 ORDER BY tipo,nombre")->fetchAll();
$productos_sel = $db->query("SELECT id,nombre,precio_venta as precio FROM productos WHERE activo=1 AND stock>0 ORDER BY nombre")->fetchAll();

// ─── LISTA DE VENTAS ────────────────────────────────────────────
$search  = trim($_GET['q']  ?? '');
$estado_f = $_GET['estado'] ?? '';
$fecha_d  = $_GET['fecha_d'] ?? date('Y-m-01');
$fecha_h  = $_GET['fecha_h'] ?? date('Y-m-d');
$where  = "v.fecha BETWEEN ? AND ?";
$params = [$fecha_d.' 00:00:00', $fecha_h.' 23:59:59'];
if ($estado_f) { $where .= " AND v.estado=?"; $params[]=$estado_f; }
if ($search)   { $where .= " AND (c.nombre LIKE ? OR v.serie LIKE ? OR CAST(v.numero AS CHAR) LIKE ?)"; $like="%$search%"; $params=array_merge($params,[$like,$like,$like]); }
$ventas = $db->prepare("SELECT v.*,c.nombre as cliente,m.nombre as mascota FROM ventas v JOIN clientes c ON c.id=v.cliente_id LEFT JOIN mascotas m ON m.id=v.mascota_id WHERE $where ORDER BY v.fecha DESC LIMIT 100");
$ventas->execute($params); $ventas=$ventas->fetchAll();
$total_periodo = array_sum(array_column(array_filter($ventas,fn($v)=>$v['estado']==='pagado'),'total'));
?>

<?php if(($msg??'')==='error_items'): ?>
<div class="alert alert-warn mb-2">⚠️ Debes agregar al menos un servicio o producto con precio mayor a 0.</div>
<?php endif; ?>
<?php if(($msg??'')==='success' || ($_GET['msg']??'')==='nuevo'): ?>
<div class="alert alert-success mb-2">✅ Venta registrada exitosamente.</div>
<?php endif; ?>
<?php if(($_GET['sunat'] ?? '')==='ok'): ?>
<div class="alert alert-success mb-2">📄 SUNAT: <?= clean($_GET['sunat_msg'] ?? 'Comprobante aceptado.') ?></div>
<?php elseif(($_GET['sunat'] ?? '')==='err'): ?>
<div class="alert alert-warn mb-2">⚠️ SUNAT: <?= clean($_GET['sunat_msg'] ?? 'Error al emitir.') ?></div>
<?php endif; ?>
<?php if(($msg??'')==='anulado'): ?><div class="alert alert-warn mb-2">⚠️ Venta anulada.</div><?php endif; ?>
<?php if(($msg??'')==='cobrado'): ?><div class="alert alert-success mb-2">✅ Pago registrado.</div><?php endif; ?>

<?php if($action==='nueva'): ?>
<!-- ════════════════════════════ NUEVA VENTA ════════════════════════════ -->
<div class="card" style="max-width:820px">
  <div class="sec-header"><div class="sec-title">Nueva Venta</div><a href="?p=facturacion" class="btn btn-sm">← Volver</a></div>
  <form method="POST" id="venta-form">
    <input type="hidden" name="action" value="save">
    <div class="form-row">
      <div class="form-group"><label class="form-label">Cliente *</label>
        <select class="form-input" name="cliente_id" id="sel-cli" required onchange="filterMascotas(this.value)">
          <option value="">— Seleccionar —</option>
          <?php foreach($clientes_sel as $c): ?><option value="<?= $c['id'] ?>"><?= clean($c['nombre']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label class="form-label">Mascota</label>
        <select class="form-input" name="mascota_id" id="sel-mas">
          <option value="">— Opcional —</option>
          <?php foreach($mascotas_sel as $m): ?><option value="<?= $m['id'] ?>" data-cli="<?= $m['cliente_id'] ?>"><?= clean($m['label']) ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Tipo de comprobante</label>
        <select class="form-input" name="tipo_comprobante">
          <option value="boleta">Boleta electrónica</option>
          <option value="factura">Factura electrónica</option>
          <option value="ticket">Nota de Venta</option>
        </select>
      </div>
      <div class="form-group"><label class="form-label">Método de pago</label>
        <select class="form-input" name="metodo_pago">
          <option value="efectivo">Efectivo</option>
          <option value="yape">Yape</option>
          <option value="plin">Plin</option>
          <option value="tarjeta_debito">Tarjeta débito</option>
          <option value="tarjeta_credito">Tarjeta crédito</option>
          <option value="transferencia">Transferencia</option>
        </select>
      </div>
    </div>

    <!-- ── ITEMS ── -->
    <div style="border:1px solid var(--border);border-radius:10px;padding:16px;margin-bottom:14px">
      <div class="flex items-center justify-between mb-2">
        <div class="sec-title">Servicios y Productos</div>
        <div class="flex gap-1">
          <button type="button" class="btn btn-sm btn-primary" onclick="addItem('servicio')">+ Servicio</button>
          <button type="button" class="btn btn-sm" onclick="addItem('producto')">+ Producto</button>
          <button type="button" class="btn btn-sm" onclick="addItemManual()">+ Manual</button>
        </div>
      </div>
      <!-- Tabla de ítems -->
      <table style="width:100%;border-collapse:collapse;font-size:13px" id="items-table">
        <thead id="items-thead" style="display:none">
          <tr style="background:var(--bg3)">
            <th style="padding:6px 8px;text-align:left;font-size:11px;color:var(--text3);text-transform:uppercase;letter-spacing:.4px;border-bottom:1px solid var(--border)">Descripción</th>
            <th style="padding:6px 8px;text-align:center;width:70px;font-size:11px;color:var(--text3);text-transform:uppercase;letter-spacing:.4px;border-bottom:1px solid var(--border)">Cant.</th>
            <th style="padding:6px 8px;text-align:right;width:110px;font-size:11px;color:var(--text3);text-transform:uppercase;letter-spacing:.4px;border-bottom:1px solid var(--border)">Precio (S/.)</th>
            <th style="padding:6px 8px;text-align:right;width:90px;font-size:11px;color:var(--text3);text-transform:uppercase;letter-spacing:.4px;border-bottom:1px solid var(--border)">Subtotal</th>
            <th style="width:32px;border-bottom:1px solid var(--border)"></th>
          </tr>
        </thead>
        <tbody id="items-list"></tbody>
      </table>
      <div id="items-empty" class="text-center text-muted" style="padding:20px;font-size:13px">
        Haz clic en <strong>+ Servicio</strong> o <strong>+ Producto</strong> para agregar ítems
      </div>
    </div>

    <!-- TOTALES -->
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Descuento (S/.)</label>
        <input class="form-input" type="number" step="0.01" name="descuento" id="inp-desc" value="0" min="0" oninput="calcTotal()">
        <div class="form-group mt-1"><label class="form-label">Notas</label><input class="form-input" name="notas" placeholder="Observaciones del comprobante"></div>
      </div>
      <div style="background:var(--teal-l);border:1.5px solid var(--teal);border-radius:12px;padding:16px">
        <div class="flex justify-between text-sm mb-2"><span class="text-muted">Subtotal:</span><span id="tot-sub">S/. 0.00</span></div>
        <div class="flex justify-between text-sm mb-2"><span class="text-muted">Descuento:</span><span id="tot-desc" style="color:var(--red)">-S/. 0.00</span></div>
        <div class="flex justify-between text-sm mb-2"><span class="text-muted">IGV (18%):</span><span id="tot-igv">S/. 0.00</span></div>
        <div style="border-top:1.5px solid var(--teal);padding-top:10px;margin-top:4px" class="flex justify-between"><span style="font-size:16px;font-weight:800;color:var(--teal-d)">Total:</span><span id="tot-total" style="font-size:20px;font-weight:800;color:var(--teal-d)">S/. 0.00</span></div>
      </div>
    </div>
    <div class="flex gap-1 mt-2">
      <button type="submit" class="btn btn-primary" style="flex:1;justify-content:center">🧾 Emitir comprobante</button>
      <a href="?p=facturacion" class="btn">Cancelar</a>
    </div>
  </form>
</div>

<?php elseif($action==='ver' && $venta_detalle): ?>
<!-- ════════════════════════════ VER VENTA ════════════════════════════ -->
<div class="card" style="max-width:700px">
  <div class="sec-header">
    <div>
      <div class="sec-title"><?= strtoupper($venta_detalle['tipo_comprobante']) ?> <?= $venta_detalle['serie'] ?>-<?= str_pad($venta_detalle['numero'],5,'0',STR_PAD_LEFT) ?></div>
      <div class="sec-sub"><?= date('d/m/Y H:i',strtotime($venta_detalle['fecha'])) ?></div>
    </div>
    <div class="flex gap-1 flex-wrap">
      <span class="badge <?= $venta_detalle['estado']==='pagado'?'b-teal':($venta_detalle['estado']==='anulado'?'b-red':'b-amber') ?>">
        <span class="dot"></span><?= ucfirst($venta_detalle['estado']) ?>
      </span>
      <a href="?p=facturacion" class="btn btn-sm">← Volver</a>
    </div>
  </div>

  <!-- DATOS CLIENTE -->
  <div class="grid g2 mb-2" style="background:var(--bg3);border-radius:10px;padding:14px;gap:12px">
    <div>
      <div class="text-xs text-muted mb-1">CLIENTE</div>
      <div class="font-bold"><?= clean($venta_detalle['cliente']) ?></div>
      <div class="text-xs text-muted"><?= clean($venta_detalle['telefono']) ?></div>
      <?php if($venta_detalle['dni']): ?><div class="text-xs text-muted">DNI: <?= clean($venta_detalle['dni']) ?></div><?php endif; ?>
    </div>
    <?php if($venta_detalle['mascota']): ?>
    <div>
      <div class="text-xs text-muted mb-1">MASCOTA</div>
      <div class="font-bold">🐾 <?= clean($venta_detalle['mascota']) ?></div>
    </div>
    <?php endif; ?>
  </div>

  <!-- TABLA ÍTEMS -->
  <div class="table-wrap mb-2">
    <table class="vtable">
      <thead>
        <tr>
          <th>Descripción</th>
          <th style="text-align:center;width:60px">Cant.</th>
          <th style="text-align:right;width:100px">Precio</th>
          <th style="text-align:right;width:100px">Subtotal</th>
        </tr>
      </thead>
      <tbody>
        <?php if(empty($items_detalle)): ?>
        <tr><td colspan="4" class="text-center text-muted" style="padding:20px;font-style:italic">Sin ítems registrados.</td></tr>
        <?php else: ?>
        <?php foreach($items_detalle as $it): ?>
        <tr>
          <td class="td-main"><?= clean($it['descripcion']) ?></td>
          <td style="text-align:center"><?= $it['cantidad'] ?></td>
          <td style="text-align:right">S/. <?= number_format($it['precio_unitario'],2) ?></td>
          <td style="text-align:right" class="font-med">S/. <?= number_format($it['subtotal'],2) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- TOTALES -->
  <div style="text-align:right;border-top:1px solid var(--border);padding-top:12px;margin-bottom:14px">
    <div class="text-sm text-muted mb-1">Subtotal: S/. <?= number_format($venta_detalle['subtotal'],2) ?></div>
    <?php if(($venta_detalle['descuento']??0)>0): ?>
    <div class="text-sm text-muted mb-1" style="color:var(--red)">Descuento: -S/. <?= number_format($venta_detalle['descuento'],2) ?></div>
    <?php endif; ?>
    <div class="text-sm text-muted mb-1">IGV (18%): S/. <?= number_format($venta_detalle['igv'],2) ?></div>
    <div style="font-size:22px;font-weight:800;color:var(--teal-d)">Total: S/. <?= number_format($venta_detalle['total'],2) ?></div>
    <div class="text-xs text-muted mt-1">Método: <?= ucfirst(str_replace('_',' ',$venta_detalle['metodo_pago'])) ?></div>
  </div>

  <!-- OPCIONES IMPRESIÓN -->
  <div style="background:var(--bg3);border:1px solid var(--border);border-radius:12px;padding:16px;margin-bottom:14px">
    <div class="flex items-center gap-2 mb-3"><span style="font-size:18px">🖨️</span><div class="font-bold text-sm">Opciones de impresión</div></div>
    <div class="flex gap-2" style="flex-wrap:wrap">
      <a href="<?= BASE_URL ?>/print/comprobante.php?id=<?= $venta_detalle['id'] ?>&fmt=a4" target="_blank" style="flex:1;min-width:130px;text-decoration:none">
        <div class="card card-sm text-center" style="cursor:pointer;transition:all .15s" onmouseover="this.style.borderColor='var(--teal)';this.style.background='var(--teal-l)'" onmouseout="this.style.borderColor='var(--border)';this.style.background='var(--bg2)'">
          <div style="font-size:26px;margin-bottom:4px">📄</div>
          <div class="font-bold text-sm">Formato A4</div>
          <div class="text-xs text-muted">Documento estándar</div>
        </div>
      </a>
      <a href="<?= BASE_URL ?>/print/comprobante.php?id=<?= $venta_detalle['id'] ?>&fmt=voucher" target="_blank" style="flex:1;min-width:130px;text-decoration:none">
        <div class="card card-sm text-center" style="cursor:pointer;transition:all .15s" onmouseover="this.style.borderColor='var(--teal)';this.style.background='var(--teal-l)'" onmouseout="this.style.borderColor='var(--border)';this.style.background='var(--bg2)'">
          <div style="font-size:26px;margin-bottom:4px">🖨️</div>
          <div class="font-bold text-sm">Voucher 80mm</div>
          <div class="text-xs text-muted">Ticket de impresora</div>
        </div>
      </a>
      <a href="<?= BASE_URL ?>/print/ver.php?serie=<?= urlencode($venta_detalle['serie']) ?>&num=<?= $venta_detalle['numero'] ?>" target="_blank" style="flex:1;min-width:130px;text-decoration:none">
        <div class="card card-sm text-center" style="cursor:pointer;transition:all .15s" onmouseover="this.style.borderColor='var(--blue)';this.style.background='var(--blue-l)'" onmouseout="this.style.borderColor='var(--border)';this.style.background='var(--bg2)'">
          <div style="font-size:26px;margin-bottom:4px">🌐</div>
          <div class="font-bold text-sm" style="color:var(--blue)">Ver en web</div>
          <div class="text-xs text-muted">Link para el cliente</div>
        </div>
      </a>
    </div>
    <div class="text-xs text-muted mt-2 text-center">Personaliza la cabecera en <a href="?p=plantillas" style="color:var(--blue)">Plantillas de Impresión</a></div>
  </div>

  <!-- COBRAR si pendiente -->
  <?php if($venta_detalle['estado']==='pendiente'): ?>
  <form method="POST" class="flex gap-1 mb-2">
    <input type="hidden" name="action" value="cobrar">
    <input type="hidden" name="id" value="<?= $venta_detalle['id'] ?>">
    <select class="form-input" name="metodo_pago" style="width:180px">
      <option value="efectivo">Efectivo</option><option value="yape">Yape</option>
      <option value="plin">Plin</option><option value="tarjeta_debito">Tarjeta débito</option>
    </select>
    <button type="submit" class="btn btn-primary">✅ Registrar pago</button>
  </form>
  <?php endif; ?>

  <!-- ANULAR -->
  <?php if($venta_detalle['estado']==='pagado'): ?>
  <form method="POST" class="mb-2" style="display:inline">
    <input type="hidden" name="action" value="anular">
    <input type="hidden" name="id" value="<?= $venta_detalle['id'] ?>">
    <button type="submit" class="btn btn-xs" style="color:var(--red)" onclick="return confirm('¿Anular este comprobante? Esta acción no se puede deshacer.')">✕ Anular comprobante</button>
  </form>
  <?php endif; ?>

  <!-- WHATSAPP CON URL -->
  <?php
  $tel = preg_replace('/[^0-9]/','',ltrim($venta_detalle['telefono'],'+'));
  if(strlen($tel)<11) $tel='51'.$tel;
  $num_cmp = $venta_detalle['serie'].'-'.str_pad($venta_detalle['numero'],6,'0',STR_PAD_LEFT);
  $url_cmp = BASE_URL.'/print/ver.php?serie='.urlencode($venta_detalle['serie']).'&num='.$venta_detalle['numero'];
  $tipo_wa = ['boleta'=>'BOLETA','factura'=>'FACTURA','ticket'=>'NOTA DE VENTA'];
  $wa_msg  = "🧾 *".($tipo_wa[$venta_detalle['tipo_comprobante']]??'COMPROBANTE')." VetPro*\n";
  $wa_msg .= "N° $num_cmp\n\n";
  $wa_msg .= "👤 Cliente: {$venta_detalle['cliente']}\n";
  $wa_msg .= "📅 Fecha: ".date('d/m/Y',strtotime($venta_detalle['fecha']))."\n\n";
  // Detalle de ítems en el mensaje
  foreach($items_detalle as $it) {
    $wa_msg .= "• {$it['descripcion']} x{$it['cantidad']} → S/. ".number_format($it['subtotal'],2)."\n";
  }
  $wa_msg .= "\n💰 *Total: S/. ".number_format($venta_detalle['total'],2)."*\n";
  $wa_msg .= "💳 Pago: ".ucfirst(str_replace('_',' ',$venta_detalle['metodo_pago']))." ✅\n\n";
  $wa_msg .= "📄 Ver comprobante:\n$url_cmp\n\n";
  $wa_msg .= "¡Gracias por confiar en VetPro 🐾!";
  ?>
  <a href="https://wa.me/<?= $tel ?>?text=<?= rawurlencode($wa_msg) ?>" target="_blank"
     class="btn btn-wa" style="width:100%;justify-content:center;font-size:14px;padding:12px">
    💬 Enviar comprobante por WhatsApp
  </a>
</div>

<?php else: ?>
<!-- ════════════════════════════ LISTA ════════════════════════════ -->
<div class="grid g4 mb-2">
  <div class="stat-card"><div class="stat-icon si-teal">💰</div><div class="stat-value">S/. <?= number_format($total_periodo,0) ?></div><div class="stat-label">Ingresos del período</div></div>
  <div class="stat-card"><div class="stat-icon si-blue">🧾</div><div class="stat-value"><?= count($ventas) ?></div><div class="stat-label">Comprobantes</div></div>
  <div class="stat-card"><div class="stat-icon si-teal">✅</div><div class="stat-value"><?= count(array_filter($ventas,fn($v)=>$v['estado']==='pagado')) ?></div><div class="stat-label">Pagados</div></div>
  <div class="stat-card"><div class="stat-icon si-amber">⏳</div><div class="stat-value"><?= count(array_filter($ventas,fn($v)=>$v['estado']==='pendiente')) ?></div><div class="stat-label">Pendientes</div></div>
</div>
<div class="card mb-2" style="padding:14px 18px">
  <form method="GET" class="flex items-center gap-2" style="flex-wrap:wrap">
    <input type="hidden" name="p" value="facturacion">
    <input class="form-input" name="q" value="<?= clean($search) ?>" placeholder="Buscar cliente, serie..." style="width:220px">
    <input class="form-input" type="date" name="fecha_d" value="<?= $fecha_d ?>" style="width:150px">
    <input class="form-input" type="date" name="fecha_h" value="<?= $fecha_h ?>" style="width:150px">
    <select class="form-input" name="estado" style="width:140px">
      <option value="">Todos</option>
      <option value="pagado"   <?= $estado_f==='pagado'   ?'selected':'' ?>>Pagado</option>
      <option value="pendiente"<?= $estado_f==='pendiente'?'selected':'' ?>>Pendiente</option>
      <option value="anulado"  <?= $estado_f==='anulado'  ?'selected':'' ?>>Anulado</option>
    </select>
    <button type="submit" class="btn">Filtrar</button>
    <a href="?p=facturacion&action=nueva" class="btn btn-primary" style="margin-left:auto">+ Nueva Venta</a>
    <a href="?p=plantillas" class="btn" title="Plantillas de impresión">🖨️ Plantillas</a>
  </form>
</div>
<div class="card" style="padding:0">
  <div class="table-wrap">
    <table class="vtable">
      <thead>
        <tr><th>Comprobante</th><th>Fecha</th><th>Cliente</th><th>Mascota</th><th>Total</th><th>Método</th><th>Estado</th><th>Acciones</th></tr>
      </thead>
      <tbody>
        <?php foreach($ventas as $v): ?>
        <tr>
          <td class="td-main" style="color:var(--blue)"><?= $v['serie'] ?>-<?= str_pad($v['numero'],5,'0',STR_PAD_LEFT) ?></td>
          <td class="text-muted"><?= date('d/m/Y H:i',strtotime($v['fecha'])) ?></td>
          <td><?= clean($v['cliente']) ?></td>
          <td class="text-muted"><?= clean($v['mascota']??'—') ?></td>
          <td class="font-bold">S/. <?= number_format($v['total'],2) ?></td>
          <td><span class="badge b-gray"><?= ucfirst(str_replace('_',' ',$v['metodo_pago'])) ?></span></td>
          <td><span class="badge <?= $v['estado']==='pagado'?'b-teal':($v['estado']==='anulado'?'b-red':'b-amber') ?>"><span class="dot"></span> <?= ucfirst($v['estado']) ?></span></td>
          <td>
            <div class="flex gap-1">
              <a href="?p=facturacion&action=ver&id=<?= $v['id'] ?>" class="btn btn-xs">Ver</a>
              <?php if($v['estado']==='pagado'): ?>
              <div style="position:relative" onmouseenter="this.querySelector('.pm').style.display='block'" onmouseleave="this.querySelector('.pm').style.display='none'">
                <button class="btn btn-xs">🖨️ ▾</button>
                <div class="pm" style="display:none;position:absolute;top:100%;right:0;background:#fff;border:1px solid var(--border);border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,.12);z-index:100;min-width:160px;padding:4px">
                  <a href="<?= BASE_URL ?>/print/comprobante.php?id=<?= $v['id'] ?>&fmt=a4" target="_blank" style="display:flex;align-items:center;gap:8px;padding:8px 12px;text-decoration:none;color:var(--text);font-size:12px;border-radius:6px" onmouseover="this.style.background='var(--bg3)'" onmouseout="this.style.background=''">📄 <div><div>Formato A4</div><div style="font-size:10px;color:var(--text3)">Documento estándar</div></div></a>
                  <a href="<?= BASE_URL ?>/print/comprobante.php?id=<?= $v['id'] ?>&fmt=voucher" target="_blank" style="display:flex;align-items:center;gap:8px;padding:8px 12px;text-decoration:none;color:var(--text);font-size:12px;border-radius:6px" onmouseover="this.style.background='var(--bg3)'" onmouseout="this.style.background=''">🖨️ <div><div>Voucher 80mm</div><div style="font-size:10px;color:var(--text3)">Ticket impresora</div></div></a>
                  <a href="<?= BASE_URL ?>/print/ver.php?serie=<?= urlencode($v['serie']) ?>&num=<?= $v['numero'] ?>" target="_blank" style="display:flex;align-items:center;gap:8px;padding:8px 12px;text-decoration:none;color:var(--text);font-size:12px;border-radius:6px" onmouseover="this.style.background='var(--bg3)'" onmouseout="this.style.background=''">🌐 <div><div>Link web</div><div style="font-size:10px;color:var(--text3)">Compartir al cliente</div></div></a>
                </div>
              </div>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($ventas)): ?><tr><td colspan="8" class="text-center text-muted" style="padding:32px">No se encontraron ventas.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<script>
var SERVICIOS = <?= json_encode(array_values(array_map(fn($s)=>['id'=>(int)$s['id'],'nombre'=>$s['nombre'],'precio'=>(float)$s['precio']], $servicios_sel))) ?>;
var PRODUCTOS = <?= json_encode(array_values(array_map(fn($p)=>['id'=>(int)$p['id'],'nombre'=>$p['nombre'],'precio'=>(float)$p['precio']], $productos_sel))) ?>;
var itemIdx = 0;

function showHeader() {
  var has = document.querySelector('#items-list tr');
  document.getElementById('items-thead').style.display = has ? '' : 'none';
  document.getElementById('items-empty').style.display = has ? 'none' : 'block';
}

function addItem(tipo) {
  var list  = tipo === 'servicio' ? SERVICIOS : PRODUCTOS;
  var opts  = list.map(x => `<option value="${x.id}" data-precio="${x.precio}" data-nombre="${x.nombre.replace(/"/g,'&quot;')}">${x.nombre} — S/. ${x.precio.toFixed(2)}</option>`).join('');
  var idx   = itemIdx++;
  var row   = document.createElement('tr');
  row.className = 'item-row';
  row.innerHTML = `
    <td style="padding:6px 4px">
      <input type="hidden" name="item_tipo[]" value="${tipo}">
      <input type="hidden" name="item_ref[]"  id="ref_${idx}" value="">
      <select class="form-input" style="font-size:12px" onchange="fillItem(this,${idx})">
        <option value="">— Seleccionar ${tipo} —</option>${opts}
      </select>
      <input class="form-input" name="item_desc[]" id="desc_${idx}" value="" placeholder="O escribe descripción libre" style="margin-top:4px;font-size:12px">
    </td>
    <td style="padding:6px 4px;text-align:center">
      <input class="form-input" type="number" name="item_qty[]" id="qty_${idx}" value="1" min="1" style="text-align:center;font-size:12px;width:60px" oninput="calcTotal()">
    </td>
    <td style="padding:6px 4px;text-align:right">
      <input class="form-input" type="number" step="0.01" name="item_precio[]" id="precio_${idx}" value="" placeholder="0.00" style="text-align:right;font-size:12px;width:100px" oninput="calcSubtotal(${idx});calcTotal()">
    </td>
    <td style="padding:6px 4px;text-align:right;font-weight:600;font-size:13px;color:var(--teal-d)" id="sub_${idx}">S/. 0.00</td>
    <td style="padding:6px 4px;text-align:center">
      <button type="button" onclick="removeItem(this)" class="btn btn-xs" style="color:var(--red);padding:3px 7px">✕</button>
    </td>
  `;
  document.getElementById('items-list').appendChild(row);
  showHeader();
  row.querySelector('select').focus();
}

function addItemManual() {
  var idx = itemIdx++;
  var row = document.createElement('tr');
  row.className = 'item-row';
  row.innerHTML = `
    <td style="padding:6px 4px">
      <input type="hidden" name="item_tipo[]" value="servicio">
      <input type="hidden" name="item_ref[]" value="0">
      <input class="form-input" name="item_desc[]" id="desc_${idx}" placeholder="Descripción del servicio/producto" style="font-size:12px" required>
    </td>
    <td style="padding:6px 4px;text-align:center">
      <input class="form-input" type="number" name="item_qty[]" id="qty_${idx}" value="1" min="1" style="text-align:center;font-size:12px;width:60px" oninput="calcTotal()">
    </td>
    <td style="padding:6px 4px;text-align:right">
      <input class="form-input" type="number" step="0.01" name="item_precio[]" id="precio_${idx}" value="" placeholder="0.00" style="text-align:right;font-size:12px;width:100px" oninput="calcSubtotal(${idx});calcTotal()">
    </td>
    <td style="padding:6px 4px;text-align:right;font-weight:600;font-size:13px;color:var(--teal-d)" id="sub_${idx}">S/. 0.00</td>
    <td style="padding:6px 4px;text-align:center">
      <button type="button" onclick="removeItem(this)" class="btn btn-xs" style="color:var(--red);padding:3px 7px">✕</button>
    </td>
  `;
  document.getElementById('items-list').appendChild(row);
  showHeader();
  document.getElementById('desc_'+idx).focus();
}

function fillItem(sel, idx) {
  var opt = sel.options[sel.selectedIndex];
  if (!opt.value) return;
  var precio  = parseFloat(opt.getAttribute('data-precio') || 0);
  var nombre  = opt.getAttribute('data-nombre') || opt.text.split(' — ')[0];
  var refEl   = document.getElementById('ref_'+idx);
  var descEl  = document.getElementById('desc_'+idx);
  var precioEl= document.getElementById('precio_'+idx);
  if (refEl)    refEl.value    = opt.value;
  if (descEl)   descEl.value   = nombre;
  if (precioEl){ precioEl.value = precio.toFixed(2); calcSubtotal(idx); }
  calcTotal();
}

function calcSubtotal(idx) {
  var qty   = parseFloat(document.getElementById('qty_'+idx)?.value    || 1);
  var price = parseFloat(document.getElementById('precio_'+idx)?.value || 0);
  var sub   = qty * price;
  var el    = document.getElementById('sub_'+idx);
  if (el) el.textContent = 'S/. ' + sub.toFixed(2);
}

function calcTotal() {
  var sub = 0;
  document.querySelectorAll('.item-row').forEach(row => {
    var qty   = parseFloat(row.querySelector('[name="item_qty[]"]')?.value  || 1);
    var price = parseFloat(row.querySelector('[name="item_precio[]"]')?.value || 0);
    sub += qty * price;
  });
  var desc  = parseFloat(document.getElementById('inp-desc')?.value || 0);
  var base  = sub - desc;
  var igv   = base * 0.18;
  var total = base + igv;
  document.getElementById('tot-sub').textContent   = 'S/. ' + sub.toFixed(2);
  document.getElementById('tot-desc').textContent  = '-S/. ' + desc.toFixed(2);
  document.getElementById('tot-igv').textContent   = 'S/. ' + igv.toFixed(2);
  document.getElementById('tot-total').textContent = 'S/. ' + total.toFixed(2);
}

function removeItem(btn) {
  btn.closest('tr').remove();
  calcTotal();
  showHeader();
}

function filterMascotas(cliId) {
  var sel = document.getElementById('sel-mas');
  if (!sel) return;
  Array.from(sel.options).forEach(opt => {
    if (!opt.value) return;
    opt.hidden = opt.getAttribute('data-cli') != cliId;
  });
  sel.value = '';
}

// Validar antes de enviar
document.getElementById('venta-form')?.addEventListener('submit', function(e) {
  var rows = document.querySelectorAll('.item-row');
  if (rows.length === 0) {
    e.preventDefault();
    alert('Debes agregar al menos un servicio o producto.');
    return;
  }
  var valid = false;
  rows.forEach(row => {
    var p = parseFloat(row.querySelector('[name="item_precio[]"]')?.value || 0);
    if (p > 0) valid = true;
  });
  if (!valid) {
    e.preventDefault();
    alert('Todos los ítems deben tener un precio mayor a 0.');
  }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
