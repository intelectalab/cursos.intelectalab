# Diccionario de datos

> Documento generado con `php artisan docs:technical` a partir del código y de la base de datos. No lo edites a mano: vuelve a generarlo.

Base de datos PostgreSQL. Todas las claves primarias `id` son UUID. El dinero se guarda como decimal, nunca como número de coma flotante. Las descripciones salen de los comentarios de las migraciones y, donde no los hay, de `backend/database/data-dictionary.php`; las columnas comunes (identificador, academia, fechas) y las referencias a otras tablas llevan una descripción genérica. Una celda vacía significa que la columna no tiene comentario.

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
| `type` | texto (20) |  | Sí | Tipo de actividad: QUIZ (cuestionario), TASK (tarea) o SURVEY (encuesta) |
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
| `type` | texto (20) |  | Sí | Tipo de pregunta: opción única, opción múltiple, verdadero o falso, texto corto, numérica, emparejar u ordenar |
| `statement` | texto largo |  | Sí | Enunciado de la pregunta |
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
| `question_id` | UUID | U | Sí | Pregunta respondida |
| `answer` | JSON |  | No | Lo que respondió el alumno, con la forma que pide el tipo de pregunta |
| `score` | decimal (5,4) |  | Sí | Fracción obtenida (0 a 1) y puntos que representa |
| `points_possible` | decimal (6,2) |  | Sí | Puntos que vale la pregunta en ese cuestionario |
| `points_earned` | decimal (8,4) |  | Sí | Puntos que obtuvo el alumno; se calculan al cerrar el intento |

### `quiz_attempt_grants`

Aislada por academia.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `enrollment_id` | UUID | FK enrollments.id | Sí | Referencia a `enrollments` |
| `course_id` | UUID | FK courses.id | Sí | Referencia a `courses` |
| `activity_id` | UUID |  | Sí | Actividad de la versión publicada (sin FK, como los intentos) |
| `attempts` | entero |  | Sí | Cuántos intentos adicionales se le dieron al alumno |
| `reason` | texto (300) |  | Sí | Por qué se le dieron |
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
| `activity_id` | UUID | U | Sí | Cuestionario que se rinde |
| `number` | entero | U | Sí | Número correlativo |
| `status` | texto (20) |  | Sí | IN_PROGRESS, SUBMITTED, EXPIRED |
| `questions` | JSON |  | Sí | Preguntas tal como se le presentaron, con sus respuestas: solo para calificar |
| `passing_percent` | decimal (5,2) |  | Sí | Nota mínima para aprobar que regía al empezar el intento |
| `started_at` | fecha y hora |  | Sí | Cuándo empezó el intento |
| `expires_at` | fecha y hora |  | No | Cuándo vence el tiempo; vacío si el cuestionario no tiene límite |
| `submitted_at` | fecha y hora |  | No | Cuándo se entregó o se cerró por tiempo |
| `score_percent` | decimal (5,2) |  | No | Nota del intento, de 0 a 100 |
| `passed` | sí/no |  | No | Si el intento alcanzó la nota mínima |
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
| `points` | decimal (6,2) |  | Sí | Puntos que vale la pregunta dentro del cuestionario |

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
| `time_limit_minutes` | entero |  | No | Minutos para rendirlo; vacío = sin límite |
| `shuffle` | sí/no |  | Sí | Si las preguntas se muestran en orden aleatorio |
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
| `activity_id` | UUID | U | Sí | Encuesta respondida |
| `answers` | JSON |  | Sí | Respuestas del alumno a cada pregunta de la encuesta |
| `submitted_at` | fecha y hora |  | Sí | Cuándo la respondió |

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
| `activity_id` | UUID | U | Sí | Tarea a la que corresponde la entrega |
| `body` | texto largo |  | No | Texto de la entrega |
| `file_id` | UUID | FK files.id | No | Referencia a `files` |
| `status` | texto (20) |  | Sí | SUBMITTED (por revisar) o GRADED |
| `submitted_at` | fecha y hora |  | Sí | Cuándo se entregó |
| `grade_percent` | decimal (5,2) |  | No | Nota puesta por quien revisa, de 0 a 100 |
| `feedback` | texto largo |  | No | Comentario de quien revisa |
| `graded_by` | UUID | FK users.id | No | Referencia a `users` |
| `graded_at` | fecha y hora |  | No | Cuándo se calificó |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |
| `is_late` | sí/no |  | Sí | Si se entregó pasado el plazo |
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
| `allow_file` | sí/no |  | Sí | Si la entrega puede llevar un archivo adjunto |
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
| `action` | texto (100) |  | Sí | Qué se hizo, con la forma recurso.acción (por ejemplo, orders.paid) |
| `entity_type` | texto (100) |  | Sí | Tipo de registro afectado |
| `entity_id` | UUID |  | No | Identificador del registro afectado |
| `before` | JSON |  | No | Valores antes del cambio |
| `after` | JSON |  | No | Valores después del cambio |
| `ip_address` | texto (45) |  | No | Dirección IP desde la que se hizo |
| `user_agent` | texto largo |  | No | Navegador o aplicación desde la que se hizo |
| `request_id` | texto (64) |  | No | Identificador de la petición, el mismo que aparece en el registro del servidor |
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
| `assigned_at` | fecha y hora |  | Sí | Cuándo se asignó la licencia al trabajador |
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
| `quantity` | entero |  | Sí | Número de licencias cotizadas |
| `list_price` | decimal (12,2) |  | No | Precio de lista del curso al cotizar (referencia) y precio pactado por licencia |
| `unit_price` | decimal (12,2) |  | Sí | Precio por licencia |
| `total_amount` | decimal (12,2) |  | Sí | Importe de la línea: cantidad por precio |
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
| `version` | entero | U | Sí | Número de versión de la cotización |
| `snapshot` | JSON |  | Sí | Copia de la cotización tal como se envió |
| `total_amount` | decimal (12,2) |  | Sí | Importe total de esa versión |
| `sent_by` | UUID | FK users.id | No | Referencia a `users` |
| `sent_at` | fecha y hora |  | Sí | Cuándo se marcó como enviada |
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
| `total_amount` | decimal (12,2) |  | Sí | Importe total vigente |
| `sent_at` | fecha y hora |  | No | Cuándo se envió la versión vigente |
| `accepted_at` | fecha y hora |  | No | Cuándo la aceptó la empresa |
| `accepted_by` | UUID | FK users.id | No | Referencia a `users` |
| `accepted_via` | texto (10) |  | No | PORTAL = la aceptó el responsable de la empresa; STAFF = la registró el personal |
| `acceptance_note` | texto (500) |  | No | Cómo o por qué medio se aceptó, cuando lo registra el personal |
| `rejected_at` | fecha y hora |  | No | Cuándo se rechazó |
| `reject_reason` | texto (500) |  | No | Motivo del rechazo |
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
| `billing_months` | entero |  | Sí | Meses que cubre el plan al momento de pedirlo |
| `max_students` | entero |  | No | Tope de alumnos del plan al momento de pedirlo |
| `amount` | decimal (10,2) |  | Sí | Importe a pagar |
| `currency` | texto fijo (3) |  | Sí | Moneda del importe |
| `status` | texto (20) |  | Sí | PENDING, PAID o CANCELLED |
| `requested_by` | UUID | FK users.id | No | Referencia a `users` |
| `payer_reference` | texto (300) |  | No | Lo que la organización informa de su pago (número de operación, fecha…) |
| `payment_method` | texto (20) |  | No | Medio con el que pagó la academia |
| `payment_reference` | texto (120) |  | No | Número de operación del pago |
| `notes` | texto (500) |  | No | Notas |
| `resolved_by` | texto (180) |  | No | Correo del administrador de plataforma que confirmó o canceló |
| `paid_at` | fecha y hora |  | No | Cuándo se confirmó el pago |
| `cancelled_at` | fecha y hora |  | No | Cuándo se canceló la orden |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |
| `expires_at` | fecha y hora |  | No | Hasta cuándo espera su pago; pasado el plazo vence sola si no se informó ningún pago |
| `proof_file_id` | UUID | FK files.id | No | Constancia de pago que adjunta la organización |
| `previous_subscription` | JSON |  | No | Cómo estaba la suscripción antes de confirmar, y hasta cuándo quedó: permite deshacer |
| `applied_period_ends_at` | fecha y hora |  | No | Hasta cuándo quedó vigente el plan al aplicarse este pago |
| `outcome_seen_at` | fecha y hora |  | No | La organización ya vio cómo se resolvió su orden (confirmada, cancelada o vencida) |
| `gateway` | texto (30) |  | No | Pago en línea: pasarela, cobro preparado en ella y pago con el que quedó pagada |
| `gateway_checkout_id` | texto (120) |  | No | Identificador del cobro abierto en la pasarela |
| `gateway_payment_id` | texto (120) |  | No | Identificador del pago en la pasarela |
| `review_reason` | texto (300) |  | No | La pasarela cobró y no se pudo aplicar: lo resuelve una persona |
| `gateway_amount` | decimal (10,2) |  | No | Lo que se le pidió cobrar a la pasarela, si no fue el importe de la orden tal cual |
| `gateway_currency` | texto fijo (3) |  | No | Moneda en la que cobró la pasarela |

### `plans`

Sin aislamiento por academia (dato de plataforma o del framework).

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `name` | texto (80) |  | Sí | Nombre |
| `description` | texto (300) |  | No | Descripción |
| `price` | decimal (10,2) |  | Sí | Precio del plan por periodo |
| `currency` | texto fijo (3) |  | Sí | Moneda del precio |
| `billing_months` | entero |  | Sí | Cada cuántos meses se paga: 1 = mensual, 12 = anual |
| `max_students` | entero |  | No | Tope de alumnos con matrícula activa; null = sin tope |
| `status` | texto (20) |  | Sí | ACTIVE (se ofrece) o ARCHIVED (ya no se ofrece; quien lo tiene lo conserva) |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |

### `platform_settings`

Sin aislamiento por academia (dato de plataforma o del framework).

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `key` | texto (60) | PK | Sí | Nombre del ajuste de la plataforma |
| `value` | JSON |  | No | Valor del ajuste; las cuentas de cobro se guardan cifradas |
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
| `trial_ends_at` | fecha y hora |  | No | Cuándo termina la prueba gratuita |
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
| `signer_name` | texto (150) |  | No | Nombre de quien firma el certificado |
| `signer_title` | texto (150) |  | No | Cargo de quien firma |
| `signature_file_id` | UUID | FK files.id | No | Referencia a `files` |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |
| `background_file_id` | UUID | FK files.id | No | Diseño propio de la organización: su imagen de fondo y dónde va cada dato |
| `design` | JSON |  | No | Diseño por opciones: alineación, colores y qué se muestra |
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
| `code` | texto (40) |  | Sí | Código que se consultó, tal como se escribió |
| `result` | texto (20) |  | Sí | VALID, REVOKED, NOT_FOUND |
| `ip_address` | texto (45) |  | No | Dirección IP desde la que se consultó |
| `user_agent` | texto (500) |  | No | Navegador desde el que se consultó |
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
| `issued_at` | fecha y hora |  | Sí | Cuándo se emitió |
| `issued_by` | UUID | FK users.id | No | null = lo emitió el sistema al completarse el curso |
| `revoked_at` | fecha y hora |  | No | Cuándo se anuló |
| `revoked_by` | UUID | FK users.id | No | Referencia a `users` |
| `revoke_reason` | texto (500) |  | No | Motivo de la anulación |
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
| `body` | texto largo |  | Sí | Texto del mensaje |
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
| `from_status` | texto (20) |  | No | Estado anterior |
| `to_status` | texto (20) |  | Sí | Estado nuevo |
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
| `last_name` | texto (100) |  | Sí | Apellidos del consumidor |
| `doc_type` | texto (12) |  | Sí | Tipo de documento del consumidor |
| `doc_number` | texto (20) |  | Sí | Número de documento del consumidor |
| `address` | texto (250) |  | Sí | Domicilio del consumidor |
| `email` | texto (150) |  | Sí | Correo electrónico |
| `phone` | texto (30) |  | No | Teléfono |
| `is_minor` | sí/no |  | Sí | Menor de edad: datos del padre, madre o apoderado |
| `guardian_name` | texto (200) |  | No | Padre, madre o apoderado, si el consumidor es menor de edad |
| `guardian_doc_number` | texto (20) |  | No | Documento del padre, madre o apoderado |
| `good_type` | texto (10) |  | Sí | Bien contratado |
| `good_description` | texto (500) |  | Sí | Producto o servicio contratado |
| `amount` | decimal (12,2) |  | No | Monto reclamado |
| `currency` | texto (3) |  | No | Moneda del monto reclamado |
| `order_reference` | texto (60) |  | No | Número de orden o comprobante, si el consumidor lo indica |
| `detail` | texto largo |  | Sí | Detalle del reclamo o la queja |
| `request` | texto largo |  | Sí | Lo que pide el consumidor |
| `status` | texto (20) |  | Sí | RECEIVED, IN_REVIEW, ANSWERED |
| `provider` | JSON |  | Sí | Datos del proveedor tal como figuraban al registrarse |
| `due_at` | fecha |  | Sí | Fecha límite para responder |
| `answered_at` | fecha y hora |  | No | Cuándo respondió la organización |
| `ip_address` | texto (45) |  | No | Dirección IP desde la que se registró la hoja |
| `user_agent` | texto (500) |  | No | Navegador desde el que se registró la hoja |
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
| `version` | entero | U | Sí | Número de la publicación |
| `title` | texto (150) |  | Sí | Título |
| `content` | JSON |  | Sí | Contenido publicado en esa versión |
| `published_by` | UUID | FK users.id | No | Referencia a `users` |
| `published_at` | fecha y hora |  | Sí | Cuándo se publicó |
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
| `current_version_id` | UUID |  | No | Versión que está a la vista del público; vacío si la página no está publicada |
| `published_at` | fecha y hora |  | No | Cuándo se publicó la versión a la vista |
| `draft_updated_at` | fecha y hora |  | Sí | Última vez que cambió el borrador |
| `created_by` | UUID | FK users.id | No | Referencia a `users` |
| `updated_by` | UUID | FK users.id | No | Referencia a `users` |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |
| `review_status` | texto (20) |  | No | NULL = sin revisión pedida; IN_REVIEW = espera a quien publica; CHANGES_REQUESTED = devuelta con observaciones |
| `review_requested_by` | UUID | FK users.id | No | Quién envió el borrador a revisión y cuándo |
| `review_requested_at` | fecha y hora |  | No | Cuándo se envió el borrador a revisión |
| `review_comment` | texto largo |  | No | Observaciones de quien revisó, cuando devuelve la página |
| `reviewed_by` | UUID | FK users.id | No | Quién devolvió la página y cuándo |
| `reviewed_at` | fecha y hora |  | No | Cuándo se devolvió con observaciones |

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
| `url` | texto (2000) |  | No | Enlace del recurso: el video de YouTube o una dirección externa |
| `body` | texto largo |  | No | Texto del recurso, cuando es de lectura |
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
| `note` | texto (300) |  | No | Nota de quien publicó sobre lo que cambió |
| `snapshot` | JSON |  | Sí | Copia completa del curso tal como se publicó |
| `published_by` | UUID | FK users.id | No | Referencia a `users` |
| `published_at` | fecha y hora |  | Sí | Cuándo se publicó |

### `courses`

Aislada por academia.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `title` | texto (200) |  | Sí | Título |
| `summary` | texto (300) |  | No | Resumen corto que se muestra en el catálogo |
| `description` | texto largo |  | No | Descripción |
| `category` | texto (80) |  | No | Categoría, en texto libre |
| `level` | texto (20) |  | No | Nivel: básico, intermedio o avanzado |
| `duration_hours` | entero |  | No | Duración declarada, en horas |
| `language` | texto (5) |  | Sí | Idioma del curso |
| `cover_file_id` | UUID | FK files.id | No | Referencia a `files` |
| `status` | texto (20) |  | Sí | DRAFT, PUBLISHED, ARCHIVED (Maestro §6) |
| `current_version_id` | UUID | FK course_versions.id | No | Versión que ven los alumnos; null mientras no se publique |
| `has_pending_changes` | sí/no |  | Sí | El borrador difiere de la última versión publicada |
| `published_at` | fecha y hora |  | No | Cuándo se publicó la versión vigente |
| `archived_at` | fecha y hora |  | No | Cuándo se archivó; un curso archivado no admite matrículas nuevas |
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
| `industry` | texto (100) |  | No | Rubro de la empresa |
| `website` | texto (200) |  | No | Sitio web |
| `email` | texto (150) |  | No | Correo electrónico |
| `phone` | texto (30) |  | No | Teléfono |
| `address` | texto (250) |  | No | Dirección |
| `city` | texto (100) |  | No | Ciudad |
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
| `first_name` | texto (100) |  | Sí | Nombres del contacto |
| `last_name` | texto (100) |  | No | Apellidos del contacto |
| `position` | texto (120) |  | No | Orden dentro de su lista |
| `email` | texto (150) |  | No | Correo electrónico |
| `phone` | texto (30) |  | No | Teléfono |
| `is_primary` | sí/no |  | Sí | Si es el contacto principal de la empresa |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |

### `leads`

Aislada por academia.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `first_name` | texto (100) |  | Sí | Nombres del prospecto |
| `last_name` | texto (100) |  | No | Apellidos del prospecto |
| `company_name` | texto (200) |  | No | Empresa del prospecto, como texto: todavía no es una empresa registrada |
| `position` | texto (120) |  | No | Orden dentro de su lista |
| `email` | texto (150) |  | No | Correo electrónico |
| `phone` | texto (30) |  | No | Teléfono |
| `source` | texto (20) |  | Sí | WEB, REFERRAL, EVENT, CALL, SOCIAL, OTHER |
| `interest` | texto largo |  | No | Qué le interesa |
| `status` | texto (20) |  | Sí | NEW, CONTACTED, CONVERTED, DISCARDED |
| `discard_reason` | texto (500) |  | No | Por qué se descartó |
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
| `expected_close_date` | fecha |  | No | Fecha en que se espera cerrar la venta |
| `notes` | texto largo |  | No | Notas |
| `lost_reason` | texto (500) |  | No | Por qué se perdió |
| `closed_at` | fecha y hora |  | No | Cuándo se ganó o se perdió |
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
| `occurred_at` | fecha y hora |  | Sí | Cuándo ocurrió la llamada, reunión o nota |
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
| `completed_at` | fecha y hora |  | Sí | Cuándo el alumno completó ese elemento del curso |

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
| `enrolled_at` | fecha y hora |  | Sí | Cuándo se matriculó |
| `cancelled_at` | fecha y hora |  | No | Cuándo se canceló la matrícula |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |
| `starts_at` | fecha y hora |  | No | Vigencia (Maestro §6): fuera de estas fechas no hay acceso. Vacías = sin límite. |
| `ends_at` | fecha y hora |  | No | Último día de acceso; vacío = no vence |
| `completed_at` | fecha y hora |  | No | Momento en que el alumno llegó al 100 % del curso |

## Archivos

### `files`

Aislada por academia.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `purpose` | texto (60) |  | Sí | Uso declarado en config/files.php (tenant_logo, …) |
| `visibility` | texto (10) |  | Sí | public = se sirve por su dirección; private = solo con sesión y permiso |
| `disk` | texto (40) |  | Sí | Almacén donde está guardado |
| `path` | texto (400) |  | Sí | Ruta dentro del almacén |
| `original_name` | texto (255) |  | Sí | Nombre con el que se subió |
| `mime_type` | texto (120) |  | Sí | Tipo de archivo, detectado por su contenido |
| `size_bytes` | entero |  | Sí | Tamaño en bytes |
| `checksum` | texto fijo (64) |  | Sí | Huella del contenido, para detectar cambios o duplicados |
| `uploaded_by` | UUID | FK users.id | No | Referencia a `users` |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |

## Tablas del framework (colas, caché, sesiones, tokens)

### `cache`

Sin aislamiento por academia (dato de plataforma o del framework).

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `key` | texto (255) | PK | Sí | Clave del dato en caché |
| `value` | texto largo |  | Sí | Dato guardado |
| `expiration` | entero |  | Sí | Cuándo vence, en segundos desde 1970 |

### `cache_locks`

Sin aislamiento por academia (dato de plataforma o del framework).

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `key` | texto (255) | PK | Sí | Nombre del bloqueo |
| `owner` | texto (255) |  | Sí | Proceso que lo tiene tomado |
| `expiration` | entero |  | Sí | Cuándo se libera solo |

### `failed_jobs`

Sin aislamiento por academia (dato de plataforma o del framework).
Único: `uuid`.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | entero | PK | Sí | Identificador del registro |
| `uuid` | texto (255) | U | Sí | Identificador de la tarea fallida |
| `connection` | texto (255) |  | Sí | Conexión de cola que la ejecutaba |
| `queue` | texto (255) |  | Sí | Cola a la que pertenecía |
| `payload` | texto largo |  | Sí | Contenido de la tarea |
| `exception` | texto largo |  | Sí | Error con el que falló |
| `failed_at` | fecha y hora (sin zona) |  | Sí | Cuándo falló |

### `job_batches`

Sin aislamiento por academia (dato de plataforma o del framework).

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | texto (255) | PK | Sí | Identificador del registro |
| `name` | texto (255) |  | Sí | Nombre |
| `total_jobs` | entero |  | Sí | Tareas del lote |
| `pending_jobs` | entero |  | Sí | Tareas por ejecutar |
| `failed_jobs` | entero |  | Sí | Tareas que fallaron |
| `failed_job_ids` | texto largo |  | Sí | Identificadores de las tareas fallidas |
| `options` | texto largo |  | No | Opciones del lote |
| `cancelled_at` | entero |  | No | Cuándo se canceló |
| `created_at` | entero |  | Sí | Cuándo se creó |
| `finished_at` | entero |  | No | Cuándo terminó |

### `jobs`

Sin aislamiento por academia (dato de plataforma o del framework).

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | entero | PK | Sí | Identificador del registro |
| `queue` | texto (255) |  | Sí | Cola a la que pertenece la tarea |
| `payload` | texto largo |  | Sí | Contenido de la tarea |
| `attempts` | entero |  | Sí | Veces que se intentó |
| `reserved_at` | entero |  | No | Cuándo la tomó un proceso |
| `available_at` | entero |  | Sí | Desde cuándo se puede ejecutar |
| `created_at` | entero |  | Sí | Cuándo se creó |

### `migrations`

Sin aislamiento por academia (dato de plataforma o del framework).

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | entero | PK | Sí | Identificador del registro |
| `migration` | texto (255) |  | Sí | Nombre de la migración aplicada |
| `batch` | entero |  | Sí | Tanda en la que se aplicó |

### `personal_access_tokens`

Sin aislamiento por academia (dato de plataforma o del framework).
Único: `token`.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | entero | PK | Sí | Identificador del registro |
| `tokenable_type` | texto (255) |  | Sí | Tipo de cuenta dueña de la sesión: usuario de una academia o administrador de plataforma |
| `tokenable_id` | UUID |  | Sí | Cuenta dueña de la sesión |
| `name` | texto largo |  | Sí | Nombre |
| `token` | texto (64) | U | Sí | Huella de la clave de sesión; la clave misma no se guarda |
| `abilities` | texto largo |  | No | Qué permite la sesión; el acceso de soporte lleva solo lectura |
| `last_used_at` | fecha y hora (sin zona) |  | No | Última petición hecha con la sesión |
| `expires_at` | fecha y hora (sin zona) |  | No | Cuándo vence |
| `created_at` | fecha y hora (sin zona) |  | No | Cuándo se creó |
| `updated_at` | fecha y hora (sin zona) |  | No | Última modificación |
| `ip_address` | texto (45) |  | No | Dirección IP desde la que se inició la sesión |
| `user_agent` | texto largo |  | No | Navegador o aplicación desde la que se inició la sesión |

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
| `expires_at` | fecha y hora |  | Sí | Hasta cuándo sirve el enlace para recuperar el acceso |
| `used_at` | fecha y hora |  | No | Cuándo se usó; un enlace usado no sirve otra vez |
| `created_at` | fecha y hora |  | Sí | Cuándo se creó |

### `permissions`

Sin aislamiento por academia (dato de plataforma o del framework).
Único: `code`.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `code` | texto (120) | U | Sí | Código del permiso, con la forma recurso.acción |
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
| `code` | texto (40) | U | Sí | Código del rol dentro de la academia |
| `name` | texto (80) | U | Sí | Nombre |
| `description` | texto (255) |  | No | Descripción |
| `status` | texto (30) |  | Sí | Estado; ver el catálogo de estados |
| `is_system` | sí/no |  | Sí | Si es uno de los roles base, que no se eliminan |
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
| `password` | texto (255) |  | No | Contraseña, guardada como huella irreversible |
| `first_name` | texto (100) |  | Sí | Nombres |
| `last_name` | texto (100) |  | Sí | Apellidos |
| `phone` | texto (30) |  | No | Teléfono |
| `status` | texto (30) |  | Sí | Estado; ver el catálogo de estados |
| `last_login_at` | fecha y hora |  | No | Último inicio de sesión |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |
| `doc_type` | texto (20) | U | No | Tipo de documento de identidad |
| `doc_number` | texto (30) | U | No | Número de documento de identidad |
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
| `password` | texto (255) |  | Sí | Contraseña, guardada como huella irreversible |
| `status` | texto (30) |  | Sí | Estado; ver el catálogo de estados |
| `last_login_at` | fecha y hora |  | No | Último inicio de sesión |
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
| `discount_amount` | decimal (12,2) |  | Sí | Descuento que se aplicó a la orden |
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
| `product_id` | UUID | U | Sí | Curso o paquete al que aplica la promoción |
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
| `discount_value` | decimal (12,2) |  | Sí | Valor del descuento: un porcentaje o un monto fijo, según el tipo |
| `starts_at` | fecha y hora |  | No | Vigencia; null = sin límite por ese lado |
| `ends_at` | fecha y hora |  | No | Hasta cuándo vale; vacío = sin fecha de término |
| `max_redemptions` | entero |  | No | Usos en total entre todos sus cupones; null = sin tope |
| `once_per_customer` | sí/no |  | Sí | Si cada persona puede usarla una sola vez |
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
| `code` | texto (3) | U | Sí | Código de la moneda (PEN, USD) |
| `name` | texto (60) |  | Sí | Nombre |
| `symbol` | texto (5) |  | Sí | Símbolo con el que se muestra |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |

### `exchange_rates`

Aislada por academia.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id | Sí | Academia a la que pertenece el registro |
| `base_currency` | texto fijo (3) |  | Sí | Moneda que se convierte: con USD, el valor dice cuánto cuesta un dólar |
| `quote_currency` | texto fijo (3) |  | Sí | Moneda en la que se expresa el valor: con PEN, soles por dólar |
| `rate` | decimal (12,4) |  | Sí | Tipo de cambio con el que se cobra, ya con el ajuste aplicado |
| `source` | texto (20) |  | Sí | MANUAL = lo escribió una persona; SUNAT = tomado del tipo de cambio oficial |
| `official_rate` | decimal (12,4) |  | No | Valor que publicó la fuente, antes del ajuste; vacío en los manuales |
| `margin_percent` | decimal (5,2) |  | No | Recargo sobre lo que se cobra en dólares que se aplicó a ese valor |
| `effective_date` | fecha |  | Sí | Día al que corresponde el tipo de cambio |
| `created_by` | UUID | FK users.id | No | Quién lo registró; vacío = la actualización automática |
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
| `unit_price` | decimal (12,2) |  | Sí | Precio del curso al momento de la compra |
| `quantity` | entero |  | Sí | Cantidad: 1 en una compra personal, el número de licencias en una venta a empresa |
| `total_amount` | decimal (12,2) |  | Sí | Importe de la línea |
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
| `subtotal_amount` | decimal (12,2) |  | Sí | Importe antes del descuento |
| `discount_amount` | decimal (12,2) |  | Sí | Lo usarán las promociones y cupones (S11) |
| `total_amount` | decimal (12,2) |  | Sí | Importe a pagar |
| `status` | texto (20) |  | Sí | PENDING, PAID, CANCELLED, EXPIRED |
| `expires_at` | fecha y hora |  | No | Hasta cuándo se puede pagar una orden pendiente |
| `paid_at` | fecha y hora |  | No | Cuándo quedó pagada |
| `cancelled_at` | fecha y hora |  | No | Cuándo se canceló |
| `cancelled_by` | UUID | FK users.id | No | Referencia a `users` |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |
| `company_id` | UUID | FK companies.id | No | Referencia a `companies` |
| `licenses_released_at` | fecha y hora |  | No | Venta corporativa cuyas licencias se entregaron antes del pago, y por qué |
| `licenses_released_by` | UUID | FK users.id | No | Referencia a `users` |
| `licenses_release_reason` | texto (500) |  | No | Por qué se entregaron las licencias antes del pago |
| `package_id` | UUID | FK packages.id | No | Paquete comprado; null cuando la orden es de un curso suelto o de una empresa |
| `package_title` | texto (200) |  | No | Nombre del paquete al momento de la compra |
| `coupon_code` | texto (40) |  | No | Código del cupón aplicado, tal como quedó en la orden |
| `refunded_at` | fecha y hora |  | No | Cuándo se reembolsó |

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
| `summary` | texto (500) |  | No | Resumen corto que se muestra en el catálogo |
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
| `gateway` | texto (30) |  | Sí | Pasarela con la que se abrió el cobro |
| `external_id` | texto (120) |  | No | Identificador del cobro en la pasarela (preferencia, orden) |
| `external_payment_id` | texto (120) |  | No | Pago que la pasarela terminó informando |
| `status` | texto (20) |  | Sí | CREATED, APPROVED, REJECTED, NEEDS_REVIEW |
| `amount` | decimal (12,2) |  | Sí | Importe que se le pidió cobrar a la pasarela |
| `currency` | texto (3) |  | Sí | Moneda en la que se le pidió cobrar |
| `checkout_url` | texto largo |  | No | Página de pago de la pasarela a la que se envió al comprador |
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
| `enabled` | sí/no |  | Sí | Si la pasarela se ofrece a los compradores; en pausa queda conectada sin ofrecerse |
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
| `amount` | decimal (12,2) |  | Sí | Importe pagado |
| `method` | texto (20) |  | Sí | Medio con el que pagó el comprador: TRANSFER, YAPE, CASH, OTHER |
| `gateway` | texto (30) |  | Sí | Pasarela que registró el pago; MANUAL = lo confirmó el personal |
| `status` | texto (20) |  | Sí | Estado; ver el catálogo de estados |
| `reference` | texto (120) |  | No | Número de operación que entrega el comprador |
| `notes` | texto (500) |  | No | Notas |
| `confirmed_by` | UUID | FK users.id | No | Referencia a `users` |
| `paid_at` | fecha y hora |  | Sí | Cuándo se hizo el pago |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |
| `refunded_at` | fecha y hora |  | No | Cuándo se devolvió |
| `refunded_by` | UUID | FK users.id | No | Referencia a `users` |
| `refund_reason` | texto (500) |  | No | Motivo de la devolución |
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
| `body` | texto largo |  | Sí | Texto del mensaje |
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
| `subject` | texto (200) |  | Sí | Asunto de la consulta |
| `category` | texto (20) |  | Sí | ACCESS, COURSE, PAYMENT, CERTIFICATE, OTHER |
| `priority` | texto (10) |  | Sí | LOW, NORMAL, HIGH: la fija el personal |
| `status` | texto (20) |  | Sí | OPEN, IN_PROGRESS, RESOLVED, CLOSED |
| `assignee_id` | UUID | FK users.id | No | Referencia a `users` |
| `course_id` | UUID | FK courses.id | No | A qué se refiere, si quien consulta lo indicó |
| `order_id` | UUID | FK orders.id | No | Referencia a `orders` |
| `resolved_at` | fecha y hora |  | No | Cuándo se marcó como resuelta |
| `closed_at` | fecha y hora |  | No | Cuándo se cerró |
| `last_message_at` | fecha y hora |  | Sí | Último mensaje visible para quien consulta, y de qué lado vino (REQUESTER o STAFF) |
| `last_message_by` | texto (10) |  | Sí | Quién escribió el último mensaje: el usuario o el personal |
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
| `date` | fecha | U | Sí | Día feriado: no cuenta en los plazos en días hábiles |
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
| `key` | texto (100) | U | Sí | Clave que envía el cliente para no repetir una operación |
| `method` | texto (10) |  | Sí | Método de la petición |
| `path` | texto (255) |  | Sí | Ruta de la petición |
| `request_hash` | texto fijo (64) |  | Sí | Huella del cuerpo: la misma clave con otro contenido es un error del cliente |
| `response_status` | entero |  | No | Vacíos mientras la primera petición sigue en curso |
| `response_body` | JSON |  | No | Respuesta que se dio la primera vez, para devolverla si la petición se repite |
| `created_at` | fecha y hora |  | Sí | Cuándo se creó |

### `tenant_settings`

Aislada por academia.
Único: `tenant_id, key`.

| Columna | Tipo | Clave | Oblig. | Descripción |
| --- | --- | --- | :---: | --- |
| `id` | UUID | PK | Sí | Identificador del registro |
| `tenant_id` | UUID | FK tenants.id, U | Sí | Academia a la que pertenece el registro |
| `key` | texto (120) | U | Sí | Nombre del grupo de ajustes de la academia |
| `value` | JSON |  | No | Valores del grupo de ajustes |
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
| `legal_name` | texto (200) |  | No | Razón social |
| `tax_id` | texto (30) |  | No | RUC u otro número de identificación tributaria |
| `status` | texto (30) |  | Sí | Estado; ver el catálogo de estados |
| `created_at` | fecha y hora |  | No | Cuándo se creó |
| `updated_at` | fecha y hora |  | No | Última modificación |

