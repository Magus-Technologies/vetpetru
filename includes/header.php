<?php
requireLogin();
$user = getUser();
$page = $page ?? 'dashboard';
$pageTitle = $pageTitle ?? 'Dashboard';

// Contar alertas
$db = getDB();
$alertas = 0;
// Citas hoy pendientes
$st = $db->prepare("SELECT COUNT(*) FROM citas WHERE fecha = CURDATE() AND estado = 'pendiente'");
$st->execute(); $alertas += (int)$st->fetchColumn();
// Stock crítico
$st = $db->prepare("SELECT COUNT(*) FROM productos WHERE stock <= stock_minimo AND activo = 1");
$st->execute(); $stock_critico = (int)$st->fetchColumn();
// Vacunas vencidas/por vencer
$st = $db->prepare("SELECT COUNT(*) FROM vacunas WHERE proxima_dosis <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND recordatorio_enviado = 0");
$st->execute(); $vacunas_alerta = (int)$st->fetchColumn();

$nav = [
  ['icon'=>'📊','label'=>'Dashboard','page'=>'dashboard','section'=>'Principal'],
  ['icon'=>'📅','label'=>'Citas','page'=>'citas','section'=>''],
  ['icon'=>'👥','label'=>'Clientes','page'=>'clientes','section'=>''],
  ['icon'=>'🐶','label'=>'Mascotas','page'=>'mascotas','section'=>''],
  ['icon'=>'🏥','label'=>'Historia Clínica','page'=>'historial','section'=>''],
  ['icon'=>'🔬','label'=>'Exámenes','page'=>'examenes','section'=>''],
  ['icon'=>'💉','label'=>'Vacunación','page'=>'vacunas','section'=>'Clínica'],
  ['icon'=>'💊','label'=>'Farmacia','page'=>'farmacia','section'=>''],
  ['icon'=>'🧾','label'=>'Facturación','page'=>'facturacion','section'=>''],
  ['icon'=>'🖨️','label'=>'Plantillas','page'=>'plantillas','section'=>''],
  ['icon'=>'💰','label'=>'Caja','page'=>'caja','section'=>'Gestión'],
  ['icon'=>'🧑‍⚕️','label'=>'Personal','page'=>'personal','section'=>''],
  ['icon'=>'📈','label'=>'Reportes','page'=>'reportes','section'=>''],
  ['icon'=>'💬','label'=>'WhatsApp','page'=>'whatsapp','section'=>''],
  ['icon'=>'🌐','label'=>'Portal Cliente','page'=>'portal','section'=>''],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VetPro — <?= clean($pageTitle) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Syne:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/public/css/main.css">
</head>
<body>

<nav class="sidebar">
  <div class="sidebar-logo">
    <div class="logo-icon">🐾</div>
    <div>
      <div class="logo-text">VetPro</div>
      <div class="logo-sub">Sistema Veterinario</div>
    </div>
  </div>

  <?php
  $lastSection = '';
  foreach ($nav as $item):
    if ($item['section'] && $item['section'] !== $lastSection):
      $lastSection = $item['section'];
  ?>
  <div class="sidebar-section"><?= $item['section'] ?></div>
  <?php endif; ?>
  <a href="<?= BASE_URL ?>/index.php?p=<?= $item['page'] ?>" class="nav-item <?= $page === $item['page'] ? 'active' : '' ?>">
    <span class="ni"><?= $item['icon'] ?></span>
    <?= $item['label'] ?>
    <?php if($item['page']==='citas' && $alertas > 0): ?>
      <span class="nav-badge"><?= $alertas ?></span>
    <?php endif; ?>
    <?php if($item['page']==='farmacia' && $stock_critico > 0): ?>
      <span class="nav-badge"><?= $stock_critico ?></span>
    <?php endif; ?>
  </a>
  <?php endforeach; ?>

  <div class="sidebar-footer">
    <div class="user-info-wrap">
      <div class="avatar"><?= strtoupper(substr($user['nombre'],0,1).substr(strstr($user['nombre'],' '),1,1)) ?></div>
      <div>
        <div class="user-name"><?= clean($user['nombre']) ?></div>
        <div class="user-role"><?= ucfirst($user['rol']) ?></div>
      </div>
      <a href="<?= BASE_URL ?>/logout.php" class="logout-btn" title="Salir">⏏</a>
    </div>
  </div>
</nav>

<div class="main">
  <div class="topbar">
    <div class="topbar-title"><?= clean($pageTitle) ?></div>
    <form class="search-box" method="GET" action="<?= BASE_URL ?>/index.php">
      <input type="hidden" name="p" value="buscar">
      <span class="search-icon">🔍</span>
      <input type="text" name="q" placeholder="Buscar paciente, cliente..." value="<?= clean($_GET['q']??'') ?>">
    </form>
    <div class="topbar-actions">
      <?php if($vacunas_alerta > 0): ?>
      <a href="<?= BASE_URL ?>/index.php?p=vacunas" class="notif-btn" title="<?= $vacunas_alerta ?> vacunas por vencer">
        💉<span class="notif-dot"></span>
      </a>
      <?php endif; ?>
      <a href="<?= BASE_URL ?>/index.php?p=citas&action=nueva" class="btn btn-primary">+ Nueva Atención</a>
    </div>
  </div>
  <div class="content">
