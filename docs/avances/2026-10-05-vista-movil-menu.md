# Vista móvil del menú lateral

Fecha: 2026-10-05 · Sin commit al momento de escribir este documento

## Qué se construyó

- **Panel desplegable en móvil y tableta (menos de 1024 px).** El menú lateral ya no ocupa espacio junto al contenido: queda oculto y el botón de la cabecera lo abre deslizándose desde la izquierda, sobre la pantalla, con el fondo oscurecido.
- **Cierre del panel:** tocando el fondo, con la tecla Escape, con el botón "X" del panel o al elegir una opción del menú.
- **Escritorio (1024 px o más):** sin cambios. El botón sigue contrayendo el menú a íconos.
- **Accesibilidad:** al abrir, el foco entra al panel y no sale con Tab; al cerrar vuelve al botón que lo abrió. Con el panel cerrado sus enlaces no reciben foco. El botón anuncia "Abrir menú" o "Cerrar menú" y su estado.
- **Cabecera:** márgenes más ajustados en pantallas angostas.
- **Ventanas modales (corrección):** se abrían dentro del área de contenido, así que el fondo oscuro no cubría la cabecera y, en pantallas con poco contenido o en el teléfono, el título quedaba tapado. Ahora se montan sobre toda la ventana.
- **Formulario de usuario:** en el teléfono los campos y los roles van en una columna (antes en dos, con etiquetas partidas).
- **Alto de pantalla:** el back-office usa el alto real visible del teléfono (descuenta la barra del navegador).

## Archivos

| Archivo | Cambio |
| --- | --- |
| `frontend/src/layouts/backoffice/AppLayout.tsx` | Estado del panel y del menú contraído según el tamaño de pantalla |
| `frontend/src/layouts/backoffice/Sidebar.tsx` | Panel desplegable, fondo, botón de cierre, manejo del foco |
| `frontend/src/layouts/backoffice/Header.tsx` | Estado del botón de menú y espaciado en móvil |
| `frontend/src/shared/ui/useMediaQuery.ts` | Nuevo: detecta el punto de corte de escritorio (1024 px) |
| `frontend/src/shared/ui/index.tsx` | `Modal` montado sobre toda la ventana |
| `frontend/src/features/identity/pages/UsersPage.tsx` | Formulario en una columna en móvil |

## Regla para pantallas nuevas

El punto de corte entre panel desplegable y menú fijo es `lg` (1024 px). Las pantallas deben verse bien a 375 px: rejillas de una columna por defecto (`grid-cols-1 sm:grid-cols-2`) y tablas dentro de `Table`, que ya trae desplazamiento horizontal propio.

## Verificación

- En el navegador a 375 px (teléfono), 768 px (tableta) y 1280 px (escritorio): abrir y cerrar el panel (botón, Escape, navegación), foco, y contraer el menú en escritorio.
- Revisadas a 375 px sin desbordes horizontales: Inicio, Usuarios (con el formulario de alta), Roles, Configuración, Auditoría y Mi cuenta.
- `npx tsc -b`, `npm run lint` y `npm run build` pasan.

## Cambios que afectan a la instalación

Ninguno: sin dependencias, variables ni migraciones nuevas.

## Pendiente

- Las tablas de Usuarios y Auditoría se desplazan horizontalmente en el teléfono; no se convirtieron en tarjetas.
- Login, registro y consola de plataforma no se revisaron en este bloque.
- No se probó en un teléfono real, solo en el navegador con tamaño emulado.
