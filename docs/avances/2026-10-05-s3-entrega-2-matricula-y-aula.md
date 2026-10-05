# S3 — Académico · Entrega 2: matrícula mínima y aula del alumno

Fecha: 2026-10-05 · Sin commit al momento de escribir este documento

Segunda de las tres entregas de S3. Adelanta lo mínimo de matrículas (el resto es S4) para que un alumno pueda entrar a un curso.

## Qué se construyó

- **Matrícula manual:** en el editor del curso hay una pestaña nueva, **Alumnos**. Desde ahí el personal matricula a uno o varios alumnos a la vez, ve quién está matriculado y cancela matrículas.
  - Solo se matricula en cursos **publicados**.
  - Solo son matriculables los usuarios activos con acceso al portal del alumno (rol Alumno o uno propio con ese permiso).
  - Un alumno no puede tener dos matrículas activas en el mismo curso (RN-004); matricular de nuevo a quien ya está no duplica nada.
  - Cancelar quita el acceso de inmediato, deja la matrícula en el historial y permite volver a matricular.
- **Mis cursos:** módulo nuevo para el alumno, con las tarjetas de los cursos en los que está matriculado.
- **Aula:** al entrar a un curso el alumno ve el temario a un lado y el contenido elegido al otro, con botones Anterior y Siguiente.
  - Video: se reproduce ahí mismo.
  - Lección de texto: se lee ahí mismo.
  - Enlace: botón para abrirlo en otra pestaña.
  - Documento: botón de descarga.
  - Actividades: se muestra su ficha (preguntas, nota para aprobar, intentos, qué se entrega), con el aviso de que aún no se pueden rendir.
- **El alumno ve siempre la versión publicada**, nunca el borrador. Lo que se edita no le llega hasta que se publique de nuevo.
- **Las preguntas no viajan al aula:** de un cuestionario solo llega la ficha; ni los enunciados ni las respuestas.
- **Materiales:** un documento solo lo descarga quien tiene matrícula activa, y solo si pertenece a la versión publicada.
- **Curso archivado:** no admite matrículas nuevas, pero quienes ya estaban matriculados conservan su acceso.
- **Auditoría:** matrícula y cancelación.

## Cómo se usa

| Pantalla | Dirección | Permiso |
| --- | --- | --- |
| Alumnos del curso | Editor del curso › Alumnos | `enrollments.read`; matricular, `enrollments.create`; cancelar, `enrollments.cancel` |
| Mis cursos | `/my-courses` | `enrollments.self.read` |
| Aula | `/my-courses/{id}` | `enrollments.self.read` y matrícula activa |

## Endpoints nuevos

Todos bajo `/api/v1`.

| Método y ruta | Para qué |
| --- | --- |
| `GET /courses/{id}/enrollments` | Matrículas del curso |
| `GET /courses/{id}/enrollment-candidates` | Alumnos que se pueden matricular |
| `POST /courses/{id}/enrollments` (`student_ids`) | Matricular |
| `DELETE /courses/{id}/enrollments/{matrícula}` | Cancelar |
| `GET /me/courses` | Cursos del alumno (ruta fijada por el Maestro) |
| `GET /me/courses/{id}` | Aula: versión publicada del curso |
| `GET /me/courses/{id}/resources/{recurso}/download` | Descarga de un material |

## Cambios fuera del módulo

- **Permisos nuevos:** `enrollments.read` y `enrollments.cancel`. Ningún rol base los recibe salvo el Administrador (que tiene acceso total); se pueden dar a roles propios.
- **Código de error nuevo:** `ENROLLMENT_NOT_ACTIVE`.
- **Menú:** "Mis cursos" en el grupo General.

## Verificación

- 8 pruebas nuevas (122 en total, todas pasan): matrícula sin duplicados, solo en cursos publicados, el alumno ve únicamente la versión publicada y sin preguntas, descarga de materiales, cancelación y nueva matrícula, acceso tras archivar, permisos y aislamiento entre academias (incluido el mismo correo en dos academias).
- En el navegador: matricular al alumno demo desde el curso de ejemplo; entrar con su cuenta, ver "Mis cursos", abrir el aula, reproducir el video y recorrer recursos y actividades. Revisado a 375 px sin desbordes.
- Estilo de PHP, tipos, lint y build del frontend pasan.
- No se probó en el navegador la descarga de un documento como alumno ni la cancelación; ambas están cubiertas por las pruebas automáticas.

## Cambios que afectan a la instalación

- Migración nueva: tabla `enrollments`.
- Ejecutar `php artisan access:sync` (como en cada despliegue).
- Sin dependencias ni variables nuevas.

## Decisiones tomadas

- **Vigencia de la matrícula:** no se implementa; el Maestro la deja pendiente (PD-005). Hoy una matrícula dura hasta que se cancela.
- **Curso archivado:** los alumnos ya matriculados conservan el acceso. Es una interpretación propia de "dejar de ofrecer el curso"; si se prefiere que pierdan el acceso, es un cambio pequeño.
- **Versión:** todos los alumnos ven la última versión publicada.

## Pendiente

- Entrega 3 de S3: rendir cuestionarios, entregar y revisar tareas, responder encuestas y calcular el progreso.
- Que el profesor vea a los alumnos de sus cursos (hoy la lista exige `enrollments.read`).
- Matricular muchos alumnos desde un archivo.
- Avisar al alumno por correo cuando se le matricula (depende del proveedor de correo).
- Lo propio de S4: portal completo, cursos disponibles, reglas de elegibilidad y vigencia.

En la academia demo local, el alumno de prueba quedó matriculado en el curso de ejemplo.
