# Guía de usuario

## Acceso y alcance

1. Inicia sesión con tu cuenta nominal.
2. Selecciona empresa, instalación y, si aplica, almacén/estación.
3. Confirma el chip de alcance en la barra superior antes de operar.
4. Si una opción no aparece, solicita el permiso; no uses la cuenta de otra persona.

La sesión expira según configuración. Los mensajes de permiso o alcance son controles de seguridad, no fallas de pantalla.

## Flujo de descarga

### 1. Planeación

En **Planeación** crea la marea con barco, número/año, arribo y frigorífico. El sistema fotografía los tanques maestros. Registra el RSA y crea el plan con una o más composiciones por tanque: peso, especie, talla, MSC, Dolphin Safe y temperatura.

Aprueba el plan sólo tras revisar RSA/vigencia/capacidad. Cambia a `in_progress` al comenzar la descarga; esto inicia también la marea. Finaliza el plan únicamente cuando ya no existan tinas por llenar o pesar. Cuando todos sus planes estén terminados/cancelados, cierra la marea. Las cancelaciones exigen motivo y sólo se permiten antes de operar tinas.

### 2. Tina y clasificación

Desde la estación de descarga selecciona plan, tina, tanque real y todas las procedencias, especie/talla y responsables. La tina deja de estar disponible mientras está en proceso. Completa el llenado cuando esté lista para báscula.

### 3. Pesaje

En la estación **Pesaje**:

1. Confirma indicadores de red, báscula e impresora.
2. Escanea/selecciona la tina pendiente.
3. Revisa barco, marea, RSA, tanque(s), especie y talla.
4. Selecciona fuente de tara. Catálogo es preferida; pintada/estimada exige motivo.
5. Lee peso estable y confirma dentro de la vigencia mostrada; si expira, solicita otra lectura. Usa captura manual sólo con permiso/contingencia.
6. Verifica bruto, tara, neto físico, castigo y neto aceptado.
7. Confirma una vez y espera respuesta; la interfaz previene doble clic.
8. Imprime etiqueta y verifica QR/barras legibles.

No redondees ni “corrijas” el neto físico con el castigo. Si el dato es erróneo antes de recepción, un usuario autorizado revierte el pesaje. Si ya originó inventario, el orden obligatorio es: reversar la recepción en **Movimientos**, reversar el pesaje pendiente de corrección y capturar uno nuevo. La etiqueta anterior se cancela y debe imprimirse la nueva desde **Etiquetas**.

### 4. Calidad

Registra temperatura, defectos, porcentajes, grado y disposición. Los rangos automáticos están inactivos hasta cerrar el traslape del 15%; selecciona el grado dictaminado. Un aprobador decide liberar, retener o rechazar. Castigo y calidad se capturan por separado.

### 5. Recepción

En **Recepción**, selecciona los pesajes/etiquetas válidos y la ubicación de ingreso. Confirma sólo después de contar físicamente. Éste es el momento en que nace inventario; antes no debe aparecer existencia.

## Inventario y ubicación

**Existencias** permite buscar por unidad, lote, producto, calidad y ubicación. Interpreta:

- Existencia: peso físico actual.
- Reservado: parte comprometida.
- Disponible: existencia menos reserva, si el estado lo permite.
- Retenido: existe físicamente pero no se puede surtir.

En **Movimientos**, reubica unidades completas o registra salida/devolución/merma/ajuste según permiso. Motivos son obligatorios para mermas, ajustes y reversos. Nunca vuelvas a capturar una recepción para corregir saldo.

## Solicitud, reserva y surtido

1. Producción crea una solicitud por especie/talla/calidad y kg requeridos.
2. Un aprobador autoriza o rechaza con motivo.
3. El operador pulsa **Seleccionar unidades**, revisa candidatos y captura kg exactos.
4. La reserva cambia el compromiso, no la existencia.
5. En **Reservas y surtido**, pulsa **Surtir** cuando la materia salga a producción. El sistema descuenta existencia y reserva en una transacción.
6. Si no se utilizará, pulsa **Liberar** e indica motivo.

Una reserva vencida no se surte: debe liberarse y recalcularse la disponibilidad.

## Traspasos

Crea el traspaso seleccionando origen, destino y unidades completas sin reserva. Autoriza, embarca y luego recibe en una ubicación del destino. Al embarcar, la unidad queda en tránsito. Captura peso recibido si aplica; una diferencia retiene la unidad para conciliación. En un destino de otra instalación, el alcance de la unidad cambia atómicamente al recibirla.

## Venta y facturación

En **Venta y despacho**, crea una orden seleccionando unidades y cantidades. La orden no reserva automáticamente; al **Despachar**, el servidor revalida disponibilidad y registra la salida física.

La referencia INVoiice/CFDI puede asociarse desde Venta o **Facturación**. Emitir, actualizar o cancelar sólo registra estado fiscal. Si una cancelación fiscal requiere retorno físico, registra además el caso de devolución autorizado; no ocurre automáticamente.

## Conteos y conciliación

Inicia un conteo por almacén/ubicación, escanea cada unidad y captura ubicación/peso observado. Finaliza el conteo y entrégalo a Contraloría. Si una unidad cambió después de la fotografía, la conciliación completa se rechaza; recarga o inicia un nuevo conteo. Las diferencias aprobadas crean movimientos compensatorios.

## Auditoría y reportes

DataTables permite buscar, ordenar y exportar CSV/Excel. Auditoría muestra actor, acción, agregado, motivo, correlación y antes/después. No alteres archivos exportados para presentarlos como evidencia del sistema; conserva fecha/filtros y, si la política lo exige, checksum.

## Buenas prácticas por turno

- No compartir cuentas ni claves.
- Revisar scope y conectividad al iniciar.
- Escanear la etiqueta; evitar transcribir.
- Esperar respuesta de confirmación.
- Reportar discrepancias sin repetir la operación.
- Cerrar sesión al cambiar de operador.
- Entregar pendientes de calidad, tránsito, impresión y reservas vencidas.

## Mensajes frecuentes

| Mensaje/código | Acción |
|---|---|
| `SCOPE_MISMATCH` | Cambia al alcance correcto o solicita asignación |
| `INSUFFICIENT_AVAILABLE_STOCK` | Recarga; otra operación reservó/movió saldo |
| `STALE_*` / `CONCURRENT_*` | No repitas a ciegas; recarga y verifica estado |
| `RESERVATION_EXPIRED` | Libera y crea nueva selección |
| `PLAN_RSA_REQUIRED/EXPIRED` | Corrige RSA antes de aprobar |
| `SCALE_*` | Aplica contingencia de báscula |
| `PRINT_*` | Verifica cola/equipo o usa PDF |
| `DUPLICATE_*` | Busca el folio ya creado antes de reintentar |
| `INTERNAL_ERROR` | Anota correlación/hora y escala a soporte; no recaptures todavía |

Para interrupciones consulta [contingencias](11-contingencias.md).
