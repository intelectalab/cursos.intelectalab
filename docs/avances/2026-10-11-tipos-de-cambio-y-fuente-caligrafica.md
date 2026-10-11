# Tipos de cambio, fuente caligráfica y el primer intento de reembolso

Fecha: 2026-10-11 · Sin commit al momento de escribir este documento

Tres cosas: el tipo de cambio como dato propio de la academia (cierra **RF-CUR-001** del Documento Maestro), la tipografía caligráfica del editor de certificados, y lo que se encontró al intentar el primer reembolso real con Mercado Pago.

## 1. Tipos de cambio

Antes, el tipo de cambio era un número suelto escrito en la cuenta de PayPal. **Ahora es un dato de la academia, con su fuente y su historial, y puede actualizarse solo.**

Está en **Configuración → «Tipo de cambio»**.

- **Vigente:** el valor que rige, de qué día es, de dónde salió y cuántos dólares se cobran por S/ 100.
- **Manual:** la academia lo escribe y vale hasta que lo cambie.
- **Automático (SUNAT):** cada mañana se toma el tipo de cambio oficial del día, valor de venta. Hay un botón «Actualizar ahora». Si un día la fuente no responde, se conserva el último valor.
- **Recargo opcional** en automático, de 0 a 20 %, para cubrir la comisión de la pasarela. Con 2 %, lo que sin recargo se cobraría como US$ 100 se cobra como US$ 102.
- **Historial:** cada valor que tuvo, con su día, su fuente, el valor oficial y el recargo, y quién lo registró.
- **Aviso** cuando el valor lleva más de tres días sin cambiar.
- PayPal cobra con el valor vigente; su tarjeta en «Cobros en línea» ya no lo pide, lo muestra.
- El tipo de cambio que una academia ya tenía escrito pasa a ser su primer registro del historial.

Decisiones tomadas con el responsable del proyecto: la fuente automática es SUNAT; «otro banco» queda para cuando haya uno que se pueda consultar; el recargo es opcional.

### Lo que hay que saber de la fuente

SUNAT no ofrece un servicio propio para consultar su tipo de cambio. **Se lee de un servicio de terceros que lo republica** (por defecto `api.apis.net.pe`, que hoy responde sin clave). La dirección y una clave opcional son configurables, por si ese servicio cambia sus condiciones o se prefiere otro.

### Lo que decidí y se puede cambiar

1. **Se usa el valor de venta**, no el de compra.
2. **El recargo divide el tipo de cambio** (oficial ÷ 1,02 para un 2 %), que es lo que hace que se cobren 2 % más dólares.
3. **En automático no se puede escribir a mano:** la siguiente actualización lo pisaría. Para escribirlo hay que pasar a manual.
4. **Vale el último valor registrado**, sea cual sea el día al que corresponde.
5. **La actualización corre a las 7:00 a. m.**
6. **La plataforma (cobro de planes) sigue con su tipo de cambio manual** en su cuenta de PayPal: esto se hizo para las academias.

### Lo que quedó corto

- Solo soles por dólar. Otras monedas, cuando se necesiten.
- Una sola fuente automática.
- Los reportes siguen sumando en soles; lo cobrado en dólares queda como nota del pago.
- Sin correo de aviso cuando la fuente falla varios días; se ve en la pantalla.

## 2. Tipografía caligráfica

Aprobada por el responsable del proyecto. Se agregó **Great Vibes** (licencia SIL Open Font, uso comercial permitido) como séptima tipografía del editor de certificados, pensada para el nombre del alumno.

- El archivo está en `backend/resources/fonts/` con su licencia, y una copia en `frontend/public/fonts/` para que el lienzo la muestre igual que el PDF.
- Tiene un solo trazo: **no admite negrita ni cursiva**; el editor oculta esos botones al elegirla.
- Pesa 458 KB, no los 150 KB que se estimaron al pedir la aprobación.

## 3. Primer intento de reembolso con Mercado Pago

El responsable intentó reembolsar la orden 00018 (S/ 150, pagada con saldo de Mercado Pago) y el sistema respondió «Mercado Pago rechazó la clave guardada. Hay que volver a conectar la cuenta». Volver a conectarla no lo resolvió.

**Qué pasaba:** la clave está bien (con ella se cobra y se consultan los pagos). Mercado Pago rechaza la devolución con el error «Unauthorized use of live credentials»: la aplicación de Mercado Pago de esa cuenta **no tiene activadas sus credenciales de producción**, y sin eso no acepta devoluciones por su API.

**Qué se corrigió en el sistema:**
- El mensaje ya no manda a reconectar la cuenta: explica que faltan activar las credenciales de producción y dónde se hace, y recuerda que la devolución se puede hacer desde la cuenta de Mercado Pago.
- Lo que responde Mercado Pago cuando rechaza una operación queda en el registro del servidor. Antes no quedaba, y por eso el motivo no se veía.

**Segundo intento, mismo resultado.** Con la cuenta vendedora de prueba conectada y sus credenciales de producción a la vista, Mercado Pago siguió respondiendo lo mismo: activar las credenciales no era la solución, o no basta. Mercado Pago no deja devolver por su API los pagos de esa cuenta de prueba.

**Lo que eso dejó ver:** si el dinero se devolvía desde la cuenta de Mercado Pago, la orden no se podía cerrar en el sistema, porque «Reembolsar» siempre pedía la devolución a la pasarela. **Ahora, antes de pedirla, el sistema consulta el pago: si la pasarela dice que ya está devuelto, solo lo registra** (la orden pasa a «Reembolsada», el alumno pierde la matrícula y la referencia dice «Devuelto desde la cuenta de Mercado Pago»). El mensaje del rechazo ya indica ese camino.

**El reembolso pedido desde el sistema sigue sin probarse contra Mercado Pago.** Lo que sí se puede probar hoy es devolver desde la cuenta de Mercado Pago y cerrar la orden en el sistema.

## Archivos

- Tipos de cambio: migración `2026_11_09_000100_create_exchange_rates_table`; `Sales/Application/Exchange/` (`ExchangeRateService`, `ExchangeRateSource`, `SunatExchangeRateSource`); `Sales/Domain/Models/ExchangeRate`; `Sales/Http/Controllers/ExchangeRateController`; `Sales/Console/SyncExchangeRatesCommand`; `OnlinePaymentService` lee el valor vigente; `config/payments.php`; `routes/console.php`. Frontend: `settings/components/ExchangeRateSection.tsx`, `PaymentGatewaysSection.tsx`, `SettingsPage.tsx`.
- Rutas nuevas: `GET` y `PUT /settings/exchange-rate`, `POST /settings/exchange-rate/manual`, `POST /settings/exchange-rate/refresh`.
- Fuente: `CertificateLayout`, `CertificatePdf`, `certificates/canvas.blade.php`, `index.css`, `CertificateEditorPage.tsx`.
- Reembolso: `MercadoPagoGateway::assertOk`.
- Pruebas: `Sales/ExchangeRateTest` (nuevo, 3 casos), un caso en `Sales/RefundTest` y ajustes en `Certificates/CertificateTest`.

## Verificación

- Backend: **319 pruebas pasan** (suite completa). Frontend: tipos, lint y compilación sin errores.
- Tipos de cambio en el navegador, con la demo y el servicio real: al pasar a automático con 2 % trajo el oficial del día (S/ 3,4570) y dejó vigente S/ 3,3892. La demo quedó después sin tipo de cambio, como estaba.
- Fuente: el PDF generado en la prueba la lleva incrustada, y el navegador la descarga. **No la vi dibujada en el lienzo ni en un PDF abierto.**
- Reembolso: la causa se confirmó preguntándole a Mercado Pago con un pedido que no podía devolver dinero (un importe mayor al pago).

## Al desplegar

- `php artisan migrate --force` (una migración).
- La tarea `exchange-rates:sync` corre con el programador de Laravel, como las demás.
- El servidor debe poder salir por HTTPS a `api.apis.net.pe`.
- Variables opcionales: `EXCHANGE_SUNAT_URL` y `EXCHANGE_SUNAT_TOKEN`.
- La carpeta `storage/framework/cache` debe poder escribirse: ahí se registra la tipografía la primera vez que se usa.
