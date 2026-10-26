#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 2 || "$2" != "--confirm" || ! -f "$1" ]]; then
  echo "Uso: scripts/restore.sh archivo.sql.gz --confirm"
  exit 2
fi
: "${GMAR_DB_HOST:?Define GMAR_DB_HOST}"
: "${GMAR_DB_NAME:?Define GMAR_DB_NAME}"
: "${GMAR_DB_USER:?Define GMAR_DB_USER}"
: "${GMAR_DB_PASSWORD:?Define GMAR_DB_PASSWORD}"

gzip -dc "$1" | MYSQL_PWD="$GMAR_DB_PASSWORD" mysql --host="$GMAR_DB_HOST" --user="$GMAR_DB_USER" "$GMAR_DB_NAME"
echo "Restauración terminada. Ejecuta php spark migrate:status y las pruebas de humo."
