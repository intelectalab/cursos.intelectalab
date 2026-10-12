import React, { useCallback, useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { BadgeCheck, Ban, CalendarClock, CircleDollarSign, CreditCard, Hourglass, RefreshCw, Search, TimerOff, Undo2 } from 'lucide-react';
import { useAuth } from '../../auth/AuthContext';
import { apiErrorMessage } from '../../../shared/api/client';
import {
  Alert,
  Avatar,
  Button,
  Card,
  CHECKBOX_CLASS,
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
  type PaymentReview,
} from '../api';
import { DueInstallments, InstallmentPlanForm } from '../components/Installments';
import { OrderStatusBadge, OrderSummary } from '../components/OrderParts';
import { PaymentReviewList } from '../components/PaymentReviews';
import { ExportButton } from '../../../shared/ui/ExportButton';
import { SearchSelect } from '../../../shared/ui/SearchSelect';

// Detalle de una orden con sus acciones: confirmar el pago recibido o cancelarla
const OrderModal: React.FC<{
  order: Order;
  canConfirm: boolean;
  canCancel: boolean;
  // Puede entregar las licencias de una venta a empresa antes del pago
  canDefer: boolean;
  // Puede devolver el importe completo de una compra pagada
  canRefund: boolean;
  onClose: () => void;
  onChanged: (order: Order) => void;
}> = ({ order, canConfirm, canCancel, canDefer, canRefund, onClose, onChanged }) => {
  const [mode, setMode] = useState<'view' | 'pay' | 'cancel' | 'release' | 'refund' | 'plan' | 'resolve'>('view');
  // Cobro en revisión que se está dando por resuelto
  const [resolving, setResolving] = useState<PaymentReview | null>(null);
  const [resolution, setResolution] = useState('');
  const [notice, setNotice] = useState<string | null>(null);
  const [refundReason, setRefundReason] = useState('');
  const [refundReference, setRefundReference] = useState('');
  // La pasarela ya no deja devolver el pago: el dinero se devolvió por otro medio y solo se registra
  const [refundOutside, setRefundOutside] = useState(false);
  const [releaseReason, setReleaseReason] = useState('');
  const [method, setMethod] = useState<PaymentMethod>('TRANSFER');
  const [reference, setReference] = useState('');
  const [notes, setNotes] = useState('');
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const pending = order.status === 'PENDING';
  // En cuotas: ya entró un pago y faltan otros
  const partial = order.status === 'PARTIAL';
  const next = order.debt?.next ?? null;
  const hasPlan = order.installments.length > 0;
  // El plan de pagos se arma mientras nadie haya pagado, en compras personales con importe
  const plannable = pending && order.customer !== null && Number(order.total_amount) > 0;
  const dueNow = next ? next.amount : order.total_amount;
  // Compras personales con importe y algo cobrado (pagadas, o en cuotas con alguna pagada): lo demás no tiene qué devolver por aquí
  const refundable =
    (order.status === 'PAID' || partial) && order.customer !== null && Number(order.total_amount) > 0 && (order.collected.payments > 0 || Number(order.refunded_amount) > 0);
  // Lo que devuelve la pasarela y lo que tiene que devolver la academia por su cuenta
  const refundOnline = Number(order.collected.online_amount);
  const refundManual = Number(order.collected.manual_amount);
  const gatewayName = order.collected.gateways.map((code) => PAYMENT_GATEWAY_LABEL[code] ?? code).join(' y ') || 'la pasarela';
  // Se le puede volver a preguntar a la pasarela si el comprador fue alguna vez a pagar en línea
  const recheckable = order.customer !== null && order.online_attempts > 0;

  const run = async (action: () => Promise<Order>, fallback: string) => {
    setProcessing(true);
    setError(null);
    setNotice(null);
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

  const recheck = async () => {
    setProcessing(true);
    setError(null);
    setNotice(null);
    try {
      const fresh = await salesApi.recheckPayment(order.id);
      setNotice(
        fresh.status !== order.status || fresh.collected.amount !== order.collected.amount
          ? 'La pasarela informó un pago y ya quedó aplicado.'
          : fresh.payment_reviews.length > order.payment_reviews.length
            ? 'La pasarela informó un cobro que no cuadra con la orden: quedó en revisión.'
            : 'La pasarela no informa ningún pago nuevo para esta orden.',
      );
      onChanged(fresh);
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo consultar a la pasarela.'));
    } finally {
      setProcessing(false);
    }
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
    ) : mode === 'refund' ? (
      <>
        <Button onClick={() => setMode('view')}>Volver</Button>
        <Button variant="danger" type="submit" form="refund-order-form" processing={processing}>
          Reembolsar {formatMoney(order.collected.amount, order.currency)}
        </Button>
      </>
    ) : mode === 'resolve' ? (
      <>
        <Button onClick={() => setMode('view')}>Volver</Button>
        <Button variant="primary" type="submit" form="resolve-review-form" processing={processing}>
          Marcar como resuelto
        </Button>
      </>
    ) : mode === 'plan' ? (
      <>
        <Button onClick={() => setMode('view')}>Volver</Button>
        {hasPlan && (
          <Button variant="danger" processing={processing} onClick={() => void run(() => salesApi.removeInstallments(order.id), 'No se pudo quitar el plan de pagos.')}>
            Quitar el plan
          </Button>
        )}
        <Button variant="primary" type="submit" form="installment-plan-form" processing={processing}>
          Guardar plan de pagos
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
        {refundable && canRefund && (
          <Button variant="danger" icon={<Undo2 className="w-4 h-4" />} onClick={() => setMode('refund')}>
            Reembolsar
          </Button>
        )}
        {pending && canCancel && (
          <Button variant="danger" onClick={() => setMode('cancel')}>
            Cancelar orden
          </Button>
        )}
        {recheckable && canConfirm && (
          <Button icon={<RefreshCw className="w-4 h-4" />} processing={processing} onClick={() => void recheck()}>
            Consultar a la pasarela
          </Button>
        )}
        {pending && canDefer && order.company && !order.licenses_released_at && <Button onClick={() => setMode('release')}>Entregar licencias sin pago</Button>}
        {plannable && canConfirm && (
          <Button icon={<CalendarClock className="w-4 h-4" />} onClick={() => setMode('plan')}>
            {hasPlan ? 'Cambiar el plan de pagos' : 'Pagar en cuotas'}
          </Button>
        )}
        {(pending || partial) && canConfirm && (
          <Button variant="primary" icon={<BadgeCheck className="w-4 h-4" />} onClick={() => setMode('pay')}>
            {next ? `Confirmar cuota ${next.number}` : 'Confirmar pago'}
          </Button>
        )}
      </>
    );

  return (
    <Modal title={`Orden ${orderCode(order.number)}`} subtitle={`Generada el ${formatDateTime(order.created_at)}`} onClose={onClose} footer={footer}>
      <div className="space-y-5">
        {error && <Alert>{error}</Alert>}
        {notice && mode === 'view' && <Alert tone="info">{notice}</Alert>}

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
            {order.debt && order.debt.overdue_count > 0 && (
              <Alert>
                Tiene {order.debt.overdue_count === 1 ? 'una cuota vencida' : `${order.debt.overdue_count} cuotas vencidas`} por{' '}
                <strong>{formatMoney(order.debt.overdue_amount, order.currency)}</strong>. El alumno conserva su acceso: si decides retirarlo, cancela su matrícula en «Matrículas».
              </Alert>
            )}
            <OrderSummary order={order} />
            {pending && order.expires_at && (
              <p className="text-xs text-slate-500">
                Espera {hasPlan ? 'su primera cuota' : 'su pago'} hasta el {formatDateTime(order.expires_at)}.
              </p>
            )}
            {order.status === 'CANCELLED' && <p className="text-xs text-slate-500">Cancelada el {formatDateTime(order.cancelled_at)}.</p>}
            {order.account_warning && <Alert tone="warning">{order.account_warning}</Alert>}
            {order.payment_reviews.map((review) => (
              <Alert key={review.id}>
                {PAYMENT_GATEWAY_LABEL[review.gateway] ?? review.gateway} cobró un pago (n.º {review.payment_id}) que no se aplicó a esta orden. {review.reason} Revísalo en tu
                cuenta de la pasarela y devuélvelo o confirma el pago a mano.
                {canConfirm && (
                  <>
                    {' '}
                    <button
                      type="button"
                      className="font-semibold underline cursor-pointer"
                      onClick={() => {
                        setResolving(review);
                        setResolution('');
                        setMode('resolve');
                      }}
                    >
                      Ya lo resolví
                    </button>
                  </>
                )}
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

        {mode === 'resolve' && resolving && (
          <form
            id="resolve-review-form"
            className="space-y-4"
            onSubmit={(event) => {
              event.preventDefault();
              void run(() => salesApi.resolvePaymentReview(resolving.id, resolution.trim()), 'No se pudo marcar como resuelto.');
            }}
          >
            <Alert tone="info">
              Vas a dar por resuelto el cobro n.º {resolving.payment_id} de {PAYMENT_GATEWAY_LABEL[resolving.gateway] ?? resolving.gateway} por{' '}
              <strong>{formatMoney(resolving.amount, resolving.currency)}</strong>. El sistema no mueve dinero aquí: solo deja de avisar. Hazlo cuando ya lo hayas devuelto en la
              pasarela o lo hayas confirmado a mano en la orden.
            </Alert>
            <Field label="Qué hiciste con el cobro" hint="Queda en la auditoría. Ejemplo: «Devuelto al alumno desde Mercado Pago el 11 de octubre».">
              <textarea className={INPUT_CLASS} rows={3} required minLength={5} maxLength={300} autoFocus value={resolution} onChange={(event) => setResolution(event.target.value)} />
            </Field>
          </form>
        )}

        {mode === 'plan' && (
          <InstallmentPlanForm
            order={order}
            formId="installment-plan-form"
            onSubmit={(rows) => void run(() => salesApi.setInstallments(order.id, rows), 'No se pudo guardar el plan de pagos.')}
          />
        )}

        {mode === 'pay' && (
          <form id="confirm-payment-form" onSubmit={confirmPayment} className="space-y-4">
            <Alert tone="info">
              Confirma solo si ya verificaste que recibiste <strong>{formatMoney(dueNow, order.currency)}</strong>
              {next ? ` de la cuota ${next.number} de ${order.installments.length}` : ''}.{' '}
              {partial
                ? 'El comprador ya tiene acceso; con la última cuota la orden queda pagada.'
                : `Al confirmar, ${order.company ? 'la empresa recibe sus licencias' : 'el comprador queda matriculado'} y la orden ya no se puede cancelar.`}
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

        {mode === 'refund' && (
          <form
            id="refund-order-form"
            className="space-y-4"
            onSubmit={(event) => {
              event.preventDefault();
              void run(
                () => salesApi.refundOrder(order.id, refundReason.trim(), refundManual > 0 || refundOutside ? refundReference.trim() || null : null, refundOutside),
                'No se pudo reembolsar la orden.',
              );
            }}
          >
            <Alert tone="info">
              {hasPlan && (
                <>
                  Esta venta es en cuotas: se devuelve lo cobrado hasta hoy, <strong>{formatMoney(order.collected.amount, order.currency)}</strong>
                  {order.collected.payments === 1 ? ' (una cuota)' : ` (${order.collected.payments} cuotas)`}
                  {partial ? ', y lo que faltaba pagar ya no se debe' : ''}.{' '}
                </>
              )}
              {refundOnline > 0 && (
                <>
                  Se devolverán <strong>{formatMoney(refundOnline, order.currency)}</strong> por {gatewayName}, con tu cuenta, al medio con el que pagó el comprador.{' '}
                  {order.payment?.notes ? `${order.payment.notes} ` : ''}
                </>
              )}
              {refundManual > 0 && (
                <>
                  {refundOnline > 0 ? 'El resto se recibió' : hasPlan ? 'Se recibió' : 'Este pago se recibió'} por fuera: <strong>devuelve tú el dinero</strong> (
                  {formatMoney(refundManual, order.currency)}) y regístralo aquí. El sistema no mueve ese dinero.{' '}
                </>
              )}
              {Number(order.refunded_amount) > 0 && <>Ya se devolvieron {formatMoney(order.refunded_amount, order.currency)} en un intento anterior. </>}
              El comprador pierde la matrícula que le dio esta compra. No se puede deshacer.
            </Alert>
            <Field label="Motivo" hint="Queda en la orden y en la auditoría. Ejemplo: «El alumno pidió la devolución dentro del plazo».">
              <textarea className={INPUT_CLASS} rows={3} required minLength={5} maxLength={500} autoFocus value={refundReason} onChange={(event) => setRefundReason(event.target.value)} />
            </Field>
            {refundOnline > 0 && (
              <label className="flex items-start gap-2.5 text-[13px] text-slate-700 cursor-pointer">
                <input type="checkbox" className={`${CHECKBOX_CLASS} mt-0.5`} checked={refundOutside} onChange={(event) => setRefundOutside(event.target.checked)} />
                <span>
                  <strong className="font-semibold text-slate-900">{gatewayName} ya no deja devolver este pago</strong> (por ejemplo, pasó su plazo). Ya devolví el dinero por otro medio y
                  solo quiero dejarlo registrado: el sistema no le pedirá nada a la pasarela.
                </span>
              </label>
            )}
            {(refundManual > 0 || refundOutside) && (
              <Field
                label={refundOutside ? 'Cómo devolviste el dinero' : 'Cómo devolviste el dinero (opcional)'}
                hint="Ejemplo: «Transferencia BCP, operación 445566»."
              >
                <input className={INPUT_CLASS} maxLength={120} required={refundOutside} value={refundReference} onChange={(event) => setRefundReference(event.target.value)} />
              </Field>
            )}
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
  // Cambia cada vez que una orden cambia, para que la lista de cuotas se vuelva a cargar
  const [version, setVersion] = useState(0);
  const [searchParams, setSearchParams] = useSearchParams();
  // Orden que pide abrir un enlace (por ejemplo, el aviso de una cuota vencida)
  const linked = searchParams.get('order');

  // El aviso de «otra cuenta» solo viene en el detalle: una respuesta que no lo trae conserva el que ya se veía
  const show = useCallback((order: Order) => {
    setOpen((current) => (order.account_warning === undefined && current?.id === order.id ? { ...order, account_warning: current.account_warning } : order));
  }, []);

  const openById = useCallback(async (orderId: string) => {
    try {
      show(await salesApi.order(orderId));
    } catch {
      // Si la orden ya no existe, la lista sigue a la vista
    }
  }, [show]);

  useEffect(() => {
    if (!linked) return;
    const timer = setTimeout(() => {
      void openById(linked);
      setSearchParams({}, { replace: true });
    }, 0);
    return () => clearTimeout(timer);
  }, [linked, openById, setSearchParams]);

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
    { key: 'PARTIAL', label: 'En cuotas', value: counts.PARTIAL ?? null, icon: CalendarClock, tone: 'info', help: 'Se pagan por partes: ya entró al menos una cuota y el comprador tiene acceso. Falta cobrar el resto.' },
    { key: 'PAID', label: 'Pagadas', value: counts.PAID ?? null, icon: CircleDollarSign, tone: 'success', help: 'Pago confirmado: el comprador ya está matriculado, o la empresa ya tiene sus licencias.' },
    { key: 'EXPIRED', label: 'Vencidas', value: counts.EXPIRED ?? null, icon: TimerOff, tone: 'danger', help: 'No se pagaron dentro del plazo. El comprador puede hacer una compra nueva.' },
    { key: 'CANCELLED', label: 'Canceladas', value: counts.CANCELLED ?? null, icon: Ban, tone: 'neutral', help: 'Las canceló el comprador o el personal antes de pagarse.' },
    { key: 'REFUNDED', label: 'Reembolsadas', value: counts.REFUNDED ?? null, icon: Undo2, tone: 'info', help: 'Se devolvió todo lo cobrado, fuera un pago único o las cuotas pagadas. El comprador ya no tiene la matrícula de esa compra y no cuentan como ingreso.' },
  ];

  return (
    <div>
      <PageHeader
        title="Ventas y pagos"
        icon={CreditCard}
        subtitle="Órdenes de compra de tus alumnos y confirmación de los pagos recibidos."
        action={<ExportButton path="/orders/export" params={{ search: search || undefined, status: status || undefined }} filename="ordenes.xlsx" />}
      />

      <PaymentReviewList version={version} onOpen={(orderId) => void openById(orderId)} />

      <DueInstallments version={version} onOpen={(orderId) => void openById(orderId)} />

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
          <SearchSelect
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
          </SearchSelect>
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
                    <Button
                      size="sm"
                      onClick={() => {
                        show(order);
                        void openById(order.id);
                      }}
                      aria-label={`Ver la orden ${orderCode(order.number)}`}>
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
          canRefund={hasPermission('payments.refund')}
          onClose={() => setOpen(null)}
          onChanged={(order) => {
            setOpen(order);
            setVersion((current) => current + 1);
            void load();
          }}
        />
      )}
    </div>
  );
};
