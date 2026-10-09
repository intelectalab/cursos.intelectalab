# Cierre por tiempo que califica lo guardado y nota mínima para aprobar el curso

Fecha: 2026-10-08 · Sin commit al momento de escribir este documento

Dos ajustes pequeños que quedaban a medias en el sprint 2 del backlog YC ([verificación](../verificacion-backlog.md)) y que se aprobaron con un "sí": **YC-EVA-004** (cierre automático del cuestionario al agotarse el tiempo) y **YC-CER-002** (requisitos de certificación), en la parte de la nota mínima.

## Qué se construyó

### El cuestionario que vence califica lo que el alumno dejó guardado

- Antes, si el tiempo se acababa sin que el alumno enviara el intento (por ejemplo, cerró la pestaña), el intento quedaba con **nota cero**, aunque hubiera marcado respuestas.
- Ahora se **califica lo que tenía guardado**. El aula ya guardaba sola lo marcado mientras el alumno respondía; eso es lo que se califica. Si no había nada guardado, la nota sigue siendo cero.
- Si con eso aprueba, el cuestionario cuenta para su avance, como en un envío normal.
- El intento sigue figurando como "Tiempo vencido", pero ahora **tiene detalle**: el alumno y quien revisa ven qué quedó respondido, qué no, y el puntaje de cada pregunta.
- Vale para los intentos que venzan desde ahora. Los que ya vencieron con cero no se recalculan.

### Nota mínima para aprobar el curso

- En **Información** del curso hay un campo nuevo, opcional: **"Nota mínima para aprobar"**, en la escala de la academia (por ejemplo, 13 de 20). Vacío, el curso no exige nota, como hasta ahora.
- Con nota mínima:
  - En **Seguimiento**, junto a la nota final de cada alumno aparece **Aprobado** o **No aprobado**, y el texto indica con cuánto se aprueba.
  - El **alumno** ve "Aprobado" o "Aún no aprobado" junto a su nota del curso, y en "Mis notas" con cuánto se aprueba.
  - Si el curso **entrega certificado**, ya no basta completarlo al 100 %: la nota final también debe alcanzar la mínima.
- El certificado **se emite solo cuando la nota llega**: si el alumno completa el curso con una tarea aún sin calificar, el certificado sale en el momento en que el profesor la califica y la nota alcanza. No hace falta que nadie lo emita a mano.
- Quien no alcanza la nota no aparece entre los certificados "por emitir", y la emisión manual responde con el motivo.
- Como el precio o el certificado, la nota mínima **vale desde que se guarda**, sin publicar.

## Detalle técnico

- Una migración (`2026_10_30_000100_add_passing_percent_to_courses`): columna `courses.passing_percent` (porcentaje, opcional).
- Evento nuevo `GradeRecorded` (módulo Académico), que se emite al calificar una tarea y al cerrarse un intento. Certificados lo escucha (`IssueCertificateOnGrade`) y vuelve a comprobar si ya corresponde el certificado.
- `CertificateService` usa `GradebookService` para la nota final. La lista de "por emitir" calcula las notas un curso a la vez y solo en los cursos que exigen nota mínima.
- `GET /courses/{id}/tracking` trae `meta.passing_percent` y `passed` por alumno; `GET /me/courses/{id}/grades` trae `passing_percent` y `passed`; `GET /courses/options` trae `grade_scale`.
- El cierre de un intento (enviado o vencido) pasa ahora por una sola función, así que se califican igual.
- Sin permisos nuevos, sin dependencias ni variables de entorno.

## Qué se verificó

- **275 pruebas del backend pasan** (eran 273).
- Pruebas nuevas (2):
  - Un intento con tiempo límite al que se le guarda una respuesta y vence sin enviar: queda "Tiempo vencido" con 75 %, aprueba, cuenta para el avance y tiene detalle. La prueba anterior (vencer sin nada guardado da cero) sigue pasando.
  - Un curso con certificado y nota mínima de 60 %: completar el curso sin nota no emite; calificar con 50 % tampoco; no aparece entre los pendientes y la emisión manual se rechaza; al subir la nota a 80 % el certificado se emite solo.
- La aplicación web compila y pasa la revisión de tipos y de estilo.
- En el navegador, con "Excel para finanzas" de la demo: se puso nota mínima 13 desde Información (se guardó como 65 %); Seguimiento mostró "El curso se aprueba con 13" y "No aprobado" junto a cada nota; el alumno de prueba vio "7.5 de 20 · Aún no aprobado". Al terminar se quitó la nota mínima para dejar la demo como estaba.

## Qué no se probó

- En el navegador no se dejó vencer un cuestionario con tiempo (son minutos de espera): va por prueba automática. Tampoco se vio en pantalla el detalle de un intento vencido.
- La emisión automática del certificado al calificar, en pantalla (sí por prueba automática).
- La vista en teléfono y los 5 recorridos de Playwright.

## Qué quedó corto

- **Bajar la nota no anula un certificado ya emitido.** Si el profesor devuelve una tarea o corrige la nota hacia abajo después, el certificado sigue vigente hasta que alguien lo anule.
- **Poner o subir la nota mínima no toca los certificados ya emitidos**, y bajarla o quitarla no emite de inmediato los que ahora sí cumplen: salen cuando el alumno abre "Mis certificados" o al emitirlos desde "por emitir".
- La nota mínima es **del curso entero**; no hay mínimos por actividad ni por categoría.
- El certificado sigue sin otros requisitos del backlog: encuesta respondida o asistencia.
- La nota no figura en el certificado.
- Los intentos que vencieron antes de este cambio siguen en cero.

## Qué se decidió sin preguntar

1. **Si no había nada guardado, el intento vencido vale cero** y cuenta como usado, igual que antes.
2. **El intento vencido se sigue llamando "Tiempo vencido"**, aunque tenga nota y apruebe.
3. **La nota mínima vale al guardarla**, sin publicar.
4. **Si en el curso nada cuenta para la nota** (no tiene cuestionarios ni tareas con peso), la nota mínima no impide el certificado.
5. **Un certificado ya emitido no se anula solo** si la nota baja después.

Las cinco se pueden cambiar.
