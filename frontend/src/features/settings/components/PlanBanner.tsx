import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { AlertTriangle, BellRing, Lock } from 'lucide-react';
import { api } from '../../../shared/api/client';
import type { AcademySubscription } from './PlanCard';

// Orden de plan que se resolvió y la academia todavía no vio
interface OrderNotice {
  number: number;
  status: 'PAID' | 'CANCELLED' | 'EXPIRED';
  plan_name: string;
  notes: string | null;
}

const formatDay = (value: string | null) => (value ? new Date(value).toLocaleDateString('es-PE', { day: 'numeric', month: 'long' }) : '');

function message(subscription: AcademySubscription): { strong: string; text: string; action: string; danger: boolean } | null {
  const what = subscription.state === 'TRIAL_EXPIRED' ? 'Tu prueba gratuita' : 'Tu plan';
  const action = subscription.state === 'TRIAL_EXPIRED' ? 'Elegir un plan' : 'Renovar el plan';

  if (subscription.access === 'READ_ONLY') {
    return {
      strong: 'Tu academia está en solo lectura.',
      text: `${what} venció el ${formatDay(subscription.ends_at)}: se puede consultar todo y tus alumnos siguen estudiando, pero no se pueden registrar cambios.`,
      action,
      danger: true,
    };
  }
  if (subscription.access === 'GRACE') {
    const left = subscription.grace_days_left ?? 0;
    return {
      strong: `${what} venció el ${formatDay(subscription.ends_at)}.`,
      text: `${left === 0 ? 'Hoy es el último día' : left === 1 ? 'Queda 1 día' : `Quedan ${left} días`} antes de que la academia pase a solo lectura.`,
      action,
      danger: false,
    };
  }
  if (subscription.seats_left === 0) {
    return {
      strong: `Ya usas los ${subscription.max_students} alumnos de tu plan.`,
      text: 'No podrás matricular ni vender a personas nuevas hasta pasar a un plan mayor.',
      action: 'Ver planes',
      danger: false,
    };
  }
  return null;
}

function orderMessage(notice: OrderNotice): { strong: string; text: string; good: boolean } {
  const code = `P-${String(notice.number).padStart(4, '0')}`;

  switch (notice.status) {
    case 'PAID':
      return { strong: `Pago confirmado: tu plan «${notice.plan_name}» ya está vigente.`, text: `Orden ${code}.`, good: true };
    case 'CANCELLED':
      return {
        strong: `IntelectaLab canceló tu orden ${code} del plan «${notice.plan_name}».`,
        text: notice.notes ? `Motivo: ${notice.notes}` : 'Puedes pedir otro plan cuando quieras.',
        good: false,
      };
    default:
      return { strong: `Tu orden ${code} del plan «${notice.plan_name}» venció sin pago.`, text: 'Si aún quieres el plan, pídelo de nuevo.', good: false };
  }
}

/**
 * Avisos fijos sobre el plan de la academia para quien administra su
 * configuración: plan vencido (días de gracia o solo lectura), cupo lleno y
 * cómo se resolvió su última orden de plan, hasta que lo marque como visto.
 */
export const PlanBanner: React.FC = () => {
  const [subscription, setSubscription] = useState<AcademySubscription | null>(null);
  const [notice, setNotice] = useState<OrderNotice | null>(null);

  useEffect(() => {
    let active = true;
    api
      .get('/subscription')
      .then((response) => {
        if (!active) return;
        setSubscription(response.data.data);
        setNotice(response.data.meta?.order_notice ?? null);
      })
      // Sin este dato el sistema funciona igual: no se muestra el aviso
      .catch(() => undefined);
    return () => {
      active = false;
    };
  }, []);

  const dismiss = () => {
    setNotice(null);
    // Si no se llega a guardar, el aviso vuelve a salir al recargar
    api.post('/subscription/notice/seen').catch(() => undefined);
  };

  const info = subscription ? message(subscription) : null;
  const order = notice ? orderMessage(notice) : null;
  const Icon = info?.danger ? Lock : AlertTriangle;

  return (
    <>
      {order && (
        <div
          role="status"
          className={`flex flex-wrap items-center justify-center gap-x-3 gap-y-1 px-4 py-2 border-b text-[13px] ${
            order.good ? 'bg-emerald-100 border-emerald-200 text-emerald-900' : 'bg-slate-100 border-slate-200 text-slate-800'
          }`}
        >
          <BellRing className="w-4 h-4 shrink-0" />
          <span>
            <strong>{order.strong}</strong> {order.text}
          </span>
          <Link to="/settings/plan" className="font-semibold underline">
            Ver mi plan
          </Link>
          <button type="button" onClick={dismiss} className="font-semibold underline cursor-pointer">
            Entendido
          </button>
        </div>
      )}
      {info && (
        <div
          role="status"
          className={`flex flex-wrap items-center justify-center gap-x-3 gap-y-1 px-4 py-2 border-b text-[13px] ${
            info.danger ? 'bg-rose-100 border-rose-200 text-rose-900' : 'bg-amber-100 border-amber-200 text-amber-900'
          }`}
        >
          <Icon className="w-4 h-4 shrink-0" />
          <span>
            <strong>{info.strong}</strong> {info.text}
          </span>
          <Link to="/settings/plan" className="font-semibold underline">
            {info.action}
          </Link>
        </div>
      )}
    </>
  );
};
