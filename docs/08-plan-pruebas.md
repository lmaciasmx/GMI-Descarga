# Plan de pruebas

## Estrategia

La pirámide combina pruebas unitarias de reglas puras, pruebas de integración contra MySQL 8 y validación de estación/dispositivo en sitio. No se usa SQLite para probar transacciones porque `FOR UPDATE`, checks, collations e idempotencia deben comportarse como producción.

## Automatización incluida

| Suite | Cobertura |
|---|---|
| `WeightCalculatorTest` | bruto, tara, neto físico, castigo y neto aceptado; entradas inválidas |
| `LotPeriodStrategyTest` | mes/día juliano y componentes de lote |
| `QualityRangeEvaluatorTest` | rangos y detección de traslape |
| `StateMachineTest` | transiciones válidas e imposibles |
| `PermissionMatrixTest` | privilegios y separación fiscal/inventario |
| `SimulatorScaleAdapterTest` | contrato de lectura simulada y estabilidad |
| `WeighingServiceDatabaseTest` | captura reclamada, anti-manipulación, no reutilización, expiración, timestamp, RSA, pesos separados y rollback |
| `InventoryServiceDatabaseTest` | recepción, duplicidad, rollback, reserva concurrente, surtido, expiración y reverso |
| `InventoryCountDatabaseTest` | conciliación y rechazo de fotografía obsoleta |
| `TransferAndQualityDatabaseTest` | tránsito entre instalaciones, claves/pesos de recepción, diferencia y calidad independiente |
| `IntegrationGuardsDatabaseTest` | fingerprint de báscula e idempotencia única |
| `DischargePlanDatabaseTest` | composición certificada, capacidad, RSA, procedencias sin duplicar, cierre/cancelación de planes y mareas |
| `BillingIsolationDatabaseTest` | emisión/cancelación sin stock y aislamiento de alcance |

Factories y seeders proporcionan datos coherentes; `DemoOperationalSeeder` está identificado como demo.

## Ejecución local

Crear una base exclusiva `grupomar_operaciones_test` y un usuario de pruebas. Nunca apuntar PHPUnit a producción.

```bash
composer install
npm ci
npm run check
npm run build
vendor/bin/phpcs --standard=PSR12 app tests
vendor/bin/phpunit
```

La CI de `.github/workflows/quality.yml` ejecuta PHP 8.2 y MySQL 8.4 en cada push/PR.

## Casos críticos de aceptación

### Recepción e inventario

- Confirmar dos pesajes válidos crea un encabezado, dos detalles, dos asientos, dos unidades y dos historiales de ubicación.
- Fallar la segunda línea revierte también la primera.
- Repetir el pesaje rechaza duplicidad y no crea un documento adicional.
- Libro mayor y proyección concilian por unidad, lote, ubicación y alcance.
- Documento confirmado no cambia; reverso conserva original y genera compensación.
- Reversar una recepción cambia su estado, luego permite reversar el pesaje, cancela la etiqueta y habilita una nueva captura sin borrar historial.
- Un rechazo de calidad continúa contado como existencia física/capacidad, pero no como disponible.

### Marea y planeación

- Secuencias de composición y tanques de procedencia repetidos se rechazan con rollback.
- No se finaliza un plan con tinas en llenado o pendientes de pesaje.
- Un plan con cualquier operación de tina no se cancela; una cancelación válida exige motivo.
- Una marea no cierra con planes abiertos y exige al menos un plan finalizado.
- Cancelar una marea sin operación cancela sus planes abiertos en la misma transacción y conserva el motivo en auditoría.

### Concurrencia

- Dos sesiones intentan reservar la misma disponibilidad: sólo una confirma.
- Conteo iniciado, luego movimiento, luego conciliación: se rechaza por snapshot obsoleto.
- Dos recepciones del mismo pesaje: una confirma, otra recibe conflicto.
- Dos requests con misma idempotency key/payload devuelven el primer resultado.
- Misma clave con payload distinto se rechaza.

Ejecutar carreras con dos conexiones MySQL independientes y barrera sincronizada; una prueba secuencial no sustituye este escenario.

### Pesaje

- Lectura inestable no confirma automáticamente.
- Raw/hash repetido no duplica lectura.
- Un peso/raw/timestamp/fuente distinto de la captura se rechaza y no consume evidencia.
- Sólo el actor que capturó puede confirmar; una captura expirada o ya consumida se rechaza.
- Tara catálogo se fotografía aunque luego cambie el catálogo.
- Pintada/estimada sin motivo se rechaza.
- Bruto <= tara, castigo fuera de rango o peso no numérico se rechazan.
- Captura manual exige permiso y queda marcada.

### Calidad

- Defecto que exige hold produce retención separada.
- Aprobación libera/rechaza mediante movimiento independiente.
- Regla automática apagada exige grado manual.
- Activar rangos traslapados falla la validación de configuración/prueba.

### Solicitud, reserva y surtido

- No reservar solicitud sin autorización.
- No exceder saldo pedido ni disponibilidad.
- Coincidir especie/talla/calidad.
- Reserva no cambia existencia; surtido reduce existencia+reserva en la misma transacción.
- Reserva vencida no se surte.
- Liberación exige motivo y recalcula estado.

### Traspaso

- Sólo unidad completa, disponible y no reservada se embarca.
- Al embarcar queda `in_transit`, sin disponibilidad en origen.
- Destino/ubicación pertenecen al almacén solicitado.
- Diferencia al recibir retiene y marca discrepancia.
- Una recepción entre instalaciones cambia el `site_id` de la unidad junto con almacén/ubicación; claves desconocidas, repetidas o pesos inválidos rechazan todo el documento.

### Venta y fiscal

- Orden no cambia ni reserva inventario.
- Despacho vuelve a validar y genera salida física.
- CFDI emitido/cancelado no altera documentos, movimientos o saldo.
- Orden/referencia de otra empresa o instalación se rechaza.

### Seguridad

- Endpoint sin login/clave, permiso o scope devuelve 401/403.
- CSRF ausente en internal API se rechaza.
- Registro público no existe.
- Rate limit de login/API responde 429.
- XSS de catálogos/observaciones se muestra escapado.
- Inyección SQL en filtros no cambia query.
- Configuración sensible no se expone en UI/log.

## Pruebas de estación

En tablet/PC industrial verificar tacto, teclado, lector tipo HID, contraste, doble click, pérdida/restablecimiento de red, banner offline, resolución mínima objetivo y tiempos de respuesta. Pesaje debe mostrar siempre tina, tara/fuente, bruto, netos, producto, tanques, barco, marea, lote, RSA, castigo, calidad y estado de báscula/impresora/red.

## Pruebas de dispositivo

Se ejecutan sólo con protocolo confirmado: 100 lecturas estables, ruido/inestabilidad, cable desconectado, timeout, reinicio, mensaje truncado, unidad inesperada, repetición y reloj desfasado. Para Zebra: 100 trabajos, reimpresión, sin papel, cabezal abierto, cola caída, caracteres, dimensiones, QR y barras escaneables.

## Rendimiento

Datos mínimos de ensayo: 5 años, 2 millones de movimientos, 250 mil unidades, 100 usuarios, 20 estaciones y 10 integraciones. Objetivos iniciales a validar con negocio:

- pantalla operativa p95 < 2 s en intranet;
- confirmación crítica p95 < 3 s sin dispositivo;
- búsqueda por marbete < 500 ms;
- cero deadlocks no reintentados en carga nominal;
- exportaciones grandes asíncronas si superan 10 mil filas.

## Salida a producción

Se exige: suites verdes, UAT firmado, simulador deshabilitado, protocolo/dispositivo certificados, restauración ensayada, cero vulnerabilidades críticas/altas aceptadas sin plan, conciliación de datos en cero, monitoreo activo y runbook por turno.
