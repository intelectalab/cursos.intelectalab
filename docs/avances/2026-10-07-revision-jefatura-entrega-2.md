# Revisión de jefatura · Entrega 2: grupos e importaciones con plantilla

Fecha: 2026-10-07 · Sin commit al momento de escribir este documento

Segunda de las tres entregas de la revisión de jefatura. Cubre tres puntos de su lista: **importar con plantillas** (2), **importar usuarios y matricularlos a la vez** (3) y **matricular por grupos** (4).

## Qué se construyó

### Importar desde Excel, en tres pasos

La misma ventana sirve para todas las importaciones:

1. **Descargar la plantilla:** un Excel con las columnas correctas, una fila de ejemplo y una hoja de instrucciones que dice qué va en cada columna y cuáles son obligatorias.
2. **Subir el archivo lleno** (Excel `.xlsx` o CSV, hasta 500 filas).
3. **Revisar antes de confirmar:** el sistema muestra, fila por fila, qué va a pasar. Cada fila queda como "Se importa", "Sin cambios" o "Con error", con el motivo. **Nada se guarda hasta pulsar "Importar".**

Las filas con error no frenan a las demás: se importan las correctas, y las otras se corrigen en el archivo y se vuelven a subir. **Subir dos veces el mismo archivo no duplica nada.**

Hay tres importaciones:

| Dónde | Qué importa |
| --- | --- |
| Usuarios → Importar | Personas, con su matrícula y su grupo |
| Banco de preguntas → (un banco) → Importar | Preguntas de opción única, opción múltiple, verdadero o falso, respuesta corta y numérica |
| CRM → Prospectos → Importar | Prospectos (la lista de un evento o de un formulario externo) |

Detalles que evitan tropiezos:

- Las columnas se reconocen por su título, sin importar el orden, las mayúsculas ni las tildes.
- Un CSV guardado desde Excel se lee igual si usa coma o punto y coma, y aunque no esté en UTF-8 (las tildes y la ñ llegan bien).
- Un DNI escrito como número en Excel se lee completo.

### Importar personas y matricularlas a la vez

Columnas: Nombres, Apellidos, Correo, Tipo de documento, Número de documento, Teléfono, **Curso**, **Grupo** y Fin del acceso.

- **Quien no tiene cuenta, la recibe** como alumno. Entra con su correo y **su número de documento como contraseña temporal**.
- **Quien ya tiene cuenta** (mismo correo) no se toca: solo se matricula y se agrega al grupo.
- Si la columna **Curso** trae el título de un curso publicado, la persona queda matriculada. Con "Fin del acceso" se le pone fecha; vacío, vale lo que defina el curso.
- Si la columna **Grupo** trae un nombre, la persona se suma a ese grupo; si el grupo no existe, se crea.
- Errores que se avisan por fila: correo inválido o repetido en el archivo, documento que no corresponde a su tipo o con menos de 8 caracteres, documento que ya es de otra cuenta, curso que no existe o con título repetido, cuenta desactivada, y persona que ya es docente de ese curso.

### Cambio obligatorio de la contraseña temporal

Como el documento es una contraseña fácil de adivinar, **quien entra con ella no puede usar nada hasta cambiarla**: lo primero que ve es la pantalla "Crea tu contraseña". El bloqueo lo aplica el servidor, no solo la pantalla. Recuperar la contraseña por el enlace de correo también lo resuelve.

### Grupos

Un grupo es **una lista de personas con nombre** ("Promoción 2026-II", "Área de ventas") que se arma una vez y se reutiliza.

- En **Usuarios** hay una pestaña nueva, **Grupos**: crear, renombrar y eliminar grupos; agregar personas (con buscador y "seleccionar todos") y quitarlas.
- En un curso, al **Agregar participantes**, aparecen los **grupos completos** como opciones junto a las personas sueltas. Al elegir uno entran sus miembros activos que aún no participan en el curso; quien ya está (o es docente de ese curso) simplemente no se repite.
- Una persona puede estar en varios grupos. Eliminar un grupo no toca a las personas ni sus matrículas.
- Un grupo sirve también para agregar docentes de una vez.

## Detalle técnico

- **Dependencia nueva del backend:** `openspout/openspout` ^5.12, para leer y escribir Excel y CSV. Usa las extensiones `zip`, `xmlreader` y `xmlwriter` de PHP, que ya estaban en los requisitos.
- Pieza común: `App\Shared\Import\Spreadsheet` (lectura y plantilla) e `Importer` (revisar y guardar). La revisión ejecuta la importación completa dentro de una transacción y la deshace: así muestra exactamente lo que pasará.
- Importadores: `PeopleImporter` (módulo `Enrollments`), `QuestionsImporter` (`Academic`) y `LeadsImporter` (`Crm`). Rutas: `/imports/people`, `/question-banks/{id}/import` y `/crm/leads/import`, cada una con `/template` y `/preview`.
- Permisos (no hay nuevos): personas → `users.create` (y `enrollments.create` si se usa la columna Curso); preguntas → `question_banks.manage`; prospectos → `crm.manage`; ver grupos → `users.read` o `enrollments.create`; administrarlos → `users.update`.
- Tablas nuevas `groups` y `group_members` (con RLS) y columna `users.must_change_password`. Una migración.
- `POST /courses/{id}/participants` acepta `group_ids` además de `user_ids`.
- El middleware `EnsureTenantUser` responde `PASSWORD_CHANGE_REQUIRED` (403) a todo salvo `auth/me`, `auth/password` y `auth/logout` mientras la contraseña sea temporal.

## Qué se verificó

- **257 pruebas del backend pasan** (eran 249). Las nuevas generan archivos Excel reales y comprueban: la plantilla descargada; que la revisión no guarda nada; la importación con matrícula y grupo; que repetir el archivo no duplica; el cambio obligatorio de contraseña de punta a punta; el rechazo de un archivo que no es la plantilla y de uno con otra extensión; los cinco tipos de pregunta; prospectos duplicados; un CSV con punto y coma y codificación de Windows; y los grupos (alta, miembros, matrícula del grupo completo, aislamiento entre academias y permisos).
- **Los 5 recorridos de Playwright siguen pasando.**
- En el navegador, con la academia demo: se subió un archivo de dos filas, la revisión mostró una correcta y una con tres errores, se importó la correcta, apareció el grupo creado con su miembro, la cuenta importada entró con su documento y solo vio la pantalla de cambio de contraseña, y el grupo se matriculó completo en otro curso.

## Qué no se probó

- La descarga de la plantilla desde el navegador (sí por la API: el archivo se abre y trae sus columnas).
- Un archivo de 500 filas: se probó con pocas. La importación corre dentro de la misma petición, sin cola.
- Excel exportado desde Google Sheets o Numbers, y archivos `.xls` antiguos (no se aceptan: solo `.xlsx` y CSV).

## Qué quedó corto

- **No hay importación suelta de "matrículas"**: quedó dentro de la de personas (una fila con el correo de alguien que ya tiene cuenta y la columna Curso lo matricula). Son tres plantillas, no cuatro.
- Cada fila matricula en **un** curso. Para varios cursos se repite la fila o se usa un grupo.
- La importación de personas solo crea alumnos; no asigna otros roles ni docentes.
- No se importan preguntas de emparejar ni de ordenar, ni empresas, cursos o cupones.
- No hay exportación a Excel de los listados (sigue pendiente en Reportes).
- Sin correo (decisión 3), nadie le avisa a la persona importada que tiene una cuenta: la academia debe comunicarle su acceso.
- El grupo es una lista fija: si alguien se suma al grupo después, no queda matriculado solo en los cursos donde el grupo ya entró.
- Los grupos no tienen pantalla propia en el menú; viven como pestaña de Usuarios.

## Qué se decidió sin preguntar

1. **OpenSpout** como librería de Excel (aprobaste "una dependencia"; elegí esta por liviana y sin avisos de seguridad).
2. El bloqueo por contraseña temporal se aplica en el servidor, no solo en la pantalla.
3. El documento debe tener **al menos 8 caracteres** para servir de contraseña temporal; un carné o pasaporte más corto da error en esa fila.
4. Los grupos usan los permisos de usuarios en vez de permisos nuevos, para no tocar los roles de las academias.
5. Al matricular un grupo, quien ya participa en el curso se omite en silencio en vez de dar error.
6. El curso se identifica por su **título exacto**; si hay dos cursos publicados con el mismo título, la fila da error.
