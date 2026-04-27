<?php
/**
 * SunatService — Orquesta el flujo completo de facturación electrónica.
 *
 *   generar XML  →  enviar a SUNAT  →  persistir resultado en `ventas`
 *
 * Es la única clase que `facturacion.php` debería tocar.
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

    /**
     * Procesa una venta ya guardada: genera el XML, lo envía a SUNAT,
     * y actualiza la fila de `ventas` con el resultado.
     *
     * @return array {ok: bool, mensaje: string, hash?: string, qr?: string}
     */
    public function emitir(int $ventaId): array
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

        // 1) Generar XML firmado
        $gen = $this->client->generarComprobante($payload);
        if (empty($gen['estado'])) {
            $msg = $gen['mensaje'] ?? 'Error al generar XML.';
            $this->marcarRechazada($ventaId, $msg);
            return ['ok' => false, 'mensaje' => $msg, 'detalle' => $gen];
        }

        $nombreArchivo = $gen['data']['nombre_archivo'] ?? '';
        $hash          = $gen['data']['hash']           ?? '';
        $qrInfo        = $gen['data']['qr_info']        ?? '';
        $xml           = $gen['data']['contenido_xml']  ?? '';

        // 2) Enviar a SUNAT
        $env = $this->client->enviarDocumento([
            'ruc'                 => SUNAT_RUC,
            'usuario'             => SUNAT_USUARIO_SOL,
            'clave'               => SUNAT_CLAVE_SOL,
            'endpoint'            => SUNAT_ENDPOINT,
            'nombre_documento'    => $nombreArchivo,
            'contenido_documento' => $xml,
        ]);

        if (empty($env['estado'])) {
            $msg = $env['mensaje'] ?? 'Error al enviar a SUNAT.';
            $this->marcarRechazada($ventaId, $msg, $hash, $qrInfo, $xml);
            return ['ok' => false, 'mensaje' => $msg, 'detalle' => $env];
        }

        // 3) Persistir éxito
        $this->marcarAceptada($ventaId, $hash, $qrInfo, $xml, $env['cdr'] ?? '', $env['mensaje'] ?? 'ACEPTADO');

        return [
            'ok'      => true,
            'mensaje' => 'Comprobante aceptado por SUNAT.',
            'hash'    => $hash,
            'qr'      => $qrInfo,
            'archivo' => $nombreArchivo,
        ];
    }

    // ─── Persistencia ────────────────────────────────────────────────

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
}
