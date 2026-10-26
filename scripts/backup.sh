#!/usr/bin/env bash
set -euo pipefail

: "${GMAR_DB_HOST:?Define GMAR_DB_HOST}"
: "${GMAR_DB_NAME:?Define GMAR_DB_NAME}"
: "${GMAR_DB_USER:?Define GMAR_DB_USER}"
: "${GMAR_DB_PASSWORD:?Define GMAR_DB_PASSWORD}"

backup_dir="${GMAR_BACKUP_DIR:-./backups}"
mkdir -p "$backup_dir"
timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
target="$backup_dir/grupomar-$timestamp.sql.gz"

MYSQL_PWD="$GMAR_DB_PASSWORD" mysqldump --host="$GMAR_DB_HOST" --user="$GMAR_DB_USER" --single-transaction --routines --triggers --set-gtid-purged=OFF "$GMAR_DB_NAME" | gzip -9 > "$target"
sha256sum "$target" > "$target.sha256"
echo "Respaldo creado: $target"
