# S12 — Endurecimiento · Entrega 2: rendimiento

Fecha: 2026-10-06 · Sin commit al momento de escribir este documento

Segunda entrega de S12 (Maestro RNF-PERF-001). La meta interna acordada: listados por debajo de 500 ms y bajar la descarga inicial de la aplicación web a menos de la mitad.

## Qué se hizo

### La aplicación web ya no se descarga entera

Antes, quien abría la academia descargaba todas las pantallas del sistema de una vez, incluido el editor de páginas, aunque solo fuera a iniciar sesión.

| | Antes | Ahora |
| --- | --- | --- |
| Descarga inicial (JavaScript) | 1 276 kB | 317 kB (99 kB comprimido) |
| Editor de páginas | Dentro de la descarga inicial | 419 kB, solo al abrir el editor |
| Cada pantalla | Dentro de la descarga inicial | Entre 1 y 63 kB, al abrirla |

La descarga inicial quedó en la cuarta parte. Cada pantalla se pide la primera vez que se abre; mientras llega, el menú y la cabecera se quedan en su sitio.

Si se publica una versión nueva mientras alguien tiene la pestaña abierta, y esa persona abre una pantalla cuyo archivo ya no existe, la página se recarga sola una vez para tomar la versión vigente.

### Consultas repetidas

- **Control permanente:** fuera de producción, cargar datos relacionados fila por fila ahora es un error. Así, las pruebas automáticas detectan este problema (conocido como N+1) en cualquier pantalla, también en las que se construyan después.
- **Prueba de presupuesto de consultas:** nueve listados (usuarios, roles, cursos, matrículas, órdenes, catálogo, auditoría, certificados y reportes) deben hacer el mismo número de consultas con el doble de filas.
- **Un caso encontrado y corregido:** el listado general de matrículas hacía una consulta por cada curso distinto de la página para calcular el avance. Ahora hace una sola para toda la página.

### Índices

Cinco índices nuevos para consultas que ya existen y crecerán con el uso: ítems de una orden, órdenes por fecha de pago (reportes), matrículas por fecha (listado y reportes) y recursos y actividades por curso.

### Arranque optimizado de Laravel

Se probó `php artisan config:cache`, `route:cache` y `event:cache` con este código: la aplicación resuelve la academia, responde el catálogo y rechaza un acceso incorrecto con normalidad. Quedó anotado como paso del despliegue.

## Tiempos medidos

En el equipo de desarrollo, con los datos de la academia demo (pocos registros), todos los listados principales responden entre 0,32 y 0,40 segundos. De ese tiempo, unos 0,27 segundos son el arranque del servidor de desarrollo de PHP, que en un servidor real con caché de código (OPcache) es mucho menor.

## Qué se verificó

- 241 pruebas del backend pasan, incluidas las dos nuevas de rendimiento.
- La aplicación web compila; se recorrieron en el navegador doce pantallas (inicio, CRM, promociones, reportes, páginas, cursos, soporte, reclamos, auditoría, configuración y otras) y todas cargan con la descarga partida.

## Qué quedó corto

- **No se probó con volumen real.** La meta de 500 ms se cumple con datos de demostración; falta medir con miles de matrículas y órdenes, y con el servidor definitivo (decisión 1). Queda para S13.
- El compromiso formal de tiempos y disponibilidad (PD-014 del Maestro) sigue sin definirse.
- El portal de empresa hace dos consultas por cada curso que la empresa contrató. Está acotado (una empresa contrata pocos cursos) y no se tocó.
- La prueba de presupuesto cubre nueve listados. CRM, soporte, reclamos, promociones y páginas quedan cubiertos solo por el control general de carga fila por fila.
- Las imágenes (portadas, logos) no se redimensionan ni se sirven con caché larga.
