# S6 — Pagos · Primer pago de prueba con Mercado Pago

Fecha: 2026-10-10 · Sin commit al momento de escribir este documento

**Se hizo el primer cobro contra una pasarela de verdad.** Con credenciales de prueba de Mercado Pago, el alumno de la demo pagó el curso «Marketing digital para emprendedores» (S/ 89) con una tarjeta de prueba; la orden N.º 00017 quedó pagada y la matrícula activa. Hasta hoy todo S6 estaba probado solo con respuestas simuladas.

## Qué se probó y funcionó

- **Conectar la cuenta:** Mercado Pago aceptó el Access Token y devolvió el nombre y el país de la cuenta.
- **Crear el cobro:** el sistema envió la orden, el título y el importe, y Mercado Pago devolvió la página de pago con esos datos.
- **Pagar:** con una cuenta de prueba compradora y una tarjeta de prueba (operación 183532438472).
- **Confirmar:** el sistema consultó a Mercado Pago, encontró el pago aprobado por el importe pedido, marcó la orden como pagada (medio «Tarjeta · Mercado Pago», con el número de operación) y activó la matrícula.

## Qué falló y se corrigió

1. **La orden seguía «Pendiente de pago» después de pagar.** La página de la orden solo consultaba a Mercado Pago cuando el comprador volvía por el enlace de retorno de la pasarela. Si volvía por su cuenta (otra pestaña, el menú, o porque en local Mercado Pago no regresa solo), nadie preguntaba. **Ahora la orden pregunta a la pasarela cada vez que se abre mientras esté pendiente y tenga pago en línea**, y hay un enlace «¿Ya pagaste y sigue pendiente? Comprobar pago». Si la orden nunca fue a una pasarela, no se hace ninguna consulta.
2. **La hora del pago salía cuatro horas antes.** Mercado Pago informa la fecha con su propio desfase horario (−04:00) y se guardaba esa hora como si fuera UTC. Ahora se guarda el instante real; se aplicó lo mismo a PayPal. El pago de prueba de la demo se corrigió a mano.

## Lo que se aprendió del uso (no eran fallas del sistema)

- **El botón «Pagar» de Mercado Pago queda gris si se paga con la misma cuenta que cobra.** Hacen falta dos cuentas de prueba: la vendedora (la que se conecta en la academia) y una compradora.
- Las credenciales de un usuario de prueba empiezan con `APP_USR-`, no con `TEST-`. Funcionan, pero la academia no las muestra como «Cuenta de prueba» (ver pendientes).

## Lo que sigue sin probar

- El **reembolso** de este pago por Mercado Pago.
- Pago con **Yape** y con saldo de la cuenta.
- Los **avisos** (webhooks) de Mercado Pago: en local no llegan; se prueban con servidor.
- El **pago de un plan** con la cuenta de la plataforma.
- **PayPal** completo: faltan sus credenciales de prueba.

## Archivos

- `frontend/src/features/sales/pages/MyOrdersPages.tsx`: consulta a la pasarela al abrir una orden pendiente y enlace «Comprobar pago».
- `backend/app/Modules/Sales/Application/Payments/Online/MercadoPagoGateway.php` y `PayPalGateway.php`: fecha del pago en UTC.
- `backend/tests/Feature/Sales/OnlinePaymentTest.php`: comprueba la hora guardada.

## Verificación

- Pruebas de Ventas y Planes: 55 pasan. Estilo del backend, tipos y lint del frontend sin errores.
- En la demo: orden N.º 00017 pagada, intento aprobado con su número de operación y una matrícula activa.
- Sin migraciones, variables ni permisos nuevos.
