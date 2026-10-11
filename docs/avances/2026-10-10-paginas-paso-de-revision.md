# Páginas · Paso de revisión

Fecha: 2026-10-10 · Sin commit al momento de escribir este documento

Cierra el requisito **RF-CMS-001** del Documento Maestro, que estaba a medias: las páginas iban de borrador a publicadas sin un paso de revisión. **Ahora quien redacta envía su borrador a revisión, y quien publica lo aprueba (publicándolo) o lo devuelve con observaciones.**

## Cómo funciona

1. **Quien redacta** (permiso de editar páginas, sin el de publicar) escribe el borrador y pulsa **«Enviar a revisión»**. La página queda marcada «En revisión».
2. **Quien publica** abre la página, ve quién la envió y cuándo, la mira en la vista previa y elige:
   - **«Publicar»**: aprueba. La página sale a la vista y la revisión se cierra.
   - **«Devolver con observaciones»**: escribe qué hay que corregir. La página no se publica y queda marcada «Devuelta con observaciones», con el comentario a la vista de quien la redactó.
3. Quien redacta corrige y la **envía de nuevo**.

Reglas:

- Una página vacía no se envía, y tampoco una cuyo borrador es igual a lo ya publicado.
- **Si el borrador cambia después de enviarlo, la revisión se anula** y hay que enviarlo otra vez: nadie publica algo distinto de lo que revisó.
- Devolver exige un comentario.
- La lista de páginas muestra «En revisión» y «Devuelta» junto al estado.
- Enviar a revisión y devolver quedan en la auditoría.

## Lo que decidí y se puede cambiar

1. **Quien tiene los dos permisos publica directo.** El Administrador puede editar y publicar sin pedirse revisión a sí mismo; el paso de revisión es para quien solo redacta. Si jefatura quiere que nadie publique lo que escribió, se agrega como regla.
2. **No hay un rol «Redactor» de fábrica.** La academia lo crea en Roles con los permisos «Ver páginas» y «Editar páginas».
3. **Publicar es aprobar.** No hay un botón «Aprobar» aparte que deje la página lista sin publicarla.

## Lo que quedó corto

- **Sin avisos:** quien publica no recibe correo ni contador cuando hay algo por revisar; lo ve en la lista de páginas. Depende del proveedor de correo.
- **Un solo comentario** por devolución, sin historial de idas y vueltas (queda en la auditoría).
- Sin filtro ni tarjeta «En revisión» en la lista.

## Archivos

- Backend: migración `2026_11_06_000100_add_review_to_pages` (seis columnas en `pages`), `Cms/Application/PageService` (`submitForReview`, `requestChanges`), `Page`, `PagePresenter`, `PageController` y dos rutas: `POST /pages/{id}/submit-review` y `POST /pages/{id}/request-changes`.
- Frontend: `cms/pages/PageEditorPage.tsx`, `cms/pages/PagesPage.tsx`, `cms/api.ts`; tono «advertencia» nuevo en los avisos compartidos.
- Prueba: un caso nuevo en `tests/Feature/Cms/PageTest.php` con el ciclo completo.

## Verificación

- Backend: 313 pruebas pasan (suite completa). Tipos y lint del frontend sin errores.
- En el navegador, como administrador: una página enviada a revisión muestra la marca, quién la envió y el botón «Devolver con observaciones»; al devolverla aparece el comentario. **No probé en el navegador el botón «Enviar a revisión»**, que solo ve quien redacta sin poder publicar: lo cubre la prueba automática.
- **Una migración nueva**: al desplegar, `php artisan migrate --force`. Sin variables ni permisos nuevos.
