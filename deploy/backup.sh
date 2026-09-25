#!/usr/bin/env bash
# Backup diario de Postgres. Corre como el usuario `deploy` desde cron.
# Guarda un volcado comprimido en ~/backups y borra los de más de 14 días.
#
# Restaurar (en una base VACÍA):
#   gunzip -c ~/backups/padelprotour-AAAA-MM-DD_HHMM.sql.gz | psql -h 127.0.0.1 -U padelprotour padelprotour
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/api}"
BACKUP_DIR="${BACKUP_DIR:-$HOME/backups}"
KEEP_DAYS="${KEEP_DAYS:-14}"

env_value() { grep -E "^$1=" "$APP_DIR/.env" | head -n1 | cut -d= -f2- | tr -d "\"'"; }

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

file="$BACKUP_DIR/padelprotour-$(date +%F_%H%M).sql.gz"
tmp="$file.partial"

PGPASSWORD="$(env_value DB_PASSWORD)" pg_dump \
  -h "$(env_value DB_HOST)" -p "$(env_value DB_PORT)" \
  -U "$(env_value DB_USERNAME)" --no-owner --no-privileges \
  "$(env_value DB_DATABASE)" | gzip -9 > "$tmp"

# Un volcado vacío o truncado no es un backup: no lo damos por bueno.
gzip -t "$tmp"
[ "$(stat -c %s "$tmp")" -gt 1024 ] || { echo "backup sospechosamente pequeño" >&2; rm -f "$tmp"; exit 1; }
mv "$tmp" "$file"
chmod 600 "$file"

find "$BACKUP_DIR" -name 'padelprotour-*.sql.gz' -mtime +"$KEEP_DAYS" -delete
echo "$(date -Is) backup ok: $file ($(du -h "$file" | cut -f1))"
