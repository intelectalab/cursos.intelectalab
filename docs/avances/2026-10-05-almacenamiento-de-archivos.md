# Almacenamiento de archivos por academia

Fecha: 2026-10-05 · Sin commit al momento de escribir este documento

## Qué se construyó

- **Módulo de archivos** (`backend/app/Modules/Files`): tabla `files` con aislamiento por academia (scope de Eloquent y seguridad por filas, como el resto de los datos) y un servicio único, `FileStorage`, por el que pasan todas las subidas, borrados y entregas.
- **Proveedor intercambiable:** los archivos se guardan en el disco del servidor (`storage/app/private`). Pasar a un servicio compatible con S3 es cambiar la variable `FILES_DISK`; el código no cambia.
- **Una carpeta por academia:** `tenants/{id de la academia}/{uso}/{id del archivo}.{extensión}`. El nombre en disco no viene del usuario.
- **Usos declarados:** un archivo solo se acepta para un uso registrado en `backend/config/files.php`, que fija si es público o privado, el tamaño máximo y los formatos. Hoy existe un uso: `tenant_logo`.
- **Validación por contenido:** el tipo se deduce del contenido del archivo, no de su nombre. Un script renombrado como `.png` se rechaza. SVG no se admite porque puede traer scripts.
- **Archivos públicos:** se entregan sin sesión en `/api/v1/public-files/{id}`, solo dentro del subdominio de su academia. Desde otra academia o desde el dominio central responden "no existe".
- **Archivos privados:** nunca salen por la ruta pública. No hay ruta genérica de descarga: cada módulo dueño entregará los suyos con sus propias reglas (el primero será Cursos, en S2).
- **Logo de la academia** (primer uso real): se sube, cambia y quita en Configuración › Organización. Se guarda al elegirlo, se muestra en el menú lateral y queda en la auditoría.

## Cómo se usa

| Acción | Dónde | Permiso |
| --- | --- | --- |
| Subir o cambiar el logo | Configuración › Organización › "Subir logo" | `tenant_settings.update` |
| Quitar el logo | Configuración › Organización › "Quitar" | `tenant_settings.update` |

Formatos: PNG, JPG y WebP, hasta 2 MB.

## Endpoints nuevos

| Método y ruta | Acceso |
| --- | --- |
| `POST /settings/logo` (campo `file`) | `tenant_settings.update` |
| `DELETE /settings/logo` | `tenant_settings.update` |
| `GET /public-files/{id}` | público, dentro del subdominio de la academia |

`GET /tenant` y `GET /settings` devuelven ahora `logo_url`.

## Para módulos nuevos que guarden archivos

1. Declarar el uso en `backend/config/files.php` (visibilidad, límite y formatos).
2. Validar con `FileStorage::rules('uso')` y guardar con `FileStorage::store($archivo, 'uso')`.
3. Si es privado, exponer una ruta propia que compruebe el acceso y responda con `FileStorage::response($file)`.
4. No usar `Storage` directamente.

## Verificación

- 7 pruebas nuevas (64 en total, todas pasan): subida y entrega pública, reemplazo y borrado con limpieza del archivo anterior, rechazo por contenido, formato y tamaño, permisos, aislamiento entre academias, y que un archivo privado no sale por la ruta pública.
- En el navegador: subir un logo, verlo en Configuración y en el menú, y quitarlo.
- Estilo de PHP, tipos, lint y build del frontend pasan.

## Cambios que afectan a la instalación

- Migración nueva: tabla `files`.
- Variables nuevas (opcionales): `FILES_DISK`, `FILES_IMAGE_MAX_KB`, `FILES_DOCUMENT_MAX_KB`.
- La carpeta `backend/storage/app/private` guarda los archivos: debe conservarse entre despliegues e incluirse en los respaldos.
- Límites de subida de PHP y del servidor web: ver la guía de instalación, sección 7.
- Desarrollo: `API_PORT` permite que el frontend apunte a un backend en otro puerto.

## Decisiones tomadas

- Disco del servidor por ahora, detrás de `FileStorage`.
- Dos clases de archivo: públicos y privados.
- Límites: 2 MB para imágenes y 50 MB para documentos, configurables. Videos fuera hasta S2.

## Pendiente

- El logo aún no se muestra en la pantalla de inicio de sesión ni en los correos.
- No se redimensionan ni optimizan las imágenes subidas.
- Sin antivirus ni cuota de espacio por academia.
- Descarga de archivos privados: llega con el primer uso privado, en S2.
- Con S3, los archivos públicos se seguirían entregando a través de la aplicación; servirlos directo desde el proveedor queda para cuando se elija uno.
