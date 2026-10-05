# Pendientes del proyecto

Lista general del proyecto: en qué sprint estamos, qué falta y qué decisiones se necesitan. Se actualiza al cerrar cada bloque de trabajo.

Última actualización: 2026-10-05, al cerrar la entrega 2 de S3.

## Dónde estamos

**Sprint actual: S3 — Académico (entregas 1 y 2 de 3 terminadas).**

**Siguiente paso:** S3 · entrega 3, rendir cuestionarios, entregar y revisar tareas, encuestas y progreso.

| Sprint | Contenido | Estado |
| --- | --- | --- |
| S0 | Fundación: varias academias aisladas, cada una en su subdominio | Hecho |
| S1 | Identidad: acceso, usuarios, roles y permisos, configuración, auditoría | Hecho |
| — | Vista para teléfono y tableta | Hecho |
| — | Almacenamiento de archivos por academia y logo | Hecho |
| S2 | Cursos: contenido, videos de YouTube, profesores y publicación con versiones | Hecho |
| S3 · entrega 1 | Banco de preguntas, cuestionarios, tareas y encuestas | Hecho |
| S3 · entrega 2 | Matrícula mínima y aula del alumno | Hecho |
| S3 · entrega 3 | Rendir cuestionarios, entregar y revisar tareas, encuestas y progreso | **Siguiente** |
| S4 | Matrículas y portal del alumno | Pendiente |
| S5 | Venta a personas (B2C): catálogo, compra y órdenes | Pendiente |
| S6 | Pagos | Pendiente |
| S7 | Certificados | Pendiente |
| S8 | Empresas y CRM (B2B) | Pendiente |
| S9 | SCORM | Pendiente (ver decisión 9) |
| S10 | Operación | Pendiente |
| S11 | Promociones | Pendiente |
| S12–S13 | Endurecimiento y pruebas de aceptación | Pendiente |

- **Avance del plan:** 3 de 14 sprints completos, más dos de las tres entregas de S3.
- **Calidad:** 122 pruebas automáticas del backend pasan; la aplicación web compila sin errores.
- **Qué no se puede hacer todavía:** un alumno ya entra a sus cursos y ve el contenido, pero aún no puede rendir cuestionarios, entregar tareas ni responder encuestas; llega con la entrega 3 de S3. Tampoco hay compra: la matrícula la hace el personal a mano.
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
| 10 | Vigencia de la matrícula | Cuánto dura el acceso de un alumno a un curso (indefinido, por meses, hasta una fecha). Hoy dura hasta que se cancela. | S4 Matrículas |
| 11 | Curso archivado | Si los alumnos ya matriculados conservan el acceso cuando un curso se archiva. Hoy lo conservan. | Nada por ahora |
| 8 | Pasarela de pagos y facturación | Proveedor de pagos, Yape y comprobantes electrónicos. No urge todavía. | S6 Pagos |
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

**De matrículas y aula (S3, entrega 2)**

- Que el profesor vea a los alumnos de sus cursos.
- Matricular muchos alumnos desde un archivo.
- Avisar al alumno por correo cuando se le matricula (depende de la decisión 3).


## 5. Orden propuesto

1. Limpieza del repositorio (sección 2).
2. Definir servidor, dominio y correo (decisiones 1 a 3) y montar staging.
3. Terminar S3 (entrega 3), en paralelo con lo anterior.
