<?php
/**
 * VetPro — Motor de impresión
 * URL: /vetpro/print/comprobante.php?id=X&fmt=a4|voucher
 */
require_once __DIR__ . '/../includes/config.php';
requireLogin();

$id  = (int)($_GET['id'] ?? 0);
$fmt = in_array($_GET['fmt']??'a4',['a4','voucher']) ? $_GET['fmt'] : 'a4';

if (!$id) { echo 'ID requerido.'; exit; }

$db = getDB();

// Datos de la venta
$st = $db->prepare("
    SELECT v.*,
           c.nombre as cli_nombre, c.dni, c.ruc as cli_ruc, c.telefono, c.direccion as cli_dir,
           m.nombre as mascota_nombre,
           u.nombre as vendedor
    FROM ventas v
    JOIN clientes c ON c.id=v.cliente_id
    LEFT JOIN mascotas m ON m.id=v.mascota_id
    LEFT JOIN usuarios u ON u.id=v.usuario_id
    WHERE v.id=?
");
$st->execute([$id]); $v = $st->fetch();
if (!$v) { echo 'Venta no encontrada.'; exit; }

$items = $db->prepare("SELECT * FROM venta_items WHERE venta_id=? ORDER BY id");
$items->execute([$id]); $items = $items->fetchAll();

// Config / plantilla
$cfg = $db->query("SELECT clave,valor FROM configuracion")->fetchAll(PDO::FETCH_KEY_PAIR);
function cfg(array $c, string $k, string $def=''): string { return $c[$k] ?? $def; }

$logo_url = !empty($cfg['logo_path']) && file_exists(UPLOADS_PATH.'/'.$cfg['logo_path']) ? UPLOADS_URL.'/'.$cfg['logo_path'] : '';

// Tipo de comprobante labels
$tipo_label = ['boleta'=>'BOLETA DE VENTA','factura'=>'FACTURA ELECTRÓNICA','ticket'=>'NOTA DE VENTA'];
$tipo_color = ['boleta'=>'#f59e0b','factura'=>'#f59e0b','ticket'=>'#555'];

$num_fmt = $v['serie'].'-'.str_pad($v['numero'],6,'0',STR_PAD_LEFT);
$metodo_labels = ['efectivo'=>'EFECTIVO','yape'=>'YAPE','plin'=>'PLIN','tarjeta_debito'=>'TARJETA DÉBITO','tarjeta_credito'=>'TARJETA CRÉDITO','transferencia'=>'TRANSFERENCIA'];

// QR simple (URL de consulta)
$qr_data = BASE_URL.'/print/consulta.php?serie='.$v['serie'].'&num='.$v['numero'];

if ($fmt === 'voucher') {
    include __DIR__ . '/tpl_voucher.php';
} else {
    include __DIR__ . '/tpl_a4.php';
}
