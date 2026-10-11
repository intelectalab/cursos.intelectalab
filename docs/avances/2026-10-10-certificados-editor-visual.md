# Certificados · Editor visual (entrega 1: el lienzo)

Fecha: 2026-10-10 · Sin commit al momento de escribir este documento

El responsable del proyecto pidió que el diseño del certificado fuera «más como Canva: algo que se pueda mover y editar, no solo una lista de opciones». **Ahora hay un editor visual: una hoja donde cada dato, texto o imagen se arrastra a su sitio y se edita.** El diseño por lista de opciones sigue existiendo y es el que vale mientras la academia no guarde un diseño libre.

## Cómo se usa

Se entra desde **Certificados → Plantilla → «Probar el editor visual»**, o directo en `/certificates/design`. Lo usa quien administra la configuración de la academia.

- **La hoja** se ve con las proporciones reales del PDF, con el fondo propio de la academia o, si no tiene, el marco del sistema. Los datos que se muestran son de ejemplo.
- **Mover:** se arrastra el elemento. Cerca del centro de la hoja se imanta y aparece una guía. Con las flechas del teclado se mueve medio milímetro (con Mayús, cinco).
- **Cambiar el tamaño:** se tira del cuadradito del borde. En las imágenes cambia ancho y alto; en los textos, el ancho de la caja.
- **Editar:** al tocar un elemento, el panel de la derecha muestra sus opciones: tipografía, tamaño, color, negrita, cursiva, mayúsculas, alineación y posición exacta en milímetros.
- **Agregar:** cualquier dato del certificado (alumno, documento, curso, duración, fecha, academia, quien firma y su cargo, código, dirección de verificación), **textos libres**, el logo, la firma, e **imágenes propias** que se suben (un sello, otro logo, otra firma).
- **Duplicar** y **quitar** elementos; «Centrar en la hoja»; «Empezar de nuevo».
- **Hoja A4 horizontal o vertical.**
- **«Vista previa en PDF»:** genera el PDF real con lo que hay en pantalla, sin necesidad de guardar.
- **«Guardar diseño»:** desde ese momento los certificados salen así.
- **«Volver al diseño por opciones»:** borra el diseño libre y regresa al anterior.

## Reglas

- **El código de verificación y el QR se pueden mover y achicar, pero no quitar.** Sin ellos el certificado no se puede comprobar; el servidor rechaza un diseño que no los tenga.
- Un dato sin valor no se dibuja (por ejemplo, el documento de un alumno que no lo registró, o la firma si no hay ninguna cargada).
- Las imágenes entran completas en su caja, sin deformarse.
- Hasta 40 elementos por diseño. Lo que queda fuera de la hoja se trae al borde.
- Las imágenes propias son privadas: solo las ve el personal que gestiona certificados y el PDF.

## Las cinco recomendaciones: qué entró y qué no

El responsable respondió «donde tú veas», así que seguí mis recomendaciones. **Dos de las cinco quedan para la entrega 2 del editor:**

| # | Recomendación | Estado |
| --- | --- | --- |
| 1 | Tipografías fijas, sin subir fuentes propias | **Hecho con seis**, las que el generador de PDF trae: Helvetica, Times, Courier y tres DejaVu. **No hay ninguna caligráfica**: agregarla exige instalar un archivo de fuente en el proyecto, y eso necesita aprobación (es una dependencia nueva) |
| 2 | Varias plantillas, y que cada curso elija la suya | **Pendiente.** Sigue habiendo una plantilla por academia |
| 3 | Congelar el diseño al emitir | **Pendiente.** Un cambio de diseño sigue alcanzando a los certificados ya emitidos, como hoy |
| 4 | Código y QR obligatorios | Hecho |
| 5 | A4 horizontal y vertical | Hecho |

## Lo que quedó corto

- **Sin «deshacer».** Para volver atrás está «Empezar de nuevo» o salir sin guardar.
- **Sin capas:** los elementos se apilan en el orden en que se agregaron; no hay «traer al frente».
- **Sin guías entre elementos:** el imán es solo al centro de la hoja.
- Sin formas (líneas, rectángulos), rotación ni efectos.
- Las tipografías del lienzo son parecidas a las del PDF, no idénticas; la vista previa en PDF sí es exacta.
- Las imágenes subidas que luego se quitan del diseño quedan guardadas sin uso.
- El editor está pensado para computadora. **No lo probé en teléfono ni con pantalla táctil.**
- El arrastre lo probé con eventos de puntero simulados, no moviendo el ratón a mano.

## Archivos

- Backend: `Certificates/Domain/CertificateLayout.php` (nuevo: elementos, reglas y diseño de partida), `Certificates/Application/CertificatePdf.php` (dibuja por coordenadas), `resources/views/certificates/canvas.blade.php` (nuevo), `CertificateController` (`updateLayout`, `uploadImage`, `image`, `background`, vista previa con el diseño en pantalla), migración `2026_11_07_000100_add_layout_to_certificate_templates` (columna `layout`), uso de archivos `certificate_image` en `config/files.php`.
- Rutas nuevas: `PUT /certificates/template/layout`, `POST /certificates/template/images`, `GET /certificates/template/images/{id}`, `GET /certificates/template/background`, `POST /certificates/template/preview`.
- Frontend: `certificates/pages/CertificateEditorPage.tsx` (nuevo), ruta `/certificates/design`, enlace en `CertificateDesign.tsx`, `certificates/api.ts`.
- Prueba: `test_the_organization_places_each_element_of_its_certificate_freely` en `Certificates/CertificateTest.php`.

## Verificación

- Backend: **314 pruebas pasan** (suite completa). Estilo sin observaciones.
- Frontend: tipos, lint y compilación sin errores.
- En el navegador, como administrador de la demo: la hoja dibuja los 16 elementos de partida; se arrastró el nombre del alumno, se cambió su tamaño de letra, se agregó un texto, se guardó y se generó la vista previa en PDF, que reflejó los cambios. El QR no ofrece «Quitar» ni «Duplicar».
- La demo quedó como estaba: sin diseño libre guardado.
- **Una migración nueva**: al desplegar, `php artisan migrate --force`. Sin variables, permisos ni dependencias nuevas.
