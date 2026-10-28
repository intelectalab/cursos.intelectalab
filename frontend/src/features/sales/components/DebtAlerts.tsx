import React, { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Bell, BellRing, CalendarClock, CheckCheck, ChevronRight, FileCheck2, LifeBuoy, ShieldAlert, X, type LucideIcon } from 'lucide-react';
import { useAuth } from '../../auth/AuthContext';
import { formatMoney, orderCode, salesApi, type DueInstallment } from '../api';

// Cada cuánto se vuelve a preguntar por cuotas vencidas mientras la pantalla está abierta
const REFRESH_MS = 5 * 60 * 1000;
// Cuántos avisos emergentes se muestran a la vez; el resto queda en la campana
const VISIBLE = 3;

const storageKey = (userId: string) => `avisos-cuotas-vistas:${userId}`;

// Lo que cada persona ya marcó como visto queda en su navegador
function readSeen(userId: string): string[] {
  try {
    const stored = JSON.parse(localStorage.getItem(storageKey(userId)) ?? '[]');
    return Array.isArray(stored) ? stored.filter((value): value is string => typeof value === 'string') : [];
  } catch {
    return [];
  }
}

function writeSeen(userId: string, ids: string[]) {
  try {
    // Solo las últimas: la lista no crece sin límite
    localStorage.setItem(storageKey(userId), JSON.stringify(ids.slice(-200)));
  } catch {
    // Sin almacenamiento, el aviso vuelve a salir la próxima vez
  }
}

const days = (count: number) => (count === 1 ? '1 día' : `${count} días`);

// Avisos para quien cobra: una campana fija abajo con todas las cuotas vencidas y, cuando
// aparece una nueva, un aviso emergente como una notificación de teléfono.
export const DebtAlerts: React.FC = () => {
  const { user, hasPermission } = useAuth();
  const navigate = useNavigate();
  const panel = useRef<HTMLDivElement>(null);
  const userId = user?.id ?? '';
  // Las cuotas vencidas las ve quien cobra; los demás pendientes, quien atiende cada tema
  const collects = userId !== '' && hasPermission('payments.confirm');
  const enabled = userId !== '' && (collects || hasPermission(['orders.read', 'tickets.read', 'cms.publish', 'schedules.manage', 'schedules.self']));
  const [alerts, setAlerts] = useState<DueInstallment[]>([]);
  const [summary, setSummary] = useState<Awaited<ReturnType<typeof salesApi.alertSummary>> | null>(null);
  const [seen, setSeen] = useState<string[]>(() => (userId ? readSeen(userId) : []));
  const [open, setOpen] = useState(false);

  useEffect(() => {
    if (!enabled) return;

    let active = true;
    const check = () => {
      if (collects) {
        salesApi
          .installmentAlerts()
          .then((data) => active && setAlerts(data))
          .catch(() => undefined);
      }
      salesApi
        .alertSummary()
        .then((data) => active && setSummary(data))
        .catch(() => undefined);
    };

    const first = setTimeout(check, 1500);
    const timer = setInterval(check, REFRESH_MS);
    return () => {
      active = false;
      clearTimeout(first);
      clearInterval(timer);
    };
  }, [enabled, collects]);

  // La lista se cierra con Escape o al tocar fuera
  useEffect(() => {
    if (!open) return;

    const onKey = (event: KeyboardEvent) => event.key === 'Escape' && setOpen(false);
    const onPointer = (event: PointerEvent) => {
      if (panel.current && !panel.current.contains(event.target as Node)) setOpen(false);
    };
    window.addEventListener('keydown', onKey);
    window.addEventListener('pointerdown', onPointer);
    return () => {
      window.removeEventListener('keydown', onKey);
      window.removeEventListener('pointerdown', onPointer);
    };
  }, [open]);

  if (!enabled) return null;

  const fresh = alerts.filter((alert) => !seen.includes(alert.id));

  // Pendientes de otros módulos: se muestran mientras existan, con un enlace a donde se atienden
  const pending: { key: string; icon: LucideIcon; count: number; text: string; to: string }[] = [
    { key: 'reviews', icon: ShieldAlert, count: summary?.payment_reviews ?? 0, text: 'cobro en revisión|cobros en revisión', to: '/billing' },
    { key: 'tickets', icon: LifeBuoy, count: summary?.tickets_waiting ?? 0, text: 'consulta de soporte espera respuesta|consultas de soporte esperan respuesta', to: '/support' },
    { key: 'my-schedule', icon: CalendarClock, count: summary?.schedule_to_confirm ?? 0, text: 'horario espera tu confirmación|horarios esperan tu confirmación', to: '/my-schedule' },
    { key: 'schedules', icon: CalendarClock, count: summary?.schedules_observed ?? 0, text: 'horario de docente tiene observaciones|horarios de docentes tienen observaciones', to: '/schedules' },
    { key: 'pages', icon: FileCheck2, count: summary?.pages_in_review ?? 0, text: 'página espera tu revisión|páginas esperan tu revisión', to: '/pages' },
  ].filter((item) => item.count > 0);
  const pendingTotal = pending.reduce((sum, item) => sum + item.count, 0);
  const badge = fresh.length + pendingTotal;

  const dismiss = (ids: string[]) => {
    const next = [...new Set([...seen, ...ids])];
    setSeen(next);
    writeSeen(userId, next);
  };

  const view = (alert: DueInstallment) => {
    dismiss([alert.id]);
    setOpen(false);
    navigate(`/billing?order=${alert.order.id}`);
  };

  const describe = (alert: DueInstallment) =>
    `${alert.order.customer?.name ?? 'Un alumno'} debe la cuota ${alert.number} de «${alert.order.title}». Venció hace ${days(alert.days_overdue)}.`;

  return (
    <div ref={panel} className="fixed z-40 bottom-4 right-4 flex flex-col items-end gap-2.5 pointer-events-none">
      {/* Avisos emergentes: solo lo que la persona todavía no vio, y no mientras tiene la lista abierta */}
      {!open &&
        fresh.slice(0, VISIBLE).map((alert) => (
          <div key={alert.id} role="status" className="pointer-events-auto w-[min(360px,calc(100vw-2rem))] rounded-2xl bg-white border border-rose-200 shadow-raised p-3.5 animate-rise">
            <div className="flex items-start gap-3">
              <span className="w-9 h-9 shrink-0 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center">
                <BellRing className="w-4.5 h-4.5" />
              </span>
              <div className="min-w-0 flex-1">
                <p className="text-[13px] font-semibold text-slate-900">Cuota vencida · {formatMoney(alert.amount, alert.order.currency)}</p>
                <p className="text-[13px] text-slate-600">{describe(alert)}</p>
                <div className="mt-2 flex flex-wrap gap-3">
                  <button type="button" className="text-[13px] font-semibold text-brand-700 underline cursor-pointer" onClick={() => view(alert)}>
                    Ver orden {orderCode(alert.order.number)}
                  </button>
                  <button type="button" className="text-[13px] font-semibold text-slate-600 underline cursor-pointer" onClick={() => dismiss([alert.id])}>
                    Entendido
                  </button>
                </div>
              </div>
              <button
                type="button"
                aria-label="Cerrar este aviso"
                className="shrink-0 w-7 h-7 rounded-lg text-slate-500 hover:bg-slate-100 flex items-center justify-center cursor-pointer"
                onClick={() => dismiss([alert.id])}
              >
                <X className="w-4 h-4" />
              </button>
            </div>
          </div>
        ))}
      {!open && fresh.length > VISIBLE && (
        <p role="status" className="pointer-events-auto rounded-full bg-slate-900 text-white shadow-raised px-3.5 py-1.5 text-xs font-medium">
          Y {fresh.length - VISIBLE} más en la campana
        </p>
      )}

      {/* Todos los avisos, vistos o no */}
      {open && (
        <div
          id="lista-de-avisos"
          role="dialog"
          aria-label="Avisos"
          className="pointer-events-auto w-[min(380px,calc(100vw-2rem))] max-h-[min(70vh,560px)] flex flex-col rounded-2xl bg-white border border-slate-200 shadow-pop overflow-hidden animate-rise"
        >
          <div className="flex items-center justify-between gap-3 px-4 py-3 border-b border-slate-200/80">
            <div>
              <h2 className="text-sm font-semibold text-slate-900">Avisos</h2>
              <p className="text-xs text-slate-500">
                {alerts.length === 0 && pendingTotal === 0
                  ? 'Sin avisos por ahora'
                  : [
                      alerts.length > 0 ? `${alerts.length === 1 ? '1 cuota vencida' : `${alerts.length} cuotas vencidas`}${fresh.length > 0 ? ` (${fresh.length} sin ver)` : ''}` : null,
                      pendingTotal > 0 ? `${pendingTotal} ${pendingTotal === 1 ? 'pendiente' : 'pendientes'} por atender` : null,
                    ]
                      .filter(Boolean)
                      .join(' · ')}
              </p>
            </div>
            {fresh.length > 0 && (
              <button
                type="button"
                className="inline-flex items-center gap-1.5 text-xs font-semibold text-brand-700 underline cursor-pointer"
                onClick={() => dismiss(alerts.map((alert) => alert.id))}
              >
                <CheckCheck className="w-3.5 h-3.5" />
                Marcar todo como visto
              </button>
            )}
          </div>

          {pending.length > 0 && (
            <ul className="divide-y divide-slate-100 border-b border-slate-200/80 bg-amber-50/40">
              {pending.map((item) => {
                const [one, many] = item.text.split('|');
                return (
                  <li key={item.key}>
                    <button
                      type="button"
                      className="w-full text-left px-4 py-2.5 flex items-center gap-3 hover:bg-amber-50 cursor-pointer"
                      onClick={() => {
                        setOpen(false);
                        navigate(item.to);
                      }}
                    >
                      <span className="w-8 h-8 shrink-0 rounded-lg bg-amber-100 text-amber-700 flex items-center justify-center">
                        <item.icon className="w-4 h-4" />
                      </span>
                      <span className="flex-1 text-[13px] text-slate-800">
                        <strong className="font-semibold text-slate-900 tnum">{item.count}</strong> {item.count === 1 ? one : many}
                      </span>
                      <ChevronRight className="w-4 h-4 shrink-0 text-slate-400" />
                    </button>
                  </li>
                );
              })}
            </ul>
          )}

          {alerts.length === 0 ? (
            <p className="px-4 py-8 text-center text-[13px] text-slate-500">
              {collects ? 'No hay cuotas vencidas. Cuando una cuota pase su fecha sin pagarse, aparecerá aquí.' : pendingTotal === 0 ? 'No tienes pendientes por atender.' : 'Eso es todo por ahora.'}
            </p>
          ) : (
            <ul className="overflow-y-auto divide-y divide-slate-100">
              {alerts.map((alert) => {
                const unseen = !seen.includes(alert.id);

                return (
                  <li key={alert.id}>
                    <button type="button" className="w-full text-left px-4 py-3 flex items-start gap-3 hover:bg-brand-50/50 cursor-pointer" onClick={() => view(alert)}>
                      <span className={`mt-1.5 w-2 h-2 shrink-0 rounded-full ${unseen ? 'bg-rose-500' : 'bg-slate-300'}`} aria-hidden="true" />
                      <span className="min-w-0 flex-1">
                        <span className="flex items-baseline justify-between gap-3">
                          <span className={`text-[13px] text-slate-900 ${unseen ? 'font-semibold' : 'font-medium'}`}>
                            Cuota vencida{unseen ? '' : ' · visto'}
                          </span>
                          <span className="text-[13px] font-semibold text-slate-900 tnum whitespace-nowrap">{formatMoney(alert.amount, alert.order.currency)}</span>
                        </span>
                        <span className="block text-[13px] text-slate-600">{describe(alert)}</span>
                        <span className="block text-xs text-slate-500 tnum">Orden {orderCode(alert.order.number)}</span>
                      </span>
                    </button>
                  </li>
                );
              })}
            </ul>
          )}

          {alerts.length > 0 && (
            <div className="px-4 py-2.5 border-t border-slate-200/80 text-right">
              <button
                type="button"
                className="text-xs font-semibold text-brand-700 underline cursor-pointer"
                onClick={() => {
                  setOpen(false);
                  navigate('/billing');
                }}
              >
                Ver todas las cuotas por cobrar
              </button>
            </div>
          )}
        </div>
      )}

      <button
        type="button"
        aria-label={badge > 0 ? `Avisos: ${badge} por atender` : 'Avisos'}
        aria-expanded={open}
        aria-controls="lista-de-avisos"
        className="pointer-events-auto relative w-12 h-12 rounded-full bg-gradient-to-br from-brand-700 to-accent-500 text-white shadow-raised flex items-center justify-center cursor-pointer hover:brightness-110 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
        onClick={() => setOpen((current) => !current)}
      >
        <Bell className="w-5 h-5" />
        {badge > 0 && (
          <span className="absolute -top-1 -right-1 min-w-5 h-5 px-1 rounded-full bg-rose-600 text-white text-[11px] font-bold flex items-center justify-center ring-2 ring-white tnum">
            {badge > 9 ? '9+' : badge}
          </span>
        )}
      </button>
    </div>
  );
};
