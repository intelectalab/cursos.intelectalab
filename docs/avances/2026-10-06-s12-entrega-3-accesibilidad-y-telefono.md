# S12 — Endurecimiento · Entrega 3: accesibilidad y revisión en teléfono

Fecha: 2026-10-06 · Sin commit al momento de escribir este documento

Tercera entrega de S12 (Maestro RNF-ACC-001, RNF-COMP-001 y §21: "el comportamiento responsive está validado"). Objetivo acordado: lo esencial de WCAG 2.1 nivel AA (teclado, etiquetas, contraste y foco visible), y revisar las pantallas a 375 px (teléfono), 768 px (tableta) y escritorio.

## Qué se corrigió

### Teclado

- **Ventanas emergentes.** Al abrir una ventana, el foco entra en ella; la tecla Tab ya no se escapa a la pantalla de atrás; al cerrarla (también con Escape), el foco vuelve al botón que la abrió. Se arregló en la pieza compartida, así que vale para todas las ventanas del sistema.
- **"Saltar al contenido".** Es el primer elemento al tabular dentro de la aplicación: evita recorrer todo el menú para llegar a la pantalla.
- **Mostrar u ocultar contraseña** (inicio de sesión y registro de academias): antes no se alcanzaba con el teclado ni tenía nombre para lectores de pantalla. Ahora sí, y su zona para tocar es más grande.

### Etiquetas y títulos

- Los campos del **inicio de sesión** y del **registro de academias** no estaban enlazados a su etiqueta: un lector de pantalla los anunciaba sin nombre. Corregido (9 campos).
- **El título de la pestaña del navegador** ahora dice en qué pantalla se está y de qué academia: "Matrículas · Organización Demo". Antes siempre decía "IntelectaLab". Ayuda a quien usa lector de pantalla y a cualquiera que tenga varias pestañas abiertas.

### Contraste

- El gris claro que se usaba para textos secundarios e íconos de acción (74 lugares) no alcanzaba el contraste mínimo sobre fondo blanco (2,6 a 1; se pide 4,5 a 1). Se pasó al tono siguiente (4,8 a 1). El cambio visual es leve.

## Qué se revisó

Con una rutina de revisión que se ejecutó en cada pantalla, a 375 px y a 768 px. Comprueba: que nada se salga del ancho, campos sin etiqueta, botones y enlaces sin nombre, imágenes sin texto alternativo, que haya un encabezado principal y, donde el fondo es liso, el contraste del texto.

| Grupo | Pantallas |
| --- | --- |
| Sin sesión | Inicio de sesión, registro del comprador, recuperar contraseña, catálogo, ficha de curso, ficha de paquete, verificación de certificados, Libro de Reclamaciones (formulario y seguimiento), página publicada |
| Dominio central | Registro de academias, acceso de plataforma |
| Alumno | Inicio, mis cursos, aula (video, cuestionario, tarea, encuesta, documento), cursos disponibles, checkout de curso y de paquete, mis compras y detalle de orden, mis certificados, ayuda, mi cuenta |
| Personal | Inicio, usuarios, roles, configuración, auditoría, cursos, editor de curso (contenido, profesores, seguimiento, versiones), matrículas, banco de preguntas, CRM (prospectos, empresas, oportunidades, cotizaciones), ventas y pagos, promociones, certificados, soporte, reclamos, reportes, páginas |
| Ventanas | Nuevo usuario, nuevo curso, nueva promoción, nuevo banco, nueva página, matricular alumnos |

**Resultado:** ninguna pantalla se sale del ancho en teléfono ni en tableta; las ventanas caben (351 px de 375). Los únicos hallazgos fueron los corregidos arriba.

**Sobre las tablas en el teléfono (decisión 4):** las pantallas del alumno y del comprador (mis cursos, mis compras, mis certificados, ayuda) ya estaban hechas con tarjetas, no con tablas, así que no hubo que convertir nada. Las tablas del personal siguen desplazándose de lado, como se acordó.

## Cómo se verificó

- La rutina de revisión en el navegador integrado, con sesión de administrador y de alumno en la academia demo.
- Se abrió una ventana con el teclado y se comprobó que el foco entra, y que al cerrarla vuelve al botón de origen.
- La aplicación compila y pasa la revisión de tipos y de estilo.

## Qué no se probó

- **No hay capturas de pantalla:** el navegador integrado no logró tomarlas en esta sesión. La revisión fue por medidas y estructura, no visual. Conviene que alguien mire las pantallas en un teléfono real cuando haya servidor (S13).
- No se probó con un lector de pantalla real (NVDA, VoiceOver) ni en Safari o Firefox: solo en el navegador integrado, que es Chromium.
- El contraste solo se midió automáticamente sobre fondos lisos. Los textos sobre degradados (inicio de sesión, cabecera del inicio, menú lateral) se revisaron a ojo en el código, no con medición.
- No se revisaron en teléfono: el portal del Responsable de empresa, el detalle de una cuenta corporativa, el editor de páginas por dentro y las ventanas menos usadas (cotización, plantilla de certificado, confirmación de pago).

## Qué quedó corto

- El título de la pestaña del inicio de sesión toma el lema de la portada ("Toda tu operación, en un solo lugar") en vez de "Iniciar sesión".
- Varios enlaces de texto ("Mis cursos", los del pie público) miden 16 a 20 px de alto. WCAG 2.1 AA no lo exige; la versión 2.2 pide 24 px.
- El gráfico de Reportes no tiene una tabla alternativa para lector de pantalla.
- No hay pruebas automáticas de accesibilidad: una herramienta como axe necesitaría una dependencia nueva.
- Tema oscuro: sigue sin hacerse (opcional).
