<?php
$page      = 'notas_credito';
$pageTitle = 'Notas de Crédito / Débito';
$db        = getDB();
$user      = getUser();
$action    = $_GET['action'] ?? 'lista';
$id        = (int)($_GET['id'] ?? 0);

if (!canView('facturacion')) {
    $_SESSION['flash_error'] = 'No tenés permiso para acceder a este módulo.';
    header('Location: ' . BASE_URL . '/index.php?p=dashboard'); exit;
}

$sunat_cfg = __DIR__ . '/../includes/config_sunat.php';
$sunat_svc = __DIR__ . '/../includes/sunat/SunatService.php';
if (file_exists($sunat_cfg)) require_once $sunat_cfg;
if (file_exists($sunat_svc)) require_once $sunat_svc;

// ─── POST HANDLER ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pa = $_POST['action'] ?? '';

    if ($pa === 'crear') {
        $venta_id   = (int)($_POST['venta_id']   ?? 0);
        $tipo_nota  = $_POST['tipo_nota']  ?? 'credito';
        $cod_motivo = trim($_POST['cod_motivo'] ?? '');
        $des_motivo = trim($_POST['des_motivo'] ?? '');

        if (!in_array($tipo_nota, ['credito', 'debito'], true)) {
            $_SESSION['flash_error'] = 'Tipo de nota inválido.';
            header('Location: ' . BASE_URL . '/index.php?p=notas_credito&action=nueva'); exit;
        }
        if (!$venta_id || !$cod_motivo || !$des_motivo) {
            $_SESSION['flash_error'] = 'Completá todos los campos requeridos.';
            header('Location: ' . BASE_URL . '/index.php?p=notas_credito&action=nueva'); exit;
        }

        $st = $db->prepare("SELECT * FROM ventas WHERE id=? AND tipo_comprobante IN('boleta','factura') AND sunat_xml IS NOT NULL");
        $st->execute([$venta_id]);
        $venta = $st->fetch();
        if (!$venta) {
            $_SESSION['flash_error'] = 'Comprobante no encontrado o sin XML generado.';
            header('Location: ' . BASE_URL . '/index.php?p=notas_credito&action=nueva'); exit;
        }
        if ($venta['estado'] === 'anulado') {
            $_SESSION['flash_error'] = 'Este comprobante ya está anulado — tiene una nota de crédito aceptada.';
            header('Location: ' . BASE_URL . '/index.php?p=notas_credito&action=nueva'); exit;
        }

        $stNC = $db->prepare("SELECT id FROM notas_credito WHERE venta_id=? AND tipo_nota='credito' AND sunat_estado='aceptado' LIMIT 1");
        $stNC->execute([$venta_id]);
        if ($stNC->fetch()) {
            $_SESSION['flash_error'] = 'Este comprobante ya tiene una nota de crédito aceptada por SUNAT.';
            header('Location: ' . BASE_URL . '/index.php?p=notas_credito&action=nueva'); exit;
        }

        if ($tipo_nota === 'credito') {
            $serie = $venta['tipo_comprobante'] === 'factura' ? SUNAT_SERIE_NC_FACTURA : SUNAT_SERIE_NC_BOLETA;
        } else {
            $serie = $venta['tipo_comprobante'] === 'factura' ? SUNAT_SERIE_ND_FACTURA : SUNAT_SERIE_ND_BOLETA;
        }

        $numero = SunatService::siguienteNumeroNota($db, $serie);

        $ins = $db->prepare("
            INSERT INTO notas_credito(venta_id, tipo_nota, serie, numero, cod_motivo, des_motivo, total, aplica_igv)
            VALUES(?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([
            $venta_id, $tipo_nota, $serie, $numero, $cod_motivo, $des_motivo,
            (float)$venta['total'], (int)($venta['aplica_igv'] ?? 1),
        ]);
        $notaId = (int)$db->lastInsertId();

        $_SESSION['flash_ok'] = 'Nota creada. Podés emitir el XML ahora.';
        header('Location: ' . BASE_URL . '/index.php?p=notas_credito&action=ver&id=' . $notaId); exit;
    }

    if ($pa === 'emitir' || $pa === 'regenerar') {
        $nid = (int)$_POST['id'];
        $r   = (new SunatService($db))->generarXmlNota($nid);
        if ($r['ok']) {
            $_SESSION['flash_ok'] = 'XML generado. Listo para enviar a SUNAT.';
        } else {
            $_SESSION['flash_error'] = 'Error al generar XML: ' . $r['mensaje'];
        }
        header('Location: ' . BASE_URL . '/index.php?p=notas_credito&action=ver&id=' . $nid); exit;
    }

    if ($pa === 'enviar_sunat') {
        $nid = (int)$_POST['id'];
        $r   = (new SunatService($db))->enviarSunatNota($nid);
        if ($r['ok']) {
            $_SESSION['flash_ok'] = 'SUNAT aceptó la nota: ' . $r['mensaje'];
        } else {
            $_SESSION['flash_error'] = 'SUNAT rechazó el envío: ' . $r['mensaje'];
        }
        header('Location: ' . BASE_URL . '/index.php?p=notas_credito&action=ver&id=' . $nid); exit;
    }
}

// ─── DESCARGA XML ─────────────────────────────────────────────────
if ($action === 'xml' && $id) {
    $st = $db->prepare("SELECT * FROM notas_credito WHERE id=?");
    $st->execute([$id]);
    $nota = $st->fetch();
    if (!$nota || empty($nota['sunat_xml'])) {
        http_response_code(404); echo 'Sin XML.'; exit;
    }
    $fname = 'nota_' . $nota['serie'] . '-' . str_pad((string)$nota['numero'], 8, '0', STR_PAD_LEFT) . '.xml';
    header('Content-Type: application/xml; charset=utf-8');
    if (isset($_GET['dl'])) header('Content-Disposition: attachment; filename="' . $fname . '"');
    echo $nota['sunat_xml']; exit;
}

// ─── HTML OUTPUT ──────────────────────────────────────────────────
require_once __DIR__ . '/../includes/header.php';

if (isset($_SESSION['flash_ok'])) {
    echo '<div class="alert alert-success mb-2">✅ ' . htmlspecialchars($_SESSION['flash_ok']) . '</div>';
    unset($_SESSION['flash_ok']);
} elseif (isset($_SESSION['flash_error'])) {
    echo '<div class="alert alert-warn mb-2">⚠️ ' . htmlspecialchars($_SESSION['flash_error']) . '</div>';
    unset($_SESSION['flash_error']);
}

// ─── LISTA ────────────────────────────────────────────────────────
if ($action === 'lista') {
    $fecha_d = $_GET['fecha_d'] ?? date('Y-m-01');
    $fecha_h = $_GET['fecha_h'] ?? date('Y-m-d');
    $ftipo   = $_GET['tipo']    ?? '';
    $fsun    = $_GET['sun']     ?? '';

    $where  = "WHERE DATE(n.created_at) BETWEEN ? AND ?";
    $params = [$fecha_d, $fecha_h];
    if ($ftipo) { $where .= " AND n.tipo_nota=?"; $params[] = $ftipo; }
    if ($fsun)  { $where .= " AND n.sunat_estado=?"; $params[] = $fsun; }

    $st = $db->prepare("
        SELECT n.*,
               v.tipo_comprobante, v.serie AS v_serie, v.numero AS v_numero,
               c.nombre AS cliente
        FROM notas_credito n
        JOIN ventas v ON n.venta_id = v.id
        JOIN clientes c ON v.cliente_id = c.id
        $where
        ORDER BY n.created_at DESC
        LIMIT 200
    ");
    $st->execute($params);
    $lista = $st->fetchAll();
?>
<div class="card mb-2" style="padding:14px 18px">
  <form method="GET" class="flex items-center gap-2" style="flex-wrap:wrap">
    <input type="hidden" name="p" value="notas_credito">
    <input class="form-input" type="date" name="fecha_d" value="<?= clean($fecha_d) ?>" style="width:150px">
    <input class="form-input" type="date" name="fecha_h" value="<?= clean($fecha_h) ?>" style="width:150px">
    <select class="form-input" name="tipo" style="width:160px">
      <option value="">Todos los tipos</option>
      <option value="credito" <?= $ftipo==='credito'?'selected':'' ?>>Crédito</option>
      <option value="debito"  <?= $ftipo==='debito'?'selected':'' ?>>Débito</option>
    </select>
    <select class="form-input" name="sun" style="width:170px">
      <option value="">Todos los estados</option>
      <option value="pendiente" <?= $fsun==='pendiente'?'selected':'' ?>>Pendiente</option>
      <option value="aceptado"  <?= $fsun==='aceptado'?'selected':'' ?>>Aceptado</option>
      <option value="rechazado" <?= $fsun==='rechazado'?'selected':'' ?>>Rechazado</option>
    </select>
    <button type="submit" class="btn">Filtrar</button>
    <a href="?p=notas_credito&action=nueva" class="btn btn-primary" style="margin-left:auto">+ Nueva Nota</a>
  </form>
</div>

<div class="card" style="padding:0">
  <div class="table-wrap">
    <table class="vtable">
      <thead>
        <tr>
          <th>Nota</th>
          <th>Comprobante afectado</th>
          <th>Cliente</th>
          <th>Motivo</th>
          <th style="text-align:right">Total</th>
          <th>Estado SUNAT</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($lista as $n):
          $se  = $n['sunat_estado'];
          $sCl = $se==='aceptado' ? 'b-teal' : ($se==='rechazado' ? 'b-red' : ($se==='pendiente' ? 'b-amber' : 'b-gray'));
          $tn  = $n['tipo_nota'] === 'credito' ? 'N. CRÉDITO' : 'N. DÉBITO';
          $tc  = $n['tipo_nota'] === 'credito' ? 'b-teal' : 'b-amber';
        ?>
        <tr>
          <td class="td-main">
            <span class="badge <?= $tc ?>"><?= $tn ?></span>
            <div style="font-size:12px;margin-top:2px;font-family:monospace"><?= clean($n['serie']) ?>-<?= str_pad((string)$n['numero'],8,'0',STR_PAD_LEFT) ?></div>
          </td>
          <td>
            <span class="badge b-gray"><?= strtoupper($n['tipo_comprobante']) ?></span>
            <div style="font-size:12px;margin-top:2px;font-family:monospace"><?= clean($n['v_serie']) ?>-<?= str_pad((string)$n['v_numero'],8,'0',STR_PAD_LEFT) ?></div>
          </td>
          <td><?= clean($n['cliente']) ?></td>
          <td>
            <span class="badge b-gray"><?= clean($n['cod_motivo']) ?></span>
            <div class="text-xs text-muted" style="margin-top:2px"><?= clean(mb_substr($n['des_motivo'],0,50)) ?><?= mb_strlen($n['des_motivo'])>50?'…':'' ?></div>
          </td>
          <td style="text-align:right" class="font-bold">S/. <?= number_format($n['total'],2) ?></td>
          <td><span class="badge <?= $sCl ?>"><span class="dot"></span><?= $se ? ucfirst($se) : 'Sin XML' ?></span></td>
          <td>
            <div class="flex gap-1">
              <a href="?p=notas_credito&action=ver&id=<?= $n['id'] ?>" class="btn btn-xs">Ver</a>
              <?php if (!empty($n['sunat_xml'])): ?>
                <a href="?p=notas_credito&action=xml&id=<?= $n['id'] ?>" target="_blank" class="btn btn-xs" title="Ver XML">📄</a>
              <?php endif; ?>
              <?php if (!empty($n['sunat_xml']) && $se !== 'aceptado'): ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('¿Enviar a SUNAT?')">
                  <input type="hidden" name="action" value="enviar_sunat">
                  <input type="hidden" name="id" value="<?= $n['id'] ?>">
                  <button type="submit" class="btn btn-xs btn-primary" title="Enviar a SUNAT">📤</button>
                </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($lista)): ?>
        <tr><td colspan="7" class="text-center text-muted" style="padding:32px">No hay notas en este período.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php
// ─── VER DETALLE ──────────────────────────────────────────────────
} elseif ($action === 'ver' && $id) {
    $st = $db->prepare("
        SELECT n.*,
               v.tipo_comprobante, v.serie AS v_serie, v.numero AS v_numero, v.total AS v_total,
               c.nombre AS cliente, c.dni, c.ruc
        FROM notas_credito n
        JOIN ventas v ON n.venta_id = v.id
        JOIN clientes c ON v.cliente_id = c.id
        WHERE n.id=?
    ");
    $st->execute([$id]);
    $nota = $st->fetch();

    if (!$nota) {
        echo '<div class="alert alert-warn mb-2">⚠️ Nota no encontrada.</div>';
        echo '<a href="?p=notas_credito" class="btn">← Volver</a>';
    } else {
        $se  = $nota['sunat_estado'];
        $sCl = $se==='aceptado' ? 'b-teal' : ($se==='rechazado' ? 'b-red' : ($se==='pendiente' ? 'b-amber' : 'b-gray'));
        $sl  = $se ? ucfirst($se) : 'Sin emitir';
        $tn  = $nota['tipo_nota'] === 'credito' ? 'NOTA DE CRÉDITO' : 'NOTA DE DÉBITO';
        $motivos = [
            '01' => 'Anulación de la operación',
            '02' => 'Anulación por error en el RUC',
            '06' => 'Devolución total',
            '07' => 'Devolución por ítem(s) de la operación',
            '13' => 'Ajuste en operaciones de exportación',
        ];
?>
<div class="card" style="max-width:760px">
  <div class="sec-header mb-3">
    <div>
      <div class="sec-title"><?= $tn ?> <?= clean($nota['serie']) ?>-<?= str_pad((string)$nota['numero'],8,'0',STR_PAD_LEFT) ?></div>
      <div class="sec-sub"><?= date('d/m/Y H:i', strtotime($nota['created_at'])) ?></div>
    </div>
    <div class="flex gap-1">
      <span class="badge <?= $nota['tipo_nota']==='credito'?'b-teal':'b-amber' ?>"><?= $nota['tipo_nota']==='credito'?'CRÉDITO':'DÉBITO' ?></span>
      <a href="?p=notas_credito" class="btn btn-sm">← Volver</a>
    </div>
  </div>

  <div class="grid g2 mb-3" style="background:var(--bg3);border-radius:10px;padding:14px;gap:12px">
    <div>
      <div class="text-xs text-muted mb-1">CLIENTE</div>
      <div class="font-bold"><?= clean($nota['cliente']) ?></div>
      <?php if ($nota['ruc']): ?><div class="text-xs text-muted">RUC: <?= clean($nota['ruc']) ?></div><?php endif; ?>
      <?php if ($nota['dni']): ?><div class="text-xs text-muted">DNI: <?= clean($nota['dni']) ?></div><?php endif; ?>
    </div>
    <div>
      <div class="text-xs text-muted mb-1">COMPROBANTE AFECTADO</div>
      <div class="font-bold"><?= strtoupper($nota['tipo_comprobante']) ?> <?= clean($nota['v_serie']) ?>-<?= str_pad((string)$nota['v_numero'],8,'0',STR_PAD_LEFT) ?></div>
      <a href="?p=facturacion&action=ver&id=<?= $nota['venta_id'] ?>" class="text-xs" style="color:var(--blue)">Ver comprobante original →</a>
    </div>
  </div>

  <div class="mb-3" style="background:var(--bg3);border-radius:10px;padding:14px">
    <div class="text-xs text-muted mb-1">MOTIVO</div>
    <div class="font-bold">
      <span class="badge b-gray" style="margin-right:6px"><?= clean($nota['cod_motivo']) ?></span>
      <?= clean($motivos[$nota['cod_motivo']] ?? $nota['cod_motivo']) ?>
    </div>
    <div class="text-xs text-muted mt-2"><?= clean($nota['des_motivo']) ?></div>
  </div>

  <div class="mb-3" style="background:var(--bg3);border-radius:10px;padding:14px">
    <div class="flex justify-between items-center">
      <span class="font-bold">TOTAL</span>
      <span style="font-size:22px;font-weight:800;color:var(--teal-d)">S/. <?= number_format($nota['total'],2) ?></span>
    </div>
    <div class="mt-1">
      <span class="badge <?= $nota['aplica_igv']?'b-teal':'b-gray' ?>"><?= $nota['aplica_igv']?'CON IGV (18%)':'SIN IGV' ?></span>
    </div>
  </div>

  <div style="background:var(--bg3);border:1px solid var(--border);border-radius:12px;padding:16px;margin-bottom:14px">
    <div class="flex items-center justify-between mb-3">
      <div class="flex items-center gap-2"><span style="font-size:18px">🏛️</span><div class="font-bold text-sm">SUNAT</div></div>
      <span class="badge <?= $sCl ?>"><span class="dot"></span><?= $sl ?></span>
    </div>

    <?php if (!empty($nota['sunat_mensaje'])): ?>
      <div class="text-xs text-muted mb-2"><?= clean($nota['sunat_mensaje']) ?></div>
    <?php endif; ?>
    <?php if (!empty($nota['sunat_hash'])): ?>
      <div class="text-xs text-muted mb-2"><strong>Hash:</strong> <code style="font-size:11px"><?= clean($nota['sunat_hash']) ?></code></div>
    <?php endif; ?>

    <div class="flex gap-1 flex-wrap">
      <?php if (!empty($nota['sunat_xml'])): ?>
        <a href="?p=notas_credito&action=xml&id=<?= $id ?>" target="_blank" class="btn btn-sm">📄 Ver XML</a>
        <a href="?p=notas_credito&action=xml&id=<?= $id ?>&dl=1" class="btn btn-sm">⬇ Descargar XML</a>
      <?php endif; ?>

      <?php if (!empty($nota['sunat_xml']) && $se !== 'aceptado'): ?>
        <form method="POST" style="display:inline" onsubmit="return confirm('¿Enviar esta nota a SUNAT?')">
          <input type="hidden" name="action" value="enviar_sunat">
          <input type="hidden" name="id" value="<?= $id ?>">
          <button type="submit" class="btn btn-sm btn-primary">📤 Enviar a SUNAT</button>
        </form>
      <?php endif; ?>

      <?php if (empty($nota['sunat_xml']) || $se === 'rechazado'): ?>
        <form method="POST" style="display:inline" onsubmit="return confirm('¿Generar XML de esta nota?')">
          <input type="hidden" name="action" value="<?= empty($nota['sunat_xml']) ? 'emitir' : 'regenerar' ?>">
          <input type="hidden" name="id" value="<?= $id ?>">
          <button type="submit" class="btn btn-sm"><?= empty($nota['sunat_xml']) ? '⚡ Generar XML' : '🔄 Regenerar XML' ?></button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php
    }

// ─── NUEVA NOTA ───────────────────────────────────────────────────
} elseif ($action === 'nueva') {
    $ventas = $db->query("
        SELECT v.id, v.serie, v.numero, v.tipo_comprobante, v.total, v.fecha, v.sunat_estado,
               c.nombre AS cliente
        FROM ventas v
        JOIN clientes c ON v.cliente_id = c.id
        WHERE v.tipo_comprobante IN('boleta','factura')
          AND v.sunat_xml IS NOT NULL
          AND v.estado != 'anulado'
          AND NOT EXISTS (
              SELECT 1 FROM notas_credito nc
              WHERE nc.venta_id = v.id AND nc.tipo_nota='credito' AND nc.sunat_estado='aceptado'
          )
        ORDER BY v.fecha DESC
        LIMIT 500
    ")->fetchAll();
?>
<div class="card" style="max-width:900px">
  <div class="sec-header mb-3">
    <div class="sec-title">Nueva Nota de Crédito / Débito</div>
    <a href="?p=notas_credito" class="btn btn-sm btn-ghost">← Volver</a>
  </div>

  <form method="POST">
    <input type="hidden" name="action" value="crear">
    <div style="display:grid;grid-template-columns:1fr 320px;gap:16px;align-items:start">

      <div>
        <div class="form-group mb-3">
          <label class="form-label">Comprobante a afectar (con XML generado) *</label>
          <select name="venta_id" class="form-input" required id="selVenta">
            <option value="">— Seleccioná un comprobante —</option>
            <?php foreach ($ventas as $v): ?>
              <option value="<?= $v['id'] ?>"
                      data-tipo="<?= $v['tipo_comprobante'] ?>"
                      data-total="<?= $v['total'] ?>">
                [<?= strtoupper($v['sunat_estado'] ?? '') ?>]
                <?= strtoupper($v['tipo_comprobante']) ?> ·
                <?= clean($v['serie']) ?>-<?= str_pad((string)$v['numero'],8,'0',STR_PAD_LEFT) ?> ·
                <?= clean($v['cliente']) ?> ·
                S/. <?= number_format($v['total'],2) ?> ·
                <?= date('d/m/Y', strtotime($v['fecha'])) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if (empty($ventas)): ?>
            <div class="text-xs text-muted mt-1" style="color:var(--amber)">⚠️ No hay comprobantes con XML generado. Emití una boleta o factura primero.</div>
          <?php endif; ?>
        </div>

        <div class="form-group mb-3">
          <label class="form-label">Código de motivo (catálogo SUNAT 09) *</label>
          <select name="cod_motivo" class="form-input" required>
            <option value="">— Seleccioná el motivo —</option>
            <option value="01">01 — Anulación de la operación</option>
            <option value="02">02 — Anulación por error en el RUC</option>
            <option value="06">06 — Devolución total</option>
            <option value="07">07 — Devolución por ítem(s) de la operación</option>
            <option value="13">13 — Ajuste en operaciones de exportación</option>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">Descripción del motivo *</label>
          <textarea name="des_motivo" class="form-input" rows="3" required placeholder="Describí brevemente el motivo de la nota..." style="resize:vertical"></textarea>
        </div>
      </div>

      <div>
        <div style="background:var(--bg3);border:1.5px solid var(--border);border-radius:12px;padding:16px;margin-bottom:12px">
          <div class="font-bold text-sm mb-3">Tipo de nota</div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px">
            <label style="cursor:pointer">
              <input type="radio" name="tipo_nota" value="credito" checked style="display:none" id="rNC">
              <div id="lblNC" class="btn btn-primary" style="text-align:center;font-size:13px">− Crédito</div>
            </label>
            <label style="cursor:pointer">
              <input type="radio" name="tipo_nota" value="debito" style="display:none" id="rND">
              <div id="lblND" class="btn" style="text-align:center;font-size:13px">+ Débito</div>
            </label>
          </div>
          <div id="tipoInfo" style="font-size:11px;padding:8px 10px;border-radius:8px;background:rgba(30,168,161,.08);border:1px solid rgba(30,168,161,.2);color:var(--teal-d)">
            ℹ <strong>Crédito:</strong> reduce el importe del comprobante original (devoluciones, anulaciones).
          </div>
          <div id="seriePreview" class="text-xs text-muted mt-3" style="padding:8px 10px;border-radius:8px;background:var(--bg2)">
            # Serie asignada automáticamente al guardar.
          </div>
        </div>

        <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">Crear nota</button>
        <a href="?p=notas_credito" class="btn" style="width:100%;justify-content:center;margin-top:8px">Cancelar</a>
      </div>
    </div>
  </form>
</div>

<script>
(function(){
  var rNC  = document.getElementById('rNC');
  var rND  = document.getElementById('rND');
  var lblNC = document.getElementById('lblNC');
  var lblND = document.getElementById('lblND');
  var inf  = document.getElementById('tipoInfo');
  var pre  = document.getElementById('seriePreview');
  var sel  = document.getElementById('selVenta');

  var seriesMap = {
    credito: { factura: '<?= defined('SUNAT_SERIE_NC_FACTURA') ? SUNAT_SERIE_NC_FACTURA : 'FC01' ?>', boleta: '<?= defined('SUNAT_SERIE_NC_BOLETA') ? SUNAT_SERIE_NC_BOLETA : 'BC01' ?>' },
    debito:  { factura: '<?= defined('SUNAT_SERIE_ND_FACTURA') ? SUNAT_SERIE_ND_FACTURA : 'FD01' ?>', boleta: '<?= defined('SUNAT_SERIE_ND_BOLETA') ? SUNAT_SERIE_ND_BOLETA : 'BD01' ?>' }
  };

  function update() {
    var tipo   = rNC.checked ? 'credito' : 'debito';
    var opt    = sel.options[sel.selectedIndex];
    var tipDoc = opt ? (opt.dataset.tipo || '') : '';

    if (tipo === 'credito') {
      lblNC.className = 'btn btn-primary';
      lblND.className = 'btn';
      inf.innerHTML = 'ℹ <strong>Crédito:</strong> reduce el importe del comprobante original (devoluciones, anulaciones).';
    } else {
      lblNC.className = 'btn';
      lblND.className = 'btn btn-primary';
      inf.innerHTML = 'ℹ <strong>Débito:</strong> aumenta el importe del comprobante original (cobros adicionales).';
    }

    pre.textContent = (tipDoc && seriesMap[tipo] && seriesMap[tipo][tipDoc])
      ? '# Serie: ' + seriesMap[tipo][tipDoc]
      : '# Serie asignada automáticamente al guardar.';
  }

  rNC.addEventListener('change', update);
  rND.addEventListener('change', update);
  sel.addEventListener('change', update);
  update();
})();
</script>
<?php
} else {
    header('Location: ' . BASE_URL . '/index.php?p=notas_credito'); exit;
}

require_once __DIR__ . '/../includes/footer.php';
