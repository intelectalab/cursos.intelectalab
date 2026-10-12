#!/usr/bin/env bash
# Copia de seguridad: la base de datos y los archivos de las academias.
# Guarda una copia por día y borra las de más de RETENTION_DAYS días.
#
# Uso: backup.sh            (lo llama cron; ver deploy/cron/erp)
# Variables opcionales (en /etc/erp-backup.env):
#   BACKUP_DIR       dónde se guardan las copias            (por defecto /var/backups/erp)
#   RETENTION_DAYS   cuántos días se conservan              (por defecto 30)
#   REMOTE           destino de rclone para la copia externa, p. ej. "respaldo:erp" (vacío = no se envía)
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/erp}"
[ -f /etc/erp-backup.env ] && . /etc/erp-backup.env
BACKUP_DIR="${BACKUP_DIR:-/var/backups/erp}"
RETENTION_DAYS="${RETENTION_DAYS:-30}"
REMOTE="${REMOTE:-}"

ENV_FILE="$APP_DIR/backend/.env"
read_env() { grep -E "^$1=" "$ENV_FILE" | head -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }

DB_HOST="$(read_env DB_HOST)"
DB_PORT="$(read_env DB_PORT)"
DB_NAME="$(read_env DB_DATABASE)"
DB_USER="$(read_env DB_USERNAME)"
export PGPASSWORD="$(read_env DB_PASSWORD)"

STAMP="$(date +%Y-%m-%d_%H%M)"
TARGET="$BACKUP_DIR/$STAMP"
mkdir -p "$TARGET"
chmod 700 "$BACKUP_DIR"

echo "[$(date '+%F %T')] Copia $STAMP: base de datos"
# Formato propio de PostgreSQL: comprimido y restaurable tabla por tabla con pg_restore
pg_dump --host="$DB_HOST" --port="$DB_PORT" --username="$DB_USER" --format=custom --no-owner --file="$TARGET/base.dump" "$DB_NAME"

echo "[$(date '+%F %T')] Copia $STAMP: archivos de las academias"
tar --create --gzip --file="$TARGET/archivos.tar.gz" --directory="$APP_DIR/backend/storage/app" private

# Sin esta clave no se pueden leer las claves de las pasarelas guardadas en la base
cp "$ENV_FILE" "$TARGET/env.copia"
chmod 600 "$TARGET"/*

# Una copia vacía o cortada no sirve: se comprueba antes de dar la copia por buena
pg_restore --list "$TARGET/base.dump" > /dev/null
tar --list --gzip --file="$TARGET/archivos.tar.gz" > /dev/null
du -sh "$TARGET" | sed "s/^/[$(date '+%F %T')] Tamaño: /"

if [ -n "$REMOTE" ]; then
    echo "[$(date '+%F %T')] Enviando la copia a $REMOTE"
    rclone copy "$TARGET" "$REMOTE/$STAMP"
fi

# Limpieza de las copias antiguas
find "$BACKUP_DIR" -mindepth 1 -maxdepth 1 -type d -mtime +"$RETENTION_DAYS" -exec rm -rf {} +

echo "[$(date '+%F %T')] Copia $STAMP terminada"
