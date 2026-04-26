<?php
/**
 * VetPro — Vista previa con datos de ejemplo
 */
require_once __DIR__ . '/../includes/config.php';
// Permitir acceso autenticado o desde iframe de plantillas
if (!isset($_SESSION['user'])) { echo 'No autorizado'; exit; }

$fmt = in_array($_GET['fmt']??'a4',['a4','voucher']) ? $_GET['fmt'] : 'a4';
$db  = getDB();
$cfg = $db->query("SELECT clave,valor FROM configuracion")->fetchAll(PDO::FETCH_KEY_PAIR);
function cfg(array $c, string $k, string $def=''): string { return $c[$k] ?? $def; }

$logo_url = !empty($cfg['logo_path']) && file_exists(UPLOADS_PATH.'/'.$cfg['logo_path']) ? UPLOADS_URL.'/'.$cfg['logo_path'] : '';

// Datos de ejemplo
$v = [
    'tipo_comprobante' => 'boleta',
    'serie'      => 'B001',
    'numero'     => 111,
    'fecha'      => date('Y-m-d H:i:s'),
    'cli_nombre' => 'EJEMPLO CLIENTE DE MUESTRA',
    'dni'        => '12345678',
    'cli_ruc'    => '',
    'cli_dir'    => '-',
    'mascota_nombre' => 'Luna',
    'vendedor'   => 'Sistema',
    'metodo_pago'=> 'efectivo',
    'subtotal'   => 18.64,
    'igv'        => 3.36,
    'descuento'  => 0,
    'total'      => 22.00,
];
$items = [
    ['descripcion'=>'Consulta general','cantidad'=>1,'precio_unitario'=>22.00,'subtotal'=>22.00],
];
$tipo_label  = ['boleta'=>'BOLETA DE VENTA','factura'=>'FACTURA ELECTRÓNICA','ticket'=>'NOTA DE VENTA'];
$tipo_color  = ['boleta'=>'#f59e0b','factura'=>'#f59e0b','ticket'=>'#555'];
$metodo_labels=['efectivo'=>'EFECTIVO','yape'=>'YAPE','plin'=>'PLIN','tarjeta_debito'=>'TARJETA DÉBITO','tarjeta_credito'=>'TARJETA CRÉDITO','transferencia'=>'TRANSFERENCIA'];
$num_fmt     = $v['serie'].'-'.str_pad($v['numero'],6,'0',STR_PAD_LEFT);
$qr_data     = BASE_URL.'/print/consulta.php?preview=1';

if ($fmt==='voucher') include __DIR__.'/tpl_voucher.php';
else include __DIR__.'/tpl_a4.php';
