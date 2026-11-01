import React, { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, Compass, ShieldCheck, ShoppingCart } from 'lucide-react';
import { apiError, apiErrorMessage } from '../../../shared/api/client';
import { Alert, Button, Card, EmptyState, ErrorState, LoadingState, PageHeader } from '../../../shared/ui';
import { enrollmentsApi, type AvailableCourseDetail } from '../../enrollments/api';
import { formatMoney, salesApi, type CouponCheck } from '../api';
import { CouponBox } from '../components/CouponBox';
import { CourseCover, CourseFacts } from '../components/CourseCatalog';
import { CourseCompanions } from '../components/CourseCompanions';
import { RefundPolicyNote } from '../components/RefundPolicyNote';
import { billingPayload, invoicingApi, type BillingInput } from '../../invoicing/api';
import { BillingFields } from '../../invoicing/components/BillingFields';

// Checkout (FP-02): resumen de la compra y confirmación. El precio que vale es el del servidor.
export const CheckoutPage: React.FC = () => {
  const { courseId = '' } = useParams();
  const navigate = useNavigate();
  const [course, setCourse] = useState<AvailableCourseDetail | null>(null);
  const [state, setState] = useState<'loading' | 'ready' | 'unavailable' | 'error'>('loading');
  const [loadError, setLoadError] = useState('');
  const [attempt, setAttempt] = useState(0);
  const [processing, setProcessing] = useState(false);
  const [notice, setNotice] = useState<{ tone: 'danger' | 'info'; text: string } | null>(null);
  const [coupon, setCoupon] = useState<CouponCheck | null>(null);
  // Si la academia emite comprobantes, se pregunta boleta o factura
  const [invoicing, setInvoicing] = useState(false);
  const [billing, setBilling] = useState<BillingInput>({ type: 'BOLETA', doc_type: 'DNI' });
  const [billingErrors, setBillingErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    invoicingApi.enabled().then(setInvoicing).catch(() => setInvoicing(false));
  }, []);

  useEffect(() => {
    let active = true;
    enrollmentsApi
      .availableCourse(courseId)
      .then((result) => {
        if (!active) return;
        setCourse(result);
        setState(result.price === null ? 'unavailable' : 'ready');
      })
      .catch((err) => {
        if (!active) return;
        setLoadError(apiErrorMessage(err, 'Verifica tu conexión e inténtalo nuevamente.'));
        // Sin ficha: el curso no existe, se archivó o el alumno ya está matriculado
        setState(apiError(err)?.code === 'NOT_FOUND' ? 'unavailable' : 'error');
      });
    return () => {
      active = false;
    };
  }, [courseId, attempt]);

  const confirm = async () => {
    if (!course?.price) return;
    setProcessing(true);
    setNotice(null);

    try {
      const order = await salesApi.placeOrder(course.id, coupon?.total_amount ?? course.price, coupon?.code, invoicing ? billingPayload(billing) : undefined);
      navigate(`/my-orders/${order.id}`, { replace: true });
    } catch (err) {
      const data = apiError(err);

      if (data?.code === 'ORDER_ALREADY_PENDING' && typeof data.details?.order_id === 'string') {
        navigate(`/my-orders/${data.details.order_id}`, { replace: true });
        return;
      }

      if (data?.code === 'PRICE_CHANGED' && typeof data.details?.subtotal === 'string') {
        // Cambió el precio o el descuento: se vuelve al precio vigente y el cupón se aplica de nuevo
        setCourse({ ...course, price: data.details.subtotal });
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

  const total = coupon?.total_amount ?? course?.price ?? '0';
  const free = Number(total) === 0;

  return (
    <div className="max-w-3xl">
      <Link to="/available-courses" className="inline-flex items-center gap-1.5 text-[13px] font-semibold text-brand-700 hover:text-brand-900 mb-4">
        <ArrowLeft className="w-4 h-4" /> Cursos disponibles
      </Link>
      <PageHeader title="Confirma tu compra" icon={ShoppingCart} subtitle="Revisa el curso y el total antes de continuar." />

      {state === 'loading' && <LoadingState />}
      {state === 'error' && (
        <Card>
          <ErrorState
            message={loadError}
            onRetry={() => {
              setState('loading');
              setAttempt((n) => n + 1);
            }}
          />
        </Card>
      )}
      {state === 'unavailable' && (
        <Card>
          <EmptyState
            icon={Compass}
            title="Este curso no se puede comprar"
            text="Puede que ya estés matriculado o que el curso ya no esté en venta."
            action={
              <div className="flex flex-wrap justify-center gap-2.5">
                <Button onClick={() => navigate('/my-courses')}>Ir a mis cursos</Button>
                <Button variant="primary" onClick={() => navigate('/available-courses')}>
                  Ver cursos disponibles
                </Button>
              </div>
            }
          />
        </Card>
      )}
      {state === 'ready' && course?.price && (
        <Card className="overflow-hidden">
          <div className="flex flex-col sm:flex-row gap-4 p-5 sm:p-6 border-b border-slate-200/80">
            <CourseCover url={course.cover_url} className="w-full sm:w-52 shrink-0 rounded-xl" />
            <div className="min-w-0">
              {course.category && <p className="text-xs font-semibold text-brand-600 mb-1">{course.category}</p>}
              <h2 className="text-lg font-semibold text-slate-900">{course.title}</h2>
              {course.summary && <p className="text-[13px] text-slate-500 mt-1">{course.summary}</p>}
              <div className="mt-3">
                <CourseFacts course={course} />
              </div>
              <p className="text-xs text-slate-500 mt-2">
                {course.access_days ? `Acceso por ${course.access_days} días desde que se activa tu matrícula.` : 'Acceso sin vencimiento.'}
              </p>
            </div>
          </div>

          <div className="p-5 sm:p-6 space-y-4">
            {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}

            <dl className="space-y-2 text-[13px]">
              <div className="flex justify-between gap-4">
                <dt className="text-slate-600">Curso</dt>
                <dd className="font-medium text-slate-900 tnum">{formatMoney(course.price, course.currency)}</dd>
              </div>
              {coupon && (
                <div className="flex justify-between gap-4">
                  <dt className="text-slate-600">Descuento (cupón {coupon.code})</dt>
                  <dd className="font-medium text-emerald-700 tnum">− {formatMoney(coupon.discount_amount, course.currency)}</dd>
                </div>
              )}
              <div className="flex justify-between gap-4 pt-3 border-t border-slate-200/80 text-base">
                <dt className="font-semibold text-slate-900">Total</dt>
                <dd className="font-bold text-slate-900 tnum">{free ? 'Gratis' : formatMoney(total, course.currency)}</dd>
              </div>
            </dl>

            {Number(course.price) > 0 && <CouponBox product={{ course_id: course.id }} applied={coupon} onChange={setCoupon} />}

            {invoicing && !free && (
              <BillingFields value={billing} onChange={setBilling} total={Number(total)} currency={course.currency} errors={billingErrors} />
            )}

            {Number(course.price) > 0 && <RefundPolicyNote />}

            <p className="flex items-start gap-2 text-xs text-slate-500">
              <ShieldCheck className="w-4 h-4 shrink-0 text-emerald-600" />
              {free
                ? 'Al inscribirte, el curso queda disponible en "Mis cursos" de inmediato.'
                : 'Al confirmar se genera tu orden con las instrucciones de pago. Tu acceso se activa cuando la academia confirma que recibió el pago.'}
            </p>

            <div className="flex flex-wrap justify-end gap-2.5 pt-1">
              <Button onClick={() => navigate('/available-courses')}>Volver</Button>
              <Button variant="primary" size="lg" processing={processing} onClick={confirm}>
                {free ? 'Inscribirme gratis' : 'Confirmar compra'}
              </Button>
            </div>
          </div>
        </Card>
      )}
      {course && <CourseCompanions courseId={course.id} currency={course.currency} className="mt-5" />}
    </div>
  );
};
