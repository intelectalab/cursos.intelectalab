# S10 — Operación · Entrega 3: páginas (CMS)

Fecha: 2026-10-05 · Sin commit al momento de escribir este documento

Con esta entrega S10 queda completo. Cada academia redacta y publica sus **páginas de texto** (Nosotros, Términos y condiciones, Política de privacidad, Preguntas frecuentes…) y cualquiera las lee en su sitio (Maestro RF-CMS-001, FP-19: borrador → edición → vista previa → publicación → versión; RN-017).

## Qué se construyó

### Para el personal: "Páginas"

En el menú de Administración.

- **Nueva página**: título y dirección. La dirección se propone sola a partir del título ("Términos y condiciones" → `terminos-y-condiciones`) y se puede cambiar. Nace como borrador.
- **Editor** con formato básico: título y subtítulo, negrita, cursiva, subrayado, listas, cita, línea divisoria, enlaces e imágenes, con deshacer y rehacer.
- **Guardar borrador**: los cambios no se ven en el sitio.
- **Vista previa**: muestra el borrador tal como quedará.
- **Publicar**: la pone a la vista y guarda una **versión numerada** con fecha y autor. Si se sigue editando, el público ve lo publicado hasta que se publique de nuevo; mientras tanto la página figura con "Cambios sin publicar".
- **Retirar del sitio**: deja de verse, pero conserva el borrador y las versiones.
- **Eliminar**: solo una página que no está publicada.
- Cada página se puede enlazar o no al **pie del sitio**.

La dirección y el enlace al pie se aplican al guardar; el título y el contenido, recién al publicar.

### Para el público

- Cada página publicada queda en `https://<academia>/p/<dirección>`, sin iniciar sesión.
- Al pie del catálogo y de las demás páginas públicas aparecen los enlaces a las páginas marcadas para el pie, junto al Libro de Reclamaciones.
- Un borrador o una página retirada no existen para el público.

### Editar y publicar son permisos distintos (RN-017)

| Permiso | Qué permite | Roles base |
| --- | --- | --- |
| `cms.read` | Ver las páginas y sus borradores | Administrador |
| `cms.update` | Crear páginas y editar el borrador | Administrador |
| `cms.publish` | Publicar y retirar | Administrador |

`cms.read` es nuevo. Ningún otro rol base los tiene, pero una academia puede crear, por ejemplo, un rol "Redactor" con `cms.read` y `cms.update`: escribe y guarda, y otra persona publica.

## Seguridad del contenido

El contenido **no se guarda como HTML**. El editor produce un documento estructurado (un árbol de párrafos, títulos, listas…) y el servidor lo reconstruye conservando solo lo permitido:

- Bloques: párrafo, título, subtítulo, listas, cita, línea, salto de línea e imagen.
- Formato: negrita, cursiva, subrayado, tachado y enlace.
- Enlaces: solo `http`, `https`, `mailto`, `tel` o direcciones internas del sitio. Un `javascript:` se descarta.
- Imágenes: solo archivos subidos a la propia academia.

Lo que llegue de más (scripts, marcos, estilos, atributos) se descarta al guardar, y la página pública dibuja el árbol sin insertar HTML. Así, alguien con permiso de edición no puede colar código en el sitio.

## Detalle técnico

- **Módulo nuevo** `backend/app/Modules/Cms`. Tablas del Maestro §5.1: `pages`, `page_versions`, `media`, con RLS. El validador del contenido es `PageContent`.
- **Rutas del personal**: `GET/POST /api/v1/pages`, `GET/PUT/DELETE /pages/{id}`, `POST /pages/{id}/publish` (Maestro §7.2), `POST /pages/{id}/unpublish`, `POST /pages/media`.
- **Rutas públicas**: `GET /api/v1/site/pages` (enlaces del pie) y `GET /site/pages/{dirección}`.
- **Dependencia nueva en el frontend, aprobada antes de empezar**: el editor TipTap (`@tiptap/react`, `@tiptap/starter-kit`, `@tiptap/extension-image`, `@tiptap/pm`, versión 3.31). No hay dependencias nuevas en el backend.
- Uso de archivos nuevo: `page_media` (público; png, jpg, jpeg, webp; hasta 2 MB).
- Auditoría: creación, publicación, retiro y eliminación. Código de error nuevo: `PAGE_NOT_PUBLISHABLE`.
- Frontend: `frontend/src/features/cms`; los estilos del contenido están en `frontend/src/index.css` (`.page-content`) y son los mismos en el editor y en el sitio.

## Qué se verificó

- **208 pruebas del backend pasan** (corrida completa al cierre), 5 nuevas (`tests/Feature/Cms/PageTest.php`): el público ve lo publicado y no el borrador, versiones, retiro, limpieza del contenido (scripts, marcos, enlaces `javascript:`, imágenes externas y atributos se descartan), direcciones únicas y bien formadas, validación de imágenes, permisos separados para editar y publicar, y aislamiento entre academias.
- El frontend compila, pasa el lint y genera el build.
- **En el navegador, academia demo**: creé "Términos y condiciones", escribí un título y un párrafo con el editor, guardé el borrador, abrí la vista previa, publiqué y la vi en `/p/terminos-y-condiciones` sin sesión, con su enlace al pie junto al Libro de Reclamaciones.
- En esa prueba corregí dos detalles: al volver de la vista previa se perdían los cambios sin guardar, y el título de la pestaña del navegador se quedaba con el de la página pública.
- **No se probó en el navegador**: insertar un enlace o una imagen, "Cambios sin publicar" tras editar una página publicada, retirar, eliminar, un rol que solo redacta, ni el tamaño de teléfono.

## Qué quedó corto

- **No se vuelve a una versión anterior** ni se ve su contenido: el historial muestra cuándo y quién publicó.
- **Sin editor de la portada ni del catálogo**: son solo páginas de texto. Sin menú de navegación propio: los enlaces van al pie.
- **Sin tablas, videos, columnas ni colores** en el contenido. Las imágenes no se redimensionan ni se alinean.
- **Sin biblioteca de imágenes**: una imagen subida no se puede reutilizar ni borrar desde la pantalla, y queda guardada aunque se quite de la página.
- Sin descripción para buscadores ni imagen para compartir en redes.
- Sin programar la publicación para una fecha.
- Configuración conserva los campos antiguos "Términos" y "Política de privacidad" (texto simple, que ninguna pantalla pública muestra). Conviene retirarlos y usar las páginas.
- El editor **viaja en la descarga inicial** de la aplicación para todos los usuarios, aunque solo lo use quien edita páginas. El paquete quedó en 1,24 MB (348 KB comprimido); no medí cuánto de eso es el editor. Se puede cargar solo al abrir el editor; quedó pendiente.
