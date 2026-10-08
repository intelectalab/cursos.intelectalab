# Alumnos y docentes · Entrega 2: listado de docentes y ficha del docente

Fecha: 2026-10-08 · Sin commit al momento de escribir este documento

Segunda y última entrega del bloque "Alumnos y docentes", del sprint 2 del backlog YC ([verificación](../verificacion-backlog.md)). Cierra **YC-PRO-001** (listado y alta de docentes) y deja **YC-PRO-002** (ficha del docente) hecha salvo las valoraciones, que dependen de las reseñas de cursos y no existen todavía.

## Qué se construyó

### Pantalla "Docentes"

Módulo nuevo en el menú, dentro de Operación.

- **Quién aparece:** toda persona con el rol Profesor o asignada como docente a algún curso.
- **Tarjetas que filtran**, con su explicación: Todos, Con cursos, Sin cursos y Cuenta inactiva. Los cursos archivados no cuentan.
- **Buscador** por nombre o correo.
- Por cada docente: cuántos cursos tiene a cargo, **cuántas entregas esperan su revisión** ("Al día" si no hay ninguna), último acceso y estado de la cuenta.
- **"Nuevo docente":** crea la cuenta con el rol Profesor ya puesto. La asignación a cursos se sigue haciendo desde la pestaña Participantes de cada curso.

### Ficha del docente

- **Quién es:** nombre, correo, teléfono, fecha de registro y último acceso.
- **Cuatro cifras:** cursos a cargo, alumnos con matrícula activa en esos cursos, entregas por calificar y entregas que calificó en los últimos 30 días.
- **Cursos asignados:** estado del curso, alumnos, entregas por calificar y **desde cuándo espera la más antigua** ("hace 3 d"). El nombre del curso lleva a su ficha, donde está el seguimiento.

## Quién puede verlo

Hay un **permiso nuevo**, `teachers.read` ("Consultar los docentes, sus cursos y su carga de calificación"). Lo tiene el **Administrador**; la academia puede dárselo a un rol propio (por ejemplo, un coordinador académico). El Profesor no entra a este directorio.

## Detalle técnico

- Sin migraciones.
- Rutas nuevas: `GET /teachers` (paginado, con `meta.counts`) y `GET /teachers/{id}`, en el módulo Reportes (`TeacherDirectory`).
- El formulario de alta de alumnos y de docentes es ahora un mismo componente (`NewPersonModal`), que crea la cuenta con el rol base indicado.
- Permiso agregado al catálogo: **al actualizar un servidor hay que correr `php artisan access:sync`**. `docs/roles-y-permisos.md` se regeneró.
- Sin dependencias nuevas ni variables de entorno.

## Qué se verificó

- **271 pruebas del backend pasan** (eran 269), en una corrida completa que incluye el arreglo de orden de la entrega anterior.
- Pruebas nuevas (2): el listado con cursos y carga, filtros, búsqueda y el efecto de archivar un curso; y la ficha, que baja lo pendiente y sube lo calificado cuando la docente califica, con los permisos (el propio profesor no entra, otra academia no ve a estos docentes).
- La aplicación web compila y pasa la revisión de tipos y de estilo.
- En el navegador, con la academia demo:
  - La lista mostró a la única docente de la demo, Rosa Quispe.
  - Se creó un docente con "Nuevo docente": apareció en la lista con el aviso de asignarlo a sus cursos.
  - Se asignó a Rosa Quispe al curso "Excel para finanzas" y su ficha mostró 1 curso a cargo, 4 alumnos y la fila del curso "Al día".
  - Como el formulario de alta es compartido, la creación probada cubre también el de alumnos.

## Qué no se probó

- En el navegador no se vio una ficha con entregas pendientes y su "espera desde" (sí por prueba automática): en la demo no hay entregas sin revisar.
- Los filtros y el buscador en pantalla (sí por prueba automática).
- La vista en teléfono y los 5 recorridos de Playwright.

## Qué quedó corto

- **Sin valoraciones:** el backlog las pide en la ficha, pero no existen las reseñas de cursos (YC-CUR-008).
- La carga de calificación es **por curso, no por docente**: si un curso tiene dos docentes, las mismas entregas pendientes aparecen en la ficha de ambos. La pantalla lo dice.
- Solo cuenta **tareas por revisar**. Los cuestionarios se califican solos y no hay otras actividades de revisión manual.
- No hay tiempo promedio de respuesta del docente ni alertas por demora.
- La ficha es solo lectura: no se asignan cursos desde ella.
- No está la disponibilidad horaria del docente: eso es el módulo de horarios (decisión 15).

## Qué se decidió sin preguntar

1. **Permiso nuevo `teachers.read`, solo para el Administrador** entre los roles base.
2. **Es docente quien tiene el rol Profesor o está asignado a un curso.**
3. **Los cursos archivados no cuentan** como carga ni como "con cursos", pero sí se listan en la ficha.
4. **"Calificó" cuenta 30 días** hacia atrás.

Las cuatro se pueden cambiar. Dejar fuera las valoraciones fue lo recomendado y aceptado con el "sigue".

En la demo local quedó: un docente de prueba nuevo ("Docente De Prueba …") y Rosa Quispe asignada como docente de "Excel para finanzas".
