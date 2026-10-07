# Revisión de jefatura · Entrega 3: plantilla de certificado propia y consola del super administrador

Fecha: 2026-10-07 · Sin commit al momento de escribir este documento

Tercera y última de las entregas acordadas de la revisión de jefatura. Cubre dos puntos de su lista: **crear su propia plantilla para el certificado** (8) y **la vista del super administrador que se encarga de todas las academias** (9). Quedan fuera, en espera de respuestas, Moodle (10) y el horario por disponibilidad docente (11).

## Qué se construyó

### Plantilla de certificado propia

En **Certificados → Plantilla** hay una sección nueva, "Diseño del certificado".

- **Imagen de fondo propia.** La academia sube su diseño (hecho en Canva, PowerPoint o por su diseñador) como imagen a hoja completa, A4 horizontal. El sistema escribe encima el nombre del alumno, el curso, la fecha, la firma y el código con su QR. Sin fondo propio, se usa el diseño del sistema, como hasta ahora.
- **Dónde va cada cosa:**
  - Texto alineado a la izquierda, al centro o a la derecha.
  - A qué altura empieza el texto (arriba, al medio o más abajo), para dejar libre el encabezado del fondo.
  - Tamaño del nombre: pequeño, mediano o grande.
  - Firma y código QR: cada uno a la izquierda, al centro o a la derecha (no pueden coincidir).
- **Colores:** de títulos y curso, del nombre del alumno y del texto secundario.
- **Qué escribe el sistema encima:** se puede ocultar el logo, el nombre de la academia y la palabra "Certificado" cuando el fondo ya los trae dibujados.
- **Vista previa:** muestra el **PDF real** con datos de ejemplo, dentro de la misma ventana.
- "Valores del sistema" devuelve todo al diseño original; quitar el fondo también.

El diseño vale para todos los certificados que se descarguen desde ese momento, **incluidos los ya emitidos** (el PDF se genera al pedirlo). Lo que no cambia en un certificado emitido son sus datos: nombre, curso, fecha y código.

### Consola del super administrador

En el dominio central (`/platform`), el administrador de plataforma ahora tiene:

- **Cifras de toda la plataforma:** academias (activas y suspendidas, como tarjetas que filtran), usuarios activos, alumnos con matrícula activa y cursos publicados.
- **Cifras por academia** en la tabla: usuarios activos, alumnos, cursos publicados, **ventas del mes** (en la moneda de cada academia) y **último acceso** de alguien de la academia.
- **Buscador** por nombre o subdominio.
- **Nueva academia:** crea la academia con sus roles base y su primer administrador, que entra con una contraseña temporal y debe cambiarla.
- **Entrar en modo soporte:** abre la academia en otra pestaña con lo que ve su administrador, **en solo lectura** y durante **30 minutos**.
  - No se puede cambiar nada: el servidor rechaza cualquier operación que no sea de consulta.
  - Una franja amarilla lo recuerda en todas las pantallas, con un botón para salir.
  - El acceso queda en la **auditoría de la academia** (quién entró de la plataforma, como qué usuario y hasta qué hora), a la vista de su administrador.
  - No sirve para otra academia ni se renueva con el uso.
- Suspender y reactivar, como antes, ahora con una ventana de confirmación.

## Detalle técnico

- Certificados: columnas nuevas `certificate_templates.background_file_id` y `design` (una migración). Uso de archivo nuevo `certificate_background` (privado, imagen, hasta 2 MB). Rutas `POST` y `DELETE /certificates/template/background` y `GET /certificates/template/preview`; `PUT /certificates/template` acepta `design` (solo lo que cambia). La vista `certificates/certificate.blade.php` calcula las posiciones en milímetros.
- Plataforma: `GET /platform/tenants` devuelve las cifras por academia y `meta.totals` (una consulta por cifra, no por academia); `POST /platform/tenants`; `POST /platform/tenants/{id}/support-session`.
- Acceso de soporte: un token de la cuenta del administrador más antiguo de la academia, con el único permiso `support:read` y vencimiento fijo. `EnsureTenantUser` rechaza con `SUPPORT_READ_ONLY` (403) todo lo que no sea `GET` o cerrar sesión. La sesión informa `support_mode`. El token llega a la academia en el fragmento de la dirección (`/support-access#token=…`), que no viaja al servidor, y se borra de la barra al cargar.
- Sin dependencias nuevas, sin permisos nuevos de academia y sin variables de entorno.

## Qué se verificó

- **260 pruebas del backend pasan** (eran 257). Nuevas: el diseño del certificado (valores por defecto, cambio parcial, firma y QR en el mismo sitio, color inválido, vista previa en PDF, PDF de un certificado real con el diseño propio, permisos); las cifras por academia y el alta desde la consola (incluido que el administrador nuevo debe cambiar su contraseña); y el acceso de soporte (lee, no escribe, no entra a otra academia, vence a los 30 minutos aunque se use, queda auditado, y un administrador de academia no puede abrirlo).
- **Los 5 recorridos de Playwright siguen pasando.**
- En el navegador:
  - La consola mostró las tres academias locales con sus cifras (la demo: 24 usuarios, 17 alumnos, 3 cursos, S/ 3 450 del mes).
  - Se abrió un acceso de soporte a la demo: apareció la franja amarilla, la consulta de usuarios respondió y un intento de crear un grupo fue rechazado.
  - En Plantilla se cambió el tamaño del nombre y se vio la vista previa; luego se subió un fondo de prueba, se ocultaron logo y título, se cambiaron los colores, y la vista previa mostró el fondo a hoja completa con el texto encima. Al terminar se quitó el fondo y se volvió a los valores del sistema.

## Qué no se probó

- El botón "Entrar" de la consola abre una pestaña nueva; en el navegador de pruebas se comprobó el mismo recorrido navegando a la dirección, no con la pestaña emergente.
- Un fondo real de un diseñador, con tipografías y márgenes propios, ni fondos de 2 MB a alta resolución.
- La creación de una academia desde la pantalla (sí por la API).
- La impresión del PDF en papel.

## Qué quedó corto

- **No es un editor libre:** las posiciones son predefinidas (tres alineaciones, tres alturas, tres sitios para firma y QR). No se arrastra cada dato a donde uno quiera, ni se elige la tipografía.
- Hay **una sola plantilla por academia**, no una por curso.
- Los textos fijos ("Se otorga a", "por haber completado satisfactoriamente el curso") no se pueden cambiar.
- Con el texto "más abajo" y todo visible (logo, nombre de la academia y título), el bloque puede pisar la firma: la vista previa lo muestra y se corrige ocultando lo que el fondo ya trae.
- El fondo pesa hasta 2 MB; un diseño muy pesado hay que guardarlo como JPG.
- El modo soporte entra **como el administrador** de la academia: ve todo lo que él ve. No hay un modo que oculte datos personales.
- Algunas consultas tienen efectos que el modo soporte no bloquea porque no son escrituras del usuario: por ejemplo, abrir "Mis certificados" emite los pendientes de esa cuenta. Al entrar como administrador, en la práctica no aplica, pero conviene saberlo.
- La consola no tiene todavía: editar los datos de una academia, planes ni límites, facturación a las academias, ni un historial de accesos de soporte en un solo lugar (hoy está en la auditoría de cada academia).
- Las ventas se muestran por academia en su moneda; no hay un total de la plataforma porque puede haber soles y dólares.

## Qué se decidió sin preguntar

1. El diseño propio se aplica también a los certificados ya emitidos (como ya pasaba con el logo y la firma). Congelar el diseño de cada certificado al emitirlo sería otra regla.
2. El acceso de soporte dura 30 minutos fijos y no se renueva.
3. El acceso de soporte usa la cuenta del administrador más antiguo de la academia, y en sus "sesiones abiertas" figura como "Soporte de plataforma: correo de quien entró".
4. La academia creada desde la consola obliga a su administrador a cambiar la contraseña; la que se registra sola en `/register` no, porque ahí la contraseña la pone él mismo.
