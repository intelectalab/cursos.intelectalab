import React, { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Ban, CheckCircle2, Clock, FileCheck2, Hourglass, Receipt } from 'lucide-react';
import { apiErrorMessage } from '../../../shared/api/client';
import {
  Alert,
  Badge,
  Button,
  Card,
  EmptyState,
  ErrorState,
  Field,
  INPUT_CLASS,
  LoadingState,
  Modal,
  PageHeader,
  Pagination,
  Table,
  TBody,
  Td,
  Th,
  THead,
  Tr,
  type Tone,
} from '../../../shared/ui';
import { FilterCards, type FilterCard } from '../../../shared/ui/filters';
import { formatMoney } from '../../sales/api';
import { platformApi, type PlatformPlanOrder } from '../api';

const formatWhen = (value: string | null) =>
  value ? new Date(value).toLocaleString('es-PE', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—';

const STATUS: Record<PlatformPlanOrder['status'], { label: string; tone: Tone }> = {
  PENDING: { label: 'Por confirmar', tone: 'warning' },
  PAID: { label: 'Pagada', tone: 'success' },
  CANCELLED: { label: 'Cancelada', tone: 'neutral' },
  EXPIRED: { label: 'Vencida sin pago', tone: 'danger' },
};

const METHOD_LABEL: Record<string, string> = { TRANSFER: 'Transferencia', YAPE: 'Yape o Plin', CASH: 'Efectivo', OTHER: 'Otro', CARD: 'Tarjeta', WALLET: 'Saldo en la pasarela' };

type ResolveMode = 'confirm' | 'cancel' | 'revert';

const MODE: Record<ResolveMode, { title: string; action: string; failed: string }> = {
  confirm: { title: 'Confirmar pago recibido', action: 'Confirmar pago', failed: 'No se pudo confirmar el pago.' },
  cancel: { title: 'Cancelar orden', action: 'Cancelar orden', failed: 'No se pudo cancelar la orden.' },
  revert: { title: 'Deshacer la confirmación', action: 'Deshacer confirmación', failed: 'No se pudo deshacer la confirmación.' },
};

/* ───────────────────────────── Confirmar o cancelar ───────────────────────────── */

const ResolveModal: React.FC<{ order: PlatformPlanOrder; mode: ResolveMode; methods: string[]; onClose: () => void; onDone: () => Promise<void> }> = ({
  order,
  mode,
  methods,
  onClose,
  onDone,
}) => {
  const [method, setMethod] = useState(methods[0] ?? 'TRANSFER');
  const [reference, setReference] = useState('');
  const [notes, setNotes] = useState('');
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const confirming = mode === 'confirm';

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setProcessing(true);
    setError(null);
    try {
      if (confirming) await platformApi.confirmPlanOrder(order.id, method, reference.trim() || null, notes.trim() || null);
      else if (mode === 'revert') await platformApi.revertPlanOrder(order.id, notes.trim());
      else await platformApi.cancelPlanOrder(order.id, notes.trim());
      await onDone();
    } catch (err) {
      setError(apiErrorMessage(err, MODE[mode].failed));
      setProcessing(false);
    }
  };

  return (
    <Modal
      title={MODE[mode].title}
      subtitle={`${order.tenant.name} · ${order.plan_name} · ${formatMoney(order.amount, order.currency)}`}
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Volver</Button>
          <Button variant={confirming ? 'primary' : 'danger'} type="submit" form="resolve-order-form" processing={processing}>
            {MODE[mode].action}
          </Button>
        </>
      }
    >
      <form id="resolve-order-form" onSubmit={submit} className="space-y-4">
        {error && <Alert>{error}</Alert>}
        {confirming ? (
          <>
            <Alert tone="info">
              Al confirmar, el plan de la academia queda vigente de inmediato ({order.billing_months === 12 ? 'un año' : 'un mes'}
              {order.max_students ? `, hasta ${order.max_students} alumnos` : ', sin tope de alumnos'}) y se le avisa. Si te equivocas, podrás deshacerlo mientras su plan no cambie
              después.
            </Alert>
            {order.payer_reference && (
              <p className="text-[13px] text-slate-600">
                <span className="font-medium text-slate-800">La academia informó:</span> {order.payer_reference}
              </p>
            )}
            {order.proof && (
              <button type="button" onClick={() => void platformApi.openPlanOrderProof(order.id)} className="inline-flex items-center gap-1.5 text-[13px] font-semibold text-brand-700 underline cursor-pointer">
                <FileCheck2 className="w-4 h-4" /> Ver la constancia adjunta ({order.proof.name})
              </button>
            )}
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <Field label="Medio de pago">
                <select className={INPUT_CLASS} value={method} onChange={(event) => setMethod(event.target.value)} autoFocus>
                  {methods.map((option) => (
                    <option key={option} value={option}>
                      {METHOD_LABEL[option] ?? option}
                    </option>
                  ))}
                </select>
              </Field>
              <Field label="N.º de operación (opcional)">
                <input className={INPUT_CLASS} maxLength={120} value={reference} onChange={(event) => setReference(event.target.value)} />
              </Field>
            </div>
            <Field label="Nota interna (opcional)" hint="Por ejemplo, el número de la factura que emitiste.">
              <input className={INPUT_CLASS} maxLength={500} value={notes} onChange={(event) => setNotes(event.target.value)} />
            </Field>
          </>
        ) : mode === 'revert' ? (
          <>
            <Alert tone="info">
              El plan de la academia vuelve a como estaba antes de esta confirmación y la orden queda otra vez pendiente, para confirmarla bien o cancelarla. La academia recibe el aviso con el
              motivo.
            </Alert>
            <Field label="Motivo" hint="Lo verá la academia. Ejemplo: «El pago era de otra academia».">
              <textarea className={INPUT_CLASS} rows={3} required minLength={5} maxLength={300} autoFocus value={notes} onChange={(event) => setNotes(event.target.value)} />
            </Field>
          </>
        ) : (
          <Field label="Motivo" hint="La academia lo verá y podrá pedir otro plan después.">
            <textarea className={INPUT_CLASS} rows={3} required maxLength={500} autoFocus value={notes} onChange={(event) => setNotes(event.target.value)} />
          </Field>
        )}
      </form>
    </Modal>
  );
};

/* ───────────────────────────── Listado ───────────────────────────── */

// Pagos de plan de todas las academias: la plataforma confirma los que recibió
export const PaymentsPanel: React.FC = () => {
  const [rows, setRows] = useState<PlatformPlanOrder[]>([]);
  const [meta, setMeta] = useState<{ page: number; last_page: number; total: number; counts: Record<string, number>; methods: string[] } | null>(null);
  const [status, setStatus] = useState('PENDING');
  const [page, setPage] = useState(1);
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [loadError, setLoadError] = useState('');
  const [dialog, setDialog] = useState<{ order: PlatformPlanOrder; mode: ResolveMode } | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const result = await platformApi.planOrders(status || undefined, page);
      setRows(result.data);
      setMeta(result.meta);
      setState('ready');
    } catch (err) {
      setLoadError(apiErrorMessage(err, 'Verifica tu conexión e inténtalo nuevamente.'));
      setState('error');
    }
  }, [status, page]);

  useEffect(() => {
    const timer = setTimeout(load, 0);
    return () => clearTimeout(timer);
  }, [load]);

  const counts = meta?.counts ?? {};
  const cards: FilterCard[] = [
    { key: '', label: 'Todas', value: counts.ALL ?? null, icon: Receipt, tone: 'brand', help: 'Todas las órdenes de plan que han pedido las academias.' },
    { key: 'PENDING', label: 'Por confirmar', value: counts.PENDING ?? null, icon: Hourglass, tone: 'warning', help: 'La academia pidió el plan y espera que confirmes su pago. Hasta entonces su plan no cambia.' },
    { key: 'PAID', label: 'Pagadas', value: counts.PAID ?? null, icon: CheckCircle2, tone: 'success', help: 'Pago confirmado: el plan quedó vigente en ese momento.' },
    { key: 'CANCELLED', label: 'Canceladas', value: counts.CANCELLED ?? null, icon: Ban, tone: 'neutral', help: 'Las canceló la academia o la plataforma. No cambiaron ningún plan.' },
    { key: 'EXPIRED', label: 'Vencidas', value: counts.EXPIRED ?? null, icon: Clock, tone: 'danger', help: 'Pasaron su plazo sin que la academia pagara ni informara un pago. No cambiaron ningún plan.' },
  ];

  return (
    <>
      <PageHeader icon={Receipt} title="Pagos de academias" subtitle="Cada academia pide su plan y lo paga en línea o por fuera; aquí confirmas los pagos que recibiste por fuera." />

      {notice && (
        <div className="mb-4">
          <Alert tone="success">{notice}</Alert>
        </div>
      )}

      <FilterCards
        cards={cards}
        active={status}
        label="Órdenes por estado"
        onSelect={(key) => {
          setStatus(key);
          setPage(1);
        }}
      />

      <Card>
        {state === 'loading' && <LoadingState />}
        {state === 'error' && <ErrorState message={loadError} onRetry={load} />}
        {state === 'ready' && rows.length === 0 && (
          <EmptyState
            icon={Receipt}
            title={status === 'PENDING' ? 'No hay pagos por confirmar' : 'No hay órdenes en este estado'}
            text={status === 'PENDING' ? 'Cuando una academia pida un plan, aparecerá aquí.' : undefined}
          />
        )}
        {state === 'ready' && rows.length > 0 && (
          <Table>
            <THead>
              <Th>Orden</Th>
              <Th>Academia</Th>
              <Th>Plan</Th>
              <Th>Importe</Th>
              <Th>Pedida</Th>
              <Th>Estado</Th>
              <Th />
            </THead>
            <TBody>
              {rows.map((order) => (
                <Tr key={order.id}>
                  <Td className="whitespace-nowrap font-semibold text-slate-900 tnum">P-{String(order.number).padStart(4, '0')}</Td>
                  <Td className="min-w-[160px]">
                    <Link to={`/platform/tenants/${order.tenant.id}`} className="font-semibold text-slate-900 hover:text-brand-700">
                      {order.tenant.name}
                    </Link>
                    {order.payer_reference && <p className="text-xs text-slate-500 break-words">Informó: {order.payer_reference}</p>}
                    {order.proof && (
                      <button
                        type="button"
                        onClick={() => void platformApi.openPlanOrderProof(order.id)}
                        className="inline-flex items-center gap-1 mt-0.5 text-xs font-semibold text-brand-700 underline cursor-pointer"
                      >
                        <FileCheck2 className="w-3.5 h-3.5" /> Ver constancia
                      </button>
                    )}
                    {order.review_reason && <p className="mt-1 text-xs font-medium text-rose-700 break-words whitespace-normal">Cobro por revisar: {order.review_reason}</p>}
                  </Td>
                  <Td className="whitespace-nowrap text-slate-700">
                    {order.plan_name}
                    <span className="block text-xs text-slate-500">{order.billing_months === 12 ? 'Un año' : 'Un mes'}</span>
                  </Td>
                  <Td className="whitespace-nowrap font-medium text-slate-900 tnum">{formatMoney(order.amount, order.currency)}</Td>
                  <Td className="whitespace-nowrap text-slate-600 tnum">{formatWhen(order.created_at)}</Td>
                  <Td className="whitespace-nowrap">
                    <Badge tone={order.status === 'PENDING' && (order.payer_reference || order.proof) ? 'info' : STATUS[order.status].tone} dot>
                      {order.status === 'PENDING' && (order.payer_reference || order.proof) ? 'Pago informado' : STATUS[order.status].label}
                    </Badge>
                    {order.status === 'PAID' && (
                      <span className="block text-xs text-slate-500 mt-0.5">
                        {METHOD_LABEL[order.payment_method ?? ''] ?? ''}
                        {order.paid_online ? ' en línea' : ''} · {formatWhen(order.paid_at)}
                      </span>
                    )}
                    {order.status === 'CANCELLED' && order.notes && <span className="block text-xs text-slate-500 mt-0.5 whitespace-normal">{order.notes}</span>}
                  </Td>
                  <Td className="text-right whitespace-nowrap">
                    {order.status === 'PENDING' && (
                      <div className="inline-flex gap-1.5">
                        <Button size="sm" variant="primary" onClick={() => setDialog({ order, mode: 'confirm' })}>
                          Confirmar pago
                        </Button>
                        <Button size="sm" onClick={() => setDialog({ order, mode: 'cancel' })}>
                          Cancelar
                        </Button>
                      </div>
                    )}
                    {order.can_revert && (
                      <Button size="sm" onClick={() => setDialog({ order, mode: 'revert' })}>
                        Deshacer
                      </Button>
                    )}
                  </Td>
                </Tr>
              ))}
            </TBody>
          </Table>
        )}
        {state === 'ready' && meta && <Pagination page={meta.page} lastPage={meta.last_page} total={meta.total} noun="órdenes" onPage={setPage} />}
      </Card>

      {dialog && (
        <ResolveModal
          order={dialog.order}
          mode={dialog.mode}
          methods={meta?.methods ?? ['TRANSFER']}
          onClose={() => setDialog(null)}
          onDone={async () => {
            setNotice(
              dialog.mode === 'confirm'
                ? `Pago confirmado: «${dialog.order.tenant.name}» ya tiene vigente el plan «${dialog.order.plan_name}».`
                : dialog.mode === 'revert'
                  ? `Confirmación deshecha: el plan de «${dialog.order.tenant.name}» volvió a como estaba y su orden quedó pendiente.`
                  : `Orden de «${dialog.order.tenant.name}» cancelada.`,
            );
            setDialog(null);
            await load();
          }}
        />
      )}
    </>
  );
};
