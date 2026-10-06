# S11 — Promociones y paquetes · Entrega 1: promociones y cupones

Fecha: 2026-10-05 · Sin commit al momento de escribir este documento

Primera de las dos entregas de S11. La academia crea **promociones** con uno o varios **cupones**; el comprador escribe el código al pagar y obtiene su descuento (Maestro RF-PROMO-001, FP-13: regla → cupón → validar → descontar → redención; RN-008; TC-SAL-003).

## La regla que faltaba definir (PD-010)

El Maestro dejaba pendiente cómo se acumulan las promociones. Quedó así, con tu aprobación: **los descuentos no se acumulan. Cada compra admite un solo cupón.**

## Qué se construyó

### Para el personal: "Promociones"

En el menú de Operación, pestaña **Promociones y cupones**.

Una **promoción** es la regla del descuento:

- **Tipo**: porcentaje del precio (20 %) o monto fijo (S/ 30). Un monto fijo nunca deja el total en negativo: si supera el precio, el total es cero.
- **Vigencia**: desde y hasta una fecha (opcionales).
- **Usos en total** (opcional): por ejemplo, las primeras 50 compras.
- **Una vez por persona** (activado por defecto).
- **Compra mínima** (opcional).
- **A qué aplica**: todo lo que está en venta, o solo los cursos y paquetes que se elijan.

Un **cupón** es el código que la activa. Una promoción puede tener varios (VERANO-INSTAGRAM, VERANO-CORREO) para saber por dónde llegó cada venta; cada uno se activa o desactiva por separado.

En cada promoción se ven sus cupones con sus usos y la lista de **compras en que se usó**: quién, con qué código, cuánto descontó y en qué estado está la orden.

La lista muestra la situación de hoy: activa, por empezar, vencida, agotada o desactivada.

### Para el comprador: el cupón en el checkout

- Antes de confirmar, enlace "¿Tienes un cupón de descuento?". Escribe el código y ve el descuento y el nuevo total.
- Los códigos no distinguen mayúsculas.
- Si el cupón no aplica, el mensaje dice por qué: no existe, ya no está disponible, todavía no está vigente, venció, se agotó, ya usaste esta promoción, no aplica a lo que compras, o requiere una compra mínima mayor.
- Con un cupón inválido la orden no se crea; el comprador lo quita y sigue sin descuento (TC-SAL-003).
- Si el descuento deja el total en cero, queda matriculado al instante, como en un curso gratuito.

### Reglas

- **El descuento lo calcula siempre el servidor** (RN-008). La pantalla muestra lo que el servidor le dice, y al crear la orden se vuelve a calcular; si el total ya no coincide con el que vio el comprador, se le pide confirmar el nuevo.
- **El uso cuenta desde que se crea la orden** y se libera solo si la orden vence o se cancela. Así, con "los primeros 50", nadie paga y descubre después que el cupón se agotó.
- **El descuento queda fijado en la orden**: cambiar la promoción después no altera las órdenes ya creadas.
- "Una vez por persona" vale para toda la promoción, no por código: quien usó VERANO-CORREO no puede usar VERANO-INSTAGRAM.

### Quién puede usarlo

| Permiso | Qué permite | Roles base |
| --- | --- | --- |
| `promotions.read` | Ver promociones, cupones y sus usos | Administrador, Ventas/CRM |
| `promotions.manage` | Crear y editar promociones y cupones | Administrador |
| `checkout.validate` | Probar un cupón al comprar (ya existía) | Alumno |

Ventas/CRM las ve para poder ofrecerlas, sin cambiarlas.

## Detalle técnico

- **Módulo nuevo** `backend/app/Modules/Promotions`. Tablas del Maestro §5.1: `promotions`, `coupons`, `coupon_redemptions`, más `promotion_items` (a qué cursos o paquetes aplica una promoción acotada). En `orders`, columna nueva `coupon_code`.
- **Rutas**: `POST /api/v1/checkout/validate-coupon` (Maestro §7.2; limitada a 20 por minuto para que no sirva para adivinar códigos), `GET/POST /promotions`, `GET/PUT /promotions/{id}`, `POST /promotions/{id}/coupons`, `PUT /coupons/{id}`, `GET /promotions/{id}/redemptions`. `POST /orders` acepta `coupon_code`.
- El checkout de S5 se reorganizó: un solo camino crea la orden de un curso o de un paquete, con o sin cupón. Las pruebas de S5 siguen pasando.
- Al aplicar un cupón dentro de la orden se bloquea la fila de la promoción, para que dos compras simultáneas no superen el tope de usos.
- Un uso "cuenta" según el estado de su orden (pagada o pendiente dentro de plazo); no hay nada que liberar a mano ni tarea programada nueva.
- Auditoría: creación y edición de promociones, alta y cambio de cupones; la orden registra el cupón y el descuento. Código de error nuevo: `COUPON_INVALID`, con el motivo en `details.reason`.
- Frontend: `frontend/src/features/promotions` y `CouponBox` en `features/sales`.

## Qué se verificó

- **7 pruebas nuevas** (`tests/Feature/Promotions/CouponTest.php`), que pasan junto con las de ventas, empresas, reportes e identidad. La corrida completa se hizo al cerrar el sprint: 220 pruebas pasan. Cubren: descuento por porcentaje con su registro de uso, total que debe coincidir con el del servidor, monto fijo que no baja de cero y matrícula inmediata, cada motivo de rechazo, orden que no se crea con cupón inválido, tope de usos que se libera al cancelar o vencer la orden, una vez por persona entre varios códigos, validaciones, permisos por rol y aislamiento entre academias.
- El frontend compila, pasa el lint y genera el build.
- **En el navegador, academia demo**: creé la promoción "Campaña de verano" con el cupón VERANO20 (20 %, tope de 50); como alumna probé un código inexistente (mensaje claro) y luego VERANO20 sobre un paquete de S/ 220: descuento de S/ 44, total S/ 176, y la orden salió con ese total.
- **No se probó en el navegador**: el cupón sobre un curso suelto, editar una promoción, agregar un segundo código, desactivar un cupón, una promoción acotada a ciertos cursos, la lista de usos, ni el tamaño de teléfono.

## Qué quedó corto

- **Sin promociones automáticas**: todo descuento necesita que el comprador escriba un código.
- **Sin acumulación**, por decisión. Si jefatura la quiere, hay que definir el orden y el tope.
- **Sin cupones de un solo uso generados en lote** (un código distinto por persona) ni cupones ligados a un correo.
- Los cupones **no aplican a las cotizaciones de empresas**: ahí el descuento lo pone el vendedor.
- La vigencia se guarda con la hora del navegador de quien la define, no con la zona horaria de la academia.
- Alguien puede **reservar un uso sin pagar**; se libera cuando su orden vence (72 horas por defecto).
- Los reportes no muestran todavía el descuento del periodo ni las ventas por cupón.
- Una promoción no se elimina; se desactiva.
