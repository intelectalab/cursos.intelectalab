<?php

/*
 * Descripciones del diccionario de datos (`php artisan docs:technical`) para
 * las columnas que no llevan un comentario propio en su migración.
 * El comentario de la migración, cuando existe, manda sobre lo escrito aquí.
 *
 * tabla => [columna => qué guarda]
 */

$ip = 'Dirección IP desde la que se hizo';
$agent = 'Navegador o aplicación desde la que se hizo';

return [
    // ── Académico ──
    'course_activities' => [
        'type' => 'Tipo de actividad: QUIZ (cuestionario), TASK (tarea) o SURVEY (encuesta)',
    ],
    'questions' => [
        'type' => 'Tipo de pregunta: opción única, opción múltiple, verdadero o falso, texto corto, numérica, emparejar u ordenar',
        'statement' => 'Enunciado de la pregunta',
    ],
    'quiz_answers' => [
        'question_id' => 'Pregunta respondida',
        'answer' => 'Lo que respondió el alumno, con la forma que pide el tipo de pregunta',
        'points_possible' => 'Puntos que vale la pregunta en ese cuestionario',
        'points_earned' => 'Puntos que obtuvo el alumno; se calculan al cerrar el intento',
    ],
    'quiz_attempt_grants' => [
        'attempts' => 'Cuántos intentos adicionales se le dieron al alumno',
        'reason' => 'Por qué se le dieron',
    ],
    'quiz_attempts' => [
        'activity_id' => 'Cuestionario que se rinde',
        'passing_percent' => 'Nota mínima para aprobar que regía al empezar el intento',
        'started_at' => 'Cuándo empezó el intento',
        'expires_at' => 'Cuándo vence el tiempo; vacío si el cuestionario no tiene límite',
        'submitted_at' => 'Cuándo se entregó o se cerró por tiempo',
        'score_percent' => 'Nota del intento, de 0 a 100',
        'passed' => 'Si el intento alcanzó la nota mínima',
    ],
    'quiz_questions' => [
        'points' => 'Puntos que vale la pregunta dentro del cuestionario',
    ],
    'quizzes' => [
        'time_limit_minutes' => 'Minutos para rendirlo; vacío = sin límite',
        'shuffle' => 'Si las preguntas se muestran en orden aleatorio',
    ],
    'survey_responses' => [
        'activity_id' => 'Encuesta respondida',
        'answers' => 'Respuestas del alumno a cada pregunta de la encuesta',
        'submitted_at' => 'Cuándo la respondió',
    ],
    'task_submissions' => [
        'activity_id' => 'Tarea a la que corresponde la entrega',
        'body' => 'Texto de la entrega',
        'submitted_at' => 'Cuándo se entregó',
        'grade_percent' => 'Nota puesta por quien revisa, de 0 a 100',
        'feedback' => 'Comentario de quien revisa',
        'graded_at' => 'Cuándo se calificó',
        'is_late' => 'Si se entregó pasado el plazo',
    ],
    'tasks' => [
        'allow_file' => 'Si la entrega puede llevar un archivo adjunto',
    ],

    // ── Auditoría ──
    'audit_events' => [
        'action' => 'Qué se hizo, con la forma recurso.acción (por ejemplo, orders.paid)',
        'entity_type' => 'Tipo de registro afectado',
        'entity_id' => 'Identificador del registro afectado',
        'before' => 'Valores antes del cambio',
        'after' => 'Valores después del cambio',
        'ip_address' => $ip,
        'user_agent' => $agent,
        'request_id' => 'Identificador de la petición, el mismo que aparece en el registro del servidor',
    ],

    // ── Venta a empresas ──
    'license_assignments' => [
        'assigned_at' => 'Cuándo se asignó la licencia al trabajador',
    ],
    'quotation_items' => [
        'quantity' => 'Número de licencias cotizadas',
        'unit_price' => 'Precio por licencia',
        'total_amount' => 'Importe de la línea: cantidad por precio',
    ],
    'quotation_versions' => [
        'version' => 'Número de versión de la cotización',
        'snapshot' => 'Copia de la cotización tal como se envió',
        'total_amount' => 'Importe total de esa versión',
        'sent_at' => 'Cuándo se marcó como enviada',
    ],
    'quotations' => [
        'total_amount' => 'Importe total vigente',
        'sent_at' => 'Cuándo se envió la versión vigente',
        'accepted_at' => 'Cuándo la aceptó la empresa',
        'acceptance_note' => 'Cómo o por qué medio se aceptó, cuando lo registra el personal',
        'rejected_at' => 'Cuándo se rechazó',
        'reject_reason' => 'Motivo del rechazo',
    ],

    // ── Planes de la plataforma ──
    'plan_orders' => [
        'billing_months' => 'Meses que cubre el plan al momento de pedirlo',
        'max_students' => 'Tope de alumnos del plan al momento de pedirlo',
        'amount' => 'Importe a pagar',
        'currency' => 'Moneda del importe',
        'payment_method' => 'Medio con el que pagó la academia',
        'payment_reference' => 'Número de operación del pago',
        'paid_at' => 'Cuándo se confirmó el pago',
        'cancelled_at' => 'Cuándo se canceló la orden',
        'applied_period_ends_at' => 'Hasta cuándo quedó vigente el plan al aplicarse este pago',
        'gateway_checkout_id' => 'Identificador del cobro abierto en la pasarela',
        'gateway_payment_id' => 'Identificador del pago en la pasarela',
        'gateway_currency' => 'Moneda en la que cobró la pasarela',
    ],
    'plans' => [
        'price' => 'Precio del plan por periodo',
        'currency' => 'Moneda del precio',
    ],
    'platform_settings' => [
        'key' => 'Nombre del ajuste de la plataforma',
        'value' => 'Valor del ajuste; las cuentas de cobro se guardan cifradas',
    ],
    'tenant_subscriptions' => [
        'trial_ends_at' => 'Cuándo termina la prueba gratuita',
    ],

    // ── Certificados ──
    'certificate_templates' => [
        'signer_name' => 'Nombre de quien firma el certificado',
        'signer_title' => 'Cargo de quien firma',
        'design' => 'Diseño por opciones: alineación, colores y qué se muestra',
    ],
    'certificate_verifications' => [
        'code' => 'Código que se consultó, tal como se escribió',
        'ip_address' => 'Dirección IP desde la que se consultó',
        'user_agent' => 'Navegador desde el que se consultó',
    ],
    'certificates' => [
        'issued_at' => 'Cuándo se emitió',
        'revoked_at' => 'Cuándo se anuló',
        'revoke_reason' => 'Motivo de la anulación',
    ],

    // ── Libro de Reclamaciones ──
    'claim_messages' => [
        'body' => 'Texto del mensaje',
    ],
    'claim_status_history' => [
        'from_status' => 'Estado anterior',
        'to_status' => 'Estado nuevo',
    ],
    'claims' => [
        'last_name' => 'Apellidos del consumidor',
        'doc_type' => 'Tipo de documento del consumidor',
        'doc_number' => 'Número de documento del consumidor',
        'address' => 'Domicilio del consumidor',
        'guardian_name' => 'Padre, madre o apoderado, si el consumidor es menor de edad',
        'guardian_doc_number' => 'Documento del padre, madre o apoderado',
        'good_description' => 'Producto o servicio contratado',
        'amount' => 'Monto reclamado',
        'currency' => 'Moneda del monto reclamado',
        'detail' => 'Detalle del reclamo o la queja',
        'request' => 'Lo que pide el consumidor',
        'answered_at' => 'Cuándo respondió la organización',
        'ip_address' => 'Dirección IP desde la que se registró la hoja',
        'user_agent' => 'Navegador desde el que se registró la hoja',
    ],

    // ── Páginas ──
    'page_versions' => [
        'version' => 'Número de la publicación',
        'content' => 'Contenido publicado en esa versión',
        'published_at' => 'Cuándo se publicó',
    ],
    'pages' => [
        'current_version_id' => 'Versión que está a la vista del público; vacío si la página no está publicada',
        'published_at' => 'Cuándo se publicó la versión a la vista',
        'review_requested_at' => 'Cuándo se envió el borrador a revisión',
        'reviewed_at' => 'Cuándo se devolvió con observaciones',
    ],

    // ── Cursos ──
    'course_resources' => [
        'url' => 'Enlace del recurso: el video de YouTube o una dirección externa',
        'body' => 'Texto del recurso, cuando es de lectura',
    ],
    'course_versions' => [
        'note' => 'Nota de quien publicó sobre lo que cambió',
        'snapshot' => 'Copia completa del curso tal como se publicó',
        'published_at' => 'Cuándo se publicó',
    ],
    'courses' => [
        'summary' => 'Resumen corto que se muestra en el catálogo',
        'category' => 'Categoría, en texto libre',
        'level' => 'Nivel: básico, intermedio o avanzado',
        'duration_hours' => 'Duración declarada, en horas',
        'language' => 'Idioma del curso',
        'published_at' => 'Cuándo se publicó la versión vigente',
        'archived_at' => 'Cuándo se archivó; un curso archivado no admite matrículas nuevas',
    ],

    // ── CRM ──
    'companies' => [
        'industry' => 'Rubro de la empresa',
        'website' => 'Sitio web',
        'address' => 'Dirección',
        'city' => 'Ciudad',
    ],
    'contacts' => [
        'first_name' => 'Nombres del contacto',
        'last_name' => 'Apellidos del contacto',
        'is_primary' => 'Si es el contacto principal de la empresa',
    ],
    'leads' => [
        'first_name' => 'Nombres del prospecto',
        'last_name' => 'Apellidos del prospecto',
        'company_name' => 'Empresa del prospecto, como texto: todavía no es una empresa registrada',
        'interest' => 'Qué le interesa',
        'discard_reason' => 'Por qué se descartó',
    ],
    'opportunities' => [
        'expected_close_date' => 'Fecha en que se espera cerrar la venta',
        'lost_reason' => 'Por qué se perdió',
        'closed_at' => 'Cuándo se ganó o se perdió',
    ],
    'opportunity_activities' => [
        'occurred_at' => 'Cuándo ocurrió la llamada, reunión o nota',
    ],

    // ── Matrículas ──
    'enrollment_progress' => [
        'completed_at' => 'Cuándo el alumno completó ese elemento del curso',
    ],
    'enrollments' => [
        'enrolled_at' => 'Cuándo se matriculó',
        'cancelled_at' => 'Cuándo se canceló la matrícula',
        'ends_at' => 'Último día de acceso; vacío = no vence',
    ],

    // ── Archivos ──
    'files' => [
        'visibility' => 'public = se sirve por su dirección; private = solo con sesión y permiso',
        'disk' => 'Almacén donde está guardado',
        'path' => 'Ruta dentro del almacén',
        'original_name' => 'Nombre con el que se subió',
        'mime_type' => 'Tipo de archivo, detectado por su contenido',
        'size_bytes' => 'Tamaño en bytes',
        'checksum' => 'Huella del contenido, para detectar cambios o duplicados',
    ],

    // ── Tablas del framework ──
    'cache' => [
        'key' => 'Clave del dato en caché',
        'value' => 'Dato guardado',
        'expiration' => 'Cuándo vence, en segundos desde 1970',
    ],
    'cache_locks' => [
        'key' => 'Nombre del bloqueo',
        'owner' => 'Proceso que lo tiene tomado',
        'expiration' => 'Cuándo se libera solo',
    ],
    'failed_jobs' => [
        'uuid' => 'Identificador de la tarea fallida',
        'connection' => 'Conexión de cola que la ejecutaba',
        'queue' => 'Cola a la que pertenecía',
        'payload' => 'Contenido de la tarea',
        'exception' => 'Error con el que falló',
        'failed_at' => 'Cuándo falló',
    ],
    'job_batches' => [
        'total_jobs' => 'Tareas del lote',
        'pending_jobs' => 'Tareas por ejecutar',
        'failed_jobs' => 'Tareas que fallaron',
        'failed_job_ids' => 'Identificadores de las tareas fallidas',
        'options' => 'Opciones del lote',
        'cancelled_at' => 'Cuándo se canceló',
        'finished_at' => 'Cuándo terminó',
    ],
    'jobs' => [
        'queue' => 'Cola a la que pertenece la tarea',
        'payload' => 'Contenido de la tarea',
        'attempts' => 'Veces que se intentó',
        'reserved_at' => 'Cuándo la tomó un proceso',
        'available_at' => 'Desde cuándo se puede ejecutar',
    ],
    'migrations' => [
        'migration' => 'Nombre de la migración aplicada',
        'batch' => 'Tanda en la que se aplicó',
    ],

    // ── Identidad ──
    'personal_access_tokens' => [
        'tokenable_type' => 'Tipo de cuenta dueña de la sesión: usuario de una academia o administrador de plataforma',
        'tokenable_id' => 'Cuenta dueña de la sesión',
        'token' => 'Huella de la clave de sesión; la clave misma no se guarda',
        'abilities' => 'Qué permite la sesión; el acceso de soporte lleva solo lectura',
        'last_used_at' => 'Última petición hecha con la sesión',
        'expires_at' => 'Cuándo vence',
        'ip_address' => 'Dirección IP desde la que se inició la sesión',
        'user_agent' => 'Navegador o aplicación desde la que se inició la sesión',
    ],
    'password_reset_tokens' => [
        'expires_at' => 'Hasta cuándo sirve el enlace para recuperar el acceso',
        'used_at' => 'Cuándo se usó; un enlace usado no sirve otra vez',
    ],
    'permissions' => [
        'code' => 'Código del permiso, con la forma recurso.acción',
    ],
    'roles' => [
        'code' => 'Código del rol dentro de la academia',
        'is_system' => 'Si es uno de los roles base, que no se eliminan',
    ],
    'users' => [
        'password' => 'Contraseña, guardada como huella irreversible',
        'first_name' => 'Nombres',
        'last_name' => 'Apellidos',
        'last_login_at' => 'Último inicio de sesión',
        'doc_type' => 'Tipo de documento de identidad',
        'doc_number' => 'Número de documento de identidad',
        'two_factor_secret' => 'Clave de la verificación en dos pasos (la que lee la aplicación del teléfono), cifrada',
        'two_factor_confirmed_at' => 'Cuándo activó la verificación en dos pasos; vacío = no la tiene',
        'two_factor_recovery_codes' => 'Huellas de los códigos de respaldo que aún no se usaron, cifradas',
    ],
    'platform_admins' => [
        'password' => 'Contraseña, guardada como huella irreversible',
        'last_login_at' => 'Último inicio de sesión',
    ],

    // ── Promociones ──
    'coupon_redemptions' => [
        'discount_amount' => 'Descuento que se aplicó a la orden',
    ],
    'promotion_items' => [
        'product_id' => 'Curso o paquete al que aplica la promoción',
    ],
    'promotions' => [
        'discount_value' => 'Valor del descuento: un porcentaje o un monto fijo, según el tipo',
        'ends_at' => 'Hasta cuándo vale; vacío = sin fecha de término',
        'once_per_customer' => 'Si cada persona puede usarla una sola vez',
    ],

    // ── Ventas ──
    'currencies' => [
        'code' => 'Código de la moneda (PEN, USD)',
        'symbol' => 'Símbolo con el que se muestra',
    ],
    'order_items' => [
        'unit_price' => 'Precio del curso al momento de la compra',
        'quantity' => 'Cantidad: 1 en una compra personal, el número de licencias en una venta a empresa',
        'total_amount' => 'Importe de la línea',
    ],
    'orders' => [
        'subtotal_amount' => 'Importe antes del descuento',
        'total_amount' => 'Importe a pagar',
        'paid_at' => 'Cuándo quedó pagada',
        'cancelled_at' => 'Cuándo se canceló',
        'licenses_release_reason' => 'Por qué se entregaron las licencias antes del pago',
        'refunded_at' => 'Cuándo se reembolsó',
    ],
    'packages' => [
        'summary' => 'Resumen corto que se muestra en el catálogo',
    ],
    'payment_attempts' => [
        'gateway' => 'Pasarela con la que se abrió el cobro',
        'amount' => 'Importe que se le pidió cobrar a la pasarela',
        'currency' => 'Moneda en la que se le pidió cobrar',
        'checkout_url' => 'Página de pago de la pasarela a la que se envió al comprador',
    ],
    'payment_gateway_accounts' => [
        'enabled' => 'Si la pasarela se ofrece a los compradores; en pausa queda conectada sin ofrecerse',
    ],
    'payments' => [
        'amount' => 'Importe pagado',
        'paid_at' => 'Cuándo se hizo el pago',
        'refunded_at' => 'Cuándo se devolvió',
        'refund_reason' => 'Motivo de la devolución',
    ],

    // ── Soporte ──
    'ticket_messages' => [
        'body' => 'Texto del mensaje',
    ],
    'tickets' => [
        'subject' => 'Asunto de la consulta',
        'resolved_at' => 'Cuándo se marcó como resuelta',
        'closed_at' => 'Cuándo se cerró',
        'last_message_by' => 'Quién escribió el último mensaje: el usuario o el personal',
    ],

    // ── Asistente ──
    'search_aliases' => [
        'term' => 'Lo que se buscó, normalizado (minúsculas, sin tildes ni signos)',
        'target_type' => 'Qué se eligió: COURSE (curso) o PACKAGE (paquete); nunca personas',
        'target_id' => 'El curso o paquete elegido',
        'hits' => 'Cuántas veces se eligió ese resultado con ese término',
    ],

    // ── Anuncios y reseñas ──
    'course_announcements' => [
        'title' => 'Título del anuncio',
        'body' => 'Texto del anuncio',
        'emailed_at' => 'Cuándo se avisó por correo a los alumnos; vacío = solo se ve en el aula',
    ],
    'course_reviews' => [
        'rating' => 'Calificación de 1 a 5 estrellas',
        'comment' => 'Comentario del alumno (opcional)',
        'status' => 'VISIBLE u HIDDEN (la ocultó la academia)',
        'hidden_reason' => 'Por qué la academia ocultó la reseña',
        'hidden_by' => 'Quién la ocultó',
        'hidden_at' => 'Cuándo se ocultó',
    ],

    // ── Horarios ──
    'live_class_attendances' => [
        'session_date' => 'Día de la clase en vivo',
        'session_start' => 'Hora de inicio de la clase en vivo (según el horario confirmado)',
        'status' => 'Asistencia: PRESENT (presente), LATE (tarde), ABSENT (ausente) o EXCUSED (falta justificada, no cuenta para el porcentaje)',
        'recorded_by' => 'Quién tomó la asistencia',
    ],
    'teacher_schedules' => [
        'blocks' => 'Bloques de la semana vigente: día (1 = lunes … 7 = domingo), hora de inicio y fin, tipo (AVAILABLE, LIVE_CLASS o GRADING), curso, enlace de la clase y nota',
        'observation' => 'Lo que el docente pidió cambiar al observar el horario',
        'sent_at' => 'Cuándo se envió el horario al docente para que lo confirme',
        'responded_at' => 'Cuándo lo confirmó u observó el docente',
        'confirmed_blocks' => 'Bloques de la última versión que confirmó el docente; es lo que ven los alumnos mientras un cambio espera',
    ],

    // ── Academias ──
    'tenant_mail_settings' => [
        'mode' => 'Con qué correo salen los avisos: PLATFORM (el de la plataforma, con el nombre de la academia) u OWN (su propio servidor de correo)',
        'from_name' => 'Nombre del remitente; vacío = el nombre de la academia',
        'reply_to' => 'Correo al que llegan las respuestas de los avisos',
        'from_address' => 'Correo remitente cuando sale por el servidor propio',
        'smtp' => 'Servidor, puerto, seguridad, usuario y contraseña del servidor de correo propio, cifrados',
        'last_sent_at' => 'Último aviso que salió por el servidor propio',
        'last_error' => 'Por qué falló el servidor propio la última vez (ese aviso salió por la plataforma)',
        'last_error_at' => 'Cuándo falló el servidor propio',
    ],
    'holidays' => [
        'date' => 'Día feriado: no cuenta en los plazos en días hábiles',
    ],
    'idempotency_keys' => [
        'key' => 'Clave que envía el cliente para no repetir una operación',
        'method' => 'Método de la petición',
        'path' => 'Ruta de la petición',
        'response_body' => 'Respuesta que se dio la primera vez, para devolverla si la petición se repite',
    ],
    'tenant_settings' => [
        'key' => 'Nombre del grupo de ajustes de la academia',
        'value' => 'Valores del grupo de ajustes',
    ],
    'tenants' => [
        'legal_name' => 'Razón social',
        'tax_id' => 'RUC u otro número de identificación tributaria',
    ],
];
