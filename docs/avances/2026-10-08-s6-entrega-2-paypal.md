# S6 — Pagos · Entrega 2: cobro en línea con PayPal

Fecha: 2026-10-08 · Sin commit al momento de escribir este documento

Segunda pasarela. Con ella **el alumno paga su curso con PayPal y la academia paga su plan con PayPal**, en los dos casos con matrícula o plan vigente al momento. PayPal no cobra en soles: cobra en dólares el equivalente, con el **tipo de cambio que fija quien cobra**.

**Importante:** no hubo credenciales de prueba de PayPal. Todo está probado con respuestas simuladas; **ningún cobro real ni de prueba se hizo todavía.** Lo único comprobado contra PayPal de verdad es que su entorno de pruebas rechaza unas claves inventadas.

Se construyó con las recomendaciones avisadas (decisión 8 sin respuesta de jefatura): cada quien cobra con su cuenta, PayPal en dólares con tipo de cambio manual.

## Qué se construyó

### Conectar PayPal (academia: Configuración → Cobros en línea; plataforma: consola → Planes)

- La tarjeta "Cobros en línea" muestra ahora dos pasarelas: Mercado Pago y PayPal.
- **Conectar PayPal** pide el *Client ID* y el *Secret* de la aplicación en PayPal, una casilla para indicar que son **claves de prueba (Sandbox)** y, si se quiere, el *Webhook ID*.
- El sistema comprueba las claves con PayPal antes de guardarlas. Se guardan cifradas y no se vuelven a mostrar.
- **Tipo de cambio (soles por dólar):** como los precios están en soles y PayPal cobra en dólares, la tarjeta pide el tipo de cambio y muestra un ejemplo ("S/ 100 se cobraría como US$ 26.67"). **Mientras no se defina, PayPal no se ofrece** y la tarjeta dice "Falta el tipo de cambio". Se cambia cuando se quiera, sin volver a poner las claves; no se actualiza solo.
- Si la moneda principal de la academia es dólares, PayPal cobra el importe tal cual y no pide tipo de cambio.

### Pagar con PayPal

- En la orden del alumno y en la orden de plan de la academia aparece **"Pagar con PayPal"**, junto a Mercado Pago si también está conectado.
- **Antes de ir a PayPal se ve cuánto se pagará en dólares** y con qué tipo de cambio: "PayPal cobra en dólares: pagarás US$ 32.00, al tipo de cambio de S/ 3.75 por dólar".
- En PayPal la persona **aprueba** el cobro y vuelve. **El dinero se cobra recién cuando vuelve** (o cuando llega el aviso de PayPal): es como funciona PayPal. Si cierra el navegador después de aprobar, el aviso de PayPal completa el cobro, siempre que el servidor tenga dirección pública.
- La orden sigue registrada en soles; en el pago queda anotado lo que se cobró en dólares y el tipo de cambio usado.

### Reglas del cobro

- Las mismas de Mercado Pago: el pago siempre se lee de PayPal con la cuenta de quien cobra; el mismo aviso dos veces no duplica; con la orden vencida el pago se acepta; con la orden cancelada o ya pagada queda en revisión.
- **El importe se comprueba contra lo que se le pidió cobrar a PayPal** (los dólares calculados al iniciar ese pago). Si después se cambia el tipo de cambio, los pagos ya iniciados conservan el suyo.
- Con el Webhook ID guardado, PayPal debe confirmar que cada aviso es suyo; si no lo confirma, se rechaza.

## Detalle técnico

- **Una migración** (`2026_11_04_000100_add_exchange_rate_to_online_payments`): `settings` en las cuentas de pasarela (el tipo de cambio), `exchange_rate` en los intentos de pago y `gateway_amount`/`gateway_currency` en las órdenes de plan.
- `PayPalGateway` implementa `OnlineGateway` (API Orders v2: crear orden, aprobar, capturar). La interfaz cambió en tres puntos: `chargeCurrency()` reemplaza a `supportsCurrency()`, `tracksByCheckout()` y un tercer parámetro en `paymentsFor()` (PayPal no busca por referencia, sino por su propio identificador).
- `ChargeQuote` calcula cuánto y en qué moneda cobra una pasarela. La única conversión es de soles a dólares.
- Las cuentas aceptan `exchange_rate` en `PUT /settings/payment-gateways/{pasarela}` y `PUT /platform/payment-gateways/{pasarela}`.
- Avisos de PayPal: `https://{academia}.{dominio}/api/v1/payments/webhooks/paypal` (cursos) y `https://{dominio central}/api/v1/platform/payments/webhooks/paypal` (planes). **En PayPal el webhook se registra a mano** en la aplicación; eventos: `CHECKOUT.ORDER.APPROVED` y `PAYMENT.CAPTURE.COMPLETED`.
- Medio de pago nuevo: `PAYPAL`. Variables opcionales: `PAYPAL_LIVE_URL` y `PAYPAL_SANDBOX_URL` (solo para pruebas).
- Sin dependencias nuevas, sin permisos nuevos, sin rutas nuevas.

## Qué se verificó

- **301 pruebas del backend pasan** (eran 296). Pruebas nuevas (5), con PayPal simulado:
  - claves inválidas rechazadas; PayPal no se ofrece sin tipo de cambio; con él, el comprador ve US$ 32.00 por S/ 120 a 3.75; reconectar no borra el tipo de cambio;
  - ir a PayPal (qué se le pide: dólares, orden, direcciones, entorno de pruebas), volver sin aprobar, y volver aprobado: se captura y la orden queda pagada con la nota del tipo de cambio;
  - aviso de PayPal: importe incorrecto queda en revisión; importe correcto paga; aviso repetido no duplica; avisos de otra cosa se ignoran;
  - con Webhook ID: aviso que PayPal no confirma, rechazado; confirmado, se captura;
  - una academia paga su plan de S/ 99 con PayPal (US$ 26.40) y queda vigente.
- Frontend: compila, sin errores de tipos ni de estilo.
- **En el navegador, con la demo:** la tarjeta de PayPal aparece en Configuración con sus cuatro campos; al conectar con claves inventadas marcadas como de prueba, **el entorno de pruebas de PayPal respondió** y la pantalla mostró "PayPal no reconoce esas claves".

**No se probó (falta de credenciales):** conectar una aplicación real o de prueba; el editor del tipo de cambio en el navegador (aparece solo con la cuenta conectada); el botón "Pagar con PayPal"; la página de PayPal, la aprobación, la captura y un aviso reales. **Los nombres de los campos de PayPal salen de su documentación y de mi conocimiento, no de una respuesta real.**

## Qué quedó corto

- **El tipo de cambio es manual**: si nadie lo actualiza, se cobra con un dólar desactualizado. No hay aviso de "hace N días que no lo cambias".
- El redondeo es a dos decimales del dólar; la academia asume la diferencia de centavos y las comisiones de PayPal.
- **El webhook de PayPal se registra a mano** en su panel (Mercado Pago recibe la dirección en cada cobro; PayPal no).
- Sin aviso de PayPal y sin que la persona vuelva, un cobro aprobado queda sin capturar: PayPal lo anula solo a las pocas horas. En local siempre es así (no llegan avisos).
- Solo se convierte de soles a dólares. Una academia con otra moneda principal no puede usar PayPal.
- No hay reembolsos desde el sistema (entrega 3).
- El nombre de la cuenta conectada es el final del Client ID: PayPal no entrega el nombre con esas claves.

## Decidido sin preguntar (se puede cambiar)

1. **El tipo de cambio se guarda en la cuenta de PayPal** (uno por academia y uno de la plataforma), no como ajuste general.
2. **Sin tipo de cambio, PayPal no se ofrece.**
3. **La orden sigue en soles**; los dólares cobrados quedan como nota del pago. Los reportes siguen sumando soles.
4. **El pago se comprueba contra los dólares calculados al iniciarlo**, aunque el tipo de cambio cambie después.
5. **Se captura al volver o al llegar el aviso**, lo que ocurra primero.
6. PayPal también sirve para **pagar los planes**, con la cuenta y el tipo de cambio de IntelectaLab.
