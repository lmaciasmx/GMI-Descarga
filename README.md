# Grupo Mar Operaciones

Aplicación web empresarial para planear descargas de embarcaciones, capturar pesajes, etiquetar, dictaminar calidad, confirmar recepciones y controlar inventario por tina/marbete/lote/ubicación. Incluye solicitudes de materia prima, reservas de unidades específicas, surtido, traspasos, ventas, referencias fiscales, conciliaciones, auditoría, reportes e integraciones.

La plataforma es la fuente de verdad de la operación física. **INVoiice conserva la función fiscal y la emisión o cancelación de un CFDI nunca modifica inventario.**

## Estado de la entrega

El repositorio contiene una aplicación CodeIgniter 4 completa, no un conjunto de ejemplos: rutas, controladores, servicios, repositorios, entidades/DTO, migraciones, seeders, vistas industriales, API v1, filtros de seguridad, adaptadores de dispositivos, pruebas, PWA controlada y archivos de despliegue.

Las integraciones reales con báscula y Zebra quedan deliberadamente bloqueadas hasta confirmar marca, modelo, protocolo y conectividad. Para desarrollo se incluye un simulador identificado y para etiquetas una contingencia PDF; no se presentan como dispositivos reales.

## Tecnología

- PHP 8.2+, CodeIgniter 4.7 y CodeIgniter Shield 1.4.
- MySQL 8, InnoDB y `utf8mb4`.
- Bootstrap 5, DataTables + exportación, Chart.js, SweetAlert2, Select2 y ZXing.
- Composer y npm para dependencias; PHPUnit para pruebas.
- Apache o Nginx; Windows Server o Linux; Docker opcional.

## Instalación rápida en Linux

Requisitos: PHP 8.2 con `curl`, `dom`, `intl`, `mbstring`, `mysqli`, `json`, `gd` y `zip`; Composer 2; Node.js 20+; npm; MySQL 8; Apache 2.4 o Nginx.

```bash
cp .env.example .env
# Editar .env: URL, base de datos, encryption.key, correo, HTTPS e integraciones.
./scripts/install.sh
php spark app:bootstrap-admin admin@empresa.com administrador
php spark serve
```

El comando de administrador genera una contraseña aleatoria de un solo uso, salvo que se proporcione de forma temporal mediante la variable `GMAR_BOOTSTRAP_PASSWORD`. No hay contraseñas iniciales fijas ni usuarios ocultos.

En Windows PowerShell:

```powershell
Copy-Item .env.example .env
# Editar .env.
.\scripts\install.ps1 -AdminEmail admin@empresa.com -AdminUser administrador
php spark serve
```

El `DocumentRoot` del servidor web debe apuntar exclusivamente a `public/`. Hay ejemplos en `infra/apache/grupo-mar.conf` e `infra/nginx/grupo-mar.conf`.

## Docker opcional

```bash
export GMAR_DB_PASSWORD='una-clave-segura'
export GMAR_DB_ROOT_PASSWORD='otra-clave-segura'
export GMAR_BASE_URL='http://localhost:8080/'
export GMAR_ENCRYPTION_KEY="$(php -r 'echo "hex2bin:", bin2hex(random_bytes(32));')"
docker compose up -d --build
docker compose exec web php spark migrate --all
docker compose exec web php spark db:seed 'App\Database\Seeds\CoreCatalogSeeder'
docker compose exec web php spark app:bootstrap-admin admin@empresa.com administrador
```

Docker facilita una instalación inicial, pero la aplicación no depende de él.

## Datos iniciales y demo

`CoreCatalogSeeder` carga catálogos operativos claramente identificados: Grupo Mar/Manzanillo, frigorífico y ubicaciones base, seis embarcaciones, especies/tallas preliminares, 20 tinas, báscula simulada, impresora PDF y una Zebra inactiva pendiente de configurar. `DemoOperationalSeeder` es opcional y sólo debe usarse en desarrollo o pruebas:

```bash
php spark db:seed 'App\Database\Seeds\DemoOperationalSeeder'
```

## Operación y pruebas

```bash
npm run verify
vendor/bin/phpcs --standard=PSR12 app tests
vendor/bin/phpunit
php spark migrate:status
```

Las pruebas de base de datos requieren un esquema MySQL vacío dedicado; `phpunit.xml.dist` usa `grupomar_operaciones_test`. La automatización de `.github/workflows/quality.yml` levanta MySQL 8.4 y ejecuta análisis PHP/JavaScript y PHPUnit.

Para actualizar:

```bash
./scripts/update.sh
```

Para respaldar y restaurar, define `GMAR_DB_HOST`, `GMAR_DB_NAME`, `GMAR_DB_USER` y `GMAR_DB_PASSWORD`, y consulta [instalación y operación](docs/09-instalacion-operacion.md).

## Documentación

- [Diseño funcional en el orden solicitado](docs/01-diseno-funcional.md)
- [Arquitectura y decisiones](docs/02-arquitectura.md)
- [Modelo entidad-relación y diccionario](docs/03-modelo-y-diccionario.md)
- [Estados y reglas de negocio](docs/04-estados-y-reglas.md)
- [Roles y permisos](docs/05-roles-y-permisos.md)
- [API REST y OpenAPI](docs/api/openapi.yaml)
- [Báscula, impresión e integraciones](docs/06-integraciones.md)
- [Migración controlada desde Excel](docs/07-migracion-excel.md)
- [Plan de pruebas](docs/08-plan-pruebas.md)
- [Instalación, actualización y respaldo](docs/09-instalacion-operacion.md)
- [Guía de usuario](docs/10-guia-usuario.md)
- [Contingencias](docs/11-contingencias.md)
- [Matriz de cobertura](docs/12-matriz-cobertura.md)
- [Verificación de la entrega](docs/13-verificacion-entrega.md)

## Principios no negociables implementados

- El inventario nace únicamente al confirmar recepción en frigorífico.
- Todo cambio de stock ocurre dentro de `InventoryService`, con transacción, encabezado, detalle, libro mayor, proyección, usuario y auditoría.
- Un documento confirmado no se edita ni elimina; se compensa mediante reverso.
- Peso bruto, tara, neto físico, castigo, neto aceptado, calidad, disponibilidad y estado se conservan como conceptos separados.
- Las reservas identifican unidades físicas específicas y el surtido reduce existencia y reserva en la misma transacción.
- Permisos y alcance empresa/instalación/almacén se validan en backend; ocultar botones es sólo una ayuda de interfaz.
- PWA almacena únicamente recursos estáticos. Pesajes, recepciones y movimientos no se confirman sin conexión.

## Variables sensibles

Nunca confirmes `.env`. En producción usa TLS, `cookie.secure=true`, una `encryption.key` aleatoria, usuario MySQL de privilegio mínimo, credenciales distintas por ambiente y secretos del middleware rotables. Los logs no deben recibir contraseñas, tokens completos ni payloads fiscales completos.

## Pendientes de validación operativa

Antes de producción deben cerrarse: protocolo real de báscula; modelo y transporte Zebra; formato fuente de Excel; regla final de lotes; traslape de calidad en 15%; catálogo definitivo de motivos/castigos; topología real de cámaras/ubicaciones y retención legal de auditoría. Están parametrizados o aislados para no fijar supuestos como reglas definitivas.
