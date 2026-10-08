# Pendientes del proyecto

Lista general del proyecto: en qué sprint estamos, qué falta y qué decisiones se necesitan. Se actualiza al cerrar cada bloque de trabajo.

Última actualización: 2026-10-08, al cerrar el bloque "Alumnos y docentes" (entrega 2).

## Dónde estamos

**Revisión de jefatura (12 puntos): las tres entregas acordadas están terminadas.** Nueve puntos hechos, uno a medias ("hacerlo más visual": falta saber si señalaron pantallas concretas) y dos esperan respuestas (Moodle y horarios). Los sprints del plan siguen igual: S12 terminado; S6 Pagos espera la decisión 8, S9 SCORM la decisión 9, y S13 necesita servidor, dominio y correo (decisiones 1 a 3).

**Terminado:** el bloque "Evaluaciones y aula", que sale del sprint 2 del backlog YC (83 historias: 30 hechas, 20 a medias, 30 faltan y 3 resueltas distinto), en tres entregas; y el bloque "Alumnos y docentes", en dos. **Sigue** el buscador con filtros del catálogo público. Jefatura pidió además cobrar con PayPal y Mercado Pago: es el S6 y espera las respuestas de la decisión 8 que se listan abajo. Moodle y el horario por disponibilidad docente esperan respuestas (decisiones 14 y 15). El backlog de historias YC sigue llegando por semana (decisión 13).

| Sprint | Contenido | Estado |
| --- | --- | --- |
| S0 | Fundación: varias academias aisladas, cada una en su subdominio | Hecho |
| S1 | Identidad: acceso, usuarios, roles y permisos, configuración, auditoría | Hecho |
| — | Vista para teléfono y tableta | Hecho |
| — | Almacenamiento de archivos por academia y logo | Hecho |
| S2 | Cursos: contenido, videos de YouTube, profesores y publicación con versiones | Hecho |
| S3 · entrega 1 | Banco de preguntas, cuestionarios, tareas y encuestas | Hecho |
| S3 · entrega 2 | Matrícula mínima y aula del alumno | Hecho |
| S3 · entrega 3 | Rendir cuestionarios, entregar y revisar tareas, encuestas y progreso | Hecho |
| S4 | Matrículas y portal del alumno: vigencia, gestión general, curso completado, inicio del alumno y cursos disponibles | Hecho |
| — | Escalas de notas guardadas por academia | Hecho |
| S5 | Venta a personas (B2C): catálogo público, registro, compra, órdenes y pago confirmado por el personal | Hecho |
| S6 | Pagos | Pendiente, en espera de la decisión 8 |
| S7 · entrega 1 | Certificados: emisión automática, PDF con QR, verificación pública, anulación | Hecho |
| S7 · entrega 2 | Reportes: tablero de indicadores con periodo y comparación | Hecho |
| S8 · entrega 1 | CRM: prospectos, empresas con sus contactos y embudo de oportunidades | Hecho |
| S8 · entrega 2 | Cotizaciones corporativas con versiones; al aceptarse nace la venta | Hecho |
| S8 · entrega 3 | Licencias, asignación a trabajadores y portal del Responsable de empresa | Hecho |
| S9 | SCORM | Pendiente (ver decisión 9) |
| S10 · entrega 1 | Soporte: tickets con mensajes, adjuntos y bandeja del personal | Hecho |
| S10 · entrega 2 | Libro de Reclamaciones: formulario público, código, seguimiento y respuesta | Hecho |
| S10 · entrega 3 | Páginas (CMS): borrador, vista previa, publicación con versiones | Hecho |
| S11 · entrega 1 | Promociones y cupones: descuento por código en el checkout, con límites y registro de usos | Hecho |
| S11 · entrega 2 | Paquetes: varios cursos a un precio; una compra, varias matrículas | Hecho |
| S12 · entrega 1 | Seguridad: bloqueo de cuentas, registro de accesos, cabeceras, orígenes permitidos, tope de peticiones, idempotencia, textos legales al registrarse, feriados | Hecho |
| S12 · entrega 2 | Rendimiento: descarga inicial a la cuarta parte, control de consultas repetidas, índices | Hecho |
| S12 · entrega 3 | Accesibilidad (teclado, etiquetas, contraste, títulos) y revisión en teléfono y tableta | Hecho |
| S12 · entrega 4 | Deuda acordada (6 puntos) y cinco recorridos automáticos de punta a punta | Hecho |
| S13 | Pruebas de aceptación y salida a producción | Pendiente (necesita las decisiones 1 a 3) |
| Revisión de jefatura · entrega 1 | Participantes (alumno o docente por curso), seleccionar todo y acciones sobre varias filas, tarjetas con cifras que filtran, ayuda en los filtros | Hecho |
| Revisión de jefatura · entrega 2 | Grupos de personas e importaciones desde Excel con plantilla (personas con matrícula y grupo, preguntas, prospectos); cambio obligatorio de la contraseña temporal | Hecho |
| Revisión de jefatura · entrega 3 | Plantilla de certificado propia (fondo, posiciones, colores, vista previa) y consola del super administrador (cifras, alta de academias, acceso de soporte en solo lectura) | Hecho |
| Evaluaciones y aula · entrega 1 | Plazo de las tareas con entrega tardía, devolver una tarea para rehacerla, intentos adicionales por alumno y detalle de cada intento para quien revisa | Hecho |
| Evaluaciones y aula · entrega 2 | Nota final del curso (promedio según el peso de cada cuestionario y tarea), libro de notas con descarga en Excel y "Mis notas" del alumno | Hecho |
| Evaluaciones y aula · entrega 3 | Avance secuencial por secciones (interruptor por curso) y vista previa del aula para el personal, de lo publicado o del borrador | Hecho |
| Alumnos y docentes · entrega 1 | Pantalla "Alumnos" (tarjetas que filtran, buscador, alta) y ficha Alumno 360 con cursos, compras, certificados y consultas | Hecho |
| Alumnos y docentes · entrega 2 | Pantalla "Docentes" (tarjetas que filtran, buscador, alta) y ficha del docente con sus cursos, alumnos y carga de calificación | Hecho |
| Catálogo público | Buscador y filtros | Pendiente |

- **Avance del plan:** 11 de 14 sprints completos (S0 a S5, S7, S8 y S10 a S12). Faltan S6 y S9, en espera de decisiones, y S13.
- **Calidad:** 271 pruebas automáticas del backend pasan, más 5 recorridos automáticos en navegador (acceso, compra con cupón, cuestionario, certificado y licencia); la aplicación web compila sin errores y las dependencias no tienen avisos de seguridad.
- **Seguridad:** las pruebas revisan que las 259 rutas de la API exijan sesión y permiso (salvo las declaradas públicas) y que las 63 tablas con datos de academias tengan el aislamiento forzado en la base de datos.
- **Qué ya se puede hacer de punta a punta:** crear un curso con videos, materiales y actividades, publicarlo, matricular alumnos con o sin fecha de vencimiento, y que estos lo cursen: rinden cuestionarios con nota automática, entregan tareas que el profesor califica, responden encuestas y ven su avance.
- **Venta:** un visitante ve el catálogo público, crea su cuenta, compra un curso y recibe las instrucciones de pago; el personal confirma el pago recibido y el alumno queda matriculado. Los cursos gratuitos matriculan al instante.
- **Certificados:** el curso que lo indique entrega un certificado en PDF, con código y QR, a quien lo completa; cualquiera lo verifica en una página pública; el personal puede emitir a mano y anular.
- **Reportes:** tablero con ingresos, órdenes, matrículas, alumnos activos, cursos completados y certificados de un periodo, comparados con el periodo anterior; cursos más vendidos y finalización por curso.
- **Venta a empresas:** el equipo comercial registra prospectos, lleva cada oportunidad por el embudo y arma la cotización; cuando la empresa la acepta (en línea o por otro medio) nace la venta. Confirmado el pago, la empresa recibe sus licencias y su responsable las asigna a sus trabajadores, que quedan matriculados; desde su portal ve el avance y los certificados.
- **Operación:** quien usa la academia pide ayuda desde su cuenta y el personal la atiende en una bandeja de soporte; cualquier consumidor registra un reclamo o queja en el Libro de Reclamaciones público y consulta su respuesta con un código; y la academia publica sus páginas de texto (términos, privacidad, preguntas frecuentes).
- **Promociones:** la academia crea cupones de descuento (porcentaje o monto, con vigencia, tope de usos y compra mínima) que el comprador aplica al pagar, y arma paquetes de cursos a un precio especial. Los descuentos no se acumulan: un cupón por compra.
- **Qué no se puede hacer todavía:** pagar en línea, emitir comprobantes ni enviar correos.
- **Staging:** no hay servidor todavía (decisión 1), así que nadie fuera del equipo puede probar el sistema.

El detalle de cada bloque terminado está en [avances/](avances/README.md).

## 1. Decisiones que necesitamos de jefatura

| # | Tema | Qué hay que decidir | Qué bloquea |
| --- | --- | --- | --- |
| 1 | Servidor de staging | Será un servidor "Elastic" (falta precisar el servicio exacto). Aún no hay acceso: falta que lo entreguen. | Que otras personas puedan probar el sistema |
| 2 | Dominio | Un dominio con subdominios comodín (`*.dominio`) y su certificado. Cada academia vive en un subdominio. | Staging y producción |
| 3 | Proveedor de correo | Qué servicio envía los correos. Hoy no se envía ninguno: quedan en un registro interno. | Recuperar contraseña e invitar usuarios |
| 4 | Almacenamiento de archivos | Ya funciona guardando en el propio servidor (2 MB por imagen, 50 MB por documento). Falta confirmar si se queda así o se pasa a un servicio externo; el cambio es de configuración. | Nada por ahora |
| 5 | Videos de los cursos | Resuelto: se alojan en YouTube y ya se pueden agregar a los cursos. A tener presente: un video «No listado» lo ve cualquiera con el enlace, aunque no esté matriculado. | Nada |
| 6 | Respaldos | Cada cuánto se respalda la base de datos y dónde se guarda la copia. | Salida a producción |
| 7 | Cuentas de prueba | Quiénes probarán en staging y con qué cuentas y datos. | Pruebas en staging |
| 10 | Vigencia de la matrícula | Resuelto: cada curso define sus "días de acceso" (vacío = no vence) y el personal ajusta la vigencia de cada matrícula. Falta que cada academia decida qué valor pone a sus cursos. | Nada |
| 11 | Curso archivado | Resuelto: quienes ya estaban matriculados conservan el acceso; solo se bloquean las matrículas nuevas. | Nada |
| 8 | Pasarela de pagos y facturación | **Respuesta del 2026-10-08: cobrar con PayPal y Mercado Pago.** Falta precisar: a nombre de quién se cobra (una cuenta por academia o una de IntelectaLab); las credenciales de prueba de ambas; qué se hace con la moneda (PayPal no cobra en soles); si Yape entra por Mercado Pago; y si el carrito se hace junto con esto. Sigue abierto: comprobantes electrónicos. Mientras tanto la venta funciona con pago por fuera y confirmación manual. También falta definir la política de reembolsos. Para la venta a empresas se suma: si se factura con RUC e IGV, y si habrá crédito o pagos parciales. | S6 Pagos |
| 12 | Libro de Reclamaciones | Que jefatura o quien lleve lo legal confirme el formato de la hoja y el plazo de respuesta. Se armó con el formato habitual del INDECOPI y un plazo configurable (15 días hábiles por defecto), sin revisión legal. Desde S12 el plazo descuenta los feriados que la academia registre, y hay un botón que carga los nacionales del año: **esa lista también debe revisarla quien lleve lo legal.** | Salida a producción |
| 13 | Backlog de historias YC | Jefatura entrega un backlog por partes (un sprint por semana). Los sprints 1 y 2 ya están comparados con el sistema: del sprint 1, 62 historias (18 faltan y 19 a medias); del sprint 2, 83 historias (34 faltan, 16 de ellas de pagos y facturación, y 25 a medias). Del sprint 2 solo llegaron los títulos, sin criterios de aceptación. Hay que decidir **qué se construye primero** (doble factor para el personal, correos con plantillas, campana de avisos, límites por plan, centro de reglas, reseñas y anuncios en los cursos…) y responder cinco dudas, entre ellas si se cambia la decisión de usar YouTube por un video protegido. Ver [verificacion-backlog.md](verificacion-backlog.md). | El orden del trabajo que viene |
| 14 | Sincronizar con Moodle | Jefatura lo quiere en los dos sentidos. Para estimarlo y poder probarlo hace falta: qué versión de Moodle tienen; un acceso de administrador (o un Moodle de prueba) para activar sus servicios web y generar una clave; qué se sincroniza (cursos, usuarios, matrículas, notas, avance) y cuál de los dos sistemas manda cuando un dato difiere; y cada cuánto. | Sincronización con Moodle |
| 15 | Horario por disponibilidad docente | Se pide generar un horario a partir de la disponibilidad que registre cada docente. Hoy los cursos son grabados y no tienen horarios. Hay que definir: horario de qué (clases en vivo, asesorías, sesiones de un curso); si se dictan por videollamada y con qué herramienta; duración y frecuencia de las sesiones; si el horario lo arma el sistema solo o propone y una persona confirma; y qué pasa con los alumnos (se inscriben a un horario o se les asigna). | Módulo de horarios |
| 9 | SCORM | Si la academia ya tiene cursos en SCORM o clientes que los pidan, y en qué versiones. Si no hay ninguno, S9 se puede posponer. | S9 SCORM |

## 2. Limpieza del repositorio

| Elemento | Situación |
| --- | --- |
| `backend_env_backup` | Copia antigua de la configuración, subida al repositorio con una clave de la aplicación. Hay que retirarla y generar una clave nueva para cualquier servidor. |
| `backend/app/Http/Controllers/Api/AuthController.php` | Controlador antiguo sin uso. Hace fallar la revisión automática de estilo. |
| Restos de plantillas en el frontend | `src/assets` (3 imágenes), `public/icons.svg` y `tailwind.config.js`: nada los usa. |
| Esquema `erp_legacy` en la base local | Las 58 tablas del diseño anterior. Ya no se usan; borrarlas no se puede deshacer. |
| Página de bienvenida de Laravel | Pantalla y archivos de ejemplo del backend que el sistema no usa. |
| README de `backend/` y `frontend/` | Son los textos por defecto de las plantillas; conviene reemplazarlos. |

## 3. Preparación técnica para staging

- Llevar el código a la rama `staging` (hoy está en el commit inicial).
- La revisión automática (CI) se corrigió en S12 pero nunca se ejecutó: correrá al subir el commit.
- Dar valor a `TRUSTED_PROXIES` según el servidor (HTTPS detrás de proxy) y comprobarlo ahí. Los orígenes permitidos (CORS) ya quedaron limitados a la propia plataforma.
- Poner en el servidor web las cabeceras de seguridad de la aplicación web y validar la política de contenido con los videos de YouTube y el editor de páginas (el ejemplo de la guía de despliegue no está probado).
- Repetir en el servidor la optimización de arranque de Laravel (`config:cache`, `route:cache`), que ya se probó en desarrollo.
- Crear el administrador de plataforma y una academia de prueba en el servidor.
- Correr los cinco recorridos automáticos contra staging y medir tiempos con volumen real.

## 4. Pendientes del producto

**De lo ya construido**

- Invitar usuarios por correo (hoy el administrador pone la contraseña inicial). Depende del punto 3 de decisiones.
- Mostrar el logo de la academia en los correos (en el inicio de sesión ya aparece).
- Tablas del personal en formato tarjeta para el teléfono (hoy se desplazan de lado; las pantallas del alumno ya usan tarjetas).
- Tema oscuro (opcional).

**De Cursos (S2)**

- Ver el contenido de una versión anterior y volver a ella.
- Mover recursos entre secciones y eliminar cursos creados por error.

**De Académico (S3, entrega 1)**

- Pregunta de respuesta abierta, con revisión manual.
- Preguntas al azar tomadas de un banco e importación de preguntas desde un archivo.
- Intercalar actividades y recursos dentro de una sección.
- Escalas de notas no numéricas (letras, conceptos, aprobado / desaprobado) o distintas por curso. Hoy la academia guarda sus propias escalas numéricas (nombre y nota máxima) y elige cuál usar.

**De matrículas y aula (S3, entrega 2)**

- Avisar al alumno por correo cuando se le matricula (depende de la decisión 3).

**De matrículas y portal (S4)**

- Matricular muchos alumnos desde un archivo y exportar la lista de matrículas.
- Avisos por correo: matrícula creada, acceso por vencer y vencido (depende de la decisión 3).
- Que el alumno pueda solicitar una matrícula o una extensión desde el portal.

**De venta a personas (S5)**

- Cobro en línea, comprobantes e IGV (S6; depende de la decisión 8).
- Reembolsos y anulación de órdenes pagadas (falta definir la política).
- Avisos por correo: orden creada, pago confirmado y orden por vencer (depende de la decisión 3).
- Que el comprador adjunte su comprobante de pago en la orden.
- Verificar el correo al registrarse y poder desactivar el registro público por academia.
- Volver a comprar un curso con la matrícula vencida (hoy la extensión la hace el personal).
- Que el personal registre una venta a nombre de un alumno.
- Buscador y filtros en el catálogo; cupones, carrito y paquetes (S11); segunda moneda y tipo de cambio.

**De certificados (S7, entrega 1)**

- Nota mínima para el certificado (la nota final del curso ya existe; falta la nota mínima).
- Aviso por correo al emitir el certificado (depende de la decisión 3).
- Más de una plantilla o un editor de diseño; plantilla por curso.
- Que el alumno vea desde "Mis cursos" que ya tiene certificado, y que el profesor vea los de sus cursos.
- Emitir de una vez todos los certificados pendientes.
- Pantalla para ver las consultas de verificación registradas.
- Probar el QR con un teléfono cuando haya servidor.

**De reportes (S7, entrega 2)**

- Exportar a Excel o CSV.
- Reportes por curso, por alumno y por vendedor; filtros por curso o categoría.
- Reportes del profesor sobre sus cursos.
- Indicadores de notas, de abandono y de pagos por medio.
- Mostrar las cifras principales en el inicio del administrador.
- Probar con volumen real.

**De CRM (S8, entrega 1)**

- Arrastrar las oportunidades entre etapas y poder configurar las etapas por academia.
- Recordatorios o tareas con fecha (hoy el seguimiento es un historial de lo ya ocurrido).
- Formulario público para que un prospecto se registre desde la web; importar prospectos desde un archivo.
- Eliminar o fusionar empresas duplicadas.
- Cifras del CRM y de la venta a empresas en Reportes.

**De cotizaciones (S8, entrega 2)**

- PDF de la cotización y envío por correo (depende de la decisión 3). Hoy "marcar como enviada" solo registra que el vendedor ya la hizo llegar.
- Impuestos, descuento global y segunda moneda (depende de la decisión 8).
- Cotizar paquetes de cursos (S11).
- Anular un borrador; filtro en Ventas y pagos para separar ventas a personas y a empresas.

**De licencias y portal de empresa (S8, entrega 3)**

- Registrar trabajadores en bloque desde un archivo.
- Avisar por correo al trabajador que tiene un curso asignado y enviarle su acceso (depende de la decisión 3).
- Que el responsable descargue los certificados de sus trabajadores.
- Quitar a una persona de la empresa o cambiar quién es responsable desde la pantalla.
- Renovar o extender licencias vencidas; límite de crédito por empresa para la entrega sin pago.
- Comprobantes y facturas en el portal de la empresa (S6; depende de la decisión 8).
- Revisar en teléfono el portal del Responsable de empresa y el detalle de una cuenta corporativa (el CRM y las cotizaciones ya se revisaron).

**De las pruebas automáticas**

- Resuelto en S10: la prueba de matrículas que falló una vez en S8 buscaba "ana" entre alumnos con nombres al azar, y un nombre como "Mariana" se colaba. Ahora usa un nombre fijo. No volvió a fallar, pero conviene revisar si otras pruebas buscan por texto entre datos al azar.
- Los recorridos automáticos en navegador (Playwright) no están en la revisión automática: necesitan una academia levantada. Se integran cuando haya staging.

**De promociones y cupones (S11, entrega 1)**

- Promociones automáticas sin código (por fecha o por curso) y, si jefatura lo pide, acumulación de descuentos con su orden y su tope.
- Cupones de un solo uso generados en lote o ligados a una persona.
- Cupones en las cotizaciones a empresas.
- Descuentos y ventas por cupón en Reportes.

**De paquetes (S11, entrega 2)**

- Carrito con varios productos en una orden.
- Mostrar los paquetes dentro de "Cursos disponibles" para quien ya inició sesión.
- Portada y descripción larga del paquete.
- Paquetes en cotizaciones y licencias para empresas.
- Ventas por paquete en Reportes.

**De soporte (S10, entrega 1)**

- Avisar por correo de cada respuesta (depende de la decisión 3) y mostrar en el menú cuántos tickets esperan.
- Tiempos de respuesta comprometidos y alertas por demora (falta definirlos).
- Que el personal abra un ticket a nombre de un usuario; respuestas predefinidas; encuesta de satisfacción.
- Que el responsable de empresa vea las consultas de sus trabajadores.
- Reportes de soporte.

**Del Libro de Reclamaciones (S10, entrega 2)**

- Confirmación legal del formato y del plazo (decisión 12).
- Enviar por correo la constancia y el aviso de la respuesta (depende de la decisión 3).
- Alertas cuando una hoja está por vencer.
- Feriados: se cargan a mano cada año y no incluyen los días no laborables que decreta el Gobierno.
- Captcha en el formulario; adjuntar pruebas; constancia en PDF propio.
- Reporte exportable de las hojas.

**De páginas (S10, entrega 3)**

- Ver una versión anterior y volver a ella.
- Biblioteca de imágenes; tablas y videos en el contenido; descripción para buscadores.
- Editar la portada y el menú del sitio público.
- Revisar en teléfono el editor de páginas por dentro.

**De evaluaciones y aula (entrega 1, 2026-10-08)**

- Fecha límite de calendario para cursos con grupo fijo, y prórroga del plazo por alumno.
- Descuento automático de nota por entrega tardía (hoy solo se marca).
- Historial de las entregas anteriores cuando una tarea se devuelve.
- Retirar un intento adicional ya dado.
- Avisar por correo de la devolución y del intento adicional (depende de la decisión 3).

**De docentes (2026-10-08)**

- Valoraciones del docente (dependen de las reseñas de cursos).
- Repartir la carga de calificación cuando un curso tiene varios docentes; tiempo de respuesta y alertas por demora.
- Asignar cursos desde la ficha del docente.

**De alumnos y la ficha Alumno 360 (2026-10-08)**

- Actuar desde la ficha: matricular, editar la cuenta, responder una consulta; y enlazar cada compra, certificado y consulta a su detalle.
- Limitar la ficha por bloques según el rol (por ejemplo, que Ventas no vea las consultas).
- Rol Finanzas, que el backlog menciona y no existe.
- Exportar la lista de alumnos a Excel y ordenar por otras columnas.
- Medir el listado con miles de alumnos.

**Del avance secuencial y la vista previa (entrega 3, 2026-10-08)**

- Regla para el alumno que agota los intentos de un cuestionario sin aprobar y queda sin poder avanzar (hoy lo resuelve el profesor con un intento adicional).
- Liberar una sección a un alumno en particular; orden elemento por elemento.
- Probar un cuestionario desde la vista previa, como lo rinde el alumno.
- Anuncios y requisitos del certificado en la portada del aula.

**De la nota final y el libro de notas (entrega 2, 2026-10-08)**

- Nota mínima para aprobar el curso, y usarla como requisito del certificado.
- Ver la nota solo de lo ya calificado, además de la que cuenta lo pendiente como cero.
- Categorías de peso (por ejemplo, tareas 40 % y exámenes 60 %).
- Ajustar a mano la nota final de un alumno, con observación.
- Mostrar la nota final en "Mis cursos", en Reportes y en el portal de la empresa.

**De rendir actividades (S3, entrega 3)**

- Si el tiempo de un cuestionario se acaba con la pestaña cerrada, el intento vence con nota 0 aunque hubiera respuestas guardadas: falta decidir si se califica lo guardado.


**De endurecimiento (S12)**

- Doble factor de autenticación para el personal (lo pide el backlog YC).
- El bloqueo por intentos fallidos se configura para toda la plataforma, no por academia. Quien conozca el correo de alguien puede bloquearle la cuenta 15 minutos; un captcha lo evitaría (necesita proveedor).
- Avisar por correo a la persona cuando su cuenta se bloquea o alguien cierra sus sesiones (depende de la decisión 3).
- Idempotencia en el resto de operaciones con efecto (hoy cubre la compra y la confirmación de pago).
- Medir tiempos con miles de registros y en el servidor definitivo; definir el compromiso formal de tiempos y disponibilidad.
- Prueba de penetración antes de producción (la pide el Documento Maestro; alcance sin definir).
- Monitoreo con tablero y alertas (depende del servidor).
- Probar con un lector de pantalla real, en Safari y Firefox, y mirar las pantallas en un teléfono de verdad: la revisión se hizo por medidas, sin capturas.
- Pruebas automáticas de accesibilidad (necesitan una dependencia nueva).
- Redimensionar las imágenes subidas (portadas, logos).
- Limpiar los datos "E2E" que dejan los recorridos automáticos en la academia donde se corren.

**De grupos e importaciones (revisión de jefatura, entrega 2)**

- Avisar a la persona importada que tiene una cuenta y cómo entrar (depende de la decisión 3: hoy lo comunica la academia).
- Importar en más de un curso por fila; importar docentes u otros roles.
- Importar preguntas de emparejar y ordenar; importar empresas, cursos o cupones si lo piden.
- Exportar los listados a Excel.
- Que quien se suma a un grupo quede matriculado en los cursos donde el grupo ya entró.
- Probar una importación de 500 filas; si demora, pasarla a un proceso en segundo plano.
- Acciones sobre varias filas en Cursos, Ventas y CRM; "seleccionar todo" sobre todos los resultados y no solo la página.
- Tarjetas que filtran en Soporte, Reclamos, Promociones y Páginas.

**De la plantilla de certificado y la consola de plataforma (revisión de jefatura, entrega 3)**

- Editor libre de la plantilla (arrastrar cada dato), tipografías y textos fijos editables; una plantilla por curso.
- Decidir si el diseño debe congelarse al emitir cada certificado (hoy un cambio de diseño alcanza a los ya emitidos).
- Consola: editar los datos de una academia, planes y límites, y un historial único de accesos de soporte.
- Modo soporte que oculte datos personales.

**Del backlog de historias YC**

- Lo que falta de cada historia está en [verificacion-backlog.md](verificacion-backlog.md). No se repite aquí.

## 4 bis. Revisión de jefatura (12 puntos)

Lista que jefatura envió el 2026-10-07 tras revisar el sistema.

| # | Punto | Estado |
| --- | --- | --- |
| 1 | Opción de seleccionar todo | Hecho (entrega 1). La selección es por página. |
| 2 | Importar con plantillas en los módulos necesarios | Hecho (entrega 2): personas, preguntas y prospectos, con Excel descargable y revisión previa. Falta saber si piden otros módulos (empresas, cursos, cupones). |
| 3 | Importar usuarios y matricularlos a la vez | Hecho (entrega 2). Contraseña inicial = número de documento, con cambio obligatorio al primer ingreso. Cada fila matricula en un curso. |
| 4 | Matricular por grupos | Hecho (entrega 2). Un grupo es una lista de personas con nombre; se matricula completo desde el curso. Quien se suma después al grupo no se matricula solo. |
| 5 | Tarjetas con cifras que filtran al tocarlas | Hecho (entrega 1) en nueve pantallas. Faltan Soporte, Reclamos, Promociones y Páginas. |
| 6 | Hacerlo más visual | Parcial: las tarjetas. Falta saber si señalaron pantallas concretas. |
| 7 | Qué significa cada filtro | Hecho (entrega 1): ayuda en cada tarjeta y en cada filtro de esas pantallas. |
| 8 | Plantilla propia para el certificado | Hecho (entrega 3): fondo propio, posiciones y colores a elegir, con vista previa. No es un editor libre de arrastrar; una plantilla por academia. |
| 9 | Vista del super administrador de todas las academias | Hecho (entrega 3): cifras de la plataforma y por academia, alta de academias y acceso de soporte en solo lectura, auditado. |
| 10 | Sincronizar con Moodle | En espera de la decisión 14. |
| 11 | Horario según la disponibilidad de los docentes | En espera de la decisión 15. Es un módulo nuevo. |
| 12 | Alumnos y docentes son participantes | Hecho (entrega 1): una sola pestaña y un papel por curso. |

## 5. Orden propuesto

1. Commit de las cinco entregas del 2026-10-08; limpieza del repositorio (sección 2).
2. Buscador con filtros del catálogo público. Los criterios de aceptación del backlog YC se pedirán después.
2. Responder lo que falta de la decisión 8 para empezar los cobros con PayPal y Mercado Pago (S6).
3. Responder las decisiones 14 (Moodle) y 15 (horarios) para poder estimarlas.
4. Definir servidor, dominio y correo (decisiones 1 a 3) y montar staging: con eso se puede hacer S13.
5. Definir pasarela, Yape y facturación (decisión 8) para S6, y si hace falta SCORM (decisión 9) para S9.
6. Seguir comparando el backlog YC (decisión 13) y hacer revisar lo legal (decisión 12).
