# Consola del super administrador: ficha por academia

Fecha: 2026-10-08 · Sin commit al momento de escribir este documento

La consola de plataforma tenía una sola pantalla, la lista de academias. Ahora cada academia tiene su ficha, a la que se entra tocando su nombre o el botón "Ficha". Amplía el punto 9 de la revisión de jefatura (vista del super administrador de todas las academias).

## Qué se construyó

### La ficha

- **Cabecera:** nombre, estado (activa o suspendida), su dirección, fecha de alta y último acceso de alguien de la academia. Desde ahí se entra en modo soporte, se suspende o se reactiva.
- **Cifras** (solo conteos, ningún dato de sus alumnos):
  - Usuarios activos, alumnos con matrícula activa, cursos publicados y ventas del mes.
  - Matrículas nuevas del mes, certificados vigentes y espacio que ocupan sus archivos.
- **Ventas de los últimos seis meses**, mes a mes, con el importe y el número de órdenes. Son órdenes pagadas, en la moneda de la academia, igual que en sus propios reportes.
- **Datos de la academia, editables:** nombre, razón social, RUC, y correo, teléfono y dirección de contacto. La moneda y la zona horaria se muestran, pero las sigue definiendo la academia. **El subdominio no se puede cambiar**: rompería los enlaces y los QR que la academia ya compartió.
- **Administradores:** quiénes administran la academia, con su correo, último acceso y estado de la cuenta. Sirve para saber a quién escribirle.
- **Historial de la plataforma:** en un solo lugar, cada acceso de soporte, cada suspensión y reactivación, cada corrección de datos y cada contraseña restablecida, con fecha y quién de la plataforma lo hizo.

### Restablecer la contraseña de un administrador

- Junto a cada administrador hay un botón **"Restablecer contraseña"**. Se le pone una contraseña temporal.
- Al hacerlo se **cierran sus sesiones abiertas** y queda **obligado a cambiarla** al entrar.
- Solo vale para administradores de esa academia: de cualquier otra cuenta se ocupa su propio administrador.
- Es la salida para el caso "el único administrador perdió su acceso", que hoy no tiene otra porque no hay correo de recuperación.

### Suspender pide un motivo

- Suspender una academia ahora exige escribir **por qué**. El motivo queda en el historial.
- Vale tanto desde la lista como desde la ficha. Reactivar no pide motivo.

Todo lo que la plataforma hace sobre una academia queda en **la auditoría de esa academia**, a la vista de su administrador.

## Detalle técnico

- Sin migraciones: todo se lee de tablas que ya existían.
- Rutas nuevas, solo en el dominio central y solo para el administrador de plataforma:
  - `GET /platform/tenants/{id}` (la ficha)
  - `PUT /platform/tenants/{id}` (datos)
  - `POST /platform/tenants/{id}/admins/{user}/reset-password`
- `PATCH /platform/tenants/{id}/status` ahora exige `reason` cuando el estado es `SUSPENDED`. **Cambia el contrato de esa ruta.**
- Acciones nuevas en la auditoría: `platform.tenant_updated` y `platform.admin_password_reset`.
- La edición solo toca los datos de contacto dentro de la configuración de la academia; el resto de su configuración queda como estaba.
- En la aplicación web: pantalla en `/platform/tenants/:id`; las llamadas de la consola pasaron a `features/platform/api.ts`.
- Sin dependencias nuevas, sin permisos de academia ni variables de entorno.

## Qué se verificó

- **276 pruebas del backend pasan** (eran 275).
- Prueba nueva: la ficha (cifras, seis meses de ventas, solo administradores en la lista); la edición de datos (el subdominio enviado se ignora, la academia ve el cambio y conserva su escala de notas); suspender sin motivo se rechaza y con motivo queda en el historial; restablecer la contraseña (cierra la sesión abierta, la contraseña anterior deja de servir, la nueva obliga a cambiarla, queda auditado, no sirve para un alumno ni para un administrador de otra academia); y que un administrador de academia no llega a la ficha.
- La prueba anterior de suspensión se actualizó para enviar el motivo.
- La aplicación web compila y pasa la revisión de tipos y de estilo.
- En el navegador, con la cuenta de plataforma en local:
  - La lista muestra el botón "Ficha" y el nombre lleva a la ficha.
  - La ficha de la academia demo mostró sus cifras (29 usuarios, 21 alumnos, 3 cursos, S/ 3,700 del mes, 24 matrículas nuevas, 6 certificados, 241 KB), los seis meses de ventas, su administrador y los dos accesos de soporte del 7 de octubre.
  - Se abrieron y cancelaron los modales de suspender (pide motivo) y de restablecer contraseña.
  - En la academia local de pruebas "Academia QA Registro" se guardó un teléfono de contacto: salió "Datos guardados" y el historial mostró "Datos corregidos · Cambió: teléfono".

## Qué no se probó

- En el navegador no se restableció una contraseña ni se suspendió una academia de verdad, para no dejar sin acceso a las cuentas locales. Ambas cosas van por prueba automática.
- "Entrar en modo soporte" desde la ficha (abre otra pestaña; en la lista ya estaba probado).
- La vista en teléfono.

## Qué quedó corto

- No se puede **crear, desactivar ni cambiar** administradores de una academia desde la ficha: solo restablecer la contraseña de los que hay.
- Si la academia no tiene ningún administrador activo, la ficha lo muestra pero no ofrece crear uno.
- Sin **planes ni límites** por academia, ni cobro a las academias: esperan que jefatura defina los planes.
- Las ventas se agrupan por mes en la hora del servidor, no en la zona horaria de cada academia: una venta de la última hora del mes puede caer en el mes vecino.
- El historial muestra los últimos 50 movimientos, sin filtros.
- No se avisa por correo a la academia de estos cambios (depende del proveedor de correo): solo quedan en su auditoría.
- Sigue habiendo una sola cuenta de administrador de plataforma; no se gestionan otras desde la consola.

## Qué se decidió sin preguntar

1. **La contraseña temporal la escribe el super administrador** (no se genera sola), como en el alta de una academia.
2. **Restablecer cierra las sesiones abiertas** de esa cuenta.
3. **Reactivar no pide motivo.**
4. **El historial reúne cuatro tipos de movimiento** de la plataforma; el resto de la auditoría de la academia no se muestra aquí.

Las cuatro se pueden cambiar. Las seis decisiones de alcance (no cambiar el subdominio, restablecer contraseñas, motivo al suspender, solo ventas pagadas, sin datos de alumnos y sin aviso por correo) fueron las recomendadas y aceptadas.

En la base local quedó el teléfono de contacto de prueba en "Academia QA Registro".
