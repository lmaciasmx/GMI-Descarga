# Matriz de cobertura funcional y técnica

Leyenda: **Operativo** = flujo ejecutable con UI/API/servicio/DB; **Administrable** = catálogo/configuración ejecutable; **Base preparada** = contrato/esquema sin integración real porque falta información externa; **Pendiente externo** = requiere definición/equipo/datos del cliente.

## Módulos solicitados

| Módulo | Cobertura | Evidencia principal |
|---|---|---|
| Auth | Operativo | Shield, rutas login/logout/magic link, filtros |
| Usuarios | Operativo | `Modules/Users` y comando bootstrap |
| Roles y permisos | Operativo | `Config/AuthGroups.php`, filtros por endpoint |
| Catálogos | Administrable | UI/API genérica validada y baja lógica |
| Empresas propietarias | Administrable | `companies`, scopes y catálogo |
| Embarcaciones | Administrable | `vessels`, `vessel_tanks` |
| Viajes y mareas | Operativo | `VoyageService`, snapshot de tanques, cierre/cancelación transaccional y auditoría |
| RSA | Operativo | `RsaService`, vigencia en aprobación |
| Planeación de descarga | Operativo | `DischargePlanService`, estados, guardas de tinas pendientes y pantalla |
| Tanques o bodegas | Operativo | maestro + fotografía por viaje + composición |
| Tinas | Administrable/operativo | catálogo, estados y llenados |
| Básculas | Base preparada | interface, middleware, simulador y evidencia capturada/reclamada una sola vez |
| Descarga | Operativo | llenado/clasificación/procedencia múltiple |
| Pesaje | Operativo | estación, cálculo, captura ligada, anti-replay/anti-manipulación y confirmación |
| Etiquetado | Operativo con contingencia | QR/barra, HTML/PDF, ZPL y trabajos |
| Calidad | Operativo manual | inspección/dictamen; auto-regla apagada por traslape |
| Lotes | Operativo/configurable | estrategia mes/juliano y versión |
| Recepción | Operativo | crea inventario sólo al confirmar |
| Frigoríficos | Administrable | `warehouses` internos/externos |
| Cámaras y ubicaciones | Administrable/operativo | jerarquía, capacidad y segregación |
| Inventario | Operativo | ledger + proyección por unidad |
| Solicitudes MP | Operativo | solicitud/autorización/avance |
| Reservas | Operativo | selección exacta, vigencia y liberación |
| Surtido a producción | Operativo | `fulfillReservation`, salida+liberación atómica |
| Traspasos | Operativo | autorizar, embarcar, tránsito, recepción entre instalaciones y diferencia |
| Entradas | Operativo | recepción, devolución, ajuste positivo |
| Salidas | Operativo | producción, venta, merma, ajuste negativo |
| Venta | Operativo | orden, revalidación y despacho físico |
| Almacenes externos | Operativo | tipo external y traspaso/recepción restringidos |
| Mermas y castigos | Operativo y separado | merma en ledger; castigo en pesaje sin confundir calidad |
| Ajustes | Operativo | permiso, motivo, transacción y auditoría |
| Conciliaciones | Operativo | conteo/snapshot/reconcile |
| Auditoría | Operativo | UI y `audit_logs`/`security_events` |
| Reportes | Operativo | DataTables, CSV/Excel, stock y trazabilidad |
| Dashboard | Operativo | KPI y Chart.js por alcance |
| Configuración | Operativo | settings tipados; sensibles protegidos |
| Integraciones | Base preparada | API, clientes, eventos y adapters |
| Facturación | Operativo como referencia | INVoiice/CFDI aislado de inventario |
| Cámaras externas | Base preparada | endpoint/eventos; contrato pendiente |
| Aplicaciones móviles | Base preparada | PWA responsive + API; sin offline destructivo |

## Requisitos técnicos

| Requisito | Estado | Implementación |
|---|---|---|
| CodeIgniter 4 / PHP 8.2 | Cumplido | Composer y estructura appstarter |
| MySQL 8/InnoDB/utf8mb4 | Cumplido | migrations SQL con FKs/checks/índices |
| MVC modular/namespaces | Cumplido | `App\Modules\*` |
| Service Layer | Cumplido | casos de uso por dominio |
| Repository/Models/Entities/DTO | Cumplido donde aporta | inventario/pesaje/recepción/supply/transfer |
| Adapter/Strategy/State Machine | Cumplido | dispositivos, lote/calidad, workflows |
| Transactions críticas | Cumplido | InventoryService y services mutables |
| REST API v1 | Cumplido | `/api/v1`, JSON, permisos, rate/idempotencia |
| Swagger/OpenAPI | Cumplido | `docs/api/openapi.yaml` |
| Bootstrap/DataTables/Chart/SweetAlert/Select2 | Cumplido | assets locales construidos por npm |
| QR/código de barras | Cumplido | servidor + ZXing frontend |
| Shield | Cumplido | sesiones, grupos, permisos, reset |
| CSRF/XSS/SQLi/headers | Cumplido | filtros, escaping y query binding |
| Variables de entorno | Cumplido | `.env.example`, sin secretos reales |
| Apache/Nginx/Windows/Linux | Cumplido | infra y scripts bash/PowerShell |
| Docker opcional | Cumplido | Dockerfile/compose |
| PWA sin stock offline | Cumplido | manifest/SW/banner/bloqueo |
| Logs separados | Cumplido | `OperationalLogger` y tablas de evidencia |
| Migrations/Seeders/Factories | Cumplido | cuatro migrations, core/demo y factories |
| PHPUnit crítico | Cumplido como suite | unit + database tests y CI MySQL |
| Documentación obligatoria | Cumplido | `README.md` y `docs/` |

## Restricciones verificadas

- No hay backend adicional ni PHP fuera de CodeIgniter.
- No hay otro motor de base de datos.
- Tanques múltiples usan tabla relacional.
- Contraseñas y API keys no se guardan en claro.
- Controllers no escriben saldos.
- Movimientos confirmados no se eliminan físicamente.
- Los adapters pendientes fallan explícitamente; no simulan protocolo real.
- Castigo, calidad y disponibilidad tienen campos/servicios distintos.
- Calidad/lotes/certificaciones usan configuración y versión.

## Pendientes legítimos para puesta en producción

| Pendiente | Bloquea | Responsable esperado |
|---|---|---|
| Marca/modelo/protocolo de báscula | Lectura real | Operaciones + proveedor + TI |
| Modelo/dpi/transporte Zebra | Impresión térmica real | Almacén + proveedor + TI |
| Muestras Excel y acta de conciliación | Carga histórica | Contraloría + Operaciones |
| Resolver 15% de calidad | Clasificación automática | Calidad |
| Regla definitiva de lote | Activar formato final | Operaciones/Calidad |
| Catálogo de castigos/motivos | Automatización comercial | Operaciones/Comercial |
| Topología/capacidades reales | Reglas de ubicación | Frigorífico |
| Contrato INVoiice/ERP | Sincronización automática | Finanzas + proveedor |
| UAT, seguridad y carga | Go-live | Dueños de proceso + TI |

Estos pendientes no impiden instalar y evaluar el flujo con datos demo, pero sí deben cerrarse antes de operar equipos o datos reales.
