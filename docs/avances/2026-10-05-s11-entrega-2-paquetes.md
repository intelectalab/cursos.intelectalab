# S11 — Promociones y paquetes · Entrega 2: paquetes

Fecha: 2026-10-05 · Sin commit al momento de escribir este documento

Con esta entrega S11 queda completo. La academia arma **paquetes**: varios cursos que se venden juntos a un precio propio. El comprador paga una vez y queda matriculado en todos (Maestro RF-PKG-001, FP-03: paquete → checkout → pago → orden → ítems → varias matrículas).

## Qué se construyó

### Para el personal: pestaña "Paquetes"

En **Promociones**, segunda pestaña.

- Nombre, resumen, **cursos incluidos** (al menos dos) y **precio del paquete**.
- Mientras se arma, la pantalla muestra cuánto costarían los cursos por separado y cuánto ahorra el comprador.
- Solo se eligen cursos publicados y con precio.
- **En venta / retirado**: un paquete retirado sale del catálogo sin perder sus ventas.
- Si después se archiva o se deja sin precio uno de sus cursos, **el paquete sale solo del catálogo** y figura como "Fuera del catálogo" hasta que se corrija.

### Para el comprador

- En el catálogo público, los paquetes aparecen primero, con sus cursos, su precio y el ahorro ("S/ 270 por separado, ahorras S/ 50").
- La ficha del paquete muestra cada curso incluido, con enlace a su propia ficha.
- En el checkout ve la lista de cursos, el total y puede usar un **cupón**.
- **Si ya tiene alguno de los cursos**, se le avisa antes de pagar: paga el precio completo y solo se activan los que le faltan. Cada curso figura como "Ya lo tienes" o "Se activará".
- Si ya tiene todos, no puede comprarlo.
- Una sola orden; al confirmarse el pago queda matriculado en todos los cursos que le faltaban.

### Reglas

- Cada curso del paquete usa **sus propios días de acceso** y entrega **su propio certificado**.
- El precio del paquete **se reparte entre sus cursos** en proporción al precio de cada uno, y la orden suma exacto. Ejemplo: un paquete de S/ 300 con cursos de 100, 150 y 150 queda en 75, 112,50 y 112,50. Así los reportes de ventas por curso siguen cuadrando.
- La orden guarda el nombre y el precio que tenía el paquete al comprarlo: editarlo después no la cambia.
- Un cupón vale para un paquete solo si su promoción lo incluye (o aplica a todo). El cupón de uno de sus cursos no sirve para el paquete.
- No se duplica la compra: con una orden del paquete esperando pago, no se crea otra.

### Quién puede usarlo

| Permiso | Qué permite | Roles base |
| --- | --- | --- |
| `packages.read` | Ver los paquetes | Administrador, Ventas/CRM |
| `packages.manage` | Crear y editar paquetes | Administrador |

## Detalle técnico

- En el módulo `Sales`: tablas del Maestro §5.1 `packages` y `package_items`. En `orders`, columnas nuevas `package_id` y `package_title`; los ítems de la orden son los cursos del paquete.
- **Rutas**: `GET/POST /api/v1/packages`, `GET/PUT /packages/{id}` (personal); `GET /catalog/packages` y `GET /catalog/packages/{id}` (públicas); `GET /checkout/packages/{id}` (comprador: el paquete más los cursos que ya tiene). `POST /orders` acepta `package_id` en lugar de `course_id`.
- La API de órdenes devuelve `package` (nulo en la compra de un curso) y `coupon_code`.
- Páginas nuevas del sitio: `/catalog/packages/{id}` (pública) y `/checkout/package/{id}`.
- Auditoría: creación y edición de paquetes.
- Frontend: `frontend/src/features/promotions` (gestión) y `features/sales/pages/PackagePages.tsx`.

## Qué se verificó

- **220 pruebas del backend pasan** (corrida completa al cierre), 5 nuevas (`tests/Feature/Sales/PackageTest.php`): compra que crea una orden y matricula en todos los cursos, reparto del precio que suma exacto, compra con cursos ya propios, cupón que aplica o no al paquete, salida del catálogo al archivar un curso, validaciones, permisos por rol y aislamiento entre academias.
- El frontend compila, pasa el lint y genera el build.
- **En el navegador, academia demo**: armé "Ruta de análisis de datos" (Excel para finanzas + Power BI, S/ 220, ahorro de S/ 50) y lo vi en el catálogo público. Como la alumna demo, que ya tenía uno de los cursos, entré al checkout: mostró "Ya lo tienes" y el aviso, apliqué el cupón y se generó la orden N.º 00006 por S/ 176.
- **No se probó en el navegador**: confirmar el pago de esa orden y ver las matrículas, editar o retirar un paquete, un paquete que queda fuera del catálogo, la compra sin sesión (registro y regreso al checkout), ni el tamaño de teléfono.

## Qué quedó corto

- **Sin carrito**: se compra un curso o un paquete por orden, no varios productos sueltos.
- Los paquetes **no se ofrecen dentro de la aplicación** a quien ya inició sesión ("Cursos disponibles"): se llega por el catálogo público.
- **Sin rebaja por cursos ya comprados**: quien tiene uno paga el paquete completo (se le avisa antes).
- **Sin imagen de portada** ni descripción larga del paquete.
- No se cotizan paquetes a empresas ni se licencian (el Maestro lo prevé; quedó para después).
- Un paquete no se elimina; se retira.
- Los reportes no distinguen todavía las ventas por paquete.
