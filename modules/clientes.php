<?php
$page = 'clientes'; $pageTitle = 'Clientes';
require_once __DIR__ . '/../includes/header.php';
$db = getDB();

$action = $_GET['action'] ?? 'list';
$msg = '';

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'])) {
    $a = $_POST['action'];
    if ($a === 'save') {
        $fields = ['nombre','dni','ruc','telefono','email','direccion','como_conocio','notas'];
        $data = [];
        foreach ($fields as $f) $data[$f] = trim($_POST[$f] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $sets = implode(',', array_map(fn($f)=>"$f=:$f", $fields));
            $st = $db->prepare("UPDATE clientes SET $sets WHERE id=:id");
            $data['id'] = $id;
        } else {
            $cols = implode(',', $fields);
            $pls  = implode(',', array_map(fn($f)=>":$f", $fields));
            $st = $db->prepare("INSERT INTO clientes ($cols,sede_id) VALUES ($pls,:sede_id)");
            $data['sede_id'] = $user['sede_id'] ?? 1;
        }
        $st->execute($data);
        $msg = 'success';
        $action = 'list';
    }
}

if ($action === 'delete' && isset($_GET['id'])) {
    $db->prepare("UPDATE clientes SET activo=0 WHERE id=?")->execute([(int)$_GET['id']]);
    $action = 'list';
}

$editing = null;
if (in_array($action, ['editar','ver']) && isset($_GET['id'])) {
    $st = $db->prepare("SELECT * FROM clientes WHERE id=?");
    $st->execute([(int)$_GET['id']]); $editing = $st->fetch();
}

$search = trim($_GET['q'] ?? '');
$pg = max(1,(int)($_GET['pg']??1));
$per = 20; $offset = ($pg-1)*$per;
$where = "activo=1"; $params = [];
if ($search) {
    $where .= " AND (nombre LIKE ? OR dni LIKE ? OR ruc LIKE ? OR telefono LIKE ? OR email LIKE ?)";
    $like = "%$search%"; $params = [$like,$like,$like,$like,$like];
}
$total_rows = $db->prepare("SELECT COUNT(*) FROM clientes WHERE $where");
$total_rows->execute($params); $total = $total_rows->fetchColumn();
$st = $db->prepare("SELECT c.*, (SELECT COUNT(*) FROM mascotas WHERE cliente_id=c.id AND estado='activo') as n_mascotas FROM clientes c WHERE $where ORDER BY c.created_at DESC LIMIT $per OFFSET $offset");
$st->execute($params); $clientes = $st->fetchAll();
$total_pag = ceil($total/$per);

$api_url = BASE_URL . '/api/consulta_documento.php';
?>

<?php if($msg==='success'): ?>
<div class="alert alert-success alert-dismiss mb-2">✅ Cliente guardado correctamente.</div>
<?php endif; ?>

<?php if(in_array($action,['nuevo','editar'])): ?>
<div class="card" style="max-width:700px">
  <div class="sec-header">
    <div>
      <div class="sec-title"><?= $action==='editar'?'Editar':'Nuevo'?> Cliente</div>
      <?php if($action==='nuevo'): ?><div class="sec-sub">Ingresa el DNI o RUC para autocompletar los datos</div><?php endif; ?>
    </div>
    <a href="?p=clientes" class="btn btn-sm">← Volver</a>
  </div>

  <!-- BUSCADOR DNI / RUC -->
  <?php if($action==='nuevo'): ?>
  <div style="background:var(--teal-l);border:1.5px solid var(--teal);border-radius:12px;padding:16px 18px;margin-bottom:20px">
    <div class="flex items-center gap-2 mb-3">
      <span style="font-size:20px">🔍</span>
      <div><div style="font-size:13px;font-weight:700;color:var(--teal-d)">Búsqueda automática RENIEC / SUNAT</div>
      <div style="font-size:11px;color:var(--teal-d);opacity:.7">Ingresa el DNI (8 dígitos) o RUC (11 dígitos) y los datos se autocompletan</div></div>
    </div>
    <div class="flex gap-2" style="flex-wrap:wrap">
      <!-- DNI -->
      <div style="flex:1;min-width:200px">
        <label class="form-label">DNI del titular</label>
        <div class="flex gap-1">
          <div style="position:relative;flex:1">
            <input id="inp-dni-buscar" class="form-input" type="text" maxlength="8" placeholder="12345678"
              style="padding-right:36px" oninput="this.value=this.value.replace(/\D/,'')" onkeydown="if(event.key==='Enter'){event.preventDefault();buscarDoc('dni')}">
            <span id="ico-dni" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);font-size:15px;pointer-events:none"></span>
          </div>
          <button type="button" class="btn btn-primary" onclick="buscarDoc('dni')" id="btn-dni">
            <span id="btn-dni-txt">Buscar</span>
          </button>
        </div>
      </div>
      <!-- Separador -->
      <div style="display:flex;align-items:flex-end;padding-bottom:2px;color:var(--text3);font-size:12px;font-weight:600">— o —</div>
      <!-- RUC -->
      <div style="flex:1;min-width:200px">
        <label class="form-label">RUC (persona jurídica)</label>
        <div class="flex gap-1">
          <div style="position:relative;flex:1">
            <input id="inp-ruc-buscar" class="form-input" type="text" maxlength="11" placeholder="20123456789"
              style="padding-right:36px" oninput="this.value=this.value.replace(/\D/,'')" onkeydown="if(event.key==='Enter'){event.preventDefault();buscarDoc('ruc')}">
            <span id="ico-ruc" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);font-size:15px;pointer-events:none"></span>
          </div>
          <button type="button" class="btn btn-primary" onclick="buscarDoc('ruc')" id="btn-ruc">
            <span id="btn-ruc-txt">Buscar</span>
          </button>
        </div>
      </div>
    </div>
    <!-- Resultado badge -->
    <div id="resultado-busqueda" style="display:none;margin-top:12px"></div>
  </div>
  <?php endif; ?>

  <!-- FORMULARIO PRINCIPAL -->
  <form method="POST" id="form-cliente">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= $editing['id'] ?? '' ?>">

    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Nombre completo / Razón social *</label>
        <input class="form-input" id="inp-nombre" name="nombre" value="<?= clean($editing['nombre']??'') ?>" required placeholder="Ej: María García López">
      </div>
      <div class="form-group">
        <label class="form-label">Teléfono WhatsApp *</label>
        <input class="form-input" name="telefono" value="<?= clean($editing['telefono']??'') ?>" placeholder="+51 987 654 321" required>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label class="form-label">DNI</label>
        <div style="position:relative">
          <input class="form-input" id="inp-dni" name="dni" maxlength="8" value="<?= clean($editing['dni']??'') ?>" placeholder="12345678"
            oninput="this.value=this.value.replace(/\D/,''); if(this.value.length===8 && document.getElementById('inp-nombre') && !document.getElementById('inp-nombre').value) buscarDocInline('dni',this.value)">
          <span id="ico-dni-inline" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);font-size:13px;pointer-events:none"></span>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">RUC</label>
        <div style="position:relative">
          <input class="form-input" id="inp-ruc" name="ruc" maxlength="11" value="<?= clean($editing['ruc']??'') ?>" placeholder="20123456789"
            oninput="this.value=this.value.replace(/\D/,''); if(this.value.length===11 && document.getElementById('inp-nombre') && !document.getElementById('inp-nombre').value) buscarDocInline('ruc',this.value)">
          <span id="ico-ruc-inline" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);font-size:13px;pointer-events:none"></span>
        </div>
      </div>
    </div>

    <div class="form-group">
      <label class="form-label">Dirección</label>
      <input class="form-input" id="inp-direccion" name="direccion" value="<?= clean($editing['direccion']??'') ?>" placeholder="Av. Principal 123, Miraflores, Lima">
    </div>

    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Email</label>
        <input class="form-input" type="email" name="email" value="<?= clean($editing['email']??'') ?>">
      </div>
      <div class="form-group">
        <label class="form-label">¿Cómo nos conoció?</label>
        <select class="form-input" name="como_conocio">
          <?php foreach(['referido'=>'Referido','google'=>'Google','redes_sociales'=>'Redes sociales','otro'=>'Otro'] as $k=>$v): ?>
          <option value="<?= $k ?>" <?= ($editing['como_conocio']??'otro')===$k?'selected':'' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="form-group">
      <label class="form-label">Notas</label>
      <textarea class="form-input" name="notas"><?= clean($editing['notas']??'') ?></textarea>
    </div>

    <div class="flex gap-1">
      <button type="submit" class="btn btn-primary">💾 Guardar cliente</button>
      <a href="?p=clientes" class="btn">Cancelar</a>
    </div>
  </form>
</div>

<script>
const API_URL = '<?= $api_url ?>';

// ── Búsqueda desde el panel superior (botón Buscar) ──
async function buscarDoc(tipo) {
  const numEl  = document.getElementById('inp-' + tipo + '-buscar');
  const btnTxt = document.getElementById('btn-' + tipo + '-txt');
  const btnEl  = document.getElementById('btn-' + tipo);
  const icoEl  = document.getElementById('ico-' + tipo);
  const resEl  = document.getElementById('resultado-busqueda');
  const num    = numEl ? numEl.value.trim() : '';

  if (!num) { numEl.focus(); return; }
  const len = tipo === 'dni' ? 8 : 11;
  if (num.length !== len) {
    showResultado('error', `El ${tipo.toUpperCase()} debe tener ${len} dígitos.`);
    return;
  }

  // Estado: cargando
  btnTxt.textContent = '...';
  btnEl.disabled = true;
  icoEl.textContent = '⏳';

  try {
    const resp = await fetch(`${API_URL}?tipo=${tipo}&numero=${num}`);
    const data = await resp.json();

    if (data.ok) {
      // Autocompletar formulario
      setField('inp-nombre',    data.nombre    || '');
      setField('inp-dni',       data.dni       || (tipo==='dni' ? num : ''));
      setField('inp-ruc',       data.ruc       || (tipo==='ruc' ? num : ''));
      setField('inp-direccion', data.direccion || '');

      icoEl.textContent = '✅';

      let extra = '';
      if (data.estado)    extra += ` · Estado: <strong>${data.estado}</strong>`;
      if (data.condicion) extra += ` · Condición: <strong>${data.condicion}</strong>`;
      if (data.tipo)      extra += ` · Tipo: ${data.tipo}`;

      showResultado('ok',
        `✅ <strong>${data.nombre}</strong> encontrado en <strong>${data.fuente}</strong>${extra}`
      );

      // Enfocar teléfono si está vacío
      const telEl = document.querySelector('input[name="telefono"]');
      if (telEl && !telEl.value) setTimeout(() => telEl.focus(), 100);

    } else {
      icoEl.textContent = '❌';
      showResultado('error', `❌ ${data.error}`);
    }
  } catch(e) {
    icoEl.textContent = '⚠️';
    showResultado('error', '⚠️ Error de red. Verifica tu conexión o ingresa los datos manualmente.');
  } finally {
    btnTxt.textContent = 'Buscar';
    btnEl.disabled = false;
  }
}

// ── Búsqueda inline (cuando escriben en el campo DNI/RUC del formulario) ──
async function buscarDocInline(tipo, num) {
  const icoEl = document.getElementById('ico-' + tipo + '-inline');
  if (icoEl) icoEl.textContent = '⏳';
  try {
    const resp = await fetch(`${API_URL}?tipo=${tipo}&numero=${num}`);
    const data = await resp.json();
    if (data.ok) {
      if (!document.getElementById('inp-nombre').value) setField('inp-nombre', data.nombre || '');
      if (!document.getElementById('inp-direccion').value) setField('inp-direccion', data.direccion || '');
      if (icoEl) icoEl.textContent = '✅';
    } else {
      if (icoEl) icoEl.textContent = '❌';
    }
  } catch(e) {
    if (icoEl) icoEl.textContent = '⚠️';
  }
}

function setField(id, value) {
  const el = document.getElementById(id);
  if (el && value) {
    el.value = value;
    // Efecto visual de llenado
    el.style.background = 'var(--teal-l)';
    el.style.borderColor = 'var(--teal)';
    setTimeout(() => { el.style.background = ''; el.style.borderColor = ''; }, 1800);
  }
}

function showResultado(tipo, html) {
  const el = document.getElementById('resultado-busqueda');
  if (!el) return;
  el.style.display = 'block';
  el.innerHTML = `<div style="padding:10px 14px;border-radius:8px;font-size:13px;
    background:${tipo==='ok'?'var(--green-l)':'var(--red-l)'};
    border:1px solid ${tipo==='ok'?'#86efac':'#fca5a5'};
    color:${tipo==='ok'?'var(--green)':'var(--red)'}">${html}</div>`;
}

// Enter en los campos de búsqueda
['dni','ruc'].forEach(t => {
  const el = document.getElementById('inp-' + t + '-buscar');
  if (el) el.addEventListener('keydown', e => { if(e.key==='Enter'){e.preventDefault();buscarDoc(t);} });
});
</script>

<?php else: ?>
<!-- ──────────── LISTA ──────────── -->
<div class="sec-header">
  <div><div class="sec-title">Clientes registrados</div><div class="sec-sub"><?= $total ?> clientes en total</div></div>
  <div class="flex gap-1">
    <form class="flex gap-1" method="GET">
      <input type="hidden" name="p" value="clientes">
      <input class="form-input" name="q" value="<?= clean($search) ?>" placeholder="Nombre, DNI, RUC, teléfono..." style="width:240px">
      <button type="submit" class="btn">Buscar</button>
    </form>
    <a href="?p=clientes&action=nuevo" class="btn btn-primary">+ Nuevo Cliente</a>
  </div>
</div>

<div class="card" style="padding:0">
  <div class="table-wrap">
    <table class="vtable">
      <thead><tr><th>Cliente</th><th>DNI / RUC</th><th>Teléfono</th><th>Dirección</th><th>Mascotas</th><th>Acciones</th></tr></thead>
      <tbody>
        <?php foreach($clientes as $c): ?>
        <tr>
          <td>
            <div class="flex items-center gap-2">
              <div class="avatar" style="width:32px;height:32px;font-size:11px;flex-shrink:0"><?= strtoupper(substr($c['nombre'],0,1).(strstr($c['nombre'],' ') ? substr(strstr($c['nombre'],' '),1,1) : '')) ?></div>
              <div>
                <div class="td-main"><?= clean($c['nombre']) ?></div>
                <?php if($c['email']): ?><div class="text-xs text-muted" style="color:var(--blue)"><?= clean($c['email']) ?></div><?php endif; ?>
              </div>
            </div>
          </td>
          <td>
            <?php if($c['dni']): ?><div><span class="badge b-gray" style="font-family:monospace">DNI <?= clean($c['dni']) ?></span></div><?php endif; ?>
            <?php if($c['ruc']): ?><div><span class="badge b-blue" style="font-family:monospace">RUC <?= clean($c['ruc']) ?></span></div><?php endif; ?>
            <?php if(!$c['dni']&&!$c['ruc']): ?><span class="text-muted">—</span><?php endif; ?>
          </td>
          <td>
            <a href="https://wa.me/<?= preg_replace('/\D/','',ltrim($c['telefono'],'+')) ?>" target="_blank" class="flex items-center gap-1" style="color:var(--wa-d);text-decoration:none">
              💬 <?= clean($c['telefono']) ?>
            </a>
          </td>
          <td class="text-muted text-xs" style="max-width:180px"><?= clean($c['direccion']??'—') ?></td>
          <td><span class="badge b-teal"><?= $c['n_mascotas'] ?> mascota<?= $c['n_mascotas']!=1?'s':'' ?></span></td>
          <td>
            <div class="flex gap-1">
              <a href="?p=clientes&action=editar&id=<?= $c['id'] ?>" class="btn btn-xs">✏️ Editar</a>
              <a href="?p=mascotas&cliente_id=<?= $c['id'] ?>" class="btn btn-xs">🐾 Mascotas</a>
              <a href="<?= BASE_URL ?>/index.php?p=whatsapp&cliente_id=<?= $c['id'] ?>" class="btn btn-xs btn-wa" title="WhatsApp">💬</a>
              <a href="?p=clientes&action=delete&id=<?= $c['id'] ?>" class="btn btn-xs" style="color:var(--red)" onclick="return confirm('¿Dar de baja a este cliente?')">✕</a>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($clientes)): ?>
        <tr><td colspan="6" class="text-center text-muted" style="padding:40px">
          <?php if($search): ?>No se encontraron clientes para "<?= clean($search) ?>".<?php else: ?>Aún no hay clientes registrados. <a href="?p=clientes&action=nuevo">Agregar el primero →</a><?php endif; ?>
        </td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if($total_pag>1): ?>
  <div class="pagination" style="padding:16px">
    <?php if($pg>1): ?><a href="?p=clientes&pg=<?= $pg-1 ?>&q=<?= urlencode($search) ?>" class="page-btn">‹</a><?php endif; ?>
    <?php for($i=max(1,$pg-2);$i<=min($total_pag,$pg+2);$i++): ?>
    <a href="?p=clientes&pg=<?= $i ?>&q=<?= urlencode($search) ?>" class="page-btn <?= $i===$pg?'active':'' ?>"><?= $i ?></a>
    <?php endfor; ?>
    <?php if($pg<$total_pag): ?><a href="?p=clientes&pg=<?= $pg+1 ?>&q=<?= urlencode($search) ?>" class="page-btn">›</a><?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
