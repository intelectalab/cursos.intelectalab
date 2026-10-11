// Contenido del manual de uso por rol. Es la fuente del documento de Word:
// se edita aquí y se vuelve a generar con `node generar.js` (ver LEEME.md).
//
// Cada capítulo tiene bloques:
//   ['p', texto]                 párrafo
//   ['h', título]                subtítulo dentro del capítulo
//   ['pasos', [..]]              lista numerada
//   ['lista', [..]]              lista con viñetas
//   ['nota', texto]              recuadro de aviso
//   ['tabla', [cabeceras], [[fila], ..], [anchos en %]]

module.exports = {
  titulo: 'Manual de uso por rol',
  producto: 'ERP SaaS IntelectaLab · Plataforma de cursos virtuales',
  version: '1.6',
  fecha: '11 de octubre de 2026',
  // Qué cambió en cada versión del manual: se agrega una fila con cada cambio del sistema
  historial: [
    ['1.0', '10/10/2026', 'Primera versión. Cubre lo construido hasta la entrega 3 de pagos (reembolsos).'],
    ['1.1', '10/10/2026', 'Pago en línea: la orden comprueba sola si ya se pagó y tiene el enlace «Comprobar pago». Nota sobre las cuentas de prueba de Mercado Pago.'],
    ['1.2', '10/10/2026', 'Botón «Exportar a Excel» en Reportes, Ventas y pagos, Alumnos, Matrículas y Prospectos.'],
    ['1.3', '10/10/2026', 'Páginas: paso de revisión. Quien redacta envía a revisión; quien publica aprueba o devuelve con observaciones.'],
    ['1.4', '10/10/2026', 'Certificados: editor visual para mover y editar cada elemento del diseño.'],
    ['1.5', '10/10/2026', 'Certificados: varias plantillas con una por curso; el certificado emitido conserva su diseño.'],
    ['1.6', '11/10/2026', 'Tipo de cambio manual o automático (SUNAT) con historial. Tipografía caligráfica en el editor de certificados.'],
  ],

  capitulos: [
    {
      titulo: 'Cómo usar este manual',
      bloques: [
        ['p', 'Este manual explica qué puede hacer cada persona en la plataforma según su rol, paso a paso y con los mismos nombres que aparecen en pantalla. Busca el capítulo de tu rol; si tienes más de un rol, te sirven los capítulos de cada uno.'],
        ['tabla', ['Si eres…', 'Lee el capítulo'], [
          ['Alumno o comprador de un curso', 'Alumno'],
          ['Docente de uno o más cursos', 'Profesor'],
          ['Quien administra la academia', 'Administrador'],
          ['Quien atiende consultas', 'Soporte'],
          ['Quien vende a personas y empresas', 'Ventas / CRM'],
          ['Quien administra las cuentas de empresas y sus licencias', 'B2B autorizado'],
          ['El contacto de una empresa cliente', 'Responsable de empresa'],
          ['Del equipo de IntelectaLab', 'Super administrador de la plataforma'],
          ['Alguien sin cuenta', 'Visitante'],
        ], [45, 55]],
        ['nota', 'Los nombres entre comillas angulares, como «Ventas y pagos», son los textos exactos del menú o de los botones.'],
      ],
    },

    {
      titulo: 'Lo básico para todos',
      bloques: [
        ['h', 'La academia y su dirección'],
        ['p', 'Cada academia tiene su propia dirección web (un subdominio, por ejemplo demo.dominio). Todo lo que se hace en esa dirección pertenece solo a esa academia: sus cursos, sus alumnos y sus ventas no se mezclan con los de otra.'],
        ['h', 'Entrar y salir'],
        ['pasos', [
          'Abre la dirección de tu academia.',
          'Escribe tu correo y tu contraseña y pulsa «Iniciar sesión».',
          'Para salir, usa el botón de cerrar sesión, arriba a la derecha.',
        ]],
        ['lista', [
          'Si no usas el sistema durante 30 minutos, la sesión se cierra sola (cada academia puede cambiar ese tiempo).',
          'Tras 10 intentos fallidos seguidos, la cuenta se bloquea 15 minutos.',
          'Si te dieron una contraseña temporal, el sistema te pedirá cambiarla antes de dejarte hacer cualquier otra cosa.',
          'Desde tu cuenta puedes cambiar tu contraseña y ver o cerrar tus sesiones abiertas en otros equipos.',
        ]],
        ['nota', '«Olvidé mi contraseña» funciona, pero hoy el sistema todavía no envía correos: mientras no haya proveedor de correo, pide al administrador de tu academia una contraseña nueva.'],
        ['h', 'El menú'],
        ['p', 'El menú de la izquierda muestra solo lo que tu rol puede usar. En el teléfono se abre con el botón de menú. Tiene tres grupos: General (lo tuyo), Operación (el trabajo de la academia) y Administración.'],
        ['h', 'Tarjetas, filtros y ayudas'],
        ['lista', [
          'En casi todos los listados hay tarjetas con cifras arriba (por ejemplo «Pendientes de pago»). Al tocarlas, el listado se filtra.',
          'El signo de interrogación junto a un filtro o una tarjeta explica qué significa.',
          'Las casillas de la izquierda permiten elegir varias filas y actuar sobre todas; la casilla de arriba elige todas las de la página.',
        ]],
        ['h', 'Qué ve cada rol en el menú'],
        ['tabla', ['Opción del menú', 'Quién la ve'], [
          ['Inicio', 'Todos'],
          ['Mis cursos, Cursos disponibles, Mis compras, Mis certificados', 'Alumno (y el Administrador)'],
          ['Ayuda', 'Alumno, Responsable de empresa'],
          ['Mi empresa', 'Responsable de empresa'],
          ['Cursos', 'Administrador; Profesor (solo sus cursos)'],
          ['Alumnos', 'Administrador, Soporte, Ventas/CRM'],
          ['Docentes', 'Administrador'],
          ['Matrículas', 'Administrador, Soporte (solo consulta)'],
          ['Banco de preguntas', 'Administrador, Profesor'],
          ['CRM y clientes', 'Administrador, Ventas/CRM, B2B autorizado'],
          ['Ventas y pagos', 'Administrador, Ventas/CRM, Soporte (solo consulta)'],
          ['Promociones', 'Administrador; Ventas/CRM (solo consulta)'],
          ['Certificados', 'Administrador'],
          ['Soporte', 'Administrador, Soporte'],
          ['Reclamaciones', 'Administrador; Soporte (solo consulta)'],
          ['Reportes', 'Administrador'],
          ['Usuarios, Roles y permisos, Configuración, Páginas, Auditoría', 'Administrador'],
        ], [55, 45]],
        ['p', 'Cada academia puede crear roles propios y darles los permisos que quiera; quien tenga un rol propio verá en el menú lo que ese rol permita.'],
      ],
    },

    {
      titulo: 'Alumno',
      bloques: [
        ['p', 'El alumno compra o recibe cursos, los estudia, rinde sus actividades y obtiene su certificado.'],
        ['h', 'Comprar un curso'],
        ['pasos', [
          'Entra a «Cursos disponibles» (o al catálogo público de la academia) y abre el curso. Puedes buscar por texto y filtrar por categoría, nivel y precio.',
          'Pulsa el botón de compra. Si tienes un cupón, escríbelo: el sistema te dice cuánto descuenta. Solo se usa un cupón por compra.',
          'Confirma. Se crea una orden y la ves en «Mis compras».',
          'Paga. Si la academia tiene pago en línea, verás «Pagar con Mercado Pago» o «Pagar con PayPal»: te lleva a la página del medio de pago y, al volver, tu matrícula queda activa. PayPal cobra en dólares y te muestra cuánto antes de salir.',
          'Si pagaste y la orden sigue «Pendiente de pago», ábrela desde «Mis compras»: al abrirla comprueba el pago. También puedes pulsar «Comprobar pago». No pagues dos veces.',
          'Si pagas por fuera, sigue las instrucciones de pago que aparecen en la orden. Tu acceso se activa cuando la academia confirma el pago.',
        ]],
        ['lista', [
          'Un curso gratuito te matricula al instante, sin pago.',
          'La orden tiene un plazo para pagarse (72 horas, salvo que la academia lo cambie). Pasado el plazo vence y debes generar otra.',
          'Puedes cancelar una orden mientras esté pendiente. Si ya pagaste, no la canceles.',
          'Un paquete es un conjunto de cursos a un solo precio: una compra te matricula en todos.',
          'Si la academia te reembolsa una compra, la orden figura como «Reembolsada» y pierdes el acceso a ese curso.',
        ]],
        ['nota', 'Si ves el mensaje «Por ahora la academia no está recibiendo nuevos alumnos», la academia llegó al tope de alumnos de su plan: comunícate con ella.'],
        ['h', 'Estudiar un curso'],
        ['pasos', [
          'Entra a «Mis cursos» y abre el curso. Ves tu avance en porcentaje.',
          'Recorre las secciones: videos, documentos, enlaces y textos. Un recurso cuenta como completado al abrirlo.',
          'Si el curso es secuencial, una sección se abre recién cuando completas las anteriores.',
          'Si tu acceso tiene fecha de vencimiento, la ves en el curso. Vencido el acceso, pide una extensión a la academia.',
        ]],
        ['h', 'Rendir actividades'],
        ['lista', [
          'Cuestionario: tiene un número de intentos (3, salvo que el docente indique otro) y puede tener tiempo límite. Si el tiempo se acaba, se califica lo que hayas guardado. Cuenta tu mejor intento. Las respuestas correctas se muestran al aprobar o al agotar los intentos.',
          'Tarea: sube tu archivo antes del plazo. Si entregas tarde queda marcada como tardía. El docente puede devolverla con un comentario para que la rehagas. Una tarea ya calificada no se reemplaza.',
          'Encuesta: se responde una sola vez y tus respuestas no llevan tu nombre.',
        ]],
        ['h', 'Mis notas y mi certificado'],
        ['lista', [
          'En el curso ves «Mis notas»: la nota de cada actividad y tu nota final. Lo que aún no tiene nota cuenta como cero.',
          'Si el curso entrega certificado, lo recibes al completar el 100 % (y, si el curso lo exige, al alcanzar la nota mínima).',
          'En «Mis certificados» lo descargas en PDF. Tiene un código y un QR que cualquiera puede verificar en la página pública de la academia, y un enlace para añadirlo a tu perfil de LinkedIn.',
        ]],
        ['h', 'Pedir ayuda'],
        ['pasos', [
          'Entra a «Ayuda» y crea una consulta: elige la categoría, escribe tu mensaje y adjunta hasta 3 archivos si hace falta.',
          'Verás las respuestas del equipo en la misma consulta y podrás responder.',
          'Cuando esté resuelta puedes cerrarla. Si nadie responde en 7 días después de resuelta, se cierra sola.',
        ]],
      ],
    },

    {
      titulo: 'Profesor',
      bloques: [
        ['p', 'El profesor trabaja solo sobre los cursos en los que está asignado como docente: edita su contenido, arma las actividades y califica a sus alumnos.'],
        ['h', 'Mis cursos como docente'],
        ['p', 'En «Cursos» ves únicamente tus cursos asignados. Al abrir uno tienes sus pestañas: «Contenido», «Información», «Participantes», «Seguimiento» y «Versiones».'],
        ['h', 'Editar el contenido'],
        ['pasos', [
          'En «Contenido», crea o edita secciones y agrega recursos: video (enlace de YouTube), documento, enlace o texto.',
          'Agrega actividades a una sección: cuestionario, tarea o encuesta.',
          'Lo que cambias queda como borrador: los alumnos no lo ven hasta que el curso se publique otra vez. Publicar lo hace el Administrador.',
        ]],
        ['nota', 'Un video de YouTube «No listado» lo puede ver cualquiera que tenga el enlace, aunque no esté matriculado.'],
        ['h', 'Banco de preguntas y cuestionarios'],
        ['pasos', [
          'En «Banco de preguntas», crea un banco y agrega preguntas (hay siete tipos, todos de calificación automática). También puedes importarlas desde un Excel con la plantilla que se descarga ahí mismo.',
          'En el curso, crea un cuestionario y elige las preguntas del banco.',
          'Define intentos, tiempo límite, nota para aprobar y el peso que tiene en la nota final.',
        ]],
        ['h', 'Calificar y dar seguimiento'],
        ['lista', [
          'En «Seguimiento» ves a cada alumno con su avance y sus notas, y el libro de notas del curso, que se descarga en Excel.',
          'Tareas: abre la entrega, pon la nota y un comentario; o devuélvela con un comentario para que el alumno la rehaga (devolver borra la nota).',
          'Cuestionarios: puedes ver el detalle de cada intento y dar intentos adicionales a un alumno.',
          'Con «vista previa» ves el aula como la ve el alumno, de lo publicado o del borrador.',
        ]],
        ['p', 'La nota final es el promedio de cuestionarios y tareas según su peso (peso 1 por defecto; con peso 0 la actividad no cuenta).'],
      ],
    },

    {
      titulo: 'Administrador',
      bloques: [
        ['p', 'El Administrador tiene acceso total dentro de su academia. Este capítulo recorre el menú en el orden habitual de trabajo.'],

        ['h', 'Poner en marcha la academia: «Configuración»'],
        ['lista', [
          '«Organización»: nombre, razón social, RUC y logo.',
          '«Región y monedas»: zona horaria y moneda principal (soles o dólares). Los precios de los cursos se cobran en la moneda principal.',
          '«Datos de contacto».',
          '«Calificaciones»: la escala de notas (0 a 20 por defecto); puedes guardar otras escalas y elegir cuál usar.',
          '«Ventas»: las instrucciones de pago que ve el comprador cuando paga por fuera, y el plazo para pagar una orden.',
          'Plazo de respuesta del Libro de Reclamaciones y tiempo de cierre de sesión por inactividad.',
          '«Cobros en línea», «Tu plan» y los feriados, que se explican más abajo.',
        ]],

        ['h', 'Tipo de cambio'],
        ['p', 'Son los soles por dólar con los que se cobra en dólares lo que vendes en soles. Hoy lo usa PayPal.'],
        ['lista', [
          '«Manual»: lo escribes tú en «Tipo de cambio (soles por dólar)» y pulsas «Registrar». Vale hasta que lo cambies.',
          '«Automático: SUNAT»: cada mañana se toma el tipo de cambio oficial del día. «Actualizar ahora» lo trae en el momento. Si un día la fuente no responde, se conserva el último valor.',
          '«Recargo»: en automático puedes sumar un porcentaje para cubrir la comisión de la pasarela. Con 2 %, lo que se cobraría como US$ 100 se cobra como US$ 102.',
          'La pantalla muestra el valor vigente, de qué día es y cuántos dólares se cobran por S/ 100, y debajo el historial con cada valor y quién lo registró.',
          'Si aparece «Sin cambios hace más de 3 días», revísalo: en manual toca actualizarlo; en automático puede que la fuente esté fallando.',
        ]],

        ['h', 'Cobros en línea (Mercado Pago y PayPal)'],
        ['p', 'Sirve para que tus alumnos paguen al momento, con tu propia cuenta. El dinero llega a tu cuenta de la pasarela; la plataforma no lo toca.'],
        ['pasos', [
          'En «Configuración», baja a «Cobros en línea» y pulsa «Conectar» en la pasarela.',
          'Mercado Pago: pega el Access Token de tu aplicación. PayPal: pega el Client ID y el Secret, y marca la casilla si son claves de prueba.',
          'Pulsa «Comprobar y conectar». El sistema verifica las claves con la pasarela; si no son válidas, no las guarda.',
          'Solo PayPal: necesita el tipo de cambio de la academia. Se define en «Tipo de cambio», en la misma pantalla de Configuración. Sin él, PayPal no se ofrece.',
        ]],
        ['lista', [
          'Las claves se guardan cifradas y no se vuelven a mostrar.',
          'Puedes «Pausar» (deja de ofrecerse), «Cambiar claves» o «Desconectar».',
          'Mercado Pago cobra en soles (tarjeta, Yape o saldo). PayPal cobra en dólares.',
          'El pago por fuera sigue disponible junto al pago en línea.',
          'Para probar con Mercado Pago necesitas dos cuentas de prueba: la vendedora (su Access Token es el que conectas) y una compradora para pagar. Mercado Pago no deja pagar con la misma cuenta que cobra: el botón «Pagar» queda gris.',
        ]],
        ['nota', 'El pago en línea está construido pero todavía no se ha probado con cuentas reales de las pasarelas. Antes de usarlo con alumnos, haz un pago de prueba.'],

        ['h', 'Tu plan con IntelectaLab: «Mi plan»'],
        ['p', 'La academia paga a IntelectaLab un plan según su cantidad de alumnos. Cuenta como alumno cada persona con al menos una matrícula activa. Se entra desde «Configuración» → «Ver planes y pagos».'],
        ['pasos', [
          'Mira tu situación: prueba gratuita o plan, hasta cuándo, y cuántos alumnos usas de tu cupo.',
          'Pulsa «Elegir este plan» (o «Renovar este plan») y confirma. Se crea una orden.',
          'Paga: en línea, si IntelectaLab tiene la opción activa, o por fuera con las instrucciones que aparecen.',
          'Si pagaste por fuera, pulsa «Adjuntar constancia» (foto o PDF del voucher, hasta 2 MB) o escribe cómo ubicar tu pago y pulsa «Informar pago».',
          'Cuando IntelectaLab confirma, verás un aviso arriba de la pantalla y el plan queda vigente.',
        ]],
        ['tabla', ['Situación', 'Qué pasa'], [
          ['Prueba gratuita', '14 días y hasta 10 alumnos, al crear la academia.'],
          ['Cupo lleno', 'No se puede matricular ni vender a personas nuevas. Los que ya están siguen entrando.'],
          ['Venció la prueba o el plan', '7 días de gracia: todo funciona, con un aviso que cuenta los días.'],
          ['Pasó la gracia', 'Solo lectura: se consulta todo y los alumnos siguen estudiando, pero no se registran cambios hasta renovar.'],
          ['Orden de plan sin pagar', 'Vence a los 7 días, salvo que ya hayas informado un pago.'],
        ], [32, 68]],

        ['h', 'Usuarios, roles y grupos'],
        ['lista', [
          '«Usuarios»: crea personas, asígnales uno o más roles, desactívalas o cierra sus sesiones. La contraseña inicial la pones tú.',
          'Pestaña «Grupos»: un grupo es una lista de personas con nombre que puedes matricular completa en un curso.',
          '«Roles y permisos»: los siete roles base son fijos. Puedes crear roles propios eligiendo permisos por módulo. Nadie puede dar un permiso que no tiene.',
          'La academia siempre conserva al menos un administrador activo.',
        ]],
        ['h', 'Importar personas desde Excel'],
        ['pasos', [
          'Descarga la plantilla, llénala y súbela. Puedes indicar el curso y el grupo de cada persona.',
          'Revisa la vista previa: muestra qué se creará y qué filas tienen errores.',
          'Confirma. Cada persona nueva recibe como contraseña temporal su número de documento y deberá cambiarla al entrar.',
        ]],

        ['h', 'Cursos'],
        ['pasos', [
          'En «Cursos», crea el curso: título, resumen, descripción, portada, categoría, nivel, horas e idioma.',
          'En «Contenido», arma las secciones, los recursos y las actividades.',
          'En «Información», define el precio (0 = gratuito; vacío = no se vende), los días de acceso, si entrega certificado, la nota mínima para aprobar, si el avance es secuencial y los docentes.',
          'Pulsa «Publicar». Cada publicación guarda una versión numerada; los cambios posteriores no se ven hasta volver a publicar.',
          'En «Participantes», agrega alumnos y docentes: de uno en uno, varios a la vez o un grupo entero.',
        ]],
        ['lista', [
          'Archivar un curso impide matrículas nuevas; quienes ya estaban matriculados conservan su acceso.',
          '«Seguimiento» muestra avance, notas y el libro de notas en Excel.',
        ]],

        ['h', 'Alumnos, docentes y matrículas'],
        ['lista', [
          '«Alumnos»: lista con buscador y la ficha completa de cada uno (cursos, compras, certificados y consultas).',
          '«Docentes»: lista y ficha con sus cursos, alumnos y entregas por calificar.',
          '«Matrículas»: todas las matrículas, con su vigencia. Puedes extender, acortar o quitar el vencimiento, y cancelar. El filtro «Por vencer» muestra las que vencen en los próximos días.',
        ]],

        ['h', 'Ventas y pagos'],
        ['p', 'Aquí están todas las órdenes: pendientes, pagadas, vencidas, canceladas y reembolsadas.'],
        ['pasos', [
          'Para un pago por fuera: abre la orden, pulsa «Confirmar pago», indica el medio y el número de operación, y confirma. El comprador queda matriculado.',
          'Para cancelar una orden que no se va a pagar: «Cancelar orden».',
          'Para devolver una compra: abre la orden pagada, pulsa «Reembolsar» y escribe el motivo.',
        ]],
        ['lista', [
          'Reembolso de un pago en línea: el sistema devuelve el importe completo por la misma pasarela.',
          'Reembolso de un pago por fuera: tú devuelves el dinero y aquí lo registras.',
          'En ambos casos el comprador pierde la matrícula de esa compra y la orden deja de contar como ingreso. No se puede deshacer.',
          'Si aparece una alerta de «cobro que no se aplicó», la pasarela cobró algo que no coincide con la orden: revísalo en tu cuenta de la pasarela.',
          'Las ventas a empresas y las inscripciones gratuitas no se reembolsan desde aquí.',
        ]],

        ['h', 'Promociones y paquetes'],
        ['lista', [
          '«Promociones y cupones»: crea una promoción (porcentaje o monto fijo) con sus límites opcionales: vigencia, usos totales, una vez por persona y compra mínima. Cada promoción puede tener varios cupones.',
          'Los descuentos no se acumulan: un cupón por compra.',
          '«Paquetes»: varios cursos a un precio; se muestra el ahorro frente a comprarlos por separado.',
        ]],

        ['h', 'Certificados'],
        ['lista', [
          'El certificado se emite solo cuando el alumno cumple los requisitos del curso. También puedes emitirlo a mano o emitir todos los pendientes.',
          'Puedes anular un certificado con un motivo; al verificarlo figurará como no válido.',
          'Cada plantilla tiene su firmante, firma, fondo y diseño, con vista previa. Un certificado ya emitido conserva el diseño con el que salió: cambiar la plantilla después no lo altera.',
          'Plantillas: en «Plantilla» eliges cuál editar. La principal la usan todos los cursos que no eligieron otra. Con «Nueva plantilla» creas otra (en blanco o como copia) y marcas en «Cursos que usan esta plantilla» a cuáles se aplica. «Hacer principal» la cambia; la principal no se elimina.',
        ]],
        ['h', 'Editor visual del certificado'],
        ['p', 'Si la lista de opciones no te alcanza, el editor visual te deja colocar cada elemento a mano. Mientras no guardes un diseño ahí, vale el de la lista de opciones.'],
        ['pasos', [
          'En «Certificados» pulsa «Plantilla» y luego «Probar el editor visual». Se abre la hoja con el diseño de siempre, listo para moverlo.',
          'Arrastra cada elemento a su sitio. Cerca del centro de la hoja se imanta y aparece una guía. Con las flechas del teclado lo mueves medio milímetro.',
          'Toca un elemento para cambiar su tipografía, tamaño, color, negrita, cursiva, mayúsculas y alineación en el panel de la derecha. Tira del cuadradito del borde para cambiar su tamaño.',
          'Agrega lo que falte con los botones de arriba: un dato del certificado, un texto libre, el logo, la firma o una imagen propia (un sello, otro logo).',
          'Elige la hoja: A4 horizontal o vertical. Al cambiarla, la hoja empieza de nuevo.',
          'Pulsa «Vista previa en PDF» para ver el resultado exacto, y «Guardar diseño» cuando te guste.',
        ]],
        ['lista', [
          'El código de verificación y el QR se pueden mover y achicar, pero no quitar.',
          'El fondo y la firma se cargan en «Plantilla», no en el editor.',
          '«Volver al diseño por opciones» borra el diseño libre y regresa al anterior.',
          'No hay «deshacer»: si te equivocas, usa «Empezar de nuevo» o sal sin guardar.',
          'Hay siete tipografías. «Great Vibes» es caligráfica, pensada para el nombre del alumno; no tiene negrita ni cursiva.',
        ]],

        ['h', 'Soporte y reclamaciones'],
        ['lista', [
          '«Soporte»: bandeja con las consultas. Responde, cambia el estado, pon prioridad y deja notas internas que el usuario no ve.',
          '«Reclamaciones»: las hojas del Libro de Reclamaciones que registran los consumidores desde la página pública. Cada una tiene un código y un plazo de respuesta en días hábiles (descuenta los feriados que registres en Configuración). La respuesta es una sola.',
        ]],

        ['h', 'Reportes, páginas y auditoría'],
        ['lista', [
          '«Reportes»: ingresos, órdenes, matrículas, alumnos activos, cursos completados y certificados de un periodo, comparados con el periodo anterior.',
          '«Exportar a Excel»: está en Reportes, Ventas y pagos, Alumnos, Matrículas y Prospectos. El archivo trae lo que ves en pantalla: si pusiste una búsqueda o un filtro, baja solo eso (hasta 5 000 filas). En Reportes baja el periodo elegido, con una hoja por bloque.',
          '«Páginas»: los textos públicos de la academia (términos, privacidad, preguntas frecuentes). Se editan como borrador, se ven en vista previa y se publican con versiones. Editar y publicar son permisos separados.',
          'Revisión de páginas: quien solo puede editar pulsa «Enviar a revisión». Tú, que publicas, abres la página (en la lista sale «En revisión»), la miras en la vista previa y pulsas «Publicar» para aprobarla o «Devolver con observaciones» para escribir qué corregir. Si el borrador cambia después de enviado, hay que enviarlo de nuevo. Para tener redactores, crea en «Roles» un rol con «Ver páginas» y «Editar páginas».',
          '«Auditoría»: el registro de las operaciones sensibles: quién hizo qué y cuándo.',
        ]],

        ['h', 'Venta a empresas'],
        ['p', 'El Administrador puede hacer todo lo que se describe en los capítulos «Ventas / CRM» y «B2B autorizado».'],
      ],
    },

    {
      titulo: 'Soporte',
      bloques: [
        ['p', 'Soporte atiende las consultas de alumnos, compradores y empresas, y puede consultar lo necesario para ayudarlos, sin modificarlo.'],
        ['h', 'Atender consultas'],
        ['pasos', [
          'Entra a «Soporte». Las tarjetas de arriba filtran por estado.',
          'Abre una consulta, lee los mensajes y responde. Puedes adjuntar archivos.',
          'Cambia el estado según avance: Abierto, En atención, Resuelto, Cerrado.',
          'Usa las notas internas para dejar información al equipo; el usuario no las ve.',
        ]],
        ['lista', [
          'Si el usuario responde una consulta resuelta, se reabre sola.',
          'Una consulta resuelta sin respuesta se cierra sola a los 7 días. Cerrada, ya no se reabre.',
        ]],
        ['h', 'Lo que puedes consultar'],
        ['lista', [
          '«Alumnos»: la ficha completa de cada alumno.',
          '«Matrículas» y «Ventas y pagos»: solo lectura.',
          '«Reclamaciones»: puedes verlas, pero no responderlas.',
        ]],
      ],
    },

    {
      titulo: 'Ventas / CRM',
      bloques: [
        ['p', 'Ventas lleva la relación comercial con empresas: desde el primer contacto hasta la cotización aceptada. Todo está en «CRM y clientes».'],
        ['h', 'Del prospecto a la venta'],
        ['pasos', [
          'Pestaña «Prospectos»: registra a quien mostró interés, o impórtalos desde Excel con la plantilla.',
          'Convierte el prospecto: se crea la empresa con su contacto y una oportunidad. O descártalo.',
          'Pestaña «Oportunidades»: mueve cada oportunidad por las etapas del embudo y registra las actividades (llamadas, reuniones).',
          'Pestaña «Cotizaciones»: arma la cotización con los cursos, las cantidades y el precio por licencia.',
          'Márcala como enviada cuando se la hagas llegar a la empresa. Tiene 15 días de vigencia.',
          'La empresa la acepta desde su portal, o registras tú la aceptación. Al aceptarse nace la orden de venta.',
        ]],
        ['lista', [
          'Si la empresa pide cambios, crea una nueva versión de la cotización. La venta conserva la versión y los precios aceptados.',
          'Una empresa pasa a ser cliente con su primera venta.',
          'También puedes consultar «Alumnos», «Ventas y pagos» y «Promociones».',
        ]],
        ['nota', 'Confirmar el pago de la venta y administrar las licencias no lo hace Ventas: lo hacen el Administrador y el rol B2B autorizado.'],
      ],
    },

    {
      titulo: 'B2B autorizado',
      bloques: [
        ['p', 'Administra las cuentas de las empresas cliente y sus licencias. Una licencia es un cupo de una persona en un curso.'],
        ['h', 'Cuentas corporativas'],
        ['lista', [
          'En «CRM y clientes», pestaña «Empresas», abre la empresa para ver su cuenta: licencias compradas, asignadas y disponibles, y sus personas.',
          'Registra a las personas de la empresa, incluido su responsable.',
          'Asigna una licencia a un trabajador: queda matriculado en el curso. Se puede retirar solo si aún no empezó.',
          'Las licencias se entregan cuando se confirma el pago completo de la venta, y hay 12 meses para asignarlas.',
          'También gestionas las cotizaciones de la empresa.',
        ]],
        ['nota', 'Entregar licencias antes del pago («Entregar licencias sin pago», con motivo) solo lo puede hacer el Administrador.'],
      ],
    },

    {
      titulo: 'Responsable de empresa',
      bloques: [
        ['p', 'Es el contacto de una empresa cliente. Entra a la academia con su cuenta y ve solo lo de su empresa, en «Mi empresa».'],
        ['pasos', [
          'Revisa tus cotizaciones y acéptalas o recházalas.',
          'Registra a tus trabajadores. Reciben una contraseña temporal que deben cambiar al entrar.',
          'Asigna las licencias disponibles a tus trabajadores: quedan matriculados en el curso.',
          'Sigue el avance de cada uno y si ya obtuvo su certificado.',
        ]],
        ['lista', [
          'No ves las notas de tus trabajadores: solo avance y certificado.',
          'Puedes retirar una asignación si el trabajador aún no empezó el curso.',
          'Con «Ayuda» haces consultas al equipo de la academia.',
        ]],
      ],
    },

    {
      titulo: 'Super administrador de la plataforma',
      bloques: [
        ['p', 'Es el equipo de IntelectaLab. Entra a la consola en el dominio central (/platform), con una cuenta distinta de las de las academias. La consola tiene tres secciones: «Academias», «Planes» y «Pagos».'],
        ['h', 'Academias'],
        ['lista', [
          'Cifras de toda la plataforma y la lista de academias con usuarios, alumnos, cursos, ventas del mes, plan y último acceso.',
          '«Nueva academia»: crea la academia con su primer administrador, que entra con contraseña temporal.',
          '«Ficha»: datos editables, cifras y ventas de seis meses, administradores (con restablecer contraseña), historial y su plan.',
          '«Entrar»: abre la academia en modo soporte, en solo lectura y por 30 minutos. Queda registrado en la auditoría de la academia.',
          '«Suspender» exige un motivo: nadie puede entrar a una academia suspendida.',
        ]],
        ['p', 'En la columna del plan se ve «Solo lectura» o «Gracia: quedan N días» cuando una academia está vencida.'],
        ['h', 'Planes'],
        ['lista', [
          'Crea y edita los planes: nombre, precio, mensual o anual y tope de alumnos. Un plan archivado ya no se ofrece, pero quien lo tiene lo conserva.',
          'Escribe las instrucciones de pago que verán las academias que pagan por fuera.',
          '«Cobros en línea»: conecta la cuenta de Mercado Pago o de PayPal de IntelectaLab (con su tipo de cambio) para que las academias paguen su plan al momento.',
          'Desde la ficha de una academia puedes asignarle un plan a mano, extender su prueba gratuita o dejarla «Sin plan» (sin tope ni vencimiento).',
        ]],
        ['h', 'Pagos'],
        ['p', 'La pestaña muestra un contador con las órdenes que esperan; se pone en ámbar cuando alguna academia ya informó su pago o hay un cobro por revisar.'],
        ['pasos', [
          'Abre «Pagos». Las órdenes con «Pago informado» son las que hay que revisar primero.',
          'Pulsa «Ver constancia» si la academia adjuntó su voucher.',
          'Pulsa «Confirmar pago», indica el medio y el número de operación. El plan queda vigente en ese momento y la academia recibe el aviso.',
          'Si la orden no corresponde, pulsa «Cancelar» y escribe el motivo, que la academia verá.',
        ]],
        ['lista', [
          '«Deshacer»: si confirmaste por error un pago por fuera. El plan de la academia vuelve a como estaba y la orden vuelve a pendiente.',
          '«Devolver pago»: para una orden cobrada en línea. Devuelve el dinero por la pasarela, el plan vuelve a como estaba y la orden queda cancelada.',
          'Ninguna de las dos se permite si el plan de la academia cambió después de ese pago: se ajusta a mano desde su ficha.',
          'Las órdenes sin pagar vencen solas a los 7 días, salvo las que tienen un pago informado.',
        ]],
      ],
    },

    {
      titulo: 'Visitante (sin cuenta)',
      bloques: [
        ['lista', [
          'Catálogo público de la academia: cursos y paquetes en venta, con buscador y filtros. Muestra el temario, no el contenido.',
          'Crear una cuenta para comprar: debe aceptar los textos legales de la academia.',
          'Verificar un certificado con su código o su QR.',
          'Libro de Reclamaciones: registrar un reclamo o una queja y, después, consultar la respuesta con el código y el documento.',
          'Páginas de la academia: términos, privacidad y otras que publique.',
          'Registrar una academia nueva, desde el dominio central: empieza con la prueba gratuita.',
        ]],
      ],
    },

    {
      titulo: 'Mensajes frecuentes y qué hacer',
      bloques: [
        ['tabla', ['Mensaje', 'Qué significa', 'Qué hacer'], [
          ['Debes cambiar tu contraseña temporal', 'Entraste con una contraseña que te pusieron', 'Cámbiala; después podrás usar el sistema'],
          ['La academia está en solo lectura porque su plan venció', 'Pasaron los días de gracia', 'El administrador debe renovar en «Mi plan»'],
          ['La academia tiene N de los N alumnos que permite su plan', 'Cupo lleno', 'Pasar a un plan mayor o liberar matrículas'],
          ['Estás en modo soporte', 'Sesión del equipo de IntelectaLab', 'Solo se puede consultar'],
          ['Todavía no vemos tu pago', 'La pasarela aún no confirmó', 'Esperar unos minutos y pulsar «Volver a comprobar»'],
          ['La orden venció sin pago', 'Pasó el plazo', 'Generar una orden nueva'],
          ['El total cambió', 'El precio o el cupón cambiaron mientras comprabas', 'Revisar el nuevo importe y confirmar'],
          ['Completa las secciones anteriores para abrir esta', 'El curso es secuencial', 'Terminar las secciones anteriores'],
        ], [34, 30, 36]],
      ],
    },

    {
      titulo: 'Lo que todavía no está disponible',
      bloques: [
        ['p', 'Para que nadie lo busque en el sistema, esto aún no existe o no está en funcionamiento:'],
        ['lista', [
          'Envío de correos (avisos, recuperación de contraseña, invitaciones): los correos se generan, pero no salen hasta definir el proveedor.',
          'Pago en línea probado con cuentas reales: está construido, falta el primer pago de prueba.',
          'Comprobantes electrónicos (boletas, facturas, notas de crédito).',
          'Cursos en formato SCORM.',
          'Billetera, chat con inteligencia artificial y registro de asistencia.',
          'Reembolsos parciales y exportación de reportes (solo se exporta el libro de notas).',
          'Sincronización con Moodle y horarios según disponibilidad de los docentes.',
        ]],
      ],
    },
  ],
};
