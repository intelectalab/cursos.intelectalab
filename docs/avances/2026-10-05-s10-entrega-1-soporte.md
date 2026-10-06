# S10 — Operación · Entrega 1: soporte

Fecha: 2026-10-05 · Sin commit al momento de escribir este documento

Primera de las tres entregas de S10. Quien usa la academia ya puede **pedir ayuda desde su cuenta** y el personal la atiende en una **bandeja de soporte** (Maestro RF-SUP-001, FP-17: crear → asignar → responder → resolver → cerrar o reabrir).

## Qué se construyó

### Para quien pide ayuda: "Ayuda"

En el menú de alumnos, compradores y responsables de empresa.

- **Nueva consulta**: tema (acceso, curso, pago, certificado u otro), asunto, detalle y hasta 3 archivos adjuntos. Si el tema es un curso, un certificado o un pago, puede indicar a cuál de **sus** cursos o compras se refiere.
- Ve la lista de sus consultas con su estado y un aviso "Te respondieron".
- Dentro de cada una, la conversación completa; responde y adjunta archivos.
- Puede **cerrarla** él mismo cuando su problema quedó resuelto.

### Para el personal: "Soporte"

En el menú de Operación, para quien tiene el permiso `tickets.read`.

- Tres cifras arriba: tickets que **esperan respuesta**, **sin asignar** y **a mi cargo**.
- La lista pone primero los que esperan respuesta, del más antiguo al más reciente. Filtros por estado, responsable, tema y texto.
- En cada ticket: responder, dejar una **nota interna** (el usuario no la ve ni puede descargar sus adjuntos), asignarlo, cambiar prioridad, tema y estado.

### Estados y reglas

| Estado | Qué significa |
| --- | --- |
| Abierto | Recién creado; nadie respondió todavía |
| En atención | El personal ya respondió |
| Resuelto | El personal lo dio por resuelto |
| Cerrado | Terminado; no admite más mensajes |

- La **primera respuesta** del personal lo pasa a "En atención" y, si nadie lo tenía, se lo asigna a quien responde. Una nota interna no cuenta como respuesta.
- Si el usuario **responde un ticket resuelto, se reabre solo**.
- Un ticket resuelto **se cierra solo a los 7 días** sin respuesta.
- Un ticket **cerrado no se reabre**: el usuario crea otro.
- La prioridad (baja, normal, alta) la pone solo el personal.

### Quién puede usarlo

| Permiso | Qué permite | Roles base |
| --- | --- | --- |
| `tickets.self`, `tickets.create` | Abrir y seguir las consultas propias | Alumno, Responsable de empresa |
| `tickets.read` | Ver todos los tickets | Administrador, Soporte |
| `tickets.manage` | Responder, asignar y cambiar el estado | Administrador, Soporte |

`tickets.read` y `tickets.manage` son nuevos y quedan cubiertos por el `tickets.*` que ya tenía Soporte. El Responsable de empresa recibió los dos permisos de consultas propias. El Profesor no tiene soporte en su rol (el Maestro no se lo da).

## Detalle técnico

- **Módulo nuevo** `backend/app/Modules/Support`. Tablas del Maestro §5.1: `tickets`, `ticket_messages`, `ticket_attachments`, con RLS.
- **Rutas**: `POST /api/v1/tickets` (Maestro §7.2), `/me/tickets...` para quien consulta, `/tickets...` para el personal y `GET /tickets/attachments/{id}` para descargar un adjunto.
- **Tarea programada nueva**: `tickets:close-resolved`, una vez al día. Usa el mismo cron de `schedule:run` que ya estaba documentado; no hay que configurar nada más.
- **Adjuntos**: uso nuevo `ticket_attachment` (privado; pdf, doc, docx, xls, xlsx, png, jpg, jpeg, webp, zip; hasta 50 MB).
- **Validación de archivos más estricta en todo el sistema**: además del contenido, ahora se exige que la extensión del nombre esté entre las permitidas. Antes un archivo de texto llamado `programa.exe` podía pasar donde se aceptaba texto.
- Auditoría: creación, reapertura, cambios de estado, de responsable y de prioridad, y cierre automático. Código de error nuevo: `TICKET_CLOSED`.
- Frontend: `frontend/src/features/support`.

## Qué se verificó

- **197 pruebas del backend pasan**, 7 nuevas (`tests/Feature/Support/TicketTest.php`): recorrido completo con reapertura y cierre, notas internas, adjuntos (archivo disfrazado, tipo no permitido, más de tres, y quién puede descargar cada uno), referencia solo a cursos y compras propios, asignación y filtros de la bandeja, cierre por el usuario y cierre automático a los 7 días, permisos por rol y aislamiento entre academias.
- De paso se corrigió la prueba de matrículas que había fallado una vez en S8: un nombre de alumno generado al azar que contuviera "ana" se colaba en una búsqueda. Ahora usa un nombre fijo.
- El frontend compila y pasa el lint.
- **En el navegador, academia demo**: como trabajador abrí dos consultas (una ligada a su curso, otra con adjunto) y respondí con un archivo; como administrador vi la bandeja con sus cifras, respondí (pasó a "En atención" y se me asignó) y lo marqué resuelto.
- En esa prueba apareció un error que las pruebas automáticas no veían: al crear la consulta, el adjunto se perdía antes de enviarse. Quedó corregido y vuelto a probar.
- **No se probó en el navegador**: nota interna, descarga de adjuntos, reapertura al responder, cierre por el usuario, filtros, ni el tamaño de teléfono. No pude tomar capturas: la ventana del navegador de pruebas no dibujaba.

## Qué quedó corto

- **Sin avisos por correo**: el usuario se entera de la respuesta al entrar a "Ayuda"; el personal, al entrar a "Soporte" (depende de la decisión 3).
- **Sin tiempos de respuesta comprometidos** ni alertas por demora (PD-009 del Maestro, sin definir).
- **Solo usuarios con cuenta** abren tickets; un visitante no.
- El Responsable de empresa ve solo sus propias consultas, no las de sus trabajadores.
- El personal no puede abrir un ticket a nombre de un usuario (por ejemplo, tras una llamada).
- Sin respuestas predefinidas, sin encuesta de satisfacción y sin reportes de soporte.
- El límite de 50 MB vale para cualquier adjunto, incluidas las imágenes (el sistema define un solo límite por uso).
- El menú no muestra un contador de tickets pendientes.
