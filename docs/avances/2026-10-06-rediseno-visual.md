# Rediseño visual del back-office

Fecha: 2026-10-06 · Sin commit al momento de escribir este documento

## Qué se hizo

- **Paleta de marca IntelectaLab** definida una sola vez en `frontend/src/index.css`: escala `brand-50…950` (del azul marino del logo al celeste), fondo `canvas` y sombras `card`, `raised`, `pop`.
- **Tipografía Inter**, instalada en el proyecto (`@fontsource-variable/inter`); tamaño base de 13–14 px.
- **Componentes compartidos** en `frontend/src/shared/ui`: botones, cabecera de página, tarjetas, insignias con punto, avatares, tarjetas de indicador, tabla, paginación, campos, modal y estados (cargando, vacío, error).
- **Menú lateral** agrupado en General, Operación y Administración, con el usuario al pie.
- **Cabecera** con ubicación (academia › sección), subdominio y cuenta.
- **Inicio** con bienvenida, indicadores reales (usuarios, roles, permisos) y accesos rápidos según el rol.
- **Módulos pendientes** (Académico, CRM, Ventas, Reportes) con pantalla "En construcción".
- **Pantallas de una tarjeta** (recuperar contraseña, acceso de plataforma, academia no encontrada) con el fondo de marca.

## Regla para pantallas nuevas

Las pantallas componen las piezas de `shared/ui` y usan los nombres de la paleta (`brand-*`, `canvas`). No definen colores, tamaños ni sombras propios.

## Verificación

- Revisado en el navegador: inicio, usuarios, roles, configuración, auditoría, mi cuenta, recuperación de contraseña y consola de plataforma.
- El frontend compila y pasa lint.

## Cambios que afectan a la instalación

- Dependencia nueva del frontend: `@fontsource-variable/inter`.

## Pendiente

- Vista móvil: el menú lateral no se convierte en panel desplegable; ninguna pantalla se revisó en tamaño móvil.
- Tema oscuro.
- Login y registro conservan sus estilos originales; falta alinearlos con los componentes compartidos.
