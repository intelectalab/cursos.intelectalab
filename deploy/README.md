# Despliegue en un VPS

Archivos listos para montar la plataforma en un servidor Linux (Ubuntu 24.04, Nginx, PHP 8.4, PostgreSQL 15). En cada uno hay que reemplazar `DOMINIO` por el dominio real.

| Archivo | Para qué | Dónde va en el servidor |
| --- | --- | --- |
| `env.production.example` | Variables del backend, con el correo por Elastic Email | `backend/.env` |
| `nginx/erp.conf` | Servidor web: dominio central y todas las academias (`*.DOMINIO`) | `/etc/nginx/sites-available/erp.conf` |
| `supervisor/erp-queue.conf` | Proceso que envía los correos en segundo plano | `/etc/supervisor/conf.d/erp-queue.conf` |
| `cron/erp` | Tareas programadas y copia de seguridad diaria | `/etc/cron.d/erp` |
| `scripts/deploy.sh` | Publica una versión nueva | Se ejecuta donde está |
| `scripts/backup.sh` | Copia la base y los archivos; conserva 30 días | Se ejecuta donde está |
| `scripts/restore.sh` | Restaura una copia | Se ejecuta donde está |

Los scripts dan por hecho que el código está en `/var/www/erp`. Si va en otra carpeta, se indica con la variable `APP_DIR`.

**Estado:** escritos y revisados, **sin probar en un servidor** (todavía no hay VPS). En local se comprobó la sintaxis de los scripts y se ensayó una copia y restauración completa de la base. La primera instalación hay que hacerla con cuidado y corregir lo que haga falta.
