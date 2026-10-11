# Verificación contra el Documento Maestro

Comparación del sistema con `ERP_SaaS_Cursos_Documento_Maestro_Integral_v2.docx` (versión 2.0), hecha el 2026-10-10 leyendo el documento completo y revisando el código. Se actualiza cuando cambie alguno de los dos.

**Resultado:** el Maestro no está completo. De sus 26 requisitos funcionales, 21 están hechos, 2 a medias y 3 no existen. Además falta un flujo (asistencia), el último sprint y varios entregables de documentación.

## 1. Requisitos funcionales (sección 2.2)

| Requisito | Dominio | Estado | Detalle |
| --- | --- | --- | --- |
| RF-AUTH-001 | Autenticación | Hecho | Recuperar acceso funciona, pero el correo no sale: no hay proveedor (queda en el registro del servidor). |
| RF-AUTH-002 | Sesiones | Hecho | Expiración por inactividad, sesiones activas y revocación. |
| RF-RBAC-001 | Autorización | Hecho | Permisos por rol y academia, validados en el servidor. |
| RF-TEN-001 | Tenancy | Hecho | Aislamiento por subdominio, filtro por academia y seguridad por filas en la base. |
| RF-COURSE-001 | Cursos | Hecho | Crear, editar, publicar con versiones y configurar. |
| RF-CONT-001 | Contenido | Hecho | Secciones, recursos y actividades. |
| RF-ACT-001 | Evaluación | Hecho | Banco de preguntas, cuestionarios, tareas y encuestas. |
| RF-SCORM-001 | SCORM | **Falta** | Sprint S9. Espera la decisión PD-007 (versiones y seguimiento). |
| RF-ENR-001 | Matrícula | Hecho | Matrícula y vigencia. |
| RF-STU-001 | Alumno | Hecho | Mis cursos, Cursos disponibles, progreso y actividades. |
| RF-TEA-001 | Profesor | Hecho | Revisión y calificación dentro de sus cursos. |
| RF-CERT-001 | Certificados | Hecho | Emisión, verificación por código y QR, y enlace para LinkedIn. |
| RF-B2C-001 | Venta B2C | Hecho | Compra de cursos individuales. |
| RF-B2B-001 | Venta B2B | Hecho | Cotización, licencias y asignación. |
| RF-PKG-001 | Paquetes | Hecho | Conjuntos de cursos a un precio. |
| RF-PROMO-001 | Promociones | Hecho | Cupones validados en el servidor. |
| RF-FIN-001 | Finanzas | A medias | Órdenes y pagos sí. **Faltan comprobantes y deudas** (pagos parciales, RN-012). Espera PD-003. |
| RF-CUR-001 | Monedas | Hecho | Una moneda por academia y tipos de cambio como dato propio: manual o automático con SUNAT cada día, recargo opcional e historial con su fuente (2026-10-11). Solo soles por dólar. |
| RF-PAY-001 | Pagos | A medias | Mercado Pago, PayPal y reembolsos construidos detrás de un adaptador. **Primer pago de prueba hecho el 2026-10-10** (un curso, con tarjeta, en Mercado Pago). Faltan: su reembolso, Yape, los avisos, el pago de un plan y todo PayPal (sin credenciales). |
| RF-WAL-001 | Wallet | **Falta** | Fase 3 del Maestro. Reglas sin definir (PD-012). Solo existe el permiso. |
| RF-SUP-001 | Soporte | Hecho | Tickets y mensajes. |
| RF-AI-001 | IA | **Falta** | Fase 3 del Maestro. Falta proveedor, modelo y política de datos (PD-016). Solo existe el permiso. |
| RF-CMS-001 | CMS | Hecho | Borrador, vista previa, paso de revisión (enviar, publicar o devolver con observaciones) y publicación con versiones (2026-10-10). |
| RF-CLAIM-001 | Reclamaciones | Hecho | Registro y seguimiento. Formato y plazo sin revisión legal. |
| RF-REP-001 | Reportes | Hecho | Indicadores con filtros por periodo y descarga en Excel del tablero, las órdenes, los alumnos, las matrículas, los prospectos y el libro de notas (2026-10-10). |
| RF-AUD-001 | Auditoría | Hecho | Operaciones críticas registradas. |

**Totales:** 21 hechos, 2 a medias, 3 faltan.

## 2. Flujos (sección 10)

De los 23 flujos, no existen: **FP-10 Asistencia**, **FP-11 SCORM**, **FP-15 Deuda**, **FP-16 Wallet** y **FP-18 IA**. FP-14 Pago está construido y probado con un pago de prueba de Mercado Pago; PayPal y los reembolsos siguen sin probar con la pasarela. El resto funciona.

## 3. Reglas de negocio (sección 3)

No aplican todavía, porque su módulo no existe: RN-012 (deuda), RN-013 (wallet) y RN-014 (SCORM). Las otras 17 se cumplen; RN-019 (tipo de cambio registrado) se cumple en los pagos con PayPal.

## 4. Requisitos no funcionales (sección 2.3)

| Requisito | Estado |
| --- | --- |
| RNF-SEC-001 Seguridad | Hecho: pruebas automáticas de permisos y de aislamiento entre academias. |
| RNF-DATA-001 Integridad | Hecho: claves, restricciones, transacciones e idempotencia en compra, pagos y reembolsos. |
| RNF-MNT-001 Mantenibilidad | Hecho: módulos, estilo, pruebas y compilación. La revisión automática (CI) está escrita y nunca se ejecutó. |
| RNF-ACC-001 Accesibilidad | Hecho en lo esencial (WCAG 2.1 AA), sin probar con lector de pantalla real. |
| RNF-COMP-001 Compatibilidad | Definida la matriz; probada por medidas, no en todos los navegadores. |
| RNF-PERF-001 Rendimiento | Meta interna de 500 ms en listados; falta el compromiso formal y medir con volumen real (PD-014). |
| RNF-AV-001 Disponibilidad | Falta: depende del servidor (PD-014). |
| RNF-OBS-001 Observabilidad | A medias: registro con identificador de petición y punto de salud. Faltan tableros y alertas. |
| RNF-BCP-001 Continuidad | Falta: respaldos y prueba de restauración (PD-014). |

## 5. Sprints (sección 14)

12 de 14 construidos. Falta **S9** (la parte de SCORM; la revisión docente está hecha) y **S13** (pruebas de aceptación y salida a producción, que necesita servidor, dominio y correo). S6 está construido sin cobro real.

## 6. Decisiones pendientes (sección 22)

| Decisión | Situación |
| --- | --- |
| PD-001 Proveedor de pasarela | **Asumida**: Mercado Pago y PayPal, cada academia con su cuenta. Falta confirmación de jefatura. |
| PD-002 Yape | **Asumida**: dentro de Mercado Pago. |
| PD-003 Comprobantes | Abierta. |
| PD-004 Reembolsos y cancelaciones | **Asumida**: reembolso total por el Administrador, sin plazo ni condiciones. |
| PD-005 Vigencia de matrícula | Resuelta: días de acceso por curso, ajustables por matrícula. |
| PD-006 Requisitos del certificado | Resuelta: 100 % completado y, si el curso la define, nota mínima. |
| PD-007 SCORM | Abierta. |
| PD-008 LinkedIn | Resuelta: enlace para añadir al perfil. |
| PD-009 Tiempos de respuesta de soporte | Abierta. |
| PD-010 Acumulación de promociones | Resuelta: no se acumulan; un cupón por orden. |
| PD-011 Tipo de cambio | Resuelta por el responsable del proyecto (2026-10-10): manual o automático con SUNAT, con recargo opcional. Falta confirmarla con jefatura. |
| PD-012 Wallet | Abierta. |
| PD-013 Libro de Reclamaciones | Resuelta en operativa (plazo configurable); falta revisión legal. |
| PD-014 Compromisos de servicio y respaldos | Abierta. |
| PD-015 Accesibilidad y navegadores | Resuelta. |
| PD-016 IA | Abierta. |
| PD-017 Retención de registros y auditoría | Abierta. |

El Maestro pide no inventar estas decisiones. Las cuatro **asumidas** se construyeron con recomendaciones avisadas y deben confirmarse o cambiarse.

## 7. Entregables del área de desarrollo (sección 25)

| Entregable | Estado |
| --- | --- |
| Repositorio backend y frontend | Hecho (un solo repositorio con las dos carpetas). |
| Esquema y migraciones PostgreSQL | Hecho. |
| Matriz de permisos | Hecho: `docs/roles-y-permisos.md`, generado del código. |
| ADR | Hecho uno: `docs/adr/0001-arquitectura-base.md`. |
| Pruebas automatizadas | Hecho: 315 del backend y 5 recorridos en navegador, corridos el 2026-10-10. |
| CI/CD | A medias: revisión automática escrita, nunca ejecutada; sin despliegue automático. |
| Manual de despliegue | Hecho: `docs/instalacion-y-despliegue.md`. |
| Manual de uso | Hecho: `docs/manual-de-uso-por-rol.docx` (no lo pide el Maestro; lo pidió el responsable del proyecto). |
| OpenAPI | Hecho: `docs/tecnica/openapi.json`, generado de las rutas (298 operaciones). Los cuerpos se deducen de las validaciones y no todos se pudieron deducir. |
| Diccionario de datos | Hecho: `docs/tecnica/diccionario-de-datos.md`, generado de la base (82 tablas, 839 columnas). Todas las columnas están descritas, y una prueba lo exige. |
| Catálogo de estados | Hecho: `docs/tecnica/catalogo-de-estados.md`. Muchos valores no tienen su significado escrito. |
| Catálogo de errores | Hecho: `docs/tecnica/catalogo-de-errores.md`. |
| Mapa de trazabilidad | Hecho: `docs/tecnica/trazabilidad.md` (a mano). |
| Runbooks y manual operativo | **Faltan.** |
| Reporte de seguridad | Hecho: `docs/reporte-de-seguridad.md` (revisión propia). **Falta la prueba de penetración.** |
| Reporte de QA / aceptación | **Falta** (S13). |
| Notas de versión | Hecho: `docs/notas-de-version.md`. |
| Infraestructura como código | No aplica todavía: no hay servidor. |

## 8. Qué falta para poder decir «solo queda revisar el sistema»

1. Terminar las pruebas de pagos: reembolso y pago de plan con Mercado Pago (ya hay credenciales y un primer pago hecho), y credenciales de prueba de PayPal.
2. Que jefatura decida, por escrito, si SCORM, billetera, IA, asistencia, comprobantes y deudas se construyen o quedan fuera de esta versión.
3. Servidor, dominio y correo, para S13.
4. La documentación técnica que sale del código ya está en `docs/tecnica/`. El reporte de seguridad y las notas de versión ya están. Siguen faltando runbooks, manual operativo y reporte de aceptación, que dependen de tener servidor, y la prueba de penetración.
5. Confirmar las cuatro decisiones asumidas (PD-001, PD-002, PD-004 y PD-011).

Con lo que ya existe sí se puede hacer una revisión del sistema, dejando claro que es sin esos módulos y sin cobros reales.
