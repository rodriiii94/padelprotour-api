#!/usr/bin/env bash
# Aprovisiona un VPS Ubuntu LTS recién creado para servir la API
# Laravel (PHP-FPM), el build estático de la web (Nginx), Postgres, Reverb
# y el worker de colas. Ejecutar UNA VEZ, como root, en un servidor limpio.
#
# Uso: bash setup.sh
set -euo pipefail

echo "==> Actualizando el sistema"
apt-get update && apt-get upgrade -y

echo "==> Instalando PHP y extensiones (de los repos por defecto de esta Ubuntu)"
apt-get install -y php-fpm php-cli php-pgsql php-mbstring \
    php-xml php-curl php-zip php-bcmath php-intl php-gd

echo "==> Instalando Postgres"
apt-get install -y postgresql postgresql-contrib

echo "==> Instalando Nginx"
apt-get install -y nginx

echo "==> Instalando Certbot (SSL gratis)"
apt-get install -y certbot python3-certbot-nginx

echo "==> Instalando Composer"
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

echo "==> Instalando Node (para compilar el build web de Expo si se hace en el propio VPS)"
curl -fsSL https://deb.nodesource.com/setup_lts.x | bash -
apt-get install -y nodejs

echo "==> Creando el usuario 'deploy' (sin privilegios root) para clonar y desplegar la app"
if ! id -u deploy >/dev/null 2>&1; then
    adduser --disabled-password --gecos "" deploy
    usermod -aG www-data deploy
fi
mkdir -p /var/www/api /var/www/web
chown -R deploy:www-data /var/www/api /var/www/web

echo "==> Listo. Siguiente paso: crear la base de datos (ver deploy/README.md, paso 2)"
