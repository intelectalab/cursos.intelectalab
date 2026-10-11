# Diccionario de datos

> Documento generado con `php artisan docs:technical` a partir del código y de la base de datos. No lo edites a mano: vuelve a generarlo.

Base de datos PostgreSQL. Todas las claves primarias `id` son UUID. El dinero se guarda como decimal, nunca como número de coma flotante. Las descripciones salen de los comentarios de las migraciones; las columnas comunes (identificador, academia, fechas) y las referencias a otras tablas llevan una descripción genérica. Una celda vacía significa que la columna no tiene comentario.

**Aislamiento:** las tablas marcadas con «Aislada por academia» tienen `tenant_id` y seguridad por filas forzada en la base: una academia no puede leer filas de otra aunque la consulta lo intente.

**Claves:** PK = clave primaria; FK = referencia a otra tabla; U = forma parte de una restricción de unicidad.

## Académico

### `course_activities`

Aislada por academia.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `course_id` | UUID | FK courses.id | Sí | Referencia a `courses` |
| `section_id` | UUID | FK course_sections.id | Sí | Referencia a `course_sections` |
| `type` | texto (20) |  | Sí |  |
| `title` | texto (200) |  | Sí | Título |
| `description` | texto largo |  | No | Descripción |
| `position` | entero |  | Sí | Orden dentro de su lista |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |
| `weight` | decimal (5,2) |  | Sí | Peso del cuestionario o la tarea en la nota final del curso; 0 = no cuenta |

### `question_banks`

Aislada por academia.
Único: `tenant_id, name`.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id, U | Sí | Academia a la que pertenece el registro |
| `name` | texto (150) | U | Sí | Nombre |
| `description` | texto (300) |  | No | Descripción |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |

### `questions`

Aislada por academia.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `question_bank_id` | UUID | FK question_banks.id | Sí | Referencia a `question_banks` |
| `type` | texto (20) |  | Sí |  |
| `statement` | texto largo |  | Sí |  |
| `config` | JSON |  | Sí | Opciones y respuesta correcta; la forma depende del tipo (QuestionConfig) |
| `explanation` | texto largo |  | No | Comentario que se muestra al alumno después de responder |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |

### `quiz_answers`

Aislada por academia.
Único: `attempt_id, question_id`.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `attempt_id` | UUID | FK quiz_attempts.id, U | Sí | Referencia a `quiz_attempts` |
| `question_id` | UUID | U | Sí |  |
| `answer` | JSON |  | No |  |
| `score` | decimal (5,4) |  | Sí | Fracción obtenida (0 a 1) y puntos que representa |
| `points_possible` | decimal (6,2) |  | Sí |  |
| `points_earned` | decimal (8,4) |  | Sí |  |

### `quiz_attempt_grants`

Aislada por academia.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `enrollment_id` | UUID | FK enrollments.id | Sí | Referencia a `enrollments` |
| `course_id` | UUID | FK courses.id | Sí | Referencia a `courses` |
| `activity_id` | UUID |  | Sí | Actividad de la versión publicada (sin FK, como los intentos) |
| `attempts` | entero |  | Sí |  |
| `reason` | texto (300) |  | Sí |  |
| `granted_by` | UUID | FK users.id | No | Referencia a `users` |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |

### `quiz_attempts`

Aislada por academia.
Único: `enrollment_id, activity_id, number`.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `enrollment_id` | UUID | FK enrollments.id, U | Sí | Referencia a `enrollments` |
| `student_id` | UUID | FK users.id | Sí | Referencia a `users` |
| `course_id` | UUID | FK courses.id | Sí | Referencia a `courses` |
| `course_version_id` | UUID | FK course_versions.id | Sí | Referencia a `course_versions` |
| `activity_id` | UUID | U | Sí |  |
| `number` | entero | U | Sí | Número correlativo |
| `status` | texto (20) |  | Sí | IN_PROGRESS, SUBMITTED, EXPIRED |
| `questions` | JSON |  | Sí | Preguntas tal como se le presentaron, con sus respuestas: solo para calificar |
| `passing_percent` | decimal (5,2) |  | Sí |  |
| `started_at` | fecha y hora |  | Sí |  |
| `expires_at` | fecha y hora |  | No |  |
| `submitted_at` | fecha y hora |  | No |  |
| `score_percent` | decimal (5,2) |  | No |  |
| `passed` | sí/no |  | No |  |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |
| `draft_answers` | JSON |  | No | Lo que el alumno lleva marcado en un intento en curso, para retomarlo. Las respuestas definitivas siguen en quiz_answers, al enviar. |

### `quiz_questions`

Aislada por academia.
Único: `quiz_id, question_id`.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `quiz_id` | UUID | FK quizzes.id, U | Sí | Referencia a `quizzes` |
| `question_id` | UUID | FK questions.id, U | Sí | Una pregunta en uso no se borra del banco |
| `position` | entero |  | Sí | Orden dentro de su lista |
| `points` | decimal (6,2) |  | Sí |  |

### `quizzes`

Aislada por academia.
Único: `activity_id`.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `activity_id` | UUID | FK course_activities.id, U | Sí | Referencia a `course_activities` |
| `passing_percent` | decimal (5,2) |  | Sí | Porcentaje mínimo para aprobar; se muestra en la escala de la organización |
| `max_attempts` | entero |  | No | null = intentos ilimitados |
| `time_limit_minutes` | entero |  | No |  |
| `shuffle` | sí/no |  | Sí |  |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |

### `survey_responses`

Aislada por academia.
Único: `enrollment_id, activity_id`.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `enrollment_id` | UUID | FK enrollments.id, U | Sí | Referencia a `enrollments` |
| `student_id` | UUID | FK users.id | Sí | Referencia a `users` |
| `course_id` | UUID | FK courses.id | Sí | Referencia a `courses` |
| `activity_id` | UUID | U | Sí |  |
| `answers` | JSON |  | Sí |  |
| `submitted_at` | fecha y hora |  | Sí |  |

### `surveys`

Aislada por academia.
Único: `activity_id`.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `activity_id` | UUID | FK course_activities.id, U | Sí | Referencia a `course_activities` |
| `questions` | JSON |  | Sí | Preguntas de escala, opción o texto libre (SurveyConfig) |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |

### `task_submissions`

Aislada por academia.
Único: `enrollment_id, activity_id`.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `enrollment_id` | UUID | FK enrollments.id, U | Sí | Referencia a `enrollments` |
| `student_id` | UUID | FK users.id | Sí | Referencia a `users` |
| `course_id` | UUID | FK courses.id | Sí | Referencia a `courses` |
| `activity_id` | UUID | U | Sí |  |
| `body` | texto largo |  | No |  |
| `file_id` | UUID | FK files.id | No | Referencia a `files` |
| `status` | texto (20) |  | Sí | SUBMITTED (por revisar) o GRADED |
| `submitted_at` | fecha y hora |  | Sí |  |
| `grade_percent` | decimal (5,2) |  | No |  |
| `feedback` | texto largo |  | No |  |
| `graded_by` | UUID | FK users.id | No | Referencia a `users` |
| `graded_at` | fecha y hora |  | No |  |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |
| `is_late` | sí/no |  | Sí |  |
| `returned_at` | fecha y hora |  | No | RETURNED: quien revisa la devolvió para que el alumno la rehaga |
| `returned_by` | UUID | FK users.id | No | Referencia a `users` |

### `tasks`

Aislada por academia.
Único: `activity_id`.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `activity_id` | UUID | FK course_activities.id, U | Sí | Referencia a `course_activities` |
| `allow_text` | sí/no |  | Sí | Qué puede entregar el alumno |
| `allow_file` | sí/no |  | Sí |  |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |
| `due_days` | entero |  | No | Días desde el inicio de la matrícula; vacío = sin plazo |
| `accept_late` | sí/no |  | Sí | Pasado el plazo: se recibe marcada como tardía, o ya no se recibe |

## Auditoría

### `audit_events`

Aislada por academia.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `actor_user_id` | UUID | FK users.id | No | Referencia a `users` |
| `action` | texto (100) |  | Sí |  |
| `entity_type` | texto (100) |  | Sí |  |
| `entity_id` | UUID |  | No |  |
| `before` | JSON |  | No |  |
| `after` | JSON |  | No |  |
| `ip_address` | texto (45) |  | No |  |
| `user_agent` | texto largo |  | No |  |
| `request_id` | texto (64) |  | No |  |
| `created_at` | fecha y hora |  | Sí | Cuándo se creó |

## Venta a empresas

### `corporate_accounts`

Aislada por academia.
Único: `company_id`.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `company_id` | UUID | FK companies.id, U | Sí | Referencia a `companies` |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |

### `corporate_members`

Aislada por academia.
Único: `user_id`.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `corporate_account_id` | UUID | FK corporate_accounts.id | Sí | Referencia a `corporate_accounts` |
| `user_id` | UUID | FK users.id, U | Sí | Una persona pertenece a una sola empresa |
| `is_manager` | sí/no |  | Sí | Responsable de empresa: administra las licencias en el portal |
| `created_by` | UUID | FK users.id | No | Referencia a `users` |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |

### `license_assignments`

Aislada por academia.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `license_id` | UUID | FK licenses.id | Sí | Referencia a `licenses` |
| `student_id` | UUID | FK users.id | Sí | Referencia a `users` |
| `enrollment_id` | UUID | FK enrollments.id | No | Matrícula que creó la asignación |
| `assigned_by` | UUID | FK users.id | No | Referencia a `users` |
| `assigned_at` | fecha y hora |  | Sí |  |
| `revoked_at` | fecha y hora |  | No | Retirada: la licencia volvió a quedar disponible |
| `revoked_by` | UUID | FK users.id | No | Referencia a `users` |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |

### `licenses`

Aislada por academia.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `corporate_account_id` | UUID | FK corporate_accounts.id | Sí | Referencia a `corporate_accounts` |
| `course_id` | UUID | FK courses.id | Sí | Producto licenciado (Maestro §6): hoy solo cursos; los paquetes llegan con S11 |
| `order_id` | UUID | FK orders.id | Sí | Venta que la originó |
| `status` | texto (20) |  | Sí | AVAILABLE, ASSIGNED, CANCELLED |
| `expires_at` | fecha y hora |  | Sí | Hasta cuándo se puede asignar |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |

### `quotation_items`

Aislada por academia.
Único: `quotation_id, course_id`.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `quotation_id` | UUID | FK quotations.id, U | Sí | Referencia a `quotations` |
| `course_id` | UUID | FK courses.id, U | Sí | Referencia a `courses` |
| `title` | texto (200) |  | Sí | Título del curso al cotizar |
| `quantity` | entero |  | Sí |  |
| `list_price` | decimal (12,2) |  | No | Precio de lista del curso al cotizar (referencia) y precio pactado por licencia |
| `unit_price` | decimal (12,2) |  | Sí |  |
| `total_amount` | decimal (12,2) |  | Sí |  |
| `position` | entero |  | Sí | Orden dentro de su lista |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |

### `quotation_versions`

Aislada por academia.
Único: `quotation_id, version`.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `quotation_id` | UUID | FK quotations.id, U | Sí | Referencia a `quotations` |
| `version` | entero | U | Sí |  |
| `snapshot` | JSON |  | Sí |  |
| `total_amount` | decimal (12,2) |  | Sí |  |
| `sent_by` | UUID | FK users.id | No | Referencia a `users` |
| `sent_at` | fecha y hora |  | Sí |  |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |

### `quotations`

Aislada por academia.
Único: `tenant_id, number`.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id, U | Sí | Academia a la que pertenece el registro |
| `number` | entero | U | Sí | Correlativo por organización |
| `company_id` | UUID | FK companies.id | Sí | Referencia a `companies` |
| `contact_id` | UUID | FK contacts.id | No | Referencia a `contacts` |
| `opportunity_id` | UUID | FK opportunities.id | No | Referencia a `opportunities` |
| `currency_id` | UUID | FK currencies.id | Sí | Referencia a `currencies` |
| `status` | texto (20) |  | Sí | DRAFT, SENT, ACCEPTED, REJECTED |
| `version` | entero |  | Sí | Sube cada vez que se vuelve a editar una cotización ya enviada |
| `valid_until` | fecha |  | Sí | Último día en que el cliente puede aceptarla |
| `notes` | texto largo |  | No | Notas |
| `total_amount` | decimal (12,2) |  | Sí |  |
| `sent_at` | fecha y hora |  | No |  |
| `accepted_at` | fecha y hora |  | No |  |
| `accepted_by` | UUID | FK users.id | No | Referencia a `users` |
| `accepted_via` | texto (10) |  | No | PORTAL = la aceptó el responsable de la empresa; STAFF = la registró el personal |
| `acceptance_note` | texto (500) |  | No |  |
| `rejected_at` | fecha y hora |  | No |  |
| `reject_reason` | texto (500) |  | No |  |
| `order_id` | UUID | FK orders.id | No | Venta que generó la aceptación |
| `created_by` | UUID | FK users.id | No | Referencia a `users` |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |

## Planes de la plataforma

### `plan_orders`

Aislada por academia.
Único: `number`.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `number` | entero | U | Sí | Número correlativo |
| `plan_id` | UUID | FK plans.id | Sí | Referencia a `plans` |
| `plan_name` | texto (80) |  | Sí | El plan tal como se ofrecía al pedirlo: un cambio posterior no altera la orden |
| `billing_months` | entero |  | Sí |  |
| `max_students` | entero |  | No |  |
| `amount` | decimal (10,2) |  | Sí |  |
| `currency` | texto fijo (3) |  | Sí |  |
| `status` | texto (20) |  | Sí | PENDING, PAID o CANCELLED |
| `requested_by` | UUID | FK users.id | No | Referencia a `users` |
| `payer_reference` | texto (300) |  | No | Lo que la organización informa de su pago (número de operación, fecha…) |
| `payment_method` | texto (20) |  | No |  |
| `payment_reference` | texto (120) |  | No |  |
| `notes` | texto (500) |  | No | Notas |
| `resolved_by` | texto (180) |  | No | Correo del administrador de plataforma que confirmó o canceló |
| `paid_at` | fecha y hora |  | No |  |
| `cancelled_at` | fecha y hora |  | No |  |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |
| `expires_at` | fecha y hora |  | No | Hasta cuándo espera su pago; pasado el plazo vence sola si no se informó ningún pago |
| `proof_file_id` | UUID | FK files.id | No | Constancia de pago que adjunta la organización |
| `previous_subscription` | JSON |  | No | Cómo estaba la suscripción antes de confirmar, y hasta cuándo quedó: permite deshacer |
| `applied_period_ends_at` | fecha y hora |  | No |  |
| `outcome_seen_at` | fecha y hora |  | No | La organización ya vio cómo se resolvió su orden (confirmada, cancelada o vencida) |
| `gateway` | texto (30) |  | No | Pago en línea: pasarela, cobro preparado en ella y pago con el que quedó pagada |
| `gateway_checkout_id` | texto (120) |  | No |  |
| `gateway_payment_id` | texto (120) |  | No |  |
| `review_reason` | texto (300) |  | No | La pasarela cobró y no se pudo aplicar: lo resuelve una persona |
| `gateway_amount` | decimal (10,2) |  | No | Lo que se le pidió cobrar a la pasarela, si no fue el importe de la orden tal cual |
| `gateway_currency` | texto fijo (3) |  | No |  |

### `plans`

Sin aislamiento por academia (dato de plataforma o del framework).

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `name` | texto (80) |  | Sí | Nombre |
| `description` | texto (300) |  | No | Descripción |
| `price` | decimal (10,2) |  | Sí |  |
| `currency` | texto fijo (3) |  | Sí |  |
| `billing_months` | entero |  | Sí | Cada cuántos meses se paga: 1 = mensual, 12 = anual |
| `max_students` | entero |  | No | Tope de alumnos con matrícula activa; null = sin tope |
| `status` | texto (20) |  | Sí | ACTIVE (se ofrece) o ARCHIVED (ya no se ofrece; quien lo tiene lo conserva) |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |

### `platform_settings`

Sin aislamiento por academia (dato de plataforma o del framework).

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `key` | texto (60) | PK | Sí |  |
| `value` | JSON |  | No |  |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |

### `tenant_subscriptions`

Aislada por academia.
Único: `tenant_id`.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id, U | Sí | Academia a la que pertenece el registro |
| `plan_id` | UUID | FK plans.id | No | Referencia a `plans` |
| `status` | texto (20) |  | Sí | TRIAL (prueba gratuita) o ACTIVE (con plan, o sin límites si no tiene plan) |
| `trial_ends_at` | fecha y hora |  | No |  |
| `current_period_ends_at` | fecha y hora |  | No | Hasta cuándo está pagado el plan; null = sin vencimiento |
| `max_students` | entero |  | No | Tope vigente de alumnos: el de la prueba o el del plan al asignarlo; null = sin tope |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |

## Certificados

### `certificate_templates`

Aislada por academia.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `signer_name` | texto (150) |  | No |  |
| `signer_title` | texto (150) |  | No |  |
| `signature_file_id` | UUID | FK files.id | No | Referencia a `files` |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |
| `background_file_id` | UUID | FK files.id | No | Diseño propio de la organización: su imagen de fondo y dónde va cada dato |
| `design` | JSON |  | No |  |
| `layout` | JSON |  | No | Diseño libre del editor visual: orientación de la hoja y cada elemento con su posición en milímetros. NULL = se usa el diseño por opciones de la columna design |
| `name` | texto (100) |  | Sí | Nombre con el que la organización la reconoce |
| `is_default` | sí/no |  | Sí | La que usan los cursos que no eligieron una |

### `certificate_verifications`

Aislada por academia.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `certificate_id` | UUID | FK certificates.id | No | Referencia a `certificates` |
| `code` | texto (40) |  | Sí |  |
| `result` | texto (20) |  | Sí | VALID, REVOKED, NOT_FOUND |
| `ip_address` | texto (45) |  | No |  |
| `user_agent` | texto (500) |  | No |  |
| `created_at` | fecha y hora |  | Sí | Cuándo se creó |

### `certificates`

Aislada por academia.
Único: `verification_code`.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `enrollment_id` | UUID | FK enrollments.id | Sí | Matrícula de origen (Maestro §6) |
| `course_id` | UUID | FK courses.id | Sí | Referencia a `courses` |
| `student_id` | UUID | FK users.id | Sí | Referencia a `users` |
| `verification_code` | texto (20) | U | Sí | Código público con el que cualquiera verifica el certificado |
| `status` | texto (20) |  | Sí | ISSUED, REVOKED |
| `snapshot` | JSON |  | Sí | Lo que dice el certificado, tal como se emitió: no cambia si después cambian el curso o el alumno |
| `issued_at` | fecha y hora |  | Sí |  |
| `issued_by` | UUID | FK users.id | No | null = lo emitió el sistema al completarse el curso |
| `revoked_at` | fecha y hora |  | No |  |
| `revoked_by` | UUID | FK users.id | No | Referencia a `users` |
| `revoke_reason` | texto (500) |  | No |  |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |
| `design` | JSON |  | No | Diseño con el que se emitió, congelado: un cambio posterior de la plantilla no lo altera. NULL = emitido sin plantilla guardada; se dibuja con la plantilla vigente de su curso |

## Libro de Reclamaciones

### `claim_messages`

Aislada por academia.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `claim_id` | UUID | FK claims.id | Sí | Referencia a `claims` |
| `author_id` | UUID | FK users.id | No | Referencia a `users` |
| `body` | texto largo |  | Sí |  |
| `is_public` | sí/no |  | Sí | true = respuesta al consumidor; false = nota interna del personal |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |

### `claim_status_history`

Aislada por academia.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `claim_id` | UUID | FK claims.id | Sí | Referencia a `claims` |
| `from_status` | texto (20) |  | No |  |
| `to_status` | texto (20) |  | Sí |  |
| `changed_by` | UUID | FK users.id | No | null = lo registró el consumidor desde el formulario público |
| `created_at` | fecha y hora |  | Sí | Cuándo se creó |

### `claims`

Aislada por academia.
Único: `tenant_id, code`.
Único: `tenant_id, year, number`.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id, U | Sí | Academia a la que pertenece el registro |
| `year` | entero | U | Sí | Correlativo por organización y año (RN-018) |
| `number` | entero | U | Sí | Número correlativo |
| `code` | texto (20) | U | Sí | Código que recibe el consumidor: LR-2026-000001 |
| `type` | texto (10) |  | Sí | RECLAMO = disconformidad con el producto o servicio; QUEJA = malestar con la atención |
| `first_name` | texto (100) |  | Sí | Consumidor |
| `last_name` | texto (100) |  | Sí |  |
| `doc_type` | texto (12) |  | Sí |  |
| `doc_number` | texto (20) |  | Sí |  |
| `address` | texto (250) |  | Sí |  |
| `email` | texto (150) |  | Sí | Correo electrónico |
| `phone` | texto (30) |  | No | Teléfono |
| `is_minor` | sí/no |  | Sí | Menor de edad: datos del padre, madre o apoderado |
| `guardian_name` | texto (200) |  | No |  |
| `guardian_doc_number` | texto (20) |  | No |  |
| `good_type` | texto (10) |  | Sí | Bien contratado |
| `good_description` | texto (500) |  | Sí |  |
| `amount` | decimal (12,2) |  | No |  |
| `currency` | texto (3) |  | No |  |
| `order_reference` | texto (60) |  | No | Número de orden o comprobante, si el consumidor lo indica |
| `detail` | texto largo |  | Sí |  |
| `request` | texto largo |  | Sí |  |
| `status` | texto (20) |  | Sí | RECEIVED, IN_REVIEW, ANSWERED |
| `provider` | JSON |  | Sí | Datos del proveedor tal como figuraban al registrarse |
| `due_at` | fecha |  | Sí | Fecha límite para responder |
| `answered_at` | fecha y hora |  | No |  |
| `ip_address` | texto (45) |  | No |  |
| `user_agent` | texto (500) |  | No |  |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |

## Páginas

### `media`

Aislada por academia.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `file_id` | UUID | FK files.id | Sí | Referencia a `files` |
| `uploaded_by` | UUID | FK users.id | No | Referencia a `users` |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |

### `page_versions`

Aislada por academia.
Único: `page_id, version`.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `page_id` | UUID | FK pages.id, U | Sí | Referencia a `pages` |
| `version` | entero | U | Sí |  |
| `title` | texto (150) |  | Sí | Título |
| `content` | JSON |  | Sí |  |
| `published_by` | UUID | FK users.id | No | Referencia a `users` |
| `published_at` | fecha y hora |  | Sí |  |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |

### `pages`

Aislada por academia.
Único: `tenant_id, slug`.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id, U | Sí | Academia a la que pertenece el registro |
| `slug` | texto (80) | U | Sí | Parte final de la dirección pública: /p/{slug} |
| `title` | texto (150) |  | Sí | Título |
| `content` | JSON |  | Sí | Documento estructurado del editor (no HTML): solo los nodos que admite PageContent |
| `show_in_footer` | sí/no |  | Sí | Se enlaza al pie de las páginas públicas |
| `status` | texto (20) |  | Sí | DRAFT = nunca publicada o retirada; PUBLISHED = tiene una versión a la vista |
| `current_version_id` | UUID |  | No |  |
| `published_at` | fecha y hora |  | No |  |
| `draft_updated_at` | fecha y hora |  | Sí | Última vez que cambió el borrador |
| `created_by` | UUID | FK users.id | No | Referencia a `users` |
| `updated_by` | UUID | FK users.id | No | Referencia a `users` |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |
| `review_status` | texto (20) |  | No | NULL = sin revisión pedida; IN_REVIEW = espera a quien publica; CHANGES_REQUESTED = devuelta con observaciones |
| `review_requested_by` | UUID | FK users.id | No | Quién envió el borrador a revisión y cuándo |
| `review_requested_at` | fecha y hora |  | No |  |
| `review_comment` | texto largo |  | No | Observaciones de quien revisó, cuando devuelve la página |
| `reviewed_by` | UUID | FK users.id | No | Quién devolvió la página y cuándo |
| `reviewed_at` | fecha y hora |  | No |  |

## Cursos

### `course_resources`

Aislada por academia.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `course_id` | UUID | FK courses.id | Sí | Referencia a `courses` |
| `section_id` | UUID | FK course_sections.id | Sí | Referencia a `course_sections` |
| `type` | texto (20) |  | Sí | VIDEO (enlace a YouTube), DOCUMENT, LINK, TEXT |
| `title` | texto (200) |  | Sí | Título |
| `position` | entero |  | Sí | Orden dentro de su lista |
| `url` | texto (2000) |  | No |  |
| `body` | texto largo |  | No |  |
| `file_id` | UUID | FK files.id | No | Referencia a `files` |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |

### `course_sections`

Aislada por academia.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `course_id` | UUID | FK courses.id | Sí | Referencia a `courses` |
| `title` | texto (200) |  | Sí | Título |
| `position` | entero |  | Sí | Orden dentro de su lista |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |

### `course_teachers`

Aislada por academia.
Único: `course_id, user_id`.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `course_id` | UUID | FK courses.id, U | Sí | Referencia a `courses` |
| `user_id` | UUID | FK users.id, U | Sí | Referencia a `users` |
| `created_at` | fecha y hora |  | No | Cuándo se creó |

### `course_versions`

Aislada por academia.
Único: `course_id, number`.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `course_id` | UUID | FK courses.id, U | Sí | Referencia a `courses` |
| `number` | entero | U | Sí | Número correlativo |
| `note` | texto (300) |  | No |  |
| `snapshot` | JSON |  | Sí |  |
| `published_by` | UUID | FK users.id | No | Referencia a `users` |
| `published_at` | fecha y hora |  | Sí |  |

### `courses`

Aislada por academia.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `title` | texto (200) |  | Sí | Título |
| `summary` | texto (300) |  | No |  |
| `description` | texto largo |  | No | Descripción |
| `category` | texto (80) |  | No |  |
| `level` | texto (20) |  | No |  |
| `duration_hours` | entero |  | No |  |
| `language` | texto (5) |  | Sí |  |
| `cover_file_id` | UUID | FK files.id | No | Referencia a `files` |
| `status` | texto (20) |  | Sí | DRAFT, PUBLISHED, ARCHIVED (Maestro §6) |
| `current_version_id` | UUID | FK course_versions.id | No | Versión que ven los alumnos; null mientras no se publique |
| `has_pending_changes` | sí/no |  | Sí | El borrador difiere de la última versión publicada |
| `published_at` | fecha y hora |  | No |  |
| `archived_at` | fecha y hora |  | No |  |
| `created_by` | UUID | FK users.id | No | Referencia a `users` |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |
| `access_days` | entero |  | No | Días de acceso que recibe cada matrícula nueva. Vacío = no vence. La regla de vigencia no se fija en el código (PD-005): la define cada organización por curso. |
| `price` | decimal (10,2) |  | No | Precio de venta en la moneda de la organización. Vacío = no está en venta; 0 = gratuito. Regla comercial, no contenido: no forma parte de las versiones publicadas. |
| `issues_certificate` | sí/no |  | Sí | El curso entrega certificado a quien lo completa (RN-007). Regla de la organización, no contenido. |
| `sequential` | sí/no |  | Sí | Avance secuencial: una sección se abre al completar la anterior |
| `passing_percent` | decimal (5,2) |  | No | Nota final mínima para aprobar el curso, en porcentaje; vacío = no se exige |
| `certificate_template_id` | UUID | FK certificate_templates.id | No | Plantilla de certificado del curso; NULL = la principal de la organización |

## CRM

### `companies`

Aislada por academia.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `name` | texto (200) |  | Sí | Nombre |
| `tax_id` | texto (20) |  | No | RUC u otro identificador tributario |
| `industry` | texto (100) |  | No |  |
| `website` | texto (200) |  | No |  |
| `email` | texto (150) |  | No | Correo electrónico |
| `phone` | texto (30) |  | No | Teléfono |
| `address` | texto (250) |  | No |  |
| `city` | texto (100) |  | No |  |
| `notes` | texto largo |  | No | Notas |
| `status` | texto (20) |  | Sí | PROSPECT, CUSTOMER (pasa a cliente con su primera venta) |
| `owner_id` | UUID | FK users.id | No | Referencia a `users` |
| `created_by` | UUID | FK users.id | No | Referencia a `users` |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |

### `contacts`

Aislada por academia.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `company_id` | UUID | FK companies.id | Sí | Referencia a `companies` |
| `first_name` | texto (100) |  | Sí |  |
| `last_name` | texto (100) |  | No |  |
| `position` | texto (120) |  | No | Orden dentro de su lista |
| `email` | texto (150) |  | No | Correo electrónico |
| `phone` | texto (30) |  | No | Teléfono |
| `is_primary` | sí/no |  | Sí |  |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |

### `leads`

Aislada por academia.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `first_name` | texto (100) |  | Sí |  |
| `last_name` | texto (100) |  | No |  |
| `company_name` | texto (200) |  | No |  |
| `position` | texto (120) |  | No | Orden dentro de su lista |
| `email` | texto (150) |  | No | Correo electrónico |
| `phone` | texto (30) |  | No | Teléfono |
| `source` | texto (20) |  | Sí | WEB, REFERRAL, EVENT, CALL, SOCIAL, OTHER |
| `interest` | texto largo |  | No |  |
| `status` | texto (20) |  | Sí | NEW, CONTACTED, CONVERTED, DISCARDED |
| `discard_reason` | texto (500) |  | No |  |
| `owner_id` | UUID | FK users.id | No | Referencia a `users` |
| `created_by` | UUID | FK users.id | No | Referencia a `users` |
| `converted_at` | fecha y hora |  | No | Rastro de la conversión: en qué empresa, contacto y oportunidad terminó |
| `converted_by` | UUID | FK users.id | No | Referencia a `users` |
| `company_id` | UUID | FK companies.id | No | Referencia a `companies` |
| `contact_id` | UUID | FK contacts.id | No | Referencia a `contacts` |
| `opportunity_id` | UUID | FK opportunities.id | No | Referencia a `opportunities` |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |

### `opportunities`

Aislada por academia.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `company_id` | UUID | FK companies.id | Sí | Referencia a `companies` |
| `contact_id` | UUID | FK contacts.id | No | Referencia a `contacts` |
| `title` | texto (200) |  | Sí | Título |
| `stage` | texto (20) |  | Sí | NEW, CONTACTED, PROPOSAL, NEGOTIATION, WON, LOST |
| `estimated_amount` | decimal (12,2) |  | No | Estimación del vendedor en la moneda de la organización; el importe real lo fija la cotización |
| `expected_close_date` | fecha |  | No |  |
| `notes` | texto largo |  | No | Notas |
| `lost_reason` | texto (500) |  | No |  |
| `closed_at` | fecha y hora |  | No |  |
| `owner_id` | UUID | FK users.id | No | Referencia a `users` |
| `created_by` | UUID | FK users.id | No | Referencia a `users` |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |

### `opportunity_activities`

Aislada por academia.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `opportunity_id` | UUID | FK opportunities.id | Sí | Referencia a `opportunities` |
| `type` | texto (20) |  | Sí | CALL, MEETING, EMAIL, NOTE; STAGE lo registra el sistema |
| `notes` | texto largo |  | Sí | Notas |
| `occurred_at` | fecha y hora |  | Sí |  |
| `created_by` | UUID | FK users.id | No | Referencia a `users` |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |

## Matrículas y aula

### `enrollment_progress`

Aislada por academia.
Único: `enrollment_id, item_type, item_id`.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `enrollment_id` | UUID | FK enrollments.id, U | Sí | Referencia a `enrollments` |
| `item_type` | texto (20) | U | Sí | RESOURCE o ACTIVITY |
| `item_id` | UUID | U | Sí | Identificador dentro de la versión publicada; sin FK porque el borrador puede haberlo eliminado |
| `completed_at` | fecha y hora |  | Sí |  |

### `enrollments`

Aislada por academia.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `course_id` | UUID | FK courses.id | Sí | Referencia a `courses` |
| `student_id` | UUID | FK users.id | Sí | Referencia a `users` |
| `status` | texto (20) |  | Sí | ACTIVE, CANCELLED. La vigencia queda fuera: el Maestro la deja pendiente (PD-005). |
| `source` | texto (20) |  | Sí | MANUAL por ahora; la compra y las licencias llegarán con S5 y S8 |
| `enrolled_by` | UUID | FK users.id | No | Referencia a `users` |
| `enrolled_at` | fecha y hora |  | Sí |  |
| `cancelled_at` | fecha y hora |  | No |  |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |
| `starts_at` | fecha y hora |  | No | Vigencia (Maestro §6): fuera de estas fechas no hay acceso. Vacías = sin límite. |
| `ends_at` | fecha y hora |  | No |  |
| `completed_at` | fecha y hora |  | No | Momento en que el alumno llegó al 100 % del curso |

## Archivos

### `files`

Aislada por academia.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `purpose` | texto (60) |  | Sí | Uso declarado en config/files.php (tenant_logo, …) |
| `visibility` | texto (10) |  | Sí |  |
| `disk` | texto (40) |  | Sí |  |
| `path` | texto (400) |  | Sí |  |
| `original_name` | texto (255) |  | Sí |  |
| `mime_type` | texto (120) |  | Sí |  |
| `size_bytes` | entero |  | Sí |  |
| `checksum` | texto fijo (64) |  | Sí |  |
| `uploaded_by` | UUID | FK users.id | No | Referencia a `users` |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |

## Tablas del framework (colas, caché, sesiones, tokens)

### `cache`

Sin aislamiento por academia (dato de plataforma o del framework).

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `key` | texto (255) | PK | Sí |  |
| `value` | texto largo |  | Sí |  |
| `expiration` | entero |  | Sí |  |

### `cache_locks`

Sin aislamiento por academia (dato de plataforma o del framework).

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `key` | texto (255) | PK | Sí |  |
| `owner` | texto (255) |  | Sí |  |
| `expiration` | entero |  | Sí |  |

### `failed_jobs`

Sin aislamiento por academia (dato de plataforma o del framework).
Único: `uuid`.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | entero | PK | Sí | Identificador del registro |
| `uuid` | texto (255) | U | Sí |  |
| `connection` | texto (255) |  | Sí |  |
| `queue` | texto (255) |  | Sí |  |
| `payload` | texto largo |  | Sí |  |
| `exception` | texto largo |  | Sí |  |
| `failed_at` | fecha y hora (sin zona) |  | Sí |  |

### `job_batches`

Sin aislamiento por academia (dato de plataforma o del framework).

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | texto (255) | PK | Sí | Identificador del registro |
| `name` | texto (255) |  | Sí | Nombre |
| `total_jobs` | entero |  | Sí |  |
| `pending_jobs` | entero |  | Sí |  |
| `failed_jobs` | entero |  | Sí |  |
| `failed_job_ids` | texto largo |  | Sí |  |
| `options` | texto largo |  | No |  |
| `cancelled_at` | entero |  | No |  |
| `created_at` | entero |  | Sí | Cuándo se creó |
| `finished_at` | entero |  | No |  |

### `jobs`

Sin aislamiento por academia (dato de plataforma o del framework).

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | entero | PK | Sí | Identificador del registro |
| `queue` | texto (255) |  | Sí |  |
| `payload` | texto largo |  | Sí |  |
| `attempts` | entero |  | Sí |  |
| `reserved_at` | entero |  | No |  |
| `available_at` | entero |  | Sí |  |
| `created_at` | entero |  | Sí | Cuándo se creó |

### `migrations`

Sin aislamiento por academia (dato de plataforma o del framework).

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | entero | PK | Sí | Identificador del registro |
| `migration` | texto (255) |  | Sí |  |
| `batch` | entero |  | Sí |  |

### `personal_access_tokens`

Sin aislamiento por academia (dato de plataforma o del framework).
Único: `token`.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | entero | PK | Sí | Identificador del registro |
| `tokenable_type` | texto (255) |  | Sí |  |
| `tokenable_id` | UUID |  | Sí |  |
| `name` | texto largo |  | Sí | Nombre |
| `token` | texto (64) | U | Sí |  |
| `abilities` | texto largo |  | No |  |
| `last_used_at` | fecha y hora (sin zona) |  | No |  |
| `expires_at` | fecha y hora (sin zona) |  | No |  |
| `created_at` | fecha y hora (sin zona) |  | No | Cuándo se creó |
| `updated_at` | fecha y hora (sin zona) |  | No | Última modificación |
| `ip_address` | texto (45) |  | No |  |
| `user_agent` | texto largo |  | No |  |

## Identidad y accesos

### `group_members`

Aislada por academia.
Único: `group_id, user_id`.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `group_id` | UUID | FK groups.id, U | Sí | Referencia a `groups` |
| `user_id` | UUID | FK users.id, U | Sí | Referencia a `users` |
| `created_at` | fecha y hora |  | No | Cuándo se creó |

### `groups`

Aislada por academia.
Único: `tenant_id, name`.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id, U | Sí | Academia a la que pertenece el registro |
| `name` | texto (120) | U | Sí | Nombre |
| `description` | texto (300) |  | No | Descripción |
| `created_by` | UUID | FK users.id | No | Referencia a `users` |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |

### `password_reset_tokens`

Aislada por academia.
Único: `token_hash`.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `user_id` | UUID | FK users.id | Sí | Referencia a `users` |
| `token_hash` | texto (64) | U | Sí | Solo se guarda el hash: el token viaja únicamente en el correo |
| `expires_at` | fecha y hora |  | Sí |  |
| `used_at` | fecha y hora |  | No |  |
| `created_at` | fecha y hora |  | Sí | Cuándo se creó |

### `permissions`

Sin aislamiento por academia (dato de plataforma o del framework).
Único: `code`.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `code` | texto (120) | U | Sí |  |
| `description` | texto (255) |  | No | Descripción |

### `role_permissions`

Sin aislamiento por academia (dato de plataforma o del framework).

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `role_id` | UUID | PK, FK roles.id | Sí | Referencia a `roles` |
| `permission_id` | UUID | PK, FK permissions.id | Sí | Referencia a `permissions` |

### `roles`

Aislada por academia.
Único: `tenant_id, code`.
Único: `tenant_id, name`.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id, U | Sí | Academia a la que pertenece el registro |
| `code` | texto (40) | U | Sí |  |
| `name` | texto (80) | U | Sí | Nombre |
| `description` | texto (255) |  | No | Descripción |
| `status` | texto (30) |  | Sí | Estado; ver el catálogo de estados |
| `is_system` | sí/no |  | Sí |  |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |

### `user_roles`

Sin aislamiento por academia (dato de plataforma o del framework).

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `user_id` | UUID | PK, FK users.id | Sí | Referencia a `users` |
| `role_id` | UUID | PK, FK roles.id | Sí | Referencia a `roles` |

### `users`

Aislada por academia.
Único: `tenant_id, doc_type, doc_number`.
Único: `tenant_id, email`.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id, U | Sí | Academia a la que pertenece el registro |
| `email` | texto (180) | U | Sí | Correo electrónico |
| `password` | texto (255) |  | No |  |
| `first_name` | texto (100) |  | Sí |  |
| `last_name` | texto (100) |  | Sí |  |
| `phone` | texto (30) |  | No | Teléfono |
| `status` | texto (30) |  | Sí | Estado; ver el catálogo de estados |
| `last_login_at` | fecha y hora |  | No |  |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |
| `doc_type` | texto (20) | U | No |  |
| `doc_number` | texto (30) | U | No |  |
| `terms_accepted_at` | fecha y hora |  | No | Cuándo aceptó los textos legales quien se registró por su cuenta. La versión aceptada es la que estaba publicada en ese momento (page_versions.published_at). |
| `must_change_password` | sí/no |  | Sí | Cuenta creada con una contraseña temporal (importación): debe cambiarla al entrar |

## Consola de plataforma

### `platform_admins`

Sin aislamiento por academia (dato de plataforma o del framework).
Único: `email`.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `name` | texto (150) |  | Sí | Nombre |
| `email` | texto (180) | U | Sí | Correo electrónico |
| `password` | texto (255) |  | Sí |  |
| `status` | texto (30) |  | Sí | Estado; ver el catálogo de estados |
| `last_login_at` | fecha y hora |  | No |  |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |

## Promociones

### `coupon_redemptions`

Aislada por academia.
Único: `order_id`.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `coupon_id` | UUID | FK coupons.id | Sí | Referencia a `coupons` |
| `promotion_id` | UUID | FK promotions.id | Sí | Referencia a `promotions` |
| `order_id` | UUID | FK orders.id, U | Sí | Una orden lleva un solo cupón (los descuentos no se acumulan) |
| `customer_id` | UUID | FK users.id | Sí | Referencia a `users` |
| `discount_amount` | decimal (12,2) |  | Sí |  |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |

### `coupons`

Aislada por academia.
Único: `tenant_id, code`.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id, U | Sí | Academia a la que pertenece el registro |
| `promotion_id` | UUID | FK promotions.id | Sí | Referencia a `promotions` |
| `code` | texto (40) | U | Sí | Siempre en mayúsculas |
| `label` | texto (100) |  | No | Para saber por dónde llegó la venta: Instagram, correo, un aliado… |
| `status` | texto (10) |  | Sí | ACTIVE, INACTIVE |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |

### `promotion_items`

Aislada por academia.
Único: `promotion_id, product_type, product_id`.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `promotion_id` | UUID | FK promotions.id, U | Sí | Referencia a `promotions` |
| `product_type` | texto (10) | U | Sí | COURSE o PACKAGE |
| `product_id` | UUID | U | Sí |  |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |

### `promotions`

Aislada por academia.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `name` | texto (150) |  | Sí | Nombre |
| `discount_type` | texto (10) |  | Sí | PERCENT = porcentaje del precio; FIXED = monto en la moneda de la organización |
| `discount_value` | decimal (12,2) |  | Sí |  |
| `starts_at` | fecha y hora |  | No | Vigencia; null = sin límite por ese lado |
| `ends_at` | fecha y hora |  | No |  |
| `max_redemptions` | entero |  | No | Usos en total entre todos sus cupones; null = sin tope |
| `once_per_customer` | sí/no |  | Sí |  |
| `min_purchase` | decimal (12,2) |  | No | Compra mínima, antes del descuento |
| `applies_to` | texto (10) |  | Sí | ALL = todo lo que está en venta; SELECTED = solo lo listado en promotion_items |
| `status` | texto (10) |  | Sí | ACTIVE, INACTIVE |
| `created_by` | UUID | FK users.id | No | Referencia a `users` |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |

## Ventas y pagos

### `currencies`

Sin aislamiento por academia (dato de plataforma o del framework).
Único: `code`.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `code` | texto (3) | U | Sí |  |
| `name` | texto (60) |  | Sí | Nombre |
| `symbol` | texto (5) |  | Sí |  |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |

### `order_items`

Aislada por academia.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `order_id` | UUID | FK orders.id | Sí | Referencia a `orders` |
| `course_id` | UUID | FK courses.id | Sí | Referencia a `courses` |
| `title` | texto (200) |  | Sí | Título del curso al momento de la compra |
| `unit_price` | decimal (12,2) |  | Sí |  |
| `quantity` | entero |  | Sí |  |
| `total_amount` | decimal (12,2) |  | Sí |  |
| `enrollment_id` | UUID | FK enrollments.id | No | Matrícula que generó el pago de la orden |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |

### `orders`

Aislada por academia.
Único: `tenant_id, number`.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id, U | Sí | Academia a la que pertenece el registro |
| `number` | entero | U | Sí | Correlativo por organización |
| `customer_id` | UUID | FK users.id | No | Referencia a `users` |
| `currency_id` | UUID | FK currencies.id | Sí | RN-019: importe y moneda quedan fijados en la orden |
| `subtotal_amount` | decimal (12,2) |  | Sí |  |
| `discount_amount` | decimal (12,2) |  | Sí | Lo usarán las promociones y cupones (S11) |
| `total_amount` | decimal (12,2) |  | Sí |  |
| `status` | texto (20) |  | Sí | PENDING, PAID, CANCELLED, EXPIRED |
| `expires_at` | fecha y hora |  | No | Hasta cuándo se puede pagar una orden pendiente |
| `paid_at` | fecha y hora |  | No |  |
| `cancelled_at` | fecha y hora |  | No |  |
| `cancelled_by` | UUID | FK users.id | No | Referencia a `users` |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |
| `company_id` | UUID | FK companies.id | No | Referencia a `companies` |
| `licenses_released_at` | fecha y hora |  | No | Venta corporativa cuyas licencias se entregaron antes del pago, y por qué |
| `licenses_released_by` | UUID | FK users.id | No | Referencia a `users` |
| `licenses_release_reason` | texto (500) |  | No |  |
| `package_id` | UUID | FK packages.id | No | Paquete comprado; null cuando la orden es de un curso suelto o de una empresa |
| `package_title` | texto (200) |  | No | Nombre del paquete al momento de la compra |
| `coupon_code` | texto (40) |  | No | Código del cupón aplicado, tal como quedó en la orden |
| `refunded_at` | fecha y hora |  | No |  |

### `package_items`

Aislada por academia.
Único: `package_id, course_id`.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `package_id` | UUID | FK packages.id, U | Sí | Referencia a `packages` |
| `course_id` | UUID | FK courses.id, U | Sí | Referencia a `courses` |
| `position` | entero |  | Sí | Orden dentro de su lista |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |

### `packages`

Aislada por academia.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `title` | texto (200) |  | Sí | Título |
| `summary` | texto (500) |  | No |  |
| `price` | decimal (10,2) |  | Sí | Precio del conjunto, en la moneda de la organización |
| `status` | texto (10) |  | Sí | ACTIVE = en venta; INACTIVE = retirado del catálogo |
| `created_by` | UUID | FK users.id | No | Referencia a `users` |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |

### `payment_attempts`

Aislada por academia.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `order_id` | UUID | FK orders.id | Sí | Referencia a `orders` |
| `gateway` | texto (30) |  | Sí |  |
| `external_id` | texto (120) |  | No | Identificador del cobro en la pasarela (preferencia, orden) |
| `external_payment_id` | texto (120) |  | No | Pago que la pasarela terminó informando |
| `status` | texto (20) |  | Sí | CREATED, APPROVED, REJECTED, NEEDS_REVIEW |
| `amount` | decimal (12,2) |  | Sí |  |
| `currency` | texto (3) |  | Sí |  |
| `checkout_url` | texto largo |  | No |  |
| `review_reason` | texto (300) |  | No | Por qué quedó para revisar |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |
| `exchange_rate` | decimal (10,4) |  | No | amount y currency guardan lo que se pidió cobrar; aquí, el tipo de cambio usado si lo hubo |

### `payment_gateway_accounts`

Aislada por academia.
Único: `tenant_id, gateway`.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id, U | Sí | Academia a la que pertenece el registro |
| `gateway` | texto (30) | U | Sí | MERCADOPAGO, PAYPAL |
| `enabled` | sí/no |  | Sí |  |
| `credentials` | texto largo |  | Sí | Claves de la cuenta, cifradas con la clave de la aplicación |
| `display` | JSON |  | No | Lo que se puede mostrar de la cuenta: nombre, país, si es de prueba |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |
| `settings` | JSON |  | No | Ajustes de la cuenta que no son claves: p. ej. el tipo de cambio (soles por dólar) |

### `payments`

Aislada por academia.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `order_id` | UUID | FK orders.id | Sí | Referencia a `orders` |
| `currency_id` | UUID | FK currencies.id | Sí | Referencia a `currencies` |
| `amount` | decimal (12,2) |  | Sí |  |
| `method` | texto (20) |  | Sí | Medio con el que pagó el comprador: TRANSFER, YAPE, CASH, OTHER |
| `gateway` | texto (30) |  | Sí | Pasarela que registró el pago; MANUAL = lo confirmó el personal |
| `status` | texto (20) |  | Sí | Estado; ver el catálogo de estados |
| `reference` | texto (120) |  | No | Número de operación que entrega el comprador |
| `notes` | texto (500) |  | No | Notas |
| `confirmed_by` | UUID | FK users.id | No | Referencia a `users` |
| `paid_at` | fecha y hora |  | Sí |  |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |
| `refunded_at` | fecha y hora |  | No |  |
| `refunded_by` | UUID | FK users.id | No | Referencia a `users` |
| `refund_reason` | texto (500) |  | No |  |
| `refund_reference` | texto (120) |  | No | Devolución en la pasarela, o cómo se devolvió el dinero por fuera |

## Soporte

### `ticket_attachments`

Aislada por academia.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `ticket_message_id` | UUID | FK ticket_messages.id | Sí | Referencia a `ticket_messages` |
| `file_id` | UUID | FK files.id | Sí | Referencia a `files` |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |

### `ticket_messages`

Aislada por academia.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `ticket_id` | UUID | FK tickets.id | Sí | Referencia a `tickets` |
| `author_id` | UUID | FK users.id | No | Referencia a `users` |
| `body` | texto largo |  | Sí |  |
| `is_staff` | sí/no |  | Sí | Lo escribió el personal de la organización |
| `is_internal` | sí/no |  | Sí | Nota interna: quien consulta no la ve |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |

### `tickets`

Aislada por academia.
Único: `tenant_id, number`.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id, U | Sí | Academia a la que pertenece el registro |
| `number` | entero | U | Sí | Correlativo por organización |
| `requester_id` | UUID | FK users.id | Sí | Referencia a `users` |
| `subject` | texto (200) |  | Sí |  |
| `category` | texto (20) |  | Sí | ACCESS, COURSE, PAYMENT, CERTIFICATE, OTHER |
| `priority` | texto (10) |  | Sí | LOW, NORMAL, HIGH: la fija el personal |
| `status` | texto (20) |  | Sí | OPEN, IN_PROGRESS, RESOLVED, CLOSED |
| `assignee_id` | UUID | FK users.id | No | Referencia a `users` |
| `course_id` | UUID | FK courses.id | No | A qué se refiere, si quien consulta lo indicó |
| `order_id` | UUID | FK orders.id | No | Referencia a `orders` |
| `resolved_at` | fecha y hora |  | No |  |
| `closed_at` | fecha y hora |  | No |  |
| `last_message_at` | fecha y hora |  | Sí | Último mensaje visible para quien consulta, y de qué lado vino (REQUESTER o STAFF) |
| `last_message_by` | texto (10) |  | Sí |  |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |

## Organización y configuración

### `grade_scales`

Aislada por academia.
Único: `tenant_id, max_score`.
Único: `tenant_id, name`.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id, U | Sí | Academia a la que pertenece el registro |
| `name` | texto (80) | U | Sí | Nombre |
| `max_score` | entero | U | Sí | Nota máxima: las notas se muestran de 0 a este valor |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |

### `holidays`

Aislada por academia.
Único: `tenant_id, date`.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id, U | Sí | Academia a la que pertenece el registro |
| `date` | fecha | U | Sí |  |
| `name` | texto (120) |  | Sí | Nombre |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |

### `idempotency_keys`

Aislada por academia.
Único: `tenant_id, user_id, key`.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id, U | Sí | Academia a la que pertenece el registro |
| `user_id` | UUID | FK users.id, U | Sí | Referencia a `users` |
| `key` | texto (100) | U | Sí |  |
| `method` | texto (10) |  | Sí |  |
| `path` | texto (255) |  | Sí |  |
| `request_hash` | texto fijo (64) |  | Sí | Huella del cuerpo: la misma clave con otro contenido es un error del cliente |
| `response_status` | entero |  | No | Vacíos mientras la primera petición sigue en curso |
| `response_body` | JSON |  | No |  |
| `created_at` | fecha y hora |  | Sí | Cuándo se creó |

### `tenant_settings`

Aislada por academia.
Único: `tenant_id, key`.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id, U | Sí | Academia a la que pertenece el registro |
| `key` | texto (120) | U | Sí |  |
| `value` | JSON |  | No |  |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |

### `tenants`

Sin aislamiento por academia (dato de plataforma o del framework).
Único: `slug`.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `name` | texto (150) |  | Sí | Nombre |
| `slug` | texto (40) | U | Sí | Subdominio de la organización: {slug}.{dominio central} |
| `legal_name` | texto (200) |  | No |  |
| `tax_id` | texto (30) |  | No |  |
| `status` | texto (30) |  | Sí | Estado; ver el catálogo de estados |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |

