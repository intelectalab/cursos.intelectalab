# Notas de versión

## Versión 1.0 · candidata

Fecha: 2026-10-10 · Rama `develop` · **No está en producción: todavía no hay servidor.**

Es la primera versión completa de la plataforma de cursos virtuales para varias academias. «Candidata» significa que está construida y probada por el equipo de desarrollo, pero falta la aceptación de jefatura y la salida a un servidor.

### Qué incluye

**Plataforma**
- Varias academias independientes, cada una en su subdominio, con sus datos aislados.
- Consola de IntelectaLab: alta de academias, ficha de cada una, acceso de soporte en solo lectura, planes y pagos de las academias.
- Planes por cantidad de alumnos, con prueba gratuita, días de gracia y solo lectura al vencer.

**Academia**
- Usuarios, roles y permisos propios; grupos de personas; importación desde Excel.
- Cursos con secciones, videos de YouTube, materiales y versiones publicadas.
- Cuestionarios con siete tipos de pregunta, tareas con revisión y encuestas; nota final y libro de notas.
- Matrículas con vigencia, avance secuencial y aula del alumno.
- Certificados con código y QR, verificación pública, varias plantillas por curso y editor visual del diseño.

**Ventas**
- Catálogo público con buscador, registro del comprador y compra de cursos y paquetes.
- Cupones de descuento.
- Pago en línea con Mercado Pago y PayPal, pago por fuera con confirmación del personal, y reembolso total.
- Venta a empresas: prospectos, oportunidades, cotizaciones, licencias y portal del responsable de la empresa.

**Operación**
- Soporte por tickets, Libro de Reclamaciones público y páginas de texto con paso de revisión.
- Reportes con comparación de periodos y descarga en Excel.
- Auditoría de las operaciones críticas.

### Qué no incluye

| Tema | Situación |
| --- | --- |
| SCORM | No construido. Espera saber si alguna academia lo necesita |
| Comprobantes electrónicos (boletas, facturas, IGV) | No construido. Espera definir proveedor y reglas |
| Pagos en cuotas y deudas | No construido. Decidido que el alumno entra con el primer pago; falta definir el resto |
| Billetera | No construido. Sin reglas definidas |
| Inteligencia artificial | No construido. Sin proveedor ni política de datos |
| Asistencia y horarios | No construido. Va junto con el módulo de horarios que pidió jefatura |
| Sincronización con Moodle | No construido. Falta un Moodle de prueba |
| Envío de correos | Los avisos se generan pero no salen: falta el proveedor de correo |
| Doble factor de autenticación | No construido |

### Qué falta probar

- **Pagos:** se hizo un pago de prueba de un curso con tarjeta en Mercado Pago. Faltan su reembolso, Yape, los avisos automáticos, el pago de un plan y todo PayPal.
- **En un servidor:** nada se probó fuera del equipo de desarrollo. Faltan las pruebas de aceptación, de carga y de penetración.
- **En dispositivos reales:** la revisión en teléfono y tableta se hizo por medidas, no con equipos.

### Contra el Documento Maestro

De 26 requisitos funcionales: **20 hechos, 3 a medias y 3 sin construir**. El detalle está en [verificacion-maestro.md](verificacion-maestro.md).

### Calidad

- 315 pruebas automáticas del backend pasan.
- Cinco recorridos automáticos en navegador: acceso, compra con cupón, cuestionario, certificado y licencia.
- Dependencias sin avisos de seguridad al 2026-10-10.
- Revisión de seguridad en [reporte-de-seguridad.md](reporte-de-seguridad.md).

### Para desplegar

Los pasos están en [instalacion-y-despliegue.md](instalacion-y-despliegue.md). A tener presente en esta versión:

- **No cambiar la clave de la aplicación** después de que las academias conecten sus cuentas de pasarelas: sus claves se guardan cifradas con ella.
- **Generar una clave nueva** para cada servidor: la del archivo `backend_env_backup` del repositorio quedó expuesta y no debe usarse.
- Los certificados emitidos conservan el diseño con el que salieron; cambiar la plantilla ya no los altera.
- Con el cupo del plan lleno una academia no matricula alumnos nuevos, y vencido el plan pasa a solo lectura tras los días de gracia.

### Documentación de esta versión

- Manual de uso por rol: `docs/manual-de-uso-por-rol.docx` (versión 1.5).
- Documentación técnica: [tecnica/](tecnica/README.md) (contrato de la API, diccionario de datos, catálogos de estados y de errores, trazabilidad).
- Historial de entregas: [avances/](avances/README.md).
- Pendientes y decisiones abiertas: [pendientes.md](pendientes.md).
