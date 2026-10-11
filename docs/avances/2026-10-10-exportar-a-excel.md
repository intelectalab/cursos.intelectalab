# Reportes · Exportar a Excel

Fecha: 2026-10-10 · Sin commit al momento de escribir este documento

Cierra el requisito **RF-REP-001** del Documento Maestro, que estaba a medias: los reportes tenían filtros pero no se podían descargar. **Ahora el tablero y cuatro listados se descargan en Excel, con los mismos filtros que la pantalla.**

## Qué se construyó

En cada pantalla hay un botón **«Exportar a Excel»** (en Prospectos, «Exportar»). El archivo trae lo que se está viendo: si hay una búsqueda o un filtro puesto, baja solo eso.

| Pantalla | Archivo | Qué trae |
| --- | --- | --- |
| Reportes | `reporte-{desde}-a-{hasta}.xlsx` | Cinco hojas: Resumen (cada indicador, el periodo y el periodo anterior), Hoy, Por día, Cursos más vendidos y Finalización por curso |
| Ventas y pagos | `ordenes.xlsx` | Una fila por orden: número, estado, comprador, empresa, detalle, subtotal, descuento, cupón, total, fecha y medio de pago, quién lo cobró, número de operación y reembolso |
| Alumnos | `alumnos.xlsx` | Nombres, apellidos, correo, teléfono, documento, estado, cursos activos y completados, último ingreso y fecha de registro |
| Matrículas | `matriculas.xlsx` | Alumno, curso, situación, origen, avance, fechas de matrícula, de acceso, de término y de cancelación |
| CRM → Prospectos | `prospectos.xlsx` | Datos del prospecto, origen, interés, estado, motivo de descarte, responsable y fechas |

- Las fechas salen en la **hora de la academia**, no en UTC.
- Los estados salen con el mismo texto que la pantalla («Pagada», «Por iniciar», «Contactado»).
- Los importes salen como números, para poder sumarlos.
- Cada listado pide **el mismo permiso que su pantalla**. El tablero pide además **«Exportar reportes»** (`reports.export`), un permiso que el Maestro ya definía y no tenía función; hoy lo tiene el Administrador. No hay permisos nuevos.

## Lo que decidí y se puede cambiar

1. **Tope de 5 000 filas por archivo.** Si hay más, baja las primeras 5 000 según el orden de la pantalla; se acota con los filtros. No avisa cuando se corta.
2. **Solo Excel (`.xlsx`)**, sin CSV.
3. **Qué pantallas.** Elegí las que jefatura y el backlog mencionan. No se exportan todavía: Docentes, Empresas, Oportunidades, Cotizaciones, Certificados, Soporte, Reclamos ni Cupones.

## Además: cuentas de prueba de Mercado Pago

La cuenta de un usuario de prueba de Mercado Pago ahora se muestra como **«Cuenta de prueba»**. Sus claves empiezan con `APP_USR-`, igual que las reales, así que se reconoce por el nombre de la cuenta (`TESTUSER…`). La página de pago no cambia: sigue siendo la normal, que es la que funcionó en el primer pago de prueba.

## Lo que quedó corto

- Sin aviso cuando el archivo se corta en 5 000 filas.
- Las exportaciones se generan al momento; con decenas de miles de filas convendría hacerlas en segundo plano.
- No se probó abrir los archivos en Excel de escritorio: se comprobaron leyéndolos con la misma librería que los escribe.

## Archivos

- Backend: `app/Shared/Export/ExcelExport.php` (nuevo), `app/Shared/Import/Spreadsheet.php` (libro con varias hojas), y los controladores de Reportes, Alumnos, Órdenes, Matrículas y Prospectos, con sus rutas `…/export`.
- Frontend: `shared/ui/ExportButton.tsx` (nuevo) y las cinco pantallas.
- Pruebas: `tests/Feature/Reports/ExcelExportTest.php` (nuevo) y un caso en `Sales/OnlinePaymentTest.php`.

## Verificación

- Backend: **312 pruebas pasan** (suite completa). Estilo sin observaciones.
- Frontend: tipos y lint sin errores.
- En el navegador, como administrador de la demo: las cinco descargas respondieron correctamente.
- Sin migraciones, variables ni permisos nuevos. Cinco rutas nuevas.
