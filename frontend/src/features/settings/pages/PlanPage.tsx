import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { ArrowLeft, CheckCircle2, Clock, CreditCard, FileCheck2, Hourglass, Paperclip, Users } from 'lucide-react';
import { useAuth } from '../../auth/AuthContext';
import { api, apiErrorMessage } from '../../../shared/api/client';
import { Alert, Badge, Button, Card, CardHeader, ConfirmModal, EmptyState, ErrorState, Field, INPUT_CLASS, LoadingState, PageHeader, Table, TBody, Td, Th, THead, Tr, type Tone } from '../../../shared/ui';
import { formatMoney, type OnlineGatewayOption } from '../../sales/api';
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
  status: 'PENDING' | 'PAID' | 'CANCELLED' | 'EXPIRED';
  payer_reference: string | null;
  // Constancia de pago adjunta
  proof: { name: string; size_bytes: number } | null;
  // La cobró una pasarela (no la confirmó una persona)
  paid_online: boolean;
  notes: string | null;
  created_at: string;
  // Hasta cuándo espera su pago; con un pago informado ya no vence sola
  expires_at: string | null;
  paid_at: string | null;
}

interface MyPlan {
  subscription: AcademySubscription | null;
  plans: OfferedPlan[];
  payment_instructions: string | null;
  pending_order: PlanOrder | null;
  // Pasarelas con las que se puede pagar la orden pendiente en línea
  online_gateways: OnlineGatewayOption[];
  proof: { max_kb: number; extensions: string[] };
  orders: PlanOrder[];
}

const STATUS: Record<PlanOrder['status'], { label: string; tone: Tone }> = {
  PENDING: { label: 'Pendiente de pago', tone: 'warning' },
  PAID: { label: 'Pagada', tone: 'success' },
  CANCELLED: { label: 'Cancelada', tone: 'neutral' },
  EXPIRED: { label: 'Vencida sin pago', tone: 'danger' },
};

const formatDay = (value: string | null) => (value ? new Date(value).toLocaleDateString('es-PE', { day: 'numeric', month: 'short', year: 'numeric' }) : '—');
const period = (months: number) => (months === 12 ? 'al año' : 'al mes');
const orderCode = (order: PlanOrder) => `P-${String(order.number).padStart(4, '0')}`;

/* ───────────────────────────── Orden pendiente ───────────────────────────── */

const PendingOrder: React.FC<{
  order: PlanOrder;
  instructions: string | null;
  gateways: MyPlan['online_gateways'];
  proofRules: MyPlan['proof'];
  editable: boolean;
  onChanged: () => Promise<void>;
}> = ({ order, instructions, gateways, proofRules, editable, onChanged }) => {
  const [reference, setReference] = useState(order.payer_reference ?? '');
  const [saving, setSaving] = useState(false);
  const [notice, setNotice] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);
  const [cancelling, setCancelling] = useState(false);
  const [paying, setPaying] = useState<string | null>(null);
  const [payError, setPayError] = useState<string | null>(null);
  const [uploading, setUploading] = useState(false);
  const fileInput = useRef<HTMLInputElement>(null);
  const informed = Boolean(order.payer_reference) || order.proof !== null;

  const save = async (event: React.FormEvent) => {
    event.preventDefault();
    setSaving(true);
    setNotice(null);
    try {
      await api.put(`/subscription/orders/${order.id}/payment`, { payer_reference: reference.trim() || null });
      await onChanged();
      setNotice({ tone: 'success', text: 'Listo: IntelectaLab recibió el aviso y verá este dato al revisar tu pago.' });
    } catch (err) {
      setNotice({ tone: 'danger', text: apiErrorMessage(err, 'No se pudo guardar.') });
    } finally {
      setSaving(false);
    }
  };

  const payWith = async (gateway: string) => {
    setPaying(gateway);
    setPayError(null);
    try {
      const response = await api.post(`/subscription/orders/${order.id}/pay`, { gateway, return_url: `${window.location.origin}/settings/plan` });
      window.location.assign(response.data.data.checkout_url);
    } catch (err) {
      setPayError(apiErrorMessage(err, 'No se pudo abrir el pago en línea. Inténtalo de nuevo.'));
      setPaying(null);
    }
  };

  const attach = async (file: File | undefined) => {
    if (!file) return;
    setUploading(true);
    setNotice(null);
    try {
      const body = new FormData();
      body.append('file', file);
      await api.post(`/subscription/orders/${order.id}/proof`, body);
      await onChanged();
      setNotice({ tone: 'success', text: 'Constancia adjuntada: IntelectaLab recibió el aviso y la revisará.' });
    } catch (err) {
      setNotice({ tone: 'danger', text: apiErrorMessage(err, 'No se pudo adjuntar el archivo.') });
    } finally {
      setUploading(false);
      if (fileInput.current) fileInput.current.value = '';
    }
  };

  const removeProof = async () => {
    setUploading(true);
    try {
      await api.delete(`/subscription/orders/${order.id}/proof`);
      await onChanged();
    } catch (err) {
      setNotice({ tone: 'danger', text: apiErrorMessage(err, 'No se pudo quitar el archivo.') });
    } finally {
      setUploading(false);
    }
  };

  const openProof = async () => {
    const response = await api.get(`/subscription/orders/${order.id}/proof`, { responseType: 'blob' });
    window.open(URL.createObjectURL(response.data), '_blank', 'noopener');
  };

  return (
    <Card className="mb-5 ring-1 ring-amber-300">
      <div className="flex flex-wrap items-start gap-3 px-5 py-4 border-b border-slate-200/80">
        <span className="w-10 h-10 shrink-0 rounded-xl bg-amber-50 text-amber-600 inline-flex items-center justify-center">
          <Hourglass className="w-5 h-5" />
        </span>
        <div className="min-w-0 flex-1">
          <p className="text-xs font-semibold text-slate-500">
            Orden {orderCode(order)} · {informed ? 'pago informado, en revisión' : 'pendiente de pago'}
          </p>
          <h2 className="text-base font-bold text-slate-900">
            {order.plan_name} · {formatMoney(order.amount, order.currency)}
          </h2>
          <p className="text-[13px] text-slate-600">
            {order.billing_months === 12 ? 'Un año' : 'Un mes'} de plan{order.max_students ? `, hasta ${order.max_students} alumnos` : ', sin tope de alumnos'}. Tu plan
            cambia en cuanto se confirme el pago.
          </p>
          {order.expires_at && (
            <p className="flex items-center gap-1.5 text-xs text-slate-500 mt-1">
              <Clock className="w-3.5 h-3.5" />
              {informed
                ? 'Ya informaste tu pago: la orden espera la revisión de IntelectaLab y no vence sola.'
                : `Tienes hasta el ${formatDay(order.expires_at)} para pagarla o informar tu pago; después vence y tendrás que pedir otra.`}
            </p>
          )}
        </div>
      </div>
      <div className="p-5 space-y-5">
        {order.notes && <Alert tone="info">{order.notes}</Alert>}

        {gateways.length > 0 && editable && (
          <div>
            <h3 className="text-[13px] font-semibold text-slate-900 mb-1">Paga en línea</h3>
            <p className="text-[13px] text-slate-600 mb-3">Tu plan queda vigente al momento. Te llevamos a la página segura del medio de pago y vuelves aquí.</p>
            {payError && (
              <div className="mb-3">
                <Alert>{payError}</Alert>
              </div>
            )}
            <div className="flex flex-wrap gap-2">
              {gateways.map((gateway) => (
                <Button key={gateway.code} variant="primary" processing={paying === gateway.code} disabled={paying !== null} onClick={() => payWith(gateway.code)}>
                  Pagar con {gateway.label}
                </Button>
              ))}
            </div>
            {gateways
              .filter((gateway) => gateway.charge)
              .map((gateway) => (
                <p key={gateway.code} className="text-xs text-slate-500 mt-2">
                  {gateway.label} cobra en dólares: pagarás <strong className="text-slate-700 tnum">{formatMoney(gateway.charge!.amount, gateway.charge!.currency)}</strong>
                  {gateway.charge!.rate ? `, al tipo de cambio de S/ ${Number(gateway.charge!.rate).toFixed(2)} por dólar que fijó IntelectaLab` : ''}.
                </p>
              ))}
          </div>
        )}

        <div>
          <h3 className="text-[13px] font-semibold text-slate-900 mb-1">{gateways.length > 0 ? 'O paga por fuera' : 'Cómo pagar'}</h3>
          {instructions ? (
            <p className="text-[13px] leading-relaxed text-slate-700 whitespace-pre-wrap break-words p-3.5 rounded-xl border border-slate-200 bg-slate-50/60">{instructions}</p>
          ) : (
            <Alert tone="info">IntelectaLab aún no publicó sus datos de pago. Comunícate con ellos e indica el número de esta orden.</Alert>
          )}
        </div>

        {editable && (
          <form onSubmit={save} className="space-y-3">
            {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}

            <div>
              <p className="text-[13px] font-medium text-slate-800 mb-1.5">¿Ya pagaste? Adjunta tu constancia</p>
              {order.proof ? (
                <div className="flex flex-wrap items-center gap-2 p-3 rounded-xl border border-emerald-200 bg-emerald-50/60">
                  <FileCheck2 className="w-4 h-4 text-emerald-600 shrink-0" />
                  <button type="button" onClick={openProof} className="text-[13px] font-semibold text-emerald-900 underline break-all text-left cursor-pointer">
                    {order.proof.name}
                  </button>
                  <span className="text-xs text-emerald-800">{Math.max(1, Math.round(order.proof.size_bytes / 1024))} KB</span>
                  <span className="flex-1" />
                  <Button size="sm" processing={uploading} onClick={() => fileInput.current?.click()}>
                    Reemplazar
                  </Button>
                  <Button size="sm" disabled={uploading} onClick={removeProof}>
                    Quitar
                  </Button>
                </div>
              ) : (
                <Button icon={<Paperclip className="w-4 h-4" />} processing={uploading} onClick={() => fileInput.current?.click()}>
                  Adjuntar constancia
                </Button>
              )}
              <input
                ref={fileInput}
                type="file"
                className="sr-only"
                aria-label="Constancia de pago"
                accept={proofRules.extensions.map((extension) => `.${extension}`).join(',')}
                onChange={(event) => void attach(event.target.files?.[0])}
              />
              <p className="text-xs text-slate-500 mt-1.5">
                Foto o PDF del voucher ({proofRules.extensions.join(', ').toUpperCase()}), hasta {Math.round(proofRules.max_kb / 1024)} MB.
              </p>
            </div>

            <Field label="O indica cómo ubicar tu pago (opcional)" hint="Por ejemplo: «Transferencia BCP, operación 123456, 8 de octubre».">
              <input className={INPUT_CLASS} maxLength={300} value={reference} onChange={(event) => setReference(event.target.value)} />
            </Field>
            <div className="flex flex-wrap gap-2">
              <Button variant={gateways.length > 0 ? 'secondary' : 'primary'} type="submit" processing={saving}>
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
  const [searchParams] = useSearchParams();
  // Vuelve de la pasarela: en vez de esperar su aviso, se le pregunta si ya pagó
  const [returned] = useState(() => ['payment_id', 'collection_status', 'status', 'token', 'PayerID'].some((key) => searchParams.has(key)));
  const [checking, setChecking] = useState(false);

  const fetchPlan = async () => (await api.get('/subscription/plans')).data.data as MyPlan;

  const load = useCallback(async () => {
    try {
      setData(await fetchPlan());
      setError(null);
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo cargar tu plan.'));
    }
  }, []);

  // Pregunta a la pasarela por la orden pendiente y vuelve a cargar
  const checkPayment = useCallback(async () => {
    setChecking(true);
    try {
      const before = await fetchPlan();
      if (before.pending_order) {
        // Si la pasarela no responde, se muestra lo que hay
        await api.post(`/subscription/orders/${before.pending_order.id}/pay/sync`).catch(() => undefined);
      }
      const after = await fetchPlan();
      setData(after);
      setError(null);
      if (before.pending_order && !after.pending_order) setNotice('Pago confirmado: tu plan ya está vigente.');
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo cargar tu plan.'));
    } finally {
      setChecking(false);
    }
  }, []);

  useEffect(() => {
    const timer = setTimeout(returned ? checkPayment : load, 0);
    return () => clearTimeout(timer);
  }, [load, checkPayment, returned]);

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

  const currentPlanId = data.subscription?.state === 'ACTIVE' || data.subscription?.state === 'EXPIRED' ? data.subscription.plan?.id : null;
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
      {returned && data.pending_order && (
        <div className="mb-4">
          <Alert tone="info">
            Todavía no vemos tu pago. Si acabas de pagar, puede tardar unos minutos en confirmarse; si lo dejaste a medias, puedes intentarlo otra vez.{' '}
            <button type="button" className="font-semibold underline cursor-pointer disabled:opacity-60" disabled={checking} onClick={checkPayment}>
              {checking ? 'Comprobando…' : 'Volver a comprobar'}
            </button>
          </Alert>
        </div>
      )}

      <PlanCard subscription={data.subscription} />

      {data.pending_order && (
        <PendingOrder
          key={data.pending_order.id}
          order={data.pending_order}
          instructions={data.payment_instructions}
          gateways={data.online_gateways}
          proofRules={data.proof}
          editable={editable}
          onChanged={load}
        />
      )}

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
                    {order.status === 'PAID' && order.paid_online && <span className="block text-xs text-slate-500 mt-0.5">Pago en línea</span>}
                    {(order.status === 'CANCELLED' || order.paid_online) && order.notes && <span className="block text-xs text-slate-500 mt-0.5">{order.notes}</span>}
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
          text={`Se creará una orden por ${formatMoney(choosing.price, choosing.currency)} (${choosing.billing_months === 12 ? 'un año' : 'un mes'}). Verás cómo pagarla; tu plan cambia en cuanto se confirme el pago.`}
          confirmLabel="Crear la orden"
          variant="primary"
          onClose={() => setChoosing(null)}
          onConfirm={async () => {
            await api.post('/subscription/orders', { plan_id: choosing.id });
            setChoosing(null);
            setNotice('Orden creada. Abajo verás cómo pagarla.');
            await load();
          }}
        />
      )}
    </div>
  );
};
