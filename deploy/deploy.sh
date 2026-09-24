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

echo "==> migraciones"
php artisan migrate --force

echo "==> cachear config/rutas"
php artisan config:cache
php artisan route:cache

echo "==> reiniciar servicios"
# queue:work mantiene el código anterior en memoria mientras corre --
# hay que avisarle explícitamente de que hay deploy nuevo.
php artisan queue:restart
sudo systemctl restart reverb
sudo systemctl reload php8.3-fpm

echo "==> hecho"
