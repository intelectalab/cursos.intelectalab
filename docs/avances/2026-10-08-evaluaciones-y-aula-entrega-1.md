# Evaluaciones y aula · Entrega 1: plazo y devolución de tareas, intentos adicionales y detalle del intento

Fecha: 2026-10-08 · Sin commit al momento de escribir este documento

Primer bloque que sale del sprint 2 del backlog YC ([verificación](../verificacion-backlog.md)). El bloque "Evaluaciones y aula" se acordó en tres entregas; esta es la primera y cierra tres historias que estaban a medias: **YC-EVA-005** (intentos adicionales y revisión del intento), **YC-EVA-006** (entrega a tiempo o tardía) y **YC-EVA-007** (calificar y devolver tareas).

Quedan para las siguientes entregas: la nota final del curso con el libro de notas (entrega 2) y el avance secuencial con la vista previa del aula (entrega 3).

## Qué se construyó

### Plazo de las tareas

- Al crear o editar una tarea se puede poner un **plazo en días**. Se cuenta desde que empieza la matrícula de cada alumno, porque los cursos son grabados y cada quien empieza en una fecha distinta. Sin plazo, la tarea queda como hasta ahora.
- Con plazo, quien arma el curso elige qué pasa al vencer:
  - **Recibir entregas fuera de plazo** (opción por defecto): la entrega se acepta y queda marcada como **tardía**.
  - **No recibirlas**: pasado el plazo, quien no entregó ya no puede hacerlo.
- El alumno ve su fecha límite dentro de la tarea ("Entrega hasta el…") y, si ya venció, un aviso que le dice si todavía puede entregar.
- Quien revisa ve la marca "fuera de plazo" en la lista de entregas, al calificar y en la tabla de notas.
- Como el resto del contenido, el plazo empieza a valer cuando se **publica** el curso.

### Devolver una tarea

- Al revisar una entrega hay un botón nuevo, **"Devolver para rehacer"**. Exige escribir un comentario con lo que el alumno debe corregir.
- La entrega devuelta **pierde la nota** (si la tenía) y el alumno puede entregarla otra vez. Ve el aviso "Tu profesor te devolvió la tarea" con el comentario.
- Una entrega devuelta se puede rehacer **aunque el plazo haya pasado**, y conserva la marca que tenía (a tiempo o tardía).
- Mientras está devuelta no se puede calificar: hay que esperar la nueva entrega.
- Antes, una tarea calificada quedaba cerrada para siempre; ahora quien revisa tiene esta salida.

### Intentos de un alumno en un cuestionario

- En **Seguimiento → Avance y notas**, la nota de un cuestionario ahora se puede tocar. Abre una ventana con **cada intento del alumno**: nota, fecha y, al desplegarlo, lo que respondió en cada pregunta, su puntaje y la respuesta correcta.
- Desde esa misma ventana, **"Dar intento adicional"**: se indica cuántos (1 a 10) y el motivo. Se suman a los del cuestionario solo para ese alumno. El motivo queda a la vista en la ventana y en la auditoría.
- El alumno ve los intentos que le quedan ya con el adicional, y el aviso "Tu profesor te dio un intento adicional".
- En un cuestionario de intentos ilimitados no hay nada que dar: el botón no aparece.

## Quién puede hacerlo

Lo mismo que ya permitía revisar entregas: quien tiene el permiso de revisar en todos los cursos (Administrador) y el Profesor en los cursos donde está asignado. **No hay permisos nuevos.**

## Detalle técnico

- Una migración (`2026_10_27_000100_add_deadlines_returns_and_attempt_grants`): columnas `tasks.due_days` y `tasks.accept_late`; `task_submissions.is_late`, `returned_at` y `returned_by`; y la tabla nueva `quiz_attempt_grants` (con aislamiento por academia).
- El plazo viaja en la versión publicada del curso, junto con el resto de la tarea. Las versiones publicadas antes de este cambio no lo traen y se leen como "sin plazo".
- Estado nuevo de la entrega: `RETURNED`. Código de error nuevo: `SUBMISSION_LATE` (409).
- Rutas nuevas, todas bajo `/courses/{course}` y con el permiso de revisar:
  - `POST /submissions/{submission}/return`
  - `GET /enrollments/{enrollment}/activities/{activity}/attempts`
  - `POST /enrollments/{enrollment}/activities/{activity}/extra-attempts`
- La situación del alumno en una tarea (`GET /me/courses/{course}/activities`) ahora trae `due_at`, `past_due` y `can_submit`; la de un cuestionario, `extra_attempts`.
- Auditoría: `submissions.returned` y `attempts.granted`.
- Sin dependencias nuevas ni variables de entorno.

## Qué se verificó

- **263 pruebas del backend pasan** (eran 260), incluidas las que revisan que toda ruta exija sesión y permiso y que toda tabla con datos de academias tenga el aislamiento forzado.
- Pruebas nuevas (3): plazo que marca tardía o rechaza según la tarea; devolver, rehacer y volver a calificar; ver los intentos de un alumno, darle uno adicional y que solo recupere ese, sin que un alumno ni un profesor de otro curso lleguen a esas rutas.
- La aplicación web compila, y pasa la revisión de tipos y de estilo.
- En el navegador, con la academia demo y el curso "Excel para finanzas":
  - Como administrador: se abrió el detalle de intentos de un alumno, se desplegaron sus respuestas (con la correcta en la pregunta fallada) y se le dio un intento adicional con motivo; el contador pasó de "2 de 3" a "2 de 4 intentos usados".
  - Se devolvió una tarea ya calificada: pasó a "Devuelta" en la lista y perdió la nota.
  - Como alumno: la tarea mostró el aviso de devolución con el comentario y el botón "Volver a entregar"; el cuestionario mostró "Te quedan 2 intentos. Tu profesor te dio un intento adicional".

## Qué no se probó

- En el navegador no se probó el formulario del plazo ni el aviso de fecha límite del alumno: el plazo está cubierto por las pruebas automáticas, no por un recorrido en pantalla.
- Los 5 recorridos de Playwright no se corrieron en esta entrega.
- La vista en teléfono de la ventana de intentos.

## Qué quedó corto

- El plazo es **relativo** (días desde la matrícula), no una fecha de calendario. Sirve para cursos grabados; un curso con fechas fijas para todo el grupo necesitaría además una fecha límite común.
- No hay **prórroga por alumno**: el plazo es el mismo para todos. La salida hoy es devolverle la tarea o usar una tarea que reciba tardías.
- La tardanza **no descuenta nota**: solo queda marcada, y quien califica decide.
- Devolver borra la nota anterior y **no guarda el historial** de entregas: se conserva solo la última versión y el último comentario (en la auditoría sí queda que hubo una devolución).
- No se avisa al alumno por correo de la devolución ni del intento adicional (depende de la decisión 3).
- Un intento vencido por tiempo sigue con nota 0 aunque hubiera respuestas guardadas (YC-EVA-004 sigue a medias): falta la decisión.
- Los intentos adicionales no se pueden retirar una vez dados.

## Qué se decidió sin preguntar

1. **El plazo se cuenta en días desde el inicio de la matrícula**, no como fecha fija. Motivo: los cursos son grabados y cada alumno empieza cuando se matricula.
2. **Por defecto las tareas con plazo reciben entregas tardías**, marcadas; rechazarlas es una opción de cada tarea.
3. **Devolver exige comentario y borra la nota.** Una tarea devuelta se puede rehacer aunque el plazo haya vencido.
4. **Los intentos adicionales los da quien puede revisar** (incluido el profesor del curso), con motivo obligatorio y tope de 10 por vez. No pasa por una aprobación.
5. **Quien revisa ve siempre las respuestas correctas** en el detalle de un intento, aunque el alumno todavía no pueda verlas.

Las cinco se pueden cambiar.

Los datos de la prueba en el navegador quedaron en la demo local: el alumno de prueba tiene un intento adicional en "Evaluación del módulo 1" y su tarea "Presupuesto mensual" está devuelta.
