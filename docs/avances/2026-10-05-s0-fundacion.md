# S0 — Fundación

Fecha: 2026-10-05 · Commit: `8c0c47e` ("S0 Completo")

## Qué se construyó

- **Estructura por módulos** en el backend: `backend/app/Modules/{Tenancy, Identity, Audit}` y lo compartido en `backend/app/Shared`. Cada módulo trae sus rutas (`routes.php`) y sus migraciones (`Database/Migrations`).
- **Multi-tenancy por subdominio:** cada academia vive en `{subdominio}.dominio`. El backend resuelve la academia por el host antes de autenticar. En el dominio central solo existe el alta de academias.
- **Aislamiento en tres capas:** filtro automático por academia en los modelos, token válido solo en su subdominio y seguridad por filas (RLS) en PostgreSQL.
- **Esquema con los nombres del Maestro:** `tenants`, `tenant_settings`, `users`, `roles`, `permissions`, `role_permissions`, `user_roles`, `audit_events`.
- **Permisos** con la convención `recurso.acción` y comodines; seis roles base por academia.
- **Contrato de API:** éxito `data` + `meta`; error `code` + `message` + `details` + `trace_id`; `X-Request-ID` en toda respuesta.
- **Auditoría** de solo inserción (la base rechaza modificar o borrar).
- **Frontend** reorganizado en `src/app`, `src/features`, `src/layouts`, `src/shared`; sesión revalidada al cargar.

## Cómo se usa

- Registrar una academia: `http://localhost:5173/register`. Al terminar redirige al login de su subdominio.
- Academia de demostración: `http://demo.localhost:5173`.

## Endpoints

| Método y ruta | Dónde | Permiso |
| --- | --- | --- |
| `GET /api/v1/tenant` | cualquier host | público |
| `POST /api/v1/tenants` | dominio central | público (con límite de intentos) |
| `POST /api/v1/auth/login` | subdominio | público |
| `GET /api/v1/auth/me`, `POST /api/v1/auth/logout` | subdominio | sesión |
| `GET /api/v1/users` | subdominio | `users.read` |

## Verificación

- 26 pruebas de backend, incluidas TC-TEN-001 (un usuario de una academia no accede a otra, tampoco por SQL directo) y TC-RBAC-001 (un alumno recibe 403 en un endpoint administrativo).
- Probado en el navegador: login, registro de academia y subdominio inexistente.

## Cambios que afectan a la instalación

- Rol de base de datos `erp_app` sin superusuario (necesario para RLS) y base de pruebas `erp_saas_test`.
- Variables nuevas: `TENANCY_CENTRAL_DOMAIN`; `SESSION_DRIVER=file`.
- El esquema anterior de 58 tablas quedó en la base local renombrado como `erp_legacy`.

## Pendiente al cerrar

- `backend/app/Http/Controllers/Api/AuthController.php` (antiguo, sin uso) sigue en el repositorio.
- `backend_env_backup` versionado con una `APP_KEY`.
- CI sin ejecutar; falta OpenAPI, almacenamiento de archivos y colas con contexto de academia.
