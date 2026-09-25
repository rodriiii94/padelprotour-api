#!/usr/bin/env bash
# Chequeo de caídas. Corre como `deploy` desde cron cada 5 minutos.
# Comprueba web, API, servicios y disco; avisa por email (Resend) SOLO cuando el estado
# cambia (cae / se recupera), no cada 5 minutos mientras siga caído.
#
# Necesita en /var/www/api/.env:  ALERT_EMAIL=tu@correo   (y RESEND_API_KEY, que ya existe)
set -uo pipefail

APP_DIR="${APP_DIR:-/var/www/api}"
STATE_FILE="${STATE_FILE:-$HOME/.healthcheck-state}"

env_value() { grep -E "^$1=" "$APP_DIR/.env" | head -n1 | cut -d= -f2- | tr -d "\"'"; }

problems=()

http_ok() { # url
  local code
  code=$(curl -s -o /dev/null -m 15 -w '%{http_code}' "$1" || true)
  [ "$code" = "200" ] || { problems+=("$1 responde ${code:-sin respuesta}"); return 1; }
}

http_ok "https://api.padelprotour.net/up"
http_ok "https://padelprotour.net/"

for service in nginx php8.5-fpm postgresql@18-main queue-worker reverb; do
  systemctl is-active --quiet "$service" || problems+=("servicio $service caído")
done

usage=$(df --output=pcent / | tail -n1 | tr -dc '0-9')
[ "${usage:-0}" -lt 90 ] || problems+=("disco al ${usage}%")

latest=$(find "$HOME/backups" -name 'padelprotour-*.sql.gz' -mtime -2 2>/dev/null | head -n1)
[ -n "$latest" ] || problems+=("no hay backup de las últimas 48 h")

if [ ${#problems[@]} -eq 0 ]; then status="ok"; else status="fail"; fi
previous=$(cat "$STATE_FILE" 2>/dev/null || echo ok)
[ "$status" != "$previous" ] || exit 0   # sin cambios: no se avisa
echo "$status" > "$STATE_FILE"

send_mail() { # asunto, cuerpo
  local to key
  to=$(env_value ALERT_EMAIL); key=$(env_value RESEND_API_KEY)
  [ -n "$to" ] && [ -n "$key" ] || { echo "falta ALERT_EMAIL o RESEND_API_KEY" >&2; return 1; }
  curl -s -m 20 https://api.resend.com/emails \
    -H "Authorization: Bearer $key" -H 'Content-Type: application/json' \
    -d "$(printf '{"from":"PadelProTour <no-reply@padelprotour.net>","to":["%s"],"subject":"%s","text":"%s"}' "$to" "$1" "$2")" \
    >/dev/null
}

if [ "$status" = "fail" ]; then
  body=$(printf '%s\\n' "${problems[@]}")
  send_mail "[PadelProTour] Problema en producción" "$(date -Is)\\n$body"
else
  send_mail "[PadelProTour] Recuperado" "$(date -Is)\\nTodo vuelve a responder con normalidad."
fi
