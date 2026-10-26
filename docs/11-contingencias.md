# Guía operativa de contingencias

## Regla general

Proteger personas y producto primero. Después preservar identidad, orden temporal y evidencia. Nunca “resolver” una caída insertando SQL, compartiendo cuentas, cambiando saldos directamente o confirmando offline. Registrar inicio/fin, estación, operador, unidades afectadas y responsable que autorizó la contingencia.

## Pérdida de red

1. La PWA muestra banner offline y bloquea confirmaciones críticas.
2. Detener recepción, pesaje confirmado, movimientos, surtido, despacho y conciliación.
3. Mantener físicamente separadas las tinas pendientes con folio manual de contingencia prenumerado.
4. No recargar repetidamente ni usar otra red no autorizada.
5. Al volver, consultar si existe documento/folio antes de capturar.
6. Ingresar en orden, con motivo/referencia del formato manual y revisión dual.

El cache sólo permite abrir recursos estáticos; no existe cola de escritura offline.

## Báscula desconectada o inestable

1. Revisar energía/cable/estado sin alterar configuración sellada.
2. Esperar timeout y comprobar consola de integraciones.
3. Si la política permite captura manual, un usuario con `weighings.manual` captura el indicador visible, unidad y motivo; idealmente adjunta evidencia según procedimiento externo.
4. Marcar el formato físico y solicitar revisión del supervisor.
5. No usar el simulador en producción; está bloqueado por defecto.
6. Soporte conserva muestra raw, hora, firmware y reinicio en bitácora sin secretos.

Reconciliar todos los pesajes manuales al cierre del turno.

## Impresora sin servicio

1. No volver a confirmar el pesaje.
2. Revisar estado, papel, cabezal y cola.
3. Reintentar mediante **reimpresión**, con permiso y motivo; esto conserva número de intento.
4. Si Zebra no está disponible, generar PDF aprobado y colocar etiqueta temporal según procedimiento.
5. Al restablecer, reemplazar/adjuntar etiqueta sin crear otra unidad.
6. Escanear QR y barras antes de liberar la tina.

## Respuesta incierta o doble clic

Si hubo timeout después de Confirmar:

1. No repetir con una clave nueva.
2. Consultar el folio, pesaje, recepción o movimiento por unidad/hora.
3. Para API, reenviar la misma `Idempotency-Key` y mismo payload.
4. Si el servidor devuelve conflicto por payload distinto, detener y escalar.
5. Sólo crear una operación nueva tras confirmar que la anterior no existe.

## Etiqueta dañada o perdida

Buscar la unidad por tina/lote/pesaje; validar físicamente; reimprimir con motivo. Nunca generar una etiqueta lógica distinta para la misma recepción con el fin de evitar el historial.

## Diferencia de traspaso

Recibir con el peso observado; el sistema marca discrepancia y hold. Aislar la unidad, levantar evidencia origen/destino y conciliar mediante dictamen/ajuste autorizado. No modificar el peso enviado.

## Calidad pendiente

Producto en hold permanece físicamente existente pero no disponible. Separar/identificar, registrar defectos y esperar aprobador. No usar castigo comercial como sustituto del dictamen.

## Reserva vencida o selección ya no disponible

Liberar la reserva con motivo, actualizar solicitud y volver a seleccionar. No surtir físicamente hasta que el sistema confirme el nuevo documento.

## Error de captura confirmado

- Pesaje: reverso autorizado y nueva captura enlazada.
- Inventario: reverso del documento o ajuste compensatorio con motivo.
- CFDI: actualizar/cancelar referencia fiscal; esto no corrige stock.
- Recepción duplicada: buscar el documento existente; no borrar.

Los documentos confirmados no se editan ni eliminan.

## Caída de aplicación o base de datos

1. Detener confirmaciones y conservar formatos prenumerados.
2. Soporte revisa health, espacio, conexión, servicios y logs; no expone errores al operador.
3. Si se declara recuperación, activar runbook de restauración y comunicar RPO/RTO.
4. Restaurar primero en instancia aislada; verificar checksum, migrations y conciliación.
5. Autoridad operativa decide reapertura y tratamiento del intervalo no capturado.

## Incidente de seguridad

Ante clave expuesta, acceso anómalo o malware:

1. Aislar cuenta/cliente/dispositivo; preservar evidencia.
2. Rotar credencial desde un canal limpio.
3. Revisar `security_events`, auditoría, access/error logs y alcance afectado.
4. No borrar logs ni reinstalar antes de adquirir evidencia.
5. Seguir el plan de respuesta corporativo y notificación legal.

## Cierre de contingencia

El responsable documenta: causa, ventana, tinas/pesajes/documentos, capturas manuales, reimpresiones, correcciones/reversos, conciliación final, aprobadores y acciones preventivas. Operaciones y Contraloría confirman que libro mayor, proyección y físico concilian antes de cerrar.
