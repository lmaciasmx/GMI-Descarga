#!/usr/bin/env bash
set -euo pipefail

if [[ ! -f .env ]]; then
  cp .env.example .env
  echo "Se creó .env. Configura base de datos, URL, HTTPS y correo antes de continuar."
  exit 2
fi

mkdir -p \
  writable/cache \
  writable/debugbar \
  writable/logs \
  writable/session \
  writable/uploads \
  writable/labels

composer install --prefer-dist --no-interaction
npm ci
npm run verify
php spark migrate --all
php spark db:seed 'App\Database\Seeds\CoreCatalogSeeder'

echo "Instalación base completada. Crea el primer usuario con:"
echo "php spark app:bootstrap-admin correo@empresa.com usuario"
