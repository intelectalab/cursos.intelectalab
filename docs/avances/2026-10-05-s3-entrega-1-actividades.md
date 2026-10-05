# S3 — Académico · Entrega 1: armado de actividades

Fecha: 2026-10-05 · Sin commit al momento de escribir este documento

S3 se hace en tres entregas, porque el Documento Maestro pide que termine con una prueba académica de punta a punta y eso exige alumnos matriculados (previstos recién en S4):

1. **Armado** (este documento): banco de preguntas y actividades dentro de los cursos.
2. **Matrícula mínima y aula:** el administrador inscribe a un alumno y este ve el curso publicado.
3. **Rendir y progreso:** intentos de cuestionario, entregas de tareas con revisión del profesor, encuestas y avance.

## Qué se construyó

- **Banco de preguntas** por academia: bancos con nombre (por ejemplo «Excel básico»), con sus preguntas, búsqueda y filtro por tipo. Una pregunta que se usa en un cuestionario no se puede borrar, ni su banco.
- **Siete tipos de pregunta**, todos con calificación automática:

  | Tipo | Cómo se califica |
  | --- | --- |
  | Opción única | Acierta o no |
  | Opción múltiple | Parcial: cada acierto suma y cada opción incorrecta marcada resta (marcar todo da cero) |
  | Verdadero o falso | Acierta o no |
  | Respuesta corta | Coincide con alguna de las respuestas aceptadas, sin importar mayúsculas, tildes ni espacios |
  | Numérica | Dentro del margen aceptado; admite coma decimal |
  | Emparejar | Parcial: cada par acertado suma |
  | Ordenar | Parcial: cada elemento en su posición suma |

- **Actividades dentro de las secciones del curso:**
  - **Cuestionario:** preguntas elegidas del banco, con orden y puntos por pregunta; nota mínima para aprobar, máximo de intentos (3 por defecto, o ilimitados), tiempo límite opcional y orden aleatorio opcional.
  - **Tarea:** consigna obligatoria y qué puede entregar el alumno (texto, archivo o ambos).
  - **Encuesta:** preguntas de escala del 1 al 5, de elegir una opción o de texto libre; obligatorias u opcionales.
- **Escala de notas** por academia: de 0 a 20 (por defecto) o de 0 a 100, en Configuración › Calificaciones. Las notas se guardan en porcentaje y solo cambia cómo se muestran.
- **Versiones:** las actividades viajan en la versión publicada con una copia de sus preguntas. Si luego se edita una pregunta en el banco, el curso queda con "cambios sin publicar" y la versión vigente conserva la pregunta original.
- **Publicación:** un curso ya se puede publicar solo con actividades. Un cuestionario o una encuesta sin preguntas impide publicar, y el editor lo avisa en la propia actividad.
- **Motor de calificación** (`QuestionGrader`) y vista sin respuestas para el alumno (`QuestionConfig::forStudent`): ya escritos y probados; los usará la entrega 3.

## Cómo se usa

| Pantalla | Dirección | Permiso |
| --- | --- | --- |
| Banco de preguntas | `/question-banks` | `question_banks.read`; para editar, `question_banks.manage` |
| Actividades | Editor del curso › Contenido, bajo los recursos de cada sección | `courses.content.write`, o `activities.assigned.manage` en los cursos asignados |
| Escala de notas | Configuración › Calificaciones | `tenant_settings.update` |

Flujo de un cuestionario: crear las preguntas en el banco → en el curso, "Agregar actividad" › Cuestionario → botón "Elegir preguntas".

## Endpoints nuevos

Todos bajo `/api/v1`.

| Método y ruta | Para qué |
| --- | --- |
| `GET`/`POST /question-banks`, `PUT`/`DELETE /question-banks/{banco}` | Bancos |
| `GET`/`POST /question-banks/{banco}/questions`, `PUT`/`DELETE /questions/{pregunta}` | Preguntas |
| `GET /courses/{id}/activities` | Actividades del curso y escala de notas |
| `POST /courses/{id}/sections/{sección}/activities`, `PUT`/`DELETE /courses/{id}/activities/{actividad}` | Alta, edición y baja |
| `PUT /courses/{id}/sections/{sección}/activities/order` | Orden dentro de la sección |
| `PUT /courses/{id}/activities/{actividad}/questions` | Preguntas de un cuestionario, con orden y puntos |

## Cambios fuera del módulo

- **Permisos nuevos:** `question_banks.read` y `question_banks.manage`. El rol base Profesor los recibe, además de lo que fija el Maestro, porque los necesita para armar los cuestionarios de sus cursos.
- **Cursos:** otros módulos aportan contenido a la versión publicada mediante `CourseContentExtension` (registrada en `AppServiceProvider`); así Cursos no depende de Académico. SCORM usará el mismo mecanismo.
- **Configuración:** nuevo dato `grade_scale`.
- **Código de error nuevo:** `QUESTION_IN_USE`.
- **Menú:** nuevo módulo "Banco de preguntas".

## Verificación

- 37 pruebas nuevas (114 en total, todas pasan): 28 casos de calificación de los siete tipos y 9 de armado (bancos, validación por tipo, que el alumno no reciba las respuestas, cuestionarios, tareas y encuestas, copia congelada en la versión, alcance del profesor y aislamiento entre academias).
- En el navegador: crear un banco y una pregunta de cada tipo; en el curso de ejemplo, agregar un cuestionario con cuatro preguntas, una tarea y una encuesta, y publicar la versión 2 con todo ello.
- Estilo de PHP, tipos, lint y build del frontend pasan.
- No se probó en el navegador con un usuario Profesor ni en tamaño de teléfono.

## Cambios que afectan a la instalación

- Migración nueva: tablas `question_banks`, `questions`, `course_activities`, `quizzes`, `quiz_questions`, `tasks` y `surveys`.
- Ejecutar `php artisan access:sync` (como en cada despliegue): agrega los dos permisos y actualiza el rol Profesor.
- Sin dependencias ni variables nuevas.

## Decisiones tomadas

- S3 en tres entregas, adelantando una matrícula mínima.
- Escala de notas de 0 a 20, configurable por academia.
- Siete tipos de pregunta de calificación automática (el usuario pidió más de los tres iniciales).
- Bancos de preguntas por academia.
- Tareas sin fecha límite por ahora; encuestas con respuestas asociadas al alumno y resultados agregados.

## Pendiente

- Entregas 2 y 3 de S3: hoy las actividades se arman y se publican, pero ningún alumno puede rendirlas todavía.
- Pregunta de respuesta abierta (requiere revisión manual).
- Tomar preguntas al azar de un banco; hoy el cuestionario tiene una lista fija.
- Las actividades se listan después de los recursos de su sección; no se pueden intercalar.
- Importar preguntas desde un archivo y mover preguntas entre bancos.
- Imágenes en los enunciados.
- El archivo principal del frontend supera los 500 kB; conviene dividirlo por pantallas más adelante.

En la academia demo local quedaron el banco «Excel básico» con siete preguntas y las actividades del curso de ejemplo, creados durante la prueba.
