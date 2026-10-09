import React, { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { ArrowLeft, CheckCircle2, CreditCard, Hourglass, Users } from 'lucide-react';
import { useAuth } from '../../auth/AuthContext';
import { api, apiErrorMessage } from '../../../shared/api/client';
import { Alert, Badge, Button, Card, CardHeader, ConfirmModal, EmptyState, ErrorState, Field, INPUT_CLASS, LoadingState, PageHeader, Table, TBody, Td, Th, THead, Tr, type Tone } from '../../../shared/ui';
import { formatMoney } from '../../sales/api';
import { PlanCard, type AcademySubscription } from '../components/PlanCard';

interface OfferedPlan {
  id: string;
  name: string;
  description: string | null;
  price: string;
  currency: string;
  billing_months: number;
  max_students: number | null;
}

interface PlanOrder {
  id: string;
  number: number;
  plan_name: string;
  billing_months: number;
  max_students: number | null;
  amount: string;
  currency: string;
  status: 'PENDING' | 'PAID' | 'CANCELLED';
  payer_reference: string | null;
  notes: string | null;
  created_at: string;
  paid_at: string | null;
}

interface MyPlan {
  subscription: AcademySubscription | null;
  plans: OfferedPlan[];
  payment_instructions: string | null;
  pending_order: PlanOrder | null;
  orders: PlanOrder[];
}

const STATUS: Record<PlanOrder['status'], { label: string; tone: Tone }> = {
  PENDING: { label: 'Pendiente de pago', tone: 'warning' },
  PAID: { label: 'Pagada', tone: 'success' },
  CANCELLED: { label: 'Cancelada', tone: 'neutral' },
};

const formatDay = (value: string | null) => (value ? new Date(value).toLocaleDateString('es-PE', { day: 'numeric', month: 'short', year: 'numeric' }) : '—');
const period = (months: number) => (months === 12 ? 'al año' : 'al mes');
const orderCode = (order: PlanOrder) => `P-${String(order.number).padStart(4, '0')}`;

/* ───────────────────────────── Orden pendiente ───────────────────────────── */

const PendingOrder: React.FC<{ order: PlanOrder; instructions: string | null; editable: boolean; onChanged: () => Promise<void> }> = ({
  order,
  instructions,
  editable,
  onChanged,
}) => {
  const [reference, setReference] = useState(order.payer_reference ?? '');
  const [saving, setSaving] = useState(false);
  const [notice, setNotice] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);
  const [cancelling, setCancelling] = useState(false);

  const save = async (event: React.FormEvent) => {
    event.preventDefault();
    setSaving(true);
    setNotice(null);
    try {
      await api.put(`/subscription/orders/${order.id}/payment`, { payer_reference: reference.trim() || null });
      await onChanged();
      setNotice({ tone: 'success', text: 'Listo: IntelectaLab verá este dato al revisar tu pago.' });
    } catch (err) {
      setNotice({ tone: 'danger', text: apiErrorMessage(err, 'No se pudo guardar.') });
    } finally {
      setSaving(false);
    }
  };

  return (
    <Card className="mb-5 ring-1 ring-amber-300">
      <div className="flex flex-wrap items-start gap-3 px-5 py-4 border-b border-slate-200/80">
        <span className="w-10 h-10 shrink-0 rounded-xl bg-amber-50 text-amber-600 inline-flex items-center justify-center">
          <Hourglass className="w-5 h-5" />
        </span>
        <div className="min-w-0 flex-1">
          <p className="text-xs font-semibold text-slate-500">Orden {orderCode(order)} · pendiente de pago</p>
          <h2 className="text-base font-bold text-slate-900">
            {order.plan_name} · {formatMoney(order.amount, order.currency)}
          </h2>
          <p className="text-[13px] text-slate-600">
            {order.billing_months === 12 ? 'Un año' : 'Un mes'} de plan{order.max_students ? `, hasta ${order.max_students} alumnos` : ', sin tope de alumnos'}. Tu plan
            cambia cuando IntelectaLab confirme el pago.
          </p>
        </div>
      </div>
      <div className="p-5 space-y-4">
        <div>
          <h3 className="text-[13px] font-semibold text-slate-900 mb-1">Cómo pagar</h3>
          {instructions ? (
            <p className="text-[13px] leading-relaxed text-slate-700 whitespace-pre-wrap break-words p-3.5 rounded-xl border border-slate-200 bg-slate-50/60">{instructions}</p>
          ) : (
            <Alert tone="info">IntelectaLab aún no publicó sus datos de pago. Comunícate con ellos e indica el número de esta orden.</Alert>
          )}
        </div>
        {editable && (
          <form onSubmit={save} className="space-y-3">
            {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}
            <Field label="¿Ya pagaste? Indica cómo ubicar tu pago (opcional)" hint="Por ejemplo: «Transferencia BCP, operación 123456, 8 de octubre».">
              <input className={INPUT_CLASS} maxLength={300} value={reference} onChange={(event) => setReference(event.target.value)} />
            </Field>
            <div className="flex flex-wrap gap-2">
              <Button variant="primary" type="submit" processing={saving}>
                Informar pago
              </Button>
              <Button onClick={() => setCancelling(true)}>Cancelar esta orden</Button>
            </div>
          </form>
        )}
      </div>

      {cancelling && (
        <ConfirmModal
          title="Cancelar la orden"
          text={`Se cancelará la orden ${orderCode(order)}. Si ya pagaste, no la canceles: espera a que IntelectaLab confirme tu pago. Podrás pedir otro plan después.`}
          confirmLabel="Cancelar la orden"
          onClose={() => setCancelling(false)}
          onConfirm={async () => {
            await api.post(`/subscription/orders/${order.id}/cancel`);
            setCancelling(false);
            await onChanged();
          }}
        />
      )}
    </Card>
  );
};

/* ───────────────────────────── Página ───────────────────────────── */

// "Mi plan": la academia ve su situación, elige un plan y le da seguimiento a su pago
export const PlanPage: React.FC = () => {
  const { hasPermission } = useAuth();
  const editable = hasPermission('tenant_settings.update');
  const [data, setData] = useState<MyPlan | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [choosing, setChoosing] = useState<OfferedPlan | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setData((await api.get('/subscription/plans')).data.data);
      setError(null);
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo cargar tu plan.'));
    }
  }, []);

  useEffect(() => {
    const timer = setTimeout(load, 0);
    return () => clearTimeout(timer);
  }, [load]);

  const back = (
    <Link to="/settings" className="inline-flex items-center gap-1.5 text-[13px] font-medium text-slate-500 hover:text-brand-700 mb-4">
      <ArrowLeft className="w-4 h-4" /> Configuración
    </Link>
  );

  if (error && !data) {
    return (
      <>
        {back}
        <Card>
          <ErrorState message={error} onRetry={load} />
        </Card>
      </>
    );
  }
  if (!data) return <LoadingState />;

  const currentPlanId = data.subscription?.state === 'ACTIVE' ? data.subscription.plan?.id : null;
  const students = data.subscription?.students ?? 0;
  const past = data.orders.filter((order) => order.status !== 'PENDING');

  return (
    <div>
      {back}
      <PageHeader title="Mi plan" icon={CreditCard} subtitle="Lo que tu academia paga a IntelectaLab, según cuántos alumnos tiene." />

      {notice && (
        <div className="mb-4">
          <Alert tone="success">{notice}</Alert>
        </div>
      )}

      <PlanCard subscription={data.subscription} />

      {data.pending_order && <PendingOrder order={data.pending_order} instructions={data.payment_instructions} editable={editable} onChanged={load} />}

      <Card className="mb-5">
        <CardHeader
          title="Planes disponibles"
          text={`Cuenta como alumno cada persona con al menos una matrícula activa. Hoy tienes ${students} ${students === 1 ? 'alumno' : 'alumnos'}.`}
        />
        {data.plans.length === 0 ? (
          <EmptyState icon={CreditCard} title="Aún no hay planes publicados" text="Comunícate con IntelectaLab para definir tu plan." />
        ) : (
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 p-5">
            {data.plans.map((plan) => {
              const current = plan.id === currentPlanId;
              const tooSmall = plan.max_students !== null && students > plan.max_students;
              return (
                <div key={plan.id} className={`flex flex-col p-4 rounded-2xl border ${current ? 'border-brand-500 bg-brand-50/50 ring-4 ring-brand-500/10' : 'border-slate-200'}`}>
                  <div className="flex items-start justify-between gap-2">
                    <h3 className="text-[15px] font-bold text-slate-900">{plan.name}</h3>
                    {current && <Badge tone="brand">Tu plan</Badge>}
                  </div>
                  {plan.description && <p className="text-[13px] text-slate-500 mt-1">{plan.description}</p>}
                  <p className="mt-3 text-2xl font-bold text-slate-900 tnum">
                    {formatMoney(plan.price, plan.currency)} <span className="text-[13px] font-medium text-slate-500">{period(plan.billing_months)}</span>
                  </p>
                  <p className="inline-flex items-center gap-1.5 mt-2 text-[13px] text-slate-700">
                    <Users className="w-4 h-4 text-slate-500" />
                    {plan.max_students === null ? 'Sin tope de alumnos' : `Hasta ${plan.max_students} alumnos`}
                  </p>
                  {tooSmall && <p className="text-xs font-medium text-amber-700 mt-1.5">Ya tienes más alumnos de los que permite.</p>}
                  {editable && (
                    <div className="mt-auto pt-4">
                      <Button
                        variant={current ? 'secondary' : 'primary'}
                        className="w-full"
                        disabled={data.pending_order !== null}
                        title={data.pending_order ? 'Ya tienes una orden pendiente: págala o cancélala primero' : undefined}
                        onClick={() => setChoosing(plan)}
                      >
                        {current ? 'Renovar este plan' : 'Elegir este plan'}
                      </Button>
                    </div>
                  )}
                </div>
              );
            })}
          </div>
        )}
      </Card>

      <Card>
        <CardHeader title="Pagos anteriores" />
        {past.length === 0 ? (
          <EmptyState icon={CheckCircle2} title="Aún no hay pagos" />
        ) : (
          <Table>
            <THead>
              <Th>Orden</Th>
              <Th>Plan</Th>
              <Th>Importe</Th>
              <Th>Pedida</Th>
              <Th>Estado</Th>
            </THead>
            <TBody>
              {past.map((order) => (
                <Tr key={order.id}>
                  <Td className="whitespace-nowrap font-semibold text-slate-900 tnum">{orderCode(order)}</Td>
                  <Td className="whitespace-nowrap text-slate-700">
                    {order.plan_name} · {order.billing_months === 12 ? 'un año' : 'un mes'}
                  </Td>
                  <Td className="whitespace-nowrap text-slate-900 tnum">{formatMoney(order.amount, order.currency)}</Td>
                  <Td className="whitespace-nowrap text-slate-600 tnum">{formatDay(order.created_at)}</Td>
                  <Td>
                    <Badge tone={STATUS[order.status].tone} dot>
                      {STATUS[order.status].label}
                      {order.status === 'PAID' ? ` el ${formatDay(order.paid_at)}` : ''}
                    </Badge>
                    {order.status === 'CANCELLED' && order.notes && <span className="block text-xs text-slate-500 mt-0.5">{order.notes}</span>}
                  </Td>
                </Tr>
              ))}
            </TBody>
          </Table>
        )}
      </Card>

      {choosing && (
        <ConfirmModal
          title={`Pedir el plan «${choosing.name}»`}
          text={`Se creará una orden por ${formatMoney(choosing.price, choosing.currency)} (${choosing.billing_months === 12 ? 'un año' : 'un mes'}). Verás cómo pagarla; tu plan cambia cuando IntelectaLab confirme el pago.`}
          confirmLabel="Crear la orden"
          variant="primary"
          onClose={() => setChoosing(null)}
          onConfirm={async () => {
            await api.post('/subscription/orders', { plan_id: choosing.id });
            setChoosing(null);
            setNotice('Orden creada. Sigue las instrucciones de pago; avísanos cuando hayas pagado.');
            await load();
          }}
        />
      )}
    </div>
  );
};
