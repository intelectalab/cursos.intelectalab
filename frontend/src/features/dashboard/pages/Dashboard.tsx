import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import {
  ArrowUpRight,
  CalendarClock,
  CheckCircle2,
  ChevronRight,
  ClipboardCheck,
  FileCheck2,
  LifeBuoy,
  ScrollText,
  ShieldAlert,
  ShieldCheck,
  Sparkles,
  type LucideIcon,
} from 'lucide-react';
import { useAuth } from '../../auth/AuthContext';
import { visibleModules } from '../../../config/modules';
import { Card, CardHeader, IconChip, LoadingState } from '../../../shared/ui';
import { StudentHome } from '../../enrollments/components/StudentHome';
import { salesApi } from '../../sales/api';

function greeting(): string {
  const hour = new Date().getHours();
  if (hour < 12) return 'Buenos días';
  return hour < 19 ? 'Buenas tardes' : 'Buenas noches';
}

type Summary = Awaited<ReturnType<typeof salesApi.alertSummary>>;

// Quien atiende alguno de estos temas ve sus pendientes en Inicio (los mismos permisos que pide /alerts/summary)
const PENDING_PERMISSIONS = ['payments.confirm', 'orders.read', 'tickets.read', 'cms.publish', 'submissions.review', 'submissions.assigned.review', 'claims.read', 'schedules.manage', 'schedules.self'];

// Cada pendiente con su texto en singular|plural y a dónde se atiende
const PENDING: { key: keyof Summary; icon: LucideIcon; text: string; to: string }[] = [
  { key: 'schedule_to_confirm', icon: CalendarClock, text: 'horario espera tu confirmación|horarios esperan tu confirmación', to: '/my-schedule' },
  { key: 'schedules_observed', icon: CalendarClock, text: 'horario de docente tiene observaciones|horarios de docentes tienen observaciones', to: '/schedules' },
  { key: 'submissions_to_grade', icon: ClipboardCheck, text: 'tarea entregada espera nota|tareas entregadas esperan nota', to: '/courses' },
  { key: 'tickets_waiting', icon: LifeBuoy, text: 'consulta de soporte espera respuesta|consultas de soporte esperan respuesta', to: '/support' },
  { key: 'claims_unanswered', icon: ScrollText, text: 'reclamo o queja sin responder|reclamos o quejas sin responder', to: '/claims' },
  { key: 'overdue_installments', icon: CalendarClock, text: 'cuota vencida por cobrar|cuotas vencidas por cobrar', to: '/billing' },
  { key: 'payment_reviews', icon: ShieldAlert, text: 'cobro en revisión|cobros en revisión', to: '/billing' },
  { key: 'pages_in_review', icon: FileCheck2, text: 'página espera tu revisión|páginas esperan tu revisión', to: '/pages' },
];

/** Lo que espera a esta persona, con un enlace a donde se atiende cada cosa. */
const PendingCard: React.FC = () => {
  const [summary, setSummary] = useState<Summary | null>(null);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    let active = true;
    salesApi
      .alertSummary()
      .then((data) => active && setSummary(data))
      .catch(() => active && setFailed(true));
    return () => {
      active = false;
    };
  }, []);

  const items = summary ? PENDING.filter((item) => (summary[item.key] ?? 0) > 0) : [];

  return (
    <Card>
      <CardHeader title="Pendientes" text="Lo que espera tu atención." />
      {failed ? (
        <p className="px-5 pb-5 text-[13px] text-slate-500">No se pudieron cargar los pendientes. Vuelve a abrir Inicio en un momento.</p>
      ) : !summary ? (
        <LoadingState />
      ) : items.length === 0 ? (
        <p className="flex items-center gap-2 px-5 pb-5 text-[13px] text-slate-600">
          <CheckCircle2 className="w-4 h-4 text-emerald-600 shrink-0" aria-hidden="true" />
          Nada pendiente por ahora.
        </p>
      ) : (
        <ul className="divide-y divide-slate-100 border-t border-slate-100">
          {items.map(({ key, icon: Icon, text, to }) => {
            const count = summary[key] ?? 0;
            const [one, many] = text.split('|');
            return (
              <li key={key}>
                <Link to={to} className="group flex items-center gap-3 px-5 py-3.5 hover:bg-brand-50/50 transition-colors">
                  <span className="w-9 h-9 rounded-lg bg-amber-50 text-amber-700 ring-1 ring-amber-100 flex items-center justify-center shrink-0">
                    <Icon className="w-4 h-4" aria-hidden="true" />
                  </span>
                  <span className="flex-1 min-w-0 text-[13px] text-slate-700">
                    <strong className="font-bold text-slate-900 tnum">{count}</strong> {count === 1 ? one : many}
                  </span>
                  <ChevronRight className="w-4 h-4 text-slate-300 group-hover:text-brand-600" aria-hidden="true" />
                </Link>
              </li>
            );
          })}
        </ul>
      )}
    </Card>
  );
};

/**
 * Inicio: saludo, accesos rápidos a los módulos del rol y lo pendiente. Las
 * cifras de la academia están en «Panel»; quien solo estudia ve su avance.
 */
export const Dashboard: React.FC = () => {
  const { user, tenant, permissions, hasPermission } = useAuth();

  // Quien solo cursa (no administra) ve su avance en lugar de los accesos del personal
  const isStudent = hasPermission('enrollments.self.read') && !hasPermission('users.read') && !hasPermission('courses.read');
  const seesPending = !isStudent && hasPermission(PENDING_PERMISSIONS);
  const shortcuts = visibleModules(permissions, hasPermission).filter((module) => module.id !== 'dashboard');
  const today = new Date().toLocaleDateString('es-PE', { weekday: 'long', day: 'numeric', month: 'long' });

  return (
    <div className="space-y-6">
      {/* Bienvenida */}
      <section className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-brand-900 via-brand-800 to-brand-600 text-white p-7 sm:p-9 shadow-raised">
        <div className="absolute -top-20 -right-10 w-80 h-80 rounded-full bg-sky-400/25 blur-3xl pointer-events-none" />
        <div className="absolute -bottom-32 left-1/3 w-80 h-80 rounded-full bg-brand-400/20 blur-3xl pointer-events-none" />
        <div
          className="absolute inset-0 opacity-[0.07] pointer-events-none"
          style={{
            backgroundImage:
              'linear-gradient(to right, #fff 1px, transparent 1px), linear-gradient(to bottom, #fff 1px, transparent 1px)',
            backgroundSize: '44px 44px',
          }}
        />

        <div className="relative flex flex-wrap items-end justify-between gap-6">
          <div>
            <p className="inline-flex items-center gap-1.5 text-xs font-medium text-sky-200 capitalize">
              <Sparkles className="w-3.5 h-3.5" />
              {today}
            </p>
            <h1 className="mt-2 text-2xl sm:text-3xl font-bold tracking-tight">
              {greeting()}, {user?.first_name}
            </h1>
            <p className="mt-1.5 text-sm text-brand-100/85 max-w-xl">
              {isStudent ? (
                <>
                  Bienvenido a <strong className="font-semibold text-white">{tenant?.name}</strong>. Aquí tienes tus cursos, lo que te falta y
                  tus notas.
                </>
              ) : (
                <>
                  Bienvenido a <strong className="font-semibold text-white">{tenant?.name}</strong>. Desde aquí llegas a todo lo que tu rol te
                  permite gestionar{hasPermission('reports.read') ? '; las cifras de la academia están en «Panel»' : ''}.
                </>
              )}
            </p>
          </div>

          <div className="flex flex-wrap gap-2">
            {user?.roles.map((role) => (
              <span
                key={role}
                className="inline-flex items-center gap-1.5 h-8 px-3 rounded-full bg-white/12 ring-1 ring-white/20 backdrop-blur text-xs font-medium"
              >
                <ShieldCheck className="w-3.5 h-3.5 text-sky-300" />
                {role}
              </span>
            ))}
          </div>
        </div>
      </section>

      {isStudent && <StudentHome />}

      <div className={`grid grid-cols-1 gap-4 items-start ${seesPending ? 'xl:grid-cols-3' : ''}`}>
        {/* Accesos rápidos */}
        <Card className={seesPending ? 'xl:col-span-2' : ''}>
          <CardHeader title="Accesos rápidos" text="Los módulos disponibles para tu rol." />
          {shortcuts.length === 0 ? (
            <p className="px-5 py-8 text-[13px] text-slate-500">Tu rol todavía no tiene módulos asignados.</p>
          ) : (
            <ul className={`grid grid-cols-1 sm:grid-cols-2 ${seesPending ? '' : 'xl:grid-cols-3'} gap-px bg-slate-100 rounded-b-2xl overflow-hidden`}>
              {shortcuts.map((module) => (
                <li key={module.id} className="bg-white">
                  <Link
                    to={module.href}
                    className="group flex items-center gap-3.5 px-5 py-4 hover:bg-brand-50/50 transition-colors"
                  >
                    <IconChip icon={module.icon} tone={module.tone} />
                    <div className="min-w-0 flex-1">
                      <p className="text-sm font-semibold text-slate-900">{module.name}</p>
                      <p className="text-[13px] text-slate-500 truncate">{module.description}</p>
                    </div>
                    <ArrowUpRight className="w-4 h-4 text-slate-300 group-hover:text-brand-600 group-hover:-translate-y-0.5 group-hover:translate-x-0.5 transition-all" />
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </Card>

        {seesPending && <PendingCard />}
      </div>
    </div>
  );
};
