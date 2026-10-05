# S7 — Certificados y reportes · Entrega 1: certificados

Fecha: 2026-10-05 · Sin commit al momento de escribir este documento

S7 se adelantó a S6 (Pagos), que espera a que jefatura defina la pasarela. Se hace en dos entregas: esta, con los certificados, y una segunda con los reportes.

Flujo del Maestro (FP-12): requisitos → emitir → generar código y QR → almacenar → verificar. Hay un PDF de ejemplo en [ejemplo-certificado.pdf](ejemplo-certificado.pdf).

## Qué se construyó

### Qué se exige para el certificado

- En **Información del curso** hay un interruptor **"Este curso entrega certificado"**.
- El alumno lo recibe al **completar el curso al 100 %**. Eso ya exige abrir todos los recursos, aprobar los cuestionarios, entregar las tareas y responder las encuestas.
- No hay nota mínima final: llegará cuando se defina la nota final del curso. El Maestro deja los requisitos exactos pendientes (PD-006).

### Emisión

- **Automática**: en el momento en que el alumno llega al 100 %.
- **Cursos completados antes de activar el interruptor**: el certificado se emite cuando el alumno abre "Mis certificados", o lo emite el personal.
- **Manual**: en Certificados → "Emitir certificado" aparecen los alumnos que cumplen los requisitos y no tienen uno vigente. Si no se cumplen los requisitos, se rechaza.
- Cada certificado guarda lo que dice (nombre del alumno, documento, curso, horas, academia, quién firma) tal como estaba al emitirlo: si después cambia el nombre del curso o del alumno, el certificado no cambia.
- Un solo certificado vigente por matrícula.

### El certificado en PDF

- Hoja A4 horizontal con el logo de la academia, nombre del alumno y su documento (si lo tiene registrado), curso, horas, fecha, firma, código de verificación y QR.
- El QR lleva a la página pública de verificación de la academia.
- Se genera en el momento de descargarlo; no se guarda un archivo por certificado.

### Plantilla

- En Certificados → **Plantilla**: nombre y cargo de quien firma, y la imagen de su firma (PNG o JPG, máximo 2 MB).
- El diseño es el mismo para todas las academias; cada una pone su logo y su firma.
- La imagen de la firma es privada: solo la ve el personal y sale en el PDF.

### Verificación pública

- `https://{academia}/verify` — se escribe el código; `https://{academia}/verify/{código}` — a donde lleva el QR. No requiere iniciar sesión.
- Muestra si el certificado es **válido** o fue **anulado**, y el nombre del alumno, el curso, las horas, la fecha de emisión y la academia. No muestra el correo ni el documento de identidad.
- Acepta el código con o sin guiones, en mayúsculas o minúsculas.
- Cada consulta queda registrada (código, resultado, fecha y origen).

### "Mis certificados" (alumno)

- Sus certificados vigentes, con **Descargar PDF**, **Verificar** y **Añadir a LinkedIn**.
- El botón de LinkedIn abre su formulario "Añadir licencia o certificación" con los datos ya llenos. No usa convenio ni llaves (PD-008).

### "Certificados" (personal)

- Lista con búsqueda por alumno, curso o código, y filtro por estado.
- Descargar el PDF, emitir a mano y **anular** indicando el motivo.
- Un certificado anulado deja de existir para el alumno y aparece como anulado en la verificación pública. No se repone solo: el personal puede emitir uno nuevo, con otro código.

### En el catálogo

Los cursos que entregan certificado lo indican en su ficha y en su tarjeta ("Con certificado").

### Quién puede hacer qué

| Acción | Permiso | Roles base que lo tienen |
| --- | --- | --- |
| Ver y descargar sus certificados | `certificates.self` | Alumno |
| Ver los certificados de la academia | `certificates.read` (nuevo) | Administrador |
| Emitir a mano | `certificates.issue` | Administrador |
| Anular | `certificates.revoke` (nuevo) | Administrador |
| Cambiar la plantilla | `tenant_settings.update` | Administrador |

Los permisos se pueden dar a roles propios de la academia.

## Detalle técnico

- **Módulo nuevo** `backend/app/Modules/Certificates`: `CertificateService` (requisitos, emisión, anulación, verificación), `CertificatePdf`, `QrCode`, controladores y presentador.
- **Tablas nuevas** (migración `2026_10_17_000100_create_certificates_tables`): `certificate_templates`, `certificates`, `certificate_verifications`, y la columna `courses.issues_certificate`. Todas con RLS. Un índice único parcial impide dos certificados vigentes por matrícula.
- **Dependencias nuevas de composer**: `dompdf/dompdf` (genera el PDF) y `bacon/bacon-qr-code` (calcula el QR).
- **Extensión de PHP nueva**: `gd`. Dibuja el QR e incrusta el logo y la firma en el PDF.
- **Emisión automática sin acoplar módulos**: Matrículas emite el evento `EnrollmentCompleted`; Certificados lo escucha (registrado en `AppServiceProvider`).
- **Plantilla del PDF**: `backend/resources/views/certificates/certificate.blade.php`.
- **Archivos**: uso nuevo `certificate_signature` (privado) en `config/files.php`.
- **Rutas nuevas**:
  - Pública: `GET /certificates/verify/{código}` (30 consultas por minuto).
  - Alumno: `GET /me/certificates`, `GET /me/certificates/{id}/pdf`.
  - Personal: `GET /certificates`, `GET /certificates/candidates`, `POST /certificates/issue` (Maestro §7.2), `POST /certificates/{id}/revoke`, `GET /certificates/{id}/pdf`.
  - Plantilla: `GET` y `PUT /certificates/template`; `GET`, `POST` y `DELETE /certificates/template/signature`.
- **Códigos de error nuevos**: `CERTIFICATE_NOT_ELIGIBLE`, `CERTIFICATE_REVOKED`.
- **Auditoría**: `certificates.issued`, `certificates.revoked`, `certificate_templates.updated`, `certificate_templates.signature_updated`, `certificate_templates.signature_removed`.
- **Frontend**: `frontend/src/features/certificates` y el interruptor en el formulario del curso.

## Qué se verificó

- **161 pruebas del backend pasan**, 7 nuevas (`tests/Feature/Certificates/CertificateTest.php`): emisión al completar y verificación pública (TC-CERT-002), descarga en PDF, rechazo sin requisitos (TC-CERT-001), cursos completados antes de activar el interruptor, anulación y nueva emisión, firma (subida, validación y privacidad), permisos y aislamiento entre academias.
- El frontend compila, pasa el lint y genera el build.
- **En el navegador, academia demo**: se activó el certificado en "Excel para finanzas"; el alumno demo, que ya lo había completado, recibió el suyo. Se vio el PDF con logo, firma, código y QR; se verificó en la página pública escribiendo el código sin guiones y en minúsculas; se subió una firma en la plantilla; se anuló el certificado y se emitió uno nuevo a mano; y se revisó "Mis certificados" del alumno con sus tres botones.
- **No se probó**: escanear el QR con un teléfono (hace falta un servidor accesible desde fuera), el envío real a LinkedIn (solo se revisó que el enlace lleve los datos), ni las pantallas nuevas a tamaño de teléfono.

## Qué quedó corto

- **Sin nota mínima** para el certificado: depende de definir la nota final del curso.
- **Una sola plantilla**, con diseño fijo. No hay editor ni plantillas por curso.
- **Sin aviso por correo** al emitir el certificado (depende del proveedor de correo).
- El alumno no ve desde "Mis cursos" que ya tiene certificado; lo encuentra en "Mis certificados".
- El profesor no ve los certificados de sus cursos.
- No hay emisión masiva con un solo clic: el personal emite uno por uno los casos pendientes (los demás se emiten solos).
- El logo y la firma del PDF son los vigentes al descargar, no los del día de la emisión.
- Las consultas de verificación se registran, pero todavía no hay pantalla para verlas.

## Qué sigue

Entrega 2 de S7: reportes. Tablero con rango de fechas: ventas, matrículas nuevas, alumnos activos, porcentaje de finalización por curso, certificados emitidos y cursos más vendidos.
