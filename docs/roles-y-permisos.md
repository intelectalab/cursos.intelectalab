# Roles y accesos

> Documento generado con `php artisan access:docs` a partir de `RoleCatalog` y `PermissionCatalog`.
> No lo edites a mano: cambia los catálogos, ejecuta `php artisan access:sync` y vuelve a generarlo.

## 1. Niveles de acceso

| Nivel | Quién | Dónde entra | Alcance |
| --- | --- | --- | --- |
| Plataforma | Administrador de plataforma (IntelectaLab) | Dominio central | Lista, suspende y reactiva academias. No pertenece a ninguna academia y su sesión no sirve dentro de ellas. |
| Academia | Usuarios de cada academia, según su rol | Subdominio de la academia (`{academia}.dominio`) | Solo los datos de su academia. |
| Público | Visitantes sin sesión | Subdominio de la academia | Inicio de sesión. En el dominio central, registro de nuevas academias. |

El aislamiento entre academias no depende de los permisos: lo aplican el subdominio, el filtro por organización en cada consulta y la seguridad por filas de la base de datos.

## 2. Roles base

Cada academia recibe estos seis roles al crearse. Son fijos: no se editan ni se eliminan.

| Rol | Código | Descripción | Permisos |
| --- | --- | --- | --- |
| Administrador | `ADMIN` | Administración integral de la organización | Acceso total |
| Alumno | `STUDENT` | Consumo de cursos, actividades y certificados | 13 |
| Profesor | `TEACHER` | Gestión académica de los cursos asignados | 5 |
| Soporte | `SUPPORT` | Atención y seguimiento de tickets | 4 |
| Ventas/CRM | `SALES` | Gestión comercial y CRM | 4 |
| B2B autorizado | `B2B` | Operaciones corporativas: cuentas, licencias y cotizaciones | 3 |

### Roles propios

Además de los roles base, cada academia puede crear sus propios roles y elegir sus permisos por módulo (pantalla **Roles y permisos**). Reglas:

- Un rol propio solo puede incluir permisos del catálogo de la sección 4; nunca el acceso total.
- Nadie puede otorgar un permiso que su propio usuario no tiene.
- Un rol con usuarios asignados no se puede eliminar.
- La academia siempre conserva al menos un administrador activo.
- Todo cambio de roles y asignaciones queda en la auditoría.

### Rol pendiente: Responsable de empresa (B2B)

Se agregará en el sprint S8. Será el contacto de una empresa cliente (uno o varios por empresa): asigna licencias a sus trabajadores, ve su avance y certificados, acepta cotizaciones en línea y ve sus facturas y pagos, siempre acotado a su propia empresa.

## 3. Detalle por rol

### Administrador

Acceso total a todos los módulos de su academia (52 permisos). No alcanza las operaciones de plataforma.

### Alumno

- `student.portal`: Acceder al portal del alumno
- `courses.catalog.read`: Consultar el catálogo de cursos
- `enrollments.self.read`: Consultar las matrículas propias
- `activities.self`: Consultar las actividades propias
- `activities.attempt`: Rendir actividades
- `certificates.self`: Consultar los certificados propios
- `orders.create`: Crear órdenes
- `checkout.validate`: Validar cupones en el checkout
- `payments.create`: Registrar pagos
- `wallet.use`: Usar la billetera
- `tickets.self`: Consultar y responder los tickets propios
- `tickets.create`: Crear tickets
- `ai.chat`: Usar el chat de IA

### Profesor

- `courses.assigned.read`: Consultar los cursos asignados
- `content.assigned.write`: Editar el contenido de los cursos asignados
- `activities.assigned.manage`: Gestionar actividades de los cursos asignados
- `submissions.assigned.review`: Revisar entregas de los cursos asignados
- `reports.assigned.read`: Consultar reportes de los cursos asignados

### Soporte

- `tickets.*`: Gestión completa de tickets
- `users.support.read`: Consultar usuarios para atención de soporte
- `orders.support.read`: Consultar órdenes para atención de soporte
- `enrollments.support.read`: Consultar matrículas para atención de soporte

### Ventas/CRM

- `crm.*`: Gestión completa del CRM
- `quotations.*`: Gestión completa de cotizaciones
- `orders.read`: Consultar órdenes
- `orders.create`: Crear órdenes

### B2B autorizado

- `corporate_accounts.*`: Gestión completa de cuentas corporativas
- `licenses.*`: Gestión completa de licencias
- `quotations.*`: Gestión completa de cotizaciones

## 4. Matriz de permisos por módulo

Una marca indica que el rol base tiene ese permiso, ya sea directamente o por un comodín del mismo recurso.

### Usuarios y roles

| Permiso | Descripción | Administrador | Alumno | Profesor | Soporte | Ventas/CRM | B2B autorizado |
| --- | --- | :---: | :---: | :---: | :---: | :---: | :---: |
| `users.read` | Consultar usuarios | ✔ |  |  |  |  |  |
| `users.create` | Crear usuarios | ✔ |  |  |  |  |  |
| `users.update` | Editar usuarios y asignar roles | ✔ |  |  |  |  |  |
| `users.support.read` | Consultar usuarios para atención de soporte | ✔ |  |  | ✔ |  |  |
| `roles.read` | Consultar roles y permisos | ✔ |  |  |  |  |  |
| `roles.create` | Crear roles | ✔ |  |  |  |  |  |
| `roles.update` | Editar roles | ✔ |  |  |  |  |  |
| `roles.delete` | Eliminar roles | ✔ |  |  |  |  |  |

### Configuración y auditoría

| Permiso | Descripción | Administrador | Alumno | Profesor | Soporte | Ventas/CRM | B2B autorizado |
| --- | --- | :---: | :---: | :---: | :---: | :---: | :---: |
| `tenant_settings.read` | Consultar la configuración de la organización | ✔ |  |  |  |  |  |
| `tenant_settings.update` | Editar la configuración de la organización | ✔ |  |  |  |  |  |
| `audit.read` | Consultar auditoría | ✔ |  |  |  |  |  |

### Cursos y contenido

| Permiso | Descripción | Administrador | Alumno | Profesor | Soporte | Ventas/CRM | B2B autorizado |
| --- | --- | :---: | :---: | :---: | :---: | :---: | :---: |
| `courses.read` | Consultar cursos | ✔ |  |  |  |  |  |
| `courses.create` | Crear cursos | ✔ |  |  |  |  |  |
| `courses.update` | Editar cursos | ✔ |  |  |  |  |  |
| `courses.publish` | Publicar cursos | ✔ |  |  |  |  |  |
| `courses.content.write` | Editar secciones, recursos y actividades | ✔ |  |  |  |  |  |
| `courses.catalog.read` | Consultar el catálogo de cursos | ✔ | ✔ |  |  |  |  |
| `courses.assigned.read` | Consultar los cursos asignados | ✔ |  | ✔ |  |  |  |
| `content.assigned.write` | Editar el contenido de los cursos asignados | ✔ |  | ✔ |  |  |  |
| `scorm.manage` | Gestionar paquetes SCORM | ✔ |  |  |  |  |  |

### Académico

| Permiso | Descripción | Administrador | Alumno | Profesor | Soporte | Ventas/CRM | B2B autorizado |
| --- | --- | :---: | :---: | :---: | :---: | :---: | :---: |
| `activities.attempt` | Rendir actividades | ✔ | ✔ |  |  |  |  |
| `activities.self` | Consultar las actividades propias | ✔ | ✔ |  |  |  |  |
| `activities.assigned.manage` | Gestionar actividades de los cursos asignados | ✔ |  | ✔ |  |  |  |
| `submissions.assigned.review` | Revisar entregas de los cursos asignados | ✔ |  | ✔ |  |  |  |

### Matrículas y portal del alumno

| Permiso | Descripción | Administrador | Alumno | Profesor | Soporte | Ventas/CRM | B2B autorizado |
| --- | --- | :---: | :---: | :---: | :---: | :---: | :---: |
| `enrollments.create` | Crear matrículas | ✔ |  |  |  |  |  |
| `enrollments.self.read` | Consultar las matrículas propias | ✔ | ✔ |  |  |  |  |
| `enrollments.support.read` | Consultar matrículas para atención de soporte | ✔ |  |  | ✔ |  |  |
| `student.portal` | Acceder al portal del alumno | ✔ | ✔ |  |  |  |  |

### Certificados

| Permiso | Descripción | Administrador | Alumno | Profesor | Soporte | Ventas/CRM | B2B autorizado |
| --- | --- | :---: | :---: | :---: | :---: | :---: | :---: |
| `certificates.issue` | Emitir certificados | ✔ |  |  |  |  |  |
| `certificates.self` | Consultar los certificados propios | ✔ | ✔ |  |  |  |  |

### Ventas

| Permiso | Descripción | Administrador | Alumno | Profesor | Soporte | Ventas/CRM | B2B autorizado |
| --- | --- | :---: | :---: | :---: | :---: | :---: | :---: |
| `orders.read` | Consultar órdenes | ✔ |  |  |  | ✔ |  |
| `orders.create` | Crear órdenes | ✔ | ✔ |  |  | ✔ |  |
| `orders.support.read` | Consultar órdenes para atención de soporte | ✔ |  |  | ✔ |  |  |
| `checkout.validate` | Validar cupones en el checkout | ✔ | ✔ |  |  |  |  |

### Pagos y finanzas

| Permiso | Descripción | Administrador | Alumno | Profesor | Soporte | Ventas/CRM | B2B autorizado |
| --- | --- | :---: | :---: | :---: | :---: | :---: | :---: |
| `payments.create` | Registrar pagos | ✔ | ✔ |  |  |  |  |
| `payments.refund` | Reembolsar pagos | ✔ |  |  |  |  |  |
| `wallet.use` | Usar la billetera | ✔ | ✔ |  |  |  |  |

### CRM

| Permiso | Descripción | Administrador | Alumno | Profesor | Soporte | Ventas/CRM | B2B autorizado |
| --- | --- | :---: | :---: | :---: | :---: | :---: | :---: |
| `crm.*` | Gestión completa del CRM | ✔ |  |  |  | ✔ |  |

### B2B y licencias

| Permiso | Descripción | Administrador | Alumno | Profesor | Soporte | Ventas/CRM | B2B autorizado |
| --- | --- | :---: | :---: | :---: | :---: | :---: | :---: |
| `quotations.*` | Gestión completa de cotizaciones | ✔ |  |  |  | ✔ | ✔ |
| `quotations.create` | Crear cotizaciones | ✔ |  |  |  | ✔ | ✔ |
| `corporate_accounts.*` | Gestión completa de cuentas corporativas | ✔ |  |  |  |  | ✔ |
| `licenses.*` | Gestión completa de licencias | ✔ |  |  |  |  | ✔ |
| `licenses.assign` | Asignar licencias | ✔ |  |  |  |  | ✔ |

### Soporte

| Permiso | Descripción | Administrador | Alumno | Profesor | Soporte | Ventas/CRM | B2B autorizado |
| --- | --- | :---: | :---: | :---: | :---: | :---: | :---: |
| `tickets.*` | Gestión completa de tickets | ✔ |  |  | ✔ |  |  |
| `tickets.create` | Crear tickets | ✔ | ✔ |  | ✔ |  |  |
| `tickets.self` | Consultar y responder los tickets propios | ✔ | ✔ |  | ✔ |  |  |

### CMS

| Permiso | Descripción | Administrador | Alumno | Profesor | Soporte | Ventas/CRM | B2B autorizado |
| --- | --- | :---: | :---: | :---: | :---: | :---: | :---: |
| `cms.update` | Editar páginas del CMS | ✔ |  |  |  |  |  |
| `cms.publish` | Publicar páginas del CMS | ✔ |  |  |  |  |  |

### Chat IA

| Permiso | Descripción | Administrador | Alumno | Profesor | Soporte | Ventas/CRM | B2B autorizado |
| --- | --- | :---: | :---: | :---: | :---: | :---: | :---: |
| `ai.chat` | Usar el chat de IA | ✔ | ✔ |  |  |  |  |

### Reportes

| Permiso | Descripción | Administrador | Alumno | Profesor | Soporte | Ventas/CRM | B2B autorizado |
| --- | --- | :---: | :---: | :---: | :---: | :---: | :---: |
| `reports.read` | Consultar reportes | ✔ |  |  |  |  |  |
| `reports.export` | Exportar reportes | ✔ |  |  |  |  |  |
| `reports.assigned.read` | Consultar reportes de los cursos asignados | ✔ |  | ✔ |  |  |  |

## 5. Cómo leer un permiso

Los permisos siguen la forma `recurso.acción`. Algunos llevan un alcance que limita los datos:

| Forma | Significado |
| --- | --- |
| `recurso.acción` | La acción sobre todos los registros de la academia. |
| `recurso.*` | Todas las acciones sobre ese recurso. |
| `recurso.self…` | Solo los registros propios del usuario. |
| `recurso.assigned…` | Solo los cursos asignados al profesor. |
| `recurso.support.read` | Solo lectura, para atención de soporte. |

El catálogo crece con cada módulo: un permiso se agrega cuando existe la función que lo exige.

## 6. Accesos de prueba (solo entorno local)

Los crea `php artisan db:seed`. La contraseña de los tres está definida en `backend/database/seeders/DemoTenantSeeder.php` (variable `DEMO_ADMIN_PASSWORD`). No existen fuera del entorno local.

| Acceso | Dirección | Correo | Rol |
| --- | --- | --- | --- |
| Academia demo | `http://demo.localhost:5173` | `admin@plataforma.com` | Administrador |
| Academia demo | `http://demo.localhost:5173` | `alumno@plataforma.com` | Alumno |
| Plataforma (solo API por ahora) | `http://localhost:5173/api/v1/platform` | `plataforma@intelectalab.test` | Administrador de plataforma |

En otros ambientes el administrador de plataforma se crea con `php artisan platform:create-admin`.
