# S12 — Endurecimiento · Entrega 4: deuda y recorridos automáticos

Fecha: 2026-10-06 · Sin commit al momento de escribir este documento

Última entrega de S12. Cierra los seis puntos de deuda acordados (sección 4 de [pendientes](../pendientes.md)) y deja cinco recorridos automáticos de punta a punta, que servirán también como prueba de humo en S13.

## Deuda resuelta

### 1. Retomar un cuestionario recupera lo ya marcado

Antes, si el alumno cerraba la pestaña o se le iba la conexión a mitad de un cuestionario, al volver encontraba el intento abierto pero en blanco.

- Mientras responde, lo que marca **se guarda solo** (poco más de un segundo después de cada cambio).
- Al volver ("Continuar el intento"), el cuestionario aparece con sus respuestas.
- Lo guardado no se califica: la nota sigue saliendo solo al pulsar "Enviar respuestas".
- Si falla un guardado, el alumno no se entera ni pierde nada: sus respuestas siguen en pantalla y viajan al enviar.

### 2. El administrador cierra las sesiones de otra persona

Hecho en la entrega 1 (Usuarios → editar → "Cerrar sesiones").

### 3. Logo de la academia en el inicio de sesión

Si la academia subió su logo en Configuración, aparece sobre "Bienvenido de nuevo". Sin logo, la pantalla queda como estaba.

### 4. Se retiraron los campos antiguos de textos legales

En Configuración había dos cajas de texto, "Términos y condiciones" y "Política de privacidad", que nadie mostraba desde que existen las Páginas (S10). Se quitaron; en su lugar hay una nota que lleva a Páginas. Si alguna academia tenía texto guardado ahí, **se conserva en la base de datos** aunque ya no se muestre.

### 5. Buscador en los selectores de empresa

Al crear una oportunidad, una cotización o convertir un prospecto, el selector de empresa cargaba solo las primeras 100. Ahora, cuando hay 100 o más, aparece una caja para **buscar por nombre o RUC** entre todas. Con menos de 100 se ve igual que antes.

### 6. La vigencia de los cupones cuenta en la zona horaria de la academia

Antes, "vigente hasta el 1 de diciembre" dependía de la hora del navegador de quien creó la promoción. Ahora el día empieza y termina **a la medianoche de la academia** (su zona horaria de Configuración), sin importar desde dónde se configure ni dónde esté el servidor.

Las promociones creadas antes de este cambio conservan sus límites tal como se guardaron.

## Recorridos automáticos (Playwright)

Cinco recorridos que abren un navegador real, preparan sus datos por la API y comprueban el resultado en pantalla:

| Recorrido | Qué comprueba |
| --- | --- |
| Acceso | Rechaza una clave incorrecta con un mensaje, entra con la correcta, el título de la pestaña nombra la pantalla y cierra sesión |
| Compra con cupón | Un comprador nuevo aplica un cupón de 50 % en el checkout y la orden queda pendiente de pago con el descuento |
| Cuestionario | El alumno marca una respuesta, recarga la página, retoma el intento con la respuesta puesta, envía y aprueba |
| Certificado | El alumno completa la única lección, el curso llega a 100 % y el certificado aparece en "Mis certificados" |
| Licencia | Cotización aceptada y pagada; el personal asigna una de las dos licencias a un trabajador |

Cómo correrlos, con la web y la API ya levantadas:

```bash
cd frontend
E2E_BASE_URL=http://demo.localhost:5173 E2E_PASSWORD=<clave del administrador> npm run e2e
```

- La contraseña no se guarda en el repositorio: se pasa por la variable `E2E_PASSWORD`.
- Cada corrida crea datos nuevos con el prefijo "E2E" (cursos, que quedan archivados al terminar; cuentas `e2e.…@prueba.test`; una promoción y una empresa). **No deben correrse contra producción.**
- Tardan cerca de un minuto.

El recorrido de acceso encontró un defecto real: el mensaje de error del inicio de sesión no se anunciaba a los lectores de pantalla. Quedó corregido.

## Detalle técnico

- Columna nueva `quiz_attempts.draft_answers`; ruta `PUT /me/attempts/{id}/draft` (permiso `activities.attempt`); `presentOpen` devuelve `answers`. El borrador se borra al enviar.
- `TenantSettingsService`: se quitaron `terms` y `privacy_policy` de las claves editables; `update()` conserva lo que hubiera guardado. Método nuevo `timezone()`.
- Promociones: una fecha sin hora (`AAAA-MM-DD`) se interpreta como día de la academia; la API devuelve además `starts_on` y `ends_on`. Una fecha con hora se sigue aceptando tal cual.
- `CompanySelect` (frontend) reemplaza los tres selectores de empresa del CRM.
- Dependencia nueva, solo de desarrollo: `@playwright/test` 1.63 (más el navegador Chromium que descarga `npx playwright install chromium`).

## Qué se verificó

- **243 pruebas del backend pasan.** Nuevas en esta entrega: recuperar un intento con lo marcado (incluye que otra persona no puede escribir en él y que un intento enviado no admite guardados) y la vigencia de un cupón en el huso de Lima, probada minuto a minuto alrededor de la medianoche.
- **Los 5 recorridos de Playwright pasan** contra la academia demo local.
- Frontend: compila, y pasa la revisión de tipos y de estilo.

## Qué no se probó

- El buscador de empresas con 100 o más empresas reales: la academia demo tiene tres, así que la caja de búsqueda no llegó a mostrarse. La lógica está revisada, no ejercitada en pantalla.
- El logo en el inicio de sesión con logos muy anchos o muy altos.
- Los recorridos solo corrieron en Chromium y en escritorio.

## Qué quedó corto

- Si el tiempo de un cuestionario se acaba con la pestaña cerrada, el intento vence con nota 0 aunque hubiera respuestas guardadas. Calificar lo guardado al vencer es una decisión de regla académica (ver "decidí sin preguntar" en el cierre de S12).
- Los recorridos no están en la revisión automática (CI): necesitan una academia levantada con datos. Se integran cuando haya staging.
- Los datos "E2E" se acumulan en la academia donde se corran; no hay limpieza automática.
