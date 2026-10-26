# Báscula, impresión e integraciones

## Principio

Los navegadores no abren puertos industriales ni envían ZPL directamente a una impresora de red. La aplicación central se comunica con un servicio local controlado; cada dispositivo queda detrás de un contrato y su protocolo puede cambiar sin contaminar pesaje o inventario.

## Báscula

Contrato implementado en `ScaleAdapterInterface`:

- `connect()`
- `disconnect()`
- `readWeight()`
- `readStableWeight()`
- `getStatus()`
- `getDeviceInformation()`

Adapters disponibles:

| Adapter | Estado | Uso |
|---|---|---|
| `SimulatorScaleAdapter` | Funcional y marcado como simulado | Desarrollo/pruebas; bloqueado en producción por defecto |
| `LocalMiddlewareScaleAdapter` | Funcional contra contrato HTTP | Recomendado para dispositivo real |
| `Rs232ScaleAdapter` | Guard explícito | Exige protocolo/middleware confirmado |
| `UsbSerialScaleAdapter` | Guard explícito | Exige protocolo/middleware confirmado |
| `TcpScaleAdapter` | Guard explícito | Exige framing/protocolo confirmado |
| Contingencia manual | Flujo funcional sin adapter físico | Permiso separado, motivo obligatorio, sin reclamar captura automática |

### Contrato esperado del middleware

El adapter utiliza `POST /api/v1/scale/connect`, `GET /api/v1/scale/weight`, `GET /api/v1/scale/weight/stable`, `GET /api/v1/scale/status`, `GET /api/v1/scale/device` y `POST /api/v1/scale/disconnect`, autenticados con `X-API-Key`, sólo desde IP autorizada y sobre TLS cuando salga de localhost.

Respuesta JSON mínima:

```json
{
  "weight": 986.5,
  "unit": "kg",
  "stable": true,
  "captured_at": "2026-09-13T08:31:24-06:00",
  "scale_identifier": "BAS-01",
  "original_value": "ST,GS,986.500kg",
  "connection_status": "connected"
}
```

`original_value` es evidencia, no una instrucción. `POST /api/v1/scale/read-stable` guarda primero la captura y devuelve `id`; luego el pesaje debe presentar ese `scale_reading_id`. Dentro de la transacción el servidor bloquea la fila, comprueba mismo actor/cliente, alcance, vigencia, peso, raw/hash, timestamp, fuente y estabilidad, y la marca consumida. Una captura no puede reutilizarse y su vigencia inicial es 120 segundos mediante `scale.reading_max_age_seconds`.

Antes de activar el adapter real hay que documentar marca, modelo, firmware, conexión, baud/paridad/stop bits o host/puerto, delimitador, unidad, signo, mensajes estable/inestable/error y frecuencia.

### Configuración

```dotenv
integrations.scale.driver = middleware
integrations.scale.middlewareUrl = 'https://127.0.0.1:8787'
integrations.scale.apiKey = 'secreto-rotado'
integrations.scale.allowSimulatorInProduction = false
```

El registro `scales.configuration` aloja parámetros no secretos por equipo. Los secretos deben vivir en `.env`/vault; nunca en el catálogo visible.

La captura resuelve una báscula activa dentro de la instalación seleccionada. Sus campos `adapter_key` y `connection_type` deben coincidir exactamente con `integrations.scale.driver`; una estación no puede declarar `simulator` en catálogo y consumir accidentalmente un middleware/puerto distinto. Un driver desconocido falla de forma explícita.

### Criterios de aceptación en sitio

1. Cero, positivo, negativo/error, sobrecarga y desconexión.
2. Secuencia inestable → estable con timeout.
3. Unidad distinta de kg rechazada o convertida mediante regla aprobada.
4. Mensaje duplicado, lectura expirada, de otro actor o ya consumida no produce otro pesaje.
5. Calibración vencida visible/bloqueante según política.
6. Relojes NTP y trazabilidad del raw.
7. Recuperación al reiniciar agente/dispositivo.

## Impresión de etiquetas

Contrato implementado en `LabelPrinterInterface`:

- `printLabel()`
- `reprintLabel()`
- `getPrinterStatus()`
- `cancelPrintJob()`

| Adapter | Uso |
|---|---|
| `BrowserLabelPrinter` | Diálogo del navegador para contingencia local |
| `PdfLabelPrinter` | PDF recuperable fuera de `public` |
| `LocalMiddlewareLabelPrinter` | Entrega segura de HTML/ZPL al agente local |

La plantilla `TINA_RECEPCION_ZPL` existe y es versionada, pero la impresora `ZEBRA-PEND` se siembra inactiva. Activarla requiere validar ancho/dpi, lenguaje, conectividad, cola, encoding, QR, Code 128, tamaño real y comando de estado del modelo.

Los drivers físicos (`middleware`, `thermal` o `zpl`) exigen seleccionar una impresora activa de la instalación; no se admite un destino implícito. El servicio valida además compatibilidad entre driver, `printers.adapter_key` y formato: PDF/navegador usan HTML, Zebra usa ZPL y el middleware conserva explícitamente el formato recibido. Un driver desconocido o una combinación incompatible se rechaza antes de crear el trabajo.

### Contrato esperado del agente de impresión

`POST /v1/print-jobs` con identificador idempotente, impresora, formato, contenido y hash. Responde `job_id`, `accepted`, estado y mensaje saneado. El agente puede mantener spool de impresión, pero jamás confirma recepción o inventario.

### Historial

Cada intento guarda `label_id`, `template_id`, versión, `printer_id`, número de intento, reimpresión, motivo, formato, hash del payload, resultado, respuesta/error, usuario y fechas. Una reimpresión exige permiso y motivo; no crea una nueva unidad de inventario.

## QR y código de barras

La etiqueta genera QR y Code 128 con librerías de servidor. El frontend incluye ZXing para futuras capturas por cámara; los lectores tipo teclado funcionan sin integración especial. El payload QR debe contener identificador/versión y referencias, no secretos ni datos fiscales completos.

## API externa

- Base: `/api/v1`.
- JSON; respuesta consistente `{success,data,meta}` o `{success:false,error:{code,message,details}}`.
- Cabeceras: `X-API-CLIENT`, `X-API-KEY` e `Idempotency-Key` para POST/PUT de negocio.
- Permiso por endpoint y alcance por cliente.
- Rate limit por cliente/IP.
- Idempotencia ligada a propietario, método, ruta, clave y hash del payload; reutilizar una clave con payload distinto se rechaza.
- Especificación: `docs/api/openapi.yaml`.

## INVoiice/ERP

La integración fiscal sólo escribe `billing_references`. Debe enviar empresa, instalación, ID externo, tipo y estado, y opcionalmente orden física/UUID/fechas. La respuesta confirma siempre `inventory_changed=false`. Si el ERP requiere stock, consume endpoints de lectura o una exportación; no escribe proyecciones directamente.

## Eventos y observabilidad

`integration_endpoints` registra sistemas sin secretos en claro. `integration_events` conserva dirección, tipo, correlación, resultado, código HTTP, reintentos y payloads saneados. Categorías de log: `integrations`, `scales`, `printing`, `inventory`, `security`, `audit`, `application`, `errors`.

Reglas de log:

- Enmascarar API keys, Authorization, cookies, contraseñas, SMTP y connection strings.
- Guardar sólo últimos caracteres de referencias sensibles cuando baste.
- No almacenar binarios/CFDI completo en log general.
- Usar correlation ID de extremo a extremo.
- Alertar por desconexión sostenida, repetición, cola fallida y errores 5xx.

## Checklist de activación

- Contrato/protocolo firmado y muestra real capturada.
- Certificados y secretos instalados fuera del repositorio.
- Lista blanca y firewall.
- Health check visible en consola.
- Pruebas de carga, desconexión, reintento e idempotencia.
- Simulador deshabilitado en producción.
- PDF disponible como contingencia de impresión.
- Runbook y responsables por turno.
