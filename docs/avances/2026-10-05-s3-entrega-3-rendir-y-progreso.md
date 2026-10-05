# S3 — Académico · Entrega 3: rendir actividades y progreso

Fecha: 2026-10-05 · Sin commit al momento de escribir este documento

Tercera y última entrega de S3. Con ella se cumple el criterio de salida del sprint: una prueba académica de punta a punta (un alumno matriculado rinde un cuestionario, entrega una tarea, responde una encuesta y su avance se calcula solo).

## Qué se construyó

### Para el alumno, en el aula

- **Cuestionarios:** comienza un intento, responde los siete tipos de pregunta y envía. La nota se calcula en el servidor; lo que mande el navegador no la cambia (RN-006).
  - Respeta el máximo de intentos y cuenta el mejor.
  - Con tiempo límite, muestra una cuenta regresiva y envía solo al llegar a cero. El servidor rechaza un envío fuera de tiempo; un intento vencido cuenta como usado, con nota cero.
  - Tras enviar muestra la nota, qué preguntas acertó y su respuesta a cada una. **Las respuestas correctas solo se muestran cuando ya aprobó o no le quedan intentos**, para que no sirvan en el siguiente intento.
  - Guarda el historial de intentos y permite revisar cada uno.
- **Tareas:** entrega un texto, un archivo o ambos, según lo que pida la tarea (PDF, Word, Excel, PowerPoint, imagen o ZIP, hasta 50 MB). Mientras no esté calificada puede reemplazarla; una vez calificada ve su nota y el comentario del profesor.
- **Encuestas:** responde una sola vez. Se le avisa que sus respuestas se muestran agrupadas y sin su nombre.
- **Avance:** barra con el porcentaje en el aula y en "Mis cursos", y una marca en cada elemento completado. Todo pesa igual: un recurso cuenta al abrirlo, un cuestionario al aprobarlo, una tarea al entregarla y una encuesta al responderla (RN-005).

### Para quien revisa, en el editor del curso

Pestaña nueva **Seguimiento**:

- **Entregas de tareas:** primero las que faltan revisar. Se abre la entrega, se descarga el archivo y se pone nota (en la escala de la academia) y comentario. La nota se puede corregir después.
- **Avance y notas:** tabla con cada alumno, su avance y su resultado en cada actividad.
- **Encuestas:** resultados agrupados por pregunta (promedio y distribución, o la lista de textos), sin nombres.

La ve quien tiene el permiso de revisar todos los cursos y el profesor en los cursos donde está asignado.

### Reglas que conviene conocer

- **Versiones:** un intento se califica con las preguntas con las que se abrió, aunque el curso se publique de nuevo a mitad del intento. Las notas y entregas se conservan si la actividad se edita o se retira después.
- **Avance y versiones nuevas:** si se publica contenido nuevo, el avance baja, porque hay más por hacer. Lo ya completado sigue contando mientras siga en el curso.
- **Privacidad de los intentos:** un alumno no puede ver ni enviar el intento de otro.

## Cómo se usa

| Quién | Dónde | Permiso |
| --- | --- | --- |
| Alumno | Mis cursos › curso › actividad | `activities.self` para ver, `activities.attempt` para rendir |
| Quien revisa | Editor del curso › Seguimiento | `submissions.review` (todos los cursos) o `submissions.assigned.review` (los asignados) |

## Endpoints nuevos

Todos bajo `/api/v1`.

| Método y ruta | Para qué |
| --- | --- |
| `GET /me/courses/{id}/activities` | Situación del alumno en cada actividad |
| `GET /me/courses/{id}/activities/{actividad}` | Detalle (preguntas de la encuesta, entrega de la tarea) |
| `POST /me/courses/{id}/activities/{actividad}/attempts` | Abrir o retomar un intento |
| `POST /me/attempts/{intento}/submit`, `GET /me/attempts/{intento}` | Enviar y revisar un intento |
| `POST /me/courses/{id}/activities/{actividad}/submission` | Entregar o reemplazar una tarea |
| `GET /me/courses/{id}/activities/{actividad}/submission/download` | Descargar la propia entrega |
| `POST /me/courses/{id}/activities/{actividad}/survey` | Responder una encuesta |
| `POST /me/courses/{id}/resources/{recurso}/complete` | Marcar un recurso como visto |
| `GET /courses/{id}/tracking` | Avance y notas de los alumnos |
| `GET /courses/{id}/submissions`, `POST …/{entrega}/grade`, `GET …/{entrega}/download` | Revisión de tareas |
| `GET /courses/{id}/activities/{actividad}/survey-results` | Resultados de una encuesta |

El Maestro nombra `POST /activities/{id}/attempts`; aquí la ruta va dentro del curso del alumno (`/me/courses/{id}/…`) porque la actividad se lee de la versión publicada de su matrícula.

## Cambios fuera del módulo

- **Permiso nuevo:** `submissions.review` (revisar en todos los cursos). El Profesor ya tenía `submissions.assigned.review`.
- **Uso de archivo nuevo:** `task_submission` (privado, hasta 50 MB).
- **Códigos de error nuevos:** `NO_ATTEMPTS_LEFT`, `ATTEMPT_CLOSED`, `SUBMISSION_GRADED` y `SURVEY_ALREADY_ANSWERED`.
- **Menú:** se retiró el módulo "Académico" (era un aviso de "en construcción"); su contenido quedó en el aula y en la pestaña Seguimiento.
- `GET /me/courses` y `GET /me/courses/{id}` devuelven ahora el avance.

## Verificación

- 9 pruebas nuevas (131 en total, todas pasan): rendir y calificar sin filtrar respuestas, mejor intento y límite de intentos, tiempo límite, que un intento sea solo de su alumno, entrega, reemplazo y calificación de tareas, encuesta única con resultados agrupados, avance calculado en el servidor, seguimiento con alcance del profesor, y conservación de resultados al cambiar el curso con aislamiento entre academias.
- En el navegador, con la cuenta del alumno demo: rendir el cuestionario (15 de 20, aprobado, con el detalle por pregunta), entregar la tarea con texto y un PDF, responder la encuesta y ver el avance subir a 57 %. Con el administrador: ver el seguimiento, calificar la tarea con 17 y abrir los resultados de la encuesta.
- Estilo de PHP, tipos, lint y build del frontend pasan.
- No se probó en el navegador: el tiempo límite con cuenta regresiva, agotar los intentos, la vista de un Profesor, ni el tamaño de teléfono para las pantallas nuevas. Las reglas están cubiertas por las pruebas automáticas; el aspecto en teléfono, no.

## Cambios que afectan a la instalación

- Migraciones nuevas: `enrollment_progress`, `quiz_attempts`, `quiz_answers`, `task_submissions` y `survey_responses`.
- Ejecutar `php artisan access:sync` (como en cada despliegue).
- Los alumnos suben archivos de hasta 50 MB: aplican los límites de subida de PHP y del servidor web (guía de instalación, sección 7), y sus entregas se guardan en la misma carpeta de archivos que hay que respaldar.
- Sin dependencias ni variables nuevas.

## Decisiones tomadas

- Cuenta el mejor intento; un intento vencido vale cero y cuenta como usado.
- Las respuestas correctas se revelan solo al aprobar o al agotar los intentos.
- Margen de 60 segundos tras el tiempo límite para que llegue el envío automático.
- Una tarea calificada ya no se reemplaza; quien revisa sí puede corregir la nota.
- La encuesta se responde una sola vez y no se puede editar.

## Pendiente

- Al retomar un intento abierto (por ejemplo, si se cierra el navegador) no se recuperan las respuestas ya marcadas: el intento sigue, pero hay que responder de nuevo.
- Que quien revisa vea el detalle de cada intento de un alumno; hoy ve solo la mejor nota.
- Nota final del curso (promedio ponderado) y exportar las notas.
- Pregunta de respuesta abierta, con revisión manual.
- Avisos por correo (tarea calificada, nueva actividad); depende del proveedor de correo.
- Fechas límite para las tareas; depende de la vigencia de la matrícula.
- Revisar las pantallas nuevas en teléfono.
