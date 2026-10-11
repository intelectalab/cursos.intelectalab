# Certificados · Editor visual (entrega 2: plantillas por curso y diseño congelado)

Fecha: 2026-10-10 · Sin commit al momento de escribir este documento

Completa las dos recomendaciones que la entrega 1 del editor dejó pendientes. **Una academia puede tener varias plantillas de certificado y decidir qué cursos usan cada una, y un certificado ya emitido conserva para siempre el diseño con el que salió.**

## Varias plantillas

En **Certificados → Plantilla** (ahora «Plantillas de certificado») hay un selector arriba:

- **«Nueva plantilla»**: se crea en blanco o como copia de la que está elegida (con su firmante, firma, fondo y diseño).
- **«Renombrar»**, **«Hacer principal»** y **«Eliminar»**.
- **Una plantilla es la principal**: la usan todos los cursos que no eligieron otra. No se puede eliminar; para cambiarla se marca otra como principal.
- En las demás plantillas aparece **«Cursos que usan esta plantilla»**: una lista de los cursos que entregan certificado, con casillas. Se guarda al marcar. Si un curso ya usa otra plantilla, lo dice.
- Cada plantilla tiene lo suyo: firmante, firma, fondo, diseño por opciones y diseño libre del editor visual.
- Hasta 20 plantillas por academia.

Al emitir, el certificado toma la plantilla de su curso: el diseño y también **quién firma**.

## Diseño congelado al emitir

- Cada certificado guarda, al emitirse, el diseño completo con el que sale: plantilla, diseño por opciones, diseño libre, fondo y firma.
- **Cambiar o eliminar la plantilla después no lo altera.** Lo mismo si se cambia el fondo o la firma: el archivo anterior se conserva mientras algún certificado emitido lo use.
- **Los certificados emitidos antes de este cambio** quedaron congelados con el diseño que la academia tenía al aplicar la actualización.
- El logo de la academia no se congela: el certificado muestra siempre el logo vigente.

Esto cambia un comportamiento anterior: antes, un cambio de diseño alcanzaba a los certificados ya emitidos.

## Lo que decidí y se puede cambiar

1. **La asignación se hace desde la plantilla**, no desde la ficha del curso. Así todo lo del certificado está en un lugar.
2. **Una copia comparte los archivos** (fondo, firma, imágenes) con la original hasta que se cambien en una de las dos.
3. **Al eliminar una plantilla, sus cursos pasan a la principal.**
4. **No hay forma de «actualizar» un certificado ya emitido al diseño nuevo.** Si hace falta, se anula y se emite otra vez.

## Lo que quedó corto

- La ficha del curso no muestra qué plantilla usa.
- No se puede elegir plantilla al emitir un certificado a mano: siempre es la del curso.
- Sin vista en miniatura de cada plantilla en el selector.
- Sigue pendiente de la entrega 1: tipografía caligráfica, deshacer, capas y formas.

## Archivos

- Backend: migración `2026_11_08_000100_add_templates_per_course_and_frozen_design` (`certificate_templates.name` e `is_default`, `courses.certificate_template_id`, `certificates.design`, y congela los certificados existentes); `CertificateService` (`template($id)`, `templateFor($curso)`, `fileInUse`); `CertificateTemplate::frozen()`; `CertificatePdf` dibuja desde el diseño congelado; `CertificateController` (`templates`, `storeTemplate`, `renameTemplate`, `makeDefault`, `destroyTemplate`; las demás operaciones aceptan `template_id`).
- Rutas nuevas: `GET` y `POST /certificates/templates`, `PUT` y `DELETE /certificates/templates/{id}`, `POST /certificates/templates/{id}/default`.
- Frontend: `certificates/components/CertificateDialogs.tsx` (gestor de plantillas), `certificates/api.ts` (`templateApi`), `CertificateDesign.tsx` y `CertificateEditorPage.tsx` (trabajan sobre la plantilla elegida; el editor recibe `?template=`).
- Prueba: `test_each_course_uses_its_template_and_an_issued_certificate_keeps_its_design`.

## Verificación

- Backend: **315 pruebas pasan** (suite completa). Estilo sin observaciones.
- Frontend: tipos, lint y compilación sin errores.
- En el navegador, como administrador de la demo: se creó una plantilla como copia (trajo el firmante), se le asignó un curso, el enlace al editor visual apuntó a esa plantilla y se eliminó. La demo quedó con su única plantilla.
- **No probé en el navegador** descargar un certificado antiguo después de cambiar el diseño; lo cubre la prueba automática.
- **Una migración nueva**: al desplegar, `php artisan migrate --force`. Conviene avisar a las academias que desde esta versión los certificados emitidos ya no cambian con el diseño.
