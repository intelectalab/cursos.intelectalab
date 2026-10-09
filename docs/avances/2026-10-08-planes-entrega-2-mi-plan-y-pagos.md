# Planes de la plataforma · Entrega 2: "Mi plan" y pagos de las academias

Fecha: 2026-10-08 · Sin commit al momento de escribir este documento

Segunda de las tres entregas para que IntelectaLab cobre a las academias. Con esta, **la academia le paga su plan a IntelectaLab con el mismo recorrido con el que un alumno le paga un curso a la academia**: pide lo que quiere, ve cómo pagar, paga por fuera y quien recibe el dinero confirma el pago.

Sigue sin bloquearse nada: los límites y el vencimiento son la entrega 3. El cobro en línea llegará con las pasarelas.

## Qué se construyó

### "Mi plan" (dentro de la academia)

Pantalla nueva, a la que se llega desde **Configuración → "Ver planes y pagos"**.

- **Su situación:** la misma tarjeta de la entrega 1 (prueba o plan, hasta cuándo y cupo de alumnos usado).
- **Planes disponibles:** los que la plataforma ofrece, con precio, periodo y tope de alumnos. Marca cuál es el suyo y avisa si un plan le queda chico para los alumnos que ya tiene.
- **Elegir un plan** (o renovar el suyo) crea una **orden de pago**, con su número (P-0001…).
- **La orden pendiente** se muestra arriba, con:
  - qué plan es y cuánto cuesta;
  - **cómo pagar**: las instrucciones que publica IntelectaLab;
  - un campo para **informar el pago** ("Transferencia BCP, operación 123456…"), que ayuda a ubicarlo;
  - la opción de **cancelarla**.
- Solo puede haber **una orden pendiente** a la vez. Pedir un plan no lo activa: cambia cuando IntelectaLab confirma el pago.
- **Pagos anteriores:** las órdenes pagadas y canceladas.

Ve la pantalla quien puede ver la Configuración; pide planes, informa y cancela quien puede cambiarla (el Administrador).

### "Pagos" (consola de plataforma)

Tercera sección de la consola, junto a Academias y Planes.

- Lista las órdenes de plan de **todas las academias**, con tarjetas que filtran: por confirmar, pagadas y canceladas. Abre en "por confirmar", de la más antigua a la más nueva.
- Cada fila muestra la academia (con enlace a su ficha), el plan, el importe y lo que la academia informó de su pago.
- **Confirmar pago:** se indica el medio (transferencia, Yape o Plin, efectivo, otro), el número de operación y una nota interna (por ejemplo, el número de la factura emitida). **Al confirmar, el plan de la academia queda vigente en ese momento**, sin más pasos.
- **Cancelar:** con motivo, que la academia ve en sus pagos anteriores.

### Cómo pagan las academias (consola → Planes)

- Al pie de la sección Planes, el super administrador escribe las **instrucciones de pago** de IntelectaLab: cuenta, Yape, a nombre de quién y a dónde enviar la constancia. Es lo que ve la academia en su orden pendiente.

### Reglas

- **Renovar el mismo plan antes de que venza suma el periodo al vencimiento actual** (no se pierde lo ya pagado). En cualquier otro caso (plan distinto, plan vencido o venía de la prueba) el periodo empieza el día en que se confirma el pago.
- La orden guarda el precio y el tope **con los que se pidió**: si después se cambia el plan, la academia recibe lo que compró.
- Un plan archivado no se puede pedir.
- Todo queda en la auditoría de la academia: la orden creada, cancelada y confirmada.

## Detalle técnico

- Una migración (`2026_11_01_000100_create_plan_orders_and_platform_settings`): tablas `plan_orders` (con aislamiento por academia) y `platform_settings` (ajustes de la plataforma, sin academia). La numeración de las órdenes es única para toda la plataforma.
- Rutas nuevas:
  - Academia: `GET /subscription/plans`, `POST /subscription/orders`, `PUT /subscription/orders/{id}/payment`, `POST /subscription/orders/{id}/cancel`.
  - Plataforma: `GET /platform/plan-orders`, `POST /platform/plan-orders/{id}/confirm`, `POST /platform/plan-orders/{id}/cancel`, `GET` y `PUT /platform/billing-settings`.
- Pantallas nuevas: `/settings/plan` en la academia y `/platform/payments` en la consola.
- Sin dependencias nuevas, sin permisos nuevos ni variables de entorno.

## Qué se verificó

- **280 pruebas del backend pasan** (eran 278).
- Pruebas nuevas (2):
  - El recorrido completo: instrucciones de pago, la academia pide un plan, no puede pedir otro mientras tanto, informa su pago, la plataforma lo ve y lo confirma, el plan queda vigente un mes con su tope, no se confirma dos veces, y renovar suma el mes al vencimiento.
  - Cancelaciones y aislamiento: otra academia no ve ni toca la orden; cancela la academia o la plataforma (con motivo); una cancelada no se confirma; un plan archivado no se pide; un alumno no pide planes y una academia no entra a la consola.
- La aplicación web compila y pasa la revisión de tipos y de estilo.
- En el navegador, el recorrido completo en local:
  - En la consola se guardaron instrucciones de pago de prueba.
  - En la academia demo, "Mi plan" mostró los dos planes; al elegir "Hasta 50 alumnos" se creó la orden P-0001 con las instrucciones, y los botones de elegir quedaron deshabilitados; se informó un número de operación.
  - En la consola, "Pagos" mostró la orden con lo informado; al confirmarla, la academia pasó a "Hasta 50 alumnos · 21 de 50 alumnos", pagado hasta el 8 de noviembre.
  - Al terminar se devolvió la academia demo a "Sin plan", para que no le aplique el vencimiento de la entrega 3.

Durante el trabajo se corrigieron dos fallos que detectaron las pruebas: la numeración de órdenes sobrevivía a un reinicio de la base, y la consola no encontraba una orden al confirmarla por el aislamiento entre academias.

## Qué no se probó

- En el navegador no se canceló una orden (ni desde la academia ni desde la consola) ni se renovó un plan: van por prueba automática.
- La vista en teléfono de las pantallas nuevas.

## Qué quedó corto

- **No hay cobro en línea:** la academia paga por fuera y alguien confirma. Llega con las pasarelas.
- **No se adjunta la constancia** como archivo: la academia escribe cómo ubicar su pago.
- **Sin comprobante:** la factura de IntelectaLab se emite por fuera; se puede anotar su número en la nota interna.
- **No se avisa a nadie:** ni a la plataforma cuando llega una orden, ni a la academia cuando se confirma. No hay correo ni avisos dentro del sistema.
- Una orden pendiente **no vence sola**: queda hasta que alguien la confirme o la cancele.
- Confirmar un pago **no se puede deshacer** desde la pantalla (sí se puede reasignar el plan desde la ficha).
- No hay prorrateo al cambiar a un plan mayor a mitad de periodo: el nuevo periodo empieza al confirmar.
- La consola no muestra todavía cuánto ingresó la plataforma por planes.

## Qué se decidió sin preguntar

1. **Renovar el mismo plan antes de vencer suma al vencimiento actual.**
2. **La orden conserva el precio y el tope con que se pidió.**
3. **Una sola orden pendiente por academia.**
4. **Las instrucciones de pago las escribe el super administrador** en la sección Planes.
5. **Cancelar desde la plataforma exige motivo**; la academia cancela la suya sin motivo.
6. **La orden pendiente no vence sola.**

Las seis se pueden cambiar.

En la base local quedó: las instrucciones de pago de prueba, la orden P-0001 pagada de la academia demo, y la academia demo de vuelta en "Sin plan".
