<?php
/* ============================================================================
 * VetPro — Cron de recordatorios de cita por WhatsApp
 * ----------------------------------------------------------------------------
 * Envía un recordatorio a las citas que ocurren MAÑANA y que aún no tienen
 * recordatorio enviado. Marca recordatorio_enviado=1 para no repetir.
 *
 * Se ejecuta por cron (ver instrucción al final). No es accesible por web.
 *
 * Uso manual de prueba:   php /ruta/cron_recordatorios_wa.php
 * ==========================================================================*/

// Evitar ejecución por navegador
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('Solo CLI'); }

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/wa_notify.php';

$db = getDB();

// Citas de MAÑANA, pendientes/confirmadas, sin recordatorio enviado
$sql = "SELECT ci.id, ci.fecha, ci.hora,
               m.nombre AS mascota, u.nombre AS vet,
               c.nombre AS dueno, c.telefono
        FROM citas ci
        JOIN mascotas m ON m.id = ci.mascota_id
        JOIN usuarios u ON u.id = ci.veterinario_id
        JOIN clientes c ON c.id = m.cliente_id
        WHERE ci.fecha = CURDATE()
          AND ci.estado IN ('pendiente','confirmada')
          AND ci.recordatorio_enviado = 0";
$citas = $db->query($sql)->fetchAll();

if (!$citas) { echo date('Y-m-d H:i') . " — Sin recordatorios pendientes.\n"; exit; }

$cfg = $db->query("SELECT clave,valor FROM configuracion")->fetchAll(PDO::FETCH_KEY_PAIR);
$clinica = trim($cfg['nombre_clinica'] ?? $cfg['clinica_nombre'] ?? 'VetPro') ?: 'VetPro';

$marcar = $db->prepare("UPDATE citas SET recordatorio_enviado=1 WHERE id=?");
$enviados = 0;

foreach ($citas as $c) {
    if (empty($c['telefono'])) continue;
    $texto = wa_msg_recordatorio($clinica, $c['dueno'], $c['mascota'], $c['fecha'], $c['hora'], $c['vet']);
    if (wa_enviar($c['telefono'], $texto)) {
        $marcar->execute([$c['id']]);
        $enviados++;
        // Pequeña pausa entre mensajes (buena práctica anti-spam)
        sleep(3);
    }
}

echo date('Y-m-d H:i') . " — Recordatorios enviados: {$enviados} de " . count($citas) . ".\n";

/* ─────────────────────────────────────────────────────────────────────────
 * INSTALAR EN CRON (ejecuta cada día a las 9:00 am):
 *   crontab -e
 *   0 9 * * *  /usr/bin/php /ruta/a/vetpro/cron_recordatorios_wa.php >> /var/log/vetpro_wa.log 2>&1
 * Ajusta /ruta/a/vetpro/ a la ruta real de tu proyecto.
 * ───────────────────────────────────────────────────────────────────────── */
