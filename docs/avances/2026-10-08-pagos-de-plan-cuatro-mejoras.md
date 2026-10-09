# Pagos de plan de las academias: cobro en línea, constancia, avisos, vencimiento y deshacer

Fecha: 2026-10-08 · Sin commit al momento de escribir este documento

Resuelve los cuatro puntos que habían quedado cortos en "Mi plan" (entrega 2 de planes):

| Lo que faltaba | Ahora |
| --- | --- |
| No hay cobro en línea: la academia paga por fuera y alguien confirma | La academia puede pagar su plan con Mercado Pago y el plan queda vigente solo. **Probado solo con respuestas simuladas** |
| No se adjunta la constancia como archivo | La academia adjunta la foto o el PDF de su voucher; la plataforma lo abre desde la consola |
| Nadie recibe aviso | La plataforma ve un contador en "Pagos" y recibe un correo; la academia ve un aviso fijo y recibe un correo. **Los correos hoy quedan en el registro del servidor: no hay proveedor de correo** |
| Una orden pendiente no vence sola, y una confirmación no se deshace desde la pantalla | La orden vence a los 7 días si no se pagó ni se informó un pago; y la plataforma tiene el botón "Deshacer" |

## Qué se construyó

### 1. Pago del plan en línea

- **Consola → Planes → "Cobros en línea":** el super administrador conecta la cuenta de Mercado Pago **de IntelectaLab**. Es la misma tarjeta que usan las academias para sus alumnos: comprueba la clave con Mercado Pago, la guarda cifrada y permite pausar, cambiar o desconectar.
- **Academia → Mi plan:** en la orden pendiente aparece "Pagar con Mercado Pago", encima del pago por fuera, que sigue disponible.
- Al volver de Mercado Pago el sistema le pregunta si se pagó; además Mercado Pago avisa por su cuenta a la plataforma. **Con el pago aprobado, el plan queda vigente en ese momento, sin que nadie confirme.**
- Mismas reglas que en la venta de cursos: el pago siempre se consulta en Mercado Pago; solo vale un pago aprobado por el importe y la moneda exactos; el mismo aviso dos veces no duplica; un cobro que no cuadra **no se aplica y queda "por revisar"** en la consola, con aviso por correo.
- Un pago que llega con la orden ya vencida se acepta (ya se cobró).

### 2. Constancia adjunta

- En la orden pendiente: **"Adjuntar constancia"** (PDF, PNG, JPG o WEBP, hasta 2 MB). Se puede ver, reemplazar o quitar mientras la orden siga pendiente. El campo de texto para indicar cómo ubicar el pago sigue estando.
- El archivo se valida por su contenido, no por su nombre.
- En la consola, la orden muestra **"Ver constancia"** en la lista y dentro de "Confirmar pago".
- Otra academia no puede llegar a la constancia.

### 3. Avisos

**A la plataforma:**

- La pestaña **Pagos** de la consola lleva un **contador** de órdenes que esperan. Se pone en ámbar cuando alguna academia ya informó su pago o hay un cobro por revisar.
- La orden con pago informado se distingue en la lista con la etiqueta "Pago informado".
- Correo a los administradores de plataforma cuando: llega una orden nueva, una academia informa su pago (texto o constancia), o la pasarela cobró algo que hay que revisar.

**A la academia:**

- **Aviso fijo** arriba de todas las pantallas (para quien administra la configuración) cuando su orden se resuelve: pago confirmado, cancelada por IntelectaLab (con el motivo) o vencida. Se queda hasta que pulse **"Entendido"**.
- Correo a quien pidió la orden y a los administradores de la academia en esos mismos casos, y cuando se deshace una confirmación.
- Si la academia cancela su propia orden, no se le avisa nada.

### 4. Vencimiento y deshacer

- **Vencimiento:** la orden muestra hasta cuándo se puede pagar (7 días). Pasado el plazo vence sola y la academia puede pedir otra. **Si la academia ya informó un pago, no vence**: espera a que la plataforma la confirme o la cancele.
- La consola tiene una tarjeta nueva, "Vencidas".
- **Deshacer una confirmación:** en las órdenes pagadas, botón **"Deshacer"** con motivo obligatorio. El plan de la academia vuelve exactamente a como estaba antes (por ejemplo, a su prueba gratuita) y la orden vuelve a pendiente, con el motivo a la vista, para confirmarla bien o cancelarla.
- No se puede deshacer si: el plan de la academia cambió después (otra orden o un cambio a mano en la ficha), la academia ya tiene otra orden pendiente, o **el pago lo cobró la pasarela** (eso sería una devolución de dinero). En esos casos el sistema explica qué hacer.

## Detalle técnico

- **Una migración** (`2026_11_03_000100_extend_plan_orders`): columnas nuevas en `plan_orders` (plazo, constancia, pasarela, datos para deshacer, aviso visto). Las órdenes pendientes que ya existían reciben su plazo desde el día en que se aplica.
- Estado nuevo de una orden de plan: `EXPIRED`.
- La pasarela se hizo reutilizable: `OnlineGateway` recibe ahora la cuenta (`GatewayCredentials`) y lo que hay que cobrar (`Charge`), sin saber de órdenes. La usan Ventas (cuenta de cada academia) y Planes (cuenta de la plataforma, guardada cifrada en `platform_settings`).
- Clases nuevas en `Billing/Application`: `PlanOnlinePayments`, `PlatformGateways`, `PlanOrderNotifier`. `PlanOrderService` suma `attachProof`, `removeProof`, `revert`, `expireOverdue` y `flagForReview`.
- Rutas nuevas:
  - Academia: `POST`, `DELETE` y `GET /subscription/orders/{id}/proof`; `POST /subscription/orders/{id}/pay` y `/pay/sync`; `POST /subscription/notice/seen`.
  - Plataforma: `POST /platform/plan-orders/{id}/revert`, `GET /platform/plan-orders/{id}/proof`, `GET /platform/plan-orders/summary`, `GET`/`PUT`/`DELETE /platform/payment-gateways`.
  - Pública, en el dominio central: `POST /platform/payments/webhooks/{pasarela}`.
- Tarea programada nueva: `plan-orders:expire`, cada hora. Además, abrir "Mi plan" o "Pagos" vence lo que ya pasó su plazo, sin esperar a la tarea.
- Variable de entorno opcional: `BILLING_ORDER_EXPIRATION_DAYS` (7).
- Uso de archivo nuevo: `plan_payment_proof` (privado).
- Sin dependencias nuevas y sin permisos nuevos.

## Qué se verificó

- **296 pruebas del backend pasan** (eran 290). Pruebas nuevas (6):
  - constancia: archivo disfrazado rechazado, adjuntar, ver, quitar, la plataforma la ve y la descarga, otra academia no llega, correos a la plataforma;
  - avisos a la academia: confirmada, cancelada por la plataforma con motivo, cancelada por ella misma (sin aviso), y "Entendido";
  - vencimiento: vence a los 8 días, se puede pedir otra, y una con pago informado no vence;
  - deshacer: solo órdenes pagadas y con motivo; el plan vuelve a la prueba; se puede confirmar de nuevo; bloqueado si el plan cambió después;
  - pago en línea del plan: conectar la cuenta de la plataforma sin que la clave salga, ir a pagar, volver sin pago, aviso que activa el plan, aviso repetido, y que no se puede deshacer;
  - cobro que no cuadra: queda por revisar, con contador y correo.
- Frontend: compila, sin errores de tipos ni de estilo.
- **En el navegador, con la demo:** la academia pidió el plan (orden P-0002), vio el plazo ("Tienes hasta el 15 oct."), adjuntó un voucher y la orden pasó a "pago informado, en revisión"; en la consola apareció el contador "1", "Pago informado" y "Ver constancia"; confirmé el pago (plan vigente), lo **deshice** con motivo (la demo volvió a "Sin plan" y la orden a pendiente con el motivo a la vista) y la academia canceló la orden. Los correos quedaron en el registro del servidor. La tarjeta "Cobros en línea" aparece en Planes. **La demo quedó como estaba: "Sin plan".**

**No se probó:** ningún pago en línea real ni de prueba (faltan credenciales); el aviso fijo de la academia en el navegador (sí en pruebas); el envío real de correos (no hay proveedor); la tarea programada corriendo sola en un servidor.

## Qué quedó corto

- **Pago en línea solo con Mercado Pago y solo simulado.** PayPal sigue pendiente (entrega 2 de pagos). Lo primero con credenciales: un pago de plan de punta a punta.
- **Los correos no salen de verdad** hasta definir el proveedor (decisión 3). El contador y el aviso fijo sí funcionan hoy.
- El aviso fijo de la academia solo lo ve quien administra la configuración.
- No hay devolución de un pago en línea desde el sistema: se hace en la cuenta de Mercado Pago y el plan se ajusta en la ficha.
- El contador de la consola se actualiza al cambiar de sección, no al instante.
- Una orden con pago informado que la plataforma nunca atiende queda pendiente para siempre: no hay recordatorio.
- Las órdenes pagadas antes de esta entrega no se pueden deshacer (no guardaron cómo estaba el plan); el botón no aparece en ellas.
- El plazo de 7 días es uno solo para toda la plataforma (variable de entorno).

## Decidido sin preguntar (se puede cambiar)

1. **Plazo de 7 días** para pagar una orden de plan.
2. **Una orden con pago informado no vence sola.**
3. **Deshacer devuelve la orden a pendiente** (no la cancela), con el motivo visible para la academia.
4. **No se deshace lo que cobró la pasarela.**
5. **Constancia de hasta 2 MB**, en PDF o imagen; una sola por orden.
6. **Se avisa a la plataforma dos veces**: al llegar la orden y al informarse el pago.
7. **Los correos a la academia van a quien pidió la orden y a sus administradores.**
8. **Las academias pagan su plan con la cuenta de IntelectaLab** en Mercado Pago, que conecta el super administrador en la consola (no en un archivo del servidor).
