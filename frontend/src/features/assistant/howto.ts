// Guías paso a paso del asistente («cómo …»), tomadas del manual de uso por rol.
// Cada guía se ofrece solo a quien tiene el permiso de su módulo.

import { normalize } from './assistantIntents';

export interface Guide {
  title: string;
  // Palabras clave sin tildes; gana la guía con más coincidencias
  keywords: string[];
  steps: string[];
  // Permiso del módulo; sin permiso, la guía es para todos
  permission?: string | string[];
  link?: { label: string; href: string; permission?: string | string[] };
}

export const GUIDES: Guide[] = [
  {
    title: 'Matricular alumnos',
    keywords: ['matricul', 'inscrib', 'agregar alumno', 'dar acceso'],
    permission: 'enrollments.create',
    steps: [
      'Entra a «Matrículas» y pulsa «Matricular alumnos».',
      'Elige el curso y las personas (una por una o un grupo completo).',
      'Si el curso tiene días de acceso, la vigencia se calcula sola; puedes cambiarla después.',
      'Pulsa «Matricular». Cada alumno recibe un aviso por correo.',
    ],
    link: { label: 'Ir a Matrículas', href: '/enrollments' },
  },
  {
    title: 'Confirmar un pago recibido',
    keywords: ['confirm', 'pago', 'pagos', 'yape', 'transferencia', 'voucher', 'deposito'],
    permission: 'payments.confirm',
    steps: [
      'Entra a «Ventas y pagos» y abre la orden.',
      'Pulsa «Confirmar pago» e indica el medio (transferencia, Yape, tarjeta…) y el número de operación.',
      'El alumno queda matriculado al momento y recibe el aviso.',
    ],
    link: { label: 'Ir a Ventas y pagos', href: '/billing' },
  },
  {
    title: 'Reembolsar una compra',
    keywords: ['reembols', 'devolver', 'devolucion', 'anular venta', 'anulo una venta', 'anular una venta'],
    permission: 'payments.refund',
    steps: [
      'Entra a «Ventas y pagos» y abre la orden pagada.',
      'Pulsa «Reembolsar» y escribe el motivo.',
      'Si se pagó en línea, la devolución va por la pasarela; si fue por fuera, registra cómo devolviste el dinero.',
      'La orden queda «Reembolsada» y el comprador pierde el acceso a ese curso.',
    ],
    link: { label: 'Ir a Ventas y pagos', href: '/billing' },
  },
  {
    title: 'Cancelar una orden pendiente',
    keywords: ['cancelar orden', 'cancelo una orden', 'cancelar una orden', 'anular orden'],
    permission: 'orders.cancel',
    steps: ['Entra a «Ventas y pagos» y abre la orden pendiente.', 'Pulsa «Cancelar orden». Si ya se pagó, no la canceles: usa «Reembolsar».'],
    link: { label: 'Ir a Ventas y pagos', href: '/billing' },
  },
  {
    title: 'Armar un plan de cuotas',
    keywords: ['cuota', 'cuotas', 'pagos parciales', 'fraccionar', 'plan de pagos'],
    permission: 'payments.confirm',
    steps: [
      'Abre la orden pendiente en «Ventas y pagos».',
      'Pulsa «Pagar en cuotas» y define cuántas, sus importes y fechas.',
      'El alumno entra al curso con la primera cuota; las siguientes se pagan desde la misma orden.',
      'Si una cuota vence, aparece en la campana de avisos.',
    ],
    link: { label: 'Ir a Ventas y pagos', href: '/billing' },
  },
  {
    title: 'Publicar un curso',
    keywords: ['publicar', 'publico', 'crear curso', 'nuevo curso'],
    permission: 'courses.update',
    steps: [
      'Entra a «Cursos» y pulsa «Nuevo curso» (o abre uno existente).',
      'En «Contenido», arma las secciones con sus recursos y actividades.',
      'En «Información», revisa el precio y los días de acceso.',
      'Pulsa «Publicar curso» (o «Publicar cambios» si ya estaba publicado). Los alumnos ven la versión publicada; lo que cambies después queda como borrador hasta volver a publicar.',
    ],
    link: { label: 'Ir a Cursos', href: '/courses' },
  },
  {
    title: 'Emitir o anular un certificado',
    keywords: ['certificado', 'certificados', 'emitir', 'anular certificado'],
    permission: ['certificates.issue', 'certificates.revoke'],
    steps: [
      'El certificado sale solo cuando el alumno completa el curso (y alcanza la nota mínima, si la tiene).',
      'Para emitirlo a mano: «Certificados» → «Emitir».',
      'Para anularlo: búscalo en «Certificados», pulsa «Anular» y escribe el motivo.',
    ],
    link: { label: 'Ir a Certificados', href: '/certificates' },
  },
  {
    title: 'Crear un cupón de descuento',
    keywords: ['cupon', 'cupones', 'descuento', 'promocion', 'codigo de descuento'],
    permission: 'promotions.manage',
    steps: [
      'Entra a «Promociones y paquetes» y crea una promoción (porcentaje o monto, vigencia y cursos).',
      'Agrega uno o más cupones con su código y su tope de usos.',
      'El comprador escribe el código al pagar. Los descuentos no se acumulan.',
    ],
    link: { label: 'Ir a Promociones', href: '/promotions' },
  },
  {
    title: 'Restablecer la contraseña de alguien',
    keywords: ['restablec', 'contrasena de', 'olvido su contrasena', 'no puede entrar', 'resetear'],
    permission: 'users.update',
    steps: [
      'Entra a «Usuarios» y pulsa «Editar» en la persona.',
      'Pulsa «Restablecer contraseña» y escribe una temporal.',
      'Entrégasela: al entrar, el sistema le pedirá elegir una propia.',
    ],
    link: { label: 'Ir a Usuarios', href: '/users' },
  },
  {
    title: 'Armar el horario de un docente',
    keywords: ['horario', 'disponibilidad', 'armar horario', 'clase en vivo'],
    permission: 'schedules.manage',
    steps: [
      'Entra a «Horarios» y pulsa «Armar horario» en el docente.',
      'Elige desde qué fecha vale y agrega los bloques: disponible, clase en vivo (con curso y enlace) o revisión de trabajos.',
      'Pulsa «Guardar y enviar al docente». Le llega un aviso para confirmarlo.',
    ],
    link: { label: 'Ir a Horarios', href: '/schedules' },
  },
  {
    title: 'Tomar asistencia de una clase en vivo',
    keywords: ['asistencia', 'tomar asistencia', 'pasar lista', 'lista'],
    permission: ['submissions.review', 'submissions.assigned.review'],
    steps: [
      'Abre el curso → pestaña «Seguimiento» → «Asistencia a clases en vivo» (el docente también puede ir desde «Mi horario» → «Tomar asistencia»).',
      'Elige el día y la clase.',
      'Marca a cada alumno: presente, tarde, ausente o justificado, y pulsa «Guardar asistencia».',
    ],
  },
  {
    title: 'Confirmar mi horario',
    keywords: ['confirmar horario', 'mi horario', 'confirmo mi horario', 'pedir cambios'],
    permission: 'schedules.self',
    steps: ['Entra a «Mi horario».', 'Revisa la semana y pulsa «Confirmar horario», o «Pedir cambios» y escribe qué hay que cambiar.'],
    link: { label: 'Ir a Mi horario', href: '/my-schedule' },
  },
  {
    title: 'Calificar una tarea',
    keywords: ['calific', 'nota', 'notas', 'tarea', 'revisar entrega', 'corregir'],
    permission: ['submissions.review', 'submissions.assigned.review'],
    steps: [
      'Abre el curso → pestaña «Seguimiento».',
      'En «Entregas por revisar», pulsa «Revisar».',
      'Pon la nota y un comentario, o «Devolver para rehacer» si el alumno debe corregirla.',
    ],
    link: { label: 'Ir a Cursos', href: '/courses', permission: ['courses.read', 'courses.assigned.read'] },
  },
  {
    title: 'Publicar un anuncio en un curso',
    keywords: ['anuncio', 'anuncios', 'avisar a los alumnos', 'comunicado'],
    permission: ['courses.content.write', 'content.assigned.write'],
    steps: [
      'Abre el curso → pestaña «Anuncios y reseñas» → «Nuevo anuncio».',
      'Escribe el título y el mensaje. Marca «Avisar también por correo» si quieres que les llegue al correo.',
    ],
  },
  {
    title: 'Responder una consulta de soporte',
    keywords: ['consulta', 'ticket', 'soporte', 'responder'],
    permission: 'tickets.manage',
    steps: ['Entra a «Soporte» y abre la consulta.', 'Escribe tu respuesta (o una nota interna, que quien consulta no ve) y cambia el estado cuando esté resuelta.'],
    link: { label: 'Ir a Soporte', href: '/support' },
  },
  {
    title: 'Responder un reclamo',
    keywords: ['reclamo', 'reclamos', 'queja', 'libro de reclamaciones'],
    permission: 'claims.manage',
    steps: ['Entra a «Libro de Reclamaciones» y abre la hoja.', 'Escribe la respuesta antes del plazo: quien reclamó la consulta con su código y le llega por correo.'],
    link: { label: 'Ir al Libro de Reclamaciones', href: '/claims' },
  },
  {
    title: 'Cambiar el correo de envío o los textos de los correos',
    keywords: ['correo de envio', 'correos', 'smtp', 'plantilla de correo', 'remitente'],
    permission: 'email_templates.manage',
    steps: [
      'Entra a Configuración → «Correos».',
      'Arriba, en «Correo de envío», elige tu propio correo o el de IntelectaLab; prueba antes de guardar.',
      'Abajo, abre un correo para cambiar su texto, apagarlo o enviarte una prueba.',
    ],
    link: { label: 'Ir a Correos', href: '/settings/emails' },
  },
  {
    title: 'Activar la verificación en dos pasos',
    keywords: ['dos pasos', 'doble factor', '2fa', 'autenticador', 'authenticator', 'verificacion'],
    steps: [
      'Instala Google Authenticator o Microsoft Authenticator en tu teléfono.',
      'En «Mi cuenta», pulsa «Activar verificación en dos pasos» y escanea el código QR.',
      'Escribe el código de 6 dígitos y guarda tus 8 códigos de respaldo.',
    ],
    link: { label: 'Ir a Mi cuenta', href: '/account' },
  },
  {
    title: 'Cambiar mi contraseña',
    keywords: ['cambiar mi contrasena', 'cambio mi contrasena', 'mi contrasena', 'contrasena'],
    steps: ['Entra a «Mi cuenta».', 'En «Contraseña», escribe la actual y la nueva dos veces, y pulsa «Cambiar contraseña».'],
    link: { label: 'Ir a Mi cuenta', href: '/account' },
  },
  {
    title: 'Comprar un curso',
    keywords: ['comprar', 'compro', 'pagar un curso', 'inscribirme'],
    permission: 'orders.create',
    steps: [
      'Entra a «Cursos disponibles» y abre el curso.',
      'Pulsa «Comprar ahora»; si tienes un cupón, escríbelo.',
      'Paga en línea o sigue las instrucciones para pagar por fuera. Al confirmarse el pago, quedas matriculado.',
    ],
    link: { label: 'Ver cursos disponibles', href: '/available-courses' },
  },
  {
    title: 'Descargar mi certificado',
    keywords: ['mi certificado', 'descargar certificado', 'descargo mi certificado'],
    permission: 'certificates.self',
    steps: ['Entra a «Mis certificados».', 'Pulsa «Descargar» para bajar el PDF, o «Añadir a LinkedIn».'],
    link: { label: 'Ir a Mis certificados', href: '/my-certificates' },
  },
  {
    title: 'Pedir ayuda a la academia',
    keywords: ['ayuda', 'pedir ayuda', 'contactar', 'problema', 'consulta'],
    permission: 'tickets.self',
    steps: ['Entra a «Ayuda» y crea una consulta: elige la categoría, escribe tu mensaje y adjunta archivos si hace falta.', 'Verás la respuesta del equipo en la misma consulta.'],
    link: { label: 'Ir a Ayuda', href: '/help' },
  },
];

/**
 * Las guías que mejor responden a la pregunta, entre las que la persona puede ver.
 * Gana la que tiene más palabras clave en la frase; a igualdad, la más específica.
 */
export function findGuides(question: string, can: (permission: string | string[]) => boolean, limit = 2): Guide[] {
  const text = normalize(question);

  return GUIDES.filter((guide) => !guide.permission || can(guide.permission))
    .map((guide) => ({
      guide,
      score: guide.keywords.reduce((sum, keyword) => (text.includes(keyword) ? sum + keyword.length : sum), 0),
    }))
    .filter((item) => item.score > 0)
    .sort((a, b) => b.score - a.score)
    .slice(0, limit)
    .map((item) => item.guide);
}
