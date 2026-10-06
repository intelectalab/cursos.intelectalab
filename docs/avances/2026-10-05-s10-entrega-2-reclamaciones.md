# S10 — Operación · Entrega 2: Libro de Reclamaciones

Fecha: 2026-10-05 · Sin commit al momento de escribir este documento

Segunda entrega de S10. Cada academia tiene su **Libro de Reclamaciones virtual**: cualquier consumidor registra un reclamo o una queja sin necesidad de cuenta, recibe un código y consulta después la respuesta (Maestro RF-CLAIM-001, FP-20, RN-018).

> **Pendiente de confirmar con jefatura o con quien lleve lo legal:** el formato de la hoja y el plazo de respuesta. Se armó siguiendo el formato habitual del Libro de Reclamaciones del INDECOPI, pero nadie con criterio legal lo ha revisado. El Maestro deja esta operativa como pendiente (PD-013). El plazo quedó configurable para no fijar una regla en el código.

## Qué se construyó

### El formulario público

En `https://<academia>/reclamaciones`, enlazado al pie del catálogo público. No requiere sesión.

- **Proveedor**: razón social, RUC y dirección de la academia, tomados de Configuración.
- **Consumidor**: nombres, apellidos, tipo y número de documento (DNI, carné de extranjería, pasaporte o RUC), domicilio, correo y teléfono. Si es menor de edad, los datos del padre, madre o apoderado.
- **Bien contratado**: producto o servicio, descripción, monto reclamado y número de orden (los dos últimos opcionales).
- **Detalle**: si es **reclamo** (disconformidad con el producto o servicio) o **queja** (malestar con la atención), qué ocurrió y qué pide.
- Declaración de que los datos son correctos.

Al registrar, el consumidor ve su **constancia**: la hoja completa con su **código** (por ejemplo `LR-2026-000001`) y la fecha límite de respuesta. Puede imprimirla o guardarla como PDF desde el navegador.

- El código es correlativo por academia y por año.
- Una hoja registrada **no se edita ni se elimina**.

### El seguimiento público

En `/reclamaciones/seguimiento` el consumidor ingresa su código y su número de documento y ve la hoja, su estado, las fechas de cada cambio y la respuesta. No ve las notas internas ni el nombre de quien lo atendió.

### La atención del personal

En el menú, **Reclamaciones** (grupo Operación).

- Dos cifras: hojas **sin responder** y **fuera de plazo**.
- La lista pone primero lo pendiente, por la fecha límite más próxima. Filtro por estado y búsqueda por código, nombre o documento.
- En cada hoja: marcarla **en revisión**, dejar **notas internas** y **responder**.
- La **respuesta es una sola** y no se cambia después.

| Estado | Qué significa |
| --- | --- |
| Registrado | El consumidor la envió |
| En revisión | El personal la está viendo |
| Respondido | Tiene su respuesta formal |

### El plazo de respuesta

En Configuración hay una sección nueva, **Libro de Reclamaciones**, con el plazo en **días hábiles** (15 por defecto; entre 1 y 60). Se cuenta desde el día de registro en la zona horaria de la academia y no cuenta sábados ni domingos. **Los feriados sí se cuentan como hábiles**: el sistema no tiene un calendario de feriados.

### Quién puede usarlo

| Permiso | Qué permite | Roles base |
| --- | --- | --- |
| `claims.read` | Ver las hojas | Administrador, Soporte |
| `claims.manage` | Marcar en revisión, anotar y responder | Administrador |

Ambos permisos son nuevos. Soporte las ve para dar contexto a quien consulta, pero no responde.

## Detalle técnico

- **Módulo nuevo** `backend/app/Modules/Claims`. Tablas del Maestro §5.1: `claims`, `claim_status_history`, `claim_messages`, con RLS.
- **Rutas públicas** (sin sesión, con límite por dirección IP): `GET /api/v1/claims/form`, `POST /api/v1/claims` (Maestro §7.2; 5 envíos cada 10 minutos) y `POST /api/v1/claims/lookup` (10 por minuto). El seguimiento va por POST para que el documento no quede en la dirección ni en los registros del servidor.
- **Rutas del personal**: `GET /claims`, `GET /claims/{id}` y las acciones `review`, `respond`, `notes`.
- La hoja guarda una copia de los datos del proveedor tal como estaban al registrarse.
- Ajuste nuevo de la academia: `claim_response_days`.
- Auditoría: registro y cada cambio de estado. Código de error nuevo: `CLAIM_ALREADY_ANSWERED`.
- Frontend: `frontend/src/features/claims`.

## Qué se verificó

- **203 pruebas del backend pasan**, 6 nuevas (`tests/Feature/Claims/ClaimTest.php`): recorrido completo hasta la respuesta, lo que ve y no ve el consumidor, validaciones del formulario, menor con apoderado, correlativo que reinicia con el año, plazo según el ajuste y hojas fuera de plazo, día de registro en la zona horaria de la academia, permisos por rol y aislamiento entre academias.
- El frontend compila y pasa el lint.
- **En el navegador, academia demo**: llené el formulario público sin sesión y obtuve la constancia `LR-2026-000001`; como administrador la marqué en revisión, dejé una nota y respondí; en el seguimiento público, con un documento equivocado no aparece y con el correcto se ve el estado y la respuesta, sin la nota interna.
- En esa prueba apareció un error: la fecha límite se calculaba con el día del servidor (ya era el día siguiente) y no con el de la academia. Corregido, con una prueba nueva.
- **No se probó en el navegador**: la impresión de la constancia, un consumidor menor de edad, el ajuste del plazo en Configuración, ni el tamaño de teléfono.

## Qué quedó corto

- **Sin correo**: el consumidor no recibe su constancia ni el aviso de la respuesta por correo; tiene que guardar su código y volver a consultar. La pantalla le recuerda al personal que avise por su cuenta (depende de la decisión 3).
- **Sin feriados** en el cómputo del plazo, y sin alertas automáticas cuando una hoja está por vencer.
- **Sin captcha** en el formulario; solo el límite de envíos por dirección.
- La constancia se imprime desde el navegador; no se genera un PDF propio.
- No hay prórroga del plazo ni respuesta en varias partes.
- El consumidor no puede adjuntar pruebas (fotos, comprobantes).
- No hay un reporte exportable de las hojas para presentar ante la autoridad.
- La academia debe tener cargados razón social, RUC y dirección en Configuración: si faltan, la hoja los muestra vacíos.
