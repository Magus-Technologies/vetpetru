<?php
/**
 * SunatService — Orquesta el flujo de facturación electrónica en DOS pasos.
 *
 *   1) generarXml($ventaId)   → llama /generar/comprobante, guarda XML+hash+qr,
 *                               deja sunat_estado = 'pendiente'.
 *   2) enviarSunat($ventaId)  → toma el XML guardado, llama /enviar/documento/electronico,
 *                               guarda CDR, deja sunat_estado = 'aceptado' | 'rechazado'.
 *
 * El nombre del archivo SUNAT no se persiste: se reconstruye con
 * {RUC}-{TIPO}-{SERIE}-{NUMERO_8}.
 */
require_once __DIR__ . '/SunatClient.php';
require_once __DIR__ . '/SunatBuilder.php';

class SunatService
{
    private PDO          $db;
    private SunatClient  $client;

    public function __construct(PDO $db, ?SunatClient $client = null)
    {
        $this->db     = $db;
        $this->client = $client ?? new SunatClient();
    }

    // ─── PASO 1: GENERAR XML ──────────────────────────────────────
    /**
     * @return array {ok: bool, mensaje: string, hash?: string, qr?: string}
     */
    public function generarXml(int $ventaId): array
    {
        $venta = $this->fetchVenta($ventaId);
        if (!$venta) {
            return ['ok' => false, 'mensaje' => "Venta #$ventaId no encontrada."];
        }
        if (!in_array($venta['tipo_comprobante'], ['factura', 'boleta'], true)) {
            return ['ok' => false, 'mensaje' => "Tipo '{$venta['tipo_comprobante']}' no se emite a SUNAT."];
        }

        $cliente = $this->fetchCliente((int) $venta['cliente_id']);
        $items   = $this->fetchItems($ventaId);

        try {
            $payload = SunatBuilder::buildComprobante($venta, $cliente, $items);
        } catch (Throwable $e) {
            $this->marcarRechazada($ventaId, $e->getMessage());
            return ['ok' => false, 'mensaje' => $e->getMessage()];
        }

        $gen = $this->client->generarComprobante($payload);
        if (empty($gen['estado'])) {
            $msg = $gen['mensaje'] ?? 'Error al generar XML.';
            $this->marcarRechazada($ventaId, $msg);
            return ['ok' => false, 'mensaje' => $msg, 'detalle' => $gen];
        }

        $hash   = $gen['data']['hash']          ?? '';
        $qrInfo = $gen['data']['qr_info']       ?? '';
        $xml    = $gen['data']['contenido_xml'] ?? '';

        $this->marcarPendiente($ventaId, $hash, $qrInfo, $xml);

        return [
            'ok'      => true,
            'mensaje' => 'XML generado correctamente. Listo para enviar a SUNAT.',
            'hash'    => $hash,
            'qr'      => $qrInfo,
        ];
    }

    // ─── PASO 2: ENVIAR A SUNAT ───────────────────────────────────
    /**
     * @return array {ok: bool, mensaje: string, cdr?: string}
     */
    public function enviarSunat(int $ventaId): array
    {
        $venta = $this->fetchVenta($ventaId);
        if (!$venta) {
            return ['ok' => false, 'mensaje' => "Venta #$ventaId no encontrada."];
        }
        if (empty($venta['sunat_xml'])) {
            return ['ok' => false, 'mensaje' => 'Esta venta no tiene XML generado todavía.'];
        }
        if ($venta['sunat_estado'] === 'aceptado') {
            return ['ok' => false, 'mensaje' => 'Esta venta ya fue aceptada por SUNAT.'];
        }

        $nombreArchivo = $this->nombreArchivo($venta);

        $env = $this->client->enviarDocumento([
            'ruc'                 => SUNAT_RUC,
            'usuario'             => SUNAT_USUARIO_SOL,
            'clave'               => SUNAT_CLAVE_SOL,
            'endpoint'            => SUNAT_ENDPOINT,
            'nombre_documento'    => $nombreArchivo,
            'contenido_documento' => $venta['sunat_xml'],
        ]);

        if (empty($env['estado'])) {
            $msg = $env['mensaje'] ?? 'Error al enviar a SUNAT.';
            $this->marcarRechazada(
                $ventaId, $msg,
                $venta['sunat_hash'] ?? '',
                $venta['sunat_qr']   ?? '',
                $venta['sunat_xml']  ?? ''
            );
            return ['ok' => false, 'mensaje' => $msg, 'detalle' => $env];
        }

        $this->marcarAceptada(
            $ventaId,
            $venta['sunat_hash'] ?? '',
            $venta['sunat_qr']   ?? '',
            $venta['sunat_xml']  ?? '',
            $env['cdr']     ?? '',
            $env['mensaje'] ?? 'ACEPTADO'
        );

        return [
            'ok'      => true,
            'mensaje' => 'Comprobante aceptado por SUNAT.',
            'cdr'     => $env['cdr'] ?? '',
        ];
    }

    // ─── NOTAS DE CRÉDITO/DÉBITO ─────────────────────────────────────

    public function generarXmlNota(int $notaId): array
    {
        $nota = $this->fetchNota($notaId);
        if (!$nota) return ['ok' => false, 'mensaje' => "Nota #$notaId no encontrada."];

        $ventaOrig = $this->fetchVenta((int) $nota['venta_id']);
        $cliente   = $this->fetchCliente((int) $ventaOrig['cliente_id']);
        $items     = $this->fetchItems((int) $nota['venta_id']);

        try {
            $payload = SunatBuilder::buildNota($nota, $ventaOrig, $cliente, $items);
        } catch (Throwable $e) {
            $this->marcarNotaEstado($notaId, 'rechazado', $e->getMessage());
            return ['ok' => false, 'mensaje' => $e->getMessage()];
        }

        $gen = $this->client->generarNota($payload);
        if (empty($gen['estado'])) {
            $msg = $gen['mensaje'] ?? 'Error al generar XML de nota.';
            $this->marcarNotaEstado($notaId, 'rechazado', $msg);
            return ['ok' => false, 'mensaje' => $msg, 'detalle' => $gen];
        }

        $hash   = $gen['data']['hash']          ?? '';
        $qrInfo = $gen['data']['qr_info']       ?? '';
        $xml    = $gen['data']['contenido_xml'] ?? '';

        $st = $this->db->prepare("
            UPDATE notas_credito SET
                sunat_estado='pendiente', sunat_hash=?, sunat_qr=?, sunat_xml=?,
                sunat_cdr=NULL, sunat_mensaje='XML generado, pendiente de envío.', sunat_fecha=NOW()
            WHERE id=?
        ");
        $st->execute([$hash, $qrInfo, $xml, $notaId]);

        return ['ok' => true, 'mensaje' => 'XML de nota generado. Listo para enviar.', 'hash' => $hash, 'qr' => $qrInfo];
    }

    public function enviarSunatNota(int $notaId): array
    {
        $nota = $this->fetchNota($notaId);
        if (!$nota) return ['ok' => false, 'mensaje' => "Nota #$notaId no encontrada."];
        if (empty($nota['sunat_xml'])) return ['ok' => false, 'mensaje' => 'Esta nota no tiene XML generado.'];
        if ($nota['sunat_estado'] === 'aceptado') return ['ok' => false, 'mensaje' => 'Esta nota ya fue aceptada por SUNAT.'];

        $tipoNota      = $nota['tipo_nota'] === 'credito' ? '07' : '08';
        $num           = str_pad((string)$nota['numero'], 8, '0', STR_PAD_LEFT);
        $nombreArchivo = SUNAT_RUC . '-' . $tipoNota . '-' . $nota['serie'] . '-' . $num;

        $env = $this->client->enviarDocumento([
            'ruc'                 => SUNAT_RUC,
            'usuario'             => SUNAT_USUARIO_SOL,
            'clave'               => SUNAT_CLAVE_SOL,
            'endpoint'            => SUNAT_ENDPOINT,
            'nombre_documento'    => $nombreArchivo,
            'contenido_documento' => $nota['sunat_xml'],
        ]);

        if (empty($env['estado'])) {
            $msg = $env['mensaje'] ?? 'Error al enviar nota a SUNAT.';
            $this->marcarNotaEstado($notaId, 'rechazado', $msg, $nota['sunat_xml'], $nota['sunat_hash'] ?? '', $nota['sunat_qr'] ?? '');
            return ['ok' => false, 'mensaje' => $msg, 'detalle' => $env];
        }

        $st = $this->db->prepare("
            UPDATE notas_credito SET
                sunat_estado='aceptado', sunat_hash=?, sunat_qr=?, sunat_xml=?,
                sunat_cdr=?, sunat_mensaje=?, sunat_fecha=NOW()
            WHERE id=?
        ");
        $st->execute([$nota['sunat_hash'] ?? '', $nota['sunat_qr'] ?? '', $nota['sunat_xml'],
                      $env['cdr'] ?? '', $env['mensaje'] ?? 'ACEPTADO', $notaId]);

        if ($nota['tipo_nota'] === 'credito') {
            $this->db->prepare("UPDATE ventas SET estado='anulado' WHERE id=?")
                     ->execute([$nota['venta_id']]);
        }

        return ['ok' => true, 'mensaje' => 'Nota aceptada por SUNAT.', 'cdr' => $env['cdr'] ?? ''];
    }

    public static function siguienteNumeroNota(PDO $db, string $serie): int
    {
        $st = $db->prepare("SELECT COALESCE(MAX(numero),0)+1 FROM notas_credito WHERE serie=?");
        $st->execute([$serie]);
        return (int) $st->fetchColumn();
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    private function nombreArchivo(array $venta): string
    {
        $tipo = $venta['tipo_comprobante'] === 'factura' ? '01' : '03';
        $num  = str_pad((string)$venta['numero'], 8, '0', STR_PAD_LEFT);
        return SUNAT_RUC . '-' . $tipo . '-' . $venta['serie'] . '-' . $num;
    }

    // ─── Persistencia ────────────────────────────────────────────────

    private function marcarPendiente(int $id, string $hash, string $qr, string $xml): void
    {
        $st = $this->db->prepare("
            UPDATE ventas SET
                sunat_estado='pendiente',
                sunat_hash=?,
                sunat_qr=?,
                sunat_xml=?,
                sunat_cdr=NULL,
                sunat_mensaje='XML generado, pendiente de envío.',
                sunat_fecha=NOW()
            WHERE id=?
        ");
        $st->execute([$hash, $qr, $xml, $id]);
    }

    private function marcarAceptada(int $id, string $hash, string $qr, string $xml, string $cdr, string $msg): void
    {
        $st = $this->db->prepare("
            UPDATE ventas SET
                sunat_estado='aceptado',
                sunat_hash=?,
                sunat_qr=?,
                sunat_xml=?,
                sunat_cdr=?,
                sunat_mensaje=?,
                sunat_fecha=NOW()
            WHERE id=?
        ");
        $st->execute([$hash, $qr, $xml, $cdr, $msg, $id]);
    }

    private function marcarRechazada(int $id, string $msg, string $hash = '', string $qr = '', string $xml = ''): void
    {
        $st = $this->db->prepare("
            UPDATE ventas SET
                sunat_estado='rechazado',
                sunat_hash=?,
                sunat_qr=?,
                sunat_xml=?,
                sunat_mensaje=?,
                sunat_fecha=NOW()
            WHERE id=?
        ");
        $st->execute([$hash, $qr, $xml, mb_substr($msg, 0, 1000), $id]);
    }

    // ─── Lecturas ────────────────────────────────────────────────────

    private function fetchVenta(int $id): ?array
    {
        $st = $this->db->prepare("SELECT * FROM ventas WHERE id=?");
        $st->execute([$id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    private function fetchCliente(int $id): array
    {
        $st = $this->db->prepare("SELECT * FROM clientes WHERE id=?");
        $st->execute([$id]);
        return $st->fetch() ?: [];
    }

    private function fetchItems(int $ventaId): array
    {
        $st = $this->db->prepare("SELECT * FROM venta_items WHERE venta_id=? ORDER BY id");
        $st->execute([$ventaId]);
        return $st->fetchAll();
    }

    private function fetchNota(int $id): ?array
    {
        $st = $this->db->prepare("SELECT * FROM notas_credito WHERE id=?");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    private function marcarNotaEstado(int $id, string $estado, string $mensaje = '', string $xml = '', string $hash = '', string $qr = ''): void
    {
        $st = $this->db->prepare("
            UPDATE notas_credito SET sunat_estado=?, sunat_mensaje=?, sunat_xml=?, sunat_hash=?, sunat_qr=?, sunat_fecha=NOW()
            WHERE id=?
        ");
        $st->execute([$estado, mb_substr($mensaje, 0, 1000), $xml, $hash, $qr, $id]);
    }
}
