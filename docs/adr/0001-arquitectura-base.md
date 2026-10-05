# ADR 0001 — Arquitectura base

- **Estado:** aceptado (2026-10-05)
- **Fuente de requisitos:** Documento Maestro Integral v2.0. El prototipo HTML es solo referencia de vistas.

## Decisiones

| Tema | Decisión |
| --- | --- |
| Estilo | Monolito modular en Laravel 12, API-first en `/api/v1`, una sola SPA (React + Vite + Tailwind). |
| Módulos | `backend/app/Modules/{Dominio}` con capas `Http` → `Application` → `Domain` → `Infrastructure`; cada módulo trae sus rutas (`routes.php`) y migraciones (`Database/Migrations`). Lo transversal vive en `backend/app/Shared`. |
| Multi-tenancy | Base compartida con `tenant_id`. El tenant se resuelve por subdominio (`{slug}.{TENANCY_CENTRAL_DOMAIN}`) antes de autenticar. El dominio central no tiene tenant: solo alta de organizaciones y operaciones globales. |
| Aislamiento | Tres barreras: scope global de Eloquent (`BelongsToTenant`), token válido solo en el subdominio de su tenant, y Row Level Security de PostgreSQL (`Rls::enable`). Sin contexto resuelto no se ve ningún dato (deny-by-default). |
| Rol de BD | La aplicación se conecta como `erp_app` (sin superusuario ni `BYPASSRLS`). Consola y migraciones operan en modo sistema (`app.bypass_rls`). |
| Datos | Nombres del Maestro §5–6: tablas en plural, PK `id` UUID, `tenant_id`, `timestamptz`. El esquema lo versionan las migraciones, no un SQL inicial. |
| Sesiones | Tokens de Sanctum (`personal_access_tokens`) cubren `sessions` del Maestro. Cada sesión vence a los `AUTH_SESSION_LIFETIME` minutos (720 por defecto), guarda IP y dispositivo, y el usuario puede listarlas y cerrarlas. Se cierran solas al desactivar la cuenta, restablecer la contraseña o cambiarla (las demás). |
| Contraseñas | Recuperación por enlace de un solo uso enviado por correo (`AUTH_PASSWORD_RESET_MINUTES`, 60 por defecto); solo se guarda el hash del token y la respuesta no revela si la cuenta existe. El correo sale por el mailer de Laravel (`MAIL_MAILER=log` hasta definir proveedor). |
| Permisos | Convención `recurso.acción` con comodines (`*`, `recurso.*`). Catálogo en `PermissionCatalog`, roles base en `RoleCatalog` (Maestro §8.1). Se exigen por ruta con el middleware `permission:`. |
| Roles | Cada organización crea roles propios desde el catálogo global, agrupado por módulo. Los seis roles base son de sistema: no se editan ni eliminan, y se actualizan con `php artisan access:sync`. Nadie puede otorgar un permiso que no tiene, y `*` queda reservado al Administrador de sistema. |
| Plataforma | El administrador de plataforma es una identidad aparte (`platform_admins`), solo válida en el dominio central (`/api/v1/platform/*`). Ningún rol de tenant alcanza esas operaciones y su token no sirve dentro de una organización. Alta: `php artisan platform:create-admin`. |
| B2B | Modelo confirmado: varias academias independientes, cada una con su subdominio; las empresas cliente viven dentro de la academia que les vendió. Habrá dos roles: personal interno de la academia (cotiza y carga licencias) y "Responsable de empresa" (uno o varios por empresa) que asigna licencias, ve avance y certificados de sus trabajadores, acepta cotizaciones en línea y ve sus facturas y pagos, acotado a su propia empresa. Se construye en S8. |
| Contrato de API | Éxito: `data` + `meta`. Error: `code` + `message` + `details` + `trace_id`. `X-Request-ID` en toda respuesta. Códigos en `ErrorCode`. |
| Auditoría | `audit_events` de solo inserción (trigger en BD), con actor, tenant, entidad, antes/después e IP. |
| Frontend | Áreas por ruta dentro de la misma app; código en `src/features/{dominio}`, `src/layouts`, `src/shared`. La API se consume en el mismo origen (`/api/v1`). |

## Reglas para módulos nuevos

1. Toda tabla de negocio lleva `tenant_id`, el trait `BelongsToTenant` y `Rls::enable()` en su migración.
2. Toda ruta declara `tenant` o `central`, y su permiso con `permission:recurso.acción`.
3. Las operaciones sensibles registran un evento con `AuditLogger`.
4. Los proveedores externos (pagos, Yape, fiscal, correo, IA, storage) se consumen tras una interfaz interna; las decisiones PD-001…PD-017 del Maestro no se cierran en el código.
5. Los jobs encolados deben restablecer el contexto de tenant antes de ejecutar (pendiente de implementar con el primer job).

## Desarrollo local

- Base: `docker compose up -d` (crea el rol `erp_app` y la base de pruebas `erp_saas_test`).
- Backend: `php artisan migrate --seed` y `php artisan serve`.
- Frontend: `npm run dev`. Dominio central en `http://localhost:5173`; organización demo en `http://demo.localhost:5173`.
- Pruebas: `php artisan test` (usa PostgreSQL para validar RLS).
