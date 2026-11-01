import React, { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, CheckCircle2, Compass, Layers, ShieldCheck, ShoppingCart } from 'lucide-react';
import { useAuth } from '../../auth/AuthContext';
import { apiError, apiErrorMessage } from '../../../shared/api/client';
import { withNext } from '../../../shared/auth/next';
import { Alert, Badge, Button, Card, EmptyState, ErrorState, LoadingState, PageHeader } from '../../../shared/ui';
import { COURSE_CARD_CLASS, formatMoney, salesApi, type CatalogPackage, type CheckoutPackage, type CouponCheck } from '../api';
import { CouponBox } from '../components/CouponBox';
import { CourseCardBody } from '../components/CourseCatalog';
import { PublicShell } from '../components/PublicShell';
import { billingPayload, invoicingApi, type BillingInput } from '../../invoicing/api';
import { BillingFields } from '../../invoicing/components/BillingFields';

// Precio del paquete con lo que costaría por separado y el ahorro
const PackagePrice: React.FC<{ item: CatalogPackage; className?: string }> = ({ item, className = '' }) => (
  <div className={className}>
    <span className="font-bold text-slate-900 tnum">{formatMoney(item.price, item.currency)}</span>
    {Number(item.savings) > 0 && (
      <span className="block text-xs text-slate-500 mt-0.5">
        <s className="tnum">{formatMoney(item.list_price, item.currency)}</s> por separado ·{' '}
        <strong className="text-emerald-700">ahorras {formatMoney(item.savings, item.currency)}</strong>
      </span>
    )}
  </div>
);

// Tarjeta de un paquete para el catálogo
export const PackageCard: React.FC<{ item: CatalogPackage }> = ({ item }) => (
  <Link
    to={`/catalog/packages/${item.id}`}
    className="group flex flex-col rounded-2xl border border-brand-200/70 bg-gradient-to-br from-white to-brand-50/60 p-5 shadow-card hover:shadow-raised hover:-translate-y-0.5 transition-all"
  >
    <div className="flex items-center gap-2.5">
      <span className="w-9 h-9 shrink-0 rounded-xl bg-gradient-to-br from-brand-700 to-accent-500 text-white flex items-center justify-center">
        <Layers className="w-4 h-4" />
      </span>
      <Badge tone="brand">Paquete · {item.courses.length} cursos</Badge>
    </div>
    <h2 className="text-[15px] leading-5 font-semibold text-slate-900 group-hover:text-brand-700 transition-colors mt-3">{item.title}</h2>
    {item.summary && <p className="text-[13px] text-slate-500 mt-1.5 line-clamp-2">{item.summary}</p>}
    <ul className="mt-3 space-y-1 text-[13px] text-slate-600">
      {item.courses.slice(0, 4).map((course) => (
        <li key={course.id} className="flex items-start gap-1.5">
          <CheckCircle2 className="w-3.5 h-3.5 mt-0.5 shrink-0 text-emerald-500" />
          <span className="truncate">{course.title}</span>
        </li>
      ))}
      {item.courses.length > 4 && <li className="text-xs text-slate-500 pl-5">y {item.courses.length - 4} más</li>}
    </ul>
    <PackagePrice item={item} className="mt-auto pt-4 text-lg" />
  </Link>
);

// Ficha pública de un paquete, con el botón de compra
export const PublicPackagePage: React.FC = () => {
  const { packageId = '' } = useParams();
  const navigate = useNavigate();
  const { isAuthenticated } = useAuth();
  const [result, setResult] = useState<{ id: string; item: CatalogPackage | null; error: string | null } | null>(null);

  useEffect(() => {
    let active = true;
    salesApi
      .catalogPackage(packageId)
      .then((item) => active && setResult({ id: packageId, item, error: null }))
      .catch((err) => active && setResult({ id: packageId, item: null, error: apiError(err)?.code === 'NOT_FOUND' ? 'missing' : apiErrorMessage(err, 'Verifica tu conexión e inténtalo nuevamente.') }));
    return () => {
      active = false;
    };
  }, [packageId]);

  const current = result?.id === packageId ? result : null;
  const item = current?.item;
  const checkout = `/checkout/package/${packageId}`;

  return (
    <PublicShell>
      <Link to="/catalog" className="inline-flex items-center gap-1.5 text-[13px] font-semibold text-brand-700 hover:text-brand-900 mb-5">
        <ArrowLeft className="w-4 h-4" /> Todo el catálogo
      </Link>

      {!current && <LoadingState />}
      {current?.error === 'missing' && (
        <Card>
          <EmptyState icon={Compass} title="Este paquete no está disponible" text="Puede que ya no esté en venta. Revisa el catálogo." />
        </Card>
      )}
      {current?.error && current.error !== 'missing' && (
        <Card>
          <ErrorState message={current.error} />
        </Card>
      )}
      {item && (
        <div className="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_340px] gap-6 items-start">
          <div>
            <Badge tone="brand">Paquete · {item.courses.length} cursos</Badge>
            <h1 className="text-2xl sm:text-3xl font-bold tracking-tight text-slate-900 mt-2">{item.title}</h1>
            {item.summary && <p className="text-[15px] text-slate-600 mt-2">{item.summary}</p>}
            <h2 className="text-sm font-semibold text-slate-900 mt-6 mb-3">Cursos incluidos</h2>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              {item.courses.map((course) => (
                <Link key={course.id} to={`/catalog/${course.id}`} className={COURSE_CARD_CLASS}>
                  <CourseCardBody course={course} action="Ver curso" />
                </Link>
              ))}
            </div>
          </div>

          <Card className="p-5 sm:p-6 lg:sticky lg:top-6">
            <p className="text-xs font-semibold text-slate-500">Precio del paquete</p>
            <PackagePrice item={item} className="text-3xl mt-1" />
            <Button
              variant="primary"
              size="lg"
              className="w-full mt-5"
              icon={<ShoppingCart className="w-4 h-4" />}
              onClick={() => navigate(isAuthenticated ? checkout : withNext('/signup', checkout))}
            >
              Comprar paquete
            </Button>
            {!isAuthenticated && (
              <p className="text-[13px] text-slate-500 mt-3 text-center">
                ¿Ya tienes cuenta?{' '}
                <Link to={withNext('/login', checkout)} className="font-semibold text-brand-700 hover:text-brand-900">
                  Inicia sesión
                </Link>
              </p>
            )}
            <p className="flex items-start gap-2 text-xs text-slate-500 mt-5 pt-4 border-t border-slate-200/80">
              <ShieldCheck className="w-4 h-4 shrink-0 text-emerald-600" />
              Una sola compra te matricula en todos sus cursos. Tu acceso se activa cuando la academia confirma el pago.
            </p>
          </Card>
        </div>
      )}
    </PublicShell>
  );
};

// Checkout de un paquete (FP-03): resumen, cupón y confirmación
export const PackageCheckoutPage: React.FC = () => {
  const { packageId = '' } = useParams();
  const navigate = useNavigate();
  const [item, setItem] = useState<CheckoutPackage | null>(null);
  const [state, setState] = useState<'loading' | 'ready' | 'unavailable' | 'error'>('loading');
  const [loadError, setLoadError] = useState('');
  const [coupon, setCoupon] = useState<CouponCheck | null>(null);
  // Si la academia emite comprobantes, se pregunta boleta o factura
  const [invoicing, setInvoicing] = useState(false);
  const [billing, setBilling] = useState<BillingInput>({ type: 'BOLETA', doc_type: 'DNI' });
  const [billingErrors, setBillingErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    invoicingApi.enabled().then(setInvoicing).catch(() => setInvoicing(false));
  }, []);
  const [processing, setProcessing] = useState(false);
  const [notice, setNotice] = useState<{ tone: 'danger' | 'info'; text: string } | null>(null);

  useEffect(() => {
    let active = true;
    salesApi
      .checkoutPackage(packageId)
      .then((loaded) => {
        if (!active) return;
        setItem(loaded);
        // Si ya tiene todos sus cursos, no hay nada que comprar
        setState(loaded.owned_course_ids.length >= loaded.courses.length ? 'unavailable' : 'ready');
      })
      .catch((err) => {
        if (!active) return;
        setLoadError(apiErrorMessage(err, 'Verifica tu conexión e inténtalo nuevamente.'));
        setState(apiError(err)?.code === 'NOT_FOUND' ? 'unavailable' : 'error');
      });
    return () => {
      active = false;
    };
  }, [packageId]);

  const confirm = async () => {
    if (!item) return;
    setProcessing(true);
    setNotice(null);

    try {
      const order = await salesApi.placePackageOrder(item.id, coupon?.total_amount ?? item.price, coupon?.code, invoicing ? billingPayload(billing) : undefined);
      navigate(`/my-orders/${order.id}`, { replace: true });
    } catch (err) {
      const data = apiError(err);

      if (data?.code === 'ORDER_ALREADY_PENDING' && typeof data.details?.order_id === 'string') {
        navigate(`/my-orders/${data.details.order_id}`, { replace: true });
        return;
      }

      if (data?.code === 'PRICE_CHANGED' && typeof data.details?.subtotal === 'string') {
        setItem({ ...item, price: data.details.subtotal });
        setCoupon(null);
        setNotice({ tone: 'info', text: 'El precio cambió mientras lo veías. Revisa el nuevo total (si tenías un cupón, aplícalo otra vez) y confirma.' });
      } else if (data?.code === 'VALIDATION_ERROR' && data.details) {
        // Los datos del comprobante: cada error junto a su campo
        setBillingErrors(Object.fromEntries(Object.entries(data.details as Record<string, string[]>).map(([key, messages]) => [key, messages[0]])));
        setNotice({ tone: 'danger', text: 'Revisa los datos del comprobante.' });
      } else if (data?.code === 'COUPON_INVALID') {
        setCoupon(null);
        setNotice({ tone: 'danger', text: `${data.message} Puedes continuar sin el cupón.` });
      } else {
        setNotice({ tone: 'danger', text: apiErrorMessage(err, 'No se pudo generar la orden. Inténtalo nuevamente.') });
      }
      setProcessing(false);
    }
  };

  const total = coupon?.total_amount ?? item?.price ?? '0';
  const owned = item?.owned_course_ids ?? [];

  return (
    <div className="max-w-3xl">
      <Link to={`/catalog/packages/${packageId}`} className="inline-flex items-center gap-1.5 text-[13px] font-semibold text-brand-700 hover:text-brand-900 mb-4">
        <ArrowLeft className="w-4 h-4" /> Volver al paquete
      </Link>
      <PageHeader title="Confirma tu compra" icon={ShoppingCart} subtitle="Revisa el paquete y el total antes de continuar." />

      {state === 'loading' && <LoadingState />}
      {state === 'error' && (
        <Card>
          <ErrorState message={loadError} />
        </Card>
      )}
      {state === 'unavailable' && (
        <Card>
          <EmptyState
            icon={Compass}
            title="Este paquete no se puede comprar"
            text="Puede que ya tengas todos sus cursos o que ya no esté en venta."
            action={
              <Button variant="primary" onClick={() => navigate('/my-courses')}>
                Ir a mis cursos
              </Button>
            }
          />
        </Card>
      )}
      {state === 'ready' && item && (
        <Card>
          <div className="p-5 sm:p-6 border-b border-slate-200/80">
            <Badge tone="brand">Paquete · {item.courses.length} cursos</Badge>
            <h2 className="text-lg font-semibold text-slate-900 mt-2">{item.title}</h2>
            {item.summary && <p className="text-[13px] text-slate-500 mt-1">{item.summary}</p>}
            <ul className="mt-4 rounded-xl border border-slate-200 divide-y divide-slate-100">
              {item.courses.map((course) => (
                <li key={course.id} className="flex items-center justify-between gap-3 px-3.5 py-2.5 text-[13px]">
                  <span className="font-medium text-slate-900">{course.title}</span>
                  {owned.includes(course.id) ? <Badge tone="warning">Ya lo tienes</Badge> : <Badge tone="success">Se activará</Badge>}
                </li>
              ))}
            </ul>
          </div>

          <div className="p-5 sm:p-6 space-y-4">
            {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}
            {owned.length > 0 && (
              <Alert tone="info">
                Ya estás matriculado en {owned.length === 1 ? 'uno de los cursos' : `${owned.length} de los cursos`} de este paquete. Si lo compras, pagas el precio completo y solo se activan los
                que te faltan.
              </Alert>
            )}

            <dl className="space-y-2 text-[13px]">
              <div className="flex justify-between gap-4">
                <dt className="text-slate-600">Paquete</dt>
                <dd className="font-medium text-slate-900 tnum">{formatMoney(item.price, item.currency)}</dd>
              </div>
              {coupon && (
                <div className="flex justify-between gap-4">
                  <dt className="text-slate-600">Descuento (cupón {coupon.code})</dt>
                  <dd className="font-medium text-emerald-700 tnum">− {formatMoney(coupon.discount_amount, item.currency)}</dd>
                </div>
              )}
              <div className="flex justify-between gap-4 pt-3 border-t border-slate-200/80 text-base">
                <dt className="font-semibold text-slate-900">Total</dt>
                <dd className="font-bold text-slate-900 tnum">{Number(total) === 0 ? 'Gratis' : formatMoney(total, item.currency)}</dd>
              </div>
            </dl>

            {Number(item.price) > 0 && <CouponBox product={{ package_id: item.id }} applied={coupon} onChange={setCoupon} />}

            {invoicing && Number(total) > 0 && (
              <BillingFields value={billing} onChange={setBilling} total={Number(total)} currency={item.currency} errors={billingErrors} />
            )}

            <p className="flex items-start gap-2 text-xs text-slate-500">
              <ShieldCheck className="w-4 h-4 shrink-0 text-emerald-600" />
              Al confirmar se genera tu orden con las instrucciones de pago. Tu acceso a los cursos se activa cuando la academia confirma que recibió el pago.
            </p>

            <div className="flex flex-wrap justify-end gap-2.5 pt-1">
              <Button onClick={() => navigate(`/catalog/packages/${packageId}`)}>Volver</Button>
              <Button variant="primary" size="lg" processing={processing} onClick={confirm}>
                Confirmar compra
              </Button>
            </div>
          </div>
        </Card>
      )}
    </div>
  );
};
