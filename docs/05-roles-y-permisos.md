# Matriz de roles y permisos

CodeIgniter Shield administra grupos y permisos. La fuente ejecutable es `app/Config/AuthGroups.php`; esta matriz es su lectura funcional. Una `*` dentro de la configuración amplía todos los permisos del prefijo, no todas las acciones del sistema.

## Perfiles

| Grupo | Capacidades principales |
|---|---|
| `process_owner` | Control integral funcional, configuración, usuarios y auditoría |
| `identity_admin` | Usuarios, roles, alcances y auditoría |
| `master_data_owner` | Catálogos, parámetros y auditoría |
| `discharge_planner` | Viajes, RSA, planes, dashboard y reportes |
| `discharge_supervisor` | Plan, descarga, pesaje, etiqueta y reportes |
| `sorter` | Llenado/clasificación en descarga |
| `labeler` | Impresión y reimpresión justificada |
| `weigher` | Pesaje y primera impresión |
| `quality_inspector` | Inspección y consulta de inventario |
| `quality_approver` | Inspección, dictamen y consulta |
| `warehouse_operator` | Recepción, inventario, movimiento, reserva y traspaso |
| `warehouse_supervisor` | Lo anterior + ajustes, aprobación de solicitudes y reportes |
| `production_requester` | Consulta y creación de solicitud |
| `request_approver` | Aprobación y reserva de unidad específica |
| `sales_dispatch` | Venta, despacho y consulta de inventario |
| `billing` | Referencias fiscales y reportes; sin permiso de stock |
| `inventory_controller` | Movimientos, ajustes, reversos, auditoría y reportes |
| `auditor` | Consulta de dashboard, inventario, auditoría y reportes |
| `support` | Diagnóstico de integraciones, dashboard y auditoría |
| `external_warehouse` | Consulta, recepción y traspasos en alcance restringido |

## Permisos granulares

| Permiso | Acción de backend protegida |
|---|---|
| `dashboard.view` | Indicadores del alcance |
| `catalogs.manage` | Alta/cambio/baja lógica de maestros |
| `voyages.manage` | Viajes, fotografía de tanques y RSA |
| `plans.manage` | Crear/transicionar planes |
| `discharge.operate` | Iniciar/completar llenados |
| `weighings.create` | Capturar y confirmar pesaje |
| `weighings.manual` | Contingencia manual justificada |
| `weighings.reverse` | Reversar pesaje |
| `labels.print` | Primera impresión |
| `labels.reprint` | Reimpresión con motivo |
| `quality.inspect` | Inspeccionar |
| `quality.approve` | Dictaminar y afectar estado de calidad |
| `receiving.confirm` | Crear inventario desde recepción |
| `inventory.view` | Consultar unidades y movimientos |
| `inventory.move` | Reubicar/surtir/despachar según caso |
| `inventory.adjust` | Mermas, ajustes y conciliaciones |
| `inventory.reverse` | Reverso compensatorio |
| `requests.create` | Solicitud de materia prima |
| `requests.approve` | Autorizar/rechazar solicitud |
| `reservations.manage` | Reservar, liberar y surtir selección exacta |
| `transfers.manage` | Crear, autorizar, embarcar y recibir traspaso |
| `sales.manage` | Crear y despachar venta |
| `billing.reference` | Registrar estado fiscal sin inventario |
| `audit.view` | Consultar evidencia funcional |
| `reports.view` | Consultar/exportar |
| `integrations.manage` | Diagnóstico y configuración de endpoints |
| `configuration.manage` | Parámetros no sensibles |
| `users.manage` | Usuarios, estado, grupo y alcance |

## Separación de funciones recomendada

| Proceso | Ejecuta | Autoriza/controla |
|---|---|---|
| Plan de descarga | Planeador | Supervisor de descarga |
| Pesaje manual | Pesador autorizado | Supervisor/auditor revisa razón |
| Calidad | Inspector | Aprobador de calidad |
| Solicitud MP | Producción | Aprobador de solicitudes |
| Ajuste/merma | Operador sólo si tiene permiso | Contralor/supervisor |
| Conteo | Operador de conteo | Contralor concilia |
| Traspaso | Operador | Supervisor |
| Despacho venta | Ventas/despacho | Política comercial externa |
| CFDI | Facturación | INVoiice/autoridad fiscal |
| Usuarios privilegiados | Identity admin | Process owner revisa auditoría |

En una instalación pequeña una persona puede acumular grupos, pero las bitácoras conservan el actor de cada paso.

## Alcance operativo

Los permisos contestan “qué puede hacer”; `operational_user_scopes` contesta “dónde”. Cada asignación contiene empresa y opcionalmente sitio, almacén y estación. El usuario elige un alcance al iniciar; los filtros lo comprueban en cada request y el backend valida:

- IDs enviados en payload.
- Unidades dentro de líneas/selections.
- Agregados referidos por URL.
- Almacenes origen y pertenencia del destino.
- Empresa/sitio de ventas y referencias fiscales.

No se considera autorización que un botón esté oculto.

## Clientes API

Los clientes no usan grupos Shield. Cada cliente posee permisos explícitos (`health.read`, `catalogs.read`, `voyages.read/manage`, `plans.read/manage`, `scale.read`, `weighings.create/reverse`, `labels.print/reprint`, `quality.inspect/approve`, `receiving.confirm`, `inventory.read/move/adjust/reverse`, `requests.create/approve`, `reservations.manage`, `transfers.manage`, `sales.manage`, `billing.reference`) y alcance opcional.

Creación segura:

```bash
php spark app:create-api-client integracion-erp "ERP administrativo" inventory.read,billing.reference 1 1
```

La clave se muestra una sola vez y se guarda únicamente su hash. En producción no debe usarse `*` salvo para un cliente técnico excepcional, aislado y auditado.

## Administración segura

- Registro público desactivado.
- Contraseña mínima configurable; valor inicial 12 caracteres.
- Recuperación por enlace mágico y SMTP configurado.
- Bloqueo/rate limit de login provisto por Shield.
- No se puede desactivar al usuario actual desde la consola.
- Cambios de grupo/estado/alcance generan auditoría.
- Probar cuentas de emergencia y rotación antes del go-live.
