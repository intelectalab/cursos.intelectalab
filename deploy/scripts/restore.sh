#!/usr/bin/env bash
# Restaura una copia hecha con backup.sh. REEMPLAZA la base de datos y los archivos actuales.
#
# Uso: restore.sh /var/backups/erp/2026-10-11_0230
# Para ensayar sin tocar producción: DB_DATABASE=erp_ensayo FILES_DIR=/tmp/ensayo restore.sh <copia>
# Se ejecuta como root: la base se restaura con el usuario postgres, porque PostgreSQL no deja
# cargar datos con COPY en tablas con aislamiento por filas (RLS) a un usuario común.
set -euo pipefail

SOURCE="${1:?Indica la carpeta de la copia, por ejemplo /var/backups/erp/2026-10-11_0230}"
APP_DIR="${APP_DIR:-/var/www/erp}"
ENV_FILE="$APP_DIR/backend/.env"
read_env() { grep -E "^$1=" "$ENV_FILE" | head -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }

DB_NAME="${DB_DATABASE:-$(read_env DB_DATABASE)}"
DB_USER="$(read_env DB_USERNAME)"
FILES_DIR="${FILES_DIR:-$APP_DIR/backend/storage/app}"

[ -f "$SOURCE/base.dump" ] || { echo "No existe $SOURCE/base.dump"; exit 1; }

echo "Se va a REEMPLAZAR la base «$DB_NAME» y los archivos de «$FILES_DIR» con la copia $SOURCE."
read -r -p "Escribe RESTAURAR para continuar: " answer
[ "$answer" = "RESTAURAR" ] || { echo "Cancelado."; exit 1; }

echo "Restaurando la base de datos…"
# La copia solo la puede leer root: se le pasa a pg_restore por la entrada
sudo -u postgres pg_restore --dbname="$DB_NAME" --clean --if-exists --no-owner < "$SOURCE/base.dump"

# Lo restaurado queda a nombre de postgres: vuelve a ser del usuario de la aplicación, que debe ser
# el dueño para crear tablas en las próximas versiones (y sigue sin poder saltarse el aislamiento)
sudo -u postgres psql --quiet --dbname="$DB_NAME" -v ON_ERROR_STOP=1 -v owner="$DB_USER" <<'SQL'
SELECT format('ALTER SCHEMA erp OWNER TO %I', :'owner') \gexec
SELECT format('ALTER %s erp.%I OWNER TO %I',
              CASE c.relkind WHEN 'r' THEN 'TABLE' WHEN 'p' THEN 'TABLE' WHEN 'S' THEN 'SEQUENCE' WHEN 'v' THEN 'VIEW' WHEN 'm' THEN 'MATERIALIZED VIEW' END,
              c.relname, :'owner')
FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE n.nspname = 'erp' AND c.relkind IN ('r', 'p', 'S', 'v', 'm')
  -- Una secuencia ligada a una columna cambia de dueño junto con su tabla
  AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.objid = c.oid AND d.deptype IN ('a', 'i') AND c.relkind = 'S') \gexec
SELECT format('ALTER FUNCTION erp.%I(%s) OWNER TO %I', p.proname, pg_get_function_identity_arguments(p.oid), :'owner')
FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
WHERE n.nspname = 'erp' \gexec
SQL

echo "Restaurando los archivos…"
mkdir -p "$FILES_DIR"
tar --extract --gzip --file="$SOURCE/archivos.tar.gz" --directory="$FILES_DIR"

echo "Listo. Si la clave APP_KEY del servidor no es la de la copia ($SOURCE/env.copia), las academias tendrán que volver a conectar sus pasarelas de pago."
