<?php
$page = 'ganado'; $pageTitle = 'Ganado Vacuno';
require_once __DIR__ . '/../includes/header.php';
$db = getDB();

// Crear tablas una por una con conexión nueva
try {
    $pdo2 = new PDO(
        'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_SILENT, PDO::ATTR_EMULATE_PREPARES=>true]
    );
    $pdo2->exec("CREATE TABLE IF NOT EXISTS gv_fundos (id INT AUTO_INCREMENT PRIMARY KEY, codigo VARCHAR(30), nombre VARCHAR(200) NOT NULL, departamento VARCHAR(80), provincia VARCHAR(80), distrito VARCHAR(80), direccion TEXT, hectareas DECIMAL(10,2), responsable VARCHAR(150), telefono VARCHAR(30), notas TEXT, activo TINYINT(1) DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo2->exec("CREATE TABLE IF NOT EXISTS gv_lotes (id INT AUTO_INCREMENT PRIMARY KEY, fundo_id INT NOT NULL, potrero_id INT, nombre VARCHAR(150) NOT NULL, codigo VARCHAR(30), proposito ENUM('leche','carne','reproductor','mixto','cria') DEFAULT 'mixto', notas TEXT, activo TINYINT(1) DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo2->exec("CREATE TABLE IF NOT EXISTS gv_toros (id INT AUTO_INCREMENT PRIMARY KEY, codigo VARCHAR(50), nombre VARCHAR(150) NOT NULL, raza VARCHAR(100), fecha_nacimiento DATE, peso DECIMAL(7,2), fertilidad ENUM('alta','media','baja','sin_evaluar') DEFAULT 'sin_evaluar', calidad_semen VARCHAR(80), fundo_id INT, foto VARCHAR(500), historial_genetico TEXT, activo TINYINT(1) DEFAULT 1, notas TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo2->exec("CREATE TABLE IF NOT EXISTS gv_animales (id INT AUTO_INCREMENT PRIMARY KEY, codigo_interno VARCHAR(50), numero_arete VARCHAR(50), rfid VARCHAR(100), nombre VARCHAR(100), foto VARCHAR(500), sexo ENUM('macho','hembra') NOT NULL DEFAULT 'hembra', raza VARCHAR(100), color VARCHAR(80), fecha_nacimiento DATE, tipo ENUM('leche','carne','reproductor','mixto','cria') DEFAULT 'mixto', estado ENUM('activo','vendido','muerto','dado_de_baja','cuarentena') DEFAULT 'activo', estado_reproductivo ENUM('vacia','prenada','lactante','seca','toro','novillo','ternero') DEFAULT 'vacia', fundo_id INT, potrero_id INT, lote_id INT, peso_inicial DECIMAL(7,2), peso_actual DECIMAL(7,2), madre_id INT NULL, padre_id INT NULL, origen ENUM('nacimiento','compra') DEFAULT 'nacimiento', fecha_ingreso DATE, produccion_estimada DECIMAL(6,2), notas TEXT, activo TINYINT(1) DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo2->exec("CREATE TABLE IF NOT EXISTS gv_celos (id INT AUTO_INCREMENT PRIMARY KEY, animal_id INT NOT NULL, fecha DATE NOT NULL, hora TIME, metodo_deteccion VARCHAR(50) DEFAULT 'visual', inflamacion TINYINT(1) DEFAULT 0, comportamiento TEXT, temperatura DECIMAL(4,1), observaciones TEXT, veterinario_id INT, programar_inseminacion TINYINT(1) DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo2->exec("CREATE TABLE IF NOT EXISTS gv_inseminaciones (id INT AUTO_INCREMENT PRIMARY KEY, animal_id INT NOT NULL, celo_id INT NULL, fecha DATE NOT NULL, hora TIME, tecnico_id INT, toro_id INT, codigo_pajilla VARCHAR(80), raza_genetica VARCHAR(100), procedencia_semen VARCHAR(20) DEFAULT 'nacional', observaciones TEXT, fecha_diagnostico_prenez DATE, resultado VARCHAR(20) DEFAULT 'pendiente', notas TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo2->exec("CREATE TABLE IF NOT EXISTS gv_banco_semen (id INT AUTO_INCREMENT PRIMARY KEY, codigo_pajilla VARCHAR(80) NOT NULL, toro_id INT, toro_nombre VARCHAR(150), raza VARCHAR(100), procedencia VARCHAR(20) DEFAULT 'nacional', fecha_ingreso DATE, cantidad INT DEFAULT 0, cantidad_usada INT DEFAULT 0, tanque VARCHAR(50), temperatura DECIMAL(5,2), ubicacion_fisica VARCHAR(100), stock_minimo INT DEFAULT 5, fecha_vencimiento DATE, costo_unitario DECIMAL(10,2), observaciones TEXT, activo TINYINT(1) DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo2->exec("CREATE TABLE IF NOT EXISTS gv_prenez (id INT AUTO_INCREMENT PRIMARY KEY, animal_id INT NOT NULL, inseminacion_id INT NULL, fecha_diagnostico DATE NOT NULL, resultado VARCHAR(20) NOT NULL, fecha_inseminacion DATE, veterinario_id INT, metodo VARCHAR(30) DEFAULT 'ecografia', fecha_probable_parto DATE, observaciones TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo2->exec("CREATE TABLE IF NOT EXISTS gv_partos (id INT AUTO_INCREMENT PRIMARY KEY, madre_id INT NOT NULL, prenez_id INT NULL, fecha_parto DATE NOT NULL, hora_parto TIME, tipo VARCHAR(20) DEFAULT 'natural', complicaciones TEXT, cria_sexo VARCHAR(10), cria_peso DECIMAL(5,2), cria_estado VARCHAR(10) DEFAULT 'vivo', cria_arete VARCHAR(50), cria_registrada TINYINT(1) DEFAULT 0, veterinario_id INT, observaciones TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo2->exec("CREATE TABLE IF NOT EXISTS gv_leche (id INT AUTO_INCREMENT PRIMARY KEY, animal_id INT NOT NULL, lote_id INT NULL, fecha DATE NOT NULL, turno VARCHAR(10) DEFAULT 'manana', litros DECIMAL(6,2) NOT NULL, calidad VARCHAR(10) DEFAULT 'A', observaciones TEXT, registrado_por INT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo2->exec("CREATE TABLE IF NOT EXISTS gv_pesos (id INT AUTO_INCREMENT PRIMARY KEY, animal_id INT NOT NULL, fecha DATE NOT NULL, peso DECIMAL(7,2) NOT NULL, peso_anterior DECIMAL(7,2), ganancia_diaria DECIMAL(5,2), condicion_corporal DECIMAL(3,1), registrado_por INT, observaciones TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo2->exec("CREATE TABLE IF NOT EXISTS gv_razas (id INT AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(100) NOT NULL, especie VARCHAR(50) DEFAULT 'bovino', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo2 = null;
} catch(Exception $e) { /* silenciar */ }

// Leer datos con protección
$fundos=$lotes=$animales=$toros=$vets=$razas=[];
try{$fundos=$db->query("SELECT * FROM gv_fundos WHERE activo=1 ORDER BY nombre")->fetchAll();}catch(Exception $e){}
try{$lotes=$db->query("SELECT l.*,f.nombre as fundo FROM gv_lotes l JOIN gv_fundos f ON f.id=l.fundo_id WHERE l.activo=1 ORDER BY l.nombre")->fetchAll();}catch(Exception $e){}
try{$animales=$db->query("SELECT a.*,f.nombre as fundo FROM gv_animales a LEFT JOIN gv_fundos f ON f.id=a.fundo_id WHERE a.activo=1 ORDER BY a.numero_arete")->fetchAll();}catch(Exception $e){}
try{$toros=$db->query("SELECT * FROM gv_toros WHERE activo=1 ORDER BY nombre")->fetchAll();}catch(Exception $e){}
try{$vets=$db->query("SELECT id,nombre FROM usuarios WHERE rol IN ('veterinario','admin') AND activo=1")->fetchAll();}catch(Exception $e){}
try{$razas=$db->query("SELECT * FROM gv_razas ORDER BY nombre")->fetchAll();}catch(Exception $e){}

$sub=$_GET['sub']??'dashboard';
$action=$_GET['action']??'list';
$msg='';

// POST
if($_SERVER['REQUEST_METHOD']==='POST'){
  $pa=$_POST['action']??'';
  if($pa==='save_animal'){
    $id=(int)($_POST['id']??0);
    $f=['codigo_interno','numero_arete','rfid','nombre','sexo','raza','color','fecha_nacimiento','tipo','estado','estado_reproductivo','fundo_id','lote_id','peso_inicial','peso_actual','origen','fecha_ingreso','produccion_estimada','notas'];
    $d=[];foreach($f as $k)$d[$k]=trim($_POST[$k]??'')?:null;
    try{
      if($id){$sets=implode(',',array_map(fn($k)=>"$k=:$k",$f));$db->prepare("UPDATE gv_animales SET $sets WHERE id=:id")->execute(array_merge($d,['id'=>$id]));}
      else{$cols=implode(',',$f);$pls=implode(',',array_map(fn($k)=>":$k",$f));$db->prepare("INSERT INTO gv_animales ($cols) VALUES ($pls)")->execute($d);}
      $msg='ok';
    }catch(Exception $e){$msg='err:'.$e->getMessage();}
    $action='list';
    try{$animales=$db->query("SELECT a.*,f.nombre as fundo FROM gv_animales a LEFT JOIN gv_fundos f ON f.id=a.fundo_id WHERE a.activo=1 ORDER BY a.numero_arete")->fetchAll();}catch(Exception $e){}
  }
  if($pa==='save_fundo'){
    $id=(int)($_POST['id']??0);$f=['codigo','nombre','departamento','provincia','distrito','direccion','hectareas','responsable','telefono','notas'];
    $d=[];foreach($f as $k)$d[$k]=trim($_POST[$k]??'')?:null;
    try{
      if($id){$sets=implode(',',array_map(fn($k)=>"$k=:$k",$f));$db->prepare("UPDATE gv_fundos SET $sets WHERE id=:id")->execute(array_merge($d,['id'=>$id]));}
      else{$cols=implode(',',$f);$pls=implode(',',array_map(fn($k)=>":$k",$f));$db->prepare("INSERT INTO gv_fundos ($cols) VALUES ($pls)")->execute($d);}
      $msg='ok';
    }catch(Exception $e){$msg='err:'.$e->getMessage();}
    $sub='fundos';$action='list';
    try{$fundos=$db->query("SELECT * FROM gv_fundos WHERE activo=1 ORDER BY nombre")->fetchAll();}catch(Exception $e){}
  }
  if($pa==='save_celo'){
    $d=['animal_id'=>$_POST['animal_id']??null,'fecha'=>$_POST['fecha']??date('Y-m-d'),'hora'=>$_POST['hora']??null,'metodo_deteccion'=>$_POST['metodo_deteccion']??'visual','comportamiento'=>$_POST['comportamiento']??null,'temperatura'=>$_POST['temperatura']??null,'observaciones'=>$_POST['observaciones']??null,'veterinario_id'=>$_POST['veterinario_id']??null,'inflamacion'=>isset($_POST['inflamacion'])?1:0,'programar_inseminacion'=>isset($_POST['programar_inseminacion'])?1:0];
    try{$db->prepare("INSERT INTO gv_celos (animal_id,fecha,hora,metodo_deteccion,comportamiento,temperatura,observaciones,veterinario_id,inflamacion,programar_inseminacion) VALUES (:animal_id,:fecha,:hora,:metodo_deteccion,:comportamiento,:temperatura,:observaciones,:veterinario_id,:inflamacion,:programar_inseminacion)")->execute($d);$msg='ok';}catch(Exception $e){$msg='err';}
    $sub='reproduccion';$action='list';
  }
  if($pa==='save_insem'){
    $d=['animal_id'=>$_POST['animal_id']??null,'fecha'=>$_POST['fecha']??date('Y-m-d'),'hora'=>$_POST['hora']??null,'tecnico_id'=>$_POST['tecnico_id']??null,'toro_id'=>$_POST['toro_id']??null,'codigo_pajilla'=>$_POST['codigo_pajilla']??null,'raza_genetica'=>$_POST['raza_genetica']??null,'procedencia_semen'=>$_POST['procedencia_semen']??'nacional','observaciones'=>$_POST['observaciones']??null,'fecha_diagnostico_prenez'=>$_POST['fecha_diagnostico_prenez']??null,'resultado'=>'pendiente'];
    try{$db->prepare("INSERT INTO gv_inseminaciones (animal_id,fecha,hora,tecnico_id,toro_id,codigo_pajilla,raza_genetica,procedencia_semen,observaciones,fecha_diagnostico_prenez,resultado) VALUES (:animal_id,:fecha,:hora,:tecnico_id,:toro_id,:codigo_pajilla,:raza_genetica,:procedencia_semen,:observaciones,:fecha_diagnostico_prenez,:resultado)")->execute($d);$msg='ok';}catch(Exception $e){$msg='err';}
    $sub='reproduccion';$action='list';
  }
  if($pa==='save_prenez'){
    $res=$_POST['resultado']??'vacia';$aid=(int)($_POST['animal_id']??0);$fi=$_POST['fecha_inseminacion']??'';
    $fpp=$res==='prenada'&&$fi?date('Y-m-d',strtotime($fi.'+283 days')):null;
    try{$db->prepare("INSERT INTO gv_prenez (animal_id,inseminacion_id,fecha_diagnostico,resultado,fecha_inseminacion,veterinario_id,metodo,fecha_probable_parto,observaciones) VALUES (?,?,?,?,?,?,?,?,?)")->execute([$aid,$_POST['inseminacion_id']??null,$_POST['fecha_diagnostico']??date('Y-m-d'),$res,$fi,$_POST['veterinario_id']??null,$_POST['metodo']??'ecografia',$fpp,$_POST['observaciones']??'']);
    if($res==='prenada')$db->prepare("UPDATE gv_animales SET estado_reproductivo='prenada' WHERE id=?")->execute([$aid]);
    $msg='ok';}catch(Exception $e){$msg='err';}
    $sub='reproduccion';$action='list';
  }
  if($pa==='save_parto'){
    $mid=(int)($_POST['madre_id']??0);
    try{$db->prepare("INSERT INTO gv_partos (madre_id,fecha_parto,hora_parto,tipo,complicaciones,cria_sexo,cria_peso,cria_estado,cria_arete,veterinario_id,observaciones) VALUES (?,?,?,?,?,?,?,?,?,?,?)")->execute([$mid,$_POST['fecha_parto']??date('Y-m-d'),$_POST['hora_parto']??null,$_POST['tipo']??'natural',$_POST['complicaciones']??null,$_POST['cria_sexo']??null,$_POST['cria_peso']??null,$_POST['cria_estado']??'vivo',$_POST['cria_arete']??null,$_POST['veterinario_id']??null,$_POST['observaciones']??'']);
    if($mid)$db->prepare("UPDATE gv_animales SET estado_reproductivo='lactante' WHERE id=?")->execute([$mid]);
    $msg='ok';}catch(Exception $e){$msg='err';}
    $sub='reproduccion';$action='list';
  }
  if($pa==='save_leche'){
    try{$db->prepare("INSERT INTO gv_leche (animal_id,lote_id,fecha,turno,litros,calidad,observaciones,registrado_por) VALUES (?,?,?,?,?,?,?,?)")->execute([$_POST['animal_id']??null,$_POST['lote_id']??null,$_POST['fecha']??date('Y-m-d'),$_POST['turno']??'manana',$_POST['litros']??0,$_POST['calidad']??'A',$_POST['observaciones']??'',$user['id']]);$msg='ok';}catch(Exception $e){$msg='err';}
    $sub='leche';$action='list';
  }
  if($pa==='save_peso'){
    $aid=(int)($_POST['animal_id']??0);$pnew=(float)($_POST['peso']??0);
    $pant=null;$gan=null;
    try{$row=$db->prepare("SELECT peso_actual FROM gv_animales WHERE id=?");$row->execute([$aid]);$row=$row->fetch();$pant=$row['peso_actual']??null;
    if($pant){$ult=$db->prepare("SELECT fecha FROM gv_pesos WHERE animal_id=? ORDER BY fecha DESC LIMIT 1");$ult->execute([$aid]);$ult=$ult->fetch();if($ult){$dias=max(1,(int)round((strtotime($_POST['fecha']??'now')-strtotime($ult['fecha']))/86400));$gan=round(($pnew-$pant)/$dias,3);}}
    $db->prepare("INSERT INTO gv_pesos (animal_id,fecha,peso,peso_anterior,ganancia_diaria,condicion_corporal,observaciones,registrado_por) VALUES (?,?,?,?,?,?,?,?)")->execute([$aid,$_POST['fecha']??date('Y-m-d'),$pnew,$pant,$gan,$_POST['condicion_corporal']??null,$_POST['observaciones']??'',$user['id']]);
    $db->prepare("UPDATE gv_animales SET peso_actual=? WHERE id=?")->execute([$pnew,$aid]);
    $msg='ok';}catch(Exception $e){$msg='err';}
    $sub='peso';$action='list';
  }
  if($pa==='save_semen'){
    try{$db->prepare("INSERT INTO gv_banco_semen (codigo_pajilla,toro_id,toro_nombre,raza,procedencia,fecha_ingreso,cantidad,tanque,temperatura,ubicacion_fisica,stock_minimo,fecha_vencimiento,costo_unitario,observaciones) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([$_POST['codigo_pajilla']??'',$_POST['toro_id']??null,$_POST['toro_nombre']??null,$_POST['raza']??null,$_POST['procedencia']??'nacional',$_POST['fecha_ingreso']??date('Y-m-d'),$_POST['cantidad']??0,$_POST['tanque']??null,$_POST['temperatura']??null,$_POST['ubicacion_fisica']??null,$_POST['stock_minimo']??5,$_POST['fecha_vencimiento']??null,$_POST['costo_unitario']??null,$_POST['observaciones']??'']);$msg='ok';}catch(Exception $e){$msg='err';}
    $sub='banco_semen';$action='list';
  }
  // ── AJAX: Raza ──
  if($pa==='get_razas'){
    header('Content-Type: application/json');
    $r=[];try{$r=$db->query("SELECT * FROM gv_razas ORDER BY nombre")->fetchAll();}catch(Exception $e){}
    echo json_encode(['ok'=>true,'data'=>$r]);exit;
  }
  if($pa==='save_raza'){
    header('Content-Type: application/json');
    $id=(int)($_POST['id']??0);$n=trim($_POST['nombre']??'');$esp=trim($_POST['especie']??'bovino');
    if(!$n){echo json_encode(['ok'=>false,'error'=>'Nombre requerido']);exit;}
    try{
      if($id)$db->prepare("UPDATE gv_razas SET nombre=?,especie=? WHERE id=?")->execute([$n,$esp,$id]);
      else{$db->prepare("INSERT INTO gv_razas (nombre,especie) VALUES (?,?)")->execute([$n,$esp]);$id=(int)$db->lastInsertId();}
      echo json_encode(['ok'=>true,'id'=>$id,'nombre'=>$n,'especie'=>$esp]);
    }catch(Exception $e){echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);}
    exit;
  }
  if($pa==='delete_raza'){
    header('Content-Type: application/json');
    try{$db->prepare("DELETE FROM gv_razas WHERE id=?")->execute([(int)($_POST['id']??0)]);echo json_encode(['ok'=>true]);}
    catch(Exception $e){echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);}
    exit;
  }
  // ── AJAX: Fundo rápido ──
  if($pa==='save_fundo_quick'){
    header('Content-Type: application/json');
    $id=(int)($_POST['id']??0);$n=trim($_POST['nombre']??'');
    if(!$n){echo json_encode(['ok'=>false,'error'=>'Nombre requerido']);exit;}
    try{
      if($id)$db->prepare("UPDATE gv_fundos SET nombre=?,departamento=?,provincia=? WHERE id=?")->execute([$n,trim($_POST['departamento']??''),trim($_POST['provincia']??''),$id]);
      else{$db->prepare("INSERT INTO gv_fundos (nombre,departamento,provincia,activo) VALUES (?,?,?,1)")->execute([$n,trim($_POST['departamento']??''),trim($_POST['provincia']??'')]);$id=(int)$db->lastInsertId();}
      echo json_encode(['ok'=>true,'id'=>$id,'nombre'=>$n]);
    }catch(Exception $e){echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);}
    exit;
  }
  if($pa==='delete_fundo_quick'){
    header('Content-Type: application/json');
    $id=(int)($_POST['id']??0);
    $en_uso=(int)$db->query("SELECT COUNT(*) FROM gv_animales WHERE fundo_id=$id AND activo=1")->fetchColumn();
    if($en_uso>0){echo json_encode(['ok'=>false,'error'=>"$en_uso animales asignados a este fundo."]);exit;}
    try{$db->prepare("UPDATE gv_fundos SET activo=0 WHERE id=?")->execute([$id]);echo json_encode(['ok'=>true]);}
    catch(Exception $e){echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);}
    exit;
  }
  // ── AJAX: Lote rápido ──
  if($pa==='save_lote_quick'){
    header('Content-Type: application/json');
    $id=(int)($_POST['id']??0);$n=trim($_POST['nombre']??'');$fid=(int)($_POST['fundo_id']??0);
    if(!$n){echo json_encode(['ok'=>false,'error'=>'Nombre requerido']);exit;}
    try{
      if($id)$db->prepare("UPDATE gv_lotes SET nombre=?,fundo_id=? WHERE id=?")->execute([$n,$fid?:null,$id]);
      else{$db->prepare("INSERT INTO gv_lotes (nombre,fundo_id,activo) VALUES (?,?,1)")->execute([$n,$fid?:null]);$id=(int)$db->lastInsertId();}
      echo json_encode(['ok'=>true,'id'=>$id,'nombre'=>$n]);
    }catch(Exception $e){echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);}
    exit;
  }
  if($pa==='delete_lote_quick'){
    header('Content-Type: application/json');
    try{$db->prepare("UPDATE gv_lotes SET activo=0 WHERE id=?")->execute([(int)($_POST['id']??0)]);echo json_encode(['ok'=>true]);}
    catch(Exception $e){echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);}
    exit;
  }
  if($pa==='save_tratamiento'){
    $aid=(int)($_POST['animal_id']??0);
    try{$db->prepare("INSERT INTO gv_tratamientos (animal_id,fecha,enfermedad,diagnostico,tratamiento,medicamento,dosis,duracion_dias,veterinario_id,costo,observaciones,estado) VALUES (?,?,?,?,?,?,?,?,?,?,?,'activo')")->execute([$aid,$_POST['fecha']??date('Y-m-d'),$_POST['enfermedad']??null,$_POST['diagnostico']??null,$_POST['tratamiento']??null,$_POST['medicamento']??null,$_POST['dosis']??null,$_POST['duracion_dias']??null,$_POST['veterinario_id']??null,$_POST['costo']??null,$_POST['observaciones']??'']);$msg='ok';}catch(Exception $e){$msg='err';}
    $sub='sanidad';$action='list';
  }
}

// KPIs
$kpi_total=$kpi_prena=$kpi_leche_hoy=$kpi_partos_prox=$kpi_insem_pend=$kpi_enfermos=0;
$kpi_total=count($animales);
$kpi_prena=count(array_filter($animales,fn($a)=>($a['estado_reproductivo']??'')==='prenada'));
try{$kpi_leche_hoy=(float)$db->query("SELECT COALESCE(SUM(litros),0) FROM gv_leche WHERE fecha=CURDATE()")->fetchColumn();}catch(Exception $e){}
try{$kpi_partos_prox=(int)$db->query("SELECT COUNT(*) FROM gv_prenez WHERE resultado='prenada' AND fecha_probable_parto BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY)")->fetchColumn();}catch(Exception $e){}
try{$kpi_insem_pend=(int)$db->query("SELECT COUNT(*) FROM gv_inseminaciones WHERE resultado='pendiente'")->fetchColumn();}catch(Exception $e){}
try{$kpi_enfermos=(int)$db->query("SELECT COUNT(*) FROM gv_tratamientos WHERE estado='activo'")->fetchColumn();}catch(Exception $e){}

$leche_semana=[];
for($i=6;$i>=0;$i--){$dia=date('Y-m-d',strtotime("-$i days"));$lt=0;try{$lt=(float)$db->query("SELECT COALESCE(SUM(litros),0) FROM gv_leche WHERE fecha='$dia'")->fetchColumn();}catch(Exception $e){}$leche_semana[$dia]=$lt;}
$max_leche=max(array_values($leche_semana)?:[1]);

$erc=['vacia'=>['#f59e0b','Vacía'],'prenada'=>['#10b981','Preñada'],'lactante'=>['#3b82f6','Lactante'],'seca'=>['#94a3b8','Seca'],'toro'=>['#8b5cf6','Toro'],'novillo'=>['#6b7280','Novillo'],'ternero'=>['#f97316','Ternero']];
?>

<style>
.gv-subnav{display:flex;gap:4px;margin-bottom:20px;border-bottom:2px solid var(--border);overflow-x:auto;padding-bottom:0}
.gv-tab{padding:9px 14px;font-size:12px;font-weight:600;color:var(--text3);border:none;background:none;cursor:pointer;white-space:nowrap;border-bottom:2px solid transparent;transition:all .15s;font-family:var(--font);margin-bottom:-2px;text-decoration:none;display:inline-flex;align-items:center;gap:5px}
.gv-tab:hover{color:var(--text2)}
.gv-tab.active{color:#0f766e;border-bottom-color:#0f766e;font-weight:700}
.gv-kpi{display:grid;grid-template-columns:repeat(6,1fr);gap:12px;margin-bottom:20px}
@media(max-width:900px){.gv-kpi{grid-template-columns:repeat(3,1fr)}}
.gv-kpi-card{background:var(--bg2);border:1px solid var(--border);border-radius:12px;padding:16px;text-align:center}
.gv-kpi-icon{font-size:26px;margin-bottom:8px}
.gv-kpi-val{font-size:22px;font-weight:800;color:var(--text);line-height:1}
.gv-kpi-lbl{font-size:11px;color:var(--text3);margin-top:4px}
.gv-repro{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:700;color:#fff}
.gv-form-sec{background:var(--bg3);border:1px solid var(--border);border-radius:10px;padding:14px;margin-bottom:14px}
.gv-form-sec-title{font-size:12px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px}
</style>

<div class="page">

<?php if($msg==='ok'): ?><div class="alert alert-success mb-2"><span class="alert-icon">✅</span>Guardado correctamente.</div><?php endif; ?>
<?php if(substr($msg??'',0,3)==='err'): ?><div class="alert alert-danger mb-2"><span class="alert-icon">❌</span>Error: <?= clean(substr($msg,4)) ?></div><?php endif; ?>

<!-- SUB-NAV -->
<div class="gv-subnav">
<?php foreach(['dashboard'=>'🐄 Dashboard','animales'=>'🏷️ Animales','reproduccion'=>'🧬 Reproducción','leche'=>'🥛 Leche','peso'=>'⚖️ Peso','sanidad'=>'💉 Sanidad','fundos'=>'🌿 Fundos','banco_semen'=>'🧪 Banco Semen'] as $tk=>$tv): ?>
<a href="?p=ganado&sub=<?= $tk ?>" class="gv-tab <?= $sub===$tk?'active':'' ?>"><?= $tv ?></a>
<?php endforeach; ?>
</div>

<?php if($sub==='dashboard'): ?>
<!-- DASHBOARD -->
<div class="gv-kpi">
  <div class="gv-kpi-card"><div class="gv-kpi-icon">🐄</div><div class="gv-kpi-val"><?= $kpi_total ?></div><div class="gv-kpi-lbl">Total animales</div></div>
  <div class="gv-kpi-card"><div class="gv-kpi-icon">🤰</div><div class="gv-kpi-val" style="color:#10b981"><?= $kpi_prena ?></div><div class="gv-kpi-lbl">Preñadas</div></div>
  <div class="gv-kpi-card"><div class="gv-kpi-icon">🥛</div><div class="gv-kpi-val"><?= number_format($kpi_leche_hoy,1) ?>L</div><div class="gv-kpi-lbl">Leche hoy</div></div>
  <div class="gv-kpi-card"><div class="gv-kpi-icon">🐃</div><div class="gv-kpi-val" style="color:#f59e0b"><?= $kpi_partos_prox ?></div><div class="gv-kpi-lbl">Partos 30d</div></div>
  <div class="gv-kpi-card"><div class="gv-kpi-icon">🔬</div><div class="gv-kpi-val"><?= $kpi_insem_pend ?></div><div class="gv-kpi-lbl">Diag. pend.</div></div>
  <div class="gv-kpi-card"><div class="gv-kpi-icon">🏥</div><div class="gv-kpi-val" style="color:<?= $kpi_enfermos>0?'#ef4444':'var(--text)' ?>"><?= $kpi_enfermos ?></div><div class="gv-kpi-lbl">En tratamiento</div></div>
</div>
<div class="grid g2 mb-3" style="gap:16px">
  <div class="card">
    <div class="sec-title mb-3">🥛 Producción leche — 7 días</div>
    <div style="display:flex;align-items:flex-end;gap:5px;height:80px">
    <?php foreach($leche_semana as $dia=>$lit): $pct=$max_leche>0?max(4,round($lit/$max_leche*76)):4; ?>
    <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:2px">
      <div style="font-size:9px;color:var(--text3)"><?= number_format($lit,0) ?>L</div>
      <div style="height:<?= $pct ?>px;width:100%;background:<?= $dia===date('Y-m-d')?'var(--primary)':'rgba(30,168,161,.3)' ?>;border-radius:4px 4px 0 0"></div>
      <div style="font-size:9px;color:var(--text3)"><?= date('d/m',strtotime($dia)) ?></div>
    </div>
    <?php endforeach; ?>
    </div>
  </div>
  <div class="card">
    <div class="sec-title mb-3">🧬 Estado reproductivo</div>
    <div class="grid g4" style="gap:8px">
    <?php $dist=array_count_values(array_column($animales,'estado_reproductivo'));
    foreach($erc as $er=>[$color,$lbl]): $n=$dist[$er]??0; ?>
    <div style="text-align:center;padding:10px;background:var(--bg3);border-radius:8px">
      <div style="font-size:18px;font-weight:800;color:<?= $color ?>"><?= $n ?></div>
      <div style="font-size:10px;color:var(--text3)"><?= $lbl ?></div>
    </div>
    <?php endforeach; ?>
    </div>
  </div>
</div>

<?php elseif($sub==='animales'): ?>
<?php if($action==='nueva'||$action==='editar'):
  $ed=null;if($action==='editar'&&isset($_GET['id'])){$st=$db->prepare("SELECT * FROM gv_animales WHERE id=?");$st->execute([(int)$_GET['id']]);$ed=$st->fetch();}?>
<div class="card" style="max-width:780px">
  <div class="sec-header"><div class="sec-title"><?= $action==='editar'?'Editar':'Nuevo'?> Animal</div><a href="?p=ganado&sub=animales" class="btn btn-ghost btn-sm">← Volver</a></div>
  <form method="POST" enctype="multipart/form-data">
    <input type="hidden" name="action" value="save_animal"><input type="hidden" name="id" value="<?= $ed['id']??'' ?>">
    <div class="gv-form-sec"><div class="gv-form-sec-title">🏷️ Identificación</div>
      <div class="form-row-3">
        <div class="form-group"><label class="form-label">Código interno</label><input class="form-input" name="codigo_interno" value="<?= clean($ed['codigo_interno']??'') ?>"></div>
        <div class="form-group"><label class="form-label required">N° Arete</label><input class="form-input" name="numero_arete" value="<?= clean($ed['numero_arete']??'') ?>" required></div>
        <div class="form-group"><label class="form-label">RFID</label><input class="form-input" name="rfid" value="<?= clean($ed['rfid']??'') ?>"></div>
      </div>
      <div class="form-row"><div class="form-group"><label class="form-label">Nombre</label><input class="form-input" name="nombre" value="<?= clean($ed['nombre']??'') ?>"></div>
      <div class="form-group"><label class="form-label">Foto</label><input class="form-input" type="file" name="foto" accept="image/*"></div></div>
    </div>
    <div class="gv-form-sec"><div class="gv-form-sec-title">📋 Datos generales</div>
      <div class="form-row-3">
        <div class="form-group"><label class="form-label required">Sexo</label><select class="form-input" name="sexo" required><option value="hembra" <?= ($ed['sexo']??'')==='hembra'?'selected':'' ?>>Hembra</option><option value="macho" <?= ($ed['sexo']??'')==='macho'?'selected':'' ?>>Macho</option></select></div>
        <div class="form-group">
          <label class="form-label" style="display:flex;align-items:center;gap:6px">Raza
            <button type="button" onclick="abrirGvModal('raza')" title="Gestionar razas" style="width:18px;height:18px;border-radius:50%;background:var(--primary);color:#fff;border:none;cursor:pointer;font-size:12px;line-height:1;display:flex;align-items:center;justify-content:center;flex-shrink:0">＋</button>
          </label>
          <select class="form-input" name="raza" id="sel-raza">
            <option value="">— Seleccionar raza —</option>
            <?php foreach($razas as $rz): ?><option value="<?= clean($rz['nombre']) ?>" <?= ($ed['raza']??'')===$rz['nombre']?'selected':'' ?>><?= clean($rz['nombre']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Color</label><input class="form-input" name="color" value="<?= clean($ed['color']??'') ?>"></div>
      </div>
      <div class="form-row-3">
        <div class="form-group"><label class="form-label">Fecha nacimiento</label><input class="form-input" type="date" name="fecha_nacimiento" value="<?= clean($ed['fecha_nacimiento']??'') ?>"></div>
        <div class="form-group"><label class="form-label">Tipo</label><select class="form-input" name="tipo"><?php foreach(['leche'=>'Leche','carne'=>'Carne','reproductor'=>'Reproductor','mixto'=>'Mixto','cria'=>'Cría'] as $k=>$v): ?><option value="<?= $k ?>" <?= ($ed['tipo']??'mixto')===$k?'selected':'' ?>><?= $v ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label class="form-label">Estado reproductivo</label><select class="form-input" name="estado_reproductivo"><?php foreach(array_keys($erc) as $er): ?><option value="<?= $er ?>" <?= ($ed['estado_reproductivo']??'vacia')===$er?'selected':'' ?>><?= ucfirst($er) ?></option><?php endforeach; ?></select></div>
      </div>
    </div>
    <div class="gv-form-sec"><div class="gv-form-sec-title">📍 Ubicación</div>
      <div class="form-row-3">
        <div class="form-group">
          <label class="form-label" style="display:flex;align-items:center;gap:6px">Fundo
            <button type="button" onclick="abrirGvModal('fundo')" title="Gestionar fundos" style="width:18px;height:18px;border-radius:50%;background:var(--primary);color:#fff;border:none;cursor:pointer;font-size:12px;line-height:1;display:flex;align-items:center;justify-content:center;flex-shrink:0">＋</button>
          </label>
          <select class="form-input" name="fundo_id" id="sel-fundo" onchange="filtrarLotesPorFundo(this.value)">
            <option value="">—</option>
            <?php foreach($fundos as $f): ?><option value="<?= $f['id'] ?>" <?= ($ed['fundo_id']??'')==$f['id']?'selected':'' ?>><?= clean($f['nombre']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label" style="display:flex;align-items:center;gap:6px">Lote
            <button type="button" onclick="abrirGvModal('lote')" title="Gestionar lotes" style="width:18px;height:18px;border-radius:50%;background:var(--primary);color:#fff;border:none;cursor:pointer;font-size:12px;line-height:1;display:flex;align-items:center;justify-content:center;flex-shrink:0">＋</button>
          </label>
          <select class="form-input" name="lote_id" id="sel-lote">
            <option value="">—</option>
            <?php foreach($lotes as $l): ?><option value="<?= $l['id'] ?>" data-fundo="<?= $l['fundo_id'] ?>" <?= ($ed['lote_id']??'')==$l['id']?'selected':'' ?>><?= clean($l['nombre']) ?><?= $l['fundo']?' ('.$l['fundo'].')':'' ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Origen</label><select class="form-input" name="origen"><option value="nacimiento" <?= ($ed['origen']??'')==='nacimiento'?'selected':'' ?>>Nacimiento</option><option value="compra" <?= ($ed['origen']??'')==='compra'?'selected':'' ?>>Compra</option></select></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Fecha ingreso</label><input class="form-input" type="date" name="fecha_ingreso" value="<?= clean($ed['fecha_ingreso']??date('Y-m-d')) ?>"></div>
        <div class="form-group"><label class="form-label">Estado</label><select class="form-input" name="estado"><?php foreach(['activo'=>'Activo','vendido'=>'Vendido','muerto'=>'Muerto','dado_de_baja'=>'Dado de baja','cuarentena'=>'Cuarentena'] as $k=>$v): ?><option value="<?= $k ?>" <?= ($ed['estado']??'activo')===$k?'selected':'' ?>><?= $v ?></option><?php endforeach; ?></select></div>
      </div>
    </div>
    <div class="gv-form-sec"><div class="gv-form-sec-title">📊 Datos productivos</div>
      <div class="form-row-3">
        <div class="form-group"><label class="form-label">Peso inicial (kg)</label><input class="form-input" type="number" step="0.1" name="peso_inicial" value="<?= clean($ed['peso_inicial']??'') ?>"></div>
        <div class="form-group"><label class="form-label">Peso actual (kg)</label><input class="form-input" type="number" step="0.1" name="peso_actual" value="<?= clean($ed['peso_actual']??'') ?>"></div>
        <div class="form-group"><label class="form-label">Prod. estimada (L/día)</label><input class="form-input" type="number" step="0.1" name="produccion_estimada" value="<?= clean($ed['produccion_estimada']??'') ?>"></div>
      </div>
      <div class="form-group"><label class="form-label">Observaciones</label><textarea class="form-input" name="notas"><?= clean($ed['notas']??'') ?></textarea></div>
    </div>
    <div class="flex gap-2"><button type="submit" class="btn btn-primary">💾 Guardar</button><a href="?p=ganado&sub=animales" class="btn btn-ghost">Cancelar</a></div>
  </form>
</div>
<?php else: ?>
<div class="flex items-center justify-between mb-3"><div><div class="page-title">🏷️ Animales</div><div class="page-desc"><?= count($animales) ?> registrados</div></div><a href="?p=ganado&sub=animales&action=nueva" class="btn btn-primary">＋ Nuevo Animal</a></div>
<div class="grid g4">
<?php foreach($animales as $a): $foto_url=!empty($a['foto'])&&file_exists(UPLOADS_PATH.'/'.$a['foto'])?BASE_URL.'/public/uploads/'.$a['foto']:null;$erci=$erc[$a['estado_reproductivo']]??['#94a3b8','—']; ?>
<div class="card card-sm" style="padding:0;overflow:hidden;cursor:pointer" onclick="window.location.href='?p=ganado&sub=animales&action=editar&id=<?= $a['id'] ?>'">
  <?php if($foto_url): ?><img src="<?= $foto_url ?>" style="width:100%;height:110px;object-fit:cover">
  <?php else: ?><div style="width:100%;height:110px;background:linear-gradient(135deg,#f0fdf4,#dcfce7);display:flex;align-items:center;justify-content:center;font-size:48px">🐄</div><?php endif; ?>
  <div style="padding:10px 12px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:3px">
      <div style="font-size:13px;font-weight:700"><?= clean($a['nombre']??'#'.$a['numero_arete']) ?></div>
      <span style="font-size:12px;color:<?= ($a['sexo']??'')==='macho'?'#3b82f6':'#ec4899' ?>"><?= ($a['sexo']??'')==='macho'?'♂':'♀' ?></span>
    </div>
    <div style="font-size:11px;color:var(--text3);margin-bottom:5px">Arete: <?= clean($a['numero_arete']??'—') ?><?= $a['raza']?' · '.clean($a['raza']):'' ?></div>
    <div style="display:flex;justify-content:space-between;align-items:center">
      <span class="gv-repro" style="background:<?= $erci[0] ?>"><?= $erci[1] ?></span>
      <?php if($a['peso_actual']??0): ?><span style="font-size:11px;color:var(--text3)"><?= $a['peso_actual'] ?> kg</span><?php endif; ?>
    </div>
  </div>
</div>
<?php endforeach; ?>
<?php if(empty($animales)): ?><div class="card text-center" style="grid-column:1/-1;padding:48px"><div style="font-size:48px;margin-bottom:12px;opacity:.3">🐄</div><div class="font-semi mb-2">Sin animales registrados</div><a href="?p=ganado&sub=animales&action=nueva" class="btn btn-primary btn-sm">Registrar primer animal</a></div><?php endif; ?>
</div>
<?php endif; ?>

<?php elseif($sub==='reproduccion'): ?>
<?php $rtab=$_GET['rtab']??'celos'; ?>
<div class="flex items-center justify-between mb-3">
  <div class="page-title">🧬 Reproducción</div>
  <div class="flex gap-1">
    <?php foreach(['celos'=>'🌡️ Celos','insem'=>'🔬 Inseminaciones','prenez'=>'🤰 Preñez','partos'=>'🐃 Partos'] as $rt=>$rl): ?>
    <a href="?p=ganado&sub=reproduccion&rtab=<?= $rt ?>" class="btn btn-sm <?= $rtab===$rt?'btn-primary':'btn-ghost' ?>"><?= $rl ?></a>
    <?php endforeach; ?>
  </div>
</div>

<?php if($rtab==='celos'): ?>
<div class="flex justify-end mb-2"><a href="?p=ganado&sub=reproduccion&rtab=celos&action=nueva_celo" class="btn btn-primary btn-sm">＋ Registrar Celo</a></div>
<?php if($action==='nueva_celo'): ?>
<div class="card" style="max-width:620px">
  <div class="sec-title mb-3">🌡️ Control de Celo</div>
  <form method="POST"><input type="hidden" name="action" value="save_celo">
    <div class="form-row"><div class="form-group"><label class="form-label required">Animal</label><select class="form-input" name="animal_id" required><option value="">—</option><?php foreach($animales as $a): if(($a['sexo']??'')==='hembra'): ?><option value="<?= $a['id'] ?>"><?= clean($a['nombre']??$a['numero_arete']) ?> (<?= $a['numero_arete'] ?>)</option><?php endif;endforeach; ?></select></div><div class="form-group"><label class="form-label">Veterinario</label><select class="form-input" name="veterinario_id"><option value="">—</option><?php foreach($vets as $v): ?><option value="<?= $v['id'] ?>"><?= clean($v['nombre']) ?></option><?php endforeach; ?></select></div></div>
    <div class="form-row"><div class="form-group"><label class="form-label required">Fecha</label><input class="form-input" type="date" name="fecha" value="<?= date('Y-m-d') ?>" required></div><div class="form-group"><label class="form-label">Hora</label><input class="form-input" type="time" name="hora" value="<?= date('H:i') ?>"></div></div>
    <div class="form-group"><label class="form-label">Método detección</label><select class="form-input" name="metodo_deteccion"><option value="visual">Visual</option><option value="parche">Parche</option><option value="georritmo">Georritmo</option><option value="otro">Otro</option></select></div>
    <div class="form-row"><div class="form-group"><label class="form-label">Temperatura (°C)</label><input class="form-input" type="number" step="0.1" name="temperatura"></div><div class="form-group" style="padding-top:24px"><label style="display:flex;align-items:center;gap:8px;cursor:pointer"><input type="checkbox" name="inflamacion"> Inflamación vulvar</label></div></div>
    <div class="form-group"><label class="form-label">Observaciones</label><textarea class="form-input" name="observaciones"></textarea></div>
    <label style="display:flex;align-items:center;gap:8px;margin-bottom:14px;padding:10px;background:var(--primary-l);border-radius:8px;cursor:pointer"><input type="checkbox" name="programar_inseminacion" checked><strong>Programar inseminación</strong></label>
    <div class="flex gap-2"><button type="submit" class="btn btn-primary">💾 Guardar</button><a href="?p=ganado&sub=reproduccion&rtab=celos" class="btn btn-ghost">Cancelar</a></div>
  </form>
</div>
<?php else: $celos=[];try{$celos=$db->query("SELECT ce.*,a.nombre as animal,a.numero_arete FROM gv_celos ce JOIN gv_animales a ON a.id=ce.animal_id ORDER BY ce.fecha DESC LIMIT 30")->fetchAll();}catch(Exception $e){} ?>
<div class="card" style="padding:0"><table class="vtable"><thead><tr><th>Animal</th><th>Fecha</th><th>Método</th><th>Temp.</th><th>Inflamación</th><th>Prog. Insem.</th></tr></thead><tbody>
<?php foreach($celos as $c): ?><tr><td class="td-main"><?= clean($c['animal']??$c['numero_arete']) ?><div class="text-xs text-muted"><?= clean($c['numero_arete']) ?></div></td><td><?= date('d/m/Y',strtotime($c['fecha'])) ?><?= $c['hora']?' '.substr($c['hora'],0,5):'' ?></td><td><span class="badge b-info"><?= clean($c['metodo_deteccion']??'visual') ?></span></td><td><?= $c['temperatura']?$c['temperatura'].'°C':'—' ?></td><td><?= $c['inflamacion']?'<span class="badge b-warning">Sí</span>':'No' ?></td><td><?= $c['programar_inseminacion']?'<span class="badge b-success">✅</span>':'No' ?></td></tr>
<?php endforeach;if(empty($celos)): ?><tr><td colspan="6" class="text-center text-muted" style="padding:32px">Sin registros.</td></tr><?php endif; ?>
</tbody></table></div>
<?php endif; ?>

<?php elseif($rtab==='insem'): ?>
<div class="flex justify-end mb-2"><a href="?p=ganado&sub=reproduccion&rtab=insem&action=nueva_insem" class="btn btn-primary btn-sm">＋ Registrar Inseminación</a></div>
<?php if($action==='nueva_insem'): ?>
<div class="card" style="max-width:620px">
  <div class="sec-title mb-3">🔬 Inseminación Artificial</div>
  <form method="POST"><input type="hidden" name="action" value="save_insem">
    <div class="form-row"><div class="form-group"><label class="form-label required">Animal</label><select class="form-input" name="animal_id" required><option value="">—</option><?php foreach($animales as $a): if(($a['sexo']??'')==='hembra'): ?><option value="<?= $a['id'] ?>"><?= clean($a['nombre']??$a['numero_arete']) ?></option><?php endif;endforeach; ?></select></div><div class="form-group"><label class="form-label">Técnico</label><select class="form-input" name="tecnico_id"><option value="">—</option><?php foreach($vets as $v): ?><option value="<?= $v['id'] ?>"><?= clean($v['nombre']) ?></option><?php endforeach; ?></select></div></div>
    <div class="form-row"><div class="form-group"><label class="form-label required">Fecha</label><input class="form-input" type="date" name="fecha" value="<?= date('Y-m-d') ?>" required></div><div class="form-group"><label class="form-label">Toro</label><select class="form-input" name="toro_id"><option value="">—</option><?php foreach($toros as $t): ?><option value="<?= $t['id'] ?>"><?= clean($t['nombre']) ?></option><?php endforeach; ?></select></div></div>
    <div class="form-row"><div class="form-group"><label class="form-label">Código pajilla</label><input class="form-input" name="codigo_pajilla"></div><div class="form-group"><label class="form-label">Tipo semen</label><select class="form-input" name="procedencia_semen"><option value="nacional">Nacional</option><option value="importado">Importado</option><option value="sexado">Sexado</option></select></div></div>
    <div class="form-group"><label class="form-label">Programar diagnóstico preñez</label><input class="form-input" type="date" name="fecha_diagnostico_prenez" value="<?= date('Y-m-d',strtotime('+45 days')) ?>"></div>
    <div class="form-group"><label class="form-label">Observaciones</label><textarea class="form-input" name="observaciones"></textarea></div>
    <div class="flex gap-2"><button type="submit" class="btn btn-primary">💾 Guardar</button><a href="?p=ganado&sub=reproduccion&rtab=insem" class="btn btn-ghost">Cancelar</a></div>
  </form>
</div>
<?php else: $insems=[];try{$insems=$db->query("SELECT i.*,a.nombre as animal,a.numero_arete,t.nombre as toro FROM gv_inseminaciones i JOIN gv_animales a ON a.id=i.animal_id LEFT JOIN gv_toros t ON t.id=i.toro_id ORDER BY i.fecha DESC LIMIT 30")->fetchAll();}catch(Exception $e){} ?>
<div class="card" style="padding:0"><table class="vtable"><thead><tr><th>Animal</th><th>Fecha</th><th>Toro/Pajilla</th><th>Tipo</th><th>Diag. preñez</th><th>Resultado</th></tr></thead><tbody>
<?php foreach($insems as $i): $rb=['pendiente'=>'b-warning','prenada'=>'b-success','vacia'=>'b-danger','repeticion'=>'b-orange']; ?><tr><td class="td-main"><?= clean($i['animal']??'') ?></td><td><?= date('d/m/Y',strtotime($i['fecha'])) ?></td><td><?= clean($i['toro']??'—') ?><div class="text-xs text-muted"><?= clean($i['codigo_pajilla']??'') ?></div></td><td><span class="badge b-info"><?= clean($i['procedencia_semen']??'') ?></span></td><td class="text-muted"><?= $i['fecha_diagnostico_prenez']?date('d/m/Y',strtotime($i['fecha_diagnostico_prenez'])):'—' ?></td><td><span class="badge <?= $rb[$i['resultado']]??'b-gray' ?>"><?= ucfirst($i['resultado']??'—') ?></span></td></tr>
<?php endforeach;if(empty($insems)): ?><tr><td colspan="6" class="text-center text-muted" style="padding:32px">Sin registros.</td></tr><?php endif; ?>
</tbody></table></div>
<?php endif; ?>

<?php elseif($rtab==='prenez'): ?>
<div class="flex justify-end mb-2"><a href="?p=ganado&sub=reproduccion&rtab=prenez&action=nuevo_diag" class="btn btn-primary btn-sm">＋ Diagnóstico Preñez</a></div>
<?php if($action==='nuevo_diag'): ?>
<div class="card" style="max-width:580px">
  <div class="sec-title mb-3">🤰 Diagnóstico de Preñez</div>
  <form method="POST"><input type="hidden" name="action" value="save_prenez">
    <div class="form-row"><div class="form-group"><label class="form-label required">Animal</label><select class="form-input" name="animal_id" required><option value="">—</option><?php foreach($animales as $a): if(($a['sexo']??'')==='hembra'): ?><option value="<?= $a['id'] ?>"><?= clean($a['nombre']??$a['numero_arete']) ?></option><?php endif;endforeach; ?></select></div><div class="form-group"><label class="form-label">Veterinario</label><select class="form-input" name="veterinario_id"><option value="">—</option><?php foreach($vets as $v): ?><option value="<?= $v['id'] ?>"><?= clean($v['nombre']) ?></option><?php endforeach; ?></select></div></div>
    <div class="form-row"><div class="form-group"><label class="form-label required">Fecha diagnóstico</label><input class="form-input" type="date" name="fecha_diagnostico" value="<?= date('Y-m-d') ?>" required></div><div class="form-group"><label class="form-label">Fecha inseminación</label><input class="form-input" type="date" name="fecha_inseminacion" id="fi_input" oninput="calcFpp()"></div></div>
    <div class="form-row"><div class="form-group"><label class="form-label">Método</label><select class="form-input" name="metodo"><option value="ecografia">Ecografía</option><option value="tacto_rectal">Tacto rectal</option><option value="laboratorio">Laboratorio</option></select></div><div class="form-group"><label class="form-label required">Resultado</label><select class="form-input" name="resultado" required onchange="document.getElementById('fpp_wrap').style.display=this.value==='prenada'?'block':'none'"><option value="vacia">Vacía</option><option value="prenada">Preñada</option></select></div></div>
    <div id="fpp_wrap" style="display:none" class="alert alert-success">📅 Fecha probable de parto (~283 días): <strong id="fpp_val">—</strong></div>
    <div class="form-group"><label class="form-label">Observaciones</label><textarea class="form-input" name="observaciones"></textarea></div>
    <div class="flex gap-2"><button type="submit" class="btn btn-primary">💾 Guardar</button><a href="?p=ganado&sub=reproduccion&rtab=prenez" class="btn btn-ghost">Cancelar</a></div>
  </form>
  <script>function calcFpp(){var fi=document.getElementById('fi_input').value;if(!fi)return;var d=new Date(fi);d.setDate(d.getDate()+283);document.getElementById('fpp_val').textContent=d.toLocaleDateString('es-PE');}</script>
</div>
<?php else: $pl=[];try{$pl=$db->query("SELECT p.*,a.nombre as animal,a.numero_arete FROM gv_prenez p JOIN gv_animales a ON a.id=p.animal_id ORDER BY p.fecha_diagnostico DESC LIMIT 30")->fetchAll();}catch(Exception $e){} ?>
<div class="card" style="padding:0"><table class="vtable"><thead><tr><th>Animal</th><th>Fecha diag.</th><th>Método</th><th>Resultado</th><th>Prob. parto</th></tr></thead><tbody>
<?php foreach($pl as $p): $rb=['prenada'=>'b-success','vacia'=>'b-danger','aborto'=>'b-warning']; ?><tr><td class="td-main"><?= clean($p['animal']??'') ?></td><td><?= date('d/m/Y',strtotime($p['fecha_diagnostico'])) ?></td><td><span class="badge b-info"><?= clean($p['metodo']??'') ?></span></td><td><span class="badge <?= $rb[$p['resultado']]??'b-gray' ?>"><?= ucfirst($p['resultado']) ?></span></td><td class="font-semi" style="color:#f59e0b"><?= $p['fecha_probable_parto']?date('d/m/Y',strtotime($p['fecha_probable_parto'])):'—' ?></td></tr>
<?php endforeach;if(empty($pl)): ?><tr><td colspan="5" class="text-center text-muted" style="padding:32px">Sin registros.</td></tr><?php endif; ?>
</tbody></table></div>
<?php endif; ?>

<?php elseif($rtab==='partos'): ?>
<div class="flex justify-end mb-2"><a href="?p=ganado&sub=reproduccion&rtab=partos&action=nuevo_parto" class="btn btn-primary btn-sm">＋ Registrar Parto</a></div>
<?php if($action==='nuevo_parto'): ?>
<div class="card" style="max-width:620px">
  <div class="sec-title mb-3">🐃 Registro de Parto</div>
  <form method="POST"><input type="hidden" name="action" value="save_parto">
    <div class="form-row"><div class="form-group"><label class="form-label required">Madre</label><select class="form-input" name="madre_id" required><option value="">—</option><?php foreach($animales as $a): if(($a['sexo']??'')==='hembra'): ?><option value="<?= $a['id'] ?>"><?= clean($a['nombre']??$a['numero_arete']) ?> (<?= $a['numero_arete'] ?>)</option><?php endif;endforeach; ?></select></div><div class="form-group"><label class="form-label">Veterinario</label><select class="form-input" name="veterinario_id"><option value="">—</option><?php foreach($vets as $v): ?><option value="<?= $v['id'] ?>"><?= clean($v['nombre']) ?></option><?php endforeach; ?></select></div></div>
    <div class="form-row"><div class="form-group"><label class="form-label required">Fecha parto</label><input class="form-input" type="date" name="fecha_parto" value="<?= date('Y-m-d') ?>" required></div><div class="form-group"><label class="form-label">Tipo</label><select class="form-input" name="tipo"><option value="natural">Natural</option><option value="cesarea">Cesárea</option><option value="distocico">Distócico</option></select></div></div>
    <div class="form-group"><label class="form-label">Complicaciones</label><textarea class="form-input" name="complicaciones" style="min-height:55px"></textarea></div>
    <div class="gv-form-sec"><div class="gv-form-sec-title">Datos de la cría</div>
      <div class="form-row-3"><div class="form-group"><label class="form-label">Sexo cría</label><select class="form-input" name="cria_sexo"><option value="">—</option><option value="macho">Macho</option><option value="hembra">Hembra</option></select></div><div class="form-group"><label class="form-label">Peso (kg)</label><input class="form-input" type="number" step="0.1" name="cria_peso"></div><div class="form-group"><label class="form-label">Estado</label><select class="form-input" name="cria_estado"><option value="vivo">Vivo</option><option value="muerto">Muerto</option><option value="debil">Débil</option></select></div></div>
      <div class="form-group"><label class="form-label">Arete de la cría</label><input class="form-input" name="cria_arete"></div>
    </div>
    <div class="flex gap-2"><button type="submit" class="btn btn-primary">💾 Guardar</button><a href="?p=ganado&sub=reproduccion&rtab=partos" class="btn btn-ghost">Cancelar</a></div>
  </form>
</div>
<?php else: $parl=[];try{$parl=$db->query("SELECT pa.*,a.nombre as madre,a.numero_arete FROM gv_partos pa JOIN gv_animales a ON a.id=pa.madre_id ORDER BY pa.fecha_parto DESC LIMIT 20")->fetchAll();}catch(Exception $e){} ?>
<div class="card" style="padding:0"><table class="vtable"><thead><tr><th>Madre</th><th>Fecha parto</th><th>Tipo</th><th>Sexo cría</th><th>Peso cría</th><th>Estado cría</th></tr></thead><tbody>
<?php foreach($parl as $p): ?><tr><td class="td-main"><?= clean($p['madre']??'') ?></td><td><?= date('d/m/Y',strtotime($p['fecha_parto'])) ?></td><td><span class="badge b-info"><?= ucfirst($p['tipo']??'') ?></span></td><td><?= $p['cria_sexo']?ucfirst($p['cria_sexo']):'—' ?></td><td><?= $p['cria_peso']?$p['cria_peso'].' kg':'—' ?></td><td><span class="badge <?= ($p['cria_estado']??'')==='vivo'?'b-success':(($p['cria_estado']??'')==='muerto'?'b-danger':'b-warning') ?>"><?= ucfirst($p['cria_estado']??'—') ?></span></td></tr>
<?php endforeach;if(empty($parl)): ?><tr><td colspan="6" class="text-center text-muted" style="padding:32px">Sin registros.</td></tr><?php endif; ?>
</tbody></table></div>
<?php endif; ?>
<?php endif; // rtab ?>

<?php elseif($sub==='leche'): ?>
<div class="flex items-center justify-between mb-3"><div class="page-title">🥛 Producción de Leche</div><a href="?p=ganado&sub=leche&action=nueva" class="btn btn-primary">＋ Registrar</a></div>
<?php if($action==='nueva'): ?>
<div class="card" style="max-width:560px">
  <form method="POST"><input type="hidden" name="action" value="save_leche">
    <div class="form-row"><div class="form-group"><label class="form-label required">Animal</label><select class="form-input" name="animal_id" required><option value="">—</option><?php foreach($animales as $a): if(($a['sexo']??'')==='hembra'): ?><option value="<?= $a['id'] ?>"><?= clean($a['nombre']??$a['numero_arete']) ?></option><?php endif;endforeach; ?></select></div><div class="form-group"><label class="form-label required">Fecha</label><input class="form-input" type="date" name="fecha" value="<?= date('Y-m-d') ?>" required></div></div>
    <div class="form-row"><div class="form-group"><label class="form-label">Turno</label><select class="form-input" name="turno"><option value="manana">Mañana</option><option value="tarde">Tarde</option><option value="noche">Noche</option></select></div><div class="form-group"><label class="form-label required">Litros</label><input class="form-input" type="number" step="0.1" name="litros" required></div></div>
    <div class="form-row"><div class="form-group"><label class="form-label">Calidad</label><select class="form-input" name="calidad"><option value="A">A — Premium</option><option value="B">B — Estándar</option><option value="C">C — Baja</option><option value="rechazo">Rechazo</option></select></div><div class="form-group"><label class="form-label">Observaciones</label><input class="form-input" name="observaciones"></div></div>
    <div class="flex gap-2"><button type="submit" class="btn btn-primary">💾 Guardar</button><a href="?p=ganado&sub=leche" class="btn btn-ghost">Cancelar</a></div>
  </form>
</div>
<?php else: $ll=[];try{$ll=$db->query("SELECT l.*,a.nombre as animal,a.numero_arete FROM gv_leche l JOIN gv_animales a ON a.id=l.animal_id ORDER BY l.fecha DESC,l.turno LIMIT 60")->fetchAll();}catch(Exception $e){} ?>
<div class="card" style="padding:0"><table class="vtable"><thead><tr><th>Fecha</th><th>Animal</th><th>Turno</th><th>Litros</th><th>Calidad</th></tr></thead><tbody>
<?php foreach($ll as $l): ?><tr><td><?= date('d/m/Y',strtotime($l['fecha'])) ?></td><td class="td-main"><?= clean($l['animal']??'') ?></td><td><span class="badge b-info"><?= ucfirst($l['turno']??'') ?></span></td><td class="font-bold" style="color:var(--primary)"><?= $l['litros'] ?> L</td><td><span class="badge <?= ($l['calidad']??'')==='A'?'b-success':(($l['calidad']??'')==='rechazo'?'b-danger':'b-warning') ?>"><?= $l['calidad']??'—' ?></span></td></tr>
<?php endforeach;if(empty($ll)): ?><tr><td colspan="5" class="text-center text-muted" style="padding:32px">Sin registros.</td></tr><?php endif; ?>
</tbody></table></div>
<?php endif; ?>

<?php elseif($sub==='peso'): ?>
<div class="flex items-center justify-between mb-3"><div class="page-title">⚖️ Control de Peso</div><a href="?p=ganado&sub=peso&action=nueva" class="btn btn-primary">＋ Registrar pesaje</a></div>
<?php if($action==='nueva'): ?>
<div class="card" style="max-width:520px">
  <form method="POST"><input type="hidden" name="action" value="save_peso">
    <div class="form-row"><div class="form-group"><label class="form-label required">Animal</label><select class="form-input" name="animal_id" required><option value="">—</option><?php foreach($animales as $a): ?><option value="<?= $a['id'] ?>"><?= clean($a['nombre']??$a['numero_arete']) ?></option><?php endforeach; ?></select></div><div class="form-group"><label class="form-label required">Fecha</label><input class="form-input" type="date" name="fecha" value="<?= date('Y-m-d') ?>" required></div></div>
    <div class="form-row"><div class="form-group"><label class="form-label required">Peso (kg)</label><input class="form-input" type="number" step="0.1" name="peso" required></div><div class="form-group"><label class="form-label">Condición corporal (1-5)</label><input class="form-input" type="number" min="1" max="5" step="0.5" name="condicion_corporal"></div></div>
    <div class="form-group"><label class="form-label">Observaciones</label><textarea class="form-input" name="observaciones" style="min-height:55px"></textarea></div>
    <div class="flex gap-2"><button type="submit" class="btn btn-primary">💾 Guardar</button><a href="?p=ganado&sub=peso" class="btn btn-ghost">Cancelar</a></div>
  </form>
</div>
<?php else: $psl=[];try{$psl=$db->query("SELECT p.*,a.nombre as animal,a.numero_arete FROM gv_pesos p JOIN gv_animales a ON a.id=p.animal_id ORDER BY p.fecha DESC LIMIT 50")->fetchAll();}catch(Exception $e){} ?>
<div class="card" style="padding:0"><table class="vtable"><thead><tr><th>Fecha</th><th>Animal</th><th>Peso</th><th>Peso anterior</th><th>Ganancia/día</th><th>BCS</th></tr></thead><tbody>
<?php foreach($psl as $p): $g=$p['ganancia_diaria']??null; ?><tr><td><?= date('d/m/Y',strtotime($p['fecha'])) ?></td><td class="td-main"><?= clean($p['animal']??'') ?></td><td class="font-bold"><?= $p['peso'] ?> kg</td><td class="text-muted"><?= $p['peso_anterior']?$p['peso_anterior'].' kg':'—' ?></td><td class="font-semi" style="color:<?= $g>0?'var(--success)':($g<0?'var(--danger)':'var(--text)') ?>"><?= $g!==null?($g>0?'+':'').$g.' kg/día':'—' ?></td><td><?= $p['condicion_corporal']?$p['condicion_corporal'].'/5':'—' ?></td></tr>
<?php endforeach;if(empty($psl)): ?><tr><td colspan="6" class="text-center text-muted" style="padding:32px">Sin registros.</td></tr><?php endif; ?>
</tbody></table></div>
<?php endif; ?>

<?php elseif($sub==='sanidad'): ?>
<div class="flex items-center justify-between mb-3"><div class="page-title">💉 Sanidad</div><a href="?p=ganado&sub=sanidad&action=nueva" class="btn btn-primary">＋ Registrar Tratamiento</a></div>
<?php if($action==='nueva'): ?>
<div class="card" style="max-width:640px">
  <form method="POST"><input type="hidden" name="action" value="save_tratamiento">
    <div class="form-row"><div class="form-group"><label class="form-label required">Animal</label><select class="form-input" name="animal_id" required><option value="">—</option><?php foreach($animales as $a): ?><option value="<?= $a['id'] ?>"><?= clean($a['nombre']??$a['numero_arete']) ?></option><?php endforeach; ?></select></div><div class="form-group"><label class="form-label">Veterinario</label><select class="form-input" name="veterinario_id"><option value="">—</option><?php foreach($vets as $v): ?><option value="<?= $v['id'] ?>"><?= clean($v['nombre']) ?></option><?php endforeach; ?></select></div></div>
    <div class="form-row"><div class="form-group"><label class="form-label required">Fecha</label><input class="form-input" type="date" name="fecha" value="<?= date('Y-m-d') ?>" required></div><div class="form-group"><label class="form-label">Enfermedad</label><input class="form-input" name="enfermedad" placeholder="Mastitis, Brucelosis..."></div></div>
    <div class="form-group"><label class="form-label">Diagnóstico</label><textarea class="form-input" name="diagnostico"></textarea></div>
    <div class="form-row"><div class="form-group"><label class="form-label">Medicamento</label><input class="form-input" name="medicamento"></div><div class="form-group"><label class="form-label">Dosis</label><input class="form-input" name="dosis"></div></div>
    <div class="form-row"><div class="form-group"><label class="form-label">Duración (días)</label><input class="form-input" type="number" name="duracion_dias"></div><div class="form-group"><label class="form-label">Costo (S/.)</label><input class="form-input" type="number" step="0.01" name="costo"></div></div>
    <div class="form-group"><label class="form-label">Tratamiento</label><textarea class="form-input" name="tratamiento"></textarea></div>
    <div class="flex gap-2"><button type="submit" class="btn btn-primary">💾 Guardar</button><a href="?p=ganado&sub=sanidad" class="btn btn-ghost">Cancelar</a></div>
  </form>
</div>
<?php else: $tl=[];try{$tl=$db->query("SELECT t.*,a.nombre as animal FROM gv_tratamientos t JOIN gv_animales a ON a.id=t.animal_id ORDER BY t.fecha DESC LIMIT 40")->fetchAll();}catch(Exception $e){} ?>
<div class="card" style="padding:0"><table class="vtable"><thead><tr><th>Fecha</th><th>Animal</th><th>Enfermedad</th><th>Medicamento</th><th>Duración</th><th>Estado</th></tr></thead><tbody>
<?php foreach($tl as $t): ?><tr><td><?= date('d/m/Y',strtotime($t['fecha'])) ?></td><td class="td-main"><?= clean($t['animal']??'') ?></td><td><?= clean($t['enfermedad']??'—') ?></td><td class="text-muted"><?= clean($t['medicamento']??'—') ?></td><td class="text-muted"><?= $t['duracion_dias']?$t['duracion_dias'].' días':'—' ?></td><td><span class="badge <?= ($t['estado']??'')==='activo'?'b-danger':(($t['estado']??'')==='finalizado'?'b-success':'b-warning') ?>"><?= ucfirst($t['estado']??'—') ?></span></td></tr>
<?php endforeach;if(empty($tl)): ?><tr><td colspan="6" class="text-center text-muted" style="padding:32px">Sin registros.</td></tr><?php endif; ?>
</tbody></table></div>
<?php endif; ?>

<?php elseif($sub==='fundos'): ?>
<div class="flex items-center justify-between mb-3"><div class="page-title">🌿 Fundos / Haciendas</div><a href="?p=ganado&sub=fundos&action=nuevo" class="btn btn-primary">＋ Nuevo Fundo</a></div>
<?php if($action==='nuevo'||$action==='editar'): $ef=null;if($action==='editar'&&isset($_GET['id'])){try{$st=$db->prepare("SELECT * FROM gv_fundos WHERE id=?");$st->execute([(int)$_GET['id']]);$ef=$st->fetch();}catch(Exception $e){}} ?>
<div class="card" style="max-width:620px">
  <form method="POST"><input type="hidden" name="action" value="save_fundo"><input type="hidden" name="id" value="<?= $ef['id']??'' ?>">
    <div class="form-row"><div class="form-group"><label class="form-label">Código</label><input class="form-input" name="codigo" value="<?= clean($ef['codigo']??'') ?>"></div><div class="form-group"><label class="form-label required">Nombre</label><input class="form-input" name="nombre" value="<?= clean($ef['nombre']??'') ?>" required></div></div>
    <div class="form-row-3"><div class="form-group"><label class="form-label">Departamento</label><input class="form-input" name="departamento" value="<?= clean($ef['departamento']??'') ?>"></div><div class="form-group"><label class="form-label">Provincia</label><input class="form-input" name="provincia" value="<?= clean($ef['provincia']??'') ?>"></div><div class="form-group"><label class="form-label">Distrito</label><input class="form-input" name="distrito" value="<?= clean($ef['distrito']??'') ?>"></div></div>
    <div class="form-row"><div class="form-group"><label class="form-label">Dirección</label><input class="form-input" name="direccion" value="<?= clean($ef['direccion']??'') ?>"></div><div class="form-group"><label class="form-label">Hectáreas</label><input class="form-input" type="number" step="0.01" name="hectareas" value="<?= clean($ef['hectareas']??'') ?>"></div></div>
    <div class="form-row"><div class="form-group"><label class="form-label">Responsable</label><input class="form-input" name="responsable" value="<?= clean($ef['responsable']??'') ?>"></div><div class="form-group"><label class="form-label">Teléfono</label><input class="form-input" name="telefono" value="<?= clean($ef['telefono']??'') ?>"></div></div>
    <div class="form-group"><label class="form-label">Notas</label><textarea class="form-input" name="notas"><?= clean($ef['notas']??'') ?></textarea></div>
    <div class="flex gap-2"><button type="submit" class="btn btn-primary">💾 Guardar</button><a href="?p=ganado&sub=fundos" class="btn btn-ghost">Cancelar</a></div>
  </form>
</div>
<?php else: ?>
<div class="grid g3">
<?php foreach($fundos as $f): $na=count(array_filter($animales,fn($a)=>($a['fundo_id']??0)==$f['id'])); ?>
<div class="card card-hover"><div class="flex items-center gap-2 mb-2"><div class="stat-icon si-success" style="flex-shrink:0">🌿</div><div><div class="font-semi"><?= clean($f['nombre']) ?></div><?php if($f['codigo']): ?><div class="text-xs text-muted">Cód: <?= clean($f['codigo']) ?></div><?php endif; ?></div><a href="?p=ganado&sub=fundos&action=editar&id=<?= $f['id'] ?>" class="btn btn-xs btn-ghost" style="margin-left:auto">✏️</a></div>
<div style="font-size:12px;display:grid;grid-template-columns:1fr 1fr;gap:8px">
<div><span class="text-muted">📍</span> <?= clean(implode(', ',array_filter([$f['distrito']??'',$f['provincia']??'',$f['departamento']??'']))) ?></div>
<div><span class="text-muted">🐄</span> <strong><?= $na ?></strong> animales</div>
<?php if($f['hectareas']??0): ?><div><span class="text-muted">📐</span> <?= number_format($f['hectareas'],1) ?> ha</div><?php endif; ?>
<?php if($f['responsable']??''): ?><div><span class="text-muted">👤</span> <?= clean($f['responsable']) ?></div><?php endif; ?>
</div></div>
<?php endforeach; ?>
<?php if(empty($fundos)): ?><div class="card text-center" style="grid-column:1/-1;padding:48px"><div style="font-size:40px;margin-bottom:12px;opacity:.3">🌿</div><div class="font-semi mb-2">Sin fundos registrados</div><a href="?p=ganado&sub=fundos&action=nuevo" class="btn btn-primary btn-sm">Registrar fundo</a></div><?php endif; ?>
</div>
<?php endif; ?>

<?php elseif($sub==='banco_semen'): ?>
<div class="flex items-center justify-between mb-3"><div class="page-title">🧪 Banco de Semen</div><a href="?p=ganado&sub=banco_semen&action=nueva" class="btn btn-primary">＋ Ingresar Semen</a></div>
<?php if($action==='nueva'): ?>
<div class="card" style="max-width:640px">
  <form method="POST"><input type="hidden" name="action" value="save_semen">
    <div class="form-row"><div class="form-group"><label class="form-label required">Código pajilla</label><input class="form-input" name="codigo_pajilla" required></div><div class="form-group"><label class="form-label">Nombre del toro</label><input class="form-input" name="toro_nombre"></div></div>
    <div class="form-row"><div class="form-group"><label class="form-label">Raza</label><input class="form-input" name="raza"></div><div class="form-group"><label class="form-label">Procedencia</label><select class="form-input" name="procedencia"><option value="nacional">Nacional</option><option value="importado">Importado</option><option value="sexado">Sexado</option></select></div></div>
    <div class="form-row"><div class="form-group"><label class="form-label">Fecha ingreso</label><input class="form-input" type="date" name="fecha_ingreso" value="<?= date('Y-m-d') ?>"></div><div class="form-group"><label class="form-label required">Cantidad (dosis)</label><input class="form-input" type="number" name="cantidad" required></div></div>
    <div class="form-row-3"><div class="form-group"><label class="form-label">Tanque</label><input class="form-input" name="tanque"></div><div class="form-group"><label class="form-label">Temperatura (°C)</label><input class="form-input" type="number" step="0.1" name="temperatura" value="-196"></div><div class="form-group"><label class="form-label">Ubicación física</label><input class="form-input" name="ubicacion_fisica"></div></div>
    <div class="form-row"><div class="form-group"><label class="form-label">Stock mínimo</label><input class="form-input" type="number" name="stock_minimo" value="5"></div><div class="form-group"><label class="form-label">Vencimiento</label><input class="form-input" type="date" name="fecha_vencimiento"></div></div>
    <div class="flex gap-2"><button type="submit" class="btn btn-primary">💾 Registrar</button><a href="?p=ganado&sub=banco_semen" class="btn btn-ghost">Cancelar</a></div>
  </form>
</div>
<?php else: $sl=[];try{$sl=$db->query("SELECT bs.*,(bs.cantidad-bs.cantidad_usada) as disponible FROM gv_banco_semen bs WHERE bs.activo=1 ORDER BY bs.fecha_vencimiento ASC")->fetchAll();}catch(Exception $e){} ?>
<div class="card" style="padding:0"><table class="vtable"><thead><tr><th>Pajilla</th><th>Toro</th><th>Raza</th><th>Procedencia</th><th>Disponible</th><th>Tanque</th><th>Vencimiento</th><th>Estado</th></tr></thead><tbody>
<?php foreach($sl as $s): $dv=(int)($s['disponible']??0);$sm=(int)($s['stock_minimo']??5);$venc=$s['fecha_vencimiento']&&strtotime($s['fecha_vencimiento'])<time(); ?>
<tr><td class="td-main" style="font-family:monospace"><?= clean($s['codigo_pajilla']) ?></td><td><?= clean($s['toro_nombre']??'—') ?></td><td><?= clean($s['raza']??'—') ?></td><td><span class="badge b-info"><?= ucfirst($s['procedencia']??'') ?></span></td><td class="font-bold <?= $dv<=$sm?'color-danger':'' ?>"><?= $dv ?>/<?= $s['cantidad'] ?></td><td class="text-muted"><?= clean($s['tanque']??'—') ?></td><td class="<?= $venc?'color-danger':'' ?>"><?= $s['fecha_vencimiento']?date('d/m/Y',strtotime($s['fecha_vencimiento'])):'—' ?></td><td><span class="badge <?= $venc?'b-danger':($dv<=$sm?'b-warning':'b-success') ?>"><?= $venc?'Vencido':($dv<=$sm?'⚠️ Bajo':'✅ OK') ?></span></td></tr>
<?php endforeach;if(empty($sl)): ?><tr><td colspan="8" class="text-center text-muted" style="padding:32px">Sin pajillas registradas.</td></tr><?php endif; ?>
</tbody></table></div>
<?php endif; ?>
<?php endif; // sub ?>

<!-- ═══ MODALES GESTIÓN RAZA / FUNDO / LOTE ═══ -->
<div id="gv-modal-overlay" onclick="cerrarGvModal()" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:3000;align-items:center;justify-content:center"></div>
<div id="gv-modal-box" style="display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border-radius:14px;box-shadow:0 20px 60px rgba(0,0,0,.2);z-index:3001;width:480px;max-width:95vw;max-height:85vh;overflow-y:auto">
  <div style="padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
    <div id="gv-modal-title" style="font-size:15px;font-weight:700;color:var(--text)"></div>
    <button onclick="cerrarGvModal()" style="background:none;border:none;font-size:18px;cursor:pointer;color:var(--text3);line-height:1">✕</button>
  </div>
  <div id="gv-modal-body" style="padding:16px 20px"></div>
</div>

</div><!-- .page -->
<script>
const GV_API = '<?= BASE_URL ?>/index.php?p=ganado';
const GV_LOTES_ALL = <?= json_encode(array_map(fn($l)=>['id'=>(int)$l['id'],'nombre'=>$l['nombre'],'fundo_id'=>(int)($l['fundo_id']??0)], $lotes)) ?>;
const GV_FUNDOS_ALL = <?= json_encode(array_map(fn($f)=>['id'=>(int)$f['id'],'nombre'=>$f['nombre']], $fundos)) ?>;
const GV_RAZAS_ALL  = <?= json_encode(array_map(fn($r)=>['id'=>(int)$r['id'],'nombre'=>$r['nombre'],'especie'=>$r['especie']], $razas)) ?>;

let _gvTipo = '';
let _gvData = { razas:[...GV_RAZAS_ALL], fundos:[...GV_FUNDOS_ALL], lotes:[...GV_LOTES_ALL] };

// ── Abrir modal ──
function abrirGvModal(tipo) {
  _gvTipo = tipo;
  const titles = {raza:'🐄 Gestionar Razas', fundo:'🌿 Gestionar Fundos', lote:'🏷️ Gestionar Lotes'};
  document.getElementById('gv-modal-title').textContent = titles[tipo] || tipo;
  document.getElementById('gv-modal-overlay').style.display = 'block';
  document.getElementById('gv-modal-box').style.display = 'block';
  renderGvModal();
}
function cerrarGvModal() {
  document.getElementById('gv-modal-overlay').style.display = 'none';
  document.getElementById('gv-modal-box').style.display = 'none';
}

// ── Renderizar contenido del modal ──
function renderGvModal() {
  const body = document.getElementById('gv-modal-body');
  const tipo = _gvTipo;
  const items = tipo==='raza' ? _gvData.razas : tipo==='fundo' ? _gvData.fundos : _gvData.lotes;

  let listHtml = items.map(item => `
    <div style="display:flex;align-items:center;gap:8px;padding:8px 10px;background:var(--bg3);border-radius:8px;margin-bottom:6px" id="gvrow-${tipo}-${item.id}">
      <div style="flex:1;font-size:13px;font-weight:600">${item.nombre}</div>
      ${tipo==='raza'&&item.especie?`<span style="font-size:10px;color:var(--text3);background:var(--border);padding:1px 7px;border-radius:999px">${item.especie}</span>`:''}
      <button onclick="editarGvItem('${tipo}',${item.id},'${item.nombre.replace(/'/g,"\\'")}','${(item.especie||item.fundo_id||'').toString().replace(/'/g,"\\'")}') " style="padding:4px 8px;background:var(--primary-l);color:var(--primary-d);border:1px solid var(--primary);border-radius:6px;font-size:11px;cursor:pointer">✏️ Editar</button>
      <button onclick="eliminarGvItem('${tipo}',${item.id},'${item.nombre.replace(/'/g,"\\'")}') " style="padding:4px 8px;background:#fee2e2;color:#7f1d1d;border:1px solid #fca5a5;border-radius:6px;font-size:11px;cursor:pointer">🗑️</button>
    </div>
  `).join('') || `<div style="text-align:center;padding:16px;color:var(--text3);font-size:13px">Sin registros aún.</div>`;

  let formExtra = '';
  if (tipo==='lote') {
    formExtra = `<div style="margin-top:8px"><label style="font-size:12px;font-weight:600;color:var(--text2)">Fundo</label>
      <select id="inp-gv-fundo_id" style="width:100%;margin-top:4px;padding:8px 10px;border:1.5px solid var(--border);border-radius:8px;font-size:13px">
        <option value="">— Sin fundo —</option>
        ${_gvData.fundos.map(f=>`<option value="${f.id}">${f.nombre}</option>`).join('')}
      </select></div>`;
  }
  if (tipo==='raza') {
    formExtra = `<div style="margin-top:8px"><label style="font-size:12px;font-weight:600;color:var(--text2)">Especie</label>
      <select id="inp-gv-especie" style="width:100%;margin-top:4px;padding:8px 10px;border:1.5px solid var(--border);border-radius:8px;font-size:13px">
        <option value="bovino">Bovino</option><option value="ovino">Ovino</option><option value="porcino">Porcino</option><option value="caprino">Caprino</option><option value="equino">Equino</option>
      </select></div>`;
  }

  body.innerHTML = `
    <div style="margin-bottom:16px">${listHtml}</div>
    <div style="border-top:1px solid var(--border);padding-top:14px">
      <div style="font-size:13px;font-weight:700;color:var(--text2);margin-bottom:10px" id="gv-form-label">➕ Agregar nuevo</div>
      <input type="hidden" id="inp-gv-id" value="">
      <div>
        <label style="font-size:12px;font-weight:600;color:var(--text2)">Nombre <span style="color:#ef4444">*</span></label>
        <input id="inp-gv-nombre" type="text" placeholder="Escribe el nombre..."
          style="width:100%;margin-top:4px;padding:8px 10px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;outline:none;box-sizing:border-box"
          onfocus="this.style.borderColor='var(--primary)'" onblur="this.style.borderColor='var(--border)'"
          onkeydown="if(event.key==='Enter'){event.preventDefault();guardarGvItem()}">
      </div>
      ${formExtra}
      <div id="gv-form-err" style="color:#ef4444;font-size:12px;margin-top:6px;display:none"></div>
      <div style="display:flex;gap:8px;margin-top:12px">
        <button onclick="guardarGvItem()" style="flex:1;padding:9px;background:var(--primary);color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer">💾 Guardar</button>
        <button onclick="cancelarGvEdit()" id="btn-gv-cancelar" style="display:none;padding:9px 14px;background:var(--bg3);border:1px solid var(--border);border-radius:8px;font-size:13px;cursor:pointer">Cancelar</button>
      </div>
    </div>
  `;
  setTimeout(()=>document.getElementById('inp-gv-nombre')?.focus(), 100);
}

function editarGvItem(tipo, id, nombre, extra) {
  document.getElementById('inp-gv-id').value = id;
  document.getElementById('inp-gv-nombre').value = nombre;
  if (tipo==='raza') { const s=document.getElementById('inp-gv-especie'); if(s) s.value=extra||'bovino'; }
  if (tipo==='lote') { const s=document.getElementById('inp-gv-fundo_id'); if(s) s.value=extra||''; }
  document.getElementById('gv-form-label').textContent = '✏️ Editando: ' + nombre;
  document.getElementById('btn-gv-cancelar').style.display = 'inline-block';
  document.getElementById('inp-gv-nombre').focus();
}

function cancelarGvEdit() {
  document.getElementById('inp-gv-id').value = '';
  document.getElementById('inp-gv-nombre').value = '';
  document.getElementById('gv-form-label').textContent = '➕ Agregar nuevo';
  document.getElementById('btn-gv-cancelar').style.display = 'none';
  document.getElementById('gv-form-err').style.display = 'none';
}

async function guardarGvItem() {
  const tipo = _gvTipo;
  const id   = document.getElementById('inp-gv-id').value;
  const nombre = document.getElementById('inp-gv-nombre').value.trim();
  const errEl  = document.getElementById('gv-form-err');
  if (!nombre) { errEl.textContent='⚠️ El nombre es obligatorio.'; errEl.style.display='block'; return; }
  errEl.style.display = 'none';

  const fd = new FormData();
  if (tipo==='raza') {
    fd.append('action','save_raza'); fd.append('id',id); fd.append('nombre',nombre);
    const esp=document.getElementById('inp-gv-especie'); if(esp) fd.append('especie',esp.value);
  } else if (tipo==='fundo') {
    fd.append('action','save_fundo_quick'); fd.append('id',id); fd.append('nombre',nombre);
  } else {
    fd.append('action','save_lote_quick'); fd.append('id',id); fd.append('nombre',nombre);
    const fid=document.getElementById('inp-gv-fundo_id'); if(fid) fd.append('fundo_id',fid.value);
  }

  try {
    const r = await fetch(GV_API, {method:'POST', body:fd});
    const d = await r.json();
    if (!d.ok) { errEl.textContent='❌ '+(d.error||'Error al guardar'); errEl.style.display='block'; return; }
    // Actualizar data local
    const arr = tipo==='raza'?_gvData.razas:tipo==='fundo'?_gvData.fundos:_gvData.lotes;
    const idx = arr.findIndex(x=>x.id===d.id);
    const newItem = {id:d.id, nombre:d.nombre, especie:d.especie||'bovino', fundo_id:0};
    if (idx>=0) arr[idx]=newItem; else arr.push(newItem);
    // Actualizar select en formulario
    actualizarSelectGv(tipo, d.id, d.nombre);
    cancelarGvEdit();
    renderGvModal();
  } catch(e) { errEl.textContent='❌ Error de conexión.'; errEl.style.display='block'; }
}

async function eliminarGvItem(tipo, id, nombre) {
  if (!confirm(`¿Eliminar "${nombre}"?`)) return;
  const fd = new FormData();
  if (tipo==='raza') { fd.append('action','delete_raza'); fd.append('id',id); }
  else if (tipo==='fundo') { fd.append('action','delete_fundo_quick'); fd.append('id',id); }
  else { fd.append('action','delete_lote_quick'); fd.append('id',id); }

  try {
    const r = await fetch(GV_API, {method:'POST', body:fd});
    const d = await r.json();
    if (!d.ok) { alert('❌ '+(d.error||'No se pudo eliminar.')); return; }
    const arr = tipo==='raza'?_gvData.razas:tipo==='fundo'?_gvData.fundos:_gvData.lotes;
    const idx = arr.findIndex(x=>x.id===id);
    if (idx>=0) arr.splice(idx,1);
    // Quitar opción del select
    const sel = tipo==='raza'?document.getElementById('sel-raza'):tipo==='fundo'?document.getElementById('sel-fundo'):document.getElementById('sel-lote');
    if (sel) { const opt=sel.querySelector(`option[value="${tipo==='raza'?nombre:id}"]`); if(opt) opt.remove(); }
    renderGvModal();
  } catch(e) { alert('❌ Error de conexión.'); }
}

function actualizarSelectGv(tipo, id, nombre) {
  let sel;
  if (tipo==='raza')  sel = document.getElementById('sel-raza');
  if (tipo==='fundo') sel = document.getElementById('sel-fundo');
  if (tipo==='lote')  sel = document.getElementById('sel-lote');
  if (!sel) return;
  const val = tipo==='raza' ? nombre : String(id);
  const exists = sel.querySelector(`option[value="${val}"]`);
  if (!exists) {
    const opt = document.createElement('option');
    opt.value = val; opt.textContent = nombre;
    sel.appendChild(opt);
  }
  sel.value = val;
}

// Filtrar lotes por fundo seleccionado
function filtrarLotesPorFundo(fundoId) {
  const sel = document.getElementById('sel-lote');
  if (!sel) return;
  const current = sel.value;
  sel.innerHTML = '<option value="">—</option>';
  GV_LOTES_ALL.forEach(l => {
    if (!fundoId || String(l.fundo_id)===String(fundoId)) {
      const opt = document.createElement('option');
      opt.value = l.id; opt.textContent = l.nombre;
      if (String(l.id)===current) opt.selected = true;
      sel.appendChild(opt);
    }
  });
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
