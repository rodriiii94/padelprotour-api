#!/usr/bin/env bash
# Crea los roles de base de datos (deploy/db-roles.sql), genera sus contraseñas, actualiza el
# .env de la API y lo verifica. Se ejecuta UNA vez EN el VPS con un usuario con sudo (o root):
#
#   sudo bash /var/www/api/deploy/setup-db-roles.sh           # aplicar
#   sudo bash /var/www/api/deploy/setup-db-roles.sh --revert  # volver al .env anterior
#
# Antes de tocar nada guarda una copia del .env (.env.pre-roles.<fecha>). Volver atrás es
# restaurarla y reiniciar servicios; los roles nuevos no molestan si no se usan.
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/api}"
ENV_FILE="${ENV_FILE:-$APP_DIR/.env}"
PSQL="${PSQL:-sudo -u postgres psql}"      # en pruebas se puede apuntar a otro clúster
APP_USER="${APP_USER:-deploy}"
SKIP_SERVICES="${SKIP_SERVICES:-0}"

env_value() { grep -E "^$1=" "$ENV_FILE" | head -n1 | cut -d= -f2- | tr -d "\"'" || true; }
set_env() { # clave valor: reemplaza o añade
  if grep -qE "^$1=" "$ENV_FILE"; then
    awk -v k="$1" -v v="$2" 'BEGIN{FS=OFS="="} $1==k {print k "=" v; next} {print}' "$ENV_FILE" > "$ENV_FILE.tmp" && cat "$ENV_FILE.tmp" > "$ENV_FILE" && rm -f "$ENV_FILE.tmp"
  else
    printf '%s=%s\n' "$1" "$2" >> "$ENV_FILE"
  fi
}
restart_services() {
  [ "$SKIP_SERVICES" = "1" ] && { echo "(servicios no reiniciados: SKIP_SERVICES=1)"; return; }
  echo "==> recargando configuración y reiniciando servicios"
  sudo -u "$APP_USER" bash -c "cd $APP_DIR && php artisan config:cache && php artisan queue:restart"
  systemctl restart reverb queue-worker
  systemctl reload php8.5-fpm
}

if [ "${1:-}" = "--revert" ]; then
  last="$(ls -1t "$ENV_FILE".pre-roles.* 2>/dev/null | head -n1 || true)"
  [ -n "$last" ] || { echo "No hay copia .env.pre-roles.* que restaurar." >&2; exit 1; }
  cp "$last" "$ENV_FILE"
  echo "Restaurado $last"
  restart_services
  exit 0
fi

DB_NAME="$(env_value DB_DATABASE)"
OWNER="$(env_value DB_MIGRATE_USERNAME)"; [ -n "$OWNER" ] || OWNER="$(env_value DB_USERNAME)"
OWNER_PASS="$(env_value DB_MIGRATE_PASSWORD)"; [ -n "$OWNER_PASS" ] || OWNER_PASS="$(env_value DB_PASSWORD)"
DB_HOST="$(env_value DB_HOST)"; DB_PORT="$(env_value DB_PORT)"
[ -n "$DB_NAME" ] && [ -n "$OWNER" ] || { echo "Faltan DB_DATABASE/DB_USERNAME en $ENV_FILE" >&2; exit 1; }

gen() { openssl rand -base64 36 | tr -d '/+=\n' | cut -c1-32; }
APP_PASS="$(gen)"; RO_PASS="$(gen)"

stamp="$(date +%Y%m%d-%H%M%S)"
cp -p "$ENV_FILE" "$ENV_FILE.pre-roles.$stamp"
echo "==> copia del .env: $ENV_FILE.pre-roles.$stamp"

echo "==> creando roles y permisos"
$PSQL -X -v ON_ERROR_STOP=1 -v app_password="$APP_PASS" -v ro_password="$RO_PASS" \
      -v dbname="$DB_NAME" -v owner="$OWNER" -d postgres -f "$(dirname "$0")/db-roles.sql" >/dev/null

echo "==> actualizando $ENV_FILE"
set_env DB_MIGRATE_USERNAME "$OWNER"
set_env DB_MIGRATE_PASSWORD "$OWNER_PASS"
set_env DB_USERNAME padelprotour_app
set_env DB_PASSWORD "$APP_PASS"
set_env DB_BACKUP_USERNAME padelprotour_ro
set_env DB_BACKUP_PASSWORD "$RO_PASS"

echo "==> verificando permisos (solo lectura, no modifica datos)"
check() { # rol, consulta, esperado (t/f), descripción
  got="$($PSQL -X -A -t -d "$DB_NAME" -c "$2" | tr -d '[:space:]')"
  if [ "$got" = "$3" ]; then echo "  ok   $4"; else echo "  FALLA $4 (esperado $3, obtenido $got)"; failed=1; fi
}
failed=0
for t in users competitions matches registrations chat_messages personal_access_tokens; do
  check app "select has_table_privilege('padelprotour_app','$t','SELECT,INSERT,UPDATE,DELETE')" t "app lee y escribe en $t"
  check app "select has_table_privilege('padelprotour_app','$t','TRUNCATE')" f "app NO puede vaciar $t"
  check ro "select has_table_privilege('padelprotour_ro','$t','SELECT')" t "solo lectura lee $t"
  check ro "select has_table_privilege('padelprotour_ro','$t','INSERT')" f "solo lectura NO escribe en $t"
done
check app "select has_table_privilege('padelprotour_app','migrations','INSERT')" f "app NO escribe en migrations"
check app "select has_schema_privilege('padelprotour_app','public','CREATE')" f "app NO puede crear tablas"
check ro "select has_schema_privilege('padelprotour_ro','public','CREATE')" f "solo lectura NO puede crear tablas"
check app "select has_sequence_privilege('padelprotour_app','users_id_seq','USAGE')" t "app usa la secuencia de users"
check app "select rolsuper or rolcreatedb or rolcreaterole from pg_roles where rolname='padelprotour_app'" f "app sin superusuario/createdb/createrole"
check ro "select rolsuper or rolcreatedb or rolcreaterole from pg_roles where rolname='padelprotour_ro'" f "solo lectura sin superusuario/createdb/createrole"

echo "==> comprobando que las contraseñas nuevas conectan"
for who in "padelprotour_app:$APP_PASS" "padelprotour_ro:$RO_PASS"; do
  u="${who%%:*}"; p="${who#*:}"
  n="$(PGPASSWORD="$p" psql -X -A -t -h "${DB_HOST:-127.0.0.1}" -p "${DB_PORT:-5432}" -U "$u" -d "$DB_NAME" -c 'select count(*) from users' | tr -d '[:space:]')" \
    && echo "  ok   $u conecta (users: $n)" || { echo "  FALLA $u no conecta"; failed=1; }
done

if [ "$failed" = "1" ]; then
  echo "ALGUNA COMPROBACIÓN FALLÓ: restaurando el .env anterior, no se toca nada más."
  cp "$ENV_FILE.pre-roles.$stamp" "$ENV_FILE"
  exit 1
fi

restart_services
echo "Listo. La web, la cola y Reverb usan ya padelprotour_app; las migraciones, $OWNER; los backups, padelprotour_ro."
echo "Si algo fallara: sudo bash deploy/setup-db-roles.sh --revert"
