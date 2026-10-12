#!/usr/bin/env bash
# Restaura una copia hecha con backup.sh. REEMPLAZA la base de datos y los archivos actuales.
#
# Uso: restore.sh /var/backups/erp/2026-10-11_0230
# Para ensayar sin tocar producción: DB_DATABASE=erp_ensayo FILES_DIR=/tmp/ensayo restore.sh <copia>
set -euo pipefail

SOURCE="${1:?Indica la carpeta de la copia, por ejemplo /var/backups/erp/2026-10-11_0230}"
APP_DIR="${APP_DIR:-/var/www/erp}"
ENV_FILE="$APP_DIR/backend/.env"
read_env() { grep -E "^$1=" "$ENV_FILE" | head -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }

DB_HOST="$(read_env DB_HOST)"
DB_PORT="$(read_env DB_PORT)"
DB_NAME="${DB_DATABASE:-$(read_env DB_DATABASE)}"
DB_USER="$(read_env DB_USERNAME)"
export PGPASSWORD="$(read_env DB_PASSWORD)"
FILES_DIR="${FILES_DIR:-$APP_DIR/backend/storage/app}"

[ -f "$SOURCE/base.dump" ] || { echo "No existe $SOURCE/base.dump"; exit 1; }

echo "Se va a REEMPLAZAR la base «$DB_NAME» y los archivos de «$FILES_DIR» con la copia $SOURCE."
read -r -p "Escribe RESTAURAR para continuar: " answer
[ "$answer" = "RESTAURAR" ] || { echo "Cancelado."; exit 1; }

echo "Restaurando la base de datos…"
pg_restore --host="$DB_HOST" --port="$DB_PORT" --username="$DB_USER" --dbname="$DB_NAME" --clean --if-exists --no-owner "$SOURCE/base.dump"

echo "Restaurando los archivos…"
mkdir -p "$FILES_DIR"
tar --extract --gzip --file="$SOURCE/archivos.tar.gz" --directory="$FILES_DIR"

echo "Listo. Si la clave APP_KEY del servidor no es la de la copia ($SOURCE/env.copia), las academias tendrán que volver a conectar sus pasarelas de pago."
