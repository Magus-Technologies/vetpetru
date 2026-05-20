<?php
$page = 'sedes'; $pageTitle = 'Multi-Sede';
require_once __DIR__ . '/../includes/header.php';
if (!hasRole(['admin'])) {
    echo '<div class="alert alert-danger">🔒 Solo administradores pueden gestionar sedes.</div>';
    require_once __DIR__.'/../includes/footer.php'; exit;
}
$db = getDB();

// ── Auto-crear/expandir columnas ──
$alter_sedes = ["activo TINYINT(1) DEFAULT 1","color VARCHAR(20) DEFAULT '#1ea8a1'","meta_mensual DECIMAL(12,2) DEFAULT 0","descripcion TEXT","logo_url VARCHAR(500)"];
foreach ($alter_sedes as $col) { try { $db->exec("ALTER TABLE sedes ADD COLUMN $col"); } catch(Exception $e){} }
try { $db->exec("CREATE TABLE IF NOT EXISTS inventario_sedes (id INT AUTO_INCREMENT PRIMARY KEY, producto_id INT NOT NULL, sede_id INT NOT NULL, stock INT DEFAULT 0, UNIQUE KEY uk_prod_sede (producto_id,sede_id))"); } catch(Exception $e){}
try { $db->exec("CREATE TABLE IF NOT EXISTS transferencias_stock (id INT AUTO_INCREMENT PRIMARY KEY, producto_id INT, producto_nombre VARCHAR(200), sede_origen INT, sede_destino INT, cantidad INT, usuario_id INT, nota TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)"); } catch(Exception $e){}

$msg = ''; $msg_tipo = 'success';
$tab = $_GET['tab'] ?? 'overview';
$sede_filtro = (int)($_GET['sede'] ?? 0);

// ── POST ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pa = $_POST['action'] ?? '';

    // Guardar/crear sede
    if ($pa === 'save_sede') {
        $id = (int)($_POST['id'] ?? 0);
        $f  = ['nombre','descripcion','direccion','telefono','email','color','meta_mensual'];
        $d  = []; foreach ($f as $k) $d[$k] = trim($_POST[$k]??'')?:null;
        try {
            if ($id) {
                $sets = implode(',', array_map(fn($k)=>"$k=:$k", $f));
                $db->prepare("UPDATE sedes SET $sets WHERE id=:id")->execute(array_merge($d,['id'=>$id]));
                $msg = '✅ Sede actualizada correctamente.';
            } else {
                $cols = implode(',',$f); $pls = implode(',',array_map(fn($k)=>":$k",$f));
                $db->prepare("INSERT INTO sedes ($cols,activo) VALUES ($pls,1)")->execute($d);
                $msg = '✅ Nueva sede creada correctamente.';
            }
        } catch(Exception $e) { $msg = '❌ Error: '.$e->getMessage(); $msg_tipo='danger'; }
    }

    // Cambiar sede activa del usuario
    if ($pa === 'cambiar_sede') {
        $sid = (int)($_POST['sede_id'] ?? 1);
        $_SESSION['sede_id'] = $sid;
        try { $db->prepare("UPDATE usuarios SET sede_id=? WHERE id=?")->execute([$sid, $user['id']]); } catch(Exception $e){}
        header('Location: '.BASE_URL.'/index.php?p=dashboard'); exit;
    }

    // Asignar personal a sede — el form envía uid en hidden, sid en select
    if ($pa === 'asignar_personal') {
        $uid = (int)($_POST['uid'] ?? 0);
        $sid = (int)($_POST['nueva_sede_id'] ?? 0);
        if ($uid && $sid) {
            try {
                $db->prepare("UPDATE usuarios SET sede_id=? WHERE id=?")->execute([$sid, $uid]);
                $unom = $db->prepare("SELECT nombre FROM usuarios WHERE id=?"); $unom->execute([$uid]); $unom=$unom->fetchColumn();
                $snom = $db->prepare("SELECT nombre FROM sedes WHERE id=?"); $snom->execute([$sid]); $snom=$snom->fetchColumn();
                $msg = '✅ '.$unom.' asignado a '.$snom.' correctamente.';
            } catch(Exception $e) { $msg='❌ Error: '.$e->getMessage(); $msg_tipo='danger'; }
        }
    }

    // Transferir stock entre sedes
    if ($pa === 'transferir_stock') {
        $pid  = (int)($_POST['producto_id'] ?? 0);
        $de   = (int)($_POST['sede_origen'] ?? 0);
        $a    = (int)($_POST['sede_destino'] ?? 0);
        $cant = (int)($_POST['cantidad'] ?? 0);
        $nota = trim($_POST['nota'] ?? '');
        if (!$pid || !$de || !$a || !$cant) { $msg='❌ Completa todos los campos.'; $msg_tipo='danger'; }
        elseif ($de === $a) { $msg='❌ Origen y destino no pueden ser la misma sede.'; $msg_tipo='danger'; }
        else {
            try {
                $prod = $db->prepare("SELECT nombre,stock FROM productos WHERE id=?"); $prod->execute([$pid]); $prod=$prod->fetch();
                if (!$prod) throw new Exception("Producto no encontrado");
                // Verificar stock global del producto
                if ($prod['stock'] < $cant) throw new Exception("Stock insuficiente. Disponible: {$prod['stock']} unidades.");
                // Restar stock global
                $db->prepare("UPDATE productos SET stock=stock-? WHERE id=?")->execute([$cant, $pid]);
                // Registrar transferencia
                $de_nom=$db->prepare("SELECT nombre FROM sedes WHERE id=?"); $de_nom->execute([$de]); $de_nom=$de_nom->fetchColumn();
                $a_nom=$db->prepare("SELECT nombre FROM sedes WHERE id=?"); $a_nom->execute([$a]); $a_nom=$a_nom->fetchColumn();
                $db->prepare("INSERT INTO transferencias_stock (producto_id,producto_nombre,sede_origen,sede_destino,cantidad,usuario_id,nota) VALUES (?,?,?,?,?,?,?)")
                   ->execute([$pid,$prod['nombre'],$de,$a,$cant,$user['id'],$nota]);
                // Actualizar inventario_sedes
                $db->prepare("INSERT INTO inventario_sedes (producto_id,sede_id,stock) VALUES (?,?,0) ON DUPLICATE KEY UPDATE stock=stock-?")->execute([$pid,$de,$cant]);
                $db->prepare("INSERT INTO inventario_sedes (producto_id,sede_id,stock) VALUES (?,?,?) ON DUPLICATE KEY UPDATE stock=stock+?")->execute([$pid,$a,$cant,$cant]);
                $msg = "✅ Transferidos $cant uds. de '{$prod['nombre']}' desde $de_nom hacia $a_nom.";
            } catch(Exception $e) { $msg='❌ '.$e->getMessage(); $msg_tipo='danger'; }
        }
        $tab = 'stock';
    }

    // Activar/desactivar sede
    if ($pa === 'toggle_sede') {
        $id = (int)($_POST['id']??0); $act = (int)($_POST['activo']??1);
        try { $db->prepare("UPDATE sedes SET activo=? WHERE id=?")->execute([$act,$id]); $msg='✅ Estado de sede actualizado.'; } catch(Exception $e){}
    }
}

// ── Datos ──────────────────────────────────────────────────
$sedes=[];
try { $sedes=$db->query("SELECT * FROM sedes ORDER BY activo DESC,nombre ASC")->fetchAll(); } catch(Exception $e){}
$sede_actual = $_SESSION['sede_id'] ?? ($user['sede_id'] ?? 1);

// Stats por sede
$stats = [];
foreach ($sedes as $s) {
    $sid = $s['id']; $st = ['citas'=>0,'ingresos'=>0,'pacientes'=>0,'usuarios'=>0,'consultas'=>0];
    try { $st['citas']     = (int)$db->query("SELECT COUNT(*) FROM citas WHERE sede_id=$sid AND MONTH(fecha)=MONTH(CURDATE())")->fetchColumn(); }catch(Exception $e){}
    try { $st['ingresos']  = (float)$db->query("SELECT COALESCE(SUM(total),0) FROM ventas WHERE sede_id=$sid AND MONTH(fecha)=MONTH(CURDATE()) AND estado='pagado'")->fetchColumn(); }catch(Exception $e){}
    try { $st['pacientes'] = (int)$db->query("SELECT COUNT(*) FROM mascotas WHERE sede_id=$sid AND estado='activo'")->fetchColumn(); }catch(Exception $e){}
    try { $st['usuarios']  = (int)$db->query("SELECT COUNT(*) FROM usuarios WHERE sede_id=$sid AND activo=1")->fetchColumn(); }catch(Exception $e){}
    try { $st['consultas'] = (int)$db->query("SELECT COUNT(*) FROM consultas WHERE sede_id=$sid AND MONTH(fecha)=MONTH(CURDATE())")->fetchColumn(); }catch(Exception $e){}
    $stats[$sid] = $st;
}

// Personal
$personal=[];
try { $personal=$db->query("SELECT u.*,s.nombre as sede_nombre,s.color as sede_color FROM usuarios u LEFT JOIN sedes s ON s.id=u.sede_id WHERE u.activo=1 ORDER BY s.nombre,u.rol,u.nombre")->fetchAll(); } catch(Exception $e){}

// Productos para transferencia
$productos=[];
try { $productos=$db->query("SELECT id,nombre,stock,unidad FROM productos WHERE activo=1 ORDER BY nombre")->fetchAll(); } catch(Exception $e){}

// Historial de transferencias
$transferencias=[];
try { $transferencias=$db->query("SELECT t.*,u.nombre as usuario_nombre,so.nombre as sede_origen_nombre,sd.nombre as sede_destino_nombre FROM transferencias_stock t LEFT JOIN usuarios u ON u.id=t.usuario_id LEFT JOIN sedes so ON so.id=t.sede_origen LEFT JOIN sedes sd ON sd.id=t.sede_destino ORDER BY t.created_at DESC LIMIT 30")->fetchAll(); } catch(Exception $e){}

$sede_vista = $sede_filtro && $tab==='detalle' ? array_filter($sedes, fn($s)=>$s['id']==$sede_filtro) : [];
$sede_vista = $sede_vista ? reset($sede_vista) : null;
?>

<style>
.sede-card { background:var(--bg2); border:1.5px solid var(--border); border-radius:16px; overflow:hidden; transition:all .2s; }
.sede-card:hover { transform:translateY(-2px); box-shadow:0 8px 32px rgba(0,0,0,.1); }
.sede-card-header { padding:16px 18px; display:flex; align-items:center; justify-content:space-between; }
.sede-card-body { padding:0 18px 16px; }
.sede-stat { text-align:center; padding:12px 8px; background:var(--bg3); border-radius:10px; }
.sede-stat-val { font-size:20px; font-weight:800; line-height:1; margin-bottom:3px; }
.sede-stat-lbl { font-size:10px; color:var(--text3); font-weight:600; text-transform:uppercase; }
.tab-nav { display:flex; gap:4px; background:var(--bg3); padding:4px; border-radius:10px; margin-bottom:20px; flex-wrap:wrap; }
.tab-btn { padding:8px 16px; border-radius:8px; font-size:13px; font-weight:600; text-decoration:none; color:var(--text2); transition:all .15s; white-space:nowrap; }
.tab-btn.active { background:var(--bg2); color:var(--text); box-shadow:0 2px 8px rgba(0,0,0,.08); }
.prog-bar { height:6px; background:var(--bg3); border-radius:3px; overflow:hidden; }
.prog-fill { height:100%; border-radius:3px; transition:width .5s; }
.personal-row { display:grid; grid-template-columns:1fr auto auto; gap:12px; align-items:center; padding:12px 16px; border-bottom:1px solid var(--border); }
.personal-row:last-child { border-bottom:none; }
</style>

<div class="page">
<?php if($msg): ?>
<div class="alert alert-<?= $msg_tipo ?> mb-3"><?= $msg ?></div>
<?php endif; ?>

<!-- Header -->
<div class="sec-header mb-3">
  <div>
    <div class="page-title">🏢 Multi-Sede</div>
    <div class="page-desc"><?= count($sedes) ?> sede<?= count($sedes)!=1?'s':'' ?> · Sede activa: <strong><?= clean(array_values(array_filter($sedes,fn($s)=>$s['id']==$sede_actual))[0]['nombre']??'—') ?></strong></div>
  </div>
  <div class="flex gap-2 items-center">
    <!-- Cambiar sede activa -->
    <form method="POST" style="display:flex;gap:6px;align-items:center">
      <input type="hidden" name="action" value="cambiar_sede">
      <div style="font-size:12px;font-weight:600;color:var(--text3)">Operar en:</div>
      <select name="sede_id" class="form-input" style="width:auto;min-width:160px" onchange="this.form.submit()">
        <?php foreach($sedes as $s): ?>
        <option value="<?= $s['id'] ?>" <?= $s['id']==$sede_actual?'selected':'' ?>><?= clean($s['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <button onclick="abrirModalSede()" class="btn btn-primary btn-sm">＋ Nueva Sede</button>
  </div>
</div>

<!-- Tabs -->
<div class="tab-nav">
  <a href="?p=sedes&tab=overview"  class="tab-btn <?= $tab==='overview'?'active':'' ?>">📊 Resumen</a>
  <a href="?p=sedes&tab=personal"  class="tab-btn <?= $tab==='personal'?'active':'' ?>">👥 Personal</a>
  <a href="?p=sedes&tab=stock"     class="tab-btn <?= $tab==='stock'?'active':'' ?>">📦 Stock</a>
  <a href="?p=sedes&tab=config"    class="tab-btn <?= $tab==='config'?'active':'' ?>">⚙️ Configuración</a>
</div>

<?php if($tab==='overview'): ?>
<!-- ═══ TAB: RESUMEN ═══ -->
<div class="grid g<?= min(count($sedes),3) ?> mb-4" style="gap:16px">
<?php foreach($sedes as $s):
    $sid=$s['id']; $st=$stats[$sid]??[];
    $color=$s['color']??'#1ea8a1';
    $meta=$s['meta_mensual']??0;
    $pct=$meta>0?min(100,round(($st['ingresos']??0)/$meta*100)):0;
    $activa=$s['id']==$sede_actual;
?>
<div class="sede-card" style="border-top:3px solid <?= $color ?>;<?= $activa?'box-shadow:0 0 0 2px '.$color.'55':''; ?>">
  <div class="sede-card-header">
    <div style="display:flex;align-items:center;gap:10px">
      <div style="width:38px;height:38px;border-radius:10px;background:<?= $color ?>22;display:flex;align-items:center;justify-content:center;font-size:20px">🏢</div>
      <div>
        <div style="font-size:15px;font-weight:800;color:var(--text)"><?= clean($s['nombre']) ?></div>
        <?php if($activa): ?><span style="background:<?= $color ?>;color:#fff;font-size:10px;font-weight:700;padding:2px 8px;border-radius:999px">ACTIVA</span><?php endif; ?>
        <?php if(!($s['activo']??1)): ?><span style="background:#94a3b8;color:#fff;font-size:10px;font-weight:700;padding:2px 8px;border-radius:999px">INACTIVA</span><?php endif; ?>
      </div>
    </div>
    <div class="flex gap-2">
      <a href="?p=sedes&tab=detalle&sede=<?= $s['id'] ?>" class="btn btn-xs btn-ghost" title="Ver detalle">📋</a>
      <button onclick="editarSede(<?= htmlspecialchars(json_encode($s)) ?>)" class="btn btn-xs btn-ghost" title="Editar">✏️</button>
    </div>
  </div>
  <div class="sede-card-body">
    <!-- Stats 2x2 -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px">
      <div class="sede-stat">
        <div class="sede-stat-val" style="color:<?= $color ?>"><?= $st['citas']??0 ?></div>
        <div class="sede-stat-lbl">Citas mes</div>
      </div>
      <div class="sede-stat">
        <div class="sede-stat-val" style="color:var(--success)">S/ <?= number_format($st['ingresos']??0,0) ?></div>
        <div class="sede-stat-lbl">Ingresos mes</div>
      </div>
      <div class="sede-stat">
        <div class="sede-stat-val"><?= $st['pacientes']??0 ?></div>
        <div class="sede-stat-lbl">Pacientes</div>
      </div>
      <div class="sede-stat">
        <div class="sede-stat-val"><?= $st['usuarios']??0 ?></div>
        <div class="sede-stat-lbl">Usuarios</div>
      </div>
    </div>
    <!-- Consultas del mes -->
    <div style="font-size:11px;color:var(--text3);margin-bottom:8px">
      🩺 <?= $st['consultas']??0 ?> consultas este mes
    </div>
    <!-- Meta mensual -->
    <?php if($meta>0): ?>
    <div>
      <div style="display:flex;justify-content:space-between;font-size:11px;margin-bottom:4px">
        <span style="color:var(--text3)">Meta mensual</span>
        <span style="font-weight:700;color:<?= $pct>=100?'var(--success)':'var(--text)' ?>"><?= $pct ?>%</span>
      </div>
      <div class="prog-bar">
        <div class="prog-fill" style="width:<?= $pct ?>%;background:<?= $pct>=100?'#10b981':$color ?>"></div>
      </div>
      <div style="font-size:10px;color:var(--text3);margin-top:3px">S/ <?= number_format($st['ingresos']??0,0) ?> / S/ <?= number_format($meta,0) ?></div>
    </div>
    <?php endif; ?>
    <!-- Info de contacto -->
    <?php if($s['direccion']||$s['telefono']): ?>
    <div style="margin-top:10px;padding-top:10px;border-top:1px solid var(--border)">
      <?php if($s['direccion']): ?><div style="font-size:11px;color:var(--text3);margin-bottom:2px">📍 <?= clean($s['direccion']) ?></div><?php endif; ?>
      <?php if($s['telefono']): ?><div style="font-size:11px;color:var(--text3)">📞 <?= clean($s['telefono']) ?></div><?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
</div>

<!-- Comparativa rápida -->
<div class="card" style="padding:0">
  <div style="padding:14px 18px;border-bottom:1px solid var(--border);font-size:14px;font-weight:700">📈 Comparativa del mes</div>
  <table class="vtable">
    <thead><tr>
      <th>Sede</th><th style="text-align:center">Citas</th><th style="text-align:center">Consultas</th>
      <th style="text-align:center">Pacientes</th><th style="text-align:center">Usuarios</th><th style="text-align:right">Ingresos</th>
    </tr></thead>
    <tbody>
    <?php
    $tot = ['citas'=>0,'consultas'=>0,'pacientes'=>0,'usuarios'=>0,'ingresos'=>0];
    foreach($sedes as $s): $st=$stats[$s['id']]??[]; $c=$s['color']??'#1ea8a1';
    foreach(array_keys($tot) as $k) $tot[$k]+=($st[$k]??0);
    ?>
    <tr>
      <td><div style="display:flex;align-items:center;gap:8px"><div style="width:10px;height:10px;border-radius:3px;background:<?= $c ?>"></div><strong><?= clean($s['nombre']) ?></strong><?= $s['id']==$sede_actual?' <span style="font-size:10px;color:var(--primary)">(activa)</span>':'' ?></div></td>
      <td style="text-align:center"><?= $st['citas']??0 ?></td>
      <td style="text-align:center"><?= $st['consultas']??0 ?></td>
      <td style="text-align:center"><?= $st['pacientes']??0 ?></td>
      <td style="text-align:center"><?= $st['usuarios']??0 ?></td>
      <td style="text-align:right;font-weight:700;color:var(--success)">S/ <?= number_format($st['ingresos']??0,2) ?></td>
    </tr>
    <?php endforeach; ?>
    <tr style="background:var(--bg3);font-weight:700">
      <td>TOTAL</td>
      <td style="text-align:center"><?= $tot['citas'] ?></td>
      <td style="text-align:center"><?= $tot['consultas'] ?></td>
      <td style="text-align:center"><?= $tot['pacientes'] ?></td>
      <td style="text-align:center"><?= $tot['usuarios'] ?></td>
      <td style="text-align:right;color:var(--success)">S/ <?= number_format($tot['ingresos'],2) ?></td>
    </tr>
    </tbody>
  </table>
</div>

<?php elseif($tab==='personal'): ?>
<!-- ═══ TAB: PERSONAL ═══ -->
<div class="card" style="padding:0;margin-bottom:16px">
  <div style="padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
    <div style="font-size:14px;font-weight:700">👥 Personal por sede</div>
    <div style="font-size:12px;color:var(--text3)"><?= count($personal) ?> usuarios activos</div>
  </div>
  <?php
  // Agrupar por sede
  $personal_por_sede = [];
  foreach ($personal as $p) {
    $key = $p['sede_id'] ?? 0;
    $personal_por_sede[$key][] = $p;
  }
  foreach ($sedes as $s):
    $sid = $s['id'];
    $plist = $personal_por_sede[$sid] ?? [];
    $color = $s['color']??'#1ea8a1';
  ?>
  <!-- Cabecera de sede -->
  <div style="padding:10px 18px;background:var(--bg3);border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px">
    <div style="width:8px;height:8px;border-radius:50%;background:<?= $color ?>"></div>
    <div style="font-size:13px;font-weight:700"><?= clean($s['nombre']) ?></div>
    <span style="font-size:11px;color:var(--text3)"><?= count($plist) ?> usuario<?= count($plist)!=1?'s':'' ?></span>
    <?php if($s['id']==$sede_actual): ?><span style="font-size:10px;background:<?= $color ?>;color:#fff;padding:1px 7px;border-radius:999px">ACTIVA</span><?php endif; ?>
  </div>
  <?php if(empty($plist)): ?>
  <div style="padding:16px 18px;font-size:13px;color:var(--text3);font-style:italic">Sin personal asignado a esta sede.</div>
  <?php endif; ?>
  <?php foreach($plist as $p):
    $rol_colors=['admin'=>'#ef4444','veterinario'=>'#10b981','recepcionista'=>'#3b82f6','asistente'=>'#f59e0b'];
    $rc=$rol_colors[$p['rol']]??'#94a3b8';
  ?>
  <div class="personal-row">
    <!-- Info usuario -->
    <div style="display:flex;align-items:center;gap:10px">
      <div style="width:36px;height:36px;border-radius:50%;background:<?= $rc ?>22;display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:700;color:<?= $rc ?>;flex-shrink:0">
        <?= strtoupper(substr($p['nombre'],0,1)) ?>
      </div>
      <div>
        <div style="font-size:13px;font-weight:600;color:var(--text)"><?= clean($p['nombre']) ?></div>
        <div style="font-size:11px;color:var(--text3)"><?= clean($p['email']??'') ?><?= $p['especialidad']?' · '.clean($p['especialidad']):'' ?></div>
      </div>
    </div>
    <!-- Rol badge -->
    <span style="padding:4px 12px;border-radius:999px;font-size:11px;font-weight:700;background:<?= $rc ?>22;color:<?= $rc ?>;white-space:nowrap">
      <?= ucfirst($p['rol']) ?>
    </span>
    <!-- Botón cambiar sede -->
    <form method="POST" style="display:flex;gap:6px;align-items:center">
      <input type="hidden" name="action" value="asignar_personal">
      <input type="hidden" name="uid" value="<?= $p['id'] ?>">
      <select name="nueva_sede_id" class="form-input" style="width:auto;min-width:140px;font-size:12px">
        <?php foreach($sedes as $sd): ?>
        <option value="<?= $sd['id'] ?>" <?= $sd['id']==$p['sede_id']?'selected':'' ?>><?= clean($sd['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-sm btn-primary">💾</button>
    </form>
  </div>
  <?php endforeach; ?>
  <?php endforeach; ?>
  <!-- Sin sede asignada -->
  <?php if(!empty($personal_por_sede[0]??[])): ?>
  <div style="padding:10px 18px;background:#fef2f2;border-bottom:1px solid var(--border)">
    <div style="font-size:12px;font-weight:700;color:var(--danger)">⚠️ Sin sede asignada</div>
  </div>
  <?php foreach($personal_por_sede[0] as $p): ?>
  <div class="personal-row" style="background:#fff5f5">
    <div style="display:flex;align-items:center;gap:10px">
      <div style="width:36px;height:36px;border-radius:50%;background:#fecaca;display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:700;color:#ef4444"><?= strtoupper(substr($p['nombre'],0,1)) ?></div>
      <div><div style="font-size:13px;font-weight:600"><?= clean($p['nombre']) ?></div><div style="font-size:11px;color:var(--danger)">⚠ Sin sede</div></div>
    </div>
    <span style="padding:4px 12px;border-radius:999px;font-size:11px;font-weight:700;background:#fee2e2;color:#ef4444"><?= ucfirst($p['rol']) ?></span>
    <form method="POST" style="display:flex;gap:6px;align-items:center">
      <input type="hidden" name="action" value="asignar_personal">
      <input type="hidden" name="uid" value="<?= $p['id'] ?>">
      <select name="nueva_sede_id" class="form-input" style="width:auto;min-width:140px;font-size:12px">
        <?php foreach($sedes as $sd): ?><option value="<?= $sd['id'] ?>"><?= clean($sd['nombre']) ?></option><?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-sm btn-primary">💾</button>
    </form>
  </div>
  <?php endforeach; endif; ?>
</div>

<?php elseif($tab==='stock'): ?>
<!-- ═══ TAB: STOCK ═══ -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px">
  <!-- Formulario transferencia -->
  <div class="card">
    <div style="font-size:14px;font-weight:700;margin-bottom:16px">🔄 Transferir stock entre sedes</div>
    <form method="POST">
      <input type="hidden" name="action" value="transferir_stock">
      <div class="form-group">
        <label class="form-label required">Producto</label>
        <select class="form-input" name="producto_id" required>
          <option value="">— Seleccionar —</option>
          <?php foreach($productos as $p): ?>
          <option value="<?= $p['id'] ?>"><?= clean($p['nombre']) ?> · Stock: <?= $p['stock'] ?> <?= clean($p['unidad']??'uds') ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label required">Desde sede</label>
          <select class="form-input" name="sede_origen" required>
            <?php foreach($sedes as $s): ?><option value="<?= $s['id'] ?>" <?= $s['id']==$sede_actual?'selected':'' ?>><?= clean($s['nombre']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label required">Hacia sede</label>
          <select class="form-input" name="sede_destino" required>
            <?php foreach($sedes as $s): ?><option value="<?= $s['id'] ?>"><?= clean($s['nombre']) ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label required">Cantidad</label>
          <input class="form-input" type="number" name="cantidad" min="1" required placeholder="0">
        </div>
        <div class="form-group">
          <label class="form-label">Nota</label>
          <input class="form-input" name="nota" placeholder="Motivo de transferencia">
        </div>
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%">🔄 Transferir</button>
    </form>
  </div>

  <!-- Inventario por sede -->
  <div class="card">
    <div style="font-size:14px;font-weight:700;margin-bottom:16px">📦 Stock global de productos</div>
    <div style="max-height:360px;overflow-y:auto">
      <table style="width:100%;font-size:12px;border-collapse:collapse">
        <thead><tr style="background:var(--bg3)">
          <th style="padding:8px 10px;text-align:left">Producto</th>
          <?php foreach($sedes as $s): ?><th style="padding:8px 6px;text-align:center;color:<?= $s['color']??'var(--primary)' ?>"><?= clean($s['nombre']) ?></th><?php endforeach; ?>
          <th style="padding:8px 10px;text-align:center">Total</th>
        </tr></thead>
        <tbody>
        <?php foreach(array_slice($productos,0,20) as $p):
          $por_sede=[];
          foreach($sedes as $s){
            $is=$db->prepare("SELECT stock FROM inventario_sedes WHERE producto_id=? AND sede_id=?"); $is->execute([$p['id'],$s['id']]); $row=$is->fetch();
            $por_sede[$s['id']]=$row['stock']??0;
          }
        ?>
        <tr style="border-bottom:1px solid var(--border)">
          <td style="padding:8px 10px;font-weight:600"><?= clean($p['nombre']) ?></td>
          <?php foreach($sedes as $s): $stock=$por_sede[$s['id']]??0; ?>
          <td style="padding:8px 6px;text-align:center;<?= $stock<=0?'color:var(--danger)':'color:var(--text)' ?>"><?= $stock ?></td>
          <?php endforeach; ?>
          <td style="padding:8px 10px;text-align:center;font-weight:700;color:var(--primary)"><?= $p['stock'] ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Historial de transferencias -->
<div class="card" style="padding:0">
  <div style="padding:14px 18px;border-bottom:1px solid var(--border);font-size:14px;font-weight:700">📋 Historial de transferencias</div>
  <?php if(empty($transferencias)): ?>
  <div style="padding:40px;text-align:center;color:var(--text3)"><div style="font-size:32px;margin-bottom:10px;opacity:.3">🔄</div><div>Sin transferencias registradas</div></div>
  <?php else: ?>
  <table class="vtable">
    <thead><tr><th>Fecha</th><th>Producto</th><th>Desde</th><th>Hacia</th><th style="text-align:center">Cantidad</th><th>Responsable</th><th>Nota</th></tr></thead>
    <tbody>
    <?php foreach($transferencias as $t): ?>
    <tr>
      <td style="font-size:11px;white-space:nowrap"><?= date('d/m/Y H:i',strtotime($t['created_at'])) ?></td>
      <td class="td-main"><?= clean($t['producto_nombre']) ?></td>
      <td style="color:var(--danger)"><?= clean($t['sede_origen_nombre']??'—') ?></td>
      <td style="color:var(--success)">→ <?= clean($t['sede_destino_nombre']??'—') ?></td>
      <td style="text-align:center;font-weight:700"><?= $t['cantidad'] ?></td>
      <td class="text-muted"><?= clean($t['usuario_nombre']??'—') ?></td>
      <td class="text-muted" style="font-size:11px"><?= clean($t['nota']??'—') ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php elseif($tab==='config'): ?>
<!-- ═══ TAB: CONFIGURACIÓN ═══ -->
<div class="grid g2" style="gap:16px">
<?php foreach($sedes as $s): $color=$s['color']??'#1ea8a1'; ?>
<div class="card" style="border-top:3px solid <?= $color ?>">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
    <div style="display:flex;align-items:center;gap:8px">
      <div style="width:10px;height:10px;border-radius:50%;background:<?= $color ?>"></div>
      <div style="font-size:15px;font-weight:700"><?= clean($s['nombre']) ?></div>
      <?php if($s['id']==$sede_actual): ?><span style="background:<?= $color ?>;color:#fff;font-size:10px;padding:1px 7px;border-radius:999px">ACTIVA</span><?php endif; ?>
    </div>
    <div class="flex gap-2">
      <?php if(($s['activo']??1)==1 && $s['id']!=$sede_actual): ?>
      <form method="POST" onsubmit="return confirm('¿Desactivar esta sede?')">
        <input type="hidden" name="action" value="toggle_sede"><input type="hidden" name="id" value="<?= $s['id'] ?>"><input type="hidden" name="activo" value="0">
        <button type="submit" class="btn btn-xs" style="color:var(--danger);border-color:var(--danger)">Desactivar</button>
      </form>
      <?php elseif(($s['activo']??1)==0): ?>
      <form method="POST">
        <input type="hidden" name="action" value="toggle_sede"><input type="hidden" name="id" value="<?= $s['id'] ?>"><input type="hidden" name="activo" value="1">
        <button type="submit" class="btn btn-xs btn-primary">Activar</button>
      </form>
      <?php endif; ?>
      <button onclick="editarSede(<?= htmlspecialchars(json_encode($s)) ?>)" class="btn btn-xs btn-ghost">✏️ Editar</button>
    </div>
  </div>
  <div style="font-size:12px;color:var(--text2);display:flex;flex-direction:column;gap:4px">
    <?php if($s['descripcion']): ?><div>📝 <?= clean($s['descripcion']) ?></div><?php endif; ?>
    <?php if($s['direccion']): ?><div>📍 <?= clean($s['direccion']) ?></div><?php endif; ?>
    <?php if($s['telefono']): ?><div>📞 <?= clean($s['telefono']) ?></div><?php endif; ?>
    <?php if($s['email']): ?><div>📧 <?= clean($s['email']) ?></div><?php endif; ?>
    <?php if($s['meta_mensual']??0): ?><div>🎯 Meta mensual: S/ <?= number_format($s['meta_mensual'],0) ?></div><?php endif; ?>
  </div>
  <div style="margin-top:10px;padding-top:10px;border-top:1px solid var(--border);display:flex;gap:8px;font-size:11px">
    <span style="color:var(--text3)">👥 <?= $stats[$s['id']]['usuarios']??0 ?> usuarios</span>
    <span style="color:var(--text3)">🐾 <?= $stats[$s['id']]['pacientes']??0 ?> pacientes</span>
    <span style="color:<?= ($s['activo']??1)?'var(--success)':'var(--danger)' ?>">● <?= ($s['activo']??1)?'Activa':'Inactiva' ?></span>
  </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

</div><!-- .page -->

<!-- Modal Nueva/Editar Sede -->
<div id="modal-sede" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:2000;align-items:center;justify-content:center" onclick="if(event.target===this)this.style.display='none'">
  <div style="background:var(--bg2);border-radius:18px;padding:28px;width:520px;max-width:96vw;max-height:92vh;overflow-y:auto;box-shadow:0 24px 64px rgba(0,0,0,.2)">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px">
      <div style="font-size:17px;font-weight:800" id="modal-sede-title">🏢 Nueva Sede</div>
      <button onclick="document.getElementById('modal-sede').style.display='none'" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--text3);line-height:1">✕</button>
    </div>
    <form method="POST" id="form-sede">
      <input type="hidden" name="action" value="save_sede">
      <input type="hidden" name="id" id="sede-id" value="">
      <div class="form-group"><label class="form-label required">Nombre de la sede</label><input class="form-input" name="nombre" id="sede-nombre" required placeholder="Ej: Sede San Isidro"></div>
      <div class="form-group"><label class="form-label">Descripción</label><textarea class="form-input" name="descripcion" id="sede-desc" rows="2" placeholder="Descripción breve de la sede"></textarea></div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Dirección</label><input class="form-input" name="direccion" id="sede-dir" placeholder="Av. Principal 123"></div>
        <div class="form-group"><label class="form-label">Teléfono</label><input class="form-input" name="telefono" id="sede-tel" placeholder="01-444-5678"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Email</label><input class="form-input" type="email" name="email" id="sede-email"></div>
        <div class="form-group"><label class="form-label">Meta mensual S/.</label><input class="form-input" type="number" step="0.01" name="meta_mensual" id="sede-meta" value="0" placeholder="0"></div>
      </div>
      <div class="form-group">
        <label class="form-label">Color identificador</label>
        <div style="display:flex;gap:10px;align-items:center">
          <input class="form-input" type="color" name="color" id="sede-color" value="#1ea8a1" style="height:42px;width:80px;padding:4px;cursor:pointer">
          <div style="font-size:12px;color:var(--text3)">Se usa para identificar la sede en gráficas y tarjetas</div>
        </div>
      </div>
      <div class="flex gap-2 mt-3">
        <button type="submit" class="btn btn-primary btn-lg" style="flex:1">💾 Guardar sede</button>
        <button type="button" onclick="document.getElementById('modal-sede').style.display='none'" class="btn btn-ghost">Cancelar</button>
      </div>
    </form>
  </div>
</div>

<script>
function abrirModalSede() {
  document.getElementById('modal-sede-title').textContent = '🏢 Nueva Sede';
  document.getElementById('sede-id').value    = '';
  document.getElementById('sede-nombre').value = '';
  document.getElementById('sede-desc').value   = '';
  document.getElementById('sede-dir').value    = '';
  document.getElementById('sede-tel').value    = '';
  document.getElementById('sede-email').value  = '';
  document.getElementById('sede-meta').value   = '0';
  document.getElementById('sede-color').value  = '#1ea8a1';
  document.getElementById('modal-sede').style.display = 'flex';
}
function editarSede(s) {
  document.getElementById('modal-sede-title').textContent = '✏️ Editar: ' + s.nombre;
  document.getElementById('sede-id').value    = s.id;
  document.getElementById('sede-nombre').value = s.nombre||'';
  document.getElementById('sede-desc').value   = s.descripcion||'';
  document.getElementById('sede-dir').value    = s.direccion||'';
  document.getElementById('sede-tel').value    = s.telefono||'';
  document.getElementById('sede-email').value  = s.email||'';
  document.getElementById('sede-meta').value   = s.meta_mensual||0;
  document.getElementById('sede-color').value  = s.color||'#1ea8a1';
  document.getElementById('modal-sede').style.display = 'flex';
}
</script>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
