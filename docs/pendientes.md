# Pendientes del proyecto

Lista general del proyecto: en qué sprint estamos, qué falta y qué decisiones se necesitan. Se actualiza al cerrar cada bloque de trabajo.

Última actualización: 2026-10-05, al cerrar S8 (empresas y CRM).

## Dónde estamos

**Sprint actual: S8 — Empresas y CRM (B2B), terminado.** Igual que S7, se adelantó a S6, que espera la decisión 8.

**Siguiente paso:** S6 Pagos, si ya está la decisión 8 (pasarela, Yape, facturación y quién cobra). Si no, S10 Operación (soporte, páginas y Libro de Reclamaciones) o S11 Promociones y paquetes; S9 SCORM espera la decisión 9. De S6 se puede adelantar sin proveedor: el registro de transacciones con una pasarela simulada, que el comprador adjunte su comprobante y los datos de facturación.

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
| S6 | Pagos | **Siguiente**, en espera de la decisión 8 |
| S7 · entrega 1 | Certificados: emisión automática, PDF con QR, verificación pública, anulación | Hecho |
| S7 · entrega 2 | Reportes: tablero de indicadores con periodo y comparación | Hecho |
| S8 · entrega 1 | CRM: prospectos, empresas con sus contactos y embudo de oportunidades | Hecho |
| S8 · entrega 2 | Cotizaciones corporativas con versiones; al aceptarse nace la venta | Hecho |
| S8 · entrega 3 | Licencias, asignación a trabajadores y portal del Responsable de empresa | Hecho |
| S9 | SCORM | Pendiente (ver decisión 9) |
| S10 | Operación | Pendiente |
| S11 | Promociones | Pendiente |
| S12–S13 | Endurecimiento y pruebas de aceptación | Pendiente |

- **Avance del plan:** 8 de 14 sprints completos (S0 a S5, S7 y S8). S6 está en espera.
- **Calidad:** 190 pruebas automáticas del backend pasan; la aplicación web compila sin errores.
- **Qué ya se puede hacer de punta a punta:** crear un curso con videos, materiales y actividades, publicarlo, matricular alumnos con o sin fecha de vencimiento, y que estos lo cursen: rinden cuestionarios con nota automática, entregan tareas que el profesor califica, responden encuestas y ven su avance.
- **Venta:** un visitante ve el catálogo público, crea su cuenta, compra un curso y recibe las instrucciones de pago; el personal confirma el pago recibido y el alumno queda matriculado. Los cursos gratuitos matriculan al instante.
- **Certificados:** el curso que lo indique entrega un certificado en PDF, con código y QR, a quien lo completa; cualquiera lo verifica en una página pública; el personal puede emitir a mano y anular.
- **Reportes:** tablero con ingresos, órdenes, matrículas, alumnos activos, cursos completados y certificados de un periodo, comparados con el periodo anterior; cursos más vendidos y finalización por curso.
- **Venta a empresas:** el equipo comercial registra prospectos, lleva cada oportunidad por el embudo y arma la cotización; cuando la empresa la acepta (en línea o por otro medio) nace la venta. Confirmado el pago, la empresa recibe sus licencias y su responsable las asigna a sus trabajadores, que quedan matriculados; desde su portal ve el avance y los certificados.
- **Qué no se puede hacer todavía:** pagar en línea ni emitir comprobantes.
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
| 8 | Pasarela de pagos y facturación | Proveedor de pagos, Yape y comprobantes electrónicos. **Ya urge:** es lo siguiente. Mientras tanto la venta funciona con pago por fuera y confirmación manual. También falta definir la política de reembolsos. Para la venta a empresas se suma: si se factura con RUC e IGV, y si habrá crédito o pagos parciales. | S6 Pagos |
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
- La revisión automática (CI) nunca se ejecutó y no incluye la rama `staging`.
- Configurar HTTPS detrás de proxy y restringir CORS según el servidor.
- Probar la optimización de arranque de Laravel (`config:cache`, `route:cache`), nunca probada con este código.
- Crear el administrador de plataforma y una academia de prueba en el servidor.

## 4. Pendientes del producto

**De lo ya construido**

- Invitar usuarios por correo (hoy el administrador pone la contraseña inicial). Depende del punto 3 de decisiones.
- Mostrar el logo de la academia en la pantalla de inicio de sesión y en los correos.
- Que el administrador pueda cerrar las sesiones de otro usuario.
- Tablas en formato tarjeta para el teléfono (hoy se desplazan de lado).
- Revisar en teléfono el inicio de sesión, el registro y la consola de plataforma.
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
- Revisar en teléfono las pantallas de Matrículas, inicio del alumno y cursos disponibles.

**De venta a personas (S5)**

- Cobro en línea, comprobantes e IGV (S6; depende de la decisión 8).
- Reembolsos y anulación de órdenes pagadas (falta definir la política).
- Avisos por correo: orden creada, pago confirmado y orden por vencer (depende de la decisión 3).
- Que el comprador adjunte su comprobante de pago en la orden.
- Verificar el correo al registrarse y poder desactivar el registro público por academia.
- Volver a comprar un curso con la matrícula vencida (hoy la extensión la hace el personal).
- Que el personal registre una venta a nombre de un alumno.
- Buscador y filtros en el catálogo; cupones, carrito y paquetes (S11); segunda moneda y tipo de cambio.
- Revisar en teléfono el checkout y la confirmación de pago.

**De certificados (S7, entrega 1)**

- Nota mínima para el certificado (depende de definir la nota final del curso).
- Aviso por correo al emitir el certificado (depende de la decisión 3).
- Más de una plantilla o un editor de diseño; plantilla por curso.
- Que el alumno vea desde "Mis cursos" que ya tiene certificado, y que el profesor vea los de sus cursos.
- Emitir de una vez todos los certificados pendientes.
- Pantalla para ver las consultas de verificación registradas.
- Probar el QR con un teléfono cuando haya servidor; revisar en teléfono las pantallas nuevas.

**De reportes (S7, entrega 2)**

- Exportar a Excel o CSV.
- Reportes por curso, por alumno y por vendedor; filtros por curso o categoría.
- Reportes del profesor sobre sus cursos.
- Indicadores de notas, de abandono y de pagos por medio.
- Mostrar las cifras principales en el inicio del administrador.
- Probar con volumen real y en teléfono.

**De CRM (S8, entrega 1)**

- Arrastrar las oportunidades entre etapas y poder configurar las etapas por academia.
- Recordatorios o tareas con fecha (hoy el seguimiento es un historial de lo ya ocurrido).
- Formulario público para que un prospecto se registre desde la web; importar prospectos desde un archivo.
- Eliminar o fusionar empresas duplicadas; buscador en los selectores de empresa (hoy cargan las primeras 100).
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
- Revisar en teléfono las pantallas del CRM, las cotizaciones y el portal de empresa.

**De las pruebas automáticas**

- Una prueba de matrículas de S4 (`EnrollmentValidityTest`) falló una vez en una corrida completa y pasó en todas las repeticiones. Hay que encontrar por qué antes de confiarle el CI.

**De rendir actividades (S3, entrega 3)**

- Recuperar las respuestas ya marcadas al retomar un intento abierto.
- Que quien revisa vea el detalle de cada intento, la nota final del curso y pueda exportar las notas.
- Fechas límite para las tareas (la vigencia de la matrícula ya existe; falta definir cómo se fijan los plazos).
- Revisar en teléfono las pantallas de rendir, entregar y seguimiento.


## 5. Orden propuesto

1. Limpieza del repositorio (sección 2).
2. Definir servidor, dominio y correo (decisiones 1 a 3) y montar staging.
3. Definir pasarela, Yape y facturación (decisión 8) para empezar S6; si demora, seguir con S10 Operación o S11 Promociones y paquetes.
