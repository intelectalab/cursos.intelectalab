# Planes de la plataforma · Entrega 1: planes y prueba gratuita

Fecha: 2026-10-08 · Sin commit al momento de escribir este documento

Primera de tres entregas para que IntelectaLab cobre a las academias. El modelo, tal como lo describió el responsable del proyecto (pendiente de confirmar con jefatura): **una academia que se registra entra en prueba gratuita; después paga a la plataforma según la cantidad de alumnos que tiene; y sus alumnos le pagan los cursos a la academia.**

Antes de esta entrega el sistema no tenía planes: una academia se registraba gratis, sin límite y sin vencimiento.

Las tres entregas:

1. **Planes y prueba gratuita** (esta).
2. "Mi plan" en la academia, con una orden de pago a IntelectaLab y su confirmación en la consola.
3. Límites y vencimiento: qué pasa al llenar el cupo y al no pagar.

**En esta entrega todavía no se bloquea nada**: se define, se asigna y se muestra. Los límites se hacen cumplir en la entrega 3.

## Qué se construyó

### Planes (consola de plataforma)

La consola tiene ahora dos secciones: **Academias** y **Planes**.

- En Planes, el super administrador crea y edita los planes que ofrece la plataforma: nombre, descripción, precio, moneda (soles o dólares), si se paga cada mes o cada año, y **hasta cuántos alumnos permite** (o sin tope).
- Un plan se puede **archivar**: deja de ofrecerse, pero la academia que lo tiene lo conserva.
- Cada plan muestra cuántas academias lo tienen.

### Prueba gratuita

- Toda academia **nueva** (se registre sola o la cree el super administrador) empieza con una prueba de **14 días** y hasta **10 alumnos**.
- **Cuenta como alumno** cada persona con al menos una matrícula activa. No cuentan las cuentas creadas que nunca se matricularon.
- Las academias **que ya existían** no pasan por la prueba: quedaron "Sin plan", sin tope ni vencimiento, hasta que se les asigne uno.

### La suscripción de cada academia (consola)

- La lista de academias tiene una columna **Plan**: prueba, prueba vencida, el nombre del plan, plan vencido o sin plan, con su cupo ("12 de 50 alumnos").
- La **ficha** de cada academia muestra arriba su situación: plan o prueba, hasta cuándo, el precio, y una barra con los alumnos que usa de su cupo. Si supera el tope, lo avisa.
- Desde la ficha:
  - **Asignar plan:** se elige el plan y, si se quiere, hasta qué fecha queda pagado (vacío: un mes o un año desde hoy, según el plan). También se puede dejar a la academia **sin plan**, es decir, sin límites.
  - **Cambiar fin de la prueba**, mientras siga en prueba.
- Cada cambio queda en el historial de la ficha y en la auditoría de la academia.

Por ahora, asignar el plan es la forma de registrar que la academia pagó. En la entrega 2 la academia podrá generar su orden de pago y el super administrador confirmarla.

### "Tu plan" (dentro de la academia)

- En **Configuración**, la academia ve arriba una tarjeta "Tu plan en IntelectaLab": si está en prueba y cuántos días le quedan, o qué plan tiene y hasta cuándo, y cuántos alumnos usa de su cupo.
- Es solo informativa. La ve quien puede ver la configuración (el Administrador).

## Detalle técnico

- Módulo nuevo `Billing`.
- Una migración (`2026_10_31_000100_create_billing_tables`): tablas `plans` (de la plataforma, sin academia) y `tenant_subscriptions` (una fila por academia, con aislamiento por academia). **La migración crea la suscripción "sin plan" de las academias que ya existen.**
- Archivo de configuración nuevo `config/billing.php`, con dos **variables de entorno opcionales**: `BILLING_TRIAL_DAYS` (14) y `BILLING_TRIAL_MAX_STUDENTS` (10).
- Evento nuevo `TenantProvisioned`: al crearse una academia, Billing le abre la prueba.
- Rutas nuevas:
  - Plataforma: `GET` y `POST /platform/plans`, `PUT /platform/plans/{id}`, `PUT /platform/tenants/{id}/subscription`, `PUT /platform/tenants/{id}/subscription/trial`.
  - Academia: `GET /subscription` (con el permiso de ver la configuración).
- `GET /platform/tenants` y la ficha traen ahora `subscription`.
- Sin dependencias nuevas ni permisos nuevos.

## Qué se verificó

- **278 pruebas del backend pasan** (eran 276).
- Pruebas nuevas (2):
  - Una academia nueva empieza en prueba (14 días, 10 alumnos); una cuenta sin matrícula no cuenta como alumno y una matriculada sí; a los 15 días figura vencida; un alumno no puede consultar el plan.
  - El super administrador crea planes, extiende la prueba, asigna un plan (con y sin fecha), no puede asignar uno archivado, deja a la academia sin plan; cada cambio queda en la auditoría y en el historial; un administrador de academia no llega a los planes.
- La aplicación web compila y pasa la revisión de tipos y de estilo.
- En el navegador, en local:
  - Se crearon dos planes de prueba: "Hasta 50 alumnos" (S/ 99 al mes) y "Hasta 200 alumnos" (S/ 249 al mes).
  - La lista de academias mostró "Sin plan" en las tres que ya existían.
  - En la ficha de "Academia QA Registro" se asignó "Hasta 50 alumnos": la tarjeta pasó de "Sin plan" a "Pagado hasta el 8 de noviembre", la lista mostró "0 de 50 alumnos" y el historial registró el cambio.
  - Dentro de la academia demo, Configuración mostró "Tu plan en IntelectaLab: Sin límites".

Durante la prueba se corrigió un detalle: el historial mostraba la fecha de vencimiento un día después que la tarjeta (por la diferencia de hora con el servidor). Ahora ambas muestran el mismo día.

## Qué no se probó

- En el navegador no se registró una academia nueva para verla en prueba (va por prueba automática), ni se probó "Cambiar fin de la prueba", editar o archivar un plan.
- La vista en teléfono.

## Qué quedó corto

- **Nada se bloquea todavía:** una academia puede pasarse del cupo o seguir con la prueba vencida. Eso es la entrega 3.
- **La academia no puede pagar ni elegir plan** desde su cuenta: es la entrega 2. Hoy la tarjeta le dice que se comunique con IntelectaLab.
- La duración y el tope de la prueba se cambian en la configuración del servidor, no desde la consola.
- Una academia tiene **una** suscripción vigente; no se guarda una lista de periodos pagados (sí queda cada cambio en la auditoría).
- Cambiar el tope de un plan no cambia el de las academias que ya lo tienen hasta que se les vuelva a asignar.
- No hay plan anual "más barato" como regla: se logra creando un plan anual con su precio.
- Sin comprobantes: la factura de IntelectaLab a la academia se emite por fuera.

## Qué se decidió sin preguntar

1. **Las academias existentes quedan "Sin plan"** (sin tope ni vencimiento) hasta que se les asigne uno, como se acordó; se muestra así en la lista.
2. **Los días y el tope de la prueba van en la configuración del servidor**, con 14 días y 10 alumnos por defecto.
3. **Asignar un plan sin fecha cuenta el periodo desde hoy**, no desde el vencimiento anterior.
4. **Un plan archivado se conserva** en quien ya lo tiene.
5. **Planes en soles o en dólares.**
6. **"Tu plan" lo ve quien puede ver la Configuración** de la academia.

Las seis se pueden cambiar. Las ocho decisiones del modelo (qué cuenta como alumno, tramos, pago mensual o anual, 14 días y 10 alumnos de prueba, gracia y solo lectura, comprobante por fuera, confirmación por el super administrador y academias existentes con plan a mano) fueron las recomendadas y aceptadas.

En la base local quedaron los dos planes de prueba y "Academia QA Registro" con el plan "Hasta 50 alumnos".
