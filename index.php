<?php
require_once __DIR__ . '/includes/config.php';
requireLogin();

$p = preg_replace('/[^a-z_]/', '', strtolower($_GET['p'] ?? 'dashboard'));
$allowed = ['dashboard','citas','clientes','mascotas','historial','examenes','vacunas','farmacia','facturacion','plantillas','caja','personal','reportes','whatsapp','portal','buscar'];
if (!in_array($p, $allowed)) $p = 'dashboard';

$module = __DIR__ . '/modules/' . $p . '.php';
if (!file_exists($module)) $module = __DIR__ . '/modules/dashboard.php';

require $module;
