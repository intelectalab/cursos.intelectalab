# Catálogo público: buscador y filtros

Fecha: 2026-10-08 · Sin commit al momento de escribir este documento

Último de los puntos aprobados del sprint 2 del backlog YC que no dependía de una respuesta. Cierra **YC-WEB-002** (catálogo público de cursos con filtros) ([verificación](../verificacion-backlog.md)).

## Qué se construyó

En el catálogo público de cada academia (`/catalog`), encima de los cursos:

- **Buscador.** Busca en el título, el resumen y la categoría de cada curso. No distingue mayúsculas ni tildes ("programacion" encuentra "Programación"), y si se escriben varias palabras, deben estar todas.
- **Filtro por categoría**, con las categorías que tienen los cursos en venta.
- **Filtro por nivel** (básico, intermedio, avanzado).
- **Filtro por precio:** gratis o de pago.
- Los filtros **se combinan** entre sí y con la búsqueda. Un texto indica cuántos cursos quedan ("1 de 3 cursos") y un botón quita todos los filtros.
- **Cada filtro aparece solo si sirve:** si todos los cursos son del mismo nivel, el de nivel no se muestra; si todos son de pago, tampoco el de precio.
- La búsqueda y los filtros quedan **en la dirección de la página** (`/catalog?q=excel&price=free`), así que el enlace se puede compartir o guardar ya filtrado.
- Si nada coincide, se dice claramente y se ofrece quitar los filtros.

Los **paquetes** responden a la búsqueda por texto (por su título y resumen). Como no tienen categoría ni nivel, se ocultan cuando se usa uno de esos filtros o el de precio.

## Detalle técnico

- `GET /catalog/courses` acepta ahora `search`, `category`, `level` y `price` (`free` o `paid`). Devuelve en `meta` con qué se puede filtrar (`categories`, `levels`, `has_free`, `has_paid`) y el `total` de cursos en venta.
- Se filtra sobre la **versión publicada** de cada curso, que es lo que ve el visitante, no sobre el borrador.
- El filtrado se hace en el servidor, en memoria, sobre los cursos en venta de la academia (la lista ya se entregaba completa, sin paginar).
- Sin migraciones, sin permisos, sin dependencias nuevas ni variables de entorno. La ruta sigue siendo pública.

## Qué se verificó

- **273 pruebas del backend pasan** (eran 271).
- Pruebas nuevas (2): la búsqueda (sin tildes ni mayúsculas, por título, resumen y categoría, con varias palabras), cada filtro y su combinación, los valores no válidos, que un curso sin precio no aparece ni en la lista ni en las opciones; y que una categoría cambiada en el borrador no afecta al catálogo hasta publicar.
- La aplicación web compila y pasa la revisión de tipos y de estilo.
- En el navegador, sin iniciar sesión, en el catálogo de la academia demo (3 cursos y 1 paquete):
  - Aparecieron el buscador y los filtros de categoría (Datos, Ofimática) y de precio; el de nivel no, porque en la demo no hay más de un nivel.
  - "dinamicas" dejó solo "Excel para finanzas", con "1 de 3 cursos" y la dirección `?q=dinamicas`.
  - Un texto sin coincidencias mostró el aviso con "Quitar filtros", que devolvió todo.
  - "Gratis" dejó solo "Introducción a las hojas de cálculo" y ocultó el paquete.
  - A ancho de teléfono (375 px) los controles se apilan y la página no se desborda de lado.

## Qué no se probó

- El filtro por nivel en pantalla (sí por prueba automática).
- Abrir en una pestaña nueva un enlace ya filtrado.
- Un catálogo con cientos de cursos.
- Los 5 recorridos de Playwright no se corrieron (el de compra pasa por el catálogo).

## Qué quedó corto

- **"Cursos disponibles"**, el catálogo que ve el alumno ya dentro de su cuenta, sigue sin buscador ni filtros.
- No hay orden por precio, por fecha o por popularidad: los cursos salen en orden alfabético.
- No busca dentro del temario ni en la descripción larga del curso.
- La categoría sigue siendo texto libre: "Ofimática" y "Ofimatica" se muestran como dos opciones si la academia las escribió distinto (al filtrar sí se tratan igual).
- Sin paginación: se entrega todo el catálogo. Con cientos de cursos habría que paginar y filtrar en la base de datos.
- La portada del sitio y el posicionamiento en buscadores (YC-WEB-001 y 006) siguen pendientes, a la espera del dominio.

## Qué se decidió sin preguntar

1. **Los filtros que no aportan no se muestran.**
2. **Los paquetes solo responden a la búsqueda por texto** y se ocultan con los demás filtros.
3. **La búsqueda y los filtros van en la dirección de la página.**
4. **Se filtra en memoria**, sin paginar, porque los catálogos de hoy son pequeños.

Las cuatro se pueden cambiar. Buscar por texto y filtrar por categoría, nivel y precio fue lo recomendado y aceptado con el "sigue".
