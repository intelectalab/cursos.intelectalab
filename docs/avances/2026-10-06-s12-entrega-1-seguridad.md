# S12 — Endurecimiento · Entrega 1: seguridad

Fecha: 2026-10-06 · Sin commit al momento de escribir este documento

Primera entrega de S12 (Maestro §14: "Hardening: security/performance/accessibility"; §8.2 Controles; RNF-SEC-001). Refuerza el acceso, protege la API frente a abusos y repeticiones, y deja pruebas que revisan **todas** las rutas y **todas** las tablas, no módulo por módulo. Incluye los cinco puntos del backlog YC que se acordó sumar: AUTH-001, AUTH-003, AUTH-005, TEN-005 y ADM-004 (ver [verificación del backlog](../verificacion-backlog.md)).

## Qué se construyó

### Acceso

- **Bloqueo de la cuenta.** Tras 10 intentos fallidos seguidos, la cuenta no admite inicios de sesión durante 15 minutos, vengan de donde vengan. Se suma al freno que ya existía (5 fallos desde una misma dirección pausan 60 segundos). Un acceso correcto o recuperar la contraseña reinician la cuenta. Los dos valores se cambian por variable de entorno.
- **Registro de accesos.** Cada inicio de sesión correcto, cada intento fallido y cada bloqueo quedan en Auditoría (`auth.login`, `auth.login_failed`, `auth.locked`), con dirección, navegador e identificador de la petición. Nunca se guarda la contraseña intentada.
- **El administrador cierra las sesiones de otra persona.** En Usuarios → editar, botón "Cerrar sesiones". Sirve si alguien perdió un equipo o se sospecha un acceso ajeno. La cuenta sigue activa; solo tiene que volver a entrar. Queda en Auditoría.
- **Aceptación de textos legales.** Quien crea su cuenta en el registro público debe marcar que acepta los términos y la política de privacidad; el formulario enlaza las páginas publicadas de la academia. Se guarda la fecha y hora de la aceptación.

### Protección de la API

- **Cabeceras de seguridad** en todas las respuestas: impiden que el contenido se incruste en otros sitios o se interprete como otro tipo de archivo, y obligan a HTTPS cuando el servidor ya lo usa. Tampoco se anuncia la versión de PHP.
- **Orígenes permitidos (CORS).** Antes cualquier sitio web podía llamar a la API desde un navegador. Ahora solo la propia plataforma: el dominio central y los subdominios de las academias.
- **Tope general de peticiones:** 300 por minuto por usuario (o por dirección, sin sesión). Las rutas públicas y de acceso conservan su tope propio, más bajo.
- **Idempotencia** en la compra y en la confirmación de pago. Si la conexión se corta y la persona reintenta, la operación no se repite: el sistema devuelve lo que ya hizo. La aplicación web envía la clave sola; una integración externa puede enviarla en la cabecera `Idempotency-Key`. Las claves valen 24 horas.

### Feriados

- En Configuración, sección **Feriados**: lista por año, agregar y quitar, y un botón para **cargar los feriados nacionales** del año (los de fecha fija, más Jueves y Viernes Santo).
- El plazo del Libro de Reclamaciones ya no cuenta esos días. Ejemplo: una hoja del lunes 5 de octubre de 2026 con plazo de 15 días hábiles vencía el lunes 26; con el feriado del 8 de octubre vence el martes 27.
- Los plazos de las hojas ya registradas no cambian.

### Dependencias y revisión automática

- Se corrigieron los cuatro avisos de seguridad pendientes: dos en herramientas de compilación del frontend y dos en `league/commonmark` (2.10.1 → 2.10.3). Ninguno exigió un salto de versión mayor. `composer audit` y `npm audit` quedan sin avisos.
- La revisión automática (`.github/workflows/ci.yml`) ahora incluye la rama `staging`, audita dependencias y revisa el estilo solo en las carpetas vigentes. **Sigue sin haberse ejecutado nunca:** correrá cuando se suba el commit.

## Detalle técnico

- `AuthenticateUser`: segundo contador por organización y correo (`login-lock|…`), sin la dirección. Código de error nuevo `ACCOUNT_LOCKED` (429).
- `App\Shared\Http\Middleware\SecurityHeaders` (grupo `api`). Las cabeceras de la aplicación web (HTML, JS, CSS) las debe poner el servidor web: ver la guía de despliegue.
- `config/cors.php`: patrón armado con `TENANCY_CENTRAL_DOMAIN`; orígenes extra en `CORS_ALLOWED_ORIGINS`.
- Proxies de confianza por la variable `TRUSTED_PROXIES` (`config/tenancy.php`, `AppServiceProvider::configureTrustedProxies()`).
- Limitador `api` en `AppServiceProvider` (`throttleApi()` en `bootstrap/app.php`), configurable con `API_RATE_LIMIT`.
- Tabla nueva `idempotency_keys` (con RLS), middleware `idempotent` en `POST /orders` y `POST /orders/{id}/confirm-payment`, comando diario `idempotency:prune`. Solo se guardan las respuestas correctas: un error deja la clave libre para reintentar. Misma clave con otro contenido → `IDEMPOTENCY_KEY_REUSED` (422).
- Tabla nueva `holidays` (con RLS), `BusinessCalendar::addBusinessDays()` y rutas `/settings/holidays` (permisos `tenant_settings.read` y `tenant_settings.update`).
- Columna nueva `users.terms_accepted_at`. La versión aceptada es la publicada en ese momento (`page_versions.published_at`).
- Ruta nueva `DELETE /users/{user}/sessions` (permiso `users.update`).
- Tres migraciones nuevas. No cambió el catálogo de permisos.

## Qué se verificó

- **239 pruebas del backend pasan** al cerrar esta entrega (eran 220). Nuevas:
  - Toda ruta exige sesión salvo las 18 declaradas públicas, y toda ruta con sesión exige un permiso salvo las 6 de la cuenta propia. Al cerrar esta entrega eran 220 rutas. Una ruta nueva sin protección hace fallar la prueba.
  - Toda tabla con `tenant_id` (60) tiene el aislamiento forzado en la base de datos.
  - Cabeceras, CORS (incluido un dominio que solo "termina igual"), tope de peticiones por usuario y que el HTTPS reenviado por un proxy solo se crea si el proxy está declarado.
  - Bloqueo de cuenta desde direcciones distintas, y que no alcanza al mismo correo en otra academia.
  - Idempotencia: repetición, clave reutilizada con otra compra, otra persona con la misma clave, reintento tras un rechazo, pago que no se duplica y limpieza de claves vencidas.
  - Feriados: alta, baja, carga nacional sin duplicar, efecto en el plazo, aislamiento y permisos.
  - Cierre de sesiones ajenas y aceptación obligatoria de los textos legales.
- En el navegador (academia demo): el registro muestra la casilla con el enlace a "Términos y condiciones"; en Configuración se cargaron los 16 feriados de 2026; en Usuarios se cerraron las sesiones de la cuenta de alumno. Las cabeceras y el rechazo de un origen ajeno se comprobaron contra el servidor local.
- Frontend: compila y pasa la revisión de tipos y de estilo.

## Qué no se probó

- El caso de dos peticiones idénticas llegando a la vez (la segunda debe responder "en curso"): está programado, sin prueba automática.
- Las cabeceras con HTTPS real y detrás de un proxy: solo simulado en las pruebas.
- El tope de 300 peticiones por minuto con uso real. Si un aula entera comparte una misma dirección sin iniciar sesión, podría quedarse corto: se ajusta con `API_RATE_LIMIT`.

## Qué quedó corto

- El bloqueo se configura por plataforma, no por academia (el backlog lo pide como regla configurable `R-LOGIN-LOCK`; llegaría con el centro de reglas).
- Un tercero que conozca el correo de alguien puede bloquearle la cuenta 15 minutos a propósito. Es el costo habitual de este control; la persona lo resuelve recuperando su contraseña. Un captcha lo mitigaría (necesita proveedor).
- La idempotencia cubre compra y confirmación de pago. Aceptar una cotización, entregar licencias sin pago y el resto de operaciones siguen con sus protecciones propias.
- Los feriados son por academia y se cargan a mano cada año; los días no laborables que decreta el Gobierno no se incluyen. **La lista de feriados nacionales debe revisarla quien lleve lo legal** (decisión 12).
- No hay doble factor (YC-AUTH-002) ni aviso al usuario cuando su cuenta se bloquea (necesita correo, decisión 3).
