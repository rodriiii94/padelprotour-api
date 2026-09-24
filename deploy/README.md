# Despliegue en un VPS (Hostinger)

Guía paso a paso para poner `padelprotour.net` (API + web) en un único VPS. Arquitectura:
un solo Nginx, dos subdominios (`padelprotour.net` sirve el build estático de Expo web,
`api.padelprotour.net` hace de proxy a PHP-FPM/Laravel), Postgres en el mismo VPS. No hace
falta Redis: cache y colas ya usan el driver `database` en `.env.example`, suficiente a
este tamaño — instalarlo es opcional, no un requisito.

## 0. Antes de nada (fuera de este repo)

1. Crea el VPS en Hostinger: cualquier Ubuntu LTS reciente, el plan KVM más pequeño sobra
   (2 vCPU / 4GB). Apunta la IP que te den.
2. En el panel DNS de tu dominio, crea registros `A`:
   - `padelprotour.net` → IP del VPS
   - `www.padelprotour.net` → IP del VPS
   - `api.padelprotour.net` → IP del VPS
3. Espera a que propague (unos minutos, a veces horas) antes de pedir certificados SSL.

## 1. Aprovisionar el servidor (una sola vez)

Conéctate por SSH como root y copia `deploy/setup.sh` al servidor, o pégalo directamente:

```bash
scp deploy/setup.sh root@TU_IP:/root/setup.sh
ssh root@TU_IP 'bash /root/setup.sh'
```

Instala PHP (+FPM, el de los repos por defecto de la Ubuntu del VPS), Postgres, Nginx,
Certbot, Composer y Node (para compilar el build
web de Expo). Crea el usuario `deploy` (sin privilegios root) que usarán los servicios y
los despliegues siguientes.

## 2. Base de datos

```bash
sudo -u postgres createuser --pwprompt padelprotour
sudo -u postgres createdb --owner=padelprotour padelprotour
```

Guarda esa contraseña — va en `DB_PASSWORD` del `.env` de producción.

## 3. Clonar y configurar la app

```bash
sudo -u deploy git clone git@github.com:rodriiii94/padelprotour-api.git /var/www/api
cd /var/www/api
sudo -u deploy cp .env.production.example .env
# Edita .env: DB_PASSWORD, APP_KEY (lo genera el siguiente comando), RESEND_API_KEY,
# GOOGLE_CLIENT_IDS, APPLE_CLIENT_IDS, REVERB_APP_ID/KEY/SECRET (genera 3 valores
# aleatorios propios, no reutilices los de local).
sudo -u deploy php artisan key:generate
sudo -u deploy composer install --no-dev --optimize-autoloader
sudo -u deploy php artisan migrate --force
sudo -u deploy php artisan config:cache
sudo -u deploy php artisan route:cache
# storage/ y bootstrap/cache/ los crea 'deploy' (por el git clone), pero
# PHP-FPM corre como 'www-data' -- sin esto, cualquier request que loguee
# o cachee algo da 500.
chown -R deploy:www-data storage bootstrap/cache
chmod -R ug+rwx storage bootstrap/cache
```

## 4. Nginx + SSL

```bash
sudo cp deploy/nginx.conf /etc/nginx/sites-available/padelprotour
sudo ln -s /etc/nginx/sites-available/padelprotour /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d padelprotour.net -d www.padelprotour.net -d api.padelprotour.net
```

Certbot reescribe `nginx.conf` para añadir los bloques HTTPS y el redirect automático —
normal, no lo deshagas luego.

## 5. Reverb (WebSockets) y el worker de colas

```bash
sudo cp deploy/reverb.service deploy/queue-worker.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now reverb queue-worker
```

## 6. El frontend web

Desde tu máquina (o donde compiles), en el repo `padelontour-app`:

```bash
./deploy/deploy-web.sh TU_IP
```

Exporta el build estático (`npx expo export --platform web`) y lo sube por `rsync` a
`/var/www/web` en el VPS, que es lo que sirve Nginx en `padelprotour.net`.

## 7. Resend (desbloquea el envío de emails a cualquier usuario)

En [resend.com/domains](https://resend.com/domains) añade `padelprotour.net`, mete los
registros SPF/DKIM que te den en el DNS del dominio, y cuando lo verifique cambia en el
`.env` de producción:

```
MAIL_FROM_ADDRESS="no-reply@padelprotour.net"
```

(Hasta entonces, sigue en modo sandbox: solo te llegan emails a ti mismo.)

## 8. Despliegues siguientes

Una vez montado todo lo anterior, cada actualización es:

```bash
ssh deploy@TU_IP 'cd /var/www/api && bash deploy/deploy.sh'
```

## 9. Verificación

- `https://api.padelprotour.net/up` → 200 (health check de Laravel).
- `https://padelprotour.net` → carga la web.
- Registro + login desde la web real.
- Algo que dispare un evento de Reverb (p.ej. actualizar un partido) y comprobar que
  llega en tiempo real sin recargar.
