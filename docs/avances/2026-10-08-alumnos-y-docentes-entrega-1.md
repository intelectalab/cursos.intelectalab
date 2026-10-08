# Alumnos y docentes · Entrega 1: listado de alumnos y ficha Alumno 360

Fecha: 2026-10-08 · Sin commit al momento de escribir este documento

Primera de las dos entregas del bloque "Alumnos y docentes", que sale del sprint 2 del backlog YC ([verificación](../verificacion-backlog.md)). Cierra **YC-ALU-001** (listado y alta de alumnos) y **YC-ALU-002** (Alumno 360). La segunda entrega será la de docentes.

## Qué se construyó

### Pantalla "Alumnos"

Módulo nuevo en el menú, dentro de Operación.

- **Quién aparece:** toda persona con el rol Alumno o con alguna matrícula. El resto del personal (administradores, profesores, soporte) no sale aquí.
- **Tarjetas que filtran**, con su explicación: Todos, Con cursos (al menos una matrícula activa), Sin cursos (tienen cuenta pero ninguna matrícula activa) y Cuenta inactiva.
- **Buscador** por nombre, correo o número de documento.
- Por cada alumno: documento, cuántos cursos lleva y cuántos completó, último acceso y estado de la cuenta.
- **"Nuevo alumno":** crea la cuenta con el rol Alumno ya puesto (nombres, correo, documento opcional y contraseña inicial). La matrícula se hace después, como siempre, desde el curso o desde Matrículas.

### Ficha del alumno (Alumno 360)

Al abrir un alumno se ve, en una sola pantalla y en solo lectura, lo que antes había que buscar en cuatro módulos:

- **Quién es:** nombre, correo, teléfono, documento, empresa (si es trabajador de una empresa cliente), grupos a los que pertenece, fecha de registro, último acceso, y un aviso si aún no cambia su contraseña temporal.
- **Cuatro cifras:** cursos activos y completados, total pagado, certificados vigentes y consultas abiertas.
- **Cursos:** cada matrícula con su situación (en curso, completado, acceso vencido, por iniciar, anulada), avance, nota del curso, fecha de matrícula y vencimiento. El nombre del curso lleva a su ficha.
- **Compras:** sus órdenes con lo que compró, el total y el estado.
- **Certificados:** curso, código y si está vigente o anulado.
- **Consultas a soporte:** asunto, número y estado.

## Quién puede verlo

Hay un **permiso nuevo**, `students.read` ("Consultar los alumnos y la ficha completa de cada uno"). Lo tienen el **Administrador**, **Soporte** y **Ventas/CRM**; cada academia puede dárselo a sus roles propios. El Profesor no lo tiene: ve a sus alumnos desde el seguimiento de sus cursos.

Dar de alta un alumno exige además los permisos de crear usuarios y ver roles, que hoy solo tiene el Administrador.

## Detalle técnico

- Sin migraciones: todo se lee de tablas que ya existían.
- Rutas nuevas: `GET /students` (paginado, con `meta.counts`) y `GET /students/{id}`. Viven en el módulo Reportes, que es el que ya lee de varios módulos a la vez (`StudentDirectory`).
- El permiso se agregó al catálogo y a los roles base Soporte y Ventas/CRM. **Al actualizar un servidor hay que correr `php artisan access:sync`** para que las academias existentes lo reciban. `docs/roles-y-permisos.md` se regeneró.
- La ficha muestra hasta 50 compras, 50 certificados y 50 consultas, de lo más reciente a lo más antiguo.
- Sin dependencias nuevas ni variables de entorno.

## Qué se verificó

- **269 pruebas del backend pasan** (eran 266). En la primera corrida completa falló una de las nuevas: dos matrículas creadas en el mismo segundo salían en orden cambiante. Se corrigió el orden de la ficha (desempata por antigüedad real) y la prueba pasó tres veces seguidas; las otras 268 habían pasado en esa misma corrida.
- Pruebas nuevas (3): el listado con sus cifras, filtros y búsqueda por documento; la ficha de una alumna que completó un curso, compró otro y abrió una consulta; y los permisos (Soporte y Ventas entran, Profesor y Alumno no, otra academia no ve a estos alumnos).
- La aplicación web compila y pasa la revisión de tipos y de estilo.
- En el navegador, con la academia demo: el menú mostró "Alumnos"; la lista trajo 25 alumnos (21 con cursos, 4 sin cursos); la ficha del alumno de prueba mostró sus 2 cursos completados con avance y nota, sus 2 compras, su certificado vigente y el anulado. La pantalla no se desborda de lado.

## Qué no se probó

- En el navegador no se creó un alumno con "Nuevo alumno" ni se probaron los filtros y el buscador (sí por pruebas automáticas, salvo el alta, que usa la misma operación de Usuarios).
- La pantalla con la cuenta de Soporte o de Ventas (sí por prueba automática).
- Una academia con miles de alumnos: el listado calcula los cursos de cada alumno en la misma consulta, y no se midió con volumen.
- La vista en teléfono y los 5 recorridos de Playwright.

## Qué quedó corto

- La ficha es **solo lectura**: no se matricula, no se edita la cuenta ni se responde una consulta desde ahí. Los cursos enlazan a su ficha; las compras, certificados y consultas todavía no enlazan a su detalle.
- No muestra el detalle de notas por actividad, el Libro de Reclamaciones, las sesiones abiertas ni la auditoría de esa persona.
- Quien tiene el permiso ve **toda** la ficha: no se puede dar, por ejemplo, las compras a Ventas y ocultarle las consultas.
- No hay exportación de la lista a Excel.
- El "alta" crea la cuenta pero no matricula en el mismo paso (para eso está la importación con matrícula).
- La lista no ordena por otra columna que el apellido.

## Qué se decidió sin preguntar

1. **Un permiso nuevo y único** para la lista y la ficha completa, dado a Administrador, Soporte y Ventas/CRM. El backlog menciona también a "Finanzas", un rol que no existe todavía.
2. **Es alumno quien tiene el rol Alumno o alguna matrícula** (así entran también los trabajadores de empresas y los importados).
3. **La ficha vive en el módulo Reportes** y es de solo lectura.
4. **"Sin cursos" incluye a quien solo tiene matrículas anuladas.**

Las cuatro se pueden cambiar.
