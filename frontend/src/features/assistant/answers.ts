// Arma la respuesta del asistente: según la intención, consulta lo necesario y
// devuelve una tarjeta. Cada enlace solo se incluye si el rol puede abrir ese módulo.

import { apiErrorMessage } from '../../shared/api/client';
import { detectPeriod, type ParsedIntent } from './assistantIntents';
import { assistantApi, type Catalog, type Insights } from './api';
import { findGuides, type Guide } from './howto';
import { searchCatalog, type ScoredItem } from './search';

type Can = (permission: string | string[]) => boolean;

export interface Action {
  label: string;
  href: string;
}

export interface PendingLine {
  key: string;
  count: number;
  text: string;
  href: string | null;
}

export type Card =
  | { kind: 'text'; title?: string; text: string; tone?: 'info' | 'warning' | 'error'; actions?: Action[] }
  | { kind: 'help'; capabilities: string[]; prompts: string[] }
  | { kind: 'guides'; guides: Guide[]; can: Can }
  | { kind: 'sales'; period: string; currency: string; sales: NonNullable<Insights['sales']>; actions: Action[]; courseLink: boolean }
  | { kind: 'top'; period: string; currency: string; top: NonNullable<Insights['sales']>['top']; actions: Action[]; courseLink: boolean }
  | { kind: 'enrollments'; period: string; enrollments: NonNullable<Insights['enrollments']>; actions: Action[] }
  | { kind: 'pending'; lines: PendingLine[]; plan: Insights['plan'] }
  | { kind: 'results'; term: string; results: ScoredItem[]; catalog: Catalog; note?: string; actions?: Action[] };

export interface Context {
  can: Can;
  userKey: string;
  prompts: string[];
}

// Cada pendiente: texto en singular|plural y a dónde se atiende (con el permiso que pide esa pantalla)
const PENDING: { key: string; text: string; href?: string; permission?: string | string[] }[] = [
  { key: 'submissions_to_grade', text: 'tarea entregada espera nota|tareas entregadas esperan nota', href: '/courses', permission: ['courses.read', 'courses.assigned.read'] },
  { key: 'overdue_installments', text: 'cuota vencida por cobrar|cuotas vencidas por cobrar', href: '/billing', permission: ['orders.read', 'orders.support.read'] },
  { key: 'payment_reviews', text: 'cobro en línea en revisión|cobros en línea en revisión', href: '/billing', permission: ['orders.read', 'orders.support.read'] },
  { key: 'tickets_waiting', text: 'consulta de soporte espera respuesta|consultas de soporte esperan respuesta', href: '/support', permission: 'tickets.read' },
  { key: 'claims_unanswered', text: 'reclamo o queja sin responder|reclamos o quejas sin responder', href: '/claims', permission: 'claims.read' },
  { key: 'pages_in_review', text: 'página espera tu revisión|páginas esperan tu revisión', href: '/pages', permission: ['cms.read', 'cms.update', 'cms.publish'] },
  { key: 'enrollments_expiring', text: 'matrícula vence en los próximos 7 días|matrículas vencen en los próximos 7 días', href: '/enrollments', permission: ['enrollments.read', 'enrollments.support.read'] },
  { key: 'inactive_students', text: 'alumno con acceso no avanza hace 14 días|alumnos con acceso no avanzan hace 14 días', href: '/students', permission: 'students.read' },
  { key: 'schedule_to_confirm', text: 'horario espera tu confirmación|horarios esperan tu confirmación', href: '/my-schedule', permission: 'schedules.self' },
  { key: 'schedules_observed', text: 'horario de docente tiene observaciones|horarios de docentes tienen observaciones', href: '/schedules', permission: 'schedules.read' },
];

const plural = (count: number, text: string) => {
  const [one, many] = text.split('|');
  return `${count} ${count === 1 ? one : many}`;
};

const action = (can: Can, label: string, href: string, permission?: string | string[]): Action[] => (!permission || can(permission) ? [{ label, href }] : []);

function capabilities(can: Can): string[] {
  const list: string[] = [];
  if (can(['orders.read', 'reports.read'])) list.push('Cuánto se vendió en un día, una semana o un mes, y qué cursos se vendieron más.');
  if (can(['enrollments.read', 'reports.read', 'courses.assigned.read'])) list.push('Cuántas matrículas nuevas hubo en un periodo.');
  list.push('Qué está pendiente: lo que espera tu atención.');
  list.push('Buscar cursos y paquetes por su nombre o su tema, con su precio.');
  list.push('Cómo hacer algo en el sistema, paso a paso.');
  return list;
}

/** Responde una pregunta ya interpretada. Nunca lanza: los errores vuelven como tarjeta. */
export async function answer(parsed: ParsedIntent, ctx: Context): Promise<Card[]> {
  const { can } = ctx;

  try {
    switch (parsed.intent) {
      case 'help':
        return [{ kind: 'help', capabilities: capabilities(can), prompts: ctx.prompts }];

      case 'howto': {
        const guides = findGuides(parsed.text, can);
        if (guides.length > 0) return [{ kind: 'guides', guides, can }];
        return [{ kind: 'text', tone: 'info', text: 'No tengo una guía para eso todavía. Prueba con otras palabras, por ejemplo «cómo matriculo» o «cómo confirmo un pago», o revisa el manual de uso.' }];
      }

      case 'sales':
      case 'top':
      case 'enrollments': {
        const catalog = await assistantApi.catalog(ctx.userKey);
        // Las fechas del periodo se cuentan en la hora de la academia, no en la del navegador
        const range = detectPeriod(parsed.text, catalog.timezone);
        const insights = await assistantApi.insights(range.from, range.to);
        const courseLink = can('courses.read');

        if (parsed.intent === 'enrollments') {
          if (!insights.enrollments) return [{ kind: 'text', tone: 'info', text: 'Tu rol no puede ver las matrículas.' }];
          return [{ kind: 'enrollments', period: range.label, enrollments: insights.enrollments, actions: action(can, 'Ir a Matrículas', '/enrollments', ['enrollments.read', 'enrollments.support.read']) }];
        }

        if (!insights.sales) return [{ kind: 'text', tone: 'info', text: 'Tu rol no puede ver las ventas de la academia.' }];

        const actions = [...action(can, 'Ver el Panel', '/panel', 'reports.read'), ...action(can, 'Ventas y pagos', '/billing', ['orders.read', 'orders.support.read'])];
        if (parsed.intent === 'top') return [{ kind: 'top', period: range.label, currency: insights.currency, top: insights.sales.top, actions, courseLink }];
        return [{ kind: 'sales', period: range.label, currency: insights.currency, sales: insights.sales, actions, courseLink }];
      }

      case 'alerts': {
        const catalog = await assistantApi.catalog(ctx.userKey);
        const today = detectPeriod('hoy', catalog.timezone);
        const insights = await assistantApi.insights(today.from, today.to);
        const lines = PENDING.filter((item) => (insights.pending[item.key] ?? 0) > 0).map((item) => ({
          key: item.key,
          count: insights.pending[item.key] as number,
          text: plural(insights.pending[item.key] as number, item.text),
          href: item.href && (!item.permission || can(item.permission)) ? item.href : null,
        }));
        return [{ kind: 'pending', lines, plan: insights.plan }];
      }

      case 'course':
      case 'search': {
        const catalog = await assistantApi.catalog(ctx.userKey);
        const term = parsed.term ?? '';
        const results = searchCatalog(term, catalog.items);
        const note = parsed.intent === 'course' && results.length > 0 ? 'Abre el curso para ver sus alumnos, su avance y sus notas. La ficha del curso dentro del asistente llega en la próxima entrega.' : undefined;
        return [{ kind: 'results', term, results, catalog, note }];
      }

      case 'student':
        if (!can('students.read')) return [{ kind: 'text', tone: 'info', text: 'Tu rol no puede ver las fichas de los alumnos.' }];
        return [
          {
            kind: 'text',
            title: parsed.term ? `Buscar a «${parsed.term}»` : 'Fichas de alumnos',
            text: 'La ficha de cada alumno (sus cursos, lo que debe y sus certificados) está en «Alumnos». Dentro del asistente llega en la próxima entrega.',
            actions: action(can, 'Ir a Alumnos', '/students', 'students.read'),
          },
        ];

      case 'schedule':
        return [
          {
            kind: 'text',
            title: 'Horarios',
            text: 'Los horarios y las clases en vivo están en estas pantallas. Responderlos aquí llega en la próxima entrega.',
            actions: [...action(can, 'Ver Horarios', '/schedules', 'schedules.read'), ...action(can, 'Mi horario', '/my-schedule', 'schedules.self'), ...action(can, 'Mis cursos', '/my-courses', 'enrollments.self.read')],
          },
        ];

      case 'mine':
        return [
          {
            kind: 'text',
            title: 'Lo tuyo',
            text: 'Tu avance, lo que te falta pagar y tus certificados están en estas pantallas. Responderlo aquí llega en la próxima entrega.',
            actions: [...action(can, 'Mis cursos', '/my-courses', 'enrollments.self.read'), ...action(can, 'Mis compras', '/my-orders', 'student.portal'), ...action(can, 'Mis certificados', '/my-certificates', 'certificates.self')],
          },
        ];
    }
  } catch (err) {
    return [{ kind: 'text', tone: 'error', text: apiErrorMessage(err, 'No pude consultar los datos. Revisa tu conexión e inténtalo otra vez.') }];
  }
}
