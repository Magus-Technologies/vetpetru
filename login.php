<?php
require_once __DIR__ . '/includes/config.php';
if (isLogged()) { header('Location: ' . BASE_URL . '/index.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $pass  = trim($_POST['password'] ?? '');
    if ($email && $pass) {
        $db = getDB();
        $st = $db->prepare("SELECT * FROM usuarios WHERE email = ? AND activo = 1 LIMIT 1");
        $st->execute([$email]);
        $u = $st->fetch();
        if ($u && password_verify($pass, $u['password'])) {
            $_SESSION['user'] = $u;
            $db->prepare("UPDATE usuarios SET ultimo_login = NOW() WHERE id = ?")->execute([$u['id']]);
            header('Location: ' . BASE_URL . '/index.php');
            exit;
        } else {
            $error = 'Correo o contraseña incorrectos.';
        }
    } else {
        $error = 'Completa todos los campos.';
    }
}
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VetPro — Iniciar Sesión</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Syne:wght@700;800&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Plus Jakarta Sans',sans-serif;background:#f0f2f5;min-height:100vh;display:flex;align-items:center;justify-content:center}
.login-wrap{width:100%;max-width:420px;padding:20px}
.logo-area{text-align:center;margin-bottom:32px}
.logo-icon{width:64px;height:64px;background:#0d9f7a;border-radius:16px;display:inline-flex;align-items:center;justify-content:center;font-size:32px;margin-bottom:12px}
.logo-name{font-family:'Syne',sans-serif;font-size:28px;font-weight:800;color:#1a1d23}
.logo-sub{font-size:13px;color:#9299a8;margin-top:2px}
.card{background:#fff;border-radius:16px;padding:32px;border:1px solid #e2e5eb}
.form-title{font-size:18px;font-weight:700;color:#1a1d23;margin-bottom:6px}
.form-sub{font-size:13px;color:#9299a8;margin-bottom:24px}
.form-group{margin-bottom:16px}
label{display:block;font-size:12px;font-weight:600;color:#5a6072;margin-bottom:6px;text-transform:uppercase;letter-spacing:.4px}
input{width:100%;padding:11px 14px;border:1px solid #e2e5eb;border-radius:9px;font-size:14px;color:#1a1d23;background:#f8f9fb;outline:none;font-family:inherit;transition:border-color .15s}
input:focus{border-color:#0d9f7a;background:#fff}
.btn-login{width:100%;padding:12px;background:#0d9f7a;color:#fff;border:none;border-radius:9px;font-size:14px;font-weight:700;cursor:pointer;font-family:inherit;transition:background .15s;margin-top:4px}
.btn-login:hover{background:#0a7a5e}
.error{background:#fef2f2;border:1px solid #fca5a5;color:#b91c1c;border-radius:8px;padding:10px 14px;font-size:13px;margin-bottom:16px}
.demo-box{background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:10px 14px;margin-top:16px;font-size:12px;color:#15803d}
.demo-box strong{display:block;margin-bottom:4px}
</style>
</head>
<body>
<div class="login-wrap">
  <div class="logo-area">
    <div class="logo-icon">🐾</div>
    <div class="logo-name">VetPro</div>
    <div class="logo-sub">Sistema de Gestión Veterinaria</div>
  </div>
  <div class="card">
    <div class="form-title">Bienvenido</div>
    <div class="form-sub">Ingresa tus credenciales para continuar</div>
    <?php if($error): ?><div class="error"><?= clean($error) ?></div><?php endif; ?>
    <form method="POST">
      <div class="form-group">
        <label>Correo electrónico</label>
        <input type="email" name="email" value="<?= clean($_POST['email']??'') ?>" placeholder="tu@email.com" required autofocus>
      </div>
      <div class="form-group">
        <label>Contraseña</label>
        <input type="password" name="password" placeholder="••••••••" required>
      </div>
      <button type="submit" class="btn-login">Ingresar al sistema</button>
    </form>
    <div class="demo-box">
      <strong>Credenciales de prueba:</strong>
      admin@vetpro.pe / password<br>
      ana@vetpro.pe / password
    </div>
  </div>
</div>
</body>
</html>
