# Documentos del Maestro y calidad

Fecha: 2026-10-10 · Sin commit al momento de escribir este documento

Cuatro pendientes que no dependían de ninguna decisión: dos entregables de documentación que pide el Documento Maestro, completar el diccionario de datos y volver a correr los recorridos automáticos.

## Qué se hizo

### Reporte de seguridad

Nuevo: [reporte-de-seguridad.md](../reporte-de-seguridad.md). Describe los controles que existen (aislamiento entre academias, acceso y sesiones, permisos, protección de las peticiones, datos y archivos, pagos, auditoría), cómo se comprueba cada uno y qué falta. Es una revisión propia del equipo: **no reemplaza la prueba de penetración**, que sigue pendiente.

Lo más importante que deja escrito: cinco cosas a resolver antes de producción (la clave expuesta en `backend_env_backup`, la prueba de penetración, los respaldos, el monitoreo y las cabeceras del servidor web).

### Notas de versión

Nuevo: [notas-de-version.md](../notas-de-version.md). Qué incluye la versión, qué no incluye, qué falta probar, cómo está contra el Maestro y qué tener presente al desplegar.

### Diccionario de datos completo

Tenía 228 columnas sin descripción. Ahora **todas las columnas de las 82 tablas están descritas.**

- Las descripciones que faltaban están en `backend/database/data-dictionary.php`, un archivo por tabla y columna. El comentario de la migración, cuando existe, sigue mandando.
- **Una prueba automática falla si aparece una columna sin describir**, así que el diccionario ya no se puede quedar atrás: una columna nueva exige su comentario en la migración o su línea en ese archivo.

### Recorridos automáticos en navegador

No se corrían desde el sprint 12. **Los cinco pasan** contra la demo local: acceso, compra con cupón, cuestionario, certificado y licencia. Siguen funcionando después de todo lo construido desde entonces (planes con límites, pasarelas, reembolsos, plantillas de certificado).

### Dependencias

`composer audit` y `npm audit` no reportan avisos de seguridad al 2026-10-10. Los dos avisos que había en un paquete de Laravel ya no aparecen.

## Lo que decidí y se puede cambiar

1. **El nombre de la versión: «1.0 · candidata».** No había ninguna numeración. Si jefatura usa otra, se cambia en las notas.
2. **Las descripciones en un archivo aparte** y no como comentarios en cada migración: eran 228 líneas repartidas en decenas de migraciones ya aplicadas.

## Lo que quedó corto

- Los **runbooks y el manual operativo** siguen pendientes: dependen de saber qué servidor habrá.
- El **reporte de aceptación** es del sprint 13.
- Los recorridos automáticos dejan datos «E2E» en la demo cada vez que corren.
- El catálogo de estados todavía tiene valores sin su significado escrito.

## Archivos

- Nuevos: `docs/reporte-de-seguridad.md`, `docs/notas-de-version.md`, `backend/database/data-dictionary.php`.
- Modificados: `Tenancy/Console/ExportTechnicalDocsCommand.php` (lee las descripciones), `tests/Feature/Tenancy/TechnicalDocsTest.php` (exige que no falte ninguna), `docs/tecnica/diccionario-de-datos.md` (regenerado).

## Verificación

- Backend: 315 pruebas pasan (suite completa).
- Recorridos en navegador: 5 de 5.
- Sin migraciones, variables, permisos ni dependencias nuevas.
