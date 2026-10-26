# Catálogo de estados y reglas de negocio

## Estados controlados por máquina

### Viaje/marea

| Estado | Puede pasar a | Condición |
|---|---|---|
| `planned` | `unloading`, `canceled` | Iniciar exige al menos un plan aprobado; cancelar exige motivo y ausencia de operación |
| `unloading` | `closed`, `canceled` | Cerrar exige planes sin pendientes, al menos uno finalizado y ninguna tina por llenar/pesar; cancelar sólo procede sin operaciones |
| `closed` | — | Terminal |
| `canceled` | — | Terminal |

Al iniciar un plan, la marea planeada entra automáticamente a `unloading`. Cancelar una marea sin operación cancela atómicamente sus planes abiertos; una marea con plan finalizado se cierra, no se cancela.

### Plan de descarga

| Estado | Puede pasar a | Condición |
|---|---|---|
| `draft` | `approved`, `canceled` | Para aprobar: RSA activo/vigente y composición válida; cancelar exige motivo |
| `approved` | `in_progress`, `canceled` | Aprobación registrada; cancelar sólo antes de operar tinas |
| `in_progress` | `paused`, `finished`, `canceled` | Finalizar exige no tener tinas en llenado/pendientes de pesaje; cancelar sólo sin operación y con motivo |
| `paused` | `in_progress`, `canceled` | Reanudación explícita; cancelar sólo sin operación y con motivo |
| `finished` | — | Terminal |
| `canceled` | — | Terminal |

### Tina

`available → assigned → filling → pending_weighing → weighed → received → empty → available`. Desde disponible o vacía puede ir a mantenimiento; mantenimiento a disponible/inactiva. Las regresiones operativas sólo se permiten donde la máquina las declara.

### Pesaje

`draft → confirmed → pending_inspection/received → reversed`. Un pesaje recibido puede reversarse únicamente después de compensar su recepción; nunca se edita a borrador. El reverso cancela sus etiquetas vigentes, devuelve la tina/llenado a `pending_weighing` y deja libre la unicidad para una nueva captura. Sólo un registro por cadena permanece `is_current=1`.

### Unidad de inventario

| Estado | Significado | Disponibilidad |
|---|---|---|
| `received` | Ingreso confirmado pendiente de ubicación/disposición | No |
| `located` | Ubicada, pendiente de habilitar | No |
| `available` | Utilizable; disponible = existencia - reserva | Sí |
| `held` | Retenida por calidad/diferencia | No |
| `reserved` | Existencia comprometida total/parcial | Sólo remanente según proyección |
| `in_transit` | Fuera del origen y aún no recibida | No |
| `dispatched` | Salida total por venta | No, terminal |
| `rejected` | Rechazada por calidad | No, terminal |
| `exhausted` | Saldo físico cero | No, terminal |

### Solicitud de materia prima

`requested → authorized → reserved → partially_fulfilled → fulfilled`. Una liberación total sin surtido devuelve `reserved → authorized`; rechazo/cancelación exige transición válida. `fulfilled`, `rejected` y `canceled` son terminales.

### Reserva

| Estado | Evento |
|---|---|
| `active` | Unidades comprometidas y vigencia futura |
| `fulfilled` | Surtido transaccional completado |
| `released` | Compromiso liberado con motivo |
| `expired` | Reservada para proceso automático futuro; hoy una reserva vencida se rechaza y se libera explícitamente |

### Traspaso

`requested → authorized → prepared → shipped → in_transit → received`. Una diferencia lleva `in_transit → discrepancy`; después de conciliación puede recibirse. Cancelación sólo antes del embarque.

La recepción valida cada unidad bloqueada contra el origen y el destino. En un traspaso entre instalaciones actualiza conjuntamente empresa, instalación, almacén y ubicación de la proyección; una diferencia de peso deja la unidad retenida. Los pesos recibidos no pueden repetir claves ni incluir unidades ajenas al documento.

## Tipos de movimiento

| Código | Delta físico | Delta reservado | Caso de uso |
|---|---:|---:|---|
| `receipt` | + | 0 | Nacimiento de unidad en frigorífico |
| `relocation` | 0 | 0 | Cambio de ubicación/almacén permitido |
| `transfer_shipment` | 0 | 0 | Estado a tránsito; conserva custodia física |
| `transfer_receipt` | 0 o diferencia controlada | 0 | Entrada a destino y conciliación |
| `reservation` | 0 | + | Compromiso de unidad específica |
| `release` | 0 | - | Liberación con motivo |
| `production_issue` | - | - cuando surte reserva | Consumo de producción |
| `return` | + | 0 | Devolución física |
| `sale_dispatch` | - | 0 | Despacho de venta |
| `loss` | - | 0 | Merma con motivo |
| `adjustment_increase` | + | 0 | Ajuste positivo autorizado |
| `adjustment_decrease` | - | 0 | Ajuste negativo autorizado |
| `quality_hold` | 0 | 0 | Retención independiente |
| `quality_release` | 0 | 0 | Liberación de calidad |
| `quality_reject` | 0 | 0 | Rechazo terminal |
| `reversal` | inverso | inverso | Compensación del documento original |

El reverso genérico se limita a recepción, reubicación, devolución, merma y ajustes. Reservas se liberan desde su caso de uso; una salida a producción o venta se corrige con devolución autorizada; traspasos y calidad se concilian en su flujo. Esto evita cambiar el ledger dejando desincronizado el documento operativo que lo originó.

Una unidad `rejected` no está disponible para reserva, producción, venta o traspaso, pero sigue siendo existencia física y consume capacidad mientras tenga saldo. Sólo puede reubicarse en el mismo almacén o disminuirse mediante merma/ajuste autorizado; una disminución parcial conserva el rechazo.

## Reglas metrológicas

1. Toda captura almacena peso, unidad, estabilidad, reloj del dispositivo, reloj servidor, ID de báscula, identificador real reportado, valor original, hash, estado de conexión, fuente y actor.
2. La lectura estable se persiste antes del pesaje. Sólo el mismo usuario/cliente puede reclamarla, una sola vez y dentro de `scale.reading_max_age_seconds` (120 s iniciales).
3. La confirmación vuelve a comparar peso, raw/hash, timestamp, báscula, fuente y estabilidad contra la evidencia bloqueada con `FOR UPDATE`; el navegador no es autoridad metrológica.
4. La captura manual no consume una lectura automática y requiere permiso `weighings.manual` y motivo.
5. Tina registrada: se fotografía `current_tare_kg` como tara aplicada.
6. Tara pintada: captura explícita y razón.
7. Tara estimada: parámetro `tare.estimated_kg` (inicial 176 kg) y razón obligatoria.
8. Bruto y tara deben ser positivos; bruto debe superar tara.
9. El castigo porcentual no reemplaza ni cambia el neto físico.
10. Redondeo persistente: tres decimales para kg; dos para porcentaje/temperatura cuando corresponde.

## Reglas de calidad

- Una inspección guarda defectos detallados, porcentaje total, temperatura, grado y disposición.
- Calidad puede retener, liberar o rechazar stock mediante un documento separado.
- Los rangos preliminares (`AAA 0–5`, `AA 6–10`, `A 11–15`, `B 15–100`) se conservan configurados, pero `quality.preliminary_rules_active=false` por el traslape en 15.
- Mientras esa bandera esté apagada, el grado debe ser dictaminado por una persona autorizada; no se inventa una frontera.

## Reglas de lote y certificación

- Código de lote basado en componentes almacenados: barco, especie, número anual de marea, período, año y versión.
- `lot.period_mode` selecciona estrategia `month` o `julian`; cambiarla no reescribe lotes existentes.
- `lot.rule_version` se fotografía en cada lote.
- MSC acepta inicialmente `1.0`, `2.0`, `3.0`, `3.1`; Dolphin Safe acepta `A`, `B`; ambos provienen de configuración.
- Cada línea de tanque requiere temperatura de congelación numérica.
- La suma planeada de composiciones no puede exceder la capacidad fotografiada del tanque cuando exista.

## Reglas de inventario y concurrencia

- Todo cambio se ejecuta en transacción MySQL y bloquea las filas relevantes.
- Los documentos se confirman junto con sus líneas y ledger; no hay documentos confirmados vacíos.
- `reserved_weight_kg <= on_hand_weight_kg` siempre.
- Disponibilidad es `on_hand_weight_kg - reserved_weight_kg` y además depende de estado/calidad.
- Una ubicación valida actividad, almacén, máximo de unidades/peso y segregación configurada.
- Un conteo fotografía ubicación, saldo y `lock_version`; si cambia algo antes de conciliar, se rechaza todo sin documentos parciales.
- Reversar conserva el original confirmado, evita doble reverso y produce deltas opuestos.
- Una recepción confirmada pasa a `reversed` sólo mediante su documento compensatorio; después puede reversarse el pesaje y volver a pesar. El orden obligatorio es **recepción → pesaje → nueva captura**, y las etiquetas anteriores quedan canceladas.
- Una unidad `rejected` sigue contando como existencia física y capacidad ocupada, aunque no tenga disponibilidad operativa.

## Reglas de reservas y surtido

- La solicitud debe estar autorizada/reservada/parcialmente surtida.
- La vigencia debe ser futura.
- Cada selección referencia línea solicitada y unidad física.
- Producto/talla/calidad deben satisfacer el criterio.
- No se puede exceder disponibilidad ni saldo pendiente de solicitud.
- Surtir bloquea reserva, línea y unidad; reduce existencia y reserva en el mismo ledger.
- Liberar exige motivo y recalcula estados por suma de reservado/surtido.

## Reglas fiscales

- Venta y facturación son agregados distintos.
- Crear orden no mueve ni reserva stock; despachar sí genera `sale_dispatch` y revalida disponibilidad.
- Referencia fiscal exige empresa/instalación y, si hay orden, el mismo alcance.
- Un ID externo no puede moverse entre alcances.
- Repetir el mismo estado/UUID responde como replay sin escribir inventario.
- Emisión, actualización o cancelación siempre devuelve `inventory_changed=false`.

## Reglas pendientes, no codificadas como verdad

Protocolo de báscula; modelo Zebra; frontera de 15% de calidad; estructura Excel; regla definitiva de lote; tabla final de castigos; capacidades reales; política de caducidad automática de reservas. Sólo deben activarse mediante configuración/migración y prueba aprobada.
