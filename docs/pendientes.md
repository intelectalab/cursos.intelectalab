# Pendientes del proyecto

Actualizado: 2026-10-05. Lista para revisar con jefatura: qué falta, qué decisiones se necesitan y qué sigue.

## Dónde estamos

- **Hecho:** base del sistema (varias academias, cada una en su subdominio, con sus datos aislados), inicio de sesión, usuarios, roles y permisos, configuración de la academia, auditoría, consola de plataforma y vista para teléfono y tableta.
- **Calidad:** 57 pruebas automáticas del backend pasan; la aplicación web compila sin errores.
- **Avance del plan:** 2 de 14 sprints (S0 y S1). Sigue S2 Cursos.

## 1. Decisiones que necesitamos de jefatura

| # | Tema | Qué hay que decidir | Qué bloquea |
| --- | --- | --- | --- |
| 1 | Servidor de staging | Qué servidor se usa (proveedor, quién lo paga, quién da el acceso). Hoy no existe ninguno. | Que otras personas puedan probar el sistema |
| 2 | Dominio | Un dominio con subdominios comodín (`*.dominio`) y su certificado. Cada academia vive en un subdominio. | Staging y producción |
| 3 | Proveedor de correo | Qué servicio envía los correos. Hoy no se envía ninguno: quedan en un registro interno. | Recuperar contraseña e invitar usuarios |
| 4 | Almacenamiento de archivos | Guardar en el propio servidor (recomendado para empezar) o en un servicio externo. Límites de tamaño por archivo. | Logo de la academia y materiales de cursos (S2) |
| 5 | Videos de los cursos | Se suben al sistema o se enlazan desde un servicio de video. | S2 Cursos |
| 6 | Respaldos | Cada cuánto se respalda la base de datos y dónde se guarda la copia. | Salida a producción |
| 7 | Cuentas de prueba | Quiénes probarán en staging y con qué cuentas y datos. | Pruebas en staging |
| 8 | Pasarela de pagos y facturación | Proveedor de pagos, Yape y comprobantes electrónicos. No urge todavía. | S6 Pagos |

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
- Logo de la academia. Depende del almacenamiento de archivos.
- Que el administrador pueda cerrar las sesiones de otro usuario.
- Tablas en formato tarjeta para el teléfono (hoy se desplazan de lado).
- Revisar en teléfono el inicio de sesión, el registro y la consola de plataforma.
- Tema oscuro (opcional).

**Sprints por construir**

| Sprint | Contenido |
| --- | --- |
| Previo | Almacenamiento de archivos por academia |
| S2 | Cursos: cursos, versiones, secciones, recursos y publicación |
| S3 | Académico |
| S4 | Matrículas |
| S5 | Venta a personas (B2C) |
| S6 | Pagos |
| S7 | Certificados |
| S8 | Empresas y CRM (B2B) |
| S9 | SCORM |
| S10 | Operación |
| S11 | Promociones |
| S12–S13 | Endurecimiento y pruebas de aceptación |

## 5. Orden propuesto

1. Limpieza del repositorio (sección 2).
2. Definir servidor, dominio y correo (decisiones 1 a 3) y montar staging.
3. Almacenamiento de archivos y S2 Cursos, en paralelo con lo anterior.
