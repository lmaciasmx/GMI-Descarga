# Instalación, actualización, respaldo y operación

## Requisitos del servidor

- SO: Linux de 64 bits o Windows Server soportado por el proveedor.
- PHP 8.2+ estable con `curl`, `dom`, `intl`, `mbstring`, `mysqli`, `json`, `gd`, `zip`, `fileinfo` y OpenSSL.
- Composer 2; Node.js 20+ y npm sólo para construir assets.
- MySQL 8.0+ con InnoDB y zona horaria configurada.
- Apache 2.4 con `rewrite`/`headers`, o Nginx + PHP-FPM.
- TLS para producción, NTP, SMTP, DNS interno y espacio externo para respaldos.
- Directorio `writable` persistente con escritura exclusiva del usuario web.

Dimensionamiento inicial orientativo: 4 vCPU, 8 GB RAM, 100 GB SSD para aplicación/DB y almacenamiento de respaldo separado. Ajustar después de pruebas con volumen real.

## Base de datos y menor privilegio

Ejecutar como administrador MySQL y reemplazar claves/host:

```sql
CREATE DATABASE grupomar_operaciones CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER 'grupomar_app'@'10.%' IDENTIFIED BY 'CLAVE-ALEATORIA-LARGA';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES, CREATE VIEW, SHOW VIEW
ON grupomar_operaciones.* TO 'grupomar_app'@'10.%';
FLUSH PRIVILEGES;
```

Tras completar migrations puede usarse un usuario runtime sin `CREATE/ALTER/INDEX`; conservar una identidad separada de despliegue. No habilitar acceso remoto de root.

## Configuración de `.env`

```bash
cp .env.example .env
php spark key:generate
```

Cambiar obligatoriamente:

- `CI_ENVIRONMENT=production`.
- `app.baseURL` HTTPS y `app.forceGlobalSecureRequests=true`.
- host/base/usuario/contraseña MySQL.
- `cookie.secure=true`.
- SMTP para recuperación de contraseña.
- timezone de empresa/instalación.
- credenciales/URL de middleware sólo cuando estén certificadas.
- rate limit por cliente/IP y alcance de cada cliente API.

`.env` no debe entrar a control de versiones, backups de código, tickets ni logs.

## Instalación Linux

```bash
./scripts/install.sh
php spark app:bootstrap-admin admin@empresa.com administrador
```

La primera ejecución del script crea `.env` y se detiene para obligar a configurarlo. Después instala dependencias, construye assets, migra y carga `CoreCatalogSeeder`.

Permisos típicos:

```bash
sudo chown -R root:www-data /var/www/grupo-mar
sudo find /var/www/grupo-mar -type d -exec chmod 750 {} \;
sudo find /var/www/grupo-mar -type f -exec chmod 640 {} \;
sudo chown -R www-data:www-data /var/www/grupo-mar/writable
sudo chmod -R 770 /var/www/grupo-mar/writable
```

No aplicar permisos recursivos abiertos. `public/` es el único DocumentRoot.

## Instalación Windows Server

```powershell
Copy-Item .env.example .env
# Configurar .env
.\scripts\install.ps1 -AdminEmail admin@empresa.com -AdminUser administrador
```

Conceder Modify únicamente al identity pool/usuario del servicio sobre `writable`; código y `.env` sólo lectura, y denegar acceso HTTP a `app`, `tests`, `vendor`, `writable` y archivos con punto. Apache/Nginx debe apuntar a `public`.

## Apache y Nginx

Los ejemplos están en `infra/apache/grupo-mar.conf` e `infra/nginx/grupo-mar.conf`. Copiar, ajustar dominio/ruta/socket PHP y habilitar el sitio. Terminar TLS en el propio servidor o proxy confiable; configurar correctamente proxy headers y trusted proxies antes de forzar HTTPS.

Pruebas:

```bash
curl -I https://descarga.empresa.local/login
curl -s https://descarga.empresa.local/api/v1/health \
  -H 'X-API-CLIENT: health-monitor' -H 'X-API-KEY: ...'
```

Verificar CSP, HSTS en el proxy, `nosniff`, anti-frame, cookie Secure/HttpOnly/SameSite y que `/app/.env`, `/writable` y `/vendor` devuelvan 403/404.

## Docker opcional

Definir secretos en el entorno o secret manager y ejecutar:

```bash
export GMAR_DB_PASSWORD='una-clave-segura'
export GMAR_DB_ROOT_PASSWORD='otra-clave-segura'
export GMAR_ENCRYPTION_KEY="$(php -r 'echo "hex2bin:", bin2hex(random_bytes(32));')"
docker compose up -d --build
docker compose exec web php spark migrate --all
docker compose exec web php spark db:seed 'App\Database\Seeds\CoreCatalogSeeder'
docker compose exec web php spark app:bootstrap-admin admin@empresa.com administrador
```

Las variables `GMAR_DB_PASSWORD`, `GMAR_DB_ROOT_PASSWORD` y `GMAR_ENCRYPTION_KEY` son obligatorias: Compose se detiene si falta alguna. Los volúmenes `mysql_data` y `writable_data` deben incluirse en el plan de respaldo.

## Usuario inicial

```bash
php spark app:bootstrap-admin admin@empresa.com administrador
```

La clave aleatoria aparece una vez. Puede inyectarse temporalmente con `GMAR_BOOTSTRAP_PASSWORD`, pero debe eliminarse inmediatamente. Cambiarla, validar correo, activar MFA si Shield/política lo incorporan y crear cuentas nominales; no compartir el bootstrap.

## Cliente API

```bash
php spark app:create-api-client erp-prod "ERP producción" "inventory.read,billing.reference" 1 1
```

Guardar la clave mostrada una vez en el vault del consumidor. Rotar creando una credencial nueva, migrar el consumidor, desactivar la anterior y revisar auditoría.

## Actualización

Antes de cada release:

1. Leer notas y migrations.
2. Crear/verificar respaldo.
3. Probar en staging con copia anonimizada.
4. Poner la interfaz en ventana de mantenimiento si la migración cambia tablas críticas.
5. Ejecutar `./scripts/update.sh` o `.\scripts\update.ps1`.
6. Ejecutar `php spark migrate:status`, smoke tests y conciliación ledger/proyección.
7. Revisar logs y health checks.

Las migrations deben ser compatibles con rollback planificado; no usar `migrate:rollback` en producción si ya existen operaciones nuevas. Compensar negocio y restaurar sólo según runbook aprobado.

## Respaldo Linux

```bash
export GMAR_DB_HOST=127.0.0.1
export GMAR_DB_NAME=grupomar_operaciones
export GMAR_DB_USER=grupomar_backup
export GMAR_DB_PASSWORD='...'
export GMAR_BACKUP_DIR=/mnt/backup/grupo-mar
./scripts/backup.sh
```

El script usa snapshot transaccional, comprime y genera SHA-256. Además respaldar `writable/uploads` y `writable/labels` si contienen documentos necesarios. Los logs se retienen según política, no indefinidamente.

Windows:

```powershell
$env:GMAR_DB_HOST="127.0.0.1"
$env:GMAR_DB_NAME="grupomar_operaciones"
$env:GMAR_DB_USER="grupomar_backup"
$env:GMAR_DB_PASSWORD="..."
.\scripts\backup.ps1 -Destination D:\Backups\GrupoMar
```

Política inicial recomendada: diario 35 días, semanal 12 semanas, mensual 13 meses; cifrado, copia fuera del servidor y control de acceso. Ajustar a obligación legal.

## Restauración

Restaurar primero en una base vacía aislada y verificar checksum. Linux:

```bash
sha256sum -c respaldo.sql.gz.sha256
./scripts/restore.sh respaldo.sql.gz --confirm
php spark migrate:status
vendor/bin/phpunit
```

Windows:

```powershell
.\scripts\restore.ps1 -BackupFile D:\Backups\GrupoMar\respaldo.sql -Confirm
```

Después ejecutar conteos de documentos/líneas/movimientos, conciliación de saldos, login, recepción simulada sólo en staging y lectura de reportes. Medir RPO/RTO y documentar evidencia trimestralmente.

## Logs y rotación

La aplicación escribe en `writable/logs`. Configurar logrotate/rotación Windows y exportación a SIEM cuando exista. Alertas mínimas: 5xx, deadlocks repetidos, acceso denegado masivo, rate limit, báscula/impresora desconectada, trabajos fallidos y diferencia de conciliación.

## Configuración de equipos

No activar equipos por catálogo hasta terminar los checklists de [integraciones](06-integraciones.md). Producción debe usar `integrations.scale.driver=middleware`; el simulador permanece bloqueado. Configurar PDF de contingencia, plantillas aprobadas y prueba de escaneo de cada formato.

La báscula de catálogo elegida debe estar activa, pertenecer a la instalación y tener `adapter_key` y `connection_type` iguales al driver de `.env`. Ajusta `scale.reading_max_age_seconds` (5–3600; inicial 120) sólo después de medir la latencia real. Para impresión física registra una impresora activa y compatible con el driver; PDF/navegador requieren plantilla HTML y Zebra plantilla ZPL.

## Smoke test post-deploy

- Login, recuperación y selección de alcance.
- Catálogo en lectura y permiso denegado con rol limitado.
- Crear viaje/RSA/plan en datos de prueba controlados o staging.
- Estado de báscula/impresora.
- Consulta de inventario/reportes.
- API health autenticada e idempotencia.
- Backup inmediato y consulta de auditoría.

## Checklist go-live

- DNS/TLS/NTP/SMTP operativos.
- `.env` y ACL revisados; debug apagado.
- Migraciones y seed base aplicados una sola vez.
- Usuarios nominales, roles y scopes aprobados.
- Dispositivos reales certificados; simulador apagado.
- Catálogos/capacidades/calidad/lotes validados.
- Migración Excel conciliada y Excel congelado.
- Restore probado; monitoreo y responsables por turno.
- UAT y acta de aceptación firmadas.
