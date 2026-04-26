<?php
$page = 'portal'; $pageTitle = 'Portal Cliente';
require_once __DIR__ . '/../includes/header.php';
$db = getDB();

// Generar token simple para URL del portal
function genToken($cliente_id) {
    return base64_encode($cliente_id . ':' . md5($cliente_id . 'vetpro_salt_2025'));
}

$clientes = $db->query("SELECT c.*, (SELECT COUNT(*) FROM mascotas WHERE cliente_id=c.id AND estado='activo') as n_mascotas FROM clientes c WHERE c.activo=1 ORDER BY c.nombre")->fetchAll();
$base_url = BASE_URL;
?>
<div class="page">
<div class="sec-header">
  <div><div class="sec-title">Portal del Cliente</div><div class="sec-sub">Acceso de dueños al historial de sus mascotas</div></div>
  <span class="badge b-blue">🌐 Links individuales por cliente</span>
</div>

<div class="grid g3 mb-2">
  <div class="card text-center" style="padding:24px">
    <div style="font-size:36px;margin-bottom:8px">🔗</div>
    <div class="font-bold text-sm">Link único por cliente</div>
    <div class="text-xs text-muted mt-1">Cada cliente recibe una URL con token para acceder sin contraseña</div>
  </div>
  <div class="card text-center" style="padding:24px">
    <div style="font-size:36px;margin-bottom:8px">💬</div>
    <div class="font-bold text-sm">Envío por WhatsApp</div>
    <div class="text-xs text-muted mt-1">Manda el link directamente desde la tabla de abajo</div>
  </div>
  <div class="card text-center" style="padding:24px">
    <div style="font-size:36px;margin-bottom:8px">🐾</div>
    <div class="font-bold text-sm">Historial, citas y vacunas</div>
    <div class="text-xs text-muted mt-1">El cliente puede ver el historial clínico de sus mascotas</div>
  </div>
</div>

<div class="card" style="padding:0">
  <div class="table-wrap">
    <table class="vtable">
      <thead><tr><th>Cliente</th><th>Tel. WhatsApp</th><th>Mascotas</th><th>Link del portal</th><th>Enviar</th></tr></thead>
      <tbody>
        <?php foreach($clientes as $c):
          $token = genToken($c['id']);
          $link  = $base_url . '/portal_cliente.php?t=' . urlencode($token);
          $tel = preg_replace('/[^0-9]/','',ltrim($c['telefono'],'+'));
          if(strlen($tel)<11) $tel='51'.$tel;
          $wa_msg = "🌐 *Portal VetPro*\n\nHola {$c['nombre']} 👋\n\nYa puedes ver el historial clínico de tus mascotas en tu portal personal:\n\n🔗 {$link}\n\nPuedes ver:\n• Historial de consultas\n• Vacunas y recordatorios\n• Próximas citas\n• Recetas médicas\n\nVetPro 🐾";
        ?>
        <tr>
          <td><div class="flex items-center gap-2">
            <div class="avatar" style="width:30px;height:30px;font-size:11px"><?= strtoupper(substr($c['nombre'],0,1).substr(strstr($c['nombre'],' ') ?: ' ',1,1)) ?></div>
            <span class="td-main"><?= clean($c['nombre']) ?></span>
          </div></td>
          <td><?= clean($c['telefono']) ?></td>
          <td><span class="badge b-teal"><?= $c['n_mascotas'] ?> mascota<?= $c['n_mascotas']!=1?'s':'' ?></span></td>
          <td>
            <div style="font-size:11px;color:var(--blue);font-family:monospace;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($link) ?></div>
          </td>
          <td><div class="flex gap-1">
            <button class="btn btn-xs" onclick="copyToClipboard('<?= htmlspecialchars($link) ?>',this)">📋 Copiar</button>
            <a href="https://wa.me/<?= $tel ?>?text=<?= rawurlencode($wa_msg) ?>" target="_blank" class="btn btn-xs btn-wa">💬 WA</a>
          </div></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card mt-2" style="background:var(--bg3)">
  <div class="sec-title mb-1">ℹ️ Cómo funciona el portal del cliente</div>
  <div class="text-sm text-muted" style="line-height:1.8">
    1. Cada cliente tiene un link único con un token seguro.<br>
    2. Al hacer clic, el cliente accede al archivo <code>portal_cliente.php</code> en tu servidor.<br>
    3. Desde ahí puede ver las mascotas registradas a su nombre, el historial clínico, las vacunas y las próximas citas.<br>
    4. El archivo <code>portal_cliente.php</code> ya está incluido en el proyecto — solo necesita estar en la raíz de <code>/vetpro/</code>.<br>
    5. No requiere contraseña: el token en la URL identifica al cliente.
  </div>
</div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
