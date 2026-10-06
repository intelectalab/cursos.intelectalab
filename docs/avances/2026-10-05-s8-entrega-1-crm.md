# S8 — Empresas y CRM · Entrega 1: prospectos, empresas y oportunidades

Fecha: 2026-10-05 · Sin commit al momento de escribir este documento

Primera de las tres entregas de S8. Sale de "Próximamente" el módulo **CRM y clientes**: el equipo comercial registra a quién le interesa capacitar a su empresa y sigue cada posible venta hasta ganarla o perderla (Maestro FP-21: prospecto → contacto y empresa → oportunidad → actividad).

## Qué se construyó

En el menú, **CRM y clientes** (grupo Operación), con tres pestañas.

### Oportunidades (el embudo)

- Tablero con una columna por etapa: **Nueva → Contactada → Propuesta enviada → Negociación → Ganada / Perdida**. Las etapas son fijas.
- Cada columna muestra cuántas oportunidades tiene y cuánto suman sus montos estimados.
- Al abrir una oportunidad se cambia de etapa con un clic, se ven sus datos y se registra el **seguimiento**: llamadas, reuniones, correos y notas. Cada cambio de etapa queda anotado solo, con fecha y persona.
- Marcarla como **perdida exige el motivo**. Una oportunidad cerrada se puede reabrir.
- Filtros por texto y por responsable.

### Empresas

- Una sola ficha por empresa, desde que es prospecto hasta que es cliente (pasará a "Cliente" con su primera venta, en la entrega 2).
- Datos: razón social, RUC (opcional, no se repite dentro de la academia), rubro, correo, teléfono, web, dirección, ciudad y notas.
- **Contactos** de la empresa, con uno marcado como principal.
- Desde la ficha se ven y se crean sus oportunidades.

### Prospectos

- Una persona interesada: nombre, empresa, cargo, correo o teléfono (al menos uno), cómo llegó y qué le interesa.
- **Convertir**: el prospecto pasa a ser contacto de una empresa nueva o de una ya registrada y, si se quiere, se crea la oportunidad en el mismo paso. Queda el rastro de en qué empresa terminó.
- **Descartar**, con motivo.
- Un prospecto convertido o descartado ya no se edita.

### Quién puede usarlo

| Permiso | Qué permite | Roles base que lo tienen |
| --- | --- | --- |
| `crm.read` | Ver prospectos, empresas y oportunidades | Administrador, Ventas/CRM |
| `crm.manage` | Registrar y editar | Administrador, Ventas/CRM |

Los dos permisos son nuevos en el catálogo y quedan cubiertos por el `crm.*` que ya tenía el rol Ventas/CRM. Una academia puede crear, por ejemplo, un rol de solo lectura con `crm.read`.

Cada prospecto y cada oportunidad tiene una persona **a cargo**; solo puede serlo alguien con permiso para gestionar el CRM. Por defecto, quien lo registra.

## Detalle técnico

- **Módulo nuevo** `backend/app/Modules/Crm`. Tablas del Maestro §5.1: `leads`, `companies`, `contacts`, `opportunities`, `opportunity_activities`, todas con `tenant_id` y RLS.
- **Rutas** bajo `/api/v1/crm`: `leads` (lista, alta, edición, `convert`, `discard`), `companies` (lista, ficha, alta, edición, contactos), `opportunities` (`board`, detalle, alta, edición, `stage`, `activities`) y `owners`.
- **Auditoría**: alta y edición de empresas, conversión y descarte de prospectos, alta y cambio de etapa de oportunidades, eliminación de contactos.
- Código de error nuevo: `LEAD_NOT_OPEN`.
- Frontend: `frontend/src/features/crm`. Sin dependencias nuevas.

## Qué se verificó

- **173 pruebas del backend pasan**, 8 nuevas (`tests/Feature/Crm/CrmTest.php`): conversión completa con su rastro, conversión hacia una empresa existente, RUC duplicado, contacto principal único, recorrido del embudo con su historial y los totales del tablero, validaciones (medio de contacto, motivo de pérdida y de descarte, contacto de otra empresa, responsable que no es del equipo comercial), permisos por rol y aislamiento entre academias.
- El frontend compila, pasa el lint y genera el build.
- **En el navegador, academia demo**: registré un prospecto, lo convertí en empresa con oportunidad, moví la oportunidad de etapa, registré una llamada y abrí la ficha de una empresa con sus contactos y oportunidades.
- **No se probó en el navegador**: editar empresa, contacto y prospecto, descartar un prospecto, el filtro por responsable, ni el tamaño de teléfono.

## Qué quedó corto

- **Las tarjetas no se arrastran** entre columnas: la etapa se cambia abriendo la oportunidad.
- **Etapas fijas**: no se configuran por academia.
- **No se eliminan** prospectos, empresas ni oportunidades (se descartan o se marcan perdidas). Tampoco se fusionan empresas duplicadas.
- El tablero muestra hasta 50 oportunidades por etapa, las más recientes.
- Sin **recordatorios** ni tareas con fecha; el seguimiento es un historial de lo ya ocurrido.
- Sin **formulario público** para que el prospecto se registre solo desde la web, ni importación desde un archivo.
- Los selectores de empresa (al crear una oportunidad o convertir un prospecto) cargan las primeras 100 empresas; con más habrá que ponerles buscador.
- Las cifras del CRM todavía no aparecen en Reportes.
