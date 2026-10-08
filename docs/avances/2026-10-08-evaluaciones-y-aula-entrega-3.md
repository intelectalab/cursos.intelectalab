# Evaluaciones y aula · Entrega 3: avance secuencial y vista previa del aula

Fecha: 2026-10-08 · Sin commit al momento de escribir este documento

Tercera y última entrega del bloque "Evaluaciones y aula". Cierra dos historias del sprint 2 del backlog YC ([verificación](../verificacion-backlog.md)): **YC-AULA-003** (avance secuencial por secciones) y **YC-AULA-004** (vista previa del aula para profesores y administradores).

## Qué se construyó

### Avance secuencial

- Cada curso tiene un interruptor nuevo en **Información → "Avance secuencial"**, apagado por defecto.
- Encendido, **cada sección se abre cuando el alumno completa la anterior**. Dentro de una sección abierta avanza en el orden que quiera.
- "Completar" es lo mismo que ya cuenta para el avance: abrir cada recurso, aprobar el cuestionario, entregar la tarea y responder la encuesta.
- El alumno ve las secciones cerradas en el temario, con un candado y el texto "Se abre al completar la sección anterior", y un aviso al inicio del aula. El botón "Siguiente" no pasa a una sección cerrada.
- **El bloqueo lo hace el servidor**, no solo la pantalla: de una sección cerrada solo salen los títulos (ni el enlace del video, ni el texto, ni el archivo, ni la consigna), y rechaza abrir, descargar, rendir o entregar algo de ella.
- El interruptor **vale desde que se guarda**, sin publicar, igual que el precio o el certificado.

### Vista previa del aula

- En la ficha del curso hay un enlace nuevo, **"Ver como alumno"**. Abre el aula tal como la ve un alumno: el mismo temario, los videos, los textos y las descargas.
- Si el curso tiene cambios sin publicar, se puede alternar entre **Publicado** (lo que los alumnos ven hoy) y **Borrador** (lo que verán cuando se publique). Un curso que nunca se publicó muestra el borrador.
- Una franja amarilla recuerda que es una vista previa: no se registra avance ni se rinden actividades. De cada cuestionario, tarea o encuesta se ve la ficha (indicaciones, número de preguntas, nota mínima), no el formulario para rendirla.
- En la vista previa todo aparece abierto, aunque el curso tenga avance secuencial.

## Quién puede hacerlo

- El interruptor: quien ya podía editar los datos del curso.
- La vista previa: quien ya podía ver el curso por dentro (el Administrador todos; el Profesor los suyos). Un alumno no entra.
- **No hay permisos nuevos.**

## Detalle técnico

- Una migración (`2026_10_29_000100_add_sequential_to_courses`): columna `courses.sequential`.
- `SequenceGate` (módulo Matrículas) decide qué secciones están cerradas para una matrícula y rechaza con `SECTION_LOCKED` (403). Lo usan el aula (`GET /me/courses/{course}`, que ahora trae `sequential` y `locked` por sección), completar y descargar recursos, y todas las rutas del alumno sobre una actividad.
- Ruta nueva: `GET /courses/{course}/preview?source=draft|published`. El borrador se arma con la misma función que usa la publicación (`CourseService::draftSnapshot`), así que la vista previa del borrador es exactamente lo que se publicaría.
- En la aplicación web, la vista previa es la misma pantalla del aula en otro modo (`/courses/:id/preview`), no una copia.
- Sin dependencias nuevas ni variables de entorno.

## Qué se verificó

- **266 pruebas del backend pasan** (eran 264).
- Pruebas nuevas (2): el avance secuencial (sección cerrada sin contenido, rechazo al completar, abrir y entregar; se abre al completar la anterior; apagarlo libera todo) y la vista previa (publicado y borrador, curso sin publicar, profesor solo en sus cursos, alumno sin acceso).
- La aplicación web compila y pasa la revisión de tipos y de estilo.
- En el navegador, con la academia demo y "Excel para finanzas":
  - Vista previa como administrador: mostró la versión 2 publicada con 7 elementos; al pasar a "Borrador" mostró 8, incluido un recurso que aún no está publicado. El cuestionario mostró su ficha con el aviso de que no se abren intentos.
  - Se encendió "Avance secuencial" desde Información. Como alumno (Tomás), la segunda sección apareció cerrada, con el aviso al inicio; al recorrer los recursos de la primera se marcaron como completados y la segunda siguió cerrada porque faltan el cuestionario y la tarea.
  - Al terminar se apagó el interruptor, para dejar la demo como estaba.

## Qué no se probó

- En el navegador no se llegó a ver abrirse la segunda sección (habría que aprobar el cuestionario y entregar la tarea); eso lo cubre la prueba automática.
- La descarga de un archivo desde la vista previa.
- La vista previa con la cuenta de un profesor en pantalla (sí por prueba automática).
- La vista en teléfono, y los 5 recorridos de Playwright.

## Qué quedó corto

- El orden es **por secciones**, no elemento por elemento.
- **Un cuestionario bloquea hasta aprobarlo.** Si el alumno agota sus intentos sin aprobar, no puede abrir la sección siguiente: la salida es que el profesor le dé un intento adicional (entrega 1). No hay una regla de "pasa aunque no apruebe".
- Encender el interruptor en un curso en marcha puede cerrar secciones a alumnos que ya habían entrado a ellas sin terminar las anteriores. Lo que ya completaron se conserva.
- No se puede liberar una sección a un alumno en particular.
- La vista previa no deja probar un cuestionario como lo rinde el alumno, ni ver las preguntas: muestra la ficha.
- La portada del aula sigue sin anuncios ni requisitos del certificado (YC-AULA-001 sigue a medias).
- En la vista previa de lo publicado, un archivo que ya se borró del borrador no se puede descargar.

## Qué se decidió sin preguntar

1. **El interruptor vale al guardarlo**, sin publicar, como el precio y el certificado.
2. **El servidor no entrega el contenido de una sección cerrada**, solo los títulos.
3. **La vista previa muestra todo abierto** aunque el curso sea secuencial, y permite ver el borrador además de lo publicado.
4. **La vista previa no rinde actividades**: muestra la ficha.

Las cuatro se pueden cambiar. El interruptor por curso, apagado por defecto, y la regla de abrir por sección fueron lo recomendado y aceptado con el "sigue".

Quedó en la demo local el avance de la prueba: Tomás Quispe tiene tres recursos completados en "Excel para finanzas".
