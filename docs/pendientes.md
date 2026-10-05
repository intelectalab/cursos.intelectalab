# Pendientes del proyecto

Lista general del proyecto: en qué sprint estamos, qué falta y qué decisiones se necesitan. Se actualiza al cerrar cada bloque de trabajo.

Última actualización: 2026-10-05, al cerrar S4.

## Dónde estamos

**Sprint actual: S4 — Matrículas y portal del alumno, terminado. Toca empezar S5.**

**Siguiente paso:** S5 — Venta a personas (B2C): catálogo, compra y órdenes. Antes de construir hay que definir su alcance, porque el pago real depende de la decisión 8.

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
| S5 | Venta a personas (B2C): catálogo, compra y órdenes | **Siguiente** |
| S6 | Pagos | Pendiente |
| S7 | Certificados | Pendiente |
| S8 | Empresas y CRM (B2B) | Pendiente |
| S9 | SCORM | Pendiente (ver decisión 9) |
| S10 | Operación | Pendiente |
| S11 | Promociones | Pendiente |
| S12–S13 | Endurecimiento y pruebas de aceptación | Pendiente |

- **Avance del plan:** 5 de 14 sprints completos (S0 a S4).
- **Calidad:** 138 pruebas automáticas del backend pasan; la aplicación web compila sin errores.
- **Qué ya se puede hacer de punta a punta:** crear un curso con videos, materiales y actividades, publicarlo, matricular alumnos con o sin fecha de vencimiento, y que estos lo cursen: rinden cuestionarios con nota automática, entregan tareas que el profesor califica, responden encuestas y ven su avance.
- **Qué no se puede hacer todavía:** comprar un curso (la matrícula la hace el personal a mano; el alumno solo ve la ficha de los cursos disponibles), pagar, ni emitir certificados.
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

- Avisar al alumno por correo cuando se le matricula (depende de la decisión 3).

**De matrículas y portal (S4)**

- Matricular muchos alumnos desde un archivo y exportar la lista de matrículas.
- Avisos por correo: matrícula creada, acceso por vencer y vencido (depende de la decisión 3).
- Que el alumno pueda solicitar una matrícula o una extensión desde el portal.
- Revisar en teléfono las pantallas de Matrículas, inicio del alumno y cursos disponibles.

**De rendir actividades (S3, entrega 3)**

- Recuperar las respuestas ya marcadas al retomar un intento abierto.
- Que quien revisa vea el detalle de cada intento, la nota final del curso y pueda exportar las notas.
- Fechas límite para las tareas (la vigencia de la matrícula ya existe; falta definir cómo se fijan los plazos).
- Revisar en teléfono las pantallas de rendir, entregar y seguimiento.


## 5. Orden propuesto

1. Limpieza del repositorio (sección 2).
2. Definir servidor, dominio y correo (decisiones 1 a 3) y montar staging.
3. S5 Venta a personas, en paralelo con lo anterior.
