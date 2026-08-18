<?php
$page = 'servicios'; $pageTitle = 'Servicios';
require_once __DIR__ . '/../includes/config.php';   // (ya cargado por index.php; require_once no duplica)
$db = getDB();
$action = $_GET['action'] ?? 'list';
$msg = ''; $msg_tipo = 'success';

// Tipos válidos (coinciden con el ENUM de la tabla servicios)
$TIPOS = ['consulta','cirugia','vacuna','bano','grooming','hospitalizacion','laboratorio','otro'];
$TIPO_LABEL = [
  'consulta'=>'Consulta','cirugia'=>'Cirugía','vacuna'=>'Vacuna','bano'=>'Baño',
  'grooming'=>'Grooming','hospitalizacion'=>'Hospitalización','laboratorio'=>'Laboratorio','otro'=>'Otro'
];

// ════════════════════════════════════════════════════════════════
// Acciones que redirigen: DEBEN correr ANTES de imprimir el header.
// (header.php envía el HTML; un header('Location') posterior fallaría)
// Usamos PRG (Post/Redirect/Get) con un flash en sesión.
// ════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $id     = (int)($_POST['id'] ?? 0);
    $nombre = trim($_POST['nombre'] ?? '');
    $tipo   = in_array($_POST['tipo'] ?? '', $TIPOS, true) ? $_POST['tipo'] : 'otro';
    $precio = (float)str_replace(',', '.', $_POST['precio'] ?? '0');
    $dur    = max(0, (int)($_POST['duracion_minutos'] ?? 30));
    $desc   = trim($_POST['descripcion'] ?? '');
    $activo = isset($_POST['activo']) ? 1 : 0;

    if ($nombre === '' || $precio < 0) {
        $_SESSION['flash'] = 'error:Falta el nombre o el precio no es válido.';
    } else {
        try {
            if ($id) {
                $db->prepare("UPDATE servicios SET nombre=?, descripcion=?, tipo=?, precio=?, duracion_minutos=?, activo=? WHERE id=?")
                   ->execute([$nombre, $desc, $tipo, $precio, $dur, $activo, $id]);
                auditLog('editar', 'servicios', "Servicio #$id: $nombre");
                $_SESSION['flash'] = 'ok:Servicio actualizado correctamente.';
            } else {
                $sede = getSede() ?: 1;
                $db->prepare("INSERT INTO servicios (sede_id,nombre,descripcion,tipo,precio,duracion_minutos,activo) VALUES (?,?,?,?,?,?,?)")
                   ->execute([$sede, $nombre, $desc, $tipo, $precio, $dur, $activo]);
                auditLog('crear', 'servicios', "Servicio: $nombre");
                $_SESSION['flash'] = 'ok:Servicio creado correctamente.';
            }
        } catch (Exception $e) {
            $_SESSION['flash'] = 'error:No se pudo guardar el servicio. Revisa los datos.';
        }
    }
    header('Location: ?p=servicios'); exit;
}

// Activar / desactivar
if ($action === 'toggle' && isset($_GET['id'])) {
    try { $db->prepare("UPDATE servicios SET activo = 1 - activo WHERE id=?")->execute([(int)$_GET['id']]); } catch (Exception $e) {}
    header('Location: ?p=servicios'); exit;
}

// "Eliminar" = baja lógica (desactivar), para no romper ventas históricas que referencian el servicio
if ($action === 'delete' && isset($_GET['id'])) {
    try {
        $db->prepare("UPDATE servicios SET activo=0 WHERE id=?")->execute([(int)$_GET['id']]);
        auditLog('desactivar', 'servicios', "Servicio #".(int)$_GET['id']);
    } catch (Exception $e) {}
    header('Location: ?p=servicios'); exit;
}

// A partir de aquí ya se imprime la página
require_once __DIR__ . '/../includes/header.php';

// Flash del PRG
if (!empty($_SESSION['flash'])) {
    [$ft, $fm] = array_pad(explode(':', $_SESSION['flash'], 2), 2, '');
    $msg = $fm; $msg_tipo = ($ft === 'ok' ? 'success' : 'danger');
    unset($_SESSION['flash']);
}

// Cargar servicio a editar
$editing = null;
if ($action === 'editar' && isset($_GET['id'])) {
    $st = $db->prepare("SELECT * FROM servicios WHERE id=?");
    $st->execute([(int)$_GET['id']]);
    $editing = $st->fetch();
    if (!$editing) $action = 'list';
}

// Filtros de la lista
$q      = trim($_GET['q'] ?? '');
$tipo_f = $_GET['tipo'] ?? '';
$where  = "WHERE 1=1";
$params = [];
if ($q !== '')      { $where .= " AND nombre LIKE ?"; $params[] = "%$q%"; }
if (in_array($tipo_f, $TIPOS, true)) { $where .= " AND tipo = ?"; $params[] = $tipo_f; }

$stL = $db->prepare("SELECT * FROM servicios $where ORDER BY activo DESC, tipo, nombre");
$stL->execute($params);
$servicios = $stL->fetchAll();

$total_act = (int)$db->query("SELECT COUNT(*) FROM servicios WHERE activo=1")->fetchColumn();
$total_ina = (int)$db->query("SELECT COUNT(*) FROM servicios WHERE activo=0")->fetchColumn();
?>

<div class="page">
  <?php if ($msg): ?><div class="alert alert-<?= $msg_tipo ?>"><span class="alert-icon"><?= $msg_tipo==='success'?'✅':'⚠️' ?></span><?= clean($msg) ?></div><?php endif; ?>

<?php if ($action === 'nuevo' || $action === 'editar'): ?>
  <!-- ═══════════ FORMULARIO ═══════════ -->
  <div class="page-title"><?= $editing ? '✏️ Editar servicio' : '➕ Nuevo servicio' ?></div>
  <div class="page-desc">Estos servicios son los que aparecen en el buscador de <strong>Ventas / Facturación</strong>.</div>

  <div class="card" style="max-width:640px;padding:22px;margin-top:14px">
    <form method="post">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">

      <div class="form-group">
        <label class="form-label">Nombre del servicio *</label>
        <input class="form-input" name="nombre" required maxlength="200" value="<?= clean($editing['nombre'] ?? '') ?>" placeholder="Ej: Consulta general">
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Tipo</label>
          <select class="form-input" name="tipo">
            <?php foreach ($TIPOS as $t): ?>
              <option value="<?= $t ?>" <?= (($editing['tipo'] ?? 'consulta') === $t) ? 'selected' : '' ?>><?= $TIPO_LABEL[$t] ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Precio (S/) *</label>
          <input class="form-input" name="precio" type="number" step="0.01" min="0" required value="<?= isset($editing['precio']) ? number_format($editing['precio'],2,'.','') : '' ?>" placeholder="0.00">
        </div>
        <div class="form-group">
          <label class="form-label">Duración (min)</label>
          <input class="form-input" name="duracion_minutos" type="number" min="0" value="<?= (int)($editing['duracion_minutos'] ?? 30) ?>">
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Descripción (opcional)</label>
        <input class="form-input" name="descripcion" maxlength="500" value="<?= clean($editing['descripcion'] ?? '') ?>" placeholder="Detalle breve del servicio">
      </div>

      <div class="form-group">
        <label class="flex items-center gap-1" style="cursor:pointer;font-weight:600;margin:0">
          <input type="checkbox" name="activo" value="1" <?= (!$editing || !empty($editing['activo'])) ? 'checked' : '' ?> style="width:auto;margin:0">
          Activo (visible en Ventas)
        </label>
      </div>

      <div class="flex gap-1" style="margin-top:8px">
        <button type="submit" class="btn btn-primary">💾 Guardar servicio</button>
        <a href="?p=servicios" class="btn btn-ghost">Cancelar</a>
      </div>
    </form>
  </div>

<?php else: ?>
  <!-- ═══════════ LISTA ═══════════ -->
  <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
    <div>
      <div class="page-title">🏷️ Servicios</div>
      <div class="page-desc">Catálogo de servicios que aparece en el buscador de Ventas / Facturación. <strong><?= $total_act ?></strong> activos · <?= $total_ina ?> inactivos.</div>
    </div>
    <a href="?p=servicios&action=nuevo" class="btn btn-primary">➕ Nuevo servicio</a>
  </div>

  <form method="get" class="flex gap-1" style="margin:14px 0">
    <input type="hidden" name="p" value="servicios">
    <input class="form-input" name="q" value="<?= clean($q) ?>" placeholder="🔍 Buscar servicio..." style="max-width:280px">
    <select class="form-input" name="tipo" style="max-width:180px" onchange="this.form.submit()">
      <option value="">Todos los tipos</option>
      <?php foreach ($TIPOS as $t): ?>
        <option value="<?= $t ?>" <?= $tipo_f===$t?'selected':'' ?>><?= $TIPO_LABEL[$t] ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-primary btn-sm">Filtrar</button>
    <?php if ($q!=='' || $tipo_f!==''): ?><a href="?p=servicios" class="btn btn-ghost btn-sm">Limpiar</a><?php endif; ?>
  </form>

  <div class="card table-wrap">
    <table class="vtable">
      <thead><tr>
        <th>Servicio</th><th>Tipo</th><th style="text-align:right">Precio</th>
        <th style="text-align:center">Duración</th><th style="text-align:center">Estado</th><th style="text-align:right">Acciones</th>
      </tr></thead>
      <tbody>
        <?php foreach ($servicios as $s): ?>
        <tr<?= empty($s['activo']) ? ' style="opacity:.55"' : '' ?>>
          <td>
            <div class="td-main"><?= clean($s['nombre']) ?></div>
            <?php if (!empty($s['descripcion'])): ?><div class="text-xs text-muted"><?= clean($s['descripcion']) ?></div><?php endif; ?>
          </td>
          <td><span class="badge"><?= $TIPO_LABEL[$s['tipo']] ?? clean($s['tipo']) ?></span></td>
          <td style="text-align:right;font-weight:700">S/ <?= number_format($s['precio'],2) ?></td>
          <td style="text-align:center;color:var(--text3)"><?= (int)$s['duracion_minutos'] ?> min</td>
          <td style="text-align:center">
            <?php if (!empty($s['activo'])): ?>
              <span class="badge" style="background:var(--success-l);color:var(--success-d)">Activo</span>
            <?php else: ?>
              <span class="badge" style="background:#f1f5f9;color:#64748b">Inactivo</span>
            <?php endif; ?>
          </td>
          <td style="text-align:right;white-space:nowrap">
            <a href="?p=servicios&action=editar&id=<?= (int)$s['id'] ?>" class="btn btn-xs btn-primary">✏️ Editar</a>
            <a href="?p=servicios&action=toggle&id=<?= (int)$s['id'] ?>" class="btn btn-xs btn-ghost"><?= !empty($s['activo']) ? '⏸️ Desactivar' : '▶️ Activar' ?></a>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($servicios)): ?>
        <tr><td colspan="6" style="text-align:center;padding:34px;color:var(--text3)">
          No hay servicios<?= ($q!==''||$tipo_f!=='') ? ' con ese filtro' : '' ?>. <a href="?p=servicios&action=nuevo" style="color:var(--primary);font-weight:700">Crea el primero</a>.
        </td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
