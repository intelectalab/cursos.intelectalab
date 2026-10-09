# S6 — Pagos · Entrega 3: reembolso total

Fecha: 2026-10-09 · Sin commit al momento de escribir este documento

Última entrega de las pasarelas. **El Administrador puede devolver el importe completo de una compra pagada, y la plataforma puede devolver el pago en línea de un plan.** Si el pago fue en línea, el dinero se devuelve por la misma pasarela; si fue por fuera, se registra que ya se devolvió.

**Importante:** como en las entregas anteriores, lo que toca a Mercado Pago y PayPal está probado con respuestas simuladas. **No se hizo ninguna devolución real**: para eso hace falta primero un pago real o de prueba, y no hay credenciales.

Se construyó con la recomendación avisada: reembolso **total** (no parcial), hecho por el Administrador.

## Qué se construyó

### Reembolsar una compra (academia → Ventas y pagos)

- En una orden **pagada** de una persona aparece el botón **"Reembolsar"**, para quien tiene el permiso de reembolsar pagos (hoy, el Administrador).
- Pide un **motivo** (obligatorio), que queda en la orden y en la auditoría.
- **Si se pagó en línea** (Mercado Pago o PayPal): el sistema devuelve el importe completo por esa pasarela, con la cuenta de la academia, al medio con el que pagó el comprador. Si se cobró en dólares, se devuelven esos dólares.
- **Si se pagó por fuera:** la pantalla avisa que el sistema no mueve dinero; la academia devuelve el dinero por su cuenta y aquí lo registra, con un campo opcional para anotar cómo lo devolvió.
- Al reembolsar:
  - la orden pasa a **"Reembolsada"** (estado nuevo, con su tarjeta y su filtro);
  - **el comprador pierde la matrícula que le dio esa compra**; en un paquete, la de todos sus cursos;
  - la orden muestra cuándo, por qué, con qué referencia y quién lo hizo;
  - el comprador lo ve en "Mis compras" y **puede volver a comprar** el curso;
  - **deja de contar como ingreso** en Reportes, en la ficha del alumno y en la consola.
- No se reembolsa: una orden que no está pagada, una ya reembolsada, una **venta a empresa** (tiene licencias entregadas) ni una **inscripción gratuita** (no hay dinero; se cancela la matrícula).

### Reglas de seguridad

- **Primero el dinero, después la orden.** Si la pasarela no acepta la devolución (pago muy antiguo, saldo insuficiente, ya devuelto), la orden queda pagada, el alumno conserva su curso y se muestra lo que dijo la pasarela.
- **Repetir no devuelve dos veces**: ni en la pasarela ni en el sistema.
- Si la cuenta con la que se cobró ya no está conectada, se pide volver a conectarla.
- **Devolución hecha en el panel de la pasarela, fuera del sistema:** cuando la pasarela lo avisa, la orden queda marcada con una alerta para que también se reembolse aquí.

### Devolver el pago de un plan (consola → Pagos)

- Las órdenes de plan **cobradas en línea** tienen el botón **"Devolver pago"** (las confirmadas a mano siguen con "Deshacer").
- Devuelve el dinero por la pasarela con la cuenta de IntelectaLab, **el plan de la academia vuelve a como estaba antes de ese pago** y la orden queda cancelada con el motivo y el número de la devolución.
- La academia recibe el aviso fijo y un correo.
- **Si el plan de la academia cambió después de ese pago, no se devuelve nada**: se comprueba antes de tocar el dinero y se explica cómo resolverlo a mano.

## Detalle técnico

- **Una migración** (`2026_11_05_000100_add_refunds_to_orders`): `orders.refunded_at` y, en `payments`, `refunded_at`, `refunded_by`, `refund_reason` y `refund_reference`.
- Estados nuevos: orden `REFUNDED` y pago `REFUNDED`.
- `OnlineGateway::refund()` en las dos pasarelas (Mercado Pago: devolución del pago; PayPal: devolución de la captura), con clave de idempotencia.
- `OnlinePaymentService::refund()` decide si va por la pasarela; `OrderService::markRefunded()` cambia la orden y cancela las matrículas. `PlanOnlinePayments::refund()` y `PlanOrderService::revert()` (con devolución) para los planes.
- Rutas nuevas: `POST /orders/{id}/refund` (permiso `payments.refund`, que ya existía en el catálogo y nadie usaba) y `POST /platform/plan-orders/{id}/refund`.
- Código de error nuevo: `ORDER_NOT_REFUNDABLE` (409).
- Sin dependencias nuevas, sin permisos nuevos, sin variables de entorno.

## Qué se verificó

- **307 pruebas del backend pasan** (eran 301). Pruebas nuevas (6):
  - compra pagada por fuera: solo órdenes pagadas, con motivo y con permiso; queda reembolsada con sus datos; el alumno pierde el acceso y puede volver a comprar; no se reembolsa dos veces; una gratuita no se reembolsa;
  - compra pagada con Mercado Pago: si la pasarela rechaza, la orden sigue pagada y el alumno conserva el curso; si acepta, queda reembolsada con el número de la devolución, usando la cuenta de la academia y sin poder duplicarse;
  - devolución hecha en el panel de la pasarela: queda la alerta; sin la cuenta conectada no se puede devolver;
  - compra pagada con PayPal: se devuelve la captura;
  - plan pagado en línea: se devuelve, el plan vuelve a la prueba, la orden queda cancelada, correo a la academia y no se devuelve dos veces;
  - plan que cambió después: se rechaza sin llamar a la pasarela.
- Frontend: compila, sin errores de tipos ni de estilo.
- **En el navegador, con la demo:** en Ventas y pagos aparece la tarjeta "Reembolsadas"; en una orden pagada por fuera aparece "Reembolsar" y su formulario, con el aviso de que el dinero lo devuelve la academia. **No pulsé el botón final**, para no quitarle la matrícula a un alumno de la demo.

**No se probó:** un reembolso de punta a punta en el navegador; ninguna devolución real en Mercado Pago ni en PayPal; el botón "Devolver pago" de la consola en el navegador (solo aparece con un plan pagado en línea).

## Qué quedó corto

- **Solo reembolso total.** No hay reembolso parcial ni de un solo curso de un paquete.
- **No hay plazo ni condiciones**: se puede reembolsar una compra de cualquier antigüedad, aunque el alumno ya haya terminado el curso. La política la aplica la persona.
- **El certificado ya emitido no se anula solo**: si el alumno completó el curso, hay que anularlo a mano.
- El cupón usado en la compra no se libera.
- Las ventas a empresas no se reembolsan desde el sistema.
- Las cifras de periodos pasados cambian al reembolsar (la orden deja de contar como ingreso), y Reportes no muestra los reembolsos como dato propio.
- Sin correo al alumno cuando se reembolsa su compra (depende de la decisión 3); lo ve en "Mis compras".
- Si la pasarela devuelve el dinero y justo después falla el guardado, la orden quedaría pagada con el dinero ya devuelto. Repetir el reembolso lo resuelve (la pasarela no devuelve dos veces), pero no hay un aviso automático de ese caso.
- Los comprobantes electrónicos y las notas de crédito siguen fuera del sistema.

## Decidido sin preguntar (se puede cambiar)

1. **Reembolsar quita la matrícula** que dio la compra.
2. **Un pago por fuera también se puede reembolsar**, como registro: el dinero lo devuelve la academia.
3. **La orden reembolsada deja de contar como ingreso**, también en periodos ya cerrados.
4. **Usa el permiso "Reembolsar pagos"**, que hoy solo tiene el Administrador; se puede dar a otro rol desde Roles.
5. **El certificado y el cupón no se tocan.**
6. **Un plan pagado en línea se devuelve desde la consola** y la orden queda cancelada (no vuelve a pendiente, porque el dinero ya volvió a la academia).
7. **Una devolución hecha en el panel de la pasarela no reembolsa sola la orden**: deja una alerta para que una persona lo confirme.
