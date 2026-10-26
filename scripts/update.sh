#!/usr/bin/env bash
set -euo pipefail

mkdir -p \
  writable/cache \
  writable/debugbar \
  writable/logs \
  writable/session \
  writable/uploads \
  writable/labels

composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
npm ci
npm run verify
php spark migrate --all
php spark cache:clear

echo "Actualización aplicada. Revisa writable/logs y ejecuta las pruebas de humo."
