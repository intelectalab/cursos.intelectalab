# Evaluaciones y aula · Entrega 2: nota final del curso y libro de notas

Fecha: 2026-10-08 · Sin commit al momento de escribir este documento

Segunda de las tres entregas del bloque "Evaluaciones y aula". Cierra la historia **YC-EVA-009** (libro de notas y nota final del curso) del sprint 2 del backlog YC ([verificación](../verificacion-backlog.md)). Queda la entrega 3: avance secuencial y vista previa del aula.

## Qué se construyó

### Nota final del curso

- Cada curso tiene ahora una **nota final por alumno**: el promedio de sus cuestionarios y tareas.
- Cada cuestionario y cada tarea lleva un **peso**, que se pone al crearlo o editarlo ("Peso en la nota final del curso"). Por defecto todos pesan 1, es decir, todos valen lo mismo. Con peso 2, una actividad vale el doble; con peso 0, no cuenta para la nota.
- **Lo que aún no tiene nota cuenta como cero.** Así la nota final siempre responde "cuánto lleva del total del curso", y sube a medida que el alumno rinde y entrega.
- En los cuestionarios cuenta el mejor intento, como antes. Las encuestas no llevan nota.
- Como el resto del contenido, un cambio de peso vale desde que se **publica** el curso.

Ejemplo: un cuestionario de peso 1 con 20 y una tarea de peso 3 con 12 dan (1 × 20 + 3 × 12) / 4 = **14**.

### Libro de notas (quien revisa)

- En **Seguimiento → Avance y notas** hay una columna nueva, **Nota final**, al lado de las notas de cada actividad. Bajo el título de cada actividad se ve su peso cuando no es 1.
- Botón **"Descargar notas"**: baja un Excel con una fila por alumno (nombre, correo, documento), una columna por cuestionario o tarea con su peso, y la nota final. Las notas van en la escala de la academia (por ejemplo, 0 a 20).

### Mis notas (alumno)

- En el aula, bajo la barra de avance, el alumno ve **"Tu nota del curso"**.
- "Ver notas" abre el detalle: cada cuestionario y tarea con su peso y su nota, o su situación si aún no la tiene (sin nota, por revisar, devuelta).

## Quién puede hacerlo

Ver y descargar el libro de notas: quien ya podía revisar (Administrador en todos los cursos, Profesor en los suyos). Poner el peso: quien ya podía editar las actividades. El alumno solo ve sus propias notas. **No hay permisos nuevos.**

## Detalle técnico

- Una migración (`2026_10_28_000100_add_weight_to_course_activities`): columna `course_activities.weight` (por defecto 1).
- El peso viaja en la versión publicada. Las versiones publicadas antes de este cambio no lo traen y se leen con peso 1.
- Servicio nuevo `GradebookService` (módulo Académico): calcula notas y nota final. El seguimiento lo usa, así que la tabla y el Excel salen del mismo cálculo.
- Rutas nuevas: `GET /courses/{course}/gradebook/export` (permiso de revisar) y `GET /me/courses/{course}/grades` (alumno).
- `GET /courses/{course}/tracking` ahora trae `final_percent` por alumno y `weight` por actividad.
- `Spreadsheet::write()` nuevo, para generar hojas de Excel que no son plantillas de importación.
- Sin dependencias nuevas ni variables de entorno.

## Qué se verificó

- **264 pruebas del backend pasan** (eran 263). La nueva cubre: el peso se valida y se publica; la nota final con una actividad sin nota, con todas calificadas y con una de peso cero; las notas que ve el alumno; el seguimiento; que un alumno no puede descargar el libro; y **el contenido del Excel**, leído de vuelta (títulos con su peso y notas 20, 12 y 14 en escala de 0 a 20).
- La aplicación web compila y pasa la revisión de tipos y de estilo.
- En el navegador, con la academia demo y "Excel para finanzas":
  - Como alumno: el aula mostró "Tu nota del curso 7.5 de 20" y el detalle con el cuestionario en 15 y la tarea "Devuelta" (que cuenta como cero).
  - Como administrador: la tabla de seguimiento mostró la columna "Nota final" (7.5 para el alumno de prueba, 0 para los que no han rendido nada) y el botón "Descargar notas".

## Qué no se probó

- En el navegador no se hizo clic en "Descargar notas" (la descarga está cubierta por la prueba automática que lee el Excel) ni se cambió un peso desde el formulario.
- Un curso con muchos alumnos o muchas actividades: la tabla crece hacia el lado y no se midió el tiempo.
- Los 5 recorridos de Playwright no se corrieron en esta entrega.
- La vista en teléfono de "Mis notas".

## Qué quedó corto

- **No hay nota mínima para aprobar el curso** ni la palabra "aprobado": solo la nota. Por lo mismo, el certificado sigue pidiendo solo el 100 % de avance, no una nota (YC-CER-002 sigue a medias).
- No se puede ver la nota "solo de lo ya calificado": lo pendiente siempre cuenta como cero. Un alumno a mitad de curso verá una nota baja, y el texto lo explica.
- Los pesos son números sueltos; no hay categorías (por ejemplo, "tareas 40 %, exámenes 60 %") ni se exige que sumen 100.
- No se puede ajustar a mano la nota final de un alumno ni dejar una observación.
- El Excel trae solo a los alumnos con matrícula activa, y no incluye el avance ni las encuestas.
- La nota final no aparece todavía en "Mis cursos", en Reportes ni en el portal de la empresa.

## Qué se decidió sin preguntar

1. **Un peso por actividad, 1 por defecto** (lo recomendado y aceptado con el "sigue").
2. **Lo que no tiene nota cuenta como cero** en la nota final.
3. **El peso se puede poner en 0** para que una actividad sea solo de práctica.
4. **El alumno ve su nota final y el detalle** desde el aula.
5. **El Excel va en la escala de la academia**, no en porcentaje.

Las cinco se pueden cambiar.
