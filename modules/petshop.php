<?php
$page = 'petshop'; $pageTitle = 'Pet Shop';
require_once __DIR__ . '/../includes/header.php';
$db = getDB();

// ── Crear tablas si no existen ──
$db->exec("CREATE TABLE IF NOT EXISTS petshop_unidades (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(80) NOT NULL,
  abreviatura VARCHAR(20) NOT NULL,
  tipo ENUM('peso','volumen','longitud','unidad','pack') DEFAULT 'unidad',
  activo TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
// Insertar unidades por defecto si la tabla está vacía
$count = $db->query("SELECT COUNT(*) FROM petshop_unidades")->fetchColumn();
if ($count == 0) {
    $db->exec("INSERT INTO petshop_unidades (nombre,abreviatura,tipo) VALUES
        ('Unidad','und','unidad'),('Kilogramo','kg','peso'),('Gramo','g','peso'),
        ('Litro','L','volumen'),('Mililitro','ml','volumen'),('Pack / Bolsa','pack','pack'),
        ('Metro','m','longitud'),('Caja','caja','pack'),('Docena','doc','pack')");
}
$db->exec("CREATE TABLE IF NOT EXISTS petshop_productos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  categoria VARCHAR(100),
  nombre VARCHAR(250) NOT NULL,
  descripcion TEXT,
  marca VARCHAR(100),
  unidad_id INT DEFAULT 1,
  contenido VARCHAR(50),
  precio_costo DECIMAL(10,2) DEFAULT 0,
  precio_venta DECIMAL(10,2) DEFAULT 0,
  stock INT DEFAULT 0,
  stock_minimo INT DEFAULT 5,
  codigo_barras VARCHAR(50),
  activo TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$action = $_GET['action'] ?? 'list';
$msg = ''; $err_msg = '';

// ════════════════════════════════════════
// AJAX — Gestión de Unidades
// Detectar si es petición AJAX por header
// ════════════════════════════════════════
$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
$is_ajax = $is_ajax || ($_POST['ajax'] ?? '') === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pa = $_POST['action'] ?? '';

    // ── CRUD Unidades (vía AJAX o POST normal) ──
    if ($pa === 'get_unidades') {
        header('Content-Type: application/json');
        $rows = $db->query("SELECT * FROM petshop_unidades WHERE activo=1 ORDER BY nombre ASC")->fetchAll();
        echo json_encode(['ok' => true, 'data' => $rows]);
        exit;
    }

    if ($pa === 'save_unidad') {
        $uid    = (int)($_POST['uid'] ?? 0);
        $nombre = trim($_POST['nombre'] ?? '');
        $abr    = trim($_POST['abreviatura'] ?? '');
        $tipo   = $_POST['tipo'] ?? 'unidad';

        if (!$nombre || !$abr) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Nombre y abreviatura son requeridos.']);
            exit;
        }
        if ($uid) {
            $db->prepare("UPDATE petshop_unidades SET nombre=?,abreviatura=?,tipo=? WHERE id=?")
               ->execute([$nombre, $abr, $tipo, $uid]);
        } else {
            $db->prepare("INSERT INTO petshop_unidades (nombre,abreviatura,tipo) VALUES (?,?,?)")
               ->execute([$nombre, $abr, $tipo]);
            $uid = (int)$db->lastInsertId();
        }
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'id' => $uid, 'nombre' => $nombre, 'abreviatura' => $abr]);
        exit;
    }

    if ($pa === 'delete_unidad') {
        $uid = (int)($_POST['uid'] ?? 0);
        // Verificar que no tiene productos asignados
        $en_uso = $db->prepare("SELECT COUNT(*) FROM petshop_productos WHERE unidad_id=? AND activo=1");
        $en_uso->execute([$uid]); $n = (int)$en_uso->fetchColumn();
        if ($n > 0) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => "No se puede eliminar: $n producto(s) usan esta unidad."]);
            exit;
        }
        $db->prepare("UPDATE petshop_unidades SET activo=0 WHERE id=?")->execute([$uid]);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── CRUD Productos ──
    if ($pa === 'save_producto') {
        $pid = (int)($_POST['pid'] ?? 0);
        $fields = ['categoria','nombre','descripcion','marca','unidad_id','contenido',
                   'precio_costo','precio_venta','stock','stock_minimo','codigo_barras'];
        $data = [];
        foreach ($fields as $f) $data[$f] = trim($_POST[$f] ?? '') !== '' ? trim($_POST[$f]) : null;

        if (!$data['nombre']) {
            $err_msg = 'El nombre del producto es obligatorio.';
        } else {
            if ($pid) {
                $sets = implode(',', array_map(fn($f)=>"$f=:$f", $fields));
                $db->prepare("UPDATE petshop_productos SET $sets WHERE id=:id")
                   ->execute(array_merge($data, ['id' => $pid]));
            } else {
                // Agregar sede_id al INSERT
                $data['sede_id'] = getSede();
                $all_fields = array_merge($fields, ['sede_id']);
                $cols = implode(',', $all_fields);
                $pls  = implode(',', array_map(fn($f)=>":$f", $all_fields));
                $db->prepare("INSERT INTO petshop_productos ($cols) VALUES ($pls)")->execute($data);
            }
            $msg = 'success'; $action = 'list';
        }
    }

    if ($pa === 'delete_producto') {
        $db->prepare("UPDATE petshop_productos SET activo=0 WHERE id=?")->execute([(int)($_POST['pid'] ?? 0)]);
        $msg = 'eliminado'; $action = 'list';
    }
}

// ── Datos para listado ──
$editing = null;
if ($action === 'editar' && isset($_GET['id'])) {
    $st = $db->prepare("SELECT * FROM petshop_productos WHERE id=?");
    $st->execute([(int)$_GET['id']]); $editing = $st->fetch();
}

$unidades    = $db->query("SELECT * FROM petshop_unidades WHERE activo=1 ORDER BY nombre ASC")->fetchAll();
$search      = trim($_GET['q'] ?? '');
$cat_f       = $_GET['cat'] ?? '';
$where = "p.activo=1"; $params = [];
if ($search) { $where .= " AND (p.nombre LIKE ? OR p.marca LIKE ?)"; $like="%$search%"; $params=[$like,$like]; }
if ($cat_f)  { $where .= " AND p.categoria=?"; $params[] = $cat_f; }
// Filtro sede — agregar sede_id a petshop_productos si no existe
try {
    $_r=$db->query("SHOW COLUMNS FROM `petshop_productos` LIKE 'sede_id'")->fetchAll();
    if(empty($_r)) { $db->exec("ALTER TABLE petshop_productos ADD COLUMN sede_id INT DEFAULT 1"); $_r=[1]; }
    if(!empty($_r)) {
        if(!verTodasSedes()) { $where.=" AND p.sede_id=".getSede(); }
    }
} catch(Exception $e) {}
$_ps_sw = verTodasSedes() ? "" : " AND sede_id=".getSede();
$st = $db->prepare("SELECT p.*,u.nombre as unidad_nombre,u.abreviatura
    FROM petshop_productos p LEFT JOIN petshop_unidades u ON u.id=p.unidad_id
    WHERE $where ORDER BY p.nombre ASC");
$st->execute($params); $productos = $st->fetchAll();
$categorias = $db->query("SELECT DISTINCT categoria FROM petshop_productos WHERE activo=1$_ps_sw AND categoria IS NOT NULL ORDER BY categoria")->fetchAll(PDO::FETCH_COLUMN);

// Stats — filtradas por sede
$total_prods = $db->query("SELECT COUNT(*) FROM petshop_productos WHERE activo=1$_ps_sw")->fetchColumn();
$stock_bajo  = $db->query("SELECT COUNT(*) FROM petshop_productos WHERE activo=1 AND stock<=stock_minimo$_ps_sw")->fetchColumn();
// Valor inventario = stock * precio_costo (valor real del inventario)
$valor_total = $db->query("SELECT COALESCE(SUM(stock*precio_costo),0) FROM petshop_productos WHERE activo=1$_ps_sw")->fetchColumn();
?>

<div class="page">

<?php if ($msg==='success'): ?><div class="alert alert-success"><span class="alert-icon">✅</span>Producto guardado correctamente.</div><?php endif; ?>
<?php if ($msg==='eliminado'): ?><div class="alert alert-warn"><span class="alert-icon">⚠️</span>Producto dado de baja.</div><?php endif; ?>
<?php if ($err_msg): ?><div class="alert alert-danger"><span class="alert-icon">❌</span><?= clean($err_msg) ?></div><?php endif; ?>

<?php if (in_array($action, ['nuevo','editar'])): ?>
<!-- ════ FORMULARIO PRODUCTO ════ -->
<div class="card" style="max-width:700px">
  <div class="sec-header">
    <div>
      <div class="sec-title">🛒 <?= $action==='editar'?'Editar':'Nuevo'?> Producto Pet Shop</div>
      <div class="sec-sub">Completa los datos del producto</div>
    </div>
    <a href="?p=petshop" class="btn btn-ghost btn-sm">← Volver</a>
  </div>

  <form method="POST">
    <input type="hidden" name="action" value="save_producto">
    <input type="hidden" name="pid" value="<?= $editing['id']??'' ?>">

    <div class="form-row">
      <div class="form-group">
        <label class="form-label required">Nombre del producto</label>
        <input class="form-input" name="nombre" value="<?= clean($editing['nombre']??'') ?>" required placeholder="Ej: Royal Canin Adult 1.5kg">
      </div>
      <div class="form-group">
        <label class="form-label">Marca</label>
        <input class="form-input" name="marca" value="<?= clean($editing['marca']??'') ?>" placeholder="Ej: Royal Canin, Purina, Pedigree">
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Categoría</label>
        <input class="form-input" name="categoria" list="cats-datalist"
               value="<?= clean($editing['categoria']??'') ?>" placeholder="Alimentos, Accesorios, Juguetes...">
        <datalist id="cats-datalist">
          <?php foreach ($categorias as $cat): ?><option value="<?= clean($cat) ?>"><?php endforeach; ?>
          <option value="Alimentos secos"><option value="Alimentos húmedos">
          <option value="Snacks y Premios"><option value="Accesorios">
          <option value="Juguetes"><option value="Higiene y Baño">
          <option value="Ropa y Moda"><option value="Camas y Casas">
          <option value="Collares y Correas"><option value="Transporte">
        </datalist>
      </div>
      <div class="form-group">
        <label class="form-label">Código de barras</label>
        <input class="form-input" name="codigo_barras" value="<?= clean($editing['codigo_barras']??'') ?>" placeholder="7890123456789">
      </div>
    </div>

    <!-- Unidad de medida con botón gestionar -->
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Unidad de medida</label>
        <div class="flex gap-1">
          <select class="form-input" name="unidad_id" id="sel-unidad-prod" style="flex:1">
            <?php foreach ($unidades as $u): ?>
            <option value="<?= $u['id'] ?>" <?= ($editing['unidad_id']??1)==$u['id']?'selected':'' ?>>
              <?= clean($u['nombre']) ?> (<?= clean($u['abreviatura']) ?>)
            </option>
            <?php endforeach; ?>
          </select>
          <button type="button" class="btn btn-ghost btn-icon" onclick="abrirModalUnidades()" title="Gestionar unidades de medida" style="flex-shrink:0">
            ⚙️
          </button>
        </div>
        <div class="form-hint">Haz clic en ⚙️ para agregar, editar o eliminar unidades</div>
      </div>
      <div class="form-group">
        <label class="form-label">Contenido / Presentación</label>
        <input class="form-input" name="contenido" value="<?= clean($editing['contenido']??'') ?>" placeholder="Ej: 1.5kg, 400ml, x10 unidades">
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Precio de costo (S/.)</label>
        <div class="input-group">
          <span class="input-addon">S/.</span>
          <input class="form-input" type="number" step="0.01" min="0" name="precio_costo" value="<?= clean($editing['precio_costo']??'0') ?>" style="border-radius:0 var(--r-sm) var(--r-sm) 0;border-left:none">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Precio de venta (S/.)</label>
        <div class="input-group">
          <span class="input-addon">S/.</span>
          <input class="form-input" type="number" step="0.01" min="0" name="precio_venta" value="<?= clean($editing['precio_venta']??'0') ?>" style="border-radius:0 var(--r-sm) var(--r-sm) 0;border-left:none">
        </div>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Stock actual</label>
        <input class="form-input" type="number" min="0" name="stock" value="<?= clean($editing['stock']??'0') ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Stock mínimo (para alerta)</label>
        <input class="form-input" type="number" min="0" name="stock_minimo" value="<?= clean($editing['stock_minimo']??'5') ?>">
      </div>
    </div>

    <div class="form-group">
      <label class="form-label">Descripción</label>
      <textarea class="form-input" name="descripcion" style="min-height:70px"><?= clean($editing['descripcion']??'') ?></textarea>
    </div>

    <div class="flex gap-2">
      <button type="submit" class="btn btn-primary">💾 Guardar producto</button>
      <a href="?p=petshop" class="btn btn-ghost">Cancelar</a>
    </div>
  </form>
</div>

<?php else: ?>
<!-- ════ LISTA DE PRODUCTOS ════ -->

<!-- Stats -->
<div class="grid g3 mb-3">
  <div class="stat-card">
    <div class="stat-icon si-accent">🛒</div>
    <div class="stat-value"><?= $total_prods ?></div>
    <div class="stat-label">Productos activos</div>
  </div>
  <div class="stat-card" style="<?= $stock_bajo>0?'border-color:var(--warning)':'' ?>">
    <div class="stat-icon si-warning">⚠️</div>
    <div class="stat-value"><?= $stock_bajo ?></div>
    <div class="stat-label">Bajo stock mínimo</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon si-success">💰</div>
    <div class="stat-value">S/. <?= number_format($valor_total, 0) ?></div>
    <div class="stat-label">Valor en inventario</div>
  </div>
</div>

<!-- Barra de acciones -->
<div class="flex items-center gap-2 mb-3 flex-wrap">
  <form method="GET" class="flex gap-2 items-center" style="flex:1">
    <input type="hidden" name="p" value="petshop">
    <input class="form-input" name="q" value="<?= clean($search) ?>" placeholder="Buscar producto o marca..." style="width:220px;max-width:280px">
    <select class="form-input" name="cat" style="width:160px">
      <option value="">Todas las categorías</option>
      <?php foreach ($categorias as $cat): ?>
      <option value="<?= clean($cat) ?>" <?= $cat_f===clean($cat)?'selected':'' ?>><?= clean($cat) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-ghost btn-sm">Filtrar</button>
  </form>
  <button class="btn btn-ghost btn-sm" onclick="abrirModalUnidades()">⚙️ Gestionar unidades</button>
  <a href="?p=petshop&action=nuevo" class="btn btn-primary">＋ Nuevo Producto</a>
</div>

<!-- Tabla -->
<div class="card" style="padding:0">
  <div class="table-wrap">
    <table class="vtable">
      <thead>
        <tr>
          <th>Producto</th>
          <th>Categoría</th>
          <th>Unidad</th>
          <th>Precio venta</th>
          <th>Stock</th>
          <th>Estado</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($productos as $p): ?>
        <tr>
          <td>
            <div class="td-main"><?= clean($p['nombre']) ?></div>
            <div class="text-xs text-muted">
              <?= clean($p['marca']??'') ?>
              <?= $p['contenido'] ? ' · '.clean($p['contenido']) : '' ?>
              <?= $p['codigo_barras'] ? ' · '.clean($p['codigo_barras']) : '' ?>
            </div>
          </td>
          <td>
            <?php if ($p['categoria']): ?>
            <span class="badge b-accent"><?= clean($p['categoria']) ?></span>
            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
          </td>
          <td>
            <span class="badge b-gray" style="font-family:monospace"><?= clean($p['abreviatura']??'und') ?></span>
            <div class="text-xs text-muted"><?= clean($p['unidad_nombre']??'Unidad') ?></div>
          </td>
          <td>
            <div class="font-bold" style="color:var(--success)">S/. <?= number_format($p['precio_venta'], 2) ?></div>
            <?php if ($p['precio_costo'] > 0): ?>
            <div class="text-xs text-muted">Costo: S/. <?= number_format($p['precio_costo'], 2) ?></div>
            <?php endif; ?>
          </td>
          <td>
            <div class="font-semi <?= $p['stock'] <= $p['stock_minimo'] ? 'color-danger' : '' ?>">
              <?= $p['stock'] ?>
            </div>
            <?php if ($p['stock'] <= $p['stock_minimo']): ?>
            <div class="text-xs" style="color:var(--danger)">⚠️ Stock bajo</div>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge <?= $p['stock'] > 0 ? 'b-success' : 'b-danger' ?>">
              <?= $p['stock'] > 0 ? '✅ Disponible' : '❌ Agotado' ?>
            </span>
          </td>
          <td>
            <div class="flex gap-1">
              <a href="?p=petshop&action=editar&id=<?= $p['id'] ?>" class="btn btn-xs btn-outline-primary">✏️ Editar</a>
              <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="delete_producto">
                <input type="hidden" name="pid" value="<?= $p['id'] ?>">
                <button type="submit" class="btn btn-xs btn-ghost" style="color:var(--danger)"
                        onclick="return confirm('¿Dar de baja el producto «<?= clean($p['nombre']) ?>»?')">
                  🗑️ Eliminar
                </button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($productos)): ?>
        <tr><td colspan="7" class="text-center text-muted" style="padding:48px">
          <?= $search || $cat_f ? 'Sin resultados para este filtro.' : 'Aún no hay productos. ¡Agrega el primero!' ?>
        </td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

</div><!-- .page -->

<!-- ════ MODAL GESTIÓN DE UNIDADES ════ -->
<div class="modal-overlay" id="modalUnidades" style="display:none" onclick="if(event.target===this)cerrarModalUnidades()">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">⚙️ Unidades de Medida</div>
      <button class="modal-close" onclick="cerrarModalUnidades()">✕</button>
    </div>
    <div class="modal-body">

      <!-- Lista de unidades existentes -->
      <div style="margin-bottom:20px">
        <div class="font-semi text-sm mb-2" style="color:var(--text2)">Unidades registradas</div>
        <div id="lista-unidades" style="display:flex;flex-direction:column;gap:6px">
          <div class="text-muted text-center" style="padding:12px">Cargando...</div>
        </div>
      </div>

      <!-- Formulario agregar/editar -->
      <div style="background:var(--bg3);border-radius:var(--r-sm);padding:16px;border:1px solid var(--border)">
        <div class="font-semi text-sm mb-3" id="form-unidad-titulo">➕ Agregar nueva unidad</div>
        <input type="hidden" id="edit-uid" value="">
        <div class="form-row-3">
          <div class="form-group mb-0">
            <label class="form-label required">Nombre</label>
            <input class="form-input" id="inp-u-nombre" placeholder="Kilogramo" maxlength="80">
          </div>
          <div class="form-group mb-0">
            <label class="form-label required">Abreviatura</label>
            <input class="form-input" id="inp-u-abr" placeholder="kg" maxlength="20" style="text-transform:lowercase">
          </div>
          <div class="form-group mb-0">
            <label class="form-label">Tipo</label>
            <select class="form-input" id="inp-u-tipo">
              <option value="unidad">Unidad</option>
              <option value="peso">Peso</option>
              <option value="volumen">Volumen</option>
              <option value="longitud">Longitud</option>
              <option value="pack">Pack / Caja</option>
            </select>
          </div>
        </div>
        <div id="u-error" style="display:none;color:var(--danger);font-size:12px;margin-top:6px"></div>
        <div class="flex gap-2 mt-3">
          <button class="btn btn-primary btn-sm" onclick="guardarUnidad()">💾 Guardar</button>
          <button class="btn btn-ghost btn-sm" onclick="cancelarEditUnidad()" id="btn-cancelar-edit" style="display:none">Cancelar edición</button>
        </div>
      </div>

    </div>
  </div>
</div>

<script>
// ════ GESTIÓN DE UNIDADES — apunta al endpoint API dedicado ════

const API_UNIDADES = '<?= BASE_URL ?>/api/unidades.php';

function abrirModalUnidades() {
    document.getElementById('modalUnidades').style.display = 'flex';
    cargarUnidades();
}
function cerrarModalUnidades() {
    document.getElementById('modalUnidades').style.display = 'none';
    cancelarEditUnidad();
}

async function cargarUnidades() {
    const container = document.getElementById('lista-unidades');
    container.innerHTML = '<div class="text-muted text-center" style="padding:12px">⏳ Cargando...</div>';
    try {
        const fd = new FormData();
        fd.append('action', 'get');
        const r = await fetch(API_UNIDADES, { method: 'POST', body: fd });
        if (!r.ok) throw new Error('HTTP ' + r.status);
        const d = await r.json();
        if (!d.ok || !d.data || !d.data.length) {
            container.innerHTML = '<div class="text-muted text-center" style="padding:12px">Sin unidades registradas.</div>';
            return;
        }
        const TIPO_BADGE = { peso:'b-warning', volumen:'b-info', longitud:'b-purple', unidad:'b-accent', pack:'b-orange' };
        container.innerHTML = d.data.map(u => `
            <div class="flex items-center gap-2" id="row-u-${u.id}"
                 style="padding:9px 12px;background:var(--bg2);border:1px solid var(--border);border-radius:var(--r-sm)">
                <span class="badge ${TIPO_BADGE[u.tipo] || 'b-gray'}"
                      style="font-family:monospace;min-width:44px;justify-content:center">
                    ${u.abreviatura}
                </span>
                <div class="flex-1">
                    <div class="font-semi text-sm">${u.nombre}</div>
                    <div class="text-xs text-muted">${u.tipo}</div>
                </div>
                <button onclick="editarUnidad(${u.id}, '${u.nombre.replace(/'/g,"\\'")}', '${u.abreviatura}', '${u.tipo}')"
                        class="btn btn-xs btn-outline-primary" title="Editar esta unidad">✏️ Editar</button>
                <button onclick="eliminarUnidad(${u.id}, '${u.nombre.replace(/'/g,"\\'")}')"
                        class="btn btn-xs btn-ghost" style="color:var(--danger)" title="Eliminar esta unidad">🗑️</button>
            </div>
        `).join('');
    } catch(e) {
        container.innerHTML = `<div class="text-muted text-center" style="padding:12px;color:var(--danger)">
            ❌ Error al cargar unidades.<br><small>${e.message}</small>
        </div>`;
    }
}

function editarUnidad(id, nombre, abr, tipo) {
    document.getElementById('edit-uid').value = id;
    document.getElementById('inp-u-nombre').value = nombre;
    document.getElementById('inp-u-abr').value = abr;
    document.getElementById('inp-u-tipo').value = tipo;
    document.getElementById('form-unidad-titulo').textContent = '✏️ Editando: ' + nombre;
    document.getElementById('btn-cancelar-edit').style.display = 'inline-flex';
    document.getElementById('inp-u-nombre').focus();
    document.getElementById('u-error').style.display = 'none';
}

function cancelarEditUnidad() {
    document.getElementById('edit-uid').value = '';
    document.getElementById('inp-u-nombre').value = '';
    document.getElementById('inp-u-abr').value = '';
    document.getElementById('inp-u-tipo').value = 'unidad';
    document.getElementById('form-unidad-titulo').textContent = '➕ Agregar nueva unidad';
    document.getElementById('btn-cancelar-edit').style.display = 'none';
    document.getElementById('u-error').style.display = 'none';
}

async function guardarUnidad() {
    const uid    = document.getElementById('edit-uid').value;
    const nombre = document.getElementById('inp-u-nombre').value.trim();
    const abr    = document.getElementById('inp-u-abr').value.trim();
    const tipo   = document.getElementById('inp-u-tipo').value;
    const errEl  = document.getElementById('u-error');

    if (!nombre || !abr) {
        errEl.textContent = '⚠️ Nombre y abreviatura son obligatorios.';
        errEl.style.display = 'block';
        return;
    }
    errEl.style.display = 'none';

    try {
        const fd = new FormData();
        fd.append('action', 'save');
        fd.append('uid', uid);
        fd.append('nombre', nombre);
        fd.append('abreviatura', abr);
        fd.append('tipo', tipo);

        const r = await fetch(API_UNIDADES, { method: 'POST', body: fd });
        if (!r.ok) throw new Error('HTTP ' + r.status);
        const d = await r.json();

        if (d.ok) {
            cancelarEditUnidad();
            await cargarUnidades();
            await actualizarSelectUnidad(d.id);
        } else {
            errEl.textContent = '❌ ' + (d.error || 'Error al guardar.');
            errEl.style.display = 'block';
        }
    } catch(e) {
        errEl.textContent = '❌ Error de conexión: ' + e.message;
        errEl.style.display = 'block';
    }
}

async function eliminarUnidad(id, nombre) {
    if (!confirm(`¿Eliminar la unidad "${nombre}"?\n\nNo se puede eliminar si hay productos asignados a esta unidad.`)) return;
    try {
        const fd = new FormData();
        fd.append('action', 'delete');
        fd.append('uid', id);

        const r = await fetch(API_UNIDADES, { method: 'POST', body: fd });
        if (!r.ok) throw new Error('HTTP ' + r.status);
        const d = await r.json();

        if (d.ok) {
            await cargarUnidades();
            await actualizarSelectUnidad();
        } else {
            alert('❌ ' + (d.error || 'No se pudo eliminar.'));
        }
    } catch(e) {
        alert('❌ Error de conexión: ' + e.message);
    }
}

async function actualizarSelectUnidad(selectedId = null) {
    const sel = document.getElementById('sel-unidad-prod');
    if (!sel) return;
    try {
        const fd = new FormData();
        fd.append('action', 'get');
        const r = await fetch(API_UNIDADES, { method: 'POST', body: fd });
        if (!r.ok) return;
        const d = await r.json();
        if (!d.ok || !d.data) return;
        const current = selectedId || sel.value;
        sel.innerHTML = d.data.map(u =>
            `<option value="${u.id}" ${u.id == current ? 'selected' : ''}>${u.nombre} (${u.abreviatura})</option>`
        ).join('');
    } catch(e) { /* silencioso */ }
}

// Enter en los campos del formulario del modal
document.addEventListener('DOMContentLoaded', () => {
    ['inp-u-nombre', 'inp-u-abr'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('keydown', e => {
            if (e.key === 'Enter') { e.preventDefault(); guardarUnidad(); }
        });
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
