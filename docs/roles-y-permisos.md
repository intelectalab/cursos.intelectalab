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

Cada academia recibe estos roles al crearse. Son fijos: no se editan ni se eliminan.

| Rol | Código | Descripción | Permisos |
| --- | --- | --- | --- |
| Administrador | `ADMIN` | Administración integral de la organización | Acceso total |
| Alumno | `STUDENT` | Consumo de cursos, actividades y certificados | 13 |
| Profesor | `TEACHER` | Gestión académica de los cursos asignados | 7 |
| Soporte | `SUPPORT` | Atención y seguimiento de tickets | 5 |
| Ventas/CRM | `SALES` | Gestión comercial y CRM | 6 |
| B2B autorizado | `B2B` | Operaciones corporativas: cuentas, licencias y cotizaciones | 3 |
| Responsable de empresa | `COMPANY` | Portal de una empresa cliente: licencias, trabajadores, avance y cotizaciones | 3 |

### Roles propios

Además de los roles base, cada academia puede crear sus propios roles y elegir sus permisos por módulo (pantalla **Roles y permisos**). Reglas:

- Un rol propio solo puede incluir permisos del catálogo de la sección 4; nunca el acceso total.
- Nadie puede otorgar un permiso que su propio usuario no tiene.
- Un rol con usuarios asignados no se puede eliminar.
- La academia siempre conserva al menos un administrador activo.
- Todo cambio de roles y asignaciones queda en la auditoría.

### Responsable de empresa (B2B)

Es el contacto externo de una empresa cliente (uno o varios por empresa). Registra a sus trabajadores, les asigna licencias, ve su avance y certificados, responde cotizaciones y ve las compras de su empresa. Todo lo que ve está acotado a su propia empresa: el permiso `company.portal` no sirve sin estar vinculado a una.

## 3. Detalle por rol

### Administrador

Acceso total a todos los módulos de su academia (80 permisos). No alcanza las operaciones de plataforma.

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
- `question_banks.read`: Consultar los bancos de preguntas
- `question_banks.manage`: Crear y editar bancos de preguntas

### Soporte

- `tickets.*`: Gestión completa de tickets
- `users.support.read`: Consultar usuarios para atención de soporte
- `orders.support.read`: Consultar órdenes para atención de soporte
- `enrollments.support.read`: Consultar matrículas para atención de soporte
- `claims.read`: Consultar las hojas del Libro de Reclamaciones

### Ventas/CRM

- `crm.*`: Gestión completa del CRM
- `quotations.*`: Gestión completa de cotizaciones
- `orders.read`: Consultar órdenes
- `orders.create`: Crear órdenes
- `promotions.read`: Consultar promociones, cupones y sus usos
- `packages.read`: Consultar los paquetes de cursos

### B2B autorizado

- `corporate_accounts.*`: Gestión completa de cuentas corporativas
- `licenses.*`: Gestión completa de licencias
- `quotations.*`: Gestión completa de cotizaciones

### Responsable de empresa

- `company.portal`: Acceder al portal de empresa (solo para responsables de una empresa cliente)
- `tickets.self`: Consultar y responder los tickets propios
- `tickets.create`: Crear tickets

## 4. Matriz de permisos por módulo

Una marca indica que el rol base tiene ese permiso, ya sea directamente o por un comodín del mismo recurso.

### Usuarios y roles

| Permiso | Descripción | Administrador | Alumno | Profesor | Soporte | Ventas/CRM | B2B autorizado | Responsable de empresa |
| --- | --- | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| `users.read` | Consultar usuarios | ✔ |  |  |  |  |  |  |
| `users.create` | Crear usuarios | ✔ |  |  |  |  |  |  |
| `users.update` | Editar usuarios y asignar roles | ✔ |  |  |  |  |  |  |
| `users.support.read` | Consultar usuarios para atención de soporte | ✔ |  |  | ✔ |  |  |  |
| `roles.read` | Consultar roles y permisos | ✔ |  |  |  |  |  |  |
| `roles.create` | Crear roles | ✔ |  |  |  |  |  |  |
| `roles.update` | Editar roles | ✔ |  |  |  |  |  |  |
| `roles.delete` | Eliminar roles | ✔ |  |  |  |  |  |  |

### Configuración y auditoría

| Permiso | Descripción | Administrador | Alumno | Profesor | Soporte | Ventas/CRM | B2B autorizado | Responsable de empresa |
| --- | --- | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| `tenant_settings.read` | Consultar la configuración de la organización | ✔ |  |  |  |  |  |  |
| `tenant_settings.update` | Editar la configuración de la organización | ✔ |  |  |  |  |  |  |
| `audit.read` | Consultar auditoría | ✔ |  |  |  |  |  |  |

### Cursos y contenido

| Permiso | Descripción | Administrador | Alumno | Profesor | Soporte | Ventas/CRM | B2B autorizado | Responsable de empresa |
| --- | --- | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| `courses.read` | Consultar cursos | ✔ |  |  |  |  |  |  |
| `courses.create` | Crear cursos | ✔ |  |  |  |  |  |  |
| `courses.update` | Editar cursos | ✔ |  |  |  |  |  |  |
| `courses.publish` | Publicar cursos | ✔ |  |  |  |  |  |  |
| `courses.content.write` | Editar secciones, recursos y actividades | ✔ |  |  |  |  |  |  |
| `courses.catalog.read` | Consultar el catálogo de cursos | ✔ | ✔ |  |  |  |  |  |
| `courses.assigned.read` | Consultar los cursos asignados | ✔ |  | ✔ |  |  |  |  |
| `content.assigned.write` | Editar el contenido de los cursos asignados | ✔ |  | ✔ |  |  |  |  |
| `scorm.manage` | Gestionar paquetes SCORM | ✔ |  |  |  |  |  |  |

### Académico

| Permiso | Descripción | Administrador | Alumno | Profesor | Soporte | Ventas/CRM | B2B autorizado | Responsable de empresa |
| --- | --- | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| `activities.attempt` | Rendir actividades | ✔ | ✔ |  |  |  |  |  |
| `activities.self` | Consultar las actividades propias | ✔ | ✔ |  |  |  |  |  |
| `activities.assigned.manage` | Gestionar actividades de los cursos asignados | ✔ |  | ✔ |  |  |  |  |
| `question_banks.read` | Consultar los bancos de preguntas | ✔ |  | ✔ |  |  |  |  |
| `question_banks.manage` | Crear y editar bancos de preguntas | ✔ |  | ✔ |  |  |  |  |
| `submissions.review` | Revisar entregas y ver el avance en todos los cursos | ✔ |  |  |  |  |  |  |
| `submissions.assigned.review` | Revisar entregas de los cursos asignados | ✔ |  | ✔ |  |  |  |  |

### Matrículas y portal del alumno

| Permiso | Descripción | Administrador | Alumno | Profesor | Soporte | Ventas/CRM | B2B autorizado | Responsable de empresa |
| --- | --- | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| `enrollments.read` | Consultar las matrículas de los cursos | ✔ |  |  |  |  |  |  |
| `enrollments.create` | Crear matrículas | ✔ |  |  |  |  |  |  |
| `enrollments.update` | Cambiar la vigencia de las matrículas | ✔ |  |  |  |  |  |  |
| `enrollments.cancel` | Cancelar matrículas | ✔ |  |  |  |  |  |  |
| `enrollments.self.read` | Consultar las matrículas propias | ✔ | ✔ |  |  |  |  |  |
| `enrollments.support.read` | Consultar matrículas para atención de soporte | ✔ |  |  | ✔ |  |  |  |
| `student.portal` | Acceder al portal del alumno | ✔ | ✔ |  |  |  |  |  |

### Certificados

| Permiso | Descripción | Administrador | Alumno | Profesor | Soporte | Ventas/CRM | B2B autorizado | Responsable de empresa |
| --- | --- | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| `certificates.read` | Consultar los certificados emitidos | ✔ |  |  |  |  |  |  |
| `certificates.issue` | Emitir certificados | ✔ |  |  |  |  |  |  |
| `certificates.revoke` | Anular certificados | ✔ |  |  |  |  |  |  |
| `certificates.self` | Consultar los certificados propios | ✔ | ✔ |  |  |  |  |  |

### Ventas

| Permiso | Descripción | Administrador | Alumno | Profesor | Soporte | Ventas/CRM | B2B autorizado | Responsable de empresa |
| --- | --- | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| `orders.read` | Consultar órdenes | ✔ |  |  |  | ✔ |  |  |
| `orders.create` | Crear órdenes | ✔ | ✔ |  |  | ✔ |  |  |
| `orders.cancel` | Cancelar órdenes pendientes | ✔ |  |  |  |  |  |  |
| `orders.support.read` | Consultar órdenes para atención de soporte | ✔ |  |  | ✔ |  |  |  |
| `checkout.validate` | Validar cupones en el checkout | ✔ | ✔ |  |  |  |  |  |
| `promotions.read` | Consultar promociones, cupones y sus usos | ✔ |  |  |  | ✔ |  |  |
| `promotions.manage` | Crear y editar promociones y cupones | ✔ |  |  |  |  |  |  |
| `packages.read` | Consultar los paquetes de cursos | ✔ |  |  |  | ✔ |  |  |
| `packages.manage` | Crear y editar paquetes de cursos | ✔ |  |  |  |  |  |  |

### Pagos y finanzas

| Permiso | Descripción | Administrador | Alumno | Profesor | Soporte | Ventas/CRM | B2B autorizado | Responsable de empresa |
| --- | --- | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| `payments.create` | Registrar pagos | ✔ | ✔ |  |  |  |  |  |
| `payments.confirm` | Confirmar pagos recibidos | ✔ |  |  |  |  |  |  |
| `payments.refund` | Reembolsar pagos | ✔ |  |  |  |  |  |  |
| `payments.defer` | Entregar licencias a una empresa antes de recibir el pago | ✔ |  |  |  |  |  |  |
| `wallet.use` | Usar la billetera | ✔ | ✔ |  |  |  |  |  |

### CRM

| Permiso | Descripción | Administrador | Alumno | Profesor | Soporte | Ventas/CRM | B2B autorizado | Responsable de empresa |
| --- | --- | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| `crm.*` | Gestión completa del CRM | ✔ |  |  |  | ✔ |  |  |
| `crm.read` | Consultar prospectos, empresas y oportunidades | ✔ |  |  |  | ✔ |  |  |
| `crm.manage` | Registrar y editar prospectos, empresas y oportunidades | ✔ |  |  |  | ✔ |  |  |

### B2B y licencias

| Permiso | Descripción | Administrador | Alumno | Profesor | Soporte | Ventas/CRM | B2B autorizado | Responsable de empresa |
| --- | --- | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| `quotations.*` | Gestión completa de cotizaciones | ✔ |  |  |  | ✔ | ✔ |  |
| `quotations.read` | Consultar cotizaciones | ✔ |  |  |  | ✔ | ✔ |  |
| `quotations.create` | Crear, editar y enviar cotizaciones | ✔ |  |  |  | ✔ | ✔ |  |
| `quotations.accept` | Registrar la aceptación o el rechazo del cliente | ✔ |  |  |  | ✔ | ✔ |  |
| `corporate_accounts.*` | Gestión completa de cuentas corporativas | ✔ |  |  |  |  | ✔ |  |
| `corporate_accounts.read` | Consultar las cuentas de las empresas cliente | ✔ |  |  |  |  | ✔ |  |
| `corporate_accounts.manage` | Dar acceso al portal y registrar trabajadores de una empresa | ✔ |  |  |  |  | ✔ |  |
| `licenses.*` | Gestión completa de licencias | ✔ |  |  |  |  | ✔ |  |
| `licenses.read` | Consultar licencias y su asignación | ✔ |  |  |  |  | ✔ |  |
| `licenses.assign` | Asignar y retirar licencias | ✔ |  |  |  |  | ✔ |  |
| `company.portal` | Acceder al portal de empresa (solo para responsables de una empresa cliente) | ✔ |  |  |  |  |  | ✔ |

### Soporte

| Permiso | Descripción | Administrador | Alumno | Profesor | Soporte | Ventas/CRM | B2B autorizado | Responsable de empresa |
| --- | --- | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| `tickets.*` | Gestión completa de tickets | ✔ |  |  | ✔ |  |  |  |
| `tickets.read` | Consultar todos los tickets de soporte | ✔ |  |  | ✔ |  |  |  |
| `tickets.manage` | Atender tickets: responder, asignar y cambiar su estado | ✔ |  |  | ✔ |  |  |  |
| `tickets.create` | Crear tickets | ✔ | ✔ |  | ✔ |  |  | ✔ |
| `tickets.self` | Consultar y responder los tickets propios | ✔ | ✔ |  | ✔ |  |  | ✔ |

### Libro de Reclamaciones

| Permiso | Descripción | Administrador | Alumno | Profesor | Soporte | Ventas/CRM | B2B autorizado | Responsable de empresa |
| --- | --- | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| `claims.read` | Consultar las hojas del Libro de Reclamaciones | ✔ |  |  | ✔ |  |  |  |
| `claims.manage` | Atender y responder reclamos y quejas | ✔ |  |  |  |  |  |  |

### Páginas (CMS)

| Permiso | Descripción | Administrador | Alumno | Profesor | Soporte | Ventas/CRM | B2B autorizado | Responsable de empresa |
| --- | --- | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| `cms.read` | Consultar las páginas y sus borradores | ✔ |  |  |  |  |  |  |
| `cms.update` | Crear y editar páginas (borrador) | ✔ |  |  |  |  |  |  |
| `cms.publish` | Publicar y retirar páginas | ✔ |  |  |  |  |  |  |

### Chat IA

| Permiso | Descripción | Administrador | Alumno | Profesor | Soporte | Ventas/CRM | B2B autorizado | Responsable de empresa |
| --- | --- | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| `ai.chat` | Usar el chat de IA | ✔ | ✔ |  |  |  |  |  |

### Reportes

| Permiso | Descripción | Administrador | Alumno | Profesor | Soporte | Ventas/CRM | B2B autorizado | Responsable de empresa |
| --- | --- | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| `reports.read` | Consultar reportes | ✔ |  |  |  |  |  |  |
| `reports.export` | Exportar reportes | ✔ |  |  |  |  |  |  |
| `reports.assigned.read` | Consultar reportes de los cursos asignados | ✔ |  | ✔ |  |  |  |  |

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
| Consola de plataforma | `http://localhost:5173/platform` | `plataforma@intelectalab.test` | Administrador de plataforma |

En otros ambientes el administrador de plataforma se crea con `php artisan platform:create-admin`.
