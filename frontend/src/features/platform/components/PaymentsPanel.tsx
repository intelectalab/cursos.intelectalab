import React, { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Ban, CheckCircle2, Hourglass, Receipt } from 'lucide-react';
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
};

const METHOD_LABEL: Record<string, string> = { TRANSFER: 'Transferencia', YAPE: 'Yape o Plin', CASH: 'Efectivo', OTHER: 'Otro' };

/* ───────────────────────────── Confirmar o cancelar ───────────────────────────── */

const ResolveModal: React.FC<{ order: PlatformPlanOrder; mode: 'confirm' | 'cancel'; methods: string[]; onClose: () => void; onDone: () => Promise<void> }> = ({
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
      else await platformApi.cancelPlanOrder(order.id, notes.trim());
      await onDone();
    } catch (err) {
      setError(apiErrorMessage(err, confirming ? 'No se pudo confirmar el pago.' : 'No se pudo cancelar la orden.'));
      setProcessing(false);
    }
  };

  return (
    <Modal
      title={confirming ? 'Confirmar pago recibido' : 'Cancelar orden'}
      subtitle={`${order.tenant.name} · ${order.plan_name} · ${formatMoney(order.amount, order.currency)}`}
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Volver</Button>
          <Button variant={confirming ? 'primary' : 'danger'} type="submit" form="resolve-order-form" processing={processing}>
            {confirming ? 'Confirmar pago' : 'Cancelar orden'}
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
              {order.max_students ? `, hasta ${order.max_students} alumnos` : ', sin tope de alumnos'}). No se puede deshacer desde aquí.
            </Alert>
            {order.payer_reference && (
              <p className="text-[13px] text-slate-600">
                <span className="font-medium text-slate-800">La academia informó:</span> {order.payer_reference}
              </p>
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
        ) : (
          <Field label="Motivo" hint="La academia podrá pedir otro plan después.">
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
  const [dialog, setDialog] = useState<{ order: PlatformPlanOrder; mode: 'confirm' | 'cancel' } | null>(null);
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
  ];

  return (
    <>
      <PageHeader icon={Receipt} title="Pagos de academias" subtitle="Cada academia pide su plan y paga por fuera; aquí confirmas los pagos que recibiste." />

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
                  </Td>
                  <Td className="whitespace-nowrap text-slate-700">
                    {order.plan_name}
                    <span className="block text-xs text-slate-500">{order.billing_months === 12 ? 'Un año' : 'Un mes'}</span>
                  </Td>
                  <Td className="whitespace-nowrap font-medium text-slate-900 tnum">{formatMoney(order.amount, order.currency)}</Td>
                  <Td className="whitespace-nowrap text-slate-600 tnum">{formatWhen(order.created_at)}</Td>
                  <Td className="whitespace-nowrap">
                    <Badge tone={STATUS[order.status].tone} dot>
                      {STATUS[order.status].label}
                    </Badge>
                    {order.status === 'PAID' && (
                      <span className="block text-xs text-slate-500 mt-0.5">
                        {METHOD_LABEL[order.payment_method ?? ''] ?? ''} · {formatWhen(order.paid_at)}
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
