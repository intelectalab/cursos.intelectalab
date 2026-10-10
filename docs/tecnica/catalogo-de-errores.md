# Catálogo de errores

> Documento generado con `php artisan docs:technical` a partir del código y de la base de datos. No lo edites a mano: vuelve a generarlo.

Toda respuesta de error de la API tiene la misma forma: `code` (estable, de este catálogo), `message` (texto para la persona), `details` (datos del error; en una validación, el mensaje de cada campo) y `trace_id` (para ubicar la petición en los registros).

El `code` es lo que debe usar un programa; el `message` puede cambiar de redacción. Los mensajes de la tabla son ejemplos tomados del código (hasta tres por código; `{…}` marca un dato que varía).

| Código | HTTP | Mensajes de ejemplo |
| --- | --- | --- |
| `VALIDATION_ERROR` | 422 | «El orden debe incluir exactamente las actividades actuales.»<br>«Este cuestionario no tiene tope de intentos.»<br>«Revisa las respuestas de la encuesta.» |
| `UNAUTHENTICATED` | 401 | «No autenticado.» |
| `FORBIDDEN` | 403 | «Tu cuenta no administra ninguna empresa.»<br>«Solo una cuenta de alumno puede comprar cursos.»<br>«La firma del aviso no es válida.» |
| `NOT_FOUND` | 404 | «El cuestionario no existe.»<br>«El cuestionario no existe en la versión publicada.»<br>«La entrega no tiene un archivo.» |
| `METHOD_NOT_ALLOWED` | 405 | «Método no permitido.» |
| `TOO_MANY_REQUESTS` | 429 | «Demasiadas solicitudes. Intente nuevamente más tarde.» |
| `HTTP_ERROR` | el de la respuesta | «No se pudo procesar la solicitud.» |
| `INTERNAL_ERROR` | 500 | Lo produce el manejo general de errores. |
| `IDEMPOTENCY_KEY_REUSED` | 422 | «Esa clave de idempotencia ya se usó con otra operación.» |
| `IDEMPOTENCY_IN_PROGRESS` | 409 | «La operación anterior todavía está en curso. Intente nuevamente en unos segundos.» |
| `TENANT_NOT_FOUND` | 404 | «La organización no existe.» |
| `TENANT_INACTIVE` | 403, 409 | «La organización está suspendida: reactívala para poder entrar.»<br>«La organización se encuentra suspendida o inactiva.» |
| `TENANT_REQUIRED` | 404 | «Esta operación solo está disponible desde el subdominio de una organización.» |
| `CENTRAL_ONLY` | 404 | «Esta operación solo está disponible desde el dominio central de la plataforma.» |
| `INVALID_CREDENTIALS` | 401, 422 | «Las credenciales proporcionadas son incorrectas.»<br>«La contraseña actual no es correcta.» |
| `TOO_MANY_ATTEMPTS` | 429 | «Demasiados intentos fallidos. Intente nuevamente en {…} segundos.» |
| `ACCOUNT_LOCKED` | 429 | «La cuenta está bloqueada por demasiados intentos fallidos. Intente nuevamente en {…} minutos o recupere su contraseña.» |
| `ACCOUNT_INACTIVE` | 403 | «La cuenta de usuario se encuentra suspendida o inactiva.»<br>«La cuenta se encuentra inactiva.» |
| `PASSWORD_CHANGE_REQUIRED` | 403 | «Debes cambiar tu contraseña temporal antes de continuar.» |
| `SUPPORT_READ_ONLY` | 403 | «Estás en modo soporte: puedes consultar, pero no cambiar nada.» |
| `INVALID_RESET_TOKEN` | 422 | «El enlace no es válido o ya venció. Solicita uno nuevo.» |
| `PERMISSION_DENIED` | 403 | «No cuenta con el permiso requerido para gestionar las actividades de este curso.»<br>«No cuenta con el permiso requerido para editar el contenido de este curso.»<br>«No tienes permiso para agregar participantes con ese papel.» |
| `PRIVILEGE_ESCALATION` | 403 | «No puedes otorgar permisos que tu propio usuario no tiene.» |
| `ROLE_IS_SYSTEM` | 409 | «Los roles base de la plataforma no se pueden modificar ni eliminar.» |
| `ROLE_IN_USE` | 409 | «El rol tiene usuarios asignados. Reasígnalos antes de eliminarlo.» |
| `LAST_ADMINISTRATOR` | 409 | «La organización debe conservar al menos un administrador activo.» |
| `GRADE_SCALE_IN_USE` | 409 | «Esta escala está en uso. Elige otra escala de notas y guarda antes de eliminarla.» |
| `COURSE_NOT_PUBLISHABLE` | 422 | «Para publicar, el curso necesita al menos una sección con un recurso o una actividad.»<br>«No hay cambios nuevos que publicar.» |
| `COURSE_INVALID_STATE` | 409 | «El curso no está archivado.»<br>«El curso está archivado. Restáuralo para poder modificarlo.»<br>«Solo se puede matricular en un curso publicado.» |
| `QUESTION_IN_USE` | 409 | «No se puede eliminar: {…} de sus preguntas se usan en cuestionarios.»<br>«No se puede eliminar: la pregunta se usa en al menos un cuestionario. Quítala primero de ahí.» |
| `ENROLLMENT_NOT_ACTIVE` | 409 | «La matrícula está cancelada.»<br>«La matrícula ya no está activa.» |
| `ENROLLMENT_EXPIRED` | 403 | «Tu acceso a este curso venció el» |
| `ENROLLMENT_NOT_STARTED` | 403 | «Tu acceso a este curso empieza el» |
| `SECTION_LOCKED` | 403 | «Completa las secciones anteriores para abrir esta.» |
| `STUDENT_LIMIT_REACHED` | 409 | «La academia tiene {…} de los {…} alumnos que permite su plan»<br>«Por ahora la academia no está recibiendo nuevos alumnos. Comunícate con ella para inscribirte.» |
| `PLAN_READ_ONLY` | 403 | «La academia está en solo lectura porque su plan venció: se puede consultar todo, pero no registrar cambios hasta renovarlo.» |
| `PLAN_ORDER_NOT_REVERTIBLE` | 409 | El mensaje se arma según el caso: ver dónde se usa el código. |
| `COURSE_NOT_FOR_SALE` | 409 | «Esto ya no está en venta.»<br>«Este curso no está en venta.»<br>«Este paquete no está en venta.» |
| `PRICE_CHANGED` | 409 | «El total cambió. Revisa el nuevo importe antes de continuar.» |
| `ALREADY_ENROLLED` | — | El mensaje se arma según el caso: ver dónde se usa el código. |
| `ORDER_ALREADY_PENDING` | 409 | «Ya tienes una orden de plan pendiente de pago. Págala o cancélala antes de pedir otra.»<br>«Ya tienes una orden pendiente de pago por esta compra.» |
| `ORDER_NOT_PENDING` | 409 | «Solo se pueden adelantar las licencias de una orden pendiente de pago.»<br>«Esta orden ya no está pendiente.» |
| `ORDER_NOT_REFUNDABLE` | 409 | El mensaje se arma según el caso: ver dónde se usa el código. |
| `COUPON_INVALID` | — | El mensaje se arma según el caso: ver dónde se usa el código. |
| `GATEWAY_NOT_AVAILABLE` | 409 | «Esta orden no se puede pagar por ese medio.»<br>«La cuenta con la que se cobró este pago ya no está conectada. Vuelve a conectarla en Planes para poder devolverlo.»<br>«Primero hay que conectar la cuenta.» |
| `GATEWAY_ERROR` | 502 | «Mercado Pago no respondió. Inténtalo de nuevo en unos minutos.»<br>«Mercado Pago no aceptó la devolución»<br>«PayPal no devolvió a dónde enviar el pago. Inténtalo de nuevo.» |
| `CERTIFICATE_NOT_ELIGIBLE` | 409 | El mensaje se arma según el caso: ver dónde se usa el código. |
| `CERTIFICATE_REVOKED` | 409 | «El certificado ya fue anulado.»<br>«El certificado fue anulado y ya no se puede descargar.» |
| `LEAD_NOT_OPEN` | 409 | «Este prospecto ya fue convertido o descartado.» |
| `QUOTATION_INVALID_STATE` | 409 | El mensaje se arma según el caso: ver dónde se usa el código. |
| `QUOTATION_INCOMPLETE` | 409 | «Agrega al menos un curso con precio antes de enviar la cotización.»<br>«La fecha de vigencia ya pasó. Cámbiala antes de enviar.» |
| `QUOTATION_EXPIRED` | 409 | «La cotización venció. Hay que enviar una versión nueva.» |
| `ORDER_NOT_CORPORATE` | 409 | «Solo una venta a empresa entrega licencias.» |
| `LICENSE_NOT_AVAILABLE` | 409 | «Esta licencia ya no está disponible.»<br>«Esta asignación ya fue retirada.» |
| `LICENSE_IN_USE` | 409 | «El trabajador ya empezó el curso: la licencia no se puede retirar.» |
| `TICKET_CLOSED` | 409 | «Este ticket está cerrado. Si el problema continúa, abre uno nuevo.» |
| `CLAIM_ALREADY_ANSWERED` | 409 | «Esta hoja ya fue respondida.» |
| `PAGE_NOT_PUBLISHABLE` | 409 | «La página está vacía. Escribe su contenido antes de publicarla.»<br>«Retira la página del sitio antes de eliminarla.» |
| `NO_ATTEMPTS_LEFT` | 409 | «Ya usaste todos los intentos de este cuestionario.» |
| `ATTEMPT_CLOSED` | 409 | «Este intento ya se cerró.»<br>«Se acabó el tiempo de este intento.» |
| `SUBMISSION_GRADED` | 409 | «La tarea ya fue calificada y no se puede volver a entregar.» |
| `SUBMISSION_LATE` | 409 | «El plazo de esta tarea ya venció y no admite entregas tardías.» |
| `SURVEY_ALREADY_ANSWERED` | 409 | «Ya respondiste esta encuesta.» |
