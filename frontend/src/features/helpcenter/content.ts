/**
 * Contenido de la autoayuda: una ficha por pantalla (la clave es el id del
 * módulo en config/modules.tsx) y, en las pantallas con pestañas, una por
 * pestaña. Sale del manual de uso por rol (docs/manual/contenido.js): si
 * cambia una pantalla, se actualizan los dos.
 */

export interface HelpFaq {
  q: string;
  a: string;
}

export interface HelpSection {
  title: string;
  summary: string;
  steps?: string[];
  tips?: string[];
  faqs?: HelpFaq[];
}

export interface HelpTopic extends HelpSection {
  // Pestañas de la pantalla, por su id (?tab=… o el que registra la pantalla)
  sections?: Record<string, HelpSection>;
}

export const HELP_TOPICS: Record<string, HelpTopic> = {
  dashboard: {
    title: 'Inicio',
    summary: 'Tu punto de partida: un saludo, los accesos rápidos a los módulos de tu rol y «Pendientes», lo que espera tu atención.',
    steps: ['Revisa «Pendientes»: cada aviso lleva a la pantalla donde se atiende.', 'Usa los accesos rápidos para entrar a lo que más usas.', 'Las cifras y gráficos de la academia están en «Panel».'],
    tips: ['Los números amarillos del menú cuentan lo mismo que los pendientes.', 'Ctrl+K abre el asistente desde cualquier pantalla.'],
  },
  panel: {
    title: 'Panel',
    summary: 'Cifras y gráficos de la academia por periodo: académico, ventas, personas y atención. Cada pestaña se ve si tu rol puede entrar a su módulo.',
    steps: ['Elige el periodo arriba (este mes, mes pasado, un rango).', 'Cambia de pestaña para ver cada tema.', 'Descarga en Excel (datos) o PDF (gráficos).'],
    faqs: [{ q: '¿Por qué no veo la pestaña Ventas?', a: 'Cada pestaña exige el permiso de su módulo. Pide al Administrador que revise tu rol.' }],
  },
  'my-schedule': {
    title: 'Mi horario',
    summary: 'La semana que te armó el Administrador: horas disponibles, clases en vivo y revisión de trabajos.',
    steps: ['Revisa cada bloque de la semana.', 'Si está bien, pulsa «Confirmar».', 'Si algo no cuadra, pulsa «Pedir cambios» y explica qué cambiar.'],
    tips: ['Si el horario cambia, te pedirá confirmarlo de nuevo.'],
  },
  'my-courses': {
    title: 'Mis cursos',
    summary: 'Tus cursos con su avance. Al abrir uno entras al aula: lecciones, materiales, cuestionarios, tareas y clases en vivo.',
    steps: ['Abre el curso y sigue las secciones en orden.', 'Marca cada lección como vista; el avance sube solo.', 'Rinde los cuestionarios y entrega las tareas antes de su fecha.', 'Al completar el curso (y aprobar, si lo pide), tu certificado aparece en «Mis certificados».'],
    faqs: [
      { q: 'No puedo abrir una sección', a: 'El curso puede pedir avanzar en orden: termina la sección anterior.' },
      { q: 'Mi tarea fue devuelta', a: 'El docente te pide rehacerla: lee su comentario y vuelve a entregarla.' },
    ],
  },
  'available-courses': {
    title: 'Cursos disponibles',
    summary: 'Los cursos y paquetes que puedes comprar o en los que puedes inscribirte.',
    steps: ['Abre un curso para ver su temario y precio.', 'Pulsa «Comprar» (o «Inscribirme» si es gratis).', 'Si tienes un cupón, escríbelo antes de pagar.'],
  },
  'my-orders': {
    title: 'Mis compras',
    summary: 'Tus órdenes: las pendientes de pago, las pagadas, en cuotas o vencidas.',
    steps: ['Abre una orden pendiente para ver cómo pagarla.', 'Paga en línea (si la academia lo tiene) o sigue las instrucciones de pago por fuera.', 'Al confirmarse el pago quedas matriculado.'],
    faqs: [{ q: 'Pagué pero sigue pendiente', a: 'Si pagaste por fuera, la academia debe confirmarlo. Si pagaste en línea, abre la orden: se vuelve a consultar a la pasarela.' }],
  },
  'my-certificates': {
    title: 'Mis certificados',
    summary: 'Los certificados de los cursos que completaste, en PDF y con un código que cualquiera puede verificar.',
    steps: ['Descarga el PDF.', 'Comparte el enlace de verificación o el código QR.'],
  },
  company: {
    title: 'Mi empresa',
    summary: 'Para el responsable de una empresa cliente: licencias compradas, trabajadores, su avance y sus certificados.',
    steps: ['Asigna una licencia a cada trabajador: queda matriculado.', 'Sigue el avance de cada uno.', 'Descarga los certificados de quienes terminaron.'],
  },
  help: {
    title: 'Mis consultas',
    summary: 'Pide ayuda a la academia y sigue la respuesta de cada consulta.',
    steps: ['Pulsa «Nueva consulta», elige el tema y explica tu caso.', 'Adjunta una captura si ayuda.', 'Recibirás la respuesta aquí y por correo.'],
  },
  courses: {
    title: 'Cursos',
    summary: 'Crea y publica los cursos: contenido, videos, materiales y actividades. Los alumnos siempre ven la última versión publicada.',
    steps: ['«Nuevo curso»: título, idioma y precio.', 'Arma las secciones y agrega lecciones, videos de YouTube, materiales y actividades.', 'Pulsa «Publicar»: los alumnos ven esa versión. Los cambios siguientes quedan como borrador hasta publicar otra vez.'],
    tips: ['La etiqueta «N por calificar» marca los cursos con tareas por revisar; ábrelo en «Seguimiento».'],
    sections: {
      content: {
        title: 'Contenido',
        summary: 'Las secciones del curso con sus lecciones, materiales y actividades (cuestionarios, tareas, encuestas y paquetes SCORM).',
        steps: ['Agrega una sección y ponle nombre.', 'Dentro, agrega lecciones de texto, videos, materiales o actividades.', 'Ordena arrastrando y publica cuando esté listo.'],
        tips: ['«Vista previa» muestra el aula como la verá el alumno.'],
      },
      info: {
        title: 'Información',
        summary: 'Los datos del curso: descripción, portada, precio, días de acceso, nota mínima, asistencia mínima, avance en orden y certificado.',
        steps: ['Completa la descripción y la portada para el catálogo.', 'Define los días de acceso (vacío = no vence).', 'Marca si entrega certificado y qué exige.'],
      },
      participants: {
        title: 'Participantes',
        summary: 'Alumnos y docentes del curso.',
        steps: ['«Agregar» para matricular alumnos o sumar docentes.', 'Usa las casillas para actuar sobre varios a la vez.'],
      },
      tracking: {
        title: 'Seguimiento',
        summary: 'El avance y las notas de cada alumno, las entregas por revisar y el libro de notas.',
        steps: ['Revisa primero las entregas por calificar.', 'Califica, devuelve para rehacer o da un intento adicional.', 'Descarga el libro de notas en Excel.'],
      },
      community: {
        title: 'Anuncios y reseñas',
        summary: 'Publica anuncios para los alumnos del curso (con correo opcional) y modera las reseñas.',
        steps: ['«Nuevo anuncio»: título, texto y si se avisa por correo.', 'Responde u oculta las reseñas.'],
      },
      versions: {
        title: 'Versiones',
        summary: 'El historial de publicaciones del curso: quién publicó y cuándo.',
      },
    },
  },
  students: {
    title: 'Alumnos',
    summary: 'Quiénes estudian en tu academia y la ficha completa de cada uno: cursos, compras, certificados y consultas.',
    steps: ['Usa las tarjetas y los filtros (curso, grupo, empresa, cuotas vencidas, fechas).', 'Abre «Ver ficha» para ver todo de un alumno.', 'Desde la ficha puedes registrar una venta o restablecer su contraseña.'],
    tips: ['Los filtros quedan en la dirección: comparte el enlace con los mismos filtros.'],
  },
  teachers: {
    title: 'Docentes',
    summary: 'Los docentes, sus cursos, sus alumnos y cuántas tareas tienen por calificar.',
    steps: ['Filtra por curso para ver quién lo dicta.', 'Abre la ficha de un docente para ver su carga.'],
  },
  schedules: {
    title: 'Horarios',
    summary: 'Arma la semana de cada docente: disponible, clase en vivo (con curso y enlace) y revisión de trabajos. El docente la confirma o pide cambios.',
    steps: ['Elige al docente y agrega bloques a su semana.', 'Indica la fecha de inicio y guarda: el docente recibe el aviso.', 'Las clases en vivo aparecen en el aula del curso.'],
    tips: ['Registra los días sin clase para que no se envíe el recordatorio.'],
  },
  enrollments: {
    title: 'Matrículas',
    summary: 'Quién está matriculado en qué curso, hasta cuándo y cuánto avanzó.',
    steps: ['«Matricular alumnos»: elige el curso y las personas.', 'Filtra por curso, situación, grupo o fechas.', 'Con las casillas, cambia la vigencia o cancela varias a la vez.'],
  },
  'question-banks': {
    title: 'Banco de preguntas',
    summary: 'Las preguntas que usan los cuestionarios, agrupadas en bancos.',
    steps: ['Crea un banco por tema.', 'Agrega preguntas de opción única, múltiple o verdadero/falso, o impórtalas desde Excel.', 'En el curso, el cuestionario toma sus preguntas de los bancos.'],
  },
  crm: {
    title: 'CRM y clientes',
    summary: 'La venta a empresas: prospectos, oportunidades, empresas y cotizaciones.',
    sections: {
      opportunities: { title: 'Oportunidades', summary: 'El embudo de ventas: mueve cada oportunidad por sus etapas y registra las actividades.' },
      companies: { title: 'Empresas', summary: 'Las empresas con sus contactos, ventas y licencias.' },
      leads: {
        title: 'Prospectos',
        summary: 'Quienes mostraron interés. Regístralos o impórtalos desde Excel y conviértelos en oportunidad.',
        steps: ['«Nuevo prospecto» o «Importar».', '«Convertir» crea la empresa, su contacto y la oportunidad.'],
      },
      quotations: {
        title: 'Cotizaciones',
        summary: 'Arma la cotización con cursos, cantidades y precios. Al aceptarse nace la venta.',
        steps: ['Crea la cotización desde la oportunidad.', 'Márcala como enviada.', 'Al aceptarla (la empresa o tú) se genera la orden.'],
      },
    },
  },
  billing: {
    title: 'Ventas y pagos',
    summary: 'Todas las órdenes: pendientes, pagadas, en cuotas, vencidas, canceladas y reembolsadas.',
    steps: [
      '«Nueva venta» registra una compra a nombre de un alumno (en la oficina, por teléfono…).',
      'Para un pago por fuera: abre la orden, «Confirmar pago», indica el medio y el n.º de operación.',
      '«Pagar en cuotas» arma un plan de pagos para una orden pendiente.',
      '«Reembolsar» devuelve una compra y retira la matrícula.',
    ],
    tips: ['«Constancia de venta» imprime lo cobrado (no es boleta ni factura).', '«Descargar» baja la lista en Excel, PDF, CSV u ODS con los filtros que tengas.'],
    faqs: [{ q: '¿Qué es «Cobros en revisión»?', a: 'Un pago en línea que la pasarela cobró pero no se pudo aplicar a su orden. Revísalo en la pasarela y márcalo como resuelto.' }],
  },
  invoices: {
    title: 'Comprobantes',
    summary: 'Las boletas, facturas y notas de crédito de la academia, con su estado en SUNAT. Salen solas al confirmarse cada pago.',
    steps: [
      'Usa las tarjetas para ver los vigentes, los que van en camino, los que tienen un problema o los anulados.',
      '«Reintentar» vuelve a emitir uno que no salió (antes corrige los datos del comprador en la orden).',
      '«Anular» da de baja un comprobante con error dentro de los 7 días de SUNAT; luego «Emitir otro» saca uno nuevo.',
      'Pasado el plazo, «Nota de crédito» lo corrige por el total.',
    ],
    tips: ['Al reembolsar una compra, su nota de crédito sale sola: no hace falta emitirla aquí.', 'Anular o emitir una nota de crédito no devuelve el dinero.'],
  },
  promotions: {
    title: 'Promociones',
    summary: 'Cupones de descuento y paquetes de cursos a un precio especial.',
    sections: {
      coupons: {
        title: 'Promociones y cupones',
        summary: 'Descuentos por código: porcentaje o monto, con vigencia, tope de usos y compra mínima.',
        steps: ['«Nueva promoción» y define el descuento y sus límites.', 'Comparte el código: el comprador lo escribe al pagar.'],
        tips: ['Los descuentos no se acumulan: un cupón por compra.'],
      },
      packages: { title: 'Paquetes', summary: 'Varios cursos a un precio. Una compra, varias matrículas.' },
    },
  },
  certificates: {
    title: 'Certificados',
    summary: 'Los certificados emitidos, su plantilla y la emisión a mano.',
    steps: ['Los certificados salen solos al completar un curso que los entrega.', '«Emitir» para hacerlo a mano; «Anular» para retirarlo.', '«Plantillas» abre el editor visual del diseño.'],
  },
  support: {
    title: 'Soporte',
    summary: 'La bandeja de consultas de alumnos, compradores y empresas.',
    steps: ['Empieza por «Esperan respuesta».', 'Abre la consulta, respóndela y asígnala si hace falta.', 'Márcala como resuelta cuando termines.'],
  },
  claims: {
    title: 'Libro de Reclamaciones',
    summary: 'Reclamos y quejas del formulario público, con su plazo de respuesta.',
    steps: ['Atiende primero «Fuera de plazo» y «Por vencer».', 'Abre la hoja, pásala a revisión y responde.', 'El consumidor ve la respuesta con su código.'],
    tips: ['El plazo descuenta los feriados que registres en Configuración.'],
  },
  reports: {
    title: 'Reportes',
    summary: 'Ingresos, órdenes, matrículas, alumnos activos y certificados de un periodo, comparados con el anterior.',
    steps: ['Elige las fechas.', 'Revisa los cursos más vendidos y la finalización por curso.', 'Descárgalo en el formato que necesites.'],
  },
  users: {
    title: 'Usuarios',
    summary: 'Las cuentas de tu academia: crearlas, activarlas o desactivarlas, cambiar sus roles e importarlas desde Excel.',
    steps: ['«Nuevo usuario»: sin contraseña, le llega una invitación por correo.', 'Filtra por estado o rol.', '«Restablecer contraseña» da una temporal que debe cambiar al entrar.'],
  },
  roles: {
    title: 'Roles y permisos',
    summary: 'Qué puede hacer cada rol. Puedes crear roles propios.',
    steps: ['Abre un rol para ver sus permisos.', '«Nuevo rol» y marca los permisos que necesita.'],
    tips: ['Los roles del sistema no se borran.'],
  },
  settings: {
    title: 'Configuración',
    summary: 'Los datos de tu academia: identidad, apariencia, región y monedas, contacto, correos, pagos en línea, feriados, política de reembolsos y tus datos.',
    steps: [
      '«Apariencia»: tus colores (sugeridos desde el logo), la pantalla de acceso y el pie del sitio.',
      '«Correos»: con qué correo salen los avisos y el texto de cada uno.',
      '«Mis datos»: descarga una copia de tu academia en un ZIP.',
    ],
  },
  pages: {
    title: 'Páginas',
    summary: 'Los textos públicos de la academia: términos, privacidad, preguntas frecuentes, nosotros.',
    steps: ['Crea la página y escribe su contenido.', 'En «Dónde aparece» elige el pie, el menú superior, el inicio de sesión o el menú de alumnos.', 'Publica: el público ve la última versión publicada.'],
  },
  audit: {
    title: 'Auditoría',
    summary: 'Quién hizo qué y cuándo: cada cambio importante queda registrado.',
    steps: ['Filtra por usuario, acción o fecha.', 'Abre un registro para ver el antes y el después.'],
  },
  account: {
    title: 'Mi cuenta',
    summary: 'Tu perfil, tu contraseña, la verificación en dos pasos, los correos que recibes y tus sesiones abiertas.',
    steps: ['Cambia tu contraseña.', 'Activa la verificación en dos pasos.', 'En «Correos que recibo» desmarca lo que no quieras recibir.'],
  },
};

/** Preguntas de todo el sistema; cada una se ve si quien la lee puede entrar a su módulo */
export const GENERAL_FAQS: (HelpFaq & { module?: string })[] = [
  { q: 'Olvidé mi contraseña', a: 'En la pantalla de acceso pulsa «¿Olvidaste tu contraseña?» y sigue el enlace que llega a tu correo.' },
  { q: '¿Cómo cambio de rol o de permisos?', a: 'Lo hace el Administrador en «Usuarios» o «Roles y permisos».', module: 'users' },
  { q: '¿Dónde descargo mis datos?', a: 'En los listados, «Descargar» (Excel, PDF, CSV u ODS). La copia completa de la academia está en Configuración → Mis datos.', module: 'settings' },
  { q: '¿Cómo cobro en línea?', a: 'Conecta Mercado Pago o PayPal en Configuración → Cobros en línea.', module: 'settings' },
  { q: '¿Por qué no me llegan los correos?', a: 'Revisa la carpeta de spam y en «Mi cuenta» → «Correos que recibo» que no estén desmarcados.' },
  { q: '¿Cómo emito un certificado a mano?', a: 'En «Certificados», pulsa «Emitir» y elige el curso y el alumno.', module: 'certificates' },
];
