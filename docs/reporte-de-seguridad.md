# Reporte de seguridad

Fecha: 2026-10-10 · Estado: revisión interna del equipo de desarrollo, sin prueba de penetración externa.

Este reporte lo pide el Documento Maestro (sección 25). Describe qué protege hoy al sistema, cómo se comprueba y qué falta. Se escribió leyendo el código y corriendo las pruebas; **no reemplaza una prueba de penetración**, que el Maestro también pide y está pendiente.

## Resumen

- **Lo esencial está cubierto y se comprueba solo:** aislamiento entre academias, sesión y permiso en cada ruta, y cabeceras de seguridad tienen pruebas automáticas que fallan si alguien las rompe.
- **Dependencias sin avisos de seguridad** al 2026-10-10, en el backend (`composer audit`) y en la aplicación web (`npm audit`).
- **Cinco cosas hay que resolver antes de producción**, en este orden: cambiar la clave de la aplicación expuesta en el repositorio, hacer la prueba de penetración, definir respaldos, montar monitoreo con alertas, y decidir sobre el doble factor para el personal.

## 1. Aislamiento entre academias

| Control | Cómo funciona | Cómo se comprueba |
| --- | --- | --- |
| Cada academia en su subdominio | La academia se resuelve por el subdominio de la petición; una sesión de una academia no sirve en otra | Pruebas de aislamiento por módulo |
| Seguridad por filas en la base | Las 67 tablas con datos de academias tienen la seguridad por filas de PostgreSQL forzada: una consulta sin filtro tampoco ve filas ajenas | Prueba automática: toda tabla con `tenant_id` debe tenerla |
| La aplicación no es superusuario | Se conecta a la base con un usuario sin privilegios para saltarse esa seguridad | Configuración de despliegue |
| Administrador de plataforma aparte | Es otra identidad, solo existe en el dominio central y sus rutas no aceptan sesiones de academia | Pruebas de la consola |
| Acceso de soporte | El personal de la plataforma entra a una academia con una sesión de 30 minutos y solo lectura; queda en la auditoría de la academia | Prueba automática |

## 2. Acceso y sesiones

- **Contraseñas** guardadas como huella irreversible; mínimo 8 caracteres (12 para el administrador de plataforma).
- **Bloqueo por intentos:** 10 fallos seguidos bloquean la cuenta 15 minutos. Configurable para toda la plataforma.
- **Sesiones** que vencen por inactividad: 30 minutos por defecto, entre 5 minutos y 24 horas según lo fije cada academia. El usuario ve sus sesiones abiertas y puede cerrarlas; cambiar la contraseña cierra las demás.
- **Recuperar acceso:** enlace de un solo uso, vigente 60 minutos.
- **Contraseña temporal:** las cuentas creadas por importación o por la consola deben cambiarla en el primer ingreso; hasta entonces no pueden hacer nada más.
- **Registro de accesos:** cada inicio de sesión queda en la auditoría.

## 3. Permisos

- **Cada ruta exige sesión y permiso.** Una prueba recorre las 315 rutas de la API y falla si alguna queda abierta sin estar en la lista corta de rutas públicas declaradas (catálogo, verificación de certificados, Libro de Reclamaciones, avisos de pasarelas, inicio de sesión y registro).
- Los permisos se validan en el servidor; la pantalla solo oculta lo que no se puede usar.
- Los seis roles base son fijos; cada academia puede crear los suyos, y nadie puede dar un permiso que no tiene.
- El profesor solo ve y califica sus cursos.
- Acciones delicadas separadas en permisos propios: publicar páginas, reembolsar, entregar licencias sin pago, exportar reportes.

## 4. Protección de las peticiones

- **Cabeceras** en todas las respuestas: sin detección de tipo, sin incrustar en marcos, política de contenido cerrada para las respuestas de datos, HTTPS estricto cuando la petición llega segura, y sin anunciar la versión de PHP.
- **Orígenes permitidos:** solo los dominios de la propia plataforma pueden llamar a la API desde un navegador.
- **Tope de peticiones:** 300 por minuto por usuario, y topes más bajos en inicio de sesión, registro, recuperación de acceso, compra y formularios públicos.
- **Operaciones que no se repiten:** la compra, la confirmación de pago y el reembolso aceptan una clave para que un doble clic o un reintento no las ejecute dos veces.
- **Identificador de petición** en cada respuesta y en el registro del servidor, para seguir un error.

## 5. Datos y archivos

- **Claves de pasarelas** (Mercado Pago, PayPal) cifradas en la base con la clave de la aplicación; no se devuelven en ninguna respuesta ni quedan en la auditoría.
- **Archivos:** se valida el tipo por el contenido y por la extensión, con tope de tamaño (2 MB imágenes, 50 MB documentos). Los privados (entregas, adjuntos, firmas, fondos e imágenes de certificados) solo se sirven con sesión y permiso.
- **Contenido de las páginas** guardado como estructura de nodos permitidos, no como HTML: no se puede inyectar código.
- **Excel importado:** tope de 500 filas y 2 MB por archivo.

## 6. Pagos

- El sistema nunca ve ni guarda datos de tarjetas: el comprador paga en la página de la pasarela.
- Un pago solo se da por bueno si la pasarela lo confirma al consultarla, y si el importe y la moneda coinciden con lo que se le pidió cobrar. Lo que no cuadra queda marcado para revisión de una persona.
- Los avisos de las pasarelas se verifican **si quien cobra guardó el dato de verificación** (la clave de firma en Mercado Pago, el identificador del aviso en PayPal). Sin él se aceptan, aunque el pago igual se consulta en la pasarela antes de aplicarlo.
- El reembolso devuelve primero el dinero y después cambia la orden.

## 7. Auditoría

Las operaciones críticas quedan registradas con quién, cuándo, desde qué dirección, el valor anterior y el nuevo: accesos, usuarios y roles, configuración, publicación de cursos y páginas, órdenes, pagos y reembolsos, certificados, plantillas, cuentas de pasarelas y acciones de la plataforma sobre una academia.

## 8. Lo que falta

### Antes de producción

| # | Riesgo | Qué hacer |
| --- | --- | --- |
| 1 | **Una clave de la aplicación está en el repositorio**, en el archivo `backend_env_backup`. Con ella se pueden descifrar las cuentas de pasarelas guardadas | Retirar el archivo y generar una clave nueva en cada servidor. Si esa clave se usó en algún servidor, hay que volver a conectar las cuentas de pasarelas |
| 2 | **Sin prueba de penetración.** La pide el Maestro; todo lo de este reporte es revisión propia | Contratarla o asignarla, con alcance definido, cuando exista el servidor de staging |
| 3 | **Sin respaldos definidos** ni prueba de restauración | Definir frecuencia, dónde se guardan y probar una restauración |
| 4 | **Sin monitoreo ni alertas.** Hay registro de errores y un punto de salud, pero nadie se entera si el sistema cae | Depende del servidor |
| 5 | **Cabeceras de la aplicación web y HTTPS** se configuran en el servidor web; el ejemplo de la guía de despliegue no se probó | Probarlo en staging, incluida la política de contenido con los videos de YouTube |

### Mejoras recomendadas

- **Doble factor de autenticación** para el personal. Lo pide el backlog de historias.
- **Captcha** en inicio de sesión y formularios públicos: hoy, quien conoce el correo de alguien puede bloquearle la cuenta 15 minutos a propósito.
- **Hacer obligatoria la verificación de los avisos** de Mercado Pago y PayPal.
- **Política de contraseñas más estricta** (hoy solo se exige el largo) y comprobar contraseñas filtradas.
- **Avisar por correo** al usuario cuando su cuenta se bloquea o cambia su contraseña. Hoy no se envía ningún correo: no hay proveedor.
- **Retención de registros:** cuánto tiempo se guardan la auditoría y los accesos está sin definir (decisión PD-017 del Maestro).
- **Videos:** un video de YouTube «No listado» lo ve cualquiera que tenga el enlace, esté o no matriculado. Es una decisión aceptada, no un defecto.
- **La revisión automática del repositorio (CI) nunca se ejecutó.**
- **Archivos huérfanos:** las imágenes subidas al diseño de certificados que luego se quitan quedan guardadas.

## Cómo repetir esta revisión

Desde `backend/`:

```bash
php artisan test tests/Feature/Security
```

```bash
composer audit
```

Desde `frontend/`:

```bash
npm audit --omit=dev
```
