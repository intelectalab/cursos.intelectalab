# Catálogo de estados

> Documento generado con `php artisan docs:technical` a partir del código y de la base de datos. No lo edites a mano: vuelve a generarlo.

Valores fijos que el sistema guarda o calcula: estados, tipos, orígenes y medios. La API los devuelve con estos mismos códigos; la pantalla los traduce. El significado sale del comentario de cada constante en el código; vacío = sin comentario.

## Académico

### CourseActivity

| Constante | Valor | Significado |
| --- | --- | --- |
| `TYPE_QUIZ` | `QUIZ` |  |
| `TYPE_TASK` | `TASK` |  |
| `TYPE_SURVEY` | `SURVEY` |  |

### Question

| Constante | Valor | Significado |
| --- | --- | --- |
| `TYPE_SINGLE` | `SINGLE` |  |
| `TYPE_MULTIPLE` | `MULTIPLE` |  |
| `TYPE_TRUE_FALSE` | `TRUE_FALSE` |  |
| `TYPE_SHORT_TEXT` | `SHORT_TEXT` |  |
| `TYPE_NUMERIC` | `NUMERIC` |  |
| `TYPE_MATCHING` | `MATCHING` |  |
| `TYPE_ORDERING` | `ORDERING` |  |

### QuizAttempt

| Constante | Valor | Significado |
| --- | --- | --- |
| `STATUS_IN_PROGRESS` | `IN_PROGRESS` |  |
| `STATUS_SUBMITTED` | `SUBMITTED` |  |
| `STATUS_EXPIRED` | `EXPIRED` |  |

### TaskSubmission

| Constante | Valor | Significado |
| --- | --- | --- |
| `STATUS_SUBMITTED` | `SUBMITTED` |  |
| `STATUS_GRADED` | `GRADED` |  |
| `STATUS_RETURNED` | `RETURNED` | Devuelta para que el alumno la rehaga |

## Venta a empresas

### License

| Constante | Valor | Significado |
| --- | --- | --- |
| `STATUS_AVAILABLE` | `AVAILABLE` |  |
| `STATUS_ASSIGNED` | `ASSIGNED` |  |
| `STATUS_CANCELLED` | `CANCELLED` | Anulada porque se canceló la venta antes de usarla |
| `STATE_EXPIRED` | `EXPIRED` | Situación calculada: disponible, pero pasó su plazo para asignarla |

### Quotation

| Constante | Valor | Significado |
| --- | --- | --- |
| `STATUS_DRAFT` | `DRAFT` |  |
| `STATUS_SENT` | `SENT` |  |
| `STATUS_ACCEPTED` | `ACCEPTED` |  |
| `STATUS_REJECTED` | `REJECTED` |  |
| `STATE_EXPIRED` | `EXPIRED` | Situación calculada: enviada y fuera de plazo |

## Planes de la plataforma

### Plan

| Constante | Valor | Significado |
| --- | --- | --- |
| `STATUS_ACTIVE` | `ACTIVE` |  |
| `STATUS_ARCHIVED` | `ARCHIVED` |  |

### PlanOrder

| Constante | Valor | Significado |
| --- | --- | --- |
| `STATUS_PENDING` | `PENDING` |  |
| `STATUS_PAID` | `PAID` |  |
| `STATUS_CANCELLED` | `CANCELLED` |  |
| `STATUS_EXPIRED` | `EXPIRED` | Pasó su plazo sin que la organización pagara ni informara un pago |

### TenantSubscription

| Constante | Valor | Significado |
| --- | --- | --- |
| `STATUS_TRIAL` | `TRIAL` |  |
| `STATUS_ACTIVE` | `ACTIVE` |  |
| `STATE_TRIAL` | `TRIAL` | Situación de hoy, deducida del estado y las fechas |
| `STATE_TRIAL_EXPIRED` | `TRIAL_EXPIRED` |  |
| `STATE_ACTIVE` | `ACTIVE` |  |
| `STATE_EXPIRED` | `EXPIRED` |  |
| `STATE_UNLIMITED` | `UNLIMITED` | Sin plan y sin vencimiento: organización anterior a los planes o de cortesía |
| `ACCESS_FULL` | `FULL` | Lo que la organización puede hacer hoy, según su vencimiento |
| `ACCESS_GRACE` | `GRACE` |  |
| `ACCESS_READ_ONLY` | `READ_ONLY` |  |

## Certificados

### Certificate

| Constante | Valor | Significado |
| --- | --- | --- |
| `STATUS_ISSUED` | `ISSUED` |  |
| `STATUS_REVOKED` | `REVOKED` |  |

## Libro de Reclamaciones

### Claim

| Constante | Valor | Significado |
| --- | --- | --- |
| `TYPE_CLAIM` | `RECLAMO` |  |
| `TYPE_COMPLAINT` | `QUEJA` |  |
| `STATUS_RECEIVED` | `RECEIVED` |  |
| `STATUS_IN_REVIEW` | `IN_REVIEW` |  |
| `STATUS_ANSWERED` | `ANSWERED` |  |

## Páginas

### Page

| Constante | Valor | Significado |
| --- | --- | --- |
| `STATUS_DRAFT` | `DRAFT` |  |
| `STATUS_PUBLISHED` | `PUBLISHED` |  |

## Cursos

### Course

| Constante | Valor | Significado |
| --- | --- | --- |
| `STATUS_DRAFT` | `DRAFT` |  |
| `STATUS_PUBLISHED` | `PUBLISHED` |  |
| `STATUS_ARCHIVED` | `ARCHIVED` |  |

### CourseResource

| Constante | Valor | Significado |
| --- | --- | --- |
| `TYPE_VIDEO` | `VIDEO` |  |
| `TYPE_DOCUMENT` | `DOCUMENT` |  |
| `TYPE_LINK` | `LINK` |  |
| `TYPE_TEXT` | `TEXT` |  |

## CRM

### Company

| Constante | Valor | Significado |
| --- | --- | --- |
| `STATUS_PROSPECT` | `PROSPECT` |  |
| `STATUS_CUSTOMER` | `CUSTOMER` |  |

### Lead

| Constante | Valor | Significado |
| --- | --- | --- |
| `STATUS_NEW` | `NEW` |  |
| `STATUS_CONTACTED` | `CONTACTED` |  |
| `STATUS_CONVERTED` | `CONVERTED` |  |
| `STATUS_DISCARDED` | `DISCARDED` |  |

### Opportunity

| Constante | Valor | Significado |
| --- | --- | --- |
| `STAGE_NEW` | `NEW` |  |
| `STAGE_CONTACTED` | `CONTACTED` |  |
| `STAGE_PROPOSAL` | `PROPOSAL` |  |
| `STAGE_NEGOTIATION` | `NEGOTIATION` |  |
| `STAGE_WON` | `WON` |  |
| `STAGE_LOST` | `LOST` |  |

### OpportunityActivity

| Constante | Valor | Significado |
| --- | --- | --- |
| `TYPE_STAGE` | `STAGE` | Cambio de etapa: lo registra el sistema |

## Matrículas y aula

### Enrollment

| Constante | Valor | Significado |
| --- | --- | --- |
| `STATUS_ACTIVE` | `ACTIVE` | Estado guardado: la matrícula existe o fue cancelada |
| `STATUS_CANCELLED` | `CANCELLED` |  |
| `STATE_UPCOMING` | `UPCOMING` | Situaciones que se deducen de las fechas (no se guardan) |
| `STATE_EXPIRED` | `EXPIRED` |  |
| `STATE_COMPLETED` | `COMPLETED` |  |
| `SOURCE_MANUAL` | `MANUAL` |  |
| `SOURCE_PURCHASE` | `PURCHASE` | La creó el pago de una orden de compra |
| `SOURCE_LICENSE` | `LICENSE` | La creó la asignación de una licencia corporativa |

### EnrollmentProgress

| Constante | Valor | Significado |
| --- | --- | --- |
| `TYPE_RESOURCE` | `RESOURCE` |  |
| `TYPE_ACTIVITY` | `ACTIVITY` |  |

## Archivos

### File

| Constante | Valor | Significado |
| --- | --- | --- |
| `VISIBILITY_PUBLIC` | `public` |  |
| `VISIBILITY_PRIVATE` | `private` |  |

## Identidad y accesos

### User

| Constante | Valor | Significado |
| --- | --- | --- |
| `STATUS_ACTIVE` | `ACTIVE` |  |
| `STATUS_INACTIVE` | `INACTIVE` |  |

## Consola de plataforma

### PlatformAdmin

| Constante | Valor | Significado |
| --- | --- | --- |
| `STATUS_ACTIVE` | `ACTIVE` |  |

## Promociones

### Promotion

| Constante | Valor | Significado |
| --- | --- | --- |
| `TYPE_PERCENT` | `PERCENT` |  |
| `TYPE_FIXED` | `FIXED` |  |
| `STATUS_ACTIVE` | `ACTIVE` |  |
| `STATUS_INACTIVE` | `INACTIVE` |  |
| `PRODUCT_COURSE` | `COURSE` |  |
| `PRODUCT_PACKAGE` | `PACKAGE` |  |

## Ventas y pagos

### ExchangeRate

| Constante | Valor | Significado |
| --- | --- | --- |
| `SOURCE_MANUAL` | `MANUAL` | Lo escribió una persona |
| `SOURCE_SUNAT` | `SUNAT` | Tipo de cambio oficial publicado por SUNAT |

### Order

| Constante | Valor | Significado |
| --- | --- | --- |
| `STATUS_PENDING` | `PENDING` |  |
| `STATUS_PAID` | `PAID` |  |
| `STATUS_CANCELLED` | `CANCELLED` |  |
| `STATUS_EXPIRED` | `EXPIRED` |  |
| `STATUS_REFUNDED` | `REFUNDED` | Se pagó y después se devolvió el dinero completo |

### Package

| Constante | Valor | Significado |
| --- | --- | --- |
| `STATUS_ACTIVE` | `ACTIVE` |  |
| `STATUS_INACTIVE` | `INACTIVE` |  |

### Payment

| Constante | Valor | Significado |
| --- | --- | --- |
| `STATUS_CONFIRMED` | `CONFIRMED` |  |
| `STATUS_REFUNDED` | `REFUNDED` |  |
| `METHOD_TRANSFER` | `TRANSFER` |  |
| `METHOD_YAPE` | `YAPE` |  |
| `METHOD_CASH` | `CASH` |  |
| `METHOD_OTHER` | `OTHER` |  |
| `METHOD_CARD` | `CARD` | Solo llegan por una pasarela: tarjeta y saldo de la cuenta del comprador en ella |
| `METHOD_WALLET` | `WALLET` |  |
| `METHOD_PAYPAL` | `PAYPAL` |  |

### PaymentAttempt

| Constante | Valor | Significado |
| --- | --- | --- |
| `STATUS_CREATED` | `CREATED` |  |
| `STATUS_APPROVED` | `APPROVED` |  |
| `STATUS_REJECTED` | `REJECTED` |  |
| `STATUS_NEEDS_REVIEW` | `NEEDS_REVIEW` | La pasarela cobró, pero el pago no se pudo aplicar a la orden: lo resuelve una persona |

## Soporte

### Ticket

| Constante | Valor | Significado |
| --- | --- | --- |
| `STATUS_OPEN` | `OPEN` |  |
| `STATUS_IN_PROGRESS` | `IN_PROGRESS` |  |
| `STATUS_RESOLVED` | `RESOLVED` |  |
| `STATUS_CLOSED` | `CLOSED` |  |

## Organización y configuración

### Tenant

| Constante | Valor | Significado |
| --- | --- | --- |
| `STATUS_ACTIVE` | `ACTIVE` |  |
| `STATUS_SUSPENDED` | `SUSPENDED` |  |

