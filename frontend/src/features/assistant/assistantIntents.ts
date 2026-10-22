// Intérprete de preguntas del asistente: reglas en español, sin servidor ni IA.
// Las reglas van de la más específica a la más general; `search` es la de por defecto.

export type Intent =
  | 'help'
  | 'howto'
  | 'sales'
  | 'top'
  | 'enrollments'
  | 'alerts'
  | 'schedule'
  | 'mine'
  | 'student'
  | 'course'
  | 'search';

export type PeriodKey = 'today' | 'yesterday' | 'last7' | 'week' | 'month' | 'lastMonth';

export interface Period {
  key: PeriodKey;
  label: string;
  // AAAA-MM-DD en la zona horaria de la academia
  from: string;
  to: string;
}

export interface ParsedIntent {
  intent: Intent;
  term?: string;
  period?: Period;
  // Texto ya normalizado, para buscar guías y depurar
  text: string;
}

// Muletillas que no cambian la pregunta
const FILLERS = [
  'por favor',
  'porfa',
  'dime',
  'quiero saber',
  'quisiera saber',
  'me puedes decir',
  'me podrias decir',
  'puedes decirme',
  'podrias decirme',
  'necesito saber',
  'oye',
  'hola',
  'buenas',
  'buenos dias',
  'buenas tardes',
  'buenas noches',
  'una pregunta',
  'disculpa',
];

/** Minúsculas, sin tildes ni signos, con los espacios colapsados. */
export function normalize(text: string): string {
  return text
    .toLowerCase()
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .replace(/[¿?¡!.,;:"'()]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

/** Quita las muletillas del inicio, aunque vengan varias seguidas. */
export function stripFillers(text: string): string {
  let current = text;
  let changed = true;

  while (changed) {
    changed = false;
    for (const filler of FILLERS) {
      if (current === filler) return '';
      if (current.startsWith(`${filler} `)) {
        current = current.slice(filler.length + 1).trim();
        changed = true;
      }
    }
  }

  return current;
}

/** La fecha de hoy (AAAA-MM-DD) en una zona horaria; sin zona, la del navegador. */
export function todayIn(timeZone?: string, now: Date = new Date()): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(now);
}

const iso = (date: Date) => date.toISOString().slice(0, 10);
const shift = (day: string, days: number) => {
  const date = new Date(`${day}T12:00:00Z`);
  date.setUTCDate(date.getUTCDate() + days);
  return iso(date);
};

const PERIOD_RULES: { key: PeriodKey; pattern: RegExp }[] = [
  { key: 'lastMonth', pattern: /\b(mes pasado|mes anterior|ultimo mes)\b/ },
  { key: 'month', pattern: /\b(este mes|del mes|en el mes|mes actual|mensual)\b/ },
  { key: 'last7', pattern: /\b(ultimos 7 dias|ultimos siete dias|ultima semana|7 dias)\b/ },
  { key: 'week', pattern: /\b(esta semana|de la semana|en la semana|semanal)\b/ },
  { key: 'yesterday', pattern: /\bayer\b/ },
  { key: 'today', pattern: /\b(hoy|el dia de hoy|en el dia)\b/ },
];

const PERIOD_LABELS: Record<PeriodKey, string> = {
  today: 'Hoy',
  yesterday: 'Ayer',
  last7: 'Últimos 7 días',
  week: 'Esta semana',
  month: 'Este mes',
  lastMonth: 'Mes pasado',
};

/** El periodo que menciona la frase, si menciona uno. */
export function mentionedPeriod(text: string): PeriodKey | null {
  const normalized = normalize(text);
  return PERIOD_RULES.find((rule) => rule.pattern.test(normalized))?.key ?? null;
}

/** El periodo de la frase (hoy si no dice ninguno), con sus fechas en la zona de la academia. */
export function detectPeriod(text: string, timeZone?: string, now: Date = new Date()): Period {
  const key = mentionedPeriod(text) ?? 'today';
  const today = todayIn(timeZone, now);
  const [year, month] = today.split('-').map(Number);
  const firstOfMonth = `${today.slice(0, 7)}-01`;

  const range: Record<PeriodKey, [string, string]> = {
    today: [today, today],
    yesterday: [shift(today, -1), shift(today, -1)],
    last7: [shift(today, -6), today],
    // La semana empieza el lunes
    week: [shift(today, -((new Date(`${today}T12:00:00Z`).getUTCDay() + 6) % 7)), today],
    month: [firstOfMonth, today],
    lastMonth: [iso(new Date(Date.UTC(month === 1 ? year - 1 : year, month === 1 ? 11 : month - 2, 1))), shift(firstOfMonth, -1)],
  };

  return { key, label: PERIOD_LABELS[key], from: range[key][0], to: range[key][1] };
}

// Palabras que sobran alrededor de lo que se busca
const TERM_NOISE =
  /\b(el|la|los|las|un|una|unos|unas|de|del|al|curso|cursos|paquete|paquetes|hay|tienen|tienes|tenemos|quedan|queda|existe|disponible|disponibles|en venta|precio|cuesta|cuanto|cuantos|a|que|cual|cuales|ofrecen|ofrece|venden|dictan|algun|alguno|alguna)\b/g;

/** Limpia el término: sin artículos, sin «curso de», sin colas como «hay» o «tienen». */
export function cleanTerm(term: string): string {
  return term.replace(TERM_NOISE, ' ').replace(/\s+/g, ' ').trim();
}

const term = (match: RegExpMatchArray | null, group = 1) => (match?.[group] ? cleanTerm(match[group]) : '');

/** Interpreta una pregunta. Siempre devuelve una intención: `search` si ninguna otra encaja. */
export function parseIntent(input: string, timeZone?: string, now: Date = new Date()): ParsedIntent {
  const text = stripFillers(normalize(input));
  const period = mentionedPeriod(text) ? detectPeriod(text, timeZone, now) : undefined;
  const withPeriod = (intent: Intent): ParsedIntent => ({ intent, period: period ?? detectPeriod(text, timeZone, now), text });

  // Saludo o ayuda
  if (text === '' || /^(ayuda|help|menu|opciones|que puedes hacer|que sabes hacer|en que me ayudas|que haces|como funcionas|como te uso)\b/.test(text)) {
    return { intent: 'help', text };
  }

  // Plan de la academia y cupo: «cómo va mi plan» no es una guía
  if (/\b(mi plan|el plan|cupo|cuantos alumnos (me )?quedan|alumnos disponibles|prueba gratuita|cuando vence (mi|el) plan)\b/.test(text)) {
    return { intent: 'alerts', text };
  }

  // Cómo va el propio alumno
  if (/^(como voy|como va mi avance|mi avance|mis cursos|mis notas|mis certificados|cuanto (me falta pagar|debo|me falta)|que (cuotas|pagos) (tengo|me faltan)|mis (cuotas|pagos|compras))\b/.test(text)) {
    return { intent: 'mine', text };
  }

  // Guías: «cómo …», salvo «cómo va / cómo van / cómo está» que preguntan por un dato
  if (/^(como|donde|que pasos|pasos para|que hago para|como se|como puedo|como hago)\b/.test(text) && !/^como (va|van|voy|vamos|esta|estan|anda|andan)\b/.test(text)) {
    return { intent: 'howto', text };
  }

  // Pendientes
  if (/\b(pendiente|pendientes|por hacer|por atender|que falta|alertas|avisos|vencid[ao]s|por vencer|se vence|se vencen|por calificar|sin calificar|sin responder|sin atender|inactiv[oa]s|no avanzan|morosos|deudas|cuotas)\b/.test(text)) {
    return { intent: 'alerts', text };
  }

  // Lo más vendido
  if (/\b(mas vendid[oa]s?|top|ranking|que (curso|cursos) (se )?vend(io|ieron|e|en) mas|lo que mas se vend[eio]+|mejores ventas|cursos estrella)\b/.test(text)) {
    return withPeriod('top');
  }

  // Matrículas de un periodo (las guías «cómo matriculo» ya salieron arriba)
  if (/\b(cuant[oa]s (alumnos )?(se )?(matricul|inscrib)\w*|matriculas (nuevas|de|del|esta|este)|nuevos alumnos|alumnos nuevos|inscripciones|nuevas matriculas)\b/.test(text) && (period || !/\b(de|del) [a-z]/.test(text.replace(/\b(de|del) (hoy|ayer|la semana|el mes|mes)\b/, '')))) {
    return withPeriod('enrollments');
  }

  // Ventas de un periodo: si menciona un periodo y ventas, siempre es reporte
  const salesWords = /\b(vend\w*|ventas|ingres\w*|factur\w*|cobr\w*|recaud\w*|caja)\b/;
  if (salesWords.test(text) && (period || /^(cuanto|cuantas|cuantos)\b/.test(text) || /^(ventas|ingresos|cobros)$/.test(text))) {
    return withPeriod('sales');
  }

  // Horarios
  if (/\b(horario|horarios|disponible el|disponibles el|quien esta disponible|clase en vivo|clases en vivo|mi clase|mis clases|cuando (es|son) (la|las|mi|mis) clase)/.test(text)) {
    const match = text.match(/(?:horario|horarios) de ([a-z ]+)$/);
    return { intent: 'schedule', term: term(match), text };
  }

  // Ficha de una persona
  const student =
    text.match(/^(?:que|cuanto) debe ([a-z ]+)$/) ??
    text.match(/^(?:que cursos tiene|que cursos lleva|deuda de|ficha de(?:l)?(?: alumn[oa])?|alumn[oa]) ([a-z ]+)$/) ??
    text.match(/^(?:como va|como esta|como le va a) (?:el alumno |la alumna )([a-z ]+)$/);
  if (student) return { intent: 'student', term: term(student), text };

  // Ficha de un curso
  const course =
    text.match(/^(?:cuant[oa]s alumnos (?:tiene|hay en|lleva)n?|alumnos de|matriculados en|matriculas de|ventas de|ingresos de|resenas de|opiniones de|calificacion de|quien dicta|quien ensena|docentes? de|como va|como esta|como van las ventas de|como van) ([a-z0-9 ]+)$/) ??
    text.match(/^(?:ficha|info|informacion|datos) (?:del? )?(?:curso )?([a-z0-9 ]+)$/);
  if (course && term(course)) return { intent: 'course', term: term(course), text };

  // Por defecto: buscar en el catálogo
  return { intent: 'search', term: cleanTerm(text) || text, text };
}

/** Preguntas típicas según lo que puede ver la persona. */
export function quickPrompts(can: (permission: string | string[]) => boolean): string[] {
  if (can(['orders.read', 'reports.read'])) {
    return ['¿Cuánto vendimos hoy?', '¿Qué está pendiente?', 'Lo más vendido del mes', '¿Cómo confirmo un pago?'];
  }
  if (can('courses.assigned.read')) {
    return ['¿Qué está pendiente?', '¿Cuántos se matricularon este mes?', '¿Cómo tomo asistencia?', '¿Cómo califico una tarea?'];
  }
  if (can('tickets.read')) {
    return ['¿Qué está pendiente?', '¿Cómo respondo una consulta?', '¿Cómo restablezco una contraseña?', '¿Qué cursos hay?'];
  }
  if (can('student.portal')) {
    return ['¿Qué cursos hay?', '¿Cuánto me falta pagar?', '¿Cómo descargo mi certificado?', '¿Cómo pido ayuda?'];
  }
  return ['¿Qué cursos hay?', '¿Qué puedes hacer?', '¿Cómo pido ayuda?', '¿Cómo cambio mi contraseña?'];
}
