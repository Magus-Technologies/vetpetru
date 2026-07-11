# Integración SUNAT — Contexto reutilizable

> Patrón usado en **VetPro** (PHP puro, sin frameworks) para emitir comprobantes electrónicos
> contra una **API Laravel externa** que envuelve la librería **Greenter**.
> Diseñado para copiar/pegar como contexto al iniciar la integración en otro proyecto.

---

## 1. Arquitectura general

```
┌────────────────────┐    HTTP/JSON    ┌──────────────────────┐    SOAP    ┌────────┐
│  Proyecto PHP      │  ────────────►  │  API Laravel         │  ────────► │ SUNAT  │
│  (VetPro)          │                 │  (api-sunat-laravel) │  ◄──────── │ (beta/ │
│                    │  ◄────────────  │  + Greenter          │    CDR     │  prod) │
└────────────────────┘                 └──────────────────────┘            └────────┘
```

- El proyecto PHP **no** firma XML ni habla con SUNAT directamente.
- La API Laravel expone 2 endpoints:
  - `POST /api/v1/generar/comprobante` → recibe payload JSON, devuelve XML firmado + hash + qr_info.
  - `POST /api/v1/enviar/documento/electronico` → recibe el XML y lo envía a SUNAT, devuelve CDR.
- En entornos: `http://api-sunat-laravel.test/api/v1` (local) y `http://<server>/api-sunat-laravel/api/v1` (prod).

### Flujo elegido en VetPro (de 2 pasos, **manual**)

1. Al guardar la venta → se llama `generar/comprobante` y se persiste el XML (`sunat_estado = 'pendiente'`).
2. El usuario revisa el comprobante y, con un botón, dispara `enviar/documento/electronico`.
3. Si aceptado → `sunat_estado = 'aceptado'` + se guarda el CDR (zip base64).

Beneficio: si hay un error de datos del cliente, se anula localmente sin haber molestado a SUNAT.

---

## 2. Esquema de BD

Migración SQL (añade columnas a la tabla principal de comprobantes):

```sql
ALTER TABLE `ventas`
    ADD COLUMN `sunat_estado`  ENUM('pendiente','aceptado','rechazado') NULL DEFAULT NULL AFTER `estado`,
    ADD COLUMN `sunat_hash`    VARCHAR(255) NULL DEFAULT NULL AFTER `sunat_estado`,
    ADD COLUMN `sunat_qr`      TEXT NULL                       AFTER `sunat_hash`,
    ADD COLUMN `sunat_xml`     LONGTEXT NULL                   AFTER `sunat_qr`,
    ADD COLUMN `sunat_cdr`     LONGTEXT NULL                   AFTER `sunat_xml`,
    ADD COLUMN `sunat_mensaje` VARCHAR(1000) NULL              AFTER `sunat_cdr`,
    ADD COLUMN `sunat_fecha`   DATETIME NULL                   AFTER `sunat_mensaje`;
```

- `sunat_xml` → guarda el XML firmado entero (LONGTEXT).
- `sunat_cdr` → CDR en base64 tal como llega de la API (LONGTEXT). Al descargar se decodifica a ZIP.
- `sunat_qr` → string `qr_info` que devuelve la API (formato SUNAT: `RUC|TIPO|SERIE|NUM|IGV|TOTAL|FECHA|TIPO_DOC_CLI|NUM_DOC_CLI|HASH`).
- No se persiste `nombre_archivo`: se reconstruye con `{RUC}-{TIPO}-{SERIE}-{NUMERO_8}` (ej: `20000000001-03-B001-00000001`). Códigos de tipo: factura=`01`, boleta=`03`.

---

## 3. Estructura de archivos

```
includes/
├── config.php                 # detecta entorno (local vs prod) por hostname
├── config_sunat.php           # constantes SUNAT (URL API, RUC, credenciales SOL, series)
└── sunat/
    ├── SunatClient.php        # wrapper cURL → /generar y /enviar
    ├── SunatBuilder.php       # arma el payload JSON desde venta + cliente + items
    └── SunatService.php       # orquestador: generarXml() y enviarSunat()
modules/
└── facturacion.php            # UI + POST handlers (save, enviar_sunat, action=xml/cdr)
admin/
└── test_sunat.php             # script de prueba aislado (NO requiere BD)
migrations/
└── 001_sunat_columns_ventas.sql
```

---

## 4. `config_sunat.php` (auto-detección de entorno)

```php
<?php
$__host = $_SERVER['HTTP_HOST'] ?? gethostname();
$__isLocal = (
    str_contains($__host, 'localhost') ||
    str_contains($__host, '127.0.0.1') ||
    str_contains($__host, '.test')     ||
    str_contains($__host, '.local')
);

if ($__isLocal) {
    define('SUNAT_API_URL', 'http://api-sunat-laravel.test/api/v1');
} else {
    define('SUNAT_API_URL', 'http://<TU_SERVIDOR>/api-sunat-laravel/api/v1');
}

define('SUNAT_API_TIMEOUT', 60);
define('SUNAT_ENDPOINT',    'beta');     // 'beta' | 'produccion'

// Credenciales SOL del RUC de pruebas SUNAT
define('SUNAT_RUC',         '20000000001');
define('SUNAT_USUARIO_SOL', 'MODDATOS');
define('SUNAT_CLAVE_SOL',   'MODDATOS');

// Datos del emisor
define('SUNAT_RAZON_SOCIAL',     'EMPRESA DE PRUEBAS S.A.C.');
define('SUNAT_NOMBRE_COMERCIAL', 'TuMarca');
define('SUNAT_DIRECCION',        'AV. PRUEBA 123');
define('SUNAT_UBIGEO',           '150101');
define('SUNAT_DISTRITO',         'LIMA');
define('SUNAT_PROVINCIA',        'LIMA');
define('SUNAT_DEPARTAMENTO',     'LIMA');

define('SUNAT_SERIE_FACTURA', 'F001');
define('SUNAT_SERIE_BOLETA',  'B001');
```

---

## 5. `SunatClient.php` (HTTP cURL)

```php
class SunatClient {
    public function generarComprobante(array $payload): array {
        return $this->post('/generar/comprobante', $payload);
    }
    public function enviarDocumento(array $payload): array {
        return $this->post('/enviar/documento/electronico', $payload);
    }
    private function post(string $path, array $payload): array {
        $ch = curl_init(SUNAT_API_URL . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => SUNAT_API_TIMEOUT,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($err) return ['estado'=>false, 'mensaje'=>"cURL: $err", 'http'=>$http];
        $json = json_decode($body, true);
        return is_array($json) ? $json + ['http'=>$http] : ['estado'=>false, 'mensaje'=>'Respuesta no JSON', 'raw'=>$body, 'http'=>$http];
    }
}
```

---

## 6. `SunatBuilder.php` — payload `/generar/comprobante`

Estructura clave del JSON (la API Laravel/Greenter lo espera así):

```json
{
  "tipoDoc": "03",                       // 01=factura, 03=boleta
  "serie":   "B001",
  "correlativo": "1",
  "fechaEmision": "2026-04-26T15:00:00-05:00",
  "tipoMoneda": "PEN",

  "company": {
    "ruc": "20000000001",
    "razonSocial": "EMPRESA DE PRUEBAS S.A.C.",
    "nombreComercial": "VetPro",
    "address": { "ubigueo":"150101", "departamento":"LIMA", "provincia":"LIMA",
                 "distrito":"LIMA", "direccion":"AV. PRUEBA 123" }
  },

  "client": {
    "tipoDoc": "1",                      // 1=DNI, 6=RUC, 0=varios (genérico boleta)
    "numDoc":  "12345678",
    "rznSocial": "JUAN PEREZ",
    "address": { "direccion":"AV. CLIENTE 999" }   // opcional
  },

  "mtoOperGravadas": 100.00,             // base imponible (sin IGV)
  "mtoIGV":          18.00,
  "valorVenta":      100.00,
  "subTotal":        118.00,
  "mtoImpVenta":     118.00,             // total

  "details": [
    {
      "codProducto": "1",
      "unidad": "NIU",                   // NIU = unidad / ZZ = servicio
      "cantidad": 1,
      "descripcion": "CONSULTA VETERINARIA",
      "mtoBaseIgv": 50.00,
      "porcentajeIgv": 18,
      "igv": 9.00,
      "tipAfeIgv": "10",                 // 10 = gravado onerosa
      "totalImpuestos": 9.00,
      "mtoValorVenta": 50.00,
      "mtoValorUnitario": 50.00,
      "mtoPrecioUnitario": 59.00         // CON IGV
    }
  ],

  "legends": [{ "code": "1000", "value": "SON CIENTO DIECIOCHO CON 00/100 SOLES" }]
}
```

Reglas que aprendí en VetPro:
- Para **boleta a "varios"** (cliente sin documento): `tipoDoc: "0"`, `numDoc: "00000000"`, `rznSocial: "VARIOS"`.
- **Factura siempre requiere RUC** del cliente (`tipoDoc: "6"`).
- **Boleta con RUC 10** (persona natural con negocio) es válida: se envía `tipoDoc: "6"` cuando el cliente no tiene DNI/CE/pasaporte. Ojo: la boleta no da crédito fiscal.
- **Boleta con RUC 20 se rechaza** (validación en `SunatBuilder::cliente()` y en el form de facturación): a una persona jurídica le corresponde factura (Reglamento de Comprobantes de Pago, art. 4).
- `precio_unitario` en la BD se asume **CON IGV incluido**. La división base/IGV se hace en el builder: `valor = precio / 1.18`, `igv = precio - valor`.
- `unidad: "NIU"` funciona tanto para servicios como productos (la SUNAT lo acepta).

---

## 7. `SunatService.php` — orquestador en 2 pasos

```php
class SunatService {
    public function __construct(private PDO $db, private ?SunatClient $client = null) {
        $this->client ??= new SunatClient();
    }

    // PASO 1 — genera XML, guarda en BD con estado 'pendiente'. NO envía.
    public function generarXml(int $ventaId): array {
        // 1. fetch venta + cliente + items
        // 2. payload = SunatBuilder::buildComprobante(...)
        // 3. $gen = $this->client->generarComprobante($payload)
        // 4. UPDATE ventas SET sunat_estado='pendiente', sunat_xml=?, sunat_hash=?, sunat_qr=?
        // return ['ok'=>true|false, 'mensaje'=>..., 'hash'=>..., 'qr'=>...]
    }

    // PASO 2 — toma el XML guardado y lo envía a SUNAT.
    public function enviarSunat(int $ventaId): array {
        // 1. fetch venta (debe tener sunat_xml y NO estar 'aceptado')
        // 2. nombreArchivo = {RUC}-{01|03}-{serie}-{num_8}
        // 3. $env = $this->client->enviarDocumento([... 'contenido_documento' => $venta['sunat_xml']])
        // 4. si ok → UPDATE sunat_estado='aceptado', sunat_cdr=?, sunat_mensaje=?
        //    si no → UPDATE sunat_estado='rechazado', sunat_mensaje=?
        // return ['ok'=>true|false, 'mensaje'=>..., 'cdr'=>...]
    }
}
```

---

## 8. UI — patrón en `facturacion.php`

### Orden crítico del archivo

```php
<?php
$page = 'facturacion';
require_once __DIR__ . '/../includes/config.php';
$db   = getDB();
$user = getUser();
$action = $_GET['action'] ?? 'list';

// 1) POST handler PRIMERO (necesita header() limpio para redirects)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($pa === 'save') { /* ... INSERT venta ...
        if (factura|boleta) $sunat->generarXml($ventaId);          // ← solo paso 1
        header('Location: ?action=ver&id=...&sunat=xml_ok'); exit;
    }
    if ($pa === 'enviar_sunat') {
        $r = $sunat->enviarSunat((int)$_POST['id']);                // ← paso 2 manual
        header('Location: ?action=ver&id=...&sunat='.($r['ok']?'env_ok':'env_err')); exit;
    }
}

// 2) Endpoints GET binarios (XML/CDR) ANTES de header.php
if ($action === 'xml' && !empty($_GET['id'])) {
    /* SELECT sunat_xml; header('Content-Type: application/xml'); echo $xml; exit; */
}
if ($action === 'cdr' && !empty($_GET['id'])) {
    /* base64_decode($cdr); header('Content-Type: application/zip'); echo $bin; exit; */
}

// 3) AHORA sí HTML
require_once __DIR__ . '/../includes/header.php';
// ... vista ...
```

### Botones por estado en la lista

| `sunat_estado` | Botones a mostrar             |
|----------------|-------------------------------|
| `null` (no factura/boleta) | — |
| `pendiente` (XML generado) | 📄 Ver XML, 📤 Enviar a SUNAT |
| `aceptado`  | 📄 Ver XML, ⬇ Descargar CDR |
| `rechazado` | 📄 Ver XML, 📤 Reintentar envío |

---

## 9. Script de prueba aislado (`admin/test_sunat.php`)

Antes de cablear nada en la BD, vale oro un script que:
1. Arma un payload con datos hardcodeados (cliente DNI 12345678, items dummy).
2. Llama `generar/comprobante`.
3. Toma el XML y llama `enviar/documento/electronico`.
4. Imprime HTTP, estado, mensaje y primeros 80 chars del CDR.

Si esto funciona en local contra la API → la integración con BD es trivial.

⚠️ **No olvidar protegerlo** en producción (auth o eliminar el archivo).

---

## 10. Datos de prueba (SUNAT beta)

| Campo            | Valor          |
|------------------|----------------|
| RUC              | `20000000001`  |
| Usuario SOL      | `MODDATOS`     |
| Clave SOL        | `MODDATOS`     |
| Endpoint         | `beta`         |
| Cliente DNI test | `12345678`     |
| Cliente RUC test | `20123456789`  |

Para producción: cambiar `SUNAT_RUC`, credenciales SOL, y `SUNAT_ENDPOINT='produccion'`. La API Laravel debe tener cargado el certificado digital del RUC real.

---

## 11. Errores comunes que vimos

- **"Headers already sent"** → estás incluyendo `header.php` antes del POST handler. Mover el handler arriba.
- **`unknown method emitir()`** → en algún momento tuvimos un único `emitir()` que hacía los 2 pasos juntos; se separó en `generarXml()` + `enviarSunat()`. Migrar callers.
- **`*.sql` no se sincroniza por git** → `.gitignore` los bloquea; añadir excepción `!migrations/*.sql`.
- **CDR llega vacío** → la API devolvió 200 pero `estado=false`; revisar `mensaje` y `errors[]`.
- **Boleta a varios rechazada** → revisar que `tipoDoc:"0"` + `numDoc:"00000000"` (8 ceros, no 11).

---

## 12. Prompt sugerido para usar en otro proyecto

> "Tengo un proyecto en PHP/Laravel/etc que necesita emitir facturas y boletas electrónicas a SUNAT (Perú).
> Voy a usar el mismo patrón que ya implementé en VetPro: una API Laravel externa con Greenter que firma y
> envía, y mi proyecto solo le habla por HTTP. El flujo es de 2 pasos: primero `generarXml()` que guarda el
> XML firmado en la tabla con estado `pendiente`, y segundo `enviarSunat()` (botón manual) que toma ese XML
> y lo manda a SUNAT, guarda el CDR y deja estado `aceptado`/`rechazado`.
>
> Lee el archivo `SUNAT_INTEGRATION.md` adjunto y replica:
> 1. La migración de columnas `sunat_*` en la tabla de comprobantes de este proyecto.
> 2. Los 3 helpers (`SunatClient`, `SunatBuilder`, `SunatService`) adaptados a la estructura de este proyecto.
> 3. Las 2 acciones POST (`save` que solo genera XML, `enviar_sunat` manual) y los endpoints GET para
>    descargar XML/CDR.
> 4. Un script `test_sunat.php` aislado para verificar la conexión con la API antes de cablear la BD."
