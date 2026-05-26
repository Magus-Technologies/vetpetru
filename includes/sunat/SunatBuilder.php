<?php
/**
 * SunatBuilder — Construye el payload JSON que la API Laravel espera.
 *
 * Convierte los datos del dominio VetPro (venta, cliente, ítems) al formato
 * que pide GenerarComprobanteRequest. No habla con la red ni con la BD.
 */
class SunatBuilder
{
    /**
     * @param array $venta  Fila de la tabla `ventas` (con cliente embebido si se prefiere).
     * @param array $cliente Fila de la tabla `clientes`.
     * @param array $items  Filas de `venta_items`.
     * @return array Payload listo para POST /generar/comprobante.
     */
    public static function buildComprobante(array $venta, array $cliente, array $items): array
    {
        $tipo       = $venta['tipo_comprobante']; // factura | boleta
        $aplica_igv = !isset($venta['aplica_igv']) || (int)$venta['aplica_igv'] === 1;

        return [
            'endpoint'   => SUNAT_ENDPOINT,
            'documento'  => $tipo, // 'factura' o 'boleta'
            'empresa'    => self::empresa(),
            'cliente'    => self::cliente($cliente, $tipo),
            'serie'      => $venta['serie'],
            'numero'     => (string) $venta['numero'],
            'fecha_emision' => $venta['fecha'] ?? date('Y-m-d H:i:s'),
            'moneda'     => 'PEN',
            'forma_pago' => 'contado',
            'aplica_igv' => $aplica_igv,
            'detalles'   => self::detalles($items, $aplica_igv),
        ];
    }

    /** Datos de la empresa emisora desde config_sunat.php */
    private static function empresa(): array
    {
        return [
            'ruc'              => SUNAT_RUC,
            'usuario'          => SUNAT_USUARIO_SOL,
            'clave'            => SUNAT_CLAVE_SOL,
            'razon_social'     => SUNAT_RAZON_SOCIAL,
            'nombreComercial'  => SUNAT_NOMBRE_COMERCIAL,
            'direccion'        => SUNAT_DIRECCION,
            'ubigueo'          => SUNAT_UBIGEO,
            'distrito'         => SUNAT_DISTRITO,
            'provincia'        => SUNAT_PROVINCIA,
            'departamento'     => SUNAT_DEPARTAMENTO,
        ];
    }

    /**
     * Resuelve el documento del cliente según tipo de comprobante y documento.
     * Factura: requiere RUC (tipo_doc=6).
     * Boleta: DNI (1), Carné Extranjería (4), Pasaporte (7), o "varios" (0).
     */
    private static function cliente(array $cli, string $tipo): array
    {
        $ruc       = trim($cli['ruc'] ?? '');
        $dni       = trim($cli['dni'] ?? '');
        $ce        = trim($cli['ce'] ?? '');
        $pasaporte = trim($cli['pasaporte'] ?? '');
        $nom       = trim($cli['nombre'] ?? '');
        $dir       = trim($cli['direccion'] ?? '-');

        // Factura → requiere RUC
        if ($tipo === 'factura') {
            if ($ruc === '' || strlen($ruc) !== 11) {
                throw new RuntimeException("El cliente '$nom' no tiene RUC válido. Las facturas requieren RUC de 11 dígitos.");
            }
            return [
                'tipo_doc'    => '6',
                'num_doc'     => $ruc,
                'rzn_social'  => $nom,
                'direccion'   => $dir,
            ];
        }

        // Boleta → puede usar DNI, CE, Pasaporte, o "varios"
        if ($dni !== '' && strlen($dni) === 8) {
            return [
                'tipo_doc'    => '1',
                'num_doc'     => $dni,
                'rzn_social'  => $nom,
                'direccion'   => $dir,
            ];
        }

        if ($ce !== '' && strlen($ce) >= 9) {
            return [
                'tipo_doc'    => '4',
                'num_doc'     => $ce,
                'rzn_social'  => $nom,
                'direccion'   => $dir,
            ];
        }

        if ($pasaporte !== '') {
            return [
                'tipo_doc'    => '7',
                'num_doc'     => $pasaporte,
                'rzn_social'  => $nom,
                'direccion'   => $dir,
            ];
        }

        // Si no hay ningún documento → clientes varios
        return [
            'tipo_doc'    => '0',
            'num_doc'     => '00000000',
            'rzn_social'  => $nom !== '' ? $nom : 'CLIENTE VARIOS',
            'direccion'   => $dir,
        ];
    }

    /**
     * Transforma `venta_items` al formato esperado.
     * Cuando $aplica_igv=true, `precio_unitario` viene CON IGV incluido (Greenter
     * divide entre 1.18 internamente). Cuando $aplica_igv=false, se marca cada
     * item como inafecto (sin IGV).
     */
    private static function detalles(array $items, bool $aplica_igv = true): array
    {
        $out = [];
        foreach ($items as $i => $it) {
            $out[] = [
                'cod_producto' => (string) ($it['referencia_id'] ?? ($i + 1)),
                'unidad'       => 'NIU', // NIU=unidad, ZZ=servicio. NIU funciona para ambos en SUNAT beta.
                'descripcion'  => $it['descripcion'],
                'cantidad'     => (float) $it['cantidad'],
                'precio'       => (float) $it['precio_unitario'],
                'tipo_igv'     => $aplica_igv ? 'gravado' : 'inafecto',
            ];
        }
        return $out;
    }
}
