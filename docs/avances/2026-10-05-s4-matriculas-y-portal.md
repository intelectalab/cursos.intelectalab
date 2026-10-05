# S4 — Matrículas y portal del alumno

Fecha: 2026-10-05 · Sin commit al momento de escribir este documento

Parte de este sprint se adelantó en S3 (matrícula manual, "Mis cursos", aula y avance). Aquí se completa lo que faltaba: vigencia, gestión general, curso completado y el portal del alumno.

## Qué se construyó

### Vigencia de la matrícula

- Cada matrícula tiene **inicio y fin opcionales**. Fuera de esas fechas el alumno no entra al curso, ni rinde actividades, ni suma avance; conserva sus notas y su historial. El acceso incluye el día de fin completo.
- Cada curso tiene un campo opcional **"Días de acceso"** (Información del curso). Si lo tiene, cada matrícula nueva recibe esa duración; si está vacío, no vence. Al matricular se pueden poner fechas distintas, y mandan sobre el valor del curso.
- El personal puede **cambiar la vigencia** de una matrícula en cualquier momento: extenderla, acortarla o quitarle el límite.
- Cambiar los días de acceso de un curso no altera las matrículas ya hechas ni genera cambios por publicar.
- Al alumno con el acceso vencido se le dice cuándo venció y que pida la extensión; el curso sigue en "Mis cursos", marcado como vencido.

### Situación de una matrícula

Se deduce de su estado y de sus fechas:

| Situación | Cuándo | ¿Da acceso? |
| --- | --- | --- |
| Activa | Dentro de su vigencia | Sí |
| Completada | Llegó al 100 % y sigue vigente | Sí |
| Por iniciar | Su fecha de inicio aún no llega | No |
| Vencida | Pasó su fecha de fin | No |
| Cancelada | El personal la canceló | No |

### Curso completado

Cuando el avance llega a 100 %, la matrícula queda marcada como completada, con su fecha. Si después se publica contenido nuevo, el avance baja pero el curso sigue contando como completado. Lo usarán los certificados (S7).

### Módulo "Matrículas" (personal)

Lista de todas las matrículas de la academia: alumno, curso, situación, vigencia y avance. Filtros por alumno, curso y situación. Desde ahí se matricula (eligiendo el curso), se cambia la vigencia y se cancela.

El rol **Soporte** la ve en solo lectura, como indica el Maestro. El **profesor** ve ahora la lista de alumnos de sus cursos (pestaña Alumnos), sin poder matricular ni cancelar.

### Portal del alumno

- **Inicio:** sus cursos en marcha con el avance, lo que tiene pendiente (cada actividad lleva directo al aula) y sus últimas notas.
- **Mis cursos:** muestra también los completados, los vencidos y los que aún no empiezan, con su fecha.
- **Cursos disponibles** (módulo nuevo): los cursos publicados en los que no está. Solo la ficha: presentación, profesores, duración del acceso y temario con títulos. El contenido exige matrícula; la compra llega en S5.

## Cómo se usa

| Pantalla | Dirección | Permiso |
| --- | --- | --- |
| Matrículas | `/enrollments` | `enrollments.read`, o `enrollments.support.read` (solo lectura) |
| Matricular | Matrículas, o curso › Alumnos | `enrollments.create` |
| Cambiar vigencia | botón "Vigencia" de una matrícula | `enrollments.update` |
| Cancelar | botón "Cancelar" | `enrollments.cancel` |
| Días de acceso del curso | curso › Información | `courses.update` |
| Inicio del alumno | `/dashboard` | `enrollments.self.read` |
| Cursos disponibles | `/available-courses` | `courses.catalog.read` |

## Endpoints nuevos

Todos bajo `/api/v1`.

| Método y ruta | Para qué |
| --- | --- |
| `GET /enrollments` (`course_id`, `search`, `state`) | Todas las matrículas |
| `GET /enrollments/options` | Cursos para los filtros y para matricular |
| `POST /enrollments` (`course_id`, `student_ids`, `starts_at`, `ends_at`) | Matricular (ruta fijada por el Maestro) |
| `PUT /enrollments/{matrícula}` (`starts_at`, `ends_at`) | Cambiar la vigencia |
| `DELETE /enrollments/{matrícula}` | Cancelar |
| `GET /me/overview` | Inicio del alumno |
| `GET /me/available-courses`, `GET /me/available-courses/{id}` | Cursos disponibles y su ficha |

Las rutas por curso de S3 siguen funcionando; `POST /courses/{id}/enrollments` acepta ahora las fechas. Cursos devuelve y acepta `access_days`.

## Cambios fuera del módulo

- **Permiso nuevo:** `enrollments.update`.
- **Códigos de error nuevos:** `ENROLLMENT_EXPIRED` y `ENROLLMENT_NOT_STARTED` (respuesta 403 con la fecha).
- **Cambio de comportamiento:** un profesor que consulta las matrículas de un curso que no dicta recibe 404 (antes 403), como en el resto del curso.
- **Menú:** "Matrículas" (Operación) y "Cursos disponibles" (General).

## Verificación

- 7 pruebas nuevas (138 en total, todas pasan): vigencia por defecto desde el curso y fechas explícitas, acceso solo dentro de la vigencia (incluido el último día, la extensión y la matrícula por iniciar), gestión desde la lista general con sus filtros, Soporte en solo lectura y alcance del profesor, curso completado, inicio del alumno, y cursos disponibles sin filtrar contenido.
- En el navegador, como administrador: abrir Matrículas y vencer una matrícula cambiando su vigencia. Como alumno: ver el curso como "Acceso vencido" (sin poder entrar y con el mensaje del servidor), "Cursos disponibles" vacío, y, tras restaurar la vigencia, el inicio con su curso completado y sus últimas notas.
- Estilo de PHP, tipos, lint y build del frontend pasan.
- No se probó en el navegador: matricular desde la lista general eligiendo curso, la ficha de un curso disponible (en la academia demo el alumno ya está en el único curso publicado), el campo "Días de acceso", la vista de Soporte ni el tamaño de teléfono.

## Cambios que afectan a la instalación

- Migración nueva: columnas `starts_at`, `ends_at` y `completed_at` en `enrollments`, y `access_days` en `courses`.
- Ejecutar `php artisan access:sync` (como en cada despliegue).
- Sin dependencias ni variables nuevas.

## Decisiones tomadas

- **Vigencia (PD-005):** no hay regla fija en el código. Cada academia la define por curso con "Días de acceso", y el personal ajusta cada matrícula.
- **Curso archivado:** quienes ya estaban matriculados conservan el acceso; solo se bloquean las matrículas nuevas.
- **Cursos disponibles:** solo la ficha, sin solicitud de matrícula; la compra llega en S5.
- **Matrícula vencida:** sigue siendo la matrícula del alumno en ese curso (no se crea otra): para devolverle el acceso se extiende.

## Pendiente

- Matricular muchos alumnos desde un archivo.
- Avisos por correo: matrícula creada, acceso por vencer y vencido (depende del proveedor de correo).
- Que el alumno pueda solicitar una extensión o una matrícula desde el portal.
- Exportar la lista de matrículas.
- Revisar las pantallas nuevas en teléfono.
