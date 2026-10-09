# S6 — Pagos · Entrega 1: cobro en línea con Mercado Pago

Fecha: 2026-10-08 · Sin commit al momento de escribir este documento

Primera entrega de las pasarelas. Deja la base común para cobrar en línea y la primera pasarela: **el alumno paga su orden con Mercado Pago y queda matriculado al momento, sin que nadie confirme el pago a mano.**

**Importante:** no hubo credenciales de prueba de Mercado Pago. Todo lo que toca a Mercado Pago está probado con respuestas simuladas; **ningún cobro real ni de prueba se hizo todavía.** Lo único comprobado contra Mercado Pago de verdad es que rechaza una clave inventada.

Se construyó con las recomendaciones avisadas, sin respuesta de jefatura a la decisión 8: cada academia cobra con su propia cuenta, en soles, con Yape dentro de Mercado Pago, sin carrito y sin comprobantes.

## Qué se construyó

### La academia conecta su cuenta (Configuración → Cobros en línea)

- Tarjeta nueva con Mercado Pago: **Conectar** pide el *Access Token* de la aplicación de la academia en Mercado Pago y, si quiere, la clave secreta de los avisos.
- Al guardar, **el sistema comprueba la clave con Mercado Pago**: si no la reconoce, no se guarda y lo dice. Si la reconoce, muestra el nombre de la cuenta conectada y si es de prueba.
- Las claves se guardan **cifradas** y no se vuelven a mostrar: ni en la pantalla, ni en la API, ni en la auditoría.
- La academia puede **pausar** el cobro (deja de ofrecerse sin borrar la cuenta), **cambiar las claves** o **desconectar**.
- Si la moneda principal de la academia no es soles, Mercado Pago no se ofrece y la tarjeta lo explica.
- El dinero llega a la cuenta de Mercado Pago de la academia. IntelectaLab no toca ese dinero.

### El alumno paga en línea (Mis compras → su orden)

- En una orden pendiente aparece **"Pagar con Mercado Pago"**, encima de las instrucciones de pago por fuera, que siguen disponibles ("O paga por fuera").
- El botón lo lleva a la página de Mercado Pago, donde paga con tarjeta, Yape o su saldo, y vuelve a su orden.
- **Al volver, el sistema le pregunta a Mercado Pago si pagó.** Si pagó: orden pagada, matrícula activa y botón "Ir al curso". Si no: aviso de que aún no se ve el pago, con "Volver a comprobar".
- Además, Mercado Pago **avisa por su cuenta** al sistema cuando el pago se aprueba, por si el alumno cierra el navegador antes de volver.
- En la orden queda el medio (Tarjeta, Yape, saldo), "Mercado Pago" y el número de operación.

### Reglas de seguridad del cobro

- **Nada de lo que diga el navegador o el aviso se da por cierto.** El pago siempre se consulta en Mercado Pago con la cuenta de la academia.
- Solo deja pagada la orden un pago **aprobado, por el importe exacto y en la moneda de la orden**.
- **El mismo pago avisado dos veces no cobra ni matricula dos veces.**
- Si Mercado Pago cobró algo que no cuadra (otro importe, orden ya pagada por otro medio, orden cancelada), el pago **no se aplica y queda marcado para revisión**: el personal lo ve como alerta en la orden, en Ventas y pagos, y queda en la auditoría. Lo resuelve una persona (devolver o confirmar a mano).
- Si el plazo de la orden venció mientras el alumno pagaba, el pago **sí se acepta**: ya pagó. Además, Mercado Pago deja de cobrar la orden pasado su plazo.
- Con la clave secreta de avisos guardada, un aviso sin firma válida se rechaza.
- El alumno solo puede ser devuelto a su propia academia (no a otra dirección).
- Si Mercado Pago no responde, se le dice al alumno y la orden queda como estaba.

### Lo que no cambia

- El pago por fuera con confirmación manual sigue igual.
- Las ventas a empresas no se cobran por aquí.
- El cupo de alumnos se sigue revisando al comprar; una orden ya hecha se respeta al pagarse.

## Detalle técnico

- **Una migración** (`2026_11_02_000100_create_online_payment_tables`): `payment_gateway_accounts` (cuenta por academia y pasarela, claves cifradas) y `payment_attempts` (cada ida a pagar). Ambas con aislamiento por academia.
- Rutas nuevas:
  - Academia: `GET /settings/payment-gateways`, `PUT` y `DELETE /settings/payment-gateways/{pasarela}`.
  - Comprador: `POST /me/orders/{id}/pay`, `POST /me/orders/{id}/pay/sync`.
  - Pública (sin sesión): `POST /payments/webhooks/{pasarela}`, en el subdominio de cada academia.
- Código: `Sales/Application/Payments/Online/` — `OnlineGateway` (lo que debe saber hacer una pasarela), `MercadoPagoGateway`, `OnlinePaymentService` (decide qué pasa con la orden) y `GatewayPayment`. PayPal entra implementando `OnlineGateway`.
- `OrderService::applyPayment()` acepta ahora `gateway`: el pago no lleva "confirmado por" y vale aunque el plazo haya pasado.
- Medios de pago nuevos: `CARD` y `WALLET`. Códigos de error nuevos: `GATEWAY_NOT_AVAILABLE` (409) y `GATEWAY_ERROR` (502).
- Variables de entorno opcionales: `PAYMENTS_TIMEOUT` (15 s), `PAYMENTS_CA_BUNDLE` y `MERCADOPAGO_BASE_URL`.
- Sin dependencias nuevas (se usa el cliente HTTP de Laravel) y sin permisos nuevos: conecta la cuenta quien puede cambiar la Configuración.
- En solo lectura por plan vencido, los avisos de pago y el pago de órdenes ya hechas siguen entrando.

## Qué se verificó

- **290 pruebas del backend pasan** (eran 284). Pruebas nuevas (6), con Mercado Pago simulado:
  - conectar la cuenta: clave inválida rechazada, clave válida guardada, claves que no salen en la respuesta, ni en claro en la base, ni en la auditoría; pausar, desconectar y permisos;
  - ir a pagar y recibir el aviso: qué se le pide a Mercado Pago (importe, moneda, orden, direcciones), orden pagada y alumno matriculado, y el aviso repetido que no duplica;
  - volver del pago sin aviso: sin pago, pago rechazado y pago aprobado;
  - cobro que no cuadra y pago que llega a una orden cancelada: quedan para revisión;
  - avisos: firma inválida rechazada, firma válida aceptada, avisos que no son de pagos ignorados;
  - dirección de retorno ajena rechazada y falla de Mercado Pago.
- Frontend: compila, sin errores de tipos ni de estilo.
- **En el navegador, con la demo:** la tarjeta "Cobros en línea" aparece en Configuración; al conectar con una clave inventada, **Mercado Pago real respondió** y la pantalla mostró "Mercado Pago no reconoce esa clave".

**No se probó (falta de credenciales):** conectar una cuenta real o de prueba; el botón de pago del alumno en el navegador; la página de pago de Mercado Pago; el regreso; un aviso real; Yape. **Los nombres exactos de los campos que Mercado Pago envía y recibe salen de su documentación y de mi conocimiento, no de una respuesta real: lo primero que hay que hacer con las credenciales es un pago de prueba de punta a punta.**

## Lo que se encontró en este equipo

- El PHP de esta máquina (`C:\tools\php84`) no tiene certificados raíz configurados y no podía conectarse por HTTPS con Mercado Pago ("cURL error 60"). Se agregó la variable `PAYMENTS_CA_BUNDLE` y, **en `backend/.env` local, se apuntó al archivo que trae XAMPP** (`C:/xampp/apache/bin/curl-ca-bundle.crt`, de 2022). Para un servidor Linux normalmente no hace falta. Lo correcto a mediano plazo en esta máquina es configurar `curl.cainfo` en `php.ini` con un archivo actual.

## Qué quedó corto

- **Los avisos de Mercado Pago necesitan una dirección pública con HTTPS.** En local no llegan: ahí el pago se confirma cuando el alumno vuelve a su orden. Si el alumno paga y cierra el navegador en local, la orden queda pendiente hasta que la abra de nuevo.
- **No hay reembolsos desde el sistema** (van en la entrega 3). Un cobro en revisión se devuelve desde la cuenta de Mercado Pago.
- No hay una lista de "cobros en revisión": se ven orden por orden, como alerta.
- El personal no tiene un botón para volver a consultar a la pasarela por una orden; lo hace el alumno al abrir su orden.
- Si el alumno paga dos veces la misma orden (dos pestañas), el segundo pago queda en revisión; no se devuelve solo.
- La conexión es pegando la clave. No se usa el "conectar con Mercado Pago" con permiso (OAuth), que evitaría copiar claves pero exige registrar una aplicación de IntelectaLab en Mercado Pago.
- Mercado Pago solo para academias cuya moneda principal es soles.
- Cambiar la clave de la aplicación (`APP_KEY`) del servidor deja ilegibles las claves guardadas: las academias tendrían que volver a conectar.
- Sin correo al alumno cuando se confirma el pago (depende de la decisión 3).

## Decidido sin preguntar (se puede cambiar)

1. **El pago se confirma por dos caminos**: el aviso de Mercado Pago y la consulta cuando el alumno vuelve.
2. **Un pago que llega con la orden ya vencida se acepta**; uno que llega con la orden cancelada o ya pagada queda en revisión.
3. **La clave secreta de los avisos es opcional.** Sin ella los avisos se aceptan sin firma, porque el pago se consulta igual en Mercado Pago.
4. **Conecta la cuenta quien puede cambiar la Configuración** (el Administrador); no se creó un permiso aparte.
5. El pago por fuera **sigue ofreciéndose** junto al pago en línea; no hay un interruptor para ocultarlo.
6. Cada vez que el alumno pulsa "Pagar" se prepara un cobro nuevo en Mercado Pago (no se reutiliza el anterior).
