# S8 — Empresas y CRM · Entrega 3: licencias y portal de empresa

Fecha: 2026-10-05 · Sin commit al momento de escribir este documento

Con esta entrega S8 queda completo. La venta a una empresa ya termina donde debe: la empresa recibe sus **licencias**, las **asigna a sus trabajadores** (que quedan matriculados) y sigue su avance desde su propio **portal** (Maestro RF-B2B-001, FP-04, US-B2B-002, RN-010, TC-B2B-001 y TC-B2B-002).

## Qué se construyó

### Licencias

- Una licencia es **un cupo de un curso** para un trabajador de la empresa. Una venta de 25 licencias crea 25 cupos.
- Se entregan **cuando el personal confirma el pago** de la orden de la empresa (pantalla *Ventas y pagos*).
- **Entregar sin pago**: en una orden de empresa pendiente aparece el botón "Entregar licencias sin pago". Pide un motivo y queda registrado quién lo autorizó. Pensado para empresas que pagan a 30 días. Lo puede hacer quien tenga el permiso nuevo `payments.defer` (de los roles base, solo el Administrador). Pagar después no entrega las licencias otra vez.
- Si la orden se **cancela**, las licencias que nadie usó se anulan; las ya asignadas se conservan porque el trabajador ya está cursando.
- La empresa tiene **12 meses para asignar** cada licencia. Pasado ese plazo figura como vencida sin asignar.

### Asignar y retirar

- Al asignar, el trabajador queda **matriculado al instante**, con los "días de acceso" del curso contados desde ese día.
- Solo se asigna a personas registradas en esa empresa, y no a quien ya tiene una matrícula activa en ese curso.
- Si se eligen más personas que licencias disponibles, no se asigna ninguna y se avisa cuántas quedan.
- Una licencia ya usada no se asigna a otra persona (TC-B2B-002).
- **Retirar**: mientras el trabajador **no haya empezado** el curso, se le puede quitar la licencia; pierde el acceso y el cupo vuelve a estar disponible. Si ya abrió algún contenido, no se puede.
- Cada asignación guarda a quién, quién la hizo y cuándo (RN-010); las retiradas quedan como historial.

### Portal del Responsable de empresa

Rol base nuevo, el séptimo: **Responsable de empresa**. Es una persona de la empresa cliente, no de la academia. Al entrar ve solo **Inicio** y **Mi empresa**, con:

- **Cotizaciones** que la academia le envió: las abre, las **acepta en línea** o las rechaza con motivo. No ve borradores ni el historial interno de versiones.
- **Licencias por curso**: cuántas compró, cuántas asignó y cuántas le quedan, y hasta cuándo puede asignarlas.
- **Sus trabajadores** con el curso asignado: avance (%), si lo completó, hasta cuándo tiene acceso y el código del certificado. No ve las notas de cada actividad.
- **Registrar trabajadores**: nombre, correo y una contraseña inicial que él mismo le entrega (no hay correo todavía).
- **Compras**: las órdenes de su empresa y si están pagadas.

Todo lo que ve y hace está acotado a su empresa: la empresa sale de su vinculación, nunca de un dato que envíe el navegador.

Lo que el Responsable **no** puede hacer: crear otros responsables, ni vincular a su empresa una cuenta que ya existe en la academia (así no puede "atraer" a un alumno ajeno para ver su avance). Ambas cosas las hace el personal.

### El personal de la academia

- En la ficha de una empresa (CRM → Empresas) aparece **"Licencias y portal"**: la misma vista que tiene el Responsable, para atender a la empresa en su nombre.
- Desde ahí el personal **da acceso al portal** (agrega una persona marcada como responsable), registra trabajadores o vincula una cuenta existente, asigna y retira licencias.

| Permiso | Qué permite | Roles base |
| --- | --- | --- |
| `corporate_accounts.read` | Ver la cuenta de una empresa cliente | Administrador, B2B autorizado |
| `corporate_accounts.manage` | Dar acceso al portal y registrar trabajadores | Administrador, B2B autorizado |
| `licenses.read` | Ver licencias y su asignación | Administrador, B2B autorizado |
| `licenses.assign` | Asignar y retirar licencias | Administrador, B2B autorizado |
| `payments.defer` | Entregar licencias antes del pago | Administrador |
| `company.portal` | Entrar al portal de empresa | Responsable de empresa |

El rol **Ventas/CRM** vende y cotiza, pero no administra licencias (así lo define el Maestro §8.1); eso es del rol **B2B autorizado**.

## Detalle técnico

- Tablas nuevas en `backend/app/Modules/B2b`: `licenses`, `license_assignments` (Maestro §5.1) y `corporate_members` (qué usuarios pertenecen a qué empresa y cuáles son responsables). En `orders` se agregan tres columnas para la entrega anticipada.
- La entrega de licencias escucha el evento `OrderPaid`, y la anulación, `OrderCancelled` (nuevo), ambos dentro de la transacción de la orden.
- **Rutas**: portal en `/api/v1/company/...`; personal en `/api/v1/corporate-accounts/{empresa}/...`; `POST /licenses/{id}/assign` (Maestro §7.2); `POST /orders/{id}/release-licenses`.
- La matrícula creada por una licencia lleva el origen `LICENSE`.
- **`php artisan access:sync` ahora también crea los roles base que falten** en las academias que ya existían. Hay que ejecutarlo al desplegar para que reciban el rol "Responsable de empresa".
- Códigos de error nuevos: `LICENSE_NOT_AVAILABLE`, `LICENSE_IN_USE`, `ORDER_NOT_CORPORATE`.
- Frontend: `frontend/src/features/company`. En el menú, un módulo puede marcarse `explicit` para que no lo vea quien solo lo alcanza por tener acceso total (el administrador no ve "Mi empresa").

## Qué se verificó

- **190 pruebas del backend pasan**, 11 nuevas (`tests/Feature/B2b/LicenseTest.php`): entrega al pagar y asignación que matricula (TC-B2B-001), licencia usada rechazada (TC-B2B-002), no asignar a medias, retirar solo antes de empezar, avance y certificado visibles para la empresa, entrega sin pago con motivo y sin duplicar al pagar, anulación al cancelar la orden, alcance del Responsable (no ve otra empresa ni las pantallas del personal), registro de trabajadores, cotizaciones respondidas desde el portal, permisos por rol, aislamiento entre academias y creación del rol nuevo en academias existentes.
- En una de las dos corridas completas falló una vez una prueba antigua de matrículas (`EnrollmentValidityTest`, de S4, que este sprint no toca). Sola pasó cinco veces seguidas y la segunda corrida completa pasó entera. No encontré la causa; queda anotado en pendientes.
- El frontend compila, pasa el lint y genera el build.
- **En el navegador, academia demo**, de punta a punta: confirmé el pago de la orden de Textiles del Sur y aparecieron sus 25 licencias; di acceso al portal a una responsable y registré dos trabajadores; les asigné licencias; entré como la responsable, vi "Mi empresa" y acepté en línea una segunda cotización (nació la orden N.º 00005); entré como un trabajador y el curso estaba en "Mis cursos".
- **No se probó en el navegador**: retirar una licencia, entregar licencias sin pago (solo abrí el formulario), rechazar una cotización desde el portal, vincular una cuenta existente, ni el tamaño de teléfono.

## Qué quedó corto

- **Sin carga masiva** de trabajadores desde un archivo: se registran uno por uno.
- **Sin correo**: la contraseña inicial la entrega quien registra a la persona. No hay aviso al trabajador de que tiene un curso asignado.
- El Responsable **no descarga los certificados** de sus trabajadores: ve el código y puede verificarlo en la página pública.
- **No se quita** a una persona de la empresa ni se cambia quién es responsable desde la pantalla. Desactivar la cuenta se hace en Usuarios.
- **Sin extender ni renovar** una licencia vencida, ni pasar una licencia de un curso a otro.
- La entrega sin pago es todo o nada por orden; no hay límite de crédito por empresa ni pagos parciales (Finanzas, S6).
- Cancelar una orden cuyas licencias ya se usaron deja esas matrículas activas: hay que cancelarlas a mano si corresponde.
- El Responsable ve sus compras, pero **no comprobantes ni facturas** (no existen todavía; decisión 8).
- La vista de la cuenta carga todas las licencias de la empresa de una vez; con miles habrá que paginar.
- Los reportes todavía no separan ventas a empresas ni muestran licencias.
