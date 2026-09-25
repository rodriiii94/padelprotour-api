#!/usr/bin/env bash
# Despliegue repetible. Ejecutar EN el VPS, como el usuario 'deploy',
# dentro de /var/www/api (ver deploy/README.md, paso 8).
#
# Uso: bash deploy/deploy.sh
set -euo pipefail

echo "==> git pull"
git pull origin main

echo "==> composer install"
composer install --no-dev --optimize-autoloader

echo "==> migraciones (con el usuario dueño de las tablas)"
php artisan migrate --force --database=pgsql_migrate

echo "==> enlace público de storage (fotos de perfil)"
php artisan storage:link --force

echo "==> cachear config/rutas"
php artisan config:cache
# config.php es una copia de TODOS los secretos (BD, APP_KEY, Resend): que solo lo lean deploy
# (dueño) y php-fpm (grupo www-data), no cualquier usuario del servidor.
chgrp www-data bootstrap/cache/config.php
chmod 640 bootstrap/cache/config.php
php artisan route:cache

echo "==> reiniciar servicios"
# queue:work mantiene el código anterior en memoria mientras corre --
# hay que avisarle explícitamente de que hay deploy nuevo.
php artisan queue:restart
sudo systemctl restart reverb
sudo systemctl reload php8.5-fpm

echo "==> hecho"
