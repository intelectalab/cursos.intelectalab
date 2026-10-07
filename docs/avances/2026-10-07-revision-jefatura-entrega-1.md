# Revisión de jefatura · Entrega 1: participantes, selección múltiple y tarjetas que filtran

Fecha: 2026-10-07 · Sin commit al momento de escribir este documento (S12 tampoco estaba commiteado)

Jefatura revisó el sistema y pidió doce cambios. Se acordó hacerlos en tres entregas. Esta es la primera y cubre cinco puntos de la lista: **seleccionar todo** (1), **tarjetas con cifras que filtran** (5), **hacerlo más visual** (6), **qué significa cada filtro** (7) y **alumnos y docentes como participantes** (12). El estado de los doce puntos está en [pendientes](../pendientes.md), sección 4 bis.

## Qué se construyó

### Participantes: alumnos y docentes en un mismo lugar

- En cada curso, las pestañas "Profesores" y "Alumnos" se unieron en una sola: **Participantes**.
- Un solo botón, **Agregar participantes**, abre una ventana donde se elige a las personas y su **papel en ese curso**: Alumno o Docente.
- **La misma persona puede ser docente en un curso y alumna en otro.** No puede ser las dos cosas en el mismo curso: el sistema lo impide y dice a quién afecta.
- La lista de personas ya no depende del rol que tengan en la academia: aparece cualquier usuario activo que aún no participe en el curso. Si a alguien le falta el rol que su papel necesita (Alumno o Profesor), **el sistema se lo suma solo** y lo deja anotado en Auditoría.
- Un docente se puede asignar antes de publicar el curso; un alumno, solo cuando ya está publicado (como antes).
- Quitar a un docente del curso no le quita su cuenta ni sus otros cursos.

### Tarjetas con cifras que filtran

Encima de cada listado hay una fila de tarjetas, una por estado, con su cifra y una barra que muestra qué parte del total representa. **Al tocar una tarjeta, la tabla se filtra por ese estado** y la tarjeta queda resaltada con el aviso "Filtrando"; tocarla de nuevo quita el filtro. Las cifras se recalculan con la búsqueda y los demás filtros.

| Pantalla | Tarjetas |
| --- | --- |
| Matrículas | Todas, Con acceso hoy, Por vencer, Vencidas, Completadas, Canceladas |
| Participantes de un curso | Alumnos, Con acceso hoy, Por vencer, Completaron |
| Usuarios | Todos, Activos, Inactivos |
| Cursos | Todos, Borradores, Publicados, Archivados |
| Ventas y pagos | Todas, Pendientes de pago, Pagadas, Vencidas, Canceladas |
| Certificados | Todos, Vigentes, Anulados |
| CRM · Prospectos | Todos, Nuevos, Contactados, Convertidos, Descartados |
| CRM · Empresas | Todas, Prospectos, Clientes |
| CRM · Cotizaciones | Todas, Borradores, Enviadas, Aceptadas, Rechazadas, Vencidas |

"Por vencer" es nuevo: matrículas con acceso hoy que terminan en los próximos 7 días.

### Qué significa cada filtro

- Cada tarjeta tiene un ícono de ayuda (?) que explica en una frase qué cuenta. Ejemplo: "Vencidas: su fecha de fin ya pasó; no pueden entrar, pero conservan su avance y sus notas".
- Los filtros desplegables de esas pantallas tienen el mismo ícono. En los de varios estados, la explicación cambia según el estado elegido.
- La ayuda se abre con el cursor, con el teclado o al tocarla.

### Seleccionar todo y acciones sobre varias filas

**"Seleccionar todos"** en todas las listas con casillas: agregar participantes, matricular, asignar licencias, emitir certificados pendientes, cursos de un paquete y cursos o paquetes de una promoción. (La matriz de permisos de un rol ya lo tenía por módulo.)

**Casillas por fila y acciones en bloque** en tres tablas. Al marcar filas aparece una barra con lo que se puede hacer con todas:

| Tabla | Acciones |
| --- | --- |
| Usuarios | Activar, Desactivar |
| Matrículas (general y dentro de un curso) | Cambiar vigencia, Cancelar matrículas |
| Certificados pendientes | Emitir los elegidos |

Lo que no se puede aplicar se omite y se informa: por ejemplo, al desactivar varias cuentas no se desactiva la propia ni la del último administrador, y el aviso dice cuáles y por qué.

## Detalle técnico

- Los listados de usuarios, cursos, órdenes, certificados, prospectos, empresas, cotizaciones y matrículas devuelven `meta.counts` (clave `ALL` más una por estado). Pieza común: `App\Shared\Http\StateCounts`.
- Filtro nuevo `state=EXPIRING` en matrículas (`Enrollment::FILTER_EXPIRING`, 7 días).
- Participantes: `ParticipantService` y `ParticipantController` (módulo `Enrollments`). Rutas `GET /courses/{id}/participant-candidates`, `POST /courses/{id}/participants` (`role`: `STUDENT` o `TEACHER`) y `DELETE /courses/{id}/teachers/{user}`. Las tablas no cambiaron: los alumnos siguen en `enrollments` y los docentes en `course_teachers`.
- `RoleService::grantBaseRole()` suma un rol base sin tocar los demás.
- En bloque: `POST /users/bulk-status`, `POST /enrollments/bulk-cancel`, `POST /enrollments/bulk-validity`, `POST /certificates/issue-many` (máximo 200 por petición).
- Frontend: `shared/ui/filters.tsx` (`FilterCards`, `HelpTip`, `SelectAll`, `SelectRow`, `BulkBar`); `ParticipantsTab` reemplaza a las pestañas de profesores y alumnos.
- Sin migraciones, sin permisos nuevos y sin dependencias nuevas. Nada que cambiar en el servidor.

## Qué se verificó

- **249 pruebas del backend pasan** (eran 243). Nuevas: la misma persona como docente en un curso y alumna en otro; nadie con dos papeles en el mismo curso; permisos por papel y aislamiento entre academias; cancelar y cambiar vigencia de varias matrículas; desactivar varias cuentas respetando las excepciones; emitir varios certificados pendientes; y los conteos por estado.
- De paso se corrigió una prueba de rendimiento que fallaba de vez en cuando por depender del reloj.
- **Los 5 recorridos de Playwright siguen pasando.**
- En el navegador, con la academia demo: las tarjetas muestran cifras reales en las nueve pantallas; tocar "Completadas" deja 6 de 12 matrículas y sincroniza el desplegable; "seleccionar todo" abre la barra de acciones; se agregó y se quitó a una persona como docente desde la ventana de participantes; la ayuda abre con un clic. A 375 px de ancho ninguna pantalla se desborda (las tarjetas pasan a dos columnas).

## Qué no se probó

- Las acciones en bloque se probaron por la API y se vio su barra en pantalla, pero no se ejecutó cada una desde el navegador (habría cancelado matrículas y desactivado cuentas de la demo).
- "Seleccionar todos" en promociones, paquetes y licencias: revisado en el código y compila, sin clic en pantalla.
- La ayuda con lector de pantalla real.

## Qué quedó corto

- La selección es por página: "seleccionar todo" marca las filas visibles (hasta 50), no todos los resultados de la búsqueda.
- En bloque solo hay acciones en Usuarios, Matrículas y Certificados. En Cursos, Ventas y CRM hay tarjetas pero no acciones sobre varias filas.
- Las tarjetas están en nueve pantallas. Faltan Soporte y Reclamos (tienen cifras, pero no filtran al tocarlas), Promociones, Páginas, Banco de preguntas y Auditoría.
- "Por vencer" son 7 días fijos; no se configura por academia.
- Los otros roles del curso que tiene Moodle (profesor sin permiso de edición, gestor) no existen: solo Alumno y Docente.
- El menú del alumno sigue igual: "Mis cursos" muestra los que lleva; los que dicta los ve en "Cursos".

## Qué se decidió sin preguntar

1. **Al dar un papel en un curso, el sistema suma el rol base que falte** (Alumno o Profesor). Sin eso la persona no podría entrar. Lo puede hacer quien tenga permiso para matricular o para editar cursos, aunque no tenga permiso para editar usuarios; queda en Auditoría.
2. **No se rehízo la base de datos**: alumnos y docentes siguen guardándose donde estaban y lo unificado es la pantalla y la forma de agregarlos. Si más adelante se quieren más papeles por curso, ahí sí convendría una sola tabla de participantes.
3. En la pantalla general de Matrículas la ventana sigue matriculando solo alumnos; los docentes se agregan desde el curso.
4. Al desactivar varias cuentas, la propia se omite siempre.
