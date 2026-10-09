import React, { useEffect, useState } from 'react';
import { CalendarClock, CreditCard } from 'lucide-react';
import { apiErrorMessage } from '../../../shared/api/client';
import { Alert, Button, Card, Field, INPUT_CLASS, LoadingState, Modal } from '../../../shared/ui';
import { formatMoney } from '../../sales/api';
import { platformApi, type Plan, type Subscription } from '../api';

const formatDay = (value: string | null) => (value ? new Date(value).toLocaleDateString('es-PE', { day: 'numeric', month: 'long', year: 'numeric' }) : null);

const period = (plan: Plan) => (plan.billing_months === 12 ? 'al año' : 'al mes');

// AAAA-MM-DD de hoy más unos días, en la hora local
function dayFromNow(days: number): string {
  const date = new Date();
  date.setDate(date.getDate() + days);
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function summary(subscription: Subscription): { title: string; text: string; warning: boolean } {
  const until = formatDay(subscription.ends_at);
  const what = subscription.state === 'TRIAL_EXPIRED' ? 'La prueba gratuita' : `El plan «${subscription.plan?.name ?? ''}»`;

  if (subscription.access === 'READ_ONLY') {
    return {
      title: 'En solo lectura',
      text: `${what} venció el ${until} y la gracia terminó el ${formatDay(subscription.grace_ends_at)}. La academia consulta y sus alumnos estudian, pero no registra cambios hasta tener un plan vigente.`,
      warning: true,
    };
  }
  if (subscription.access === 'GRACE') {
    return {
      title: subscription.state === 'TRIAL_EXPIRED' ? 'Prueba gratuita vencida' : `${subscription.plan?.name ?? 'Plan'} · vencido`,
      text: `${what} venció el ${until}. Sigue operando hasta el ${formatDay(subscription.grace_ends_at)}; después pasa a solo lectura.`,
      warning: true,
    };
  }

  switch (subscription.state) {
    case 'TRIAL':
      return { title: 'Prueba gratuita', text: `Termina el ${until} (${subscription.days_left === 1 ? 'queda 1 día' : `quedan ${subscription.days_left} días`}).`, warning: false };
    case 'TRIAL_EXPIRED':
      return { title: 'Prueba gratuita vencida', text: `Terminó el ${until}. Aún no tiene un plan.`, warning: true };
    case 'ACTIVE':
      return { title: subscription.plan?.name ?? 'Plan', text: until ? `Pagado hasta el ${until}.` : 'Sin fecha de vencimiento.', warning: false };
    case 'EXPIRED':
      return { title: subscription.plan?.name ?? 'Plan', text: `Venció el ${until}. Falta registrar su renovación.`, warning: true };
    default:
      return { title: 'Sin plan', text: 'No tiene tope de alumnos ni fecha de vencimiento.', warning: false };
  }
}

/* ───────────────────────────── Asignar plan ───────────────────────────── */

const AssignPlanModal: React.FC<{ tenantId: string; current: Subscription | null; onClose: () => void; onDone: () => Promise<void> }> = ({
  tenantId,
  current,
  onClose,
  onDone,
}) => {
  const [plans, setPlans] = useState<Plan[] | null>(null);
  // '' = sin plan (sin límites)
  const [planId, setPlanId] = useState(current?.plan?.status === 'ACTIVE' ? current.plan.id : '');
  const [endsAt, setEndsAt] = useState('');
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let active = true;
    platformApi
      .plans()
      .then((result) => active && setPlans(result.plans.filter((plan) => plan.status === 'ACTIVE')))
      .catch((err) => active && setError(apiErrorMessage(err, 'No se pudieron cargar los planes.')));
    return () => {
      active = false;
    };
  }, []);

  const chosen = plans?.find((plan) => plan.id === planId) ?? null;

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setProcessing(true);
    setError(null);
    try {
      await platformApi.assignPlan(tenantId, planId || null, planId && endsAt ? endsAt : null);
      await onDone();
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo asignar el plan.'));
      setProcessing(false);
    }
  };

  return (
    <Modal
      title="Asignar plan"
      subtitle="Úsalo al recibir el pago de la academia, o para dejarla sin límites."
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="assign-plan-form" processing={processing} disabled={plans === null}>
            Guardar
          </Button>
        </>
      }
    >
      <form id="assign-plan-form" onSubmit={submit} className="space-y-4">
        {error && <Alert>{error}</Alert>}
        {plans === null && !error && <LoadingState />}
        {plans !== null && (
          <>
            <Field label="Plan" hint={plans.length === 0 ? 'Aún no hay planes: créalos en la sección Planes.' : undefined}>
              <select className={INPUT_CLASS} value={planId} onChange={(event) => setPlanId(event.target.value)} autoFocus>
                <option value="">Sin plan (sin tope de alumnos ni vencimiento)</option>
                {plans.map((plan) => (
                  <option key={plan.id} value={plan.id}>
                    {plan.name} · {formatMoney(plan.price, plan.currency)} {period(plan)}
                  </option>
                ))}
              </select>
            </Field>
            {chosen && (
              <>
                <p className="text-[13px] text-slate-600">
                  {chosen.max_students === null ? 'Sin tope de alumnos.' : `Hasta ${chosen.max_students} alumnos con matrícula activa.`}
                  {current && chosen.max_students !== null && current.students > chosen.max_students && (
                    <span className="block mt-1 font-medium text-amber-700">Hoy tiene {current.students} alumnos: ya supera el tope de este plan.</span>
                  )}
                </p>
                <Field label="Pagado hasta (opcional)" hint={`Vacío: ${chosen.billing_months === 12 ? 'un año' : 'un mes'} desde hoy.`}>
                  <input className={`${INPUT_CLASS} max-w-48`} type="date" min={dayFromNow(1)} value={endsAt} onChange={(event) => setEndsAt(event.target.value)} />
                </Field>
              </>
            )}
          </>
        )}
      </form>
    </Modal>
  );
};

/* ───────────────────────────── Extender la prueba ───────────────────────────── */

const ExtendTrialModal: React.FC<{ tenantId: string; onClose: () => void; onDone: () => Promise<void> }> = ({ tenantId, onClose, onDone }) => {
  const [until, setUntil] = useState(dayFromNow(14));
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setProcessing(true);
    setError(null);
    try {
      await platformApi.extendTrial(tenantId, until);
      await onDone();
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo cambiar la fecha de la prueba.'));
      setProcessing(false);
    }
  };

  return (
    <Modal
      title="Cambiar el fin de la prueba"
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="extend-trial-form" processing={processing}>
            Guardar
          </Button>
        </>
      }
    >
      <form id="extend-trial-form" onSubmit={submit} className="space-y-4">
        {error && <Alert>{error}</Alert>}
        <Field label="La prueba termina el">
          <input className={`${INPUT_CLASS} max-w-48`} type="date" required min={dayFromNow(1)} autoFocus value={until} onChange={(event) => setUntil(event.target.value)} />
        </Field>
      </form>
    </Modal>
  );
};

/* ───────────────────────────── Tarjeta ───────────────────────────── */

// Prueba o plan de la academia, con su cupo de alumnos y las acciones de la plataforma
export const SubscriptionCard: React.FC<{ tenantId: string; subscription: Subscription | null; onChanged: () => Promise<void> }> = ({
  tenantId,
  subscription,
  onChanged,
}) => {
  const [dialog, setDialog] = useState<'assign' | 'trial' | null>(null);
  const info = subscription ? summary(subscription) : { title: 'Sin suscripción', text: 'Asígnale un plan para empezar a controlarla.', warning: true };
  const inTrial = subscription?.state === 'TRIAL' || subscription?.state === 'TRIAL_EXPIRED';
  const used = subscription && subscription.max_students ? Math.min(100, Math.round((subscription.students / subscription.max_students) * 100)) : null;
  const over = subscription !== null && subscription.max_students !== null && subscription.students > subscription.max_students;

  const done = async () => {
    await onChanged();
    setDialog(null);
  };

  return (
    <Card className={`p-5 mb-5 ${info.warning ? 'ring-1 ring-amber-300' : ''}`}>
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div className="flex items-start gap-3 min-w-0">
          <span className={`w-10 h-10 shrink-0 rounded-xl inline-flex items-center justify-center ${info.warning ? 'bg-amber-50 text-amber-600' : 'bg-brand-50 text-brand-700'}`}>
            {inTrial ? <CalendarClock className="w-5 h-5" /> : <CreditCard className="w-5 h-5" />}
          </span>
          <div className="min-w-0">
            <p className="text-xs font-semibold text-slate-500">Plan de la academia</p>
            <h2 className="text-base font-bold text-slate-900">{info.title}</h2>
            <p className="text-[13px] text-slate-600">{info.text}</p>
            {subscription?.plan && subscription.state !== 'UNLIMITED' && (
              <p className="text-xs text-slate-500 mt-0.5">
                {formatMoney(subscription.plan.price, subscription.plan.currency)} {period(subscription.plan)}
              </p>
            )}
          </div>
        </div>
        <div className="flex flex-wrap gap-2">
          {inTrial && <Button onClick={() => setDialog('trial')}>Cambiar fin de la prueba</Button>}
          <Button variant="primary" onClick={() => setDialog('assign')}>
            Asignar plan
          </Button>
        </div>
      </div>

      {subscription && (
        <div className="mt-4">
          <div className="flex items-baseline justify-between text-xs mb-1.5">
            <span className="font-medium text-slate-600">Alumnos con matrícula activa</span>
            <span className={`font-semibold tnum ${over ? 'text-amber-700' : 'text-slate-900'}`}>
              {subscription.max_students === null ? `${subscription.students} · sin tope` : `${subscription.students} de ${subscription.max_students}`}
            </span>
          </div>
          {used !== null && (
            <div className="h-2 rounded-full bg-slate-200 overflow-hidden" aria-hidden="true">
              <div className={`h-full rounded-full ${over || used >= 100 ? 'bg-amber-500' : 'bg-brand-500'}`} style={{ width: `${used}%` }} />
            </div>
          )}
          {over && <p className="text-xs font-medium text-amber-700 mt-1.5">Supera el tope de su plan.</p>}
        </div>
      )}

      {dialog === 'assign' && <AssignPlanModal tenantId={tenantId} current={subscription} onClose={() => setDialog(null)} onDone={done} />}
      {dialog === 'trial' && <ExtendTrialModal tenantId={tenantId} onClose={() => setDialog(null)} onDone={done} />}
    </Card>
  );
};
