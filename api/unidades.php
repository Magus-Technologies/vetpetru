<?php
/**
 * VetPro — API Unidades de Medida Pet Shop
 * /vetpro/api/unidades.php
 * Retorna JSON puro, sin HTML, sin header/footer
 */
require_once __DIR__ . '/../includes/config.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

$db  = getDB();
$pa  = $_POST['action'] ?? $_GET['action'] ?? '';

// ── Asegurar que las tablas existen ──
$db->exec("CREATE TABLE IF NOT EXISTS petshop_unidades (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    nombre       VARCHAR(80)  NOT NULL,
    abreviatura  VARCHAR(20)  NOT NULL,
    tipo         ENUM('peso','volumen','longitud','unidad','pack') DEFAULT 'unidad',
    activo       TINYINT(1)   DEFAULT 1,
    created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
)");
$count = $db->query("SELECT COUNT(*) FROM petshop_unidades")->fetchColumn();
if ($count == 0) {
    $db->exec("INSERT INTO petshop_unidades (nombre,abreviatura,tipo) VALUES
        ('Unidad','und','unidad'),('Kilogramo','kg','peso'),('Gramo','g','peso'),
        ('Litro','L','volumen'),('Mililitro','ml','volumen'),
        ('Pack / Bolsa','pack','pack'),('Metro','m','longitud'),
        ('Caja','caja','pack'),('Docena','doc','pack')");
}

// ── Listar ──
if ($pa === 'get' || $pa === 'get_unidades' || !$pa) {
    $rows = $db->query(
        "SELECT id, nombre, abreviatura, tipo FROM petshop_unidades WHERE activo=1 ORDER BY nombre ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok' => true, 'data' => $rows]);
    exit;
}

// ── Guardar (crear o editar) ──
if ($pa === 'save' || $pa === 'save_unidad') {
    $uid    = (int)($_POST['uid'] ?? 0);
    $nombre = trim($_POST['nombre'] ?? '');
    $abr    = trim($_POST['abreviatura'] ?? '');
    $tipo   = in_array($_POST['tipo'] ?? '', ['peso','volumen','longitud','unidad','pack'])
              ? $_POST['tipo'] : 'unidad';

    if (!$nombre || !$abr) {
        echo json_encode(['ok' => false, 'error' => 'Nombre y abreviatura son obligatorios.']);
        exit;
    }

    if ($uid) {
        $db->prepare("UPDATE petshop_unidades SET nombre=?, abreviatura=?, tipo=? WHERE id=?")
           ->execute([$nombre, $abr, $tipo, $uid]);
        echo json_encode(['ok' => true, 'id' => $uid, 'nombre' => $nombre, 'abreviatura' => $abr, 'tipo' => $tipo]);
    } else {
        $db->prepare("INSERT INTO petshop_unidades (nombre, abreviatura, tipo) VALUES (?,?,?)")
           ->execute([$nombre, $abr, $tipo]);
        $nuevo_id = (int)$db->lastInsertId();
        echo json_encode(['ok' => true, 'id' => $nuevo_id, 'nombre' => $nombre, 'abreviatura' => $abr, 'tipo' => $tipo]);
    }
    exit;
}

// ── Eliminar ──
if ($pa === 'delete' || $pa === 'delete_unidad') {
    $uid = (int)($_POST['uid'] ?? 0);
    if (!$uid) {
        echo json_encode(['ok' => false, 'error' => 'ID inválido.']);
        exit;
    }
    // Verificar uso
    try {
        $en_uso = $db->prepare("SELECT COUNT(*) FROM petshop_productos WHERE unidad_id=? AND activo=1");
        $en_uso->execute([$uid]);
        $n = (int)$en_uso->fetchColumn();
        if ($n > 0) {
            echo json_encode(['ok' => false, 'error' => "No se puede eliminar: $n producto(s) usan esta unidad."]);
            exit;
        }
    } catch (Exception $e) {
        // Si la tabla petshop_productos no existe aún, ignorar
    }
    $db->prepare("UPDATE petshop_unidades SET activo=0 WHERE id=?")->execute([$uid]);
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Acción no reconocida: ' . htmlspecialchars($pa)]);
