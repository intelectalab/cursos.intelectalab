#!/usr/bin/env bash
# Publica la versión de la rama indicada en el VPS. Se ejecuta en el servidor, desde cualquier carpeta.
#
# Uso: deploy.sh [rama]        (por defecto, main)
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/erp}"
BRANCH="${1:-main}"
WEB_USER="${WEB_USER:-www-data}"
# PHP 8.4 por su nombre: en un servidor compartido `php` puede ser otra versión que usa otra aplicación
PHP="${PHP_BIN:-$(command -v php8.4 || command -v php)}"
COMPOSER="$(command -v composer)"

cd "$APP_DIR"
echo "== Código: rama $BRANCH"
git fetch --prune origin
git checkout "$BRANCH"
git pull --ff-only origin "$BRANCH"

echo "== Backend"
cd "$APP_DIR/backend"
"$PHP" "$COMPOSER" install --no-dev --optimize-autoloader --no-interaction
# El sitio responde «en mantenimiento» mientras cambia la base
"$PHP" artisan down --retry=30 || true
"$PHP" artisan migrate --force
"$PHP" artisan access:sync
"$PHP" artisan config:cache
"$PHP" artisan route:cache
"$PHP" artisan event:cache
"$PHP" artisan up

echo "== Frontend"
cd "$APP_DIR/frontend"
npm ci
npm run build

echo "== Permisos y procesos"
chown -R "$WEB_USER":"$WEB_USER" "$APP_DIR/backend/storage" "$APP_DIR/backend/bootstrap/cache"
# El proceso de cola debe tomar el código nuevo
"$PHP" "$APP_DIR/backend/artisan" queue:restart
systemctl reload php8.4-fpm

echo "== Comprobación"
DOMAIN="$(grep -E '^TENANCY_CENTRAL_DOMAIN=' "$APP_DIR/backend/.env" | cut -d= -f2-)"
curl --silent --show-error --fail --output /dev/null "https://$DOMAIN/up" && echo "La plataforma responde en https://$DOMAIN"
