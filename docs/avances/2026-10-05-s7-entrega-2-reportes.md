# S7 — Certificados y reportes · Entrega 2: reportes

Fecha: 2026-10-05 · Sin commit al momento de escribir este documento

Con esta entrega S7 queda completo. Sale de "Próximamente" el módulo **Reportes**: un tablero con los indicadores base de la academia (Maestro RF-REP-001, FP-22).

## Qué se construyó

### El tablero

En el menú, **Reportes** (grupo Operación). Lo ve quien tiene el permiso `reports.read`; de los roles base, solo el Administrador.

- **Periodo**: botones de 7, 30 y 90 días y "Este mes", o dos fechas a elección. Por defecto, los últimos 30 días. Máximo 366 días por consulta.
- **Seis indicadores del periodo**, cada uno con su variación frente al periodo anterior de la misma duración.
- **Dos gráficos**: ingresos y matrículas nuevas. Hasta 62 días muestran un punto por día; en rangos más largos agrupan por semana, y sobre 190 días, por mes. Al pasar el cursor se ve el valor exacto.
- **Cursos más vendidos** del periodo (los cinco primeros).
- **Finalización por curso** (los diez con más matriculados).
- **Situación de hoy**: cifras que no dependen del periodo.

### Qué significa cada cifra

El Maestro pide que cada indicador tenga una definición formal. Estas son:

| Indicador | Definición |
| --- | --- |
| Ingresos | Suma del total de las órdenes **pagadas** en el periodo, por fecha de pago, en la moneda principal de la academia. |
| Órdenes pagadas | Cantidad de órdenes pagadas en el periodo. Incluye las inscripciones a cursos gratuitos (órdenes de 0). |
| Matrículas nuevas | Matrículas creadas en el periodo, por compra o por el personal, aunque después se hayan cancelado. |
| Alumnos activos | Alumnos distintos que completaron al menos un recurso o actividad en el periodo. |
| Cursos completados | Matrículas que llegaron al 100 % en el periodo. |
| Certificados emitidos | Certificados emitidos en el periodo, incluidos los que después se anularon. |
| Cursos más vendidos | Cursos ordenados por el ingreso de sus órdenes pagadas en el periodo; muestra también cuántas ventas tuvo. |
| Finalización por curso | De las matrículas no canceladas de siempre, qué porcentaje completó el curso. No depende del periodo. |
| Órdenes pendientes de pago | Órdenes que hoy esperan pago y siguen dentro de su plazo, con su importe. |
| Alumnos con acceso vigente | Alumnos distintos con al menos una matrícula que hoy les da acceso. |
| Cursos publicados | Cursos en estado publicado hoy. |
| Certificados vigentes | Certificados no anulados hoy. |

Reglas comunes:

- El periodo incluye el día de inicio y el de fin, y los días se cuentan en la **zona horaria de la academia** (Configuración), no en la del servidor.
- La variación compara con el periodo inmediatamente anterior de igual duración. Si antes no hubo nada, dice "Nuevo" en vez de un porcentaje.
- Los ingresos solo suman órdenes en la moneda principal vigente: no se mezclan monedas.

## Detalle técnico

- **Módulo nuevo** `backend/app/Modules/Reports`: `OverviewReport` (consultas agregadas) y `ReportController`.
- **Ruta**: `GET /api/v1/reports/overview?from=AAAA-MM-DD&to=AAAA-MM-DD` (permiso `reports.read`). Sin fechas, los últimos 30 días.
- La serie diaria se agrupa en PostgreSQL con `at time zone` de la academia y se rellena con ceros los días sin movimiento.
- Sin tablas, migraciones ni dependencias nuevas. Los gráficos están hechos a mano (`frontend/src/features/reports/components/ColumnChart.tsx`), sin librería.
- Los gráficos incluyen una tabla equivalente para lectores de pantalla.

## Qué se verificó

- **165 pruebas del backend pasan**, 4 nuevas (`tests/Feature/Reports/OverviewReportTest.php`): cada cifra contra un escenario armado a mano (compras, orden sin pagar, matrícula manual, avance, curso completado y certificado), periodo anterior, serie diaria, días según la zona horaria de la academia, validación del periodo, permisos por rol y aislamiento entre academias.
- El frontend compila, pasa el lint y genera el build.
- **En el navegador, academia demo**: el tablero muestra las cifras de las pruebas hechas hoy (S/ 150 de ingreso, 3 órdenes, 4 matrículas, 2 certificados), los dos gráficos con su valor al pasar el cursor, los cursos más vendidos y la finalización por curso.
- **No se probó en el navegador**: rangos largos (agrupación por semana y por mes), el cambio de fechas a mano ni el tamaño de teléfono. Tampoco con volumen real: la demo tiene muy pocos datos.

## Qué quedó corto

- **Sin exportar** a Excel o CSV (existe el permiso `reports.export`, sin uso todavía).
- **Un solo tablero**: no hay reportes por curso, por alumno ni por vendedor, ni filtros por curso o categoría.
- El **profesor** no tiene reportes de sus cursos (permiso `reports.assigned.read`, sin uso).
- La **finalización** es de siempre, no del periodo; no hay abandono ni tiempo promedio para terminar.
- Sin indicadores de notas (depende de la nota final del curso) ni de pagos por medio (transferencia, Yape).
- Con muchos datos las consultas se calculan en el momento; si se vuelve lento habrá que guardar resúmenes diarios.
- El inicio del administrador sigue mostrando usuarios y roles; no enlaza todavía a estas cifras.
