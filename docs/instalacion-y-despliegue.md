# Instalación y despliegue

Guía de respaldo para instalar el sistema en un servidor. Se actualiza cada vez que un avance agrega dependencias, variables de entorno, migraciones, comandos o servicios.

> **Estado:** todavía no se ha hecho un despliegue real. Los pasos de instalación local están probados; los de servidor (sección 5 en adelante) describen lo que el sistema necesita, pero no se han ejecutado en un servidor. Los puntos sin resolver están en la sección 9.

Última actualización: 2026-10-10 (Plantillas de certificado por curso: una migración (`php artisan migrate --force`) que además deja fijo el diseño de los certificados ya emitidos; **desde esta versión un cambio de diseño ya no alcanza a los certificados emitidos**. Antes: Editor visual de certificados: una migración (`php artisan migrate --force`); sin variables, permisos ni dependencias; usa las tipografías que ya trae dompdf. Antes: Páginas, paso de revisión: una migración (`php artisan migrate --force`); sin variables ni permisos. Antes: Exportar a Excel: cinco rutas nuevas, sin migraciones, variables ni permisos; los archivos se arman en la carpeta temporal del sistema y se borran al enviarse. Antes: Primer pago de prueba con Mercado Pago: dos correcciones, sin migraciones, variables ni permisos. Para probar hacen falta dos cuentas de prueba de Mercado Pago, una vendedora y una compradora. Antes: Documentación técnica: comando nuevo `php artisan docs:technical`, que genera `docs/tecnica/` desde las rutas, el código y la base; es de desarrollo y no se ejecuta en el servidor. Sin migraciones, variables ni dependencias del sistema. El manual de uso se genera aparte, en `docs/manual` con Node. Antes: Reembolsos: una migración; sin variables ni permisos nuevos (usa `payments.refund`, que ya estaba en el catálogo). Antes: PayPal: una migración; el servidor debe poder salir por HTTPS a `api-m.paypal.com` (y a `api-m.sandbox.paypal.com` para cuentas de prueba); los avisos de PayPal se registran a mano en la aplicación de PayPal de quien cobra, con los eventos `CHECKOUT.ORDER.APPROVED` y `PAYMENT.CAPTURE.COMPLETED`, hacia `https://{academia}.{dominio}/api/v1/payments/webhooks/paypal` o, para los planes, `https://{dominio central}/api/v1/platform/payments/webhooks/paypal`; el token temporal de PayPal se guarda en la caché de la aplicación. Antes: Pagos de plan: una migración; tarea programada nueva `plan-orders:expire`, que corre con el programador de Laravel como las demás; variable opcional `BILLING_ORDER_EXPIRATION_DAYS`; Mercado Pago avisa los pagos de plan a `https://{dominio central}/api/v1/platform/payments/webhooks/mercadopago`; la cuenta de cobro de la plataforma se conecta en la consola, sección Planes, y también se guarda cifrada con `APP_KEY`. Antes: Pagos en línea, entrega 1: una migración; variables opcionales `PAYMENTS_TIMEOUT`, `PAYMENTS_CA_BUNDLE` y `MERCADOPAGO_BASE_URL`; el servidor debe poder salir por HTTPS a `api.mercadopago.com` y recibir avisos en `https://{academia}.{dominio}/api/v1/payments/webhooks/mercadopago`; **no cambiar `APP_KEY` después de que las academias conecten sus cuentas**, porque sus claves se guardan cifradas con ella. Antes: Planes, entrega 3: sin migraciones; una variable opcional, `BILLING_GRACE_DAYS`. **Desde esta versión el plan limita:** al desplegar, una academia con la prueba o el plan vencido hace más de los días de gracia queda en solo lectura, y una con el cupo lleno deja de matricular; antes de actualizar conviene revisar en la consola el plan de cada academia. Las academias "Sin plan" no cambian. Antes: Planes de la plataforma: dos migraciones, la primera de las cuales crea la suscripción de las academias existentes, y dos variables opcionales, `BILLING_TRIAL_DAYS` y `BILLING_TRIAL_MAX_STUDENTS`. Antes: Evaluaciones y aula y nota mínima del curso: cuatro migraciones nuevas; Alumnos y docentes: dos permisos nuevos. Sin dependencias ni variables de entorno. Al actualizar: `php artisan migrate --force` y `php artisan access:sync`).

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

**Extensiones de PHP:** `pdo_pgsql`, `pgsql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `fileinfo`, `bcmath`, `curl`, `zip`, `xmlreader`, `xmlwriter` (estas tres, para leer y escribir Excel en las importaciones), `gd` (dibuja el QR e incrusta el logo y la firma en el PDF de los certificados; sin ella no se generan).

## 3. Dependencias

### Backend (`backend/composer.json`)

| Paquete | Versión instalada | Para qué |
| --- | --- | --- |
| `laravel/framework` | 12.69.2 | Framework |
| `laravel/sanctum` | 4.3.3 | Sesiones por token |
| `dompdf/dompdf` | 3.1.6 | PDF de los certificados |
| `bacon/bacon-qr-code` | 3.1.1 | Código QR de los certificados |
| `openspout/openspout` | ^5.12 | Leer y escribir Excel y CSV en las importaciones (plantillas de personas, preguntas y prospectos) |

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
| `@tiptap/react`, `@tiptap/starter-kit`, `@tiptap/extension-image`, `@tiptap/pm` | ^3.31.4 | Editor de las páginas de la academia (S10) |
| `vite`, `typescript` (desarrollo) | ^8.3.0, ~6.0.2 | Compilación |
| `@playwright/test` (desarrollo) | ^1.63.0 | Recorridos automáticos de punta a punta (S12). No se instala en el servidor |

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

    # Cabeceras de seguridad de la aplicación web. Las de la API las pone Laravel.
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "DENY" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    # img-src: logos y portadas (misma web) y miniaturas de YouTube; frame-src: los videos de los cursos
    add_header Content-Security-Policy "default-src 'self'; img-src 'self' data: blob: https://i.ytimg.com; style-src 'self' 'unsafe-inline'; frame-src https://www.youtube.com https://www.youtube-nocookie.com; frame-ancestors 'none'; base-uri 'self'" always;

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
| `BILLING_TRIAL_DAYS` | `14` | Días de la prueba gratuita de una academia nueva (opcional) |
| `BILLING_TRIAL_MAX_STUDENTS` | `10` | Tope de alumnos con matrícula activa durante la prueba (opcional) |
| `PAYMENTS_TIMEOUT` | `15` | Segundos que se espera a una pasarela de pago (opcional) |
| `PAYMENTS_CA_BUNDLE` | vacío | Archivo de certificados raíz para el HTTPS de las pasarelas. Solo donde PHP no tiene uno configurado (en Windows da «cURL error 60»); en Linux se deja vacío |
| `BILLING_ORDER_EXPIRATION_DAYS` | `7` | Días que una orden de plan espera su pago antes de vencer sola (opcional) |
| `BILLING_GRACE_DAYS` | `7` | Días que una academia sigue operando tras vencer su prueba o su plan; después queda en solo lectura (opcional) |
| `AUTH_SESSION_LIFETIME` | `30` | Minutos de inactividad por defecto; cada academia puede cambiarlo en Configuración |
| `AUTH_PASSWORD_RESET_MINUTES` | `60` | Vigencia del enlace de recuperación |
| `AUTH_LOGIN_LOCK_ATTEMPTS` | `10` | Intentos fallidos seguidos que bloquean una cuenta (S12) |
| `AUTH_LOGIN_LOCK_MINUTES` | `15` | Minutos que dura ese bloqueo |
| `API_RATE_LIMIT` | `300` | Peticiones por minuto que admite la API de un mismo usuario o, sin sesión, de una misma dirección |
| `TRUSTED_PROXIES` | dirección del balanceador, o `*` | **Obligatoria si el HTTPS termina en un proxy o balanceador.** Sin ella la aplicación cree que todo llega por HTTP y desde la dirección del proxy: los enlaces de recuperación salen con `http://`, no se envía la cabecera que obliga a HTTPS y los límites por dirección bloquean a todos a la vez. Usar `*` solo si el servidor no es alcanzable más que a través del proxy |
| `CORS_ALLOWED_ORIGINS` | vacío | Orígenes adicionales (separados por coma) que pueden llamar a la API desde un navegador. Por defecto solo la propia plataforma: `TENANCY_CENTRAL_DOMAIN` y sus subdominios |

| `FILES_DISK` | `local` | Dónde se guardan los archivos de las academias. `local` = `storage/app/private`. Para un servicio compatible con S3: `s3` más las variables `AWS_*` y el paquete `league/flysystem-aws-s3-v3` |
| `FILES_IMAGE_MAX_KB` | `2048` | Tamaño máximo de imágenes (logo, portadas) |
| `FILES_DOCUMENT_MAX_KB` | `51200` | Tamaño máximo de documentos y materiales |

`DEMO_ADMIN_PASSWORD` solo se usa en el entorno local.

### Subida de archivos

El servidor debe aceptar archivos al menos tan grandes como los límites anteriores; si no, la subida falla antes de llegar a la aplicación:

- PHP (`php.ini`): `upload_max_filesize = 64M` y `post_max_size = 64M`. El valor por defecto de PHP es 2M.
- Nginx: `client_max_body_size 64m;` (por defecto 1m).

### Ajustes de PHP en producción

- `expose_php = Off`: no anunciar la versión de PHP.
- OPcache activado (`opcache.enable=1`): sin él, cada petición vuelve a leer y compilar el código.

La carpeta `backend/storage/app/private` contiene los archivos de todas las academias. Debe conservarse entre despliegues (no reemplazarla al publicar una versión nueva) e incluirse en los respaldos junto con la base de datos.

## 8. Pasos de despliegue

Backend, desde `backend/`:

1. `composer install --no-dev --optimize-autoloader`
2. Crear `.env` con los valores de la sección 7.
3. `php artisan migrate --force`
4. `php artisan access:sync` — carga el catálogo de permisos, actualiza los roles base de todas las academias y crea en las que ya existían los roles base nuevos (desde S8, "Responsable de empresa"). Ejecutarlo en cada despliegue, después de las migraciones.
5. Solo la primera vez: `php artisan platform:create-admin correo@dominio` — crea el administrador de plataforma (pide la contraseña por consola, mínimo 12 caracteres).
6. `php artisan config:cache`, `php artisan route:cache` y `php artisan event:cache` (probados con este código en S12). Tras cambiar el `.env` hay que repetir `config:cache`.
7. Dar permiso de escritura al usuario del servidor web sobre `storage/` y `bootstrap/cache/`.

Frontend, desde `frontend/`:

1. `npm ci`
2. `npm run build` — genera `frontend/dist`, que es lo que publica el servidor web.

Tarea programada (cron), necesaria para limpiar las sesiones vencidas (diario), marcar como vencidas las órdenes sin pago (`orders:expire`, cada hora), cerrar los tickets resueltos hace más de una semana (`tickets:close-resolved`, diario) y borrar las claves de idempotencia vencidas (`idempotency:prune`, diario). Es una sola línea de cron; todas las tareas salen de ella:

```
* * * * * cd /var/www/erp/backend && php artisan schedule:run >> /dev/null 2>&1
```

S8 (Empresas y CRM) no agrega dependencias, variables de entorno, servicios ni tareas programadas: solo migraciones (módulos `Crm` y `B2b`; modifican además la tabla `orders`) y permisos nuevos, que se cargan con los pasos 3 y 4.

S10 (Operación) agrega las migraciones de los módulos `Support`, `Claims` y `Cms`, permisos nuevos (pasos 3 y 4), la tarea diaria de tickets (mismo cron) y el editor TipTap en el frontend (se instala con `npm ci`). No agrega variables de entorno ni dependencias del backend. Las rutas públicas nuevas del sitio (`/reclamaciones`, `/reclamaciones/seguimiento` y `/p/...`) las sirve la misma aplicación web: el servidor debe seguir devolviendo `index.html` para cualquier ruta que no sea `/api`. El formulario de reclamaciones limita los envíos por dirección IP: detrás de un proxy, sin los proxies de confianza configurados (sección 9), todos los visitantes compartirían la IP del proxy y el límite los bloquearía a todos.

S11 (Promociones y paquetes) agrega solo migraciones (módulo `Promotions` y tablas de paquetes en `Sales`; suman columnas a `orders`) y permisos nuevos, que se cargan con los pasos 3 y 4. No agrega dependencias, variables de entorno ni tareas programadas. Las rutas nuevas del sitio (`/catalog/packages/...` y `/checkout/package/...`) las sirve la misma aplicación web.

S12 (Endurecimiento) agrega cinco migraciones (tablas `idempotency_keys` y `holidays`, columnas `users.terms_accepted_at` y `quiz_attempts.draft_answers`, e índices), la tarea diaria `idempotency:prune` (mismo cron) y las variables `AUTH_LOGIN_LOCK_ATTEMPTS`, `AUTH_LOGIN_LOCK_MINUTES`, `API_RATE_LIMIT`, `TRUSTED_PROXIES` y `CORS_ALLOWED_ORIGINS` (todas con valor por defecto salvo `TRUSTED_PROXIES`, que hay que definir si hay proxy). No cambia el catálogo de permisos ni las dependencias del servidor: `league/commonmark` subió de 2.10.1 a 2.10.3 por un aviso de seguridad y Playwright es solo de desarrollo. La aplicación web ahora se publica en muchos archivos pequeños dentro de `frontend/dist/assets`: hay que publicar la carpeta completa en cada despliegue. Los contadores de intentos de acceso, de bloqueo de cuentas y de peticiones viven en la caché (`CACHE_STORE`): con varios servidores, la caché debe ser compartida (`database` o Redis), no `file`.

Revisión de jefatura (entregas 1 y 2) agrega una dependencia del backend, `openspout/openspout` (se instala con `composer install`; usa las extensiones `zip`, `xmlreader` y `xmlwriter`, que ya estaban entre los requisitos), y una migración (tablas `groups` y `group_members`, columna `users.must_change_password`). No agrega permisos, variables de entorno ni tareas programadas. Las importaciones escriben archivos temporales: la carpeta temporal del sistema (`sys_get_temp_dir()`) debe permitir escritura al usuario de PHP. Los archivos que se importan pesan hasta 2 MB, dentro del límite de subida ya indicado.

La entrega 3 de la revisión de jefatura agrega una migración (columnas en `certificate_templates`) y nada más para el servidor. El fondo propio del certificado se guarda con los demás archivos privados de la academia (`storage/app/private`), así que entra en el mismo respaldo. El acceso de soporte abre la academia en su subdominio con la dirección `/support-access`: la sirve la misma aplicación web.

Comprobación tras desplegar:

- `https://dominio/up` responde 200.
- `https://dominio/api/v1/tenant` responde `{"data":null,...}` y trae las cabeceras `X-Request-ID`, `X-Content-Type-Options` y `Strict-Transport-Security` (esta última solo si `TRUSTED_PROXIES` está bien puesta o el HTTPS llega directo a PHP).
- `https://dominio/register` permite crear una academia y redirige a su subdominio.

**No ejecutar en producción:** `php artisan db:seed` con `APP_ENV=local` (crea cuentas de demostración), ni `php artisan migrate:fresh`.

## 9. Pendientes antes de producción

| Tema | Situación |
| --- | --- |
| Proxy y HTTPS | Resuelto en S12 con la variable `TRUSTED_PROXIES` (sección 7). Falta darle el valor real cuando se conozca el servidor y comprobarlo ahí. |
| CORS | Resuelto en S12: solo la propia plataforma (dominio central y subdominios). |
| Cabeceras de la aplicación web | La API ya envía las suyas. Las del HTML, JS y CSS las pone el servidor web: el bloque de Nginx de la sección 5 es orientativo y **no está probado**; la política de contenido (CSP) hay que validarla en staging, sobre todo con los videos de YouTube y el editor de páginas. |
| Prueba de penetración | El Maestro (§8.2) la pide antes de producción; alcance y frecuencia sin definir. No se hizo. |
| Monitoreo y alertas | `/up` responde y cada petición lleva su identificador en los registros, pero no hay tablero ni alertas (Maestro §18). Depende del servidor. |
| Correo | Sin proveedor. La recuperación de contraseña no llega al usuario hasta definirlo. |
| Archivos | Se guardan en el disco del servidor. Falta decidir si se pasa a un servicio externo, y respaldar la carpeta de archivos. |
| Colas | Aún no hay trabajos en cola ni proceso `queue:work`. |
| Respaldos | Sin política de respaldo de la base de datos (Maestro PD-014). |
| `config:cache` y `route:cache` | Probados en S12 en el equipo de desarrollo. Falta repetirlo en el servidor. |
| Archivos sensibles en el repositorio | `backend_env_backup` (raíz) está versionado y contiene una `APP_KEY`; hay que retirarlo y no usar esa clave. |
| CI | `.github/workflows/ci.yml` se corrigió en S12 (rama `staging`, auditoría de dependencias, estilo solo en las carpetas vigentes) pero **sigue sin haberse ejecutado**: correrá al subir el commit. |

## 10. Herramientas solo de desarrollo

- **Laravel Boost:** `composer require laravel/boost --dev` y `php artisan boost:install`. Registra un servidor MCP (`.mcp.json` en la raíz y en `backend/`) y guías en `backend/CLAUDE.md`. No se despliega. Estos archivos (`.claude/`, `.mcp.json`, `CLAUDE.md`, `AGENTS.md`, `boost.json`) están en `.gitignore`: cada desarrollador los genera en su equipo con `php artisan boost:install`.
- **Docker Compose:** PostgreSQL y Adminer (puerto 8080) para desarrollo.
- **Playwright (recorridos de punta a punta):** `npm ci` y, una vez por equipo, `npx playwright install chromium`. Con la web y la API levantadas: `E2E_BASE_URL=http://demo.localhost:5173 E2E_PASSWORD=<clave del administrador> npm run e2e`. Crean datos con el prefijo "E2E": no correrlos contra producción.
- **Datos de demostración:** `backend/database/seeders/DemoTenantSeeder.php`.
