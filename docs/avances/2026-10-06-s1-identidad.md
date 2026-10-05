# S1 — Identidad

Fecha: 2026-10-06 · Sin commit al momento de escribir este documento

## Qué se construyó

- **Usuarios:** listado con búsqueda, filtro y paginación; alta y edición; asignación de roles; documento de identidad opcional (DNI de 8 dígitos, carné de extranjería o pasaporte, único dentro de la academia). Al desactivar una cuenta se cierran sus sesiones.
- **Roles y permisos:** cada academia crea roles propios eligiendo permisos de un catálogo agrupado en 14 módulos. Los seis roles base no se editan ni eliminan.
- **Protecciones:** nadie otorga un permiso que no tiene; la academia no puede quedarse sin administrador activo; un rol con usuarios no se elimina.
- **Contraseñas:** recuperación por enlace de un solo uso (60 minutos) y cambio de contraseña por el propio usuario.
- **Sesiones:** se cierran tras 30 minutos de inactividad. El administrador de cada academia cambia ese valor en Configuración › Seguridad (entre 5 y 1440). Cada usuario ve sus sesiones activas y puede cerrarlas.
- **Configuración de la academia:** nombre, razón social, identificación fiscal, monedas (PEN/USD), zona horaria, contacto y textos legales.
- **Auditoría:** pantalla de consulta con filtros y detalle de antes/después.
- **Administrador de plataforma:** identidad separada, solo en el dominio central; lista academias y las suspende o reactiva.
- **Alumno:** se le agregaron los permisos para rendir actividades, comprar, pagar y abrir tickets.

## Cómo se usa

| Pantalla | Dirección | Permiso |
| --- | --- | --- |
| Usuarios | `/users` | `users.read` |
| Roles y permisos | `/roles` | `roles.read` |
| Configuración | `/settings` | `tenant_settings.read` |
| Auditoría | `/audit` | `audit.read` |
| Mi cuenta | `/account` (clic en tu nombre) | sesión |
| Recuperar contraseña | `/forgot-password` | público |
| Consola de plataforma | `http://localhost:5173/platform` | administrador de plataforma |

## Endpoints nuevos

| Método y ruta | Permiso |
| --- | --- |
| `POST /users`, `PUT /users/{id}`, `PUT /users/{id}/roles` | `users.create`, `users.update` |
| `GET /permissions`, `GET /roles` | `roles.read` |
| `POST /roles`, `PUT /roles/{id}`, `DELETE /roles/{id}` | `roles.create`, `roles.update`, `roles.delete` |
| `POST /auth/forgot-password`, `POST /auth/reset-password` | público |
| `PUT /auth/password`, `GET /auth/sessions`, `DELETE /auth/sessions`, `DELETE /auth/sessions/{id}` | sesión |
| `GET /settings`, `PUT /settings` | `tenant_settings.read`, `tenant_settings.update` |
| `GET /audit-events` | `audit.read` |
| `POST /platform/auth/login`, `GET /platform/tenants`, `PATCH /platform/tenants/{id}/status` | dominio central |

Todas bajo `/api/v1`.

## Comandos nuevos

| Comando | Para qué |
| --- | --- |
| `php artisan access:sync` | Carga el catálogo de permisos y actualiza los roles base de todas las academias |
| `php artisan access:docs` | Regenera `docs/roles-y-permisos.md` |
| `php artisan platform:create-admin correo` | Crea un administrador de plataforma |

## Verificación

- 57 pruebas de backend pasan.
- Probado en el navegador: crear rol y usuario, recuperar contraseña con el enlace del correo, editar configuración, ver auditoría, cerrar sesiones, suspender y reactivar una academia.

## Cambios que afectan a la instalación

- Migraciones nuevas: `platform_admins`, `password_reset_tokens`, columnas `ip_address` y `user_agent` en `personal_access_tokens`, columnas `doc_type` y `doc_number` en `users`.
- Variables nuevas: `AUTH_SESSION_LIFETIME` (30), `AUTH_PASSWORD_RESET_MINUTES` (60).
- Tarea programada diaria para limpiar sesiones vencidas (requiere el cron de `schedule:run`).
- `access:sync` debe ejecutarse en cada despliegue.

## Decisiones tomadas

- Sesión: 30 minutos de inactividad, configurable por el administrador de cada academia.
- Documento de identidad en el usuario: sí.
- Proveedor de correo: sin definir; los correos se escriben en el log.
- B2B: habrá dos roles (personal de la academia y "Responsable de empresa"); se construye en S8.

## Pendiente al cerrar

- Envío real de correos e invitación por correo al crear usuarios (hoy el administrador pone la contraseña inicial).
- Logo de la academia (necesita almacenamiento de archivos).
- Cierre de sesiones de otro usuario por parte del administrador (hoy solo ocurre al desactivarlo).
