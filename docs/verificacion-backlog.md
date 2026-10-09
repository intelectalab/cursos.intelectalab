# Verificación contra el backlog (historias YC)

Comparación entre las historias del backlog que entrega jefatura (códigos `YC-…`) y lo que el sistema ya hace. El backlog se usa como **lista de verificación**: el Documento Maestro v2 sigue mandando en nombres de tablas y en lo ya construido; lo que falte se agrega sin renombrar ni rehacer lo que funciona.

El backlog llega por partes (un sprint por semana). Cada parte se agrega aquí.

Última actualización: 2026-10-08, al cerrar el cierre por tiempo y la nota mínima del curso. El sprint 2 del backlog (12 épicas) se comparó el 2026-10-07. El sprint 1 se comparó el 2026-10-06, al cerrar S12.

**Estados:** Hecho · Parcial (existe, pero le falta algo de lo que pide la historia) · Falta · Distinto (se resolvió de otra forma por una decisión ya tomada).

## Sprint 1 del backlog

Resumen: 62 historias. 20 hechas, 19 parciales, 18 faltan y 5 se resolvieron distinto. Antes de S12 eran 16, 22, 19 y 5: S12 cerró cuatro y dejó una a medias.

### TEN — Academias y servicios comunes

| Historia | Estado | Qué hay y qué falta |
| --- | --- | --- |
| YC-TEN-001 Aislamiento con RLS | Hecho | Política forzada en las 60 tablas con `tenant_id`, más el filtro de la aplicación. Desde S12 una prueba revisa que ninguna tabla quede sin política. |
| YC-TEN-002 Academia por host | Hecho | Se resuelve por subdominio; academia inexistente o suspendida se rechaza. No hay dominios propios por academia. |
| YC-TEN-003 Correlativos | Parcial | Órdenes, cotizaciones, tickets, reclamos y certificados tienen número legible, pero cada módulo lo calcula por su cuenta. No hay tabla común de correlativos. |
| YC-TEN-004 Archivos y URL firmada | Parcial | Registro de archivos públicos y privados, con huella y validación de contenido. Los privados se descargan con sesión y permiso, no con una URL firmada que caduca. |
| YC-TEN-005 Idempotencia | Parcial (S12) | Hecha para la compra y la confirmación de pago, con clave que vale 24 horas. El resto de operaciones con efecto sigue con sus protecciones propias. |
| YC-TEN-006 Ajustes y banderas | Parcial | Ajustes por academia bajo una sola clave. No hay banderas para encender o apagar funciones. |
| YC-TEN-007 Límites del plan | Falta | No existe el concepto de plan. |

### AUTH — Acceso

| Historia | Estado | Qué hay y qué falta |
| --- | --- | --- |
| YC-AUTH-001 Bloqueo por intentos | Hecho (S12) | 10 fallos seguidos bloquean la cuenta 15 minutos, además del freno por dirección. Se configura para toda la plataforma, no por academia. |
| YC-AUTH-002 Doble factor del personal | Falta | No existe. |
| YC-AUTH-003 Registro con textos legales | Hecho (S12) | El registro exige aceptar los textos, enlaza las páginas publicadas y guarda la fecha y hora. |
| YC-AUTH-004 Recuperar y cambiar contraseña | Hecho | Completo; el correo queda en el registro interno hasta tener proveedor. |
| YC-AUTH-005 Registro de accesos | Hecho (S12) | Inicios de sesión correctos, fallidos, bloqueos y accesos denegados quedan en Auditoría. No hay una tabla aparte de accesos: se consultan filtrando la auditoría. |
| YC-AUTH-006 Cierre y sesiones abiertas | Hecho | El usuario ve y cierra sus sesiones; desde S12 el administrador cierra las de otra persona. |

### USR — Usuarios y roles

| Historia | Estado | Qué hay y qué falta |
| --- | --- | --- |
| YC-USR-001 Modelo de usuarios y roles | Parcial | 7 roles base y roles propios por academia. El backlog pide 8 (falta saber cuál) y permisos directos por usuario, que no existen. |
| YC-USR-002 Alta, invitación y edición | Parcial | Alta y edición sí. Invitación por correo no: el administrador pone la contraseña inicial. |
| YC-USR-003 Desactivar, bloquear, reactivar | Parcial | Solo activo e inactivo; no hay estado "bloqueado" aparte. |
| YC-USR-004 Roles, matriz y mapa de acceso | Hecho | Pantalla de roles con matriz de permisos. Falta aclarar qué es el "mapa de acceso". |
| YC-USR-005 Alcance de datos por rol | Parcial | Caso por caso (el profesor solo sus cursos, el responsable solo su empresa); no es una regla general por rol. |
| YC-USR-006 Varios roles y rol activo | Parcial | Varios roles con permisos sumados. No hay selector de "rol activo". |

### ADM — Ajustes y reglas

| Historia | Estado | Qué hay y qué falta |
| --- | --- | --- |
| YC-ADM-001 Servicio de reglas | Falta | Las reglas configurables son campos sueltos de los ajustes, no un catálogo. |
| YC-ADM-002 Centro de reglas | Falta | No hay pantalla para activar, parametrizar y restablecer reglas. |
| YC-ADM-003 Marca, contacto y datos fiscales | Hecho | Nombre, razón social, RUC, logo, contacto, moneda y zona horaria. Sin colores de marca. |
| YC-ADM-004 Feriados y días hábiles | Hecho (S12) | Feriados por academia, con carga de los nacionales del año; el plazo de reclamos los descuenta. Pendiente de revisión legal. |

### AUD — Auditoría

| Historia | Estado | Qué hay y qué falta |
| --- | --- | --- |
| YC-AUD-001 Registro inmutable | Hecho | La base de datos rechaza modificar o borrar eventos. |
| YC-AUD-002 Consulta con antes y después | Hecho | Pantalla con filtros por acción, entidad, usuario y fechas, y el antes y después de cada cambio. Sin exportar. |

### MAIL — Correos

Todo este módulo depende de la decisión 3 de jefatura (proveedor de correo).

| Historia | Estado | Qué hay y qué falta |
| --- | --- | --- |
| YC-MAIL-001 Motor por evento con bandeja de salida | Falta | Hoy solo existe el correo de recuperar contraseña, y va al registro interno. |
| YC-MAIL-002 Plantillas editables | Falta | — |
| YC-MAIL-003 Bandeja de enviados y reenvío | Falta | — |
| YC-MAIL-004 Dominio de envío y DNS | Falta | Depende del proveedor. |
| YC-MAIL-005 Preferencias de correo del alumno | Falta | — |
| YC-MAIL-006 Recordatorios de cuotas | Falta | Tampoco existen las cuotas (pago en partes). |

### CUR — Cursos

| Historia | Estado | Qué hay y qué falta |
| --- | --- | --- |
| YC-CUR-001 Catálogo interno y creación | Hecho | La categoría es texto libre, no un catálogo de categorías. |
| YC-CUR-002 Ficha comercial | Hecho | Portada, resumen, descripción, nivel, horas, precio y días de acceso. |
| YC-CUR-003 Docentes asignados | Hecho | — |
| YC-CUR-004 Publicar, despublicar y retirar | Parcial | Publicar, archivar y restaurar. No hay "despublicar" sin archivar ni una lista de requisitos antes de publicar. |
| YC-CUR-005 Revisión antes de publicar | Parcial | Publicar es un permiso aparte, pero no hay un paso de "enviar a revisión" con aprobación o rechazo. |
| YC-CUR-006 Versiones con comparación | Parcial | Cada publicación guarda una versión numerada. No se pueden ver ni comparar desde la pantalla. |
| YC-CUR-007 Anuncios del docente | Falta | — |
| YC-CUR-008 Reseñas y moderación | Falta | — |
| YC-CUR-009 Resumen y alumnos del curso | Hecho | Matriculados y seguimiento por curso. |

### LMS — Contenido

| Historia | Estado | Qué hay y qué falta |
| --- | --- | --- |
| YC-LMS-001 Constructor del temario | Hecho | Secciones, recursos y actividades ordenables. Recursos y actividades no se intercalan dentro de una sección. |
| YC-LMS-002 Publicar, cerrar y reabrir actividades | Distinto | Las actividades se publican junto con la versión del curso; no tienen estado propio ni fechas de cierre. |
| YC-LMS-003 Avance por ítem | Hecho | Cada ítem completado recalcula el avance y, al llegar al 100 %, marca el curso como completado. |
| YC-LMS-004 Biblioteca de recursos y videos | Falta | Cada archivo pertenece a un curso; no hay biblioteca para reutilizarlos. |

### MAT — Matrículas

| Historia | Estado | Qué hay y qué falta |
| --- | --- | --- |
| YC-MAT-001 Matrícula única con vigencia | Hecho | — |
| YC-MAT-002 Listado y matrícula manual | Hecho | — |
| YC-MAT-003 Detalle: avance, notas y recálculo | Parcial | El avance y las notas se ven en el seguimiento del curso; no hay ficha por matrícula ni nota final del curso. |
| YC-MAT-004 Suspender, reactivar y anular | Parcial | Solo anular. No hay suspensión temporal. |
| YC-MAT-005 Vencimiento y acceso tras vencer | Parcial | Al vencer se pierde el acceso. No es configurable qué se conserva tras vencer (por ejemplo, ver el certificado o el material). |
| YC-MAT-006 Extender y renovar | Parcial | El personal cambia las fechas y el avance se conserva. No hay "renovar" como operación con historial. |

### NOT — Notificaciones

| Historia | Estado | Qué hay y qué falta |
| --- | --- | --- |
| YC-NOT-001 Campana de avisos | Falta | — |
| YC-NOT-002 WhatsApp o SMS | Falta | Necesita proveedor. Es de la fase 3 del backlog. |

### MON — Monedas

| Historia | Estado | Qué hay y qué falta |
| --- | --- | --- |
| YC-MON-001 Monedas y tipo de cambio manual | Parcial | La academia elige moneda principal y secundaria (soles, dólares). No hay tipo de cambio. |
| YC-MON-002 Precio por moneda | Distinto | Decisión de S5: una moneda por academia, un solo precio por curso y paquete. |
| YC-MON-003 Tipo de cambio automático | Falta | Fuente sin definir (PD-011 del Maestro). |

### VID — Video protegido

Decisión ya tomada con jefatura: los videos se alojan en YouTube y el sistema solo guarda el enlace. Todo este módulo contradice esa decisión.

| Historia | Estado | Qué hay y qué falta |
| --- | --- | --- |
| YC-VID-001 Subida al proveedor | Distinto | Se pega el enlace de YouTube. |
| YC-VID-002 Enlace firmado que vence | Distinto | YouTube no lo permite: un video «No listado» lo ve cualquiera con el enlace. |
| YC-VID-003 Marca de agua con el dato del alumno | Distinto | No es posible con YouTube. |
| YC-VID-004 Límite de sesiones simultáneas | Falta | — |
| YC-VID-005 Configuración del video protegido | Falta | — |

### DASH — Panel de inicio

| Historia | Estado | Qué hay y qué falta |
| --- | --- | --- |
| YC-DASH-001 Panel según el rol | Parcial | El alumno ve su avance; el resto ve accesos directos a sus módulos y dos cifras. |
| YC-DASH-002 Indicadores y pendientes por rol | Parcial | Los indicadores están en Reportes (para quien tiene permiso), no en el inicio; no hay lista de pendientes por rol. |

## Sprint 2 del backlog

Recibido el 2026-10-07: 12 épicas y 83 historias (la épica B2C vino pegada dos veces; se cuenta una). Solo llegaron los títulos de las historias y el contexto de cada épica, sin criterios de aceptación ni el texto de las reglas. La comparación se hizo por título contra el código: una historia marcada "Hecho" puede tener criterios que todavía no conocemos.

Resumen: 83 historias. 32 hechas, 18 parciales, 30 faltan y 3 se resolvieron distinto. Al compararlo eran 21, 25, 34 y 3: "Evaluaciones y aula" cerró seis (EVA-005, 006, 007 y 009; AULA-003 y 004) y "Alumnos y docentes", tres (ALU-001, ALU-002 y PRO-001), además de dejar PRO-002 a medias; el buscador del catálogo, una (WEB-002); y el cierre por tiempo, otra (EVA-004), con lo que Evaluaciones queda completa. De las 30 que faltan, 16 son de Pagos y Facturación electrónica, que esperan la decisión 8.

| Épica | Historias | Hecho | Parcial | Falta | Distinto |
| --- | --- | --- | --- | --- | --- |
| EVA Evaluaciones | 9 | 9 | 0 | 0 | 0 |
| CER Certificados | 7 | 3 | 3 | 1 | 0 |
| AULA | 4 | 3 | 1 | 0 | 0 |
| B2C Venta a personas | 9 | 3 | 3 | 2 | 1 |
| PAG Pagos | 10 | 0 | 3 | 7 | 0 |
| FAC Facturación electrónica | 9 | 0 | 0 | 9 | 0 |
| PRM Promociones | 16 | 4 | 3 | 7 | 2 |
| APR Aprobaciones | 2 | 0 | 0 | 2 | 0 |
| ALU Alumnos | 2 | 2 | 0 | 0 | 0 |
| WEB Sitio público | 6 | 2 | 2 | 2 | 0 |
| CAMPUS Portal del alumno | 7 | 5 | 2 | 0 | 0 |
| PRO Docentes | 2 | 1 | 1 | 0 | 0 |

### EVA — Evaluaciones

| Historia | Estado | Qué hay y qué falta |
| --- | --- | --- |
| YC-EVA-001 Bancos con editor por tipo | Hecho | Bancos por academia y siete tipos de pregunta, cada uno con su editor, más importación desde Excel. Falta la pregunta de respuesta abierta. |
| YC-EVA-002 Configuración del cuestionario | Hecho | Nota mínima, intentos, tiempo límite, orden al azar y puntos por pregunta. Sin fechas de apertura y cierre. |
| YC-EVA-003 Rendir con calificación en el servidor | Hecho | El servidor arma el intento, guarda el borrador y califica; las respuestas correctas no viajan al navegador. |
| YC-EVA-004 Cierre automático al agotarse el tiempo | Hecho (2026-10-08) | El intento vence solo y se califica lo que el alumno dejó guardado; si aprueba, cuenta para el avance. Sin nada guardado, vale cero. |
| YC-EVA-005 Intentos, intentos adicionales y revisión | Hecho (2026-10-08) | Tope de intentos y cuenta el mejor; el alumno revisa su intento. Quien revisa ve cada intento con sus respuestas y da intentos adicionales con motivo. No se pueden retirar una vez dados. |
| YC-EVA-006 Entrega de tareas, a tiempo o tardía | Hecho (2026-10-08) | Entrega con texto o archivo. Plazo opcional en días desde la matrícula; fuera de plazo se marca como tardía o se rechaza, según la tarea. No es una fecha de calendario ni hay prórroga por alumno. |
| YC-EVA-007 Calificar y devolver tareas | Hecho (2026-10-08) | Se califica con nota y comentario, o se devuelve con comentario para que el alumno la rehaga. No se guarda el historial de entregas anteriores. |
| YC-EVA-008 Encuestas: responder y ver resultados | Hecho | Una respuesta por alumno y resultados sin nombres. |
| YC-EVA-009 Libro de notas y nota final | Hecho (2026-10-08) | Nota final por alumno como promedio de cuestionarios y tareas según su peso, a la vista de quien revisa y del alumno, con descarga en Excel. Sin nota mínima para aprobar el curso ni categorías de peso. |

### CER — Certificados

| Historia | Estado | Qué hay y qué falta |
| --- | --- | --- |
| YC-CER-001 Plantillas de certificado | Parcial | Una plantilla por academia con fondo propio, posiciones, colores y vista previa. No hay varias plantillas ni una por curso. |
| YC-CER-002 Requisitos generales y por curso | Parcial | Cada curso decide si entrega certificado; se exige el 100 % completado y, desde el 2026-10-08, la nota mínima del curso si la tiene. Faltan la encuesta obligatoria y los requisitos generales de la academia. |
| YC-CER-003 Emisión automática con código y QR | Hecho | — |
| YC-CER-004 Emitidos y emisión manual o forzada | Hecho | Listado, emisión manual de uno o de varios pendientes. La manual exige que el curso esté completado: no hay emisión "forzada" sin cumplir requisitos. |
| YC-CER-005 Detalle, descarga y revocación | Parcial | Descarga del PDF y anulación con motivo desde el listado. No hay pantalla de detalle por certificado. |
| YC-CER-006 Verificación pública por código o QR | Hecho | Página pública; cada consulta queda registrada. |
| YC-CER-007 Asistencia mínima (F2) | Falta | No existe la asistencia: los cursos son grabados. Va ligada al módulo de horarios (decisión 15). |

### AULA

| Historia | Estado | Qué hay y qué falta |
| --- | --- | --- |
| YC-AULA-001 Portada con avance, anuncios y requisitos del certificado | Parcial | Portada con el avance y el temario. No hay anuncios (tampoco existen en Cursos) ni se muestran los requisitos del certificado. |
| YC-AULA-002 Reproductor: video, PDF, texto y enlace | Hecho | Los cuatro tipos, con registro de avance al abrirlos. El video es de YouTube. |
| YC-AULA-003 Avance secuencial por secciones | Hecho (2026-10-08) | Interruptor por curso: cada sección se abre al completar la anterior, y el servidor no entrega el contenido de una sección cerrada. Un cuestionario bloquea hasta aprobarlo. |
| YC-AULA-004 Vista previa para profesores y administradores | Hecho (2026-10-08) | "Ver como alumno" abre el aula de lo publicado o del borrador, sin avance. De las actividades se ve la ficha; no se rinden. |

### B2C — Venta a personas

| Historia | Estado | Qué hay y qué falta |
| --- | --- | --- |
| YC-B2C-001 Carrito | Distinto | Decisión de S5: un curso (o un paquete) por compra, sin carrito. |
| YC-B2C-002 Precios y totales solo en el backend | Hecho | — |
| YC-B2C-003 Pedido con datos de facturación y precio congelado | Parcial | El pedido guarda título y precio del momento. No pide datos de facturación (boleta o factura, RUC). |
| YC-B2C-004 Efectos del primer pago aprobado | Parcial | El pago confirmado matricula. El cupón se cuenta al crear el pedido, no al pagar; no hay carrito que vaciar ni comprobante. |
| YC-B2C-005 Pedido del alumno: estado y cancelación | Hecho | "Mis compras" con el estado, las instrucciones de pago y cancelar si está pendiente. |
| YC-B2C-006 Caducidad y pedidos fallidos | Parcial | El pedido pendiente vence a las 72 horas. No existe el estado "fallido" porque no hay pasarela. |
| YC-B2C-007 Pedidos en el back-office | Hecho | Listado con filtros y tarjetas, detalle, confirmar pago y cancelar. |
| YC-B2C-008 Venta asistida | Falta | El personal no puede registrar una venta a nombre de un alumno (sí matricular directamente). |
| YC-B2C-009 Cierre del pedido al completarse sus matrículas | Falta | El pedido termina en "Pagado" y matricula en ese mismo momento; no hay un estado "cerrado". Falta saber qué pide la historia. |

### PAG — Pagos

Todo este módulo es el S6 del plan y espera la decisión 8 de jefatura (pasarela, quién cobra, Yape, reembolsos).

| Historia | Estado | Qué hay y qué falta |
| --- | --- | --- |
| YC-PAG-001 Configuración de la pasarela | Falta | Está el punto de conexión en el código, sin ninguna pasarela enchufada. |
| YC-PAG-002 Métodos de pago por academia | Falta | Hoy la academia solo escribe sus instrucciones de pago. |
| YC-PAG-003 Tarjeta con reintentos | Falta | — |
| YC-PAG-004 Yape con celular y código | Falta | — |
| YC-PAG-005 Tarjeta guardada | Falta | — |
| YC-PAG-006 PagoEfectivo (CIP) | Falta | — |
| YC-PAG-007 Transferencia y Yape o Plin por QR con constancia | Parcial | El alumno paga por fuera con las instrucciones de la academia. No hay QR ni puede adjuntar su constancia. |
| YC-PAG-008 Conciliación manual por Finanzas | Parcial | El personal confirma el pago con medio, referencia y nota. No existe el rol Finanzas ni una bandeja de pagos por conciliar. |
| YC-PAG-009 Webhooks con firma e idempotencia | Falta | La idempotencia de la compra y de la confirmación ya existe; los avisos de la pasarela no. |
| YC-PAG-010 Pagos en el back-office | Parcial | Los pagos se ven dentro de cada pedido; no hay un listado propio de pagos. |

### FAC — Facturación electrónica

Depende de la decisión 8 (comprobantes, RUC, IGV) y de contratar un OSE o PSE. Decisión vigente de S5: precio final, sin IGV ni comprobante.

| Historia | Estado | Qué hay y qué falta |
| --- | --- | --- |
| YC-FAC-001 Configuración: OSE/PSE, series y certificado | Falta | — |
| YC-FAC-002 IGV en tres modos | Falta | — |
| YC-FAC-003 Boleta o factura automática al pagar | Falta | — |
| YC-FAC-004 Envío de facturas y respuesta de SUNAT | Falta | — |
| YC-FAC-005 Resumen diario de boletas | Falta | — |
| YC-FAC-006 Corrección y reenvío de rechazados | Falta | — |
| YC-FAC-007 Comunicación de baja | Falta | — |
| YC-FAC-008 Notas de crédito | Falta | — |
| YC-FAC-009 Bandeja de comprobantes | Falta | — |

### PRM — Promociones

| Historia | Estado | Qué hay y qué falta |
| --- | --- | --- |
| YC-PRM-001 Porcentaje y monto fijo con alcance | Hecho | Aplica a todo o a los cursos y paquetes elegidos. No hay alcance por categoría. |
| YC-PRM-002 Ciclo de vida | Parcial | Activa o inactiva, con fechas de inicio y fin. "Programada", "pausada" y "vencida" no son estados propios: se deducen. |
| YC-PRM-003 Promoción automática por línea | Falta | Todo descuento necesita un código. |
| YC-PRM-004 Cupones: códigos, límites y activación | Hecho | Varios cupones por promoción, cada uno se activa o desactiva. |
| YC-PRM-005 Aplicar cupón con el motivo del rechazo | Hecho | En el checkout (no hay carrito), con ocho motivos distintos. |
| YC-PRM-006 Acumulación de promoción y cupón | Distinto | Decisión de S11: los descuentos no se acumulan, un cupón por compra. |
| YC-PRM-007 Canje al aprobarse el primer pago | Distinto | Decisión de S11: el uso cuenta al crear el pedido y se libera si vence o se cancela. El tope se controla con bloqueo en la base de datos. |
| YC-PRM-008 Condiciones del comprador (F2) | Parcial | Compra mínima y una vez por persona. Faltan primera compra, segmento y moneda. |
| YC-PRM-009 Tipos avanzados (F2) | Falta | — |
| YC-PRM-010 Exclusivas de un convenio (F3) | Falta | — |
| YC-PRM-011 Lotes de cupones de un solo uso (F2) | Falta | — |
| YC-PRM-012 Campañas (F2) | Falta | — |
| YC-PRM-013 Simulador «Probar cupón» | Falta | — |
| YC-PRM-014 Registro de intentos e indicadores | Parcial | Se registran los usos de cada cupón. No los intentos rechazados ni indicadores. |
| YC-PRM-015 Exclusiones (F2) | Falta | Tampoco existen los pagos en cuotas. |
| YC-PRM-016 Alcance de paquetes (F2) | Hecho | — |

### APR — Aprobaciones

| Historia | Estado | Qué hay y qué falta |
| --- | --- | --- |
| YC-APR-001 Motor de solicitudes | Falta | Hoy quien tiene el permiso ejecuta directamente (publicar, entregar licencias sin pago, anular). |
| YC-APR-002 Bandeja «Por aprobar» | Falta | — |

### ALU — Alumnos

| Historia | Estado | Qué hay y qué falta |
| --- | --- | --- |
| YC-ALU-001 Listado y alta de alumnos | Hecho (2026-10-08) | Pantalla "Alumnos" con tarjetas que filtran, buscador y alta con el rol ya puesto. La importación masiva sigue en Usuarios. |
| YC-ALU-002 Alumno 360 | Hecho (2026-10-08) | Ficha de solo lectura con sus datos, cursos (avance y nota), compras, certificados y consultas. No se actúa desde la ficha ni se limita por bloques según el rol. |

### WEB — Sitio público

| Historia | Estado | Qué hay y qué falta |
| --- | --- | --- |
| YC-WEB-001 Portada del sitio | Falta | La cara pública es el catálogo; no hay portada editable. |
| YC-WEB-002 Catálogo con filtros | Hecho (2026-10-08) | Buscador por texto y filtros por categoría, nivel y precio, combinables y guardados en la dirección. Sin orden por precio o fecha ni paginación. |
| YC-WEB-003 Ficha pública del curso | Hecho | — |
| YC-WEB-004 Rutas de aprendizaje y ficha del paquete (F2) | Parcial | Paquetes con ficha pública. No existe la "ruta" como concepto aparte. |
| YC-WEB-005 Landing para empresas con formulario (F3) | Falta | Los prospectos los registra o importa el personal. |
| YC-WEB-006 Posicionamiento, caché y rendimiento | Parcial | El rendimiento se trabajó en S12. Para buscadores no hay nada: es una sola aplicación que se arma en el navegador. |

### CAMPUS — Portal del alumno

| Historia | Estado | Qué hay y qué falta |
| --- | --- | --- |
| YC-CAMPUS-001 Inicio del campus | Hecho | — |
| YC-CAMPUS-002 Catálogo del campus | Hecho | "Cursos disponibles". No muestra los paquetes. |
| YC-CAMPUS-003 Ficha del curso y agregar al carrito | Parcial | Ficha con compra directa; sin carrito. |
| YC-CAMPUS-004 Mis cursos con avance y vigencia | Hecho | — |
| YC-CAMPUS-005 Mis certificados | Hecho | Ver, descargar y añadir a LinkedIn. |
| YC-CAMPUS-006 Mis pagos: pedidos y comprobantes | Parcial | Pedidos sí; comprobantes no existen. |
| YC-CAMPUS-007 Mi perfil | Hecho | Datos, contraseña y sesiones abiertas. |

### PRO — Docentes

| Historia | Estado | Qué hay y qué falta |
| --- | --- | --- |
| YC-PRO-001 Listado y alta de docentes | Hecho (2026-10-08) | Pantalla "Docentes" con tarjetas que filtran, buscador, carga por calificar y alta con el rol ya puesto. La asignación a cursos sigue en cada curso. |
| YC-PRO-002 Ficha con cursos, carga de calificación y valoraciones | Parcial (2026-10-08) | Ficha con sus cursos, alumnos, entregas por calificar (y desde cuándo esperan) y lo que calificó en 30 días. Faltan las valoraciones: dependen de las reseñas de cursos (CUR-008), que no existen. |

## Dudas para quien escribió el backlog

1. Cuál es el octavo rol (hoy hay siete: Administrador, Alumno, Profesor, Soporte, Ventas/CRM, B2B autorizado y Responsable de empresa).
2. Qué es el "mapa de acceso" de YC-USR-004.
3. El texto de las reglas que citan las épicas (`RN-…` y `R-…`): no vinieron con la lista.
4. Video protegido (VID): confirmar si se cambia la decisión de usar YouTube. Hacerlo exige contratar un proveedor de video.
5. Precio por moneda (MON-002): confirmar si se cambia la decisión de una sola moneda por academia.

Del sprint 2:

6. Los criterios de aceptación de las 83 historias: solo llegaron los títulos.
7. Carrito (B2C-001, CAMPUS-003): confirmar si se cambia la decisión de un curso o paquete por compra.
8. Acumulación y momento del canje (PRM-006, PRM-007): confirmar si se cambia lo decidido en S11.
9. Qué significa "cierre del pedido al completarse sus matrículas" (B2C-009).
10. El rol Finanzas aparece en PAG, FAC, APR y ALU: ¿es el octavo rol de la duda 1?
11. Qué diferencia una "ruta de aprendizaje" de un paquete (WEB-004).
12. Emisión "forzada" de certificados (CER-004): ¿sin cumplir los requisitos, con motivo?
