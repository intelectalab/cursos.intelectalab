# Mapa de trazabilidad

Para cada requisito funcional del Documento Maestro (§2.2): la regla de negocio que lo gobierna, dónde se guarda, por qué API se usa, en qué pantalla, con qué permiso, qué historia lo pide y qué prueba automática lo cubre (Maestro §20).

Este documento se escribe a mano y se revisa con cada cambio. Los demás de esta carpeta se generan con `php artisan docs:technical`. Última revisión: 2026-10-10.

- Las **pruebas** están en `backend/tests/Feature/`. «E2E» es un recorrido en navegador de `frontend/e2e/recorridos.spec.ts`.
- Los **casos críticos** del Maestro (§15.2) se indican con su código TC.
- Las **historias** son las del Maestro (§12); donde el Maestro no trae una, se indica «—».

## 1. Requisitos construidos

| Requisito | Reglas | Tablas | API principal | Pantalla | Permiso | Historia | Pruebas |
| --- | --- | --- | --- | --- | --- | --- | --- |
| RF-AUTH-001 Autenticación | RN-002 | users, password_reset_tokens | `POST /auth/login`, `/auth/logout`, `/auth/forgot-password`, `/auth/reset-password` | Inicio de sesión | — (pública) | US-AUTH-001 | Identity/AuthTest, Identity/AccountTest · TC-AUTH-001 · E2E acceso |
| RF-AUTH-002 Sesiones | RN-002 | personal_access_tokens | `GET` y `DELETE /auth/sessions`, `DELETE /users/{id}/sessions` | Mi cuenta | — (cuenta propia); users.update | US-AUTH-001 | Identity/AccountTest, Identity/AuthTest |
| RF-RBAC-001 Autorización | RN-002 | roles, permissions, role_permissions, user_roles | `GET /users`, `/roles` | Usuarios; Roles y permisos | users.\*, roles.\* | — | Identity/PermissionTest, RoleManagementTest, UserManagementTest, Security/SecurityBaselineTest · TC-RBAC-001 |
| RF-TEN-001 Tenancy | RN-001 | tenants, tenant_settings y `tenant_id` en toda tabla de negocio | Transversal (subdominio) | Todas | — | US-AUTH-001 | Tenancy/TenantIsolationTest, TenantResolutionTest, Security/SecurityBaselineTest · TC-TEN-001 |
| RF-COURSE-001 Cursos | RN-001, RN-020 | courses, course_versions, course_teachers | `POST /courses`, `PUT /courses/{id}`, `POST /courses/{id}/publish` | Cursos → editor del curso | courses.create, courses.update, courses.publish | US-COURSE-001 | Courses/CourseTest |
| RF-CONT-001 Contenido | RN-016 | course_sections, course_resources, course_activities, files | `POST /courses/{id}/sections`, `…/resources`, `…/activities` | Editor del curso → Contenido | courses.content.write, content.assigned.write | — | Courses/CourseTest, Academic/ActivityAuthoringTest |
| RF-ACT-001 Evaluación | RN-005, RN-006 | question_banks, questions, quizzes, quiz_questions, quiz_attempts, quiz_answers, quiz_attempt_grants, tasks, task_submissions, surveys, survey_responses | `POST /me/courses/{c}/activities/{a}/attempts`, `…/submission`, `…/survey` | Banco de preguntas; aula del alumno | question_banks.manage, activities.attempt | — | Academic/ActivityAuthoringTest, ActivityDeliveryTest, Unit/QuestionGraderTest · E2E cuestionario |
| RF-ENR-001 Matrícula | RN-003, RN-004 | enrollments | `POST /enrollments`, `POST /courses/{id}/participants`, `PUT /enrollments/{id}` | Matrículas; Curso → Participantes | enrollments.create, enrollments.update, enrollments.cancel | — | Enrollments/EnrollmentTest, EnrollmentValidityTest, ParticipantTest, Imports/ImportTest |
| RF-STU-001 Alumno | RN-003, RN-005 | enrollments, enrollment_progress | `GET /me/courses`, `GET /me/courses/{id}` | Mis cursos; Cursos disponibles; aula | student.portal, enrollments.self.read | US-STU-001 | Enrollments/EnrollmentTest, SequentialAndPreviewTest, Academic/ActivityDeliveryTest |
| RF-TEA-001 Profesor | RN-002, RN-006 | course_teachers, task_submissions | `POST /courses/{c}/submissions/{s}/grade`, `…/return`, `GET …/gradebook/export` | Curso → Seguimiento | submissions.review, submissions.assigned.review | — | Academic/ActivityDeliveryTest, Reports/TeacherDirectoryTest |
| RF-CERT-001 Certificados | RN-007 | certificate_templates, certificates (con su diseño congelado), certificate_verifications, courses.certificate_template_id | `POST /certificates/issue`, `GET /certificates/verify/{code}`, `PUT /certificates/template/layout`, `/certificates/templates` | Certificados; Mis certificados; verificación pública; editor visual del diseño | certificates.issue, certificates.revoke, certificates.self | US-CERT-001 | Certificates/CertificateTest · TC-CERT-001, TC-CERT-002 · E2E certificado |
| RF-B2C-001 Venta B2C | RN-008, RN-009, RN-020 | orders, order_items, payments | `POST /orders`, `POST /orders/{id}/confirm-payment` | Catálogo → checkout → Mis compras; Ventas y pagos | orders.create, payments.confirm | US-SAL-001 | Sales/OrderTest, CatalogFiltersTest · TC-SAL-001, TC-SAL-002 · E2E compra |
| RF-B2B-001 Venta B2B | RN-010, RN-011 | leads, companies, contacts, opportunities, opportunity_activities, quotations, quotation_items, quotation_versions, corporate_accounts, corporate_members, licenses, license_assignments | `POST /quotations`, `POST /licenses/{id}/assign` | CRM y clientes; Mi empresa | crm.\*, quotations.\*, licenses.assign, company.portal | US-B2B-001, US-B2B-002 | Crm/CrmTest, B2b/QuotationTest, B2b/LicenseTest · TC-B2B-001, TC-B2B-002 · E2E licencia |
| RF-PKG-001 Paquetes | RN-004, RN-008 | packages, package_items | `POST /packages`, `POST /orders` (con `package_id`) | Promociones → Paquetes; catálogo | packages.manage, orders.create | — | Sales/PackageTest |
| RF-PROMO-001 Promociones | RN-008 | promotions, promotion_items, coupons, coupon_redemptions | `POST /checkout/validate-coupon` | Promociones; checkout | promotions.manage, checkout.validate | US-SAL-001 | Promotions/CouponTest · TC-SAL-003 · E2E compra |
| RF-SUP-001 Soporte | RN-016 | tickets, ticket_messages, ticket_attachments | `POST /tickets`, `POST /tickets/{id}/messages` | Ayuda; Soporte | tickets.create, tickets.self, tickets.manage | — | Support/TicketTest |
| RF-CLAIM-001 Reclamaciones | RN-018 | claims, claim_status_history, claim_messages, holidays | `POST /claims`, `POST /claims/lookup`, `POST /claims/{id}/respond` | Libro de Reclamaciones (público); Reclamaciones | — (pública); claims.manage | — | Claims/ClaimTest, Tenancy/HolidayTest |
| RF-AUD-001 Auditoría | RN-015 | audit_events | `GET /audit-events` | Auditoría | audit.read | — | Audit/AuditEventsTest · TC-AUD-001 |

## 2. Requisitos a medias

| Requisito | Reglas | Tablas | API principal | Pantalla | Permiso | Pruebas | Qué falta |
| --- | --- | --- | --- | --- | --- | --- | --- |
| RF-FIN-001 Finanzas | RN-009, RN-012 | orders, payments | `GET /orders`, `POST /orders/{id}/confirm-payment`, `POST /orders/{id}/refund` | Ventas y pagos | orders.read, payments.confirm, payments.refund | Sales/OrderTest, RefundTest | Comprobantes (`receipts`) y deudas con pagos parciales (`debts`, RN-012). Espera PD-003. |
| RF-PAY-001 Pagos | RN-009, RN-019 | payment_gateway_accounts, payment_attempts, payments | `POST /me/orders/{id}/pay`, `…/pay/sync`, `POST /payments/webhooks/{pasarela}` | Configuración → Cobros en línea; orden del comprador | tenant_settings.update, orders.create | Sales/OnlinePaymentTest, PayPalPaymentTest, RefundTest · TC-SAL-002 | Primer pago de prueba con Mercado Pago hecho (2026-10-10). Falta probar con la pasarela: reembolso, avisos, pago de plan y PayPal. |
| RF-CUR-001 Monedas | RN-019 | currencies, exchange_rates | `PUT /settings`, `PUT /settings/payment-gateways/{pasarela}` (tipo de cambio) | Configuración → Región y monedas; Cobros en línea | tenant_settings.update | Tenancy/TenantSettingsTest, Sales/PayPalPaymentTest, Sales/ExchangeRateTest | Otras monedas y otras fuentes. |
| RF-CMS-001 CMS | RN-017 | pages, page_versions, media | `POST /pages`, `POST /pages/{id}/submit-review`, `…/request-changes`, `…/publish` | Páginas | cms.update, cms.publish | Cms/PageTest (incluye el paso de revisión) | Avisar a quien publica; historial de observaciones. |
| RF-REP-001 Reportes | — | Consultas sobre orders, enrollments, certificates | `GET /reports/overview`, `GET /reports/overview/export` y `…/export` de órdenes, alumnos, matrículas y prospectos | Reportes; Alumnos; Docentes; botón «Exportar a Excel» | reports.read, reports.export, students.read, teachers.read | Reports/OverviewReportTest, ExcelExportTest, StudentDirectoryTest, TeacherDirectoryTest | Exportar los demás listados; aviso al cortar en 5 000 filas. |

## 3. Requisitos sin construir

| Requisito | Reglas | Lo que existe | Qué espera |
| --- | --- | --- | --- |
| RF-SCORM-001 SCORM | RN-014 | El permiso `scorm.manage` | PD-007: versiones y seguimiento. Sprint S9. · TC-SCORM-001 sin prueba |
| RF-WAL-001 Wallet | RN-013 | El permiso `wallet.use` | PD-012: recarga, uso, reversa y expiración. Fase 3 del Maestro. · TC-WAL-001 sin prueba |
| RF-AI-001 IA | — | El permiso `ai.chat` | PD-016: proveedor, modelo y política de datos. Fase 3 del Maestro. |

También está sin construir el flujo **FP-10 Asistencia** (tablas `attendance_sessions` y `attendance_records` del Maestro §5.1), que no tiene requisito propio.

## 4. Lo construido que el Maestro no pide

Se construyó por pedido de jefatura o del responsable del proyecto. No tiene requisito en el Maestro; queda aquí para que también sea trazable.

| Función | Tablas | API principal | Pantalla | Permiso | Pruebas | Origen |
| --- | --- | --- | --- | --- | --- | --- |
| Consola del super administrador | platform_admins | `GET /platform/tenants`, `POST /platform/tenants/{id}/support-session` | Consola (/platform) | Administrador de plataforma | Platform/PlatformAdminTest | Revisión de jefatura, punto 9 |
| Planes y cobro a las academias | plans, tenant_subscriptions, plan_orders, platform_settings | `GET /subscription/plans`, `POST /subscription/orders`, `POST /platform/plan-orders/{id}/confirm` | Mi plan; consola → Planes y Pagos | tenant_settings.read, tenant_settings.update | Billing/PlansAndTrialTest, PlanOrdersTest, PlanLimitsTest, PlanOrderPaymentsTest | Modelo de negocio (decisión 16, sin confirmar) |
| Grupos e importaciones desde Excel | groups, group_members | `POST /imports/people`, `POST /groups` | Usuarios → Grupos; importar | users.create, users.update | Imports/ImportTest | Revisión de jefatura, puntos 2 a 4 |
| Escalas de notas y feriados | grade_scales, holidays | `POST /settings/grade-scales`, `POST /settings/holidays` | Configuración | tenant_settings.update | Tenancy/GradeScaleTest, HolidayTest | Pedido del responsable; S12 |
| Fichas de alumnos y docentes | — | `GET /students`, `GET /teachers` | Alumnos; Docentes | students.read, teachers.read | Reports/StudentDirectoryTest, TeacherDirectoryTest | Backlog YC, sprint 2 |
| Idempotencia y registro de accesos | idempotency_keys | Cabecera `Idempotency-Key` | — | — | Sales/OrderTest, Security/SecurityBaselineTest | S12 (RNF-DATA-001) |

## 5. Casos críticos del Maestro (§15.2)

| Caso | Escenario | Prueba |
| --- | --- | --- |
| TC-TEN-001 | Usuario de la academia A consulta un dato de B | Tenancy/TenantIsolationTest |
| TC-AUTH-001 | Inicio de sesión válido | Identity/AuthTest |
| TC-RBAC-001 | Alumno llama a una operación administrativa | Identity/PermissionTest, Security/SecurityBaselineTest |
| TC-SAL-001 | Compra exitosa | Sales/OrderTest |
| TC-SAL-002 | Pago duplicado | Sales/OrderTest, OnlinePaymentTest (aviso repetido) |
| TC-SAL-003 | Cupón inválido | Promotions/CouponTest |
| TC-B2B-001 | Asignar licencia disponible | B2b/LicenseTest |
| TC-B2B-002 | Asignar licencia ya usada | B2b/LicenseTest |
| TC-CERT-001 | Emitir sin requisitos | Certificates/CertificateTest |
| TC-CERT-002 | Verificar código válido | Certificates/CertificateTest |
| TC-SCORM-001 | Paquete inválido | Sin prueba: SCORM no está construido |
| TC-WAL-001 | Saldo insuficiente | Sin prueba: la billetera no está construida |
| TC-AUD-001 | Modificar una operación sensible | Audit/AuditEventsTest |

## 6. Límites de este mapa

- Indica el archivo de prueba, no cada caso dentro de él.
- Las rutas completas de la columna «API principal» se contrastaron con `openapi.json` el 2026-10-10; las abreviadas con «…» no. Si alguna no coincide, manda `openapi.json`, que sale de las rutas reales.
- Los criterios de aceptación con código propio (AC-…) que el Maestro menciona en §20 no existen como documento: el Maestro solo da el criterio base de cada historia.
