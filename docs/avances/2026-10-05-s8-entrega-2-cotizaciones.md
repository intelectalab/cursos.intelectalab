# S8 — Empresas y CRM · Entrega 2: cotizaciones

Fecha: 2026-10-05 · Sin commit al momento de escribir este documento

Segunda entrega de S8. El equipo comercial arma la **cotización** de una empresa (cursos, cantidad de licencias y precio pactado) y, cuando el cliente la acepta, nace la **venta** (Maestro RF-B2B-001, FP-04, US-B2B-001, RN-011).

## Qué se construyó

En **CRM y clientes** aparece la pestaña **Cotizaciones**.

### Armar la cotización

- Se elige la empresa, un contacto y, si se quiere, la oportunidad a la que responde.
- Por cada curso: **cuántas licencias** y **precio por licencia**. El sistema propone el precio de lista del curso y el vendedor lo cambia a mano: así da el descuento por volumen. No hay reglas automáticas de descuento.
- Precios finales en la moneda de la academia, sin impuestos adicionales (igual que en la venta a personas).
- Solo se cotizan cursos publicados; un curso aparece una sola vez por cotización.
- **Vigencia**: 15 días por defecto, editable. Notas o condiciones para el cliente.
- Cada cotización recibe un número correlativo por academia (COT-0001).

### Estados

| Estado | Qué significa | Qué se puede hacer |
| --- | --- | --- |
| Borrador | Se está armando | Editar, marcar como enviada |
| Enviada | El cliente ya la tiene | Registrar que aceptó o rechazó, abrir una versión nueva |
| Vencida | Enviada y pasó su vigencia | Registrar rechazo, abrir una versión nueva (no se puede aceptar) |
| Rechazada | El cliente dijo que no, con motivo | Abrir una versión nueva con otra propuesta |
| Aceptada | El cliente dijo que sí | Nada más: ya generó su venta |

"Marcar como enviada" no envía ningún correo (no hay proveedor de correo todavía): registra que el vendedor ya se la hizo llegar al cliente.

### Versiones (RN-011)

Una cotización enviada no se edita. Para cambiarla se abre una **versión nueva**: vuelve a borrador como versión 2 y la anterior queda guardada, de solo lectura, tal como la vio el cliente. Mientras la versión nueva está en borrador no hay nada que aceptar.

### Aceptación y venta

Al registrar la aceptación:

1. Se crea una **orden** en *Ventas y pagos* a nombre de la empresa, con los cursos, cantidades y precios de la versión aceptada. Si después cambia el precio de lista del curso, la orden no cambia.
2. La empresa pasa de "Prospecto" a **Cliente** y se crea su cuenta corporativa.
3. Si la cotización estaba ligada a una oportunidad, esta pasa a **Ganada** (y al enviar la cotización había pasado sola a "Propuesta enviada").

La orden de una empresa **no vence a las 72 horas** como la de una persona: queda pendiente hasta que el personal confirme el pago o la cancele. Al pagarse no matricula a nadie; entregará las licencias (entrega 3).

En esta entrega la aceptación la registra el personal ("aceptó por correo"). La aceptación en línea del Responsable de empresa llega con su portal, en la entrega 3.

### Quién puede usarlo

| Permiso | Qué permite | Roles base |
| --- | --- | --- |
| `quotations.read` | Ver cotizaciones | Administrador, Ventas/CRM, B2B autorizado |
| `quotations.create` | Crear, editar, enviar y abrir versiones | Administrador, Ventas/CRM, B2B autorizado |
| `quotations.accept` | Registrar la aceptación o el rechazo del cliente | Administrador, Ventas/CRM, B2B autorizado |

`quotations.read` y `quotations.accept` son nuevos; los tres roles ya los cubrían con `quotations.*`. El rol B2B autorizado, que no lleva el CRM, ahora puede ver la lista de empresas para poder cotizarles.

## Detalle técnico

- **Módulo nuevo** `backend/app/Modules/B2b`. Tablas: `quotations`, `quotation_items` (Maestro §5.1), `quotation_versions` (copia de cada envío; la pide RN-011) y `corporate_accounts`.
- **Cambio en `orders`** (S5): `customer_id` pasa a ser opcional y se agrega `company_id`; una orden tiene comprador persona o empresa (restricción en la base). La API de órdenes devuelve `customer` (puede ser `null`) y `company`.
- `OrderService::placeForCompany()` crea la orden corporativa. Al pagarse cualquier orden se emite el evento `OrderPaid`, dentro de la misma transacción.
- **Rutas**: `GET/POST /api/v1/quotations`, `GET /quotations/options`, `GET/PUT /quotations/{id}` y las acciones `send`, `revise`, `accept`, `reject`.
- Auditoría: creación, envío, versión nueva, aceptación y rechazo. Códigos de error nuevos: `QUOTATION_INVALID_STATE`, `QUOTATION_INCOMPLETE`, `QUOTATION_EXPIRED`.
- El componente `Modal` compartido acepta `size="wide"` para contenido con tablas.

## Qué se verificó

- **179 pruebas del backend pasan**, 6 nuevas (`tests/Feature/B2b/QuotationTest.php`): recorrido completo hasta la orden con los precios cotizados aunque cambie el precio de lista, versiones (la 1 se conserva, la venta sale de la 2), vencimiento, validaciones, pago de una orden corporativa sin matricular a nadie, permisos por rol y aislamiento entre academias. Las pruebas de S5 siguen pasando con el cambio en las órdenes.
- El frontend compila y pasa el lint.
- **En el navegador, academia demo**: cotización COT-0001 a Textiles del Sur (25 licencias de "Excel para finanzas" a S/ 100), marcada como enviada, aceptada; la orden N.º 00004 aparece en *Ventas y pagos* como "Venta a empresa".
- **No se probó en el navegador**: versión nueva, rechazo, cotización con varios cursos, ni el tamaño de teléfono.

## Qué quedó corto

- **Sin PDF ni correo** de la cotización: el vendedor la comunica por fuera. (El Responsable de empresa la verá en su portal, entrega 3.)
- **Sin impuestos, sin descuento global y sin segunda moneda**; el descuento es el precio que pone el vendedor en cada curso.
- **Sin pagos parciales ni crédito** formal: la orden se paga completa (deudas y adelantos son de Finanzas, S6).
- No se cotizan **paquetes** de cursos (S11).
- Un borrador no se elimina ni se anula; una cotización aceptada no se revierte desde aquí (habría que cancelar su orden).
- La vigencia se evalúa con la fecha del servidor, no con la zona horaria de la academia.
- En *Ventas y pagos* no hay filtro para separar ventas a personas y a empresas.
