import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { ArrowRight, CalendarClock, CreditCard } from 'lucide-react';
import { api } from '../../../shared/api/client';
import { Card } from '../../../shared/ui';
import { formatMoney } from '../../sales/api';

// Lo que la academia ve de su plan en la plataforma
export interface AcademySubscription {
  state: 'TRIAL' | 'TRIAL_EXPIRED' | 'ACTIVE' | 'EXPIRED' | 'UNLIMITED';
  plan: { id: string; name: string; price: string; currency: string; billing_months: number } | null;
  ends_at: string | null;
  days_left: number | null;
  max_students: number | null;
  students: number;
  seats_left: number | null;
}

const formatDay = (value: string | null) => (value ? new Date(value).toLocaleDateString('es-PE', { day: 'numeric', month: 'long', year: 'numeric' }) : '');

function describe(subscription: AcademySubscription): { title: string; text: string; warning: boolean } {
  const until = formatDay(subscription.ends_at);

  switch (subscription.state) {
    case 'TRIAL':
      return {
        title: 'Prueba gratuita',
        text: `Termina el ${until}: ${subscription.days_left === 1 ? 'queda 1 día' : `quedan ${subscription.days_left} días`}. Para seguir después, elige un plan.`,
        warning: (subscription.days_left ?? 0) <= 3,
      };
    case 'TRIAL_EXPIRED':
      return { title: 'Tu prueba gratuita terminó', text: `Venció el ${until}. Elige un plan para continuar.`, warning: true };
    case 'ACTIVE':
      return { title: subscription.plan?.name ?? 'Plan', text: until ? `Pagado hasta el ${until}.` : 'Sin fecha de vencimiento.', warning: (subscription.days_left ?? 99) <= 7 };
    case 'EXPIRED':
      return { title: subscription.plan?.name ?? 'Plan', text: `Tu plan venció el ${until}. Renuévalo para continuar.`, warning: true };
    default:
      return { title: 'Sin límites', text: 'Tu academia no tiene tope de alumnos ni fecha de vencimiento.', warning: false };
  }
}

/**
 * Plan de la academia en la plataforma: situación, vencimiento y cupo de
 * alumnos. En Configuración se carga sola y enlaza a "Mi plan"; en "Mi plan"
 * recibe la suscripción ya cargada.
 */
export const PlanCard: React.FC<{ subscription?: AcademySubscription | null }> = ({ subscription: given }) => {
  const standalone = given === undefined;
  const [loaded, setLoaded] = useState<AcademySubscription | null>(null);

  useEffect(() => {
    if (!standalone) return;
    let active = true;
    api
      .get('/subscription')
      .then((response) => active && setLoaded(response.data.data))
      // Sin esta información la configuración funciona igual: no se muestra la tarjeta
      .catch(() => undefined);
    return () => {
      active = false;
    };
  }, [standalone]);

  const subscription = standalone ? loaded : given;
  if (!subscription) return null;

  const info = describe(subscription);
  const trial = subscription.state === 'TRIAL' || subscription.state === 'TRIAL_EXPIRED';
  const used = subscription.max_students ? Math.min(100, Math.round((subscription.students / subscription.max_students) * 100)) : null;
  const full = subscription.seats_left === 0;

  return (
    <Card className={`p-5 mb-5 ${info.warning ? 'ring-1 ring-amber-300' : ''}`}>
      <div className="flex flex-wrap items-start gap-3">
        <span className={`w-10 h-10 shrink-0 rounded-xl inline-flex items-center justify-center ${info.warning ? 'bg-amber-50 text-amber-600' : 'bg-brand-50 text-brand-700'}`}>
          {trial ? <CalendarClock className="w-5 h-5" /> : <CreditCard className="w-5 h-5" />}
        </span>
        <div className="min-w-0 flex-1">
          <p className="text-xs font-semibold text-slate-500">Tu plan en IntelectaLab</p>
          <h2 className="text-base font-bold text-slate-900">{info.title}</h2>
          <p className="text-[13px] text-slate-600">{info.text}</p>
          {subscription.plan && subscription.state !== 'UNLIMITED' && (
            <p className="text-xs text-slate-500 mt-0.5">
              {formatMoney(subscription.plan.price, subscription.plan.currency)} {subscription.plan.billing_months === 12 ? 'al año' : 'al mes'}
            </p>
          )}
        </div>
        {standalone && (
          <Link
            to="/settings/plan"
            className="inline-flex items-center gap-1.5 h-9 px-3.5 rounded-lg border border-slate-300 bg-white text-[13px] font-semibold text-slate-700 hover:bg-slate-50"
          >
            Ver planes y pagos <ArrowRight className="w-4 h-4" />
          </Link>
        )}
      </div>

      {subscription.max_students !== null && (
        <div className="mt-4">
          <div className="flex items-baseline justify-between text-xs mb-1.5">
            <span className="font-medium text-slate-600">Alumnos con matrícula activa</span>
            <span className={`font-semibold tnum ${full ? 'text-amber-700' : 'text-slate-900'}`}>
              {subscription.students} de {subscription.max_students}
            </span>
          </div>
          <div className="h-2 rounded-full bg-slate-200 overflow-hidden" aria-hidden="true">
            <div className={`h-full rounded-full ${full ? 'bg-amber-500' : 'bg-brand-500'}`} style={{ width: `${used ?? 0}%` }} />
          </div>
          <p className="text-xs text-slate-500 mt-1.5">
            Cuenta cada persona con al menos una matrícula activa.{full && ' Ya usas todo el cupo de tu plan.'}
          </p>
        </div>
      )}
    </Card>
  );
};
