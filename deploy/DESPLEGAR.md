# Cómo subir cambios al VPS

Guía del día a día para desplegar sin depender de nadie. La primera instalación (desde
cero) está en [README.md](README.md); esto es para cuando ya está todo montado.

- **VPS**: `179.198.210.237` (Hostinger)
- **API**: https://api.padelprotour.net — repo `padelprotour-api`, vive en `/var/www/api`
- **Web**: https://padelprotour.net — repo `padelontour-app`, vive en `/var/www/web`
- **Usuario de despliegue**: `deploy` (entras por SSH con tu clave, sin contraseña)

## Resumen rápido

```bash
# API (tras hacer git push a main)
ssh deploy@179.198.210.237 'cd /var/www/api && bash deploy/deploy.sh'

# Web (desde la carpeta del repo padelontour-app)
./deploy/deploy-web.sh 179.198.210.237
```

## 1. Desplegar la API (Laravel)

1. Haz commit y **push a `main`**. El VPS hace `git pull` desde GitHub: lo que no esté
   subido a GitHub no llega.
2. Lanza el script:

   ```bash
   ssh deploy@179.198.210.237 'cd /var/www/api && bash deploy/deploy.sh'
   ```

Qué hace, en orden: `git pull` → `composer install --no-dev` → `migrate --force` →
`config:cache` y `route:cache` → avisa al worker de colas → reinicia Reverb → recarga
PHP-FPM. Termina con `==> hecho`. Si falla a mitad, puedes volver a lanzarlo sin miedo:
es seguro repetirlo.

**Cuidado con las migraciones**: se ejecutan solas en cada despliegue. Si una migración
borra columnas o datos, no hay marcha atrás automática — haz un backup antes (apartado 5).

## 2. Cambiar la configuración (`.env`)

El `.env` de producción existe **solo en el VPS** (no está en git, lleva secretos). Para
cambiarlo:

```bash
ssh deploy@179.198.210.237
nano /var/www/api/.env          # edita y guarda con Ctrl+O, Enter, Ctrl+X
cd /var/www/api
php artisan config:cache        # imprescindible: Laravel lee la config cacheada, no el .env
php artisan queue:restart
sudo systemctl restart reverb
```

`.env.production.example` (en el repo) es la plantilla de referencia con todas las
variables y qué significan.

## 3. Desplegar la web (Expo web)

Desde la carpeta del repo `padelontour-app`:

```bash
./deploy/deploy-web.sh 179.198.210.237
```

Exporta el build con `.env.production` (que apunta a `https://api.padelprotour.net/api`) y
lo sube por `rsync` a `/var/www/web`. No hay que reiniciar nada.

- Si cambias la URL de la API o los Client ID de Google, edita `.env.production` **antes**.
- `rsync --delete` sustituye por completo el contenido de `/var/www/web` por lo que haya
  en `dist/`: no guardes nada a mano ahí.

## 4. Ver qué pasa (estado y logs)

```bash
# ¿Están vivos los servicios? (5 líneas "active" = todo bien)
ssh deploy@179.198.210.237 'systemctl is-active reverb queue-worker nginx php8.5-fpm postgresql'

# ¿Responde la API?
curl https://api.padelprotour.net/up

# Errores de Laravel (si el fichero no existe, es que no ha habido errores)
ssh deploy@179.198.210.237 'tail -n 50 /var/www/api/storage/logs/laravel.log'

# Reverb y worker de colas
ssh deploy@179.198.210.237 'journalctl -u reverb -n 50 --no-pager'
ssh deploy@179.198.210.237 'journalctl -u queue-worker -n 50 --no-pager'

# Errores de Nginx (este fichero solo lo lee root)
ssh root@179.198.210.237 'tail -n 50 /var/log/nginx/error.log'
```

Si la API da 500 y no hay nada en `laravel.log`, casi siempre es un problema de permisos
en `storage/` o `bootstrap/cache/` — mira el bloque de `chown`/`chmod` del paso 3 de
[README.md](README.md).

## 5. Backups de la base de datos

**Ahora mismo no hay backups automáticos** — pendiente de montar. Mientras tanto, uno
manual (se guarda en tu máquina):

```bash
ssh root@179.198.210.237 'sudo -u postgres pg_dump padelprotour' > backup-$(date +%F).sql
```

Restaurar (¡sobrescribe datos!): `psql padelprotour < backup-AAAA-MM-DD.sql` desde el VPS
como el usuario `postgres`. Además, Hostinger ofrece snapshots del VPS desde su panel
(VPS → Copias de seguridad): activar uno antes de cambios grandes es buena idea.

## 6. Volver atrás si algo sale mal

Lo más seguro es deshacer el commit malo y redesplegar:

```bash
git revert <hash-del-commit>    # crea un commit que lo deshace
git push origin main
ssh deploy@179.198.210.237 'cd /var/www/api && bash deploy/deploy.sh'
```

Las migraciones **no** se deshacen solas. Si hace falta, en el VPS:
`php artisan migrate:rollback --step=1` — solo si sabes qué migración es y qué datos toca.

## 7. Seguridad — pendiente de hacer

- **Cambia la contraseña de root del VPS**: se pegó en un chat, así que considérala
  comprometida. Hostinger → VPS → cambiar contraseña de root.
- **Desactiva el login SSH por contraseña** (ya entras con clave): en `/etc/ssh/sshd_config`
  pon `PasswordAuthentication no` y `systemctl restart ssh`. Antes de cerrar la sesión,
  abre otra ventana y comprueba que sigues entrando con tu clave, para no quedarte fuera.
- **Rota la API key de Resend** (también se pegó en un chat): créala nueva en el dashboard
  de Resend, cámbiala en el `.env` del VPS (apartado 2) y borra la antigua.
- La *deploy key* que usa el VPS para clonar de GitHub es de solo lectura: aunque se
  filtrara, no permite modificar el repo.

## Backups de la base de datos

`deploy/backup.sh` hace un `pg_dump` comprimido en `/home/deploy/backups` (permisos 700),
comprueba que el archivo no está vacío ni corrupto y borra los de más de 14 días.

Programarlo (una sola vez, como `deploy`, todos los días a las 03:15):

```bash
ssh deploy@179.198.210.237 '(crontab -l 2>/dev/null; echo "15 3 * * * /bin/bash /var/www/api/deploy/backup.sh >> /home/deploy/backups/backup.log 2>&1") | crontab -'
```

Ver que funciona: `ssh deploy@179.198.210.237 'tail -n 5 ~/backups/backup.log; ls -lh ~/backups'`.

Restaurar en una base **vacía** (nunca sobre la que está en uso):

```bash
gunzip -c ~/backups/padelprotour-AAAA-MM-DD_HHMM.sql.gz | psql -h 127.0.0.1 -U padelprotour padelprotour
```

Los backups viven en el mismo disco que la base de datos: protegen de borrados y errores, no de
perder el servidor. Copia de vez en cuando uno a tu Mac:
`scp deploy@179.198.210.237:backups/padelprotour-*.sql.gz ~/Backups/`.

## Planificador de tareas (scheduler de Laravel)

Algunas tareas corren solas (p. ej. confirmar resultados propuestos a las 48 h). Laravel las
ejecuta con un único cron por minuto, que se instala una sola vez como `deploy`:

```bash
ssh deploy@179.198.210.237 '(crontab -l 2>/dev/null; echo "* * * * * cd /var/www/api && php artisan schedule:run >> /dev/null 2>&1") | crontab -'
```

Ver qué tareas hay programadas: `ssh deploy@179.198.210.237 'cd /var/www/api && php artisan schedule:list'`.

## Roles de la base de datos (mínimo privilegio)

Antes, la web, las migraciones y los backups usaban el mismo usuario de Postgres, que además es
**dueño de todas las tablas**: con él se puede hacer `DROP TABLE` o `TRUNCATE`. Ahora son tres:

| Rol | Lo usa | Puede |
|---|---|---|
| `padelprotour` (dueño) | solo las migraciones (`deploy.sh`) | todo sobre su base |
| `padelprotour_app` | la web, la cola y Reverb | leer/insertar/modificar/borrar **datos**; nada de estructura. `migrations` solo lectura |
| `padelprotour_ro` | los backups (`backup.sh`) y consultas de administración | solo `SELECT` |

Postgres solo escucha en `127.0.0.1`, no hay acceso desde internet.

**Aplicarlo (una sola vez, en el VPS, con un usuario con sudo):**

```bash
ssh deploy@179.198.210.237 'cd /var/www/api && git pull'
ssh <usuario-con-sudo>@179.198.210.237 'sudo bash /var/www/api/deploy/setup-db-roles.sh'
```

El script guarda antes una copia del `.env` (`.env.pre-roles.<fecha>`), crea los roles con
contraseñas aleatorias, actualiza `DB_USERNAME`/`DB_PASSWORD` (app), `DB_MIGRATE_*` (dueño) y
`DB_BACKUP_*` (solo lectura) en el `.env`, **comprueba los permisos** y solo entonces reinicia la
web, la cola y Reverb. Si alguna comprobación falla, restaura el `.env` y no toca nada más.

**Volver atrás:** `sudo bash /var/www/api/deploy/setup-db-roles.sh --revert` restaura el último
`.env` guardado y reinicia los servicios. Los roles nuevos no molestan si no se usan.

**Después:**
- Las migraciones siguen funcionando igual: `deploy.sh` usa la conexión `pgsql_migrate` (el dueño)
  y las tablas nuevas dan permisos solas al rol de la app y al de solo lectura.
- Para consultar datos a mano usa `padelprotour_ro` (`DB_BACKUP_*` del `.env`), no el dueño.
- Si alguna vez quieres cambiar estructura a mano, hazlo con el dueño (`DB_MIGRATE_*`).
- `php artisan tinker` usa el rol de la app: lee y escribe datos, pero no puede cambiar la estructura.
- Un dump de `padelprotour_ro` incluye todos los datos (también los hashes de contraseña): guárdalo
  como ya se hace (`~/backups`, permisos 700).
