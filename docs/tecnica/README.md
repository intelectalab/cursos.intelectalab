# Documentación técnica

Entregables que pide el Documento Maestro (§25). Salen del propio sistema para que no queden desfasados.

| Documento | Qué es | Cómo se actualiza |
| --- | --- | --- |
| [openapi.json](openapi.json) | Contrato de la API (OpenAPI 3.0): cada ruta con su método, si pide sesión, su permiso, sus parámetros y el cuerpo que valida | `php artisan docs:technical` |
| [diccionario-de-datos.md](diccionario-de-datos.md) | Cada tabla con sus columnas, tipos, claves, obligatoriedad y descripción | `php artisan docs:technical` (necesita la base encendida y migrada) |
| [catalogo-de-estados.md](catalogo-de-estados.md) | Los valores fijos que guarda el sistema: estados, tipos, orígenes y medios | `php artisan docs:technical` |
| [catalogo-de-errores.md](catalogo-de-errores.md) | Cada código de error de la API con su respuesta HTTP y mensajes de ejemplo | `php artisan docs:technical` |
| [trazabilidad.md](trazabilidad.md) | Requisito → regla → tablas → API → pantalla → permiso → historia → prueba | A mano, con cada cambio |

La matriz de permisos está en [../roles-y-permisos.md](../roles-y-permisos.md) (`php artisan access:docs`) y las decisiones de arquitectura, en [../adr/](../adr/).

## Generar

Desde `backend/`, con la base de datos encendida:

```bash
php artisan docs:technical
```

Para ver `openapi.json` como documentación navegable, se puede abrir en cualquier visor de OpenAPI (por ejemplo, pegándolo en editor.swagger.io).

## Límites de lo generado

- **OpenAPI:** los cuerpos de las peticiones se deducen de las reglas de validación escritas en cada controlador. Donde las reglas vienen de otra parte, el cuerpo figura como «no se pudo deducir». Las respuestas se describen solo por su envoltura común, no campo por campo.
- **Catálogo de estados:** el significado sale del comentario de cada constante; muchas no tienen comentario y aparecen con el significado vacío.
- **Catálogo de errores:** el código HTTP se lee del lugar donde se usa el error; en un par de códigos no se pudo leer y figura «—».
- **Diccionario de datos:** la descripción sale de los comentarios de las migraciones; las columnas comunes y las referencias a otras tablas llevan una genérica. De 839 columnas, unas 230 aparecen sin descripción: se completan escribiendo el comentario en su migración.
