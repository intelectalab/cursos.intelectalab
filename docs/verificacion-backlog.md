# Verificación contra el backlog (historias YC)

Comparación entre las historias del backlog que entrega jefatura (códigos `YC-…`) y lo que el sistema ya hace. El backlog se usa como **lista de verificación**: el Documento Maestro v2 sigue mandando en nombres de tablas y en lo ya construido; lo que falte se agrega sin renombrar ni rehacer lo que funciona.

El backlog llega por partes (un sprint por semana). Cada parte se agrega aquí.

Última actualización: 2026-10-06, al cerrar S12 (sprint 1 del backlog, 13 épicas).

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

## Dudas para quien escribió el backlog

1. Cuál es el octavo rol (hoy hay siete: Administrador, Alumno, Profesor, Soporte, Ventas/CRM, B2B autorizado y Responsable de empresa).
2. Qué es el "mapa de acceso" de YC-USR-004.
3. El texto de las reglas que citan las épicas (`RN-…` y `R-…`): no vinieron con la lista.
4. Video protegido (VID): confirmar si se cambia la decisión de usar YouTube. Hacerlo exige contratar un proveedor de video.
5. Precio por moneda (MON-002): confirmar si se cambia la decisión de una sola moneda por academia.
