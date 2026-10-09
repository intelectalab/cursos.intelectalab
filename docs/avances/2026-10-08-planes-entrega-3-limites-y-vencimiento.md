# Planes de la plataforma · Entrega 3: límites y vencimiento

Fecha: 2026-10-08 · Sin commit al momento de escribir este documento

Tercera y última entrega del cobro de IntelectaLab a las academias. Hasta aquí los planes solo informaban; **desde esta entrega el plan limita de verdad**: el cupo de alumnos se respeta y una academia que no paga termina en solo lectura.

El modelo sigue sin confirmarse con jefatura (decisión 16 de [pendientes](../pendientes.md)). El cobro en línea llegará con las pasarelas.

## Qué se construyó

### Cupo de alumnos

- Cuenta como alumno **cada persona con al menos una matrícula activa**. Quien ya es alumno no ocupa otro lugar al entrar a un segundo curso.
- **Con el cupo lleno no entra nadie nuevo**, por ninguna de las puertas: matrícula manual, participantes del curso (también por grupos), importación de personas con matrícula, asignación de licencias a trabajadores de una empresa y compra o inscripción gratuita desde el catálogo.
- **Los que ya están siguen entrando y estudiando.** Al cancelar una matrícula el lugar se libera.
- Si se intenta matricular a varios y no caben todos, **no se matricula a ninguno** y el mensaje dice cuántos hay, cuántos permite el plan y dónde ampliarlo. En la importación desde Excel el aviso sale fila por fila.
- **Una compra que ya estaba hecha se respeta**: si el alumno dejó su orden cuando había lugar y el pago se confirma después, queda matriculado aunque el cupo se haya llenado. El cupo se revisa al comprar, no al pagar.
- Al comprador se le dice que la academia no está recibiendo alumnos nuevos, sin hablarle del plan.

### Vencimiento: días de gracia y solo lectura

Cuando vence la prueba gratuita o el periodo pagado:

1. **Siete días de gracia.** Todo sigue funcionando. Quien administra la academia ve un aviso fijo arriba de todas las pantallas con los días que quedan y un enlace a "Mi plan".
2. **Después, solo lectura.** No es una suspensión: todos entran y consultan todo. Lo que deja de funcionar es registrar cambios (crear o editar cursos, matricular, vender, confirmar pagos, usuarios, configuración…) y el registro de cuentas nuevas.

Lo que **sigue funcionando en solo lectura**:

| Qué | Por qué |
| --- | --- |
| Entrar, salir, cambiar la contraseña | Sin eso no se puede ni pagar |
| "Mi plan": pedir un plan, informar el pago, cancelar la orden | Es la salida del solo lectura |
| Lo que el alumno hace en sus cursos: completar recursos, rendir cuestionarios, entregar tareas, responder encuestas, responder en sus consultas de soporte | El alumno ya le pagó a la academia; no debe perder su curso por una deuda ajena |
| Libro de Reclamaciones: registrar, consultar y responder | Tiene plazo legal |

En cuanto la plataforma confirma el pago (o asigna un plan desde la ficha), **la academia vuelve a operar en ese momento**.

Una academia "Sin plan" (las anteriores a los planes, o de cortesía) no tiene tope ni vencimiento: nada de esto le aplica.

### Lo que se ve

- **En la academia:** aviso fijo para quien ve la Configuración, en tres casos: días de gracia (ámbar, con los días que quedan), solo lectura (rojo) y cupo lleno (ámbar). La tarjeta "Tu plan" explica lo mismo con las fechas. En "Mi plan", el plan vencido aparece marcado como "Tu plan" con el botón "Renovar este plan".
- **En la consola de plataforma:** la lista de academias marca "Solo lectura" en rojo o "Gracia: quedan N días"; la ficha de la academia lo explica con fechas.
- Quien intenta un cambio en solo lectura recibe el mensaje: «La academia está en solo lectura porque su plan venció: se puede consultar todo, pero no registrar cambios hasta renovarlo».

## Detalle técnico

- **Sin migraciones.** El estado se deduce de las fechas que ya existían.
- Variable de entorno nueva y opcional: `BILLING_GRACE_DAYS` (7 por defecto).
- `TenantSubscription::access()` devuelve `FULL`, `GRACE` o `READ_ONLY`; la API de suscripción suma `access`, `grace_ends_at` y `grace_days_left`.
- Cupo: `Enrollments\Application\EnrollmentQuota` (interfaz) con la implementación `Billing\Application\PlanQuota`, enlazadas en `AppServiceProvider`; Matrículas no depende de Planes. `EnrollmentService::enroll()` lo exige salvo para las compras; `OrderService::checkout()` lo revisa antes de crear la orden. La fila de la suscripción hace de candado para que dos matrículas simultáneas no pasen el tope.
- Solo lectura: middleware `Billing\Http\Middleware\EnforcePlanAccess`, aplicado a toda la API. Solo actúa en peticiones de una academia que modifican algo; la lista de lo permitido está en el propio archivo.
- Códigos de error nuevos: `STUDENT_LIMIT_REACHED` (409) y `PLAN_READ_ONLY` (403).
- Sin dependencias nuevas, sin permisos nuevos, sin rutas nuevas.

## Qué se verificó

- **284 pruebas del backend pasan** (eran 280). Pruebas nuevas (4):
  - cupo lleno: rechaza a los nuevos por matrícula y por participantes, deja entrar a un segundo curso a quien ya es alumno y vuelve a aceptar al liberar un lugar;
  - cupo lleno en la venta: rechaza la compra y la inscripción gratuita de quien no es alumno, permite comprar a quien ya lo es y respeta la orden hecha antes;
  - vencimiento: prueba vencida → gracia (todo funciona) → solo lectura (no crea cursos, no matricula, no registra cuentas; la alumna sí completa un recurso; la academia pide su plan) → pago confirmado → vuelve a operar;
  - una academia sin plan no tiene tope ni vencimiento aunque pasen 60 días.
- Una prueba existente de cupones saltaba a diciembre y caía en solo lectura: ahora deja la prueba gratuita vigente antes del salto.
- Frontend: compila, sin errores de tipos ni de estilo.
- **En el navegador, con la academia demo** puesta a mano en cada situación: aviso de gracia ("Quedan 4 días"), rechazo del alumno 22 con el cupo en 21, aviso rojo y tarjeta de solo lectura, rechazo al crear un curso y lectura normal, y "Solo lectura" en la lista de la consola. Al terminar, la demo volvió a "Sin plan".

**No se probó:** la importación desde Excel y la asignación de licencias con el cupo lleno (pasan por el mismo servicio que sí se probó, pero no tienen prueba propia); los cinco recorridos de Playwright; el aviso en teléfono.

## Qué quedó corto

- **El aviso fijo solo lo ve quien administra la configuración.** El resto del personal se entera al intentar un cambio, por el mensaje de error; los botones no se ocultan ni se deshabilitan en solo lectura.
- **Órdenes pendientes y cupo:** el cupo se revisa al comprar. Si varios compran cuando queda un lugar y todos pagan, la academia puede quedar por encima del tope (los pagados se respetan). No se reservan lugares para órdenes pendientes.
- **Bajar de plan con más alumnos que el tope nuevo** se permite: nadie pierde el acceso, solo no entran más hasta que baje el número.
- **Sin avisos por correo** del vencimiento, la gracia o el cupo (depende de la decisión 3). Tampoco hay aviso previo dentro del sistema antes de vencer, salvo la tarjeta "Tu plan" en Configuración.
- **No hay reporte en la consola** de "academias por vencer esta semana": se ve academia por academia en la lista.
- Renovar durante la gracia empieza el periodo el día del pago: los días de gracia usados no se descuentan.
- Los días de gracia son los mismos para toda la plataforma (variable de entorno); no se cambian desde la consola ni por academia.

## Decidido sin preguntar (se puede cambiar)

1. **En solo lectura los alumnos siguen estudiando** (rinden, entregan, completan). La alternativa era congelar también al alumno; se descartó porque el alumno ya pagó a la academia.
2. **El Libro de Reclamaciones sigue completo en solo lectura**, también la respuesta del personal, por el plazo legal.
3. **El registro público de cuentas se detiene en solo lectura**; con el cupo lleno no (crear la cuenta no ocupa lugar; lo ocupa la matrícula).
4. **Una orden de compra hecha antes de llenarse el cupo se respeta al pagarse**, aunque deje a la academia por encima del tope.
5. **Las licencias de empresa también respetan el cupo** al asignarse a un trabajador, aunque la empresa ya las haya pagado: la academia debe ampliar su plan para entregarlas.
6. **Matricular a varios es todo o nada** cuando no caben todos.
7. Al comprador no se le menciona el plan de la academia; al personal sí.
