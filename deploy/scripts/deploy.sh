#!/usr/bin/env bash
# Publica la versión de la rama indicada en el VPS. Se ejecuta en el servidor, desde cualquier carpeta.
#
# Uso: deploy.sh [rama]        (por defecto, main)
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/erp}"
BRANCH="${1:-main}"
WEB_USER="${WEB_USER:-www-data}"

cd "$APP_DIR"
echo "== Código: rama $BRANCH"
git fetch --prune origin
git checkout "$BRANCH"
git pull --ff-only origin "$BRANCH"

echo "== Backend"
cd "$APP_DIR/backend"
composer install --no-dev --optimize-autoloader --no-interaction
# El sitio responde «en mantenimiento» mientras cambia la base
php artisan down --retry=30 || true
php artisan migrate --force
php artisan access:sync
php artisan config:cache
php artisan route:cache
php artisan event:cache
php artisan up

echo "== Frontend"
cd "$APP_DIR/frontend"
npm ci
npm run build

echo "== Permisos y procesos"
chown -R "$WEB_USER":"$WEB_USER" "$APP_DIR/backend/storage" "$APP_DIR/backend/bootstrap/cache"
# El proceso de cola debe tomar el código nuevo
php "$APP_DIR/backend/artisan" queue:restart
systemctl reload php8.4-fpm

echo "== Comprobación"
DOMAIN="$(grep -E '^TENANCY_CENTRAL_DOMAIN=' "$APP_DIR/backend/.env" | cut -d= -f2-)"
curl --silent --show-error --fail --output /dev/null "https://$DOMAIN/up" && echo "La plataforma responde en https://$DOMAIN"
