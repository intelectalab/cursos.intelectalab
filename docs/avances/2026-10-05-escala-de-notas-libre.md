# Escalas de notas guardadas

Fecha: 2026-10-05. Ajuste pedido antes de empezar S5.

## Qué cambió

Antes la academia solo podía elegir entre notas de 0 a 20 o de 0 a 100. Ahora tiene su propia lista de escalas, en **Configuración → Calificaciones**:

- **Escalas guardadas:** la lista muestra las dos del sistema (Vigesimal, de 0 a 20; Centesimal, de 0 a 100) y las que la academia cree.
- **Nueva escala:** se le pone un nombre (por ejemplo "Sobre 10") y una nota máxima, un número entero entre 1 y 1000. Queda guardada para usarla cuando se quiera.
- **Editar y Eliminar:** en cada escala creada por la academia. Las dos del sistema no se pueden cambiar ni borrar.
- **Escala de notas en uso:** el desplegable ofrece todas las escalas; se elige una y se guarda con "Guardar cambios".

Reglas:

- No puede haber dos escalas con el mismo nombre ni con la misma nota máxima dentro de una academia.
- Una escala en uso no se puede eliminar: primero hay que elegir otra y guardar.
- Si se cambia la nota máxima de la escala en uso, las notas pasan a mostrarse con el valor nuevo.
- Las notas se siguen guardando en porcentaje, así que cambiar de escala no altera las notas ya registradas: solo cómo se muestran en cuestionarios, tareas, seguimiento e inicio del alumno.
- Crear, editar y eliminar escalas queda en la auditoría.

## Detalle técnico

- Tabla nueva `grade_scales` (por academia, con RLS): `name`, `max_score`. Migración `2026_10_15_000100_create_grade_scales_table`: hay que correr `php artisan migrate`.
- `GradeScaleService` (módulo Tenancy): escalas del sistema, lista, alta, edición y borrado. La escala en uso sigue siendo `grade_scale` en la configuración general (la nota máxima).
- Rutas: `GET /api/v1/settings/grade-scales` (permiso `tenant_settings.read`); `POST`, `PUT /{id}` y `DELETE /{id}` (permiso `tenant_settings.update`). Todas devuelven la lista completa y, en `meta.grade_scale`, la escala en uso.
- `PUT /api/v1/settings`: `grade_scale` solo acepta la nota máxima de una escala existente.
- Código de error nuevo: `GRADE_SCALE_IN_USE` (409).
- Sin dependencias nuevas.

## Qué se verificó

- 4 pruebas nuevas (`GradeScaleTest`): crear, editar y eliminar con auditoría; nombres y notas máximas repetidos o fuera de rango; escala en uso protegida; aislamiento entre academias y permisos. Las 142 pruebas del backend pasan; el frontend compila y pasa el lint.
- En el navegador, academia demo: se creó "Sobre 10", se rechazó un duplicado, se puso en uso, se editó a "Sobre 5" (la escala en uso la siguió), se rechazó eliminarla en uso, y tras volver a Vigesimal se eliminó. La demo quedó como estaba.
- No se revisó pantalla por pantalla cómo se ven las notas con una escala poco habitual, ni esta sección en teléfono.

## Qué queda fuera

- Escalas que no son numéricas: letras (A, B, C), conceptos (Logrado / En proceso) o aprobado / desaprobado.
- Una escala distinta por curso: la escala en uso es una sola para toda la academia.
