import React, { useCallback, useEffect, useState } from 'react';
import { BadgeCheck, Ban, CircleDollarSign, CreditCard, Hourglass, Search, TimerOff } from 'lucide-react';
import { useAuth } from '../../auth/AuthContext';
import { apiErrorMessage } from '../../../shared/api/client';
import {
  Alert,
  Avatar,
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
} from '../../../shared/ui';
import { FilterCards, HelpTip, type FilterCard } from '../../../shared/ui/filters';
import type { PageMeta } from '../../identity/api';
import {
  formatDateTime,
  formatMoney,
  ORDER_STATUS_LABEL,
  ORDER_STATUSES,
  orderBuyer,
  orderCode,
  PAYMENT_GATEWAY_LABEL,
  PAYMENT_METHOD_LABEL,
  PAYMENT_METHODS,
  salesApi,
  type Order,
  type PaymentMethod,
} from '../api';
import { OrderStatusBadge, OrderSummary } from '../components/OrderParts';

// Detalle de una orden con sus acciones: confirmar el pago recibido o cancelarla
const OrderModal: React.FC<{
  order: Order;
  canConfirm: boolean;
  canCancel: boolean;
  // Puede entregar las licencias de una venta a empresa antes del pago
  canDefer: boolean;
  onClose: () => void;
  onChanged: (order: Order) => void;
}> = ({ order, canConfirm, canCancel, canDefer, onClose, onChanged }) => {
  const [mode, setMode] = useState<'view' | 'pay' | 'cancel' | 'release'>('view');
  const [releaseReason, setReleaseReason] = useState('');
  const [method, setMethod] = useState<PaymentMethod>('TRANSFER');
  const [reference, setReference] = useState('');
  const [notes, setNotes] = useState('');
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const pending = order.status === 'PENDING';

  const run = async (action: () => Promise<Order>, fallback: string) => {
    setProcessing(true);
    setError(null);
    try {
      onChanged(await action());
      setMode('view');
    } catch (err) {
      setError(apiErrorMessage(err, fallback));
    } finally {
      setProcessing(false);
    }
  };

  const confirmPayment = (event: React.FormEvent) => {
    event.preventDefault();
    void run(
      () => salesApi.confirmPayment(order.id, { method, reference: reference.trim() || null, notes: notes.trim() || null }),
      'No se pudo confirmar el pago.',
    );
  };

  const footer =
    mode === 'pay' ? (
      <>
        <Button onClick={() => setMode('view')}>Volver</Button>
        <Button variant="primary" type="submit" form="confirm-payment-form" processing={processing}>
          Confirmar pago
        </Button>
      </>
    ) : mode === 'release' ? (
      <>
        <Button onClick={() => setMode('view')}>Volver</Button>
        <Button variant="primary" type="submit" form="release-licenses-form" processing={processing}>
          Entregar licencias
        </Button>
      </>
    ) : mode === 'cancel' ? (
      <>
        <Button onClick={() => setMode('view')}>Volver</Button>
        <Button variant="danger" processing={processing} onClick={() => void run(() => salesApi.cancelOrder(order.id), 'No se pudo cancelar la orden.')}>
          Cancelar orden
        </Button>
      </>
    ) : (
      <>
        <Button onClick={onClose}>Cerrar</Button>
        {pending && canCancel && (
          <Button variant="danger" onClick={() => setMode('cancel')}>
            Cancelar orden
          </Button>
        )}
        {pending && canDefer && order.company && !order.licenses_released_at && <Button onClick={() => setMode('release')}>Entregar licencias sin pago</Button>}
        {pending && canConfirm && (
          <Button variant="primary" icon={<BadgeCheck className="w-4 h-4" />} onClick={() => setMode('pay')}>
            Confirmar pago
          </Button>
        )}
      </>
    );

  return (
    <Modal title={`Orden ${orderCode(order.number)}`} subtitle={`Generada el ${formatDateTime(order.created_at)}`} onClose={onClose} footer={footer}>
      <div className="space-y-5">
        {error && <Alert>{error}</Alert>}

        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex items-center gap-3 min-w-0">
            <Avatar name={orderBuyer(order).name} />
            <div className="min-w-0">
              <p className="text-[13px] font-semibold text-slate-900 truncate">{orderBuyer(order).name}</p>
              <p className="text-xs text-slate-500 truncate">{orderBuyer(order).detail}</p>
            </div>
          </div>
          <OrderStatusBadge status={order.status} />
        </div>

        {mode === 'view' && (
          <>
            <OrderSummary order={order} />
            {pending && order.expires_at && <p className="text-xs text-slate-500">Espera su pago hasta el {formatDateTime(order.expires_at)}.</p>}
            {order.status === 'CANCELLED' && <p className="text-xs text-slate-500">Cancelada el {formatDateTime(order.cancelled_at)}.</p>}
            {order.payment_reviews.map((review) => (
              <Alert key={`${review.gateway}-${review.payment_id}`}>
                {PAYMENT_GATEWAY_LABEL[review.gateway] ?? review.gateway} cobró un pago (n.º {review.payment_id}) que no se aplicó a esta orden. {review.reason} Revísalo en tu
                cuenta de la pasarela y devuélvelo o confirma el pago a mano.
              </Alert>
            ))}
            {order.licenses_released_at && (
              <Alert tone="info">
                Las licencias se entregaron el {formatDateTime(order.licenses_released_at)}, antes del pago. Motivo: {order.licenses_release_reason}
              </Alert>
            )}
          </>
        )}

        {mode === 'release' && (
          <form
            id="release-licenses-form"
            className="space-y-4"
            onSubmit={(event) => {
              event.preventDefault();
              void run(() => salesApi.releaseLicenses(order.id, releaseReason.trim()), 'No se pudieron entregar las licencias.');
            }}
          >
            <Alert tone="info">
              {order.company?.name} recibirá sus licencias ahora y podrá asignarlas, aunque la orden siga pendiente de pago. Úsalo solo con empresas que pagan a crédito: queda registrado quién lo
              autorizó.
            </Alert>
            <Field label="Motivo" hint="Ejemplo: orden de compra 4471, paga a 30 días.">
              <textarea className={INPUT_CLASS} rows={3} required minLength={5} maxLength={500} autoFocus value={releaseReason} onChange={(event) => setReleaseReason(event.target.value)} />
            </Field>
          </form>
        )}

        {mode === 'pay' && (
          <form id="confirm-payment-form" onSubmit={confirmPayment} className="space-y-4">
            <Alert tone="info">
              Confirma solo si ya verificaste que recibiste <strong>{formatMoney(order.total_amount, order.currency)}</strong>. Al confirmar,{' '}
              {order.company ? 'la empresa recibe sus licencias' : 'el comprador queda matriculado'} y la orden ya no se puede cancelar.
            </Alert>
            <Field label="Medio de pago">
              <select className={INPUT_CLASS} value={method} onChange={(event) => setMethod(event.target.value as PaymentMethod)}>
                {PAYMENT_METHODS.map((option) => (
                  <option key={option} value={option}>
                    {PAYMENT_METHOD_LABEL[option]}
                  </option>
                ))}
              </select>
            </Field>
            <Field label="N.º de operación (opcional)" hint="El que figura en el comprobante que envió el comprador.">
              <input className={INPUT_CLASS} maxLength={120} value={reference} onChange={(event) => setReference(event.target.value)} />
            </Field>
            <Field label="Notas (opcional)">
              <textarea className={INPUT_CLASS} rows={3} maxLength={500} value={notes} onChange={(event) => setNotes(event.target.value)} />
            </Field>
          </form>
        )}

        {mode === 'cancel' && (
          <p className="text-[13px] leading-relaxed text-slate-600">
            Se cancelará esta orden y el comprador tendrá que generar otra si quiere el curso. Hazlo solo si no va a pagarla.
          </p>
        )}
      </div>
    </Modal>
  );
};

// Ventas: todas las órdenes de la academia
export const OrdersPage: React.FC = () => {
  const { hasPermission } = useAuth();
  const [rows, setRows] = useState<Order[]>([]);
  const [meta, setMeta] = useState<PageMeta | null>(null);
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [errorMessage, setErrorMessage] = useState('');
  const [open, setOpen] = useState<Order | null>(null);

  const load = useCallback(async () => {
    try {
      const result = await salesApi.orders({ search: search || undefined, status: status || undefined, page });
      setRows(result.data);
      setMeta(result.meta);
      setState('ready');
    } catch (err) {
      setErrorMessage(apiErrorMessage(err, 'Verifica tu conexión e inténtalo nuevamente.'));
      setState('error');
    }
  }, [search, status, page]);

  // La búsqueda espera a que el usuario termine de escribir
  useEffect(() => {
    const timer = setTimeout(load, 250);
    return () => clearTimeout(timer);
  }, [load]);

  const filtered = search !== '' || status !== '';
  const filter = (apply: () => void) => {
    apply();
    setPage(1);
  };

  const counts = meta?.counts ?? {};
  const cards: FilterCard[] = [
    { key: '', label: 'Todas', value: counts.ALL ?? null, icon: CreditCard, tone: 'brand', help: 'Todas las órdenes que coinciden con la búsqueda.' },
    { key: 'PENDING', label: 'Pendientes de pago', value: counts.PENDING ?? null, icon: Hourglass, tone: 'warning', help: 'Esperan que confirmes el pago recibido. Pasado su plazo, vencen solas.' },
    { key: 'PAID', label: 'Pagadas', value: counts.PAID ?? null, icon: CircleDollarSign, tone: 'success', help: 'Pago confirmado: el comprador ya está matriculado, o la empresa ya tiene sus licencias.' },
    { key: 'EXPIRED', label: 'Vencidas', value: counts.EXPIRED ?? null, icon: TimerOff, tone: 'danger', help: 'No se pagaron dentro del plazo. El comprador puede hacer una compra nueva.' },
    { key: 'CANCELLED', label: 'Canceladas', value: counts.CANCELLED ?? null, icon: Ban, tone: 'neutral', help: 'Las canceló el comprador o el personal antes de pagarse.' },
  ];

  return (
    <div>
      <PageHeader title="Ventas y pagos" icon={CreditCard} subtitle="Órdenes de compra de tus alumnos y confirmación de los pagos recibidos." />

      <FilterCards cards={cards} active={status} label="Órdenes por estado" onSelect={(key) => filter(() => setStatus(key))} />

      <Card>
        <div className="flex flex-wrap items-center gap-3 px-5 py-4 border-b border-slate-200/80">
          <div className="relative flex-1 min-w-[200px] max-w-sm">
            <Search className="w-4 h-4 text-slate-500 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" />
            <input
              className={`${INPUT_CLASS} pl-9`}
              placeholder="Buscar por comprador, empresa, curso o n.º de orden"
              aria-label="Buscar orden"
              value={search}
              onChange={(event) => filter(() => setSearch(event.target.value))}
            />
          </div>
          <select
            className={`${INPUT_CLASS} !w-52`}
            aria-label="Filtrar por estado"
            value={status}
            onChange={(event) => filter(() => setStatus(event.target.value))}
          >
            <option value="">Todos los estados</option>
            {ORDER_STATUSES.map((option) => (
              <option key={option} value={option}>
                {ORDER_STATUS_LABEL[option]}
              </option>
            ))}
          </select>
          <HelpTip
            text={status ? (cards.find((card) => card.key === status)?.help ?? '') : 'Una orden nace pendiente; pasa a pagada al confirmarse el pago, o vence o se cancela si no se paga.'}
            label="Qué significa el estado elegido"
          />
        </div>

        {state === 'loading' && <LoadingState />}
        {state === 'error' && <ErrorState message={errorMessage} onRetry={load} />}
        {state === 'ready' && rows.length === 0 && (
          <EmptyState
            icon={CreditCard}
            title={filtered ? 'Sin resultados con estos filtros' : 'Aún no hay órdenes'}
            text={
              filtered
                ? 'Prueba con otra búsqueda o cambia el estado.'
                : 'Ponle precio a un curso publicado para que aparezca en el catálogo y tus alumnos puedan comprarlo.'
            }
            action={
              filtered && (
                <Button
                  onClick={() =>
                    filter(() => {
                      setSearch('');
                      setStatus('');
                    })
                  }
                >
                  Quitar filtros
                </Button>
              )
            }
          />
        )}
        {state === 'ready' && rows.length > 0 && (
          <Table>
            <THead>
              <Th>Orden</Th>
              <Th>Comprador</Th>
              <Th>Curso</Th>
              <Th>Estado</Th>
              <Th className="text-right">Total</Th>
              <Th />
            </THead>
            <TBody>
              {rows.map((order) => (
                <Tr key={order.id}>
                  <Td className="whitespace-nowrap">
                    <p className="font-semibold text-slate-900 tnum">{orderCode(order.number)}</p>
                    <p className="text-xs text-slate-500 tnum">{formatDateTime(order.created_at)}</p>
                  </Td>
                  <Td>
                    <p className="font-semibold text-slate-900 whitespace-nowrap">{orderBuyer(order).name}</p>
                    <p className="text-xs text-slate-500">{orderBuyer(order).detail}</p>
                  </Td>
                  <Td className="min-w-[180px] text-slate-700">{order.items.map((item) => item.title).join(', ')}</Td>
                  <Td>
                    <OrderStatusBadge status={order.status} />
                  </Td>
                  <Td className="text-right font-semibold text-slate-900 tnum whitespace-nowrap">{formatMoney(order.total_amount, order.currency)}</Td>
                  <Td className="text-right">
                    <Button size="sm" onClick={() => setOpen(order)} aria-label={`Ver la orden ${orderCode(order.number)}`}>
                      Ver
                    </Button>
                  </Td>
                </Tr>
              ))}
            </TBody>
          </Table>
        )}
        {state === 'ready' && meta && <Pagination page={meta.page} lastPage={meta.last_page} total={meta.total} noun="órdenes" onPage={setPage} />}
      </Card>

      {open && (
        <OrderModal
          order={open}
          canConfirm={hasPermission('payments.confirm')}
          canCancel={hasPermission('orders.cancel')}
          canDefer={hasPermission('payments.defer')}
          onClose={() => setOpen(null)}
          onChanged={(order) => {
            setOpen(order);
            void load();
          }}
        />
      )}
    </div>
  );
};
