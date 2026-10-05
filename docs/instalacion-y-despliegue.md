# Instalación y despliegue

Guía de respaldo para instalar el sistema en un servidor. Se actualiza cada vez que un avance agrega dependencias, variables de entorno, migraciones, comandos o servicios.

> **Estado:** todavía no se ha hecho un despliegue real. Los pasos de instalación local están probados; los de servidor (sección 5 en adelante) describen lo que el sistema necesita, pero no se han ejecutado en un servidor. Los puntos sin resolver están en la sección 9.

Última actualización: 2026-10-05 (S3, entrega 2).

## 1. Componentes

| Componente | Carpeta | Tecnología |
| --- | --- | --- |
| API | `backend/` | Laravel 12 (PHP) |
| Aplicación web | `frontend/` | React 19 + Vite 8 + Tailwind 4 (se compila a archivos estáticos) |
| Base de datos | — | PostgreSQL 15 |

No hay servicios adicionales obligatorios por ahora (ni Redis, ni almacenamiento externo, ni proveedor de correo). Los archivos de las academias se guardan en el disco del servidor.

## 2. Requisitos del servidor

| Requisito | Versión usada en desarrollo | Mínimo |
| --- | --- | --- |
| PHP | 8.4.11 | 8.2 |
| Composer | 2.8.11 | 2.x |
| Node.js / npm (solo para compilar el frontend) | 22.18.0 / 10.9.3 | Node 20 |
| PostgreSQL | 15 | 15 |

**Extensiones de PHP:** `pdo_pgsql`, `pgsql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `fileinfo`, `bcmath`, `curl`, `zip`.

## 3. Dependencias

### Backend (`backend/composer.json`)

| Paquete | Versión instalada | Para qué |
| --- | --- | --- |
| `laravel/framework` | 12.69.2 | Framework |
| `laravel/sanctum` | 4.3.3 | Sesiones por token |

Solo de desarrollo (no se instalan en el servidor con `--no-dev`): `laravel/boost` 2.10.1, `laravel/pint` 1.32.1, `laravel/pail` 1.2.7, `phpunit/phpunit` 11.5.56, `mockery/mockery` 1.6.15, `nunomaduro/collision` 8.9.5, `fakerphp/faker` 1.24.1.

### Frontend (`frontend/package.json`)

| Paquete | Versión | Para qué |
| --- | --- | --- |
| `react`, `react-dom` | ^19.2.8 | Interfaz |
| `react-router-dom` | ^7.18.3 | Rutas |
| `axios` | ^1.20.0 | Llamadas a la API |
| `tailwindcss`, `@tailwindcss/vite` | ^4.3.3 | Estilos |
| `lucide-react` | ^1.45.0 | Íconos |
| `@fontsource-variable/inter` | ^5.3.0 | Tipografía Inter, servida desde el propio sitio |
| `vite`, `typescript` (desarrollo) | ^8.3.0, ~6.0.2 | Compilación |

Las versiones exactas quedan fijadas en `backend/composer.lock` y `frontend/package-lock.json`; el servidor debe instalar desde esos archivos.

## 4. Instalación local (probada)

1. Base de datos: `docker compose up -d` en la raíz. Crea PostgreSQL, el rol `erp_app` y la base de pruebas `erp_saas_test` (script `docker/init-db/00_init.sql`).
2. Backend, desde `backend/`:
   - `composer install`
   - copiar `.env.example` a `.env` y ejecutar `php artisan key:generate`
   - `php artisan migrate --seed` (crea la academia demo y las cuentas de prueba)
   - `php artisan serve` (puerto 8000)
3. Frontend, desde `frontend/`: `npm install` y `npm run dev` (puerto 5173).
4. Abrir `http://demo.localhost:5173` (academia demo), `http://localhost:5173/register` (alta de academias) o `http://localhost:5173/platform` (consola de plataforma).

Pruebas: `php artisan test` en `backend/` (usa PostgreSQL); `npm run lint` y `npm run build` en `frontend/`.

## 5. Dominio y servidor web

El sistema identifica a cada academia por su subdominio, así que el servidor necesita:

- **DNS comodín:** `dominio` y `*.dominio` apuntando al servidor.
- **Certificado TLS comodín** para `*.dominio`.
- **Un mismo origen para la web y la API:** cada subdominio sirve los archivos de `frontend/dist` y envía `/api/*` a Laravel (`backend/public`). El frontend llama siempre a `/api/v1` en su propio subdominio.
- **Conservar el encabezado `Host`** al pasar la petición a Laravel: de ahí sale la academia.
- **Rutas de la aplicación web:** cualquier ruta que no sea un archivo ni `/api/*` debe devolver `index.html` (la navegación la resuelve React).

Ejemplo orientativo para Nginx (sin probar):

```nginx
server {
    listen 443 ssl;
    server_name dominio *.dominio;

    root /var/www/erp/frontend/dist;
    index index.html;

    location / {
        try_files $uri /index.html;
    }

    location ~ ^/(api|up) {
        root /var/www/erp/backend/public;
        try_files $uri /index.php?$query_string;
    }

    location ~ \.php$ {
        root /var/www/erp/backend/public;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME /var/www/erp/backend/public/index.php;
        include fastcgi_params;
    }
}
```

## 6. Base de datos

- La aplicación debe conectarse con un rol **sin superusuario y sin `BYPASSRLS`**. Con un superusuario, la seguridad por filas que separa a las academias no se aplica.
- Preparación (como administrador de PostgreSQL), tomando `docker/init-db/00_init.sql` como referencia:

```sql
CREATE ROLE erp_app LOGIN PASSWORD '<contraseña>' NOSUPERUSER NOBYPASSRLS;
CREATE DATABASE erp_saas_db OWNER erp_app;
\connect erp_saas_db
CREATE SCHEMA erp AUTHORIZATION erp_app;
```

- Las tablas se crean en el esquema `erp` (definido en `backend/config/database.php`, `search_path`).
- El esquema lo crean las migraciones de Laravel; no hay SQL inicial que cargar.

## 7. Variables de entorno (`backend/.env`)

| Variable | Valor en producción | Notas |
| --- | --- | --- |
| `APP_ENV` | `production` | Con `local` se crean datos de demostración al sembrar |
| `APP_DEBUG` | `false` | |
| `APP_KEY` | generar con `php artisan key:generate` | No reutilizar la de desarrollo |
| `APP_URL` | `https://dominio` | |
| `TENANCY_CENTRAL_DOMAIN` | `dominio` | Dominio central; cada academia vive en `{subdominio}.dominio` |
| `DB_CONNECTION` | `pgsql` | |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE` | según el servidor | |
| `DB_USERNAME`, `DB_PASSWORD` | rol `erp_app` | Ver sección 6 |
| `SESSION_DRIVER` | `file` | La API usa tokens; no requiere tabla de sesiones |
| `CACHE_STORE` | `database` | También guarda los contadores de intentos de inicio de sesión |
| `QUEUE_CONNECTION` | `database` | Todavía no hay trabajos en cola |
| `MAIL_MAILER` y `MAIL_*` | pendiente | Sin proveedor definido; con `log` los correos se escriben en `storage/logs/laravel.log` |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | remitente real | |
| `AUTH_SESSION_LIFETIME` | `30` | Minutos de inactividad por defecto; cada academia puede cambiarlo en Configuración |
| `AUTH_PASSWORD_RESET_MINUTES` | `60` | Vigencia del enlace de recuperación |

| `FILES_DISK` | `local` | Dónde se guardan los archivos de las academias. `local` = `storage/app/private`. Para un servicio compatible con S3: `s3` más las variables `AWS_*` y el paquete `league/flysystem-aws-s3-v3` |
| `FILES_IMAGE_MAX_KB` | `2048` | Tamaño máximo de imágenes (logo, portadas) |
| `FILES_DOCUMENT_MAX_KB` | `51200` | Tamaño máximo de documentos y materiales |

`DEMO_ADMIN_PASSWORD` solo se usa en el entorno local.

### Subida de archivos

El servidor debe aceptar archivos al menos tan grandes como los límites anteriores; si no, la subida falla antes de llegar a la aplicación:

- PHP (`php.ini`): `upload_max_filesize = 64M` y `post_max_size = 64M`. El valor por defecto de PHP es 2M.
- Nginx: `client_max_body_size 64m;` (por defecto 1m).

La carpeta `backend/storage/app/private` contiene los archivos de todas las academias. Debe conservarse entre despliegues (no reemplazarla al publicar una versión nueva) e incluirse en los respaldos junto con la base de datos.

## 8. Pasos de despliegue

Backend, desde `backend/`:

1. `composer install --no-dev --optimize-autoloader`
2. Crear `.env` con los valores de la sección 7.
3. `php artisan migrate --force`
4. `php artisan access:sync` — carga el catálogo de permisos y actualiza los roles base de todas las academias. Ejecutarlo en cada despliegue.
5. Solo la primera vez: `php artisan platform:create-admin correo@dominio` — crea el administrador de plataforma (pide la contraseña por consola, mínimo 12 caracteres).
6. `php artisan config:cache` y `php artisan route:cache`
7. Dar permiso de escritura al usuario del servidor web sobre `storage/` y `bootstrap/cache/`.

Frontend, desde `frontend/`:

1. `npm ci`
2. `npm run build` — genera `frontend/dist`, que es lo que publica el servidor web.

Tarea programada (cron), necesaria para limpiar las sesiones vencidas:

```
* * * * * cd /var/www/erp/backend && php artisan schedule:run >> /dev/null 2>&1
```

Comprobación tras desplegar:

- `https://dominio/up` responde 200.
- `https://dominio/api/v1/tenant` responde `{"data":null,...}`.
- `https://dominio/register` permite crear una academia y redirige a su subdominio.

**No ejecutar en producción:** `php artisan db:seed` con `APP_ENV=local` (crea cuentas de demostración), ni `php artisan migrate:fresh`.

## 9. Pendientes antes de producción

| Tema | Situación |
| --- | --- |
| Proxy y HTTPS | Si TLS termina en un proxy, hay que configurar los proxies de confianza de Laravel; de lo contrario los enlaces de recuperación de contraseña saldrán con `http://`. Sin configurar. |
| CORS | `backend/config/cors.php` acepta cualquier origen. Como la web y la API comparten origen, se puede restringir. Sin ajustar. |
| Correo | Sin proveedor. La recuperación de contraseña no llega al usuario hasta definirlo. |
| Archivos | Se guardan en el disco del servidor. Falta decidir si se pasa a un servicio externo, y respaldar la carpeta de archivos. |
| Colas | Aún no hay trabajos en cola ni proceso `queue:work`. |
| Respaldos | Sin política de respaldo de la base de datos (Maestro PD-014). |
| `config:cache` y `route:cache` | No se han probado con este código. |
| Archivos sensibles en el repositorio | `backend_env_backup` (raíz) está versionado y contiene una `APP_KEY`; hay que retirarlo y no usar esa clave. |
| CI | `.github/workflows/ci.yml` existe pero no se ha ejecutado. |

## 10. Herramientas solo de desarrollo

- **Laravel Boost:** `composer require laravel/boost --dev` y `php artisan boost:install`. Registra un servidor MCP (`.mcp.json` en la raíz y en `backend/`) y guías en `backend/CLAUDE.md`. No se despliega. Estos archivos (`.claude/`, `.mcp.json`, `CLAUDE.md`, `AGENTS.md`, `boost.json`) están en `.gitignore`: cada desarrollador los genera en su equipo con `php artisan boost:install`.
- **Docker Compose:** PostgreSQL y Adminer (puerto 8080) para desarrollo.
- **Datos de demostración:** `backend/database/seeders/DemoTenantSeeder.php`.
