# Verificación de la entrega 1.0.0

Fecha de corte: 2026-09-13.

## Alcance comprobado en esta entrega

El paquete contiene una aplicación CodeIgniter 4 instalable, con código de dominio, interfaz web, migrations, seeders, API, pruebas automatizadas, infraestructura y documentación. No contiene credenciales reales, dependencias PHP vendorizadas ni datos operativos del cliente.

La inspección reproducible incluida en `npm run verify` comprueba:

- sintaxis de todos los archivos PHP mediante parser;
- sintaxis de los 19 archivos JavaScript de aplicación;
- construcción de 19 recursos frontend locales;
- 105 destinos de rutas contra controladores y métodos reales;
- correspondencia exacta de 39 operaciones externas con OpenAPI;
- 225 imports internos y 21 referencias de vistas;
- 57 tablas, 1 vista y 131 llaves foráneas de dominio;
- 786 campos literales de `insert/update` contra el esquema de migrations;
- enlaces documentales locales y ausencia de marcadores de implementación pendiente;
- ausencia de JavaScript inline incompatible con la CSP en vistas.

También se validaron como archivos estructurados `package.json`, `package-lock.json`, `manifest.webmanifest`, `compose.yaml`, el workflow de CI y `docs/api/openapi.yaml`; los scripts Bash pasan `bash -n`.

## Pruebas incluidas

La suite contiene 48 escenarios unitarios y de integración para fórmulas de peso/castigo, lotes, calidad, estados, permisos, báscula, pesaje, etiquetas, recepción, inventario, reservas/surtido, conteos, traspasos entre instalaciones, idempotencia, facturación aislada y cierre/cancelación de planes y mareas.

En el entorno donde se preparó este paquete no estaban disponibles PHP, Composer, MySQL ni Docker. Por ello se ejecutaron el parser PHP, JavaScript, build, auditorías de esquema/rutas/OpenAPI y validadores YAML/JSON/Bash, pero **no se ejecutó PHPUnit contra MySQL**. La entrega incluye `.github/workflows/quality.yml`, que instala PHP 8.2, levanta MySQL 8.4 y ejecuta la suite completa.

## Comandos de aceptación en un entorno con PHP/MySQL

Usar exclusivamente una base vacía de pruebas:

```bash
composer install --prefer-dist --no-interaction
npm ci
npm run verify
vendor/bin/phpcs --standard=PSR12 app tests
vendor/bin/phpunit
```

Después, en staging:

```bash
cp .env.example .env
# Configurar URL, MySQL, encryption.key, correo y dispositivos.
php spark migrate --all
php spark db:seed 'App\Database\Seeds\CoreCatalogSeeder'
php spark app:bootstrap-admin admin@empresa.com administrador
php spark migrate:status
```

La aceptación final requiere además UAT, prueba de restauración, pruebas de concurrencia con conexiones independientes y certificación en sitio de la báscula/impresora reales.

## Límites deliberados

- Los adapters RS-232, USB serial, TCP y Zebra no inventan protocolos; fallan de forma explícita hasta configurar el middleware y el equipo confirmado.
- El simulador está identificado y bloqueado por defecto en producción.
- La clasificación automática de calidad permanece apagada por el traslape del 15 % documentado.
- La PWA sólo almacena recursos estáticos: no confirma pesajes, recepciones ni inventario sin conexión.
- INVoiice/CFDI permanece fiscalmente desacoplado y nunca altera existencia física.

## Criterio de liberación

No promover a producción si PHPUnit/PHPCS/CI no están verdes, si falta un protocolo de equipo certificado, si no se ha conciliado la migración Excel o si no existe evidencia de restore. Los pendientes externos y sus responsables están enumerados en [matriz de cobertura](12-matriz-cobertura.md).
