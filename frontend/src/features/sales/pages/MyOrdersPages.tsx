import React, { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { ArrowLeft, CheckCircle2, Clock, Compass, CreditCard, Receipt, Wallet } from 'lucide-react';
import { apiError, apiErrorMessage } from '../../../shared/api/client';
import { Alert, Button, Card, ConfirmModal, EmptyState, ErrorState, LoadingState, PageHeader } from '../../../shared/ui';
import { formatDateTime, formatMoney, orderCode, salesApi, type MyOrder, type Order } from '../api';
import { OrderStatusBadge, OrderSummary } from '../components/OrderParts';

// "Mis compras": las órdenes del comprador
export const MyOrdersPage: React.FC = () => {
  const navigate = useNavigate();
  const [orders, setOrders] = useState<Order[]>([]);
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [error, setError] = useState('');
  const [attempt, setAttempt] = useState(0);

  useEffect(() => {
    let active = true;
    salesApi
      .myOrders()
      .then((result) => {
        if (!active) return;
        setOrders(result);
        setState('ready');
      })
      .catch((err) => {
        if (!active) return;
        setError(apiErrorMessage(err, 'Verifica tu conexión e inténtalo nuevamente.'));
        setState('error');
      });
    return () => {
      active = false;
    };
  }, [attempt]);

  return (
    <div>
      <PageHeader title="Mis compras" icon={Receipt} subtitle="Tus órdenes de compra y el estado de cada pago." />

      {state === 'loading' && <LoadingState />}
      {state === 'error' && (
        <Card>
          <ErrorState
            message={error}
            onRetry={() => {
              setState('loading');
              setAttempt((n) => n + 1);
            }}
          />
        </Card>
      )}
      {state === 'ready' && orders.length === 0 && (
        <Card>
          <EmptyState
            icon={Receipt}
            title="Aún no tienes compras"
            text="Cuando compres un curso, aquí verás tu orden y cómo pagarla."
            action={
              <Button variant="primary" icon={<Compass className="w-4 h-4" />} onClick={() => navigate('/available-courses')}>
                Ver cursos disponibles
              </Button>
            }
          />
        </Card>
      )}
      {state === 'ready' && orders.length > 0 && (
        <div className="space-y-3">
          {orders.map((order) => (
            <Link
              key={order.id}
              to={`/my-orders/${order.id}`}
              className="group flex flex-wrap items-center justify-between gap-x-6 gap-y-3 bg-white border border-slate-200/80 rounded-2xl shadow-card px-5 py-4 hover:shadow-raised hover:-translate-y-0.5 transition-all"
            >
              <div className="min-w-0">
                <p className="text-xs font-semibold text-slate-500 tnum">
                  Orden {orderCode(order.number)} · {formatDateTime(order.created_at)}
                </p>
                <p className="text-[15px] font-semibold text-slate-900 group-hover:text-brand-700 transition-colors truncate">
                  {order.items.map((item) => item.title).join(', ')}
                </p>
              </div>
              <div className="flex items-center gap-4">
                <OrderStatusBadge status={order.status} />
                <span className="text-base font-bold text-slate-900 tnum whitespace-nowrap">{formatMoney(order.total_amount, order.currency)}</span>
              </div>
            </Link>
          ))}
        </div>
      )}
    </div>
  );
};

// Una orden del comprador: cómo pagarla mientras está pendiente y, pagada, el acceso al curso
export const MyOrderPage: React.FC = () => {
  const { orderId = '' } = useParams();
  const navigate = useNavigate();
  const [order, setOrder] = useState<MyOrder | null>(null);
  const [state, setState] = useState<'loading' | 'ready' | 'not_found' | 'error'>('loading');
  const [error, setError] = useState('');
  const [cancelling, setCancelling] = useState(false);
  const [searchParams] = useSearchParams();
  // Vuelve de la pasarela: en vez de esperar su aviso, se le pregunta si ya pagó
  const [returned] = useState(() => ['payment_id', 'collection_status', 'status', 'token', 'PayerID'].some((key) => searchParams.has(key)));
  const [paying, setPaying] = useState<string | null>(null);
  const [payError, setPayError] = useState<string | null>(null);
  const [checking, setChecking] = useState(false);

  const load = useCallback(async () => {
    try {
      // Si la pasarela no responde, la orden se muestra igual
      setOrder(returned ? await salesApi.syncPayment(orderId).catch(() => salesApi.myOrder(orderId)) : await salesApi.myOrder(orderId));
      setState('ready');
    } catch (err) {
      setError(apiErrorMessage(err, 'Verifica tu conexión e inténtalo nuevamente.'));
      setState(apiError(err)?.code === 'NOT_FOUND' ? 'not_found' : 'error');
    }
  }, [orderId, returned]);

  useEffect(() => {
    const timer = setTimeout(load, 0);
    return () => clearTimeout(timer);
  }, [load]);

  const instructions = order?.payment_instructions;
  const gateways = order?.status === 'PENDING' ? order.online_gateways : [];

  const payWith = async (gateway: string) => {
    if (!order) return;
    setPaying(gateway);
    setPayError(null);
    try {
      const { checkout_url } = await salesApi.payOnline(order.id, gateway, `${window.location.origin}/my-orders/${order.id}`);
      window.location.assign(checkout_url);
    } catch (err) {
      setPayError(apiErrorMessage(err, 'No se pudo abrir el pago en línea. Inténtalo de nuevo.'));
      setPaying(null);
    }
  };

  const checkAgain = async () => {
    setChecking(true);
    setPayError(null);
    try {
      setOrder(await salesApi.syncPayment(orderId));
    } catch (err) {
      setPayError(apiErrorMessage(err, 'No se pudo comprobar el pago. Inténtalo de nuevo.'));
    } finally {
      setChecking(false);
    }
  };

  return (
    <div className="max-w-3xl">
      <Link to="/my-orders" className="inline-flex items-center gap-1.5 text-[13px] font-semibold text-brand-700 hover:text-brand-900 mb-4">
        <ArrowLeft className="w-4 h-4" /> Mis compras
      </Link>

      {state === 'loading' && <LoadingState />}
      {state === 'error' && (
        <Card>
          <ErrorState
            message={error}
            onRetry={() => {
              setState('loading');
              void load();
            }}
          />
        </Card>
      )}
      {state === 'not_found' && (
        <Card>
          <EmptyState icon={Receipt} title="No encontramos esta orden" text="Revisa tus compras: puede que el enlace no sea el correcto." />
        </Card>
      )}
      {state === 'ready' && order && (
        <div className="space-y-5">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <h1 className="text-xl font-bold tracking-tight text-slate-900">Orden {orderCode(order.number)}</h1>
              <p className="text-[13px] text-slate-500">Generada el {formatDateTime(order.created_at)}</p>
            </div>
            <OrderStatusBadge status={order.status} />
          </div>

          {order.status === 'PAID' && (
            <Card className="p-5 sm:p-6 border-emerald-200 bg-emerald-50/60">
              <div className="flex flex-wrap items-center justify-between gap-4">
                <div className="flex items-start gap-3">
                  <CheckCircle2 className="w-5 h-5 shrink-0 text-emerald-600 mt-0.5" />
                  <div>
                    <p className="text-sm font-semibold text-emerald-900">
                      {Number(order.total_amount) === 0 ? 'Ya estás inscrito' : 'Pago confirmado: tu matrícula está activa'}
                    </p>
                    <p className="text-[13px] text-emerald-800">El curso ya está en "Mis cursos".</p>
                  </div>
                </div>
                <Button variant="primary" onClick={() => navigate(`/my-courses/${order.items[0].course_id}`)}>
                  Ir al curso
                </Button>
              </div>
            </Card>
          )}

          {order.status === 'PENDING' && returned && (
            <Alert tone="info">
              Todavía no vemos tu pago. Si acabas de pagar, puede tardar unos minutos en confirmarse; si lo dejaste a medias, puedes intentarlo otra vez.{' '}
              <button type="button" className="font-semibold underline cursor-pointer disabled:opacity-60" disabled={checking} onClick={checkAgain}>
                {checking ? 'Comprobando…' : 'Volver a comprobar'}
              </button>
            </Alert>
          )}

          {gateways.length > 0 && (
            <Card className="p-5 sm:p-6 space-y-4">
              <div className="flex items-start gap-3">
                <span className="w-9 h-9 shrink-0 rounded-xl bg-brand-50 text-brand-700 flex items-center justify-center">
                  <CreditCard className="w-4.5 h-4.5" />
                </span>
                <div>
                  <h2 className="text-sm font-semibold text-slate-900">Paga en línea</h2>
                  <p className="text-[13px] text-slate-500">
                    Paga <strong className="text-slate-900 tnum">{formatMoney(order.total_amount, order.currency)}</strong> ahora y tu acceso se activa al momento. Te llevamos a la
                    página segura del medio de pago y vuelves aquí.
                  </p>
                </div>
              </div>
              {payError && <Alert>{payError}</Alert>}
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
                  <p key={gateway.code} className="text-xs text-slate-500">
                    {gateway.label} cobra en dólares: pagarás <strong className="text-slate-700 tnum">{formatMoney(gateway.charge!.amount, gateway.charge!.currency)}</strong>
                    {gateway.charge!.rate ? `, al tipo de cambio de S/ ${Number(gateway.charge!.rate).toFixed(2)} por dólar que fijó la academia` : ''}.
                  </p>
                ))}
            </Card>
          )}

          {order.status === 'PENDING' && instructions && (
            <Card className="p-5 sm:p-6 space-y-4">
              <div className="flex items-start gap-3">
                <span className="w-9 h-9 shrink-0 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">
                  <Wallet className="w-4.5 h-4.5" />
                </span>
                <div>
                  <h2 className="text-sm font-semibold text-slate-900">{gateways.length > 0 ? 'O paga por fuera' : 'Cómo pagar'}</h2>
                  <p className="text-[13px] text-slate-500">
                    Paga <strong className="text-slate-900 tnum">{formatMoney(order.total_amount, order.currency)}</strong> e indica el número de orden.
                    Tu acceso se activa cuando la academia confirma el pago.
                  </p>
                </div>
              </div>

              {instructions.text ? (
                <p className="rounded-xl border border-slate-200 bg-slate-50/70 px-4 py-3 text-[13px] leading-relaxed text-slate-800 whitespace-pre-wrap break-words">
                  {instructions.text}
                </p>
              ) : (
                <Alert tone="info">La academia aún no publicó sus datos de pago. Comunícate con ella para coordinar cómo pagar esta orden.</Alert>
              )}

              {order.expires_at && (
                <p className="flex items-center gap-2 text-xs text-slate-500">
                  <Clock className="w-3.5 h-3.5" /> Tienes hasta el {formatDateTime(order.expires_at)} para pagar; después la orden vence y tendrás que generar otra.
                </p>
              )}
            </Card>
          )}

          {order.status === 'EXPIRED' && (
            <Alert tone="info">Esta orden venció sin pago. Si aún quieres el curso, vuelve a comprarlo desde "Cursos disponibles".</Alert>
          )}
          {order.status === 'REFUNDED' && (
            <Alert tone="info">
              Esta compra fue reembolsada{order.payment?.refund ? ` el ${formatDateTime(order.payment.refund.at)}` : ''}: se te devolvió el importe completo y ya no tienes acceso al
              curso. Si pagaste en línea, el dinero vuelve al medio con el que pagaste; el plazo depende de tu banco.
            </Alert>
          )}
          {order.status === 'CANCELLED' && <Alert tone="info">Esta orden fue cancelada el {formatDateTime(order.cancelled_at)}.</Alert>}

          <Card className="p-5 sm:p-6">
            <h2 className="text-sm font-semibold text-slate-900 mb-3">Detalle de la compra</h2>
            <OrderSummary order={order} />
          </Card>

          {order.status === 'PENDING' && (
            <div className="flex justify-end">
              <Button variant="danger" onClick={() => setCancelling(true)}>
                Cancelar orden
              </Button>
            </div>
          )}
        </div>
      )}

      {cancelling && order && (
        <ConfirmModal
          title="Cancelar orden"
          text={`Se cancelará la orden ${orderCode(order.number)}. Si ya pagaste, no la canceles: espera a que la academia confirme tu pago.`}
          confirmLabel="Cancelar orden"
          onClose={() => setCancelling(false)}
          onConfirm={async () => {
            setOrder(await salesApi.cancelMyOrder(order.id));
            setCancelling(false);
          }}
        />
      )}
    </div>
  );
};
