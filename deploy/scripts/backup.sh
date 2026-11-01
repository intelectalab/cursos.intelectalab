#!/usr/bin/env bash
# Copia de seguridad: la base de datos y los archivos de las academias.
# Guarda una copia por día y borra las de más de RETENTION_DAYS días.
#
# Uso: backup.sh            (lo llama cron; ver deploy/cron/erp)
# Variables opcionales (en /etc/erp-backup.env):
#   BACKUP_DIR       dónde se guardan las copias            (por defecto /var/backups/erp)
#   RETENTION_DAYS   cuántos días se conservan              (por defecto 30)
#   REMOTE           destino de rclone para la copia externa, p. ej. "respaldo:erp" (vacío = no se envía)
#   WEEKLY_DAY       día de la semana del respaldo descargable, 1 = lunes … 7 = domingo (por defecto 7)
#   WEEKLY_KEEP      cuántos respaldos semanales se conservan               (por defecto 8)
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/erp}"
[ -f /etc/erp-backup.env ] && . /etc/erp-backup.env
BACKUP_DIR="${BACKUP_DIR:-/var/backups/erp}"
RETENTION_DAYS="${RETENTION_DAYS:-30}"
REMOTE="${REMOTE:-}"
WEEKLY_DAY="${WEEKLY_DAY:-7}"
WEEKLY_KEEP="${WEEKLY_KEEP:-8}"
# Donde la consola de la plataforma los lista para descargarlos (config tenancy.backups_path)
WEEKLY_DIR="$APP_DIR/backend/storage/app/backups"

ENV_FILE="$APP_DIR/backend/.env"
read_env() { grep -E "^$1=" "$ENV_FILE" | head -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }

DB_HOST="$(read_env DB_HOST)"
DB_PORT="$(read_env DB_PORT)"
DB_NAME="$(read_env DB_DATABASE)"
DB_USER="$(read_env DB_USERNAME)"
export PGPASSWORD="$(read_env DB_PASSWORD)"
# El usuario de la aplicación no puede saltarse el aislamiento entre academias (RLS): para copiar
# todas las filas activa el permiso que la propia política reconoce, solo en esta conexión
export PGOPTIONS="-c app.bypass_rls=on"

STAMP="$(date +%Y-%m-%d_%H%M)"
TARGET="$BACKUP_DIR/$STAMP"
mkdir -p "$TARGET"
chmod 700 "$BACKUP_DIR"

# Bitácora que lee la consola de la plataforma (Respaldos): una línea JSON por respaldo, bueno o fallido
STARTED="$(date -Iseconds)"
LOG_FILE="$WEEKLY_DIR/estado.jsonl"
mkdir -p "$WEEKLY_DIR"
log_entry() { # tipo, bueno (true|false), tamaño en bytes, archivo, error
    local size="${3:-null}" file="null" error="null"
    [ -n "$4" ] && file="\"$4\""
    # Sin comillas ni barras en el mensaje: la línea debe seguir siendo JSON válido
    [ -n "$5" ] && error="\"$(printf '%s' "$5" | tr -d '"\\')\""
    printf '{"kind":"%s","started_at":"%s","finished_at":"%s","ok":%s,"size":%s,"file":%s,"error":%s,"by":null}\n' \
        "$1" "$STARTED" "$(date -Iseconds)" "$2" "$size" "$file" "$error" >> "$LOG_FILE"
    chown www-data:www-data "$LOG_FILE" "$WEEKLY_DIR"
    chmod 600 "$LOG_FILE"
}
# Si algo falla, queda anotado (y la consola avisa por correo a la mañana siguiente)
trap 'log_entry DAILY false "" "" "Falló en la línea $LINENO del script; ver /var/log/erp-backup.log"' ERR

echo "[$(date '+%F %T')] Copia $STAMP: base de datos"
# Formato propio de PostgreSQL: comprimido y restaurable tabla por tabla con pg_restore
# --enable-row-security: sin él pg_dump se niega a leer tablas con RLS forzado
pg_dump --host="$DB_HOST" --port="$DB_PORT" --username="$DB_USER" --format=custom --no-owner --enable-row-security --file="$TARGET/base.dump" "$DB_NAME"

echo "[$(date '+%F %T')] Copia $STAMP: archivos de las academias"
tar --create --gzip --file="$TARGET/archivos.tar.gz" --directory="$APP_DIR/backend/storage/app" private

# Sin esta clave no se pueden leer las claves de las pasarelas guardadas en la base
cp "$ENV_FILE" "$TARGET/env.copia"
chmod 600 "$TARGET"/*

# Una copia vacía o cortada no sirve: se comprueba antes de dar la copia por buena
pg_restore --list "$TARGET/base.dump" > /dev/null
tar --list --gzip --file="$TARGET/archivos.tar.gz" > /dev/null
du -sh "$TARGET" | sed "s/^/[$(date '+%F %T')] Tamaño: /"

SIZE="$(du -sb "$TARGET" | cut -f1)"

if [ -n "$REMOTE" ]; then
    echo "[$(date '+%F %T')] Enviando la copia a $REMOTE"
    rclone copy "$TARGET" "$REMOTE/$STAMP"
fi

# Una vez por semana: un solo archivo con la base y los archivos, para descargarlo desde la consola.
# No lleva la copia del .env: la clave de la aplicación se guarda aparte.
if [ "$(date +%u)" = "$WEEKLY_DAY" ]; then
    WEEKLY_FILE="$WEEKLY_DIR/erp-semana-$(date +%F).tar.gz"
    echo "[$(date '+%F %T')] Respaldo semanal para descargar: $WEEKLY_FILE"
    mkdir -p "$WEEKLY_DIR"
    tar --create --gzip --file="$WEEKLY_FILE" --directory="$TARGET" base.dump archivos.tar.gz
    chown -R www-data:www-data "$WEEKLY_DIR"
    chmod 700 "$WEEKLY_DIR"
    chmod 600 "$WEEKLY_FILE"
    # Solo los últimos WEEKLY_KEEP
    ls -1t "$WEEKLY_DIR"/erp-semana-*.tar.gz 2>/dev/null | tail -n +"$((WEEKLY_KEEP + 1))" | xargs -r rm -f
    log_entry WEEKLY true "$(stat -c %s "$WEEKLY_FILE")" "$(basename "$WEEKLY_FILE")" ""
fi

# Limpieza de las copias antiguas
find "$BACKUP_DIR" -mindepth 1 -maxdepth 1 -type d -mtime +"$RETENTION_DAYS" -exec rm -rf {} +

log_entry DAILY true "$SIZE" "" ""
echo "[$(date '+%F %T')] Copia $STAMP terminada"
