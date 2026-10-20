import type { CourseLevel } from '../courses/api';
import { api, postIdempotent } from '../../shared/api/client';
import type { Tone } from '../../shared/ui';
import type { PageMeta } from '../identity/api';
import type { AvailableCourse, AvailableCourseDetail } from '../enrollments/api';

// REFUNDED: se pagó y después se devolvió el dinero completo
// PARTIAL = se paga en cuotas: ya entró al menos una y el comprador tiene acceso
export type OrderStatus = 'PENDING' | 'PARTIAL' | 'PAID' | 'CANCELLED' | 'EXPIRED' | 'REFUNDED';
// CARD, WALLET y PAYPAL solo llegan por una pasarela en línea
export type PaymentMethod = 'TRANSFER' | 'YAPE' | 'CASH' | 'OTHER' | 'CARD' | 'WALLET' | 'PAYPAL';
export type PaymentGatewayCode = 'MANUAL' | 'MERCADOPAGO' | 'PAYPAL';

// Pasarela con la que se puede pagar en línea. `charge` solo viene cuando cobra en otra moneda:
// cuánto cobraría y con qué tipo de cambio (soles por dólar)
export interface OnlineGatewayOption {
  code: string;
  label: string;
  charge: { currency: string; amount: string; rate: string | null } | null;
}

export const ORDER_STATUSES: OrderStatus[] = ['PENDING', 'PARTIAL', 'PAID', 'CANCELLED', 'EXPIRED', 'REFUNDED'];
export const PAYMENT_METHODS: PaymentMethod[] = ['TRANSFER', 'YAPE', 'CASH', 'OTHER'];

export const ORDER_STATUS_LABEL: Record<OrderStatus, string> = {
  PENDING: 'Pendiente de pago',
  PARTIAL: 'En cuotas',
  PAID: 'Pagada',
  CANCELLED: 'Cancelada',
  EXPIRED: 'Vencida',
  REFUNDED: 'Reembolsada',
};

export const ORDER_STATUS_TONE: Record<OrderStatus, Tone> = {
  PENDING: 'warning',
  PARTIAL: 'info',
  PAID: 'success',
  CANCELLED: 'neutral',
  EXPIRED: 'danger',
  REFUNDED: 'info',
};

export const PAYMENT_METHOD_LABEL: Record<PaymentMethod, string> = {
  TRANSFER: 'Transferencia o depósito',
  YAPE: 'Yape',
  CASH: 'Efectivo',
  OTHER: 'Otro',
  CARD: 'Tarjeta',
  WALLET: 'Saldo en la pasarela',
  PAYPAL: 'PayPal',
};

export const PAYMENT_GATEWAY_LABEL: Record<string, string> = {
  MERCADOPAGO: 'Mercado Pago',
  PAYPAL: 'PayPal',
};

export interface OrderItem {
  course_id: string;
  title: string;
  unit_price: string;
  quantity: number;
  total_amount: string;
  // El pago de la orden ya generó la matrícula
  enrolled: boolean;
}

export interface OrderPayment {
  method: PaymentMethod;
  // MANUAL = lo confirmó el personal; si no, la pasarela que lo cobró
  gateway: PaymentGatewayCode;
  reference: string | null;
  notes: string | null;
  amount: string;
  paid_at: string;
  confirmed_by: string | null;
  // Reembolso total, si lo hubo
  refund: { at: string; reason: string | null; reference: string | null; by: string | null } | null;
}

export interface Order {
  id: string;
  number: number;
  status: OrderStatus;
  // Compra personal: quién compró. Venta a una empresa: la empresa (customer es null).
  customer: { id: string; name: string; email: string } | null;
  company: { id: string; name: string } | null;
  currency: string;
  subtotal_amount: string;
  discount_amount: string;
  // Cupón aplicado, si lo hubo
  coupon_code: string | null;
  // La orden es de un paquete: sus ítems son los cursos que incluye
  package: { id: string; title: string } | null;
  total_amount: string;
  created_at: string;
  expires_at: string | null;
  paid_at: string | null;
  cancelled_at: string | null;
  refunded_at: string | null;
  // Venta a empresa cuyas licencias se entregaron antes del pago
  licenses_released_at: string | null;
  licenses_release_reason: string | null;
  items: OrderItem[];
  payment: OrderPayment | null;
  // Cobros que una pasarela hizo y no se aplicaron a la orden: los resuelve una persona
  payment_reviews: PaymentReview[];
  // Veces que el comprador fue a pagarla a una pasarela: si hay alguna, se le puede volver a preguntar
  online_attempts: number;
  // Solo en el detalle: la orden se cobró con otra cuenta de la pasarela que la conectada hoy
  account_warning?: string | null;
  // Lo cobrado que sigue en pie (es lo que devolvería un reembolso): el pago único o el de cada cuota
  collected: { amount: string; payments: number; online_amount: string; manual_amount: string; gateways: string[] };
  refunded_amount: string;
  // Plan de pagos; vacío = la orden se paga de una vez
  installments: Installment[];
  debt: OrderDebt | null;
}

// Una cuota de una orden que se paga por partes
export interface Installment {
  id: string;
  number: number;
  amount: string;
  // AAAA-MM-DD
  due_date: string;
  status: 'PENDING' | 'PAID';
  // Sin pagar y con su fecha ya pasada
  overdue: boolean;
  paid_at: string | null;
  payment: {
    method: PaymentMethod;
    gateway: string;
    reference: string | null;
    confirmed_by: string | null;
    // La cuota se pagó y después se devolvió
    refund: { at: string; reason: string | null; reference: string | null } | null;
  } | null;
}

// Un cobro que una pasarela hizo y no se aplicó a la orden
export interface PaymentReview {
  id: string;
  gateway: string;
  payment_id: string | null;
  reason: string | null;
  amount: string;
  currency: string;
  at: string;
}

export interface PaymentReviewRow extends PaymentReview {
  order: { id: string; number: number; status: OrderStatus; title: string; customer: { name: string; email: string } | null };
}

export interface OrderDebt {
  paid_amount: string;
  pending_amount: string;
  overdue_amount: string;
  overdue_count: number;
  // La cuota que toca pagar ahora
  next: Installment | null;
}

// Una cuota por cobrar, con quién la debe
export interface DueInstallment extends Installment {
  days_overdue: number;
  order: {
    id: string;
    number: number;
    currency: string;
    title: string;
    customer: { id: string; name: string; email: string; phone: string | null } | null;
  };
}

// Lo que ve el comprador: mientras la orden espera su pago, cómo pagarla
export interface MyOrder extends Order {
  payment_instructions: { type: string; text: string | null; methods: PaymentMethod[] } | null;
  // Pasarelas con las que puede pagarla en línea ahora mismo
  online_gateways: OnlineGatewayOption[];
}

// Resultado de probar un cupón: cuánto descuenta y cuánto se pagaría
export interface CouponCheck {
  code: string;
  promotion: string;
  discount_type: 'PERCENT' | 'FIXED';
  discount_value: string;
  currency: string;
  subtotal_amount: string;
  discount_amount: string;
  total_amount: string;
}

// Paquete del catálogo: varios cursos a un precio
export interface CatalogFilters {
  search?: string;
  category?: string;
  level?: string;
  price?: 'free' | 'paid';
}

// Con qué se puede filtrar el catálogo: lo que hay en todos los cursos en venta
export interface CatalogMeta {
  categories: string[];
  levels: CourseLevel[];
  has_free: boolean;
  has_paid: boolean;
  total: number;
}

export interface CatalogPackage {
  id: string;
  title: string;
  summary: string | null;
  price: string;
  currency: string;
  // Lo que costarían sus cursos por separado y cuánto se ahorra
  list_price: string;
  savings: string;
  courses: AvailableCourse[];
}

// En el checkout: además, qué cursos del paquete ya tiene el comprador
export interface CheckoutPackage extends CatalogPackage {
  owned_course_ids: string[];
}

export interface ConfirmPaymentInput {
  method: PaymentMethod;
  reference: string | null;
  notes: string | null;
}

const CURRENCY_SYMBOL: Record<string, string> = { PEN: 'S/', USD: 'US$' };

export function formatMoney(amount: string | number, currency: string): string {
  const value = Number(amount).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  return `${CURRENCY_SYMBOL[currency] ?? currency} ${value}`;
}

// Precio de un curso en el catálogo: gratuito, con importe, o sin venta
export function priceLabel(price: string | null, currency: string): string | null {
  if (price === null) return null;
  return Number(price) === 0 ? 'Gratis' : formatMoney(price, currency);
}

// Contenedor de una tarjeta del catálogo (enlace o botón)
export const COURSE_CARD_CLASS =
  'group flex flex-col text-left bg-white border border-slate-200/80 rounded-2xl shadow-card overflow-hidden hover:shadow-raised hover:-translate-y-0.5 transition-all cursor-pointer';

// A quién se le vendió: la persona o la empresa
export const orderBuyer = (order: Order) => ({
  name: order.customer?.name ?? order.company?.name ?? '—',
  detail: order.customer?.email ?? 'Venta a empresa',
});

export const orderCode = (number: number) => `N.º ${String(number).padStart(5, '0')}`;

export function formatDateTime(value: string | null): string {
  if (!value) return '—';
  return new Date(value).toLocaleString('es-PE', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

export const salesApi = {
  // Catálogo público: no requiere sesión
  async catalog(filters: CatalogFilters = {}) {
    const response = await api.get('/catalog/courses', { params: filters });
    return { courses: response.data.data as AvailableCourse[], meta: response.data.meta as CatalogMeta };
  },
  async catalogCourse(course: string) {
    return (await api.get(`/catalog/courses/${course}`)).data.data as AvailableCourseDetail;
  },
  async placeOrder(courseId: string, expectedTotal: string, couponCode?: string) {
    return (await postIdempotent('/orders', { course_id: courseId, expected_total: expectedTotal, coupon_code: couponCode })).data.data as MyOrder;
  },
  async placePackageOrder(packageId: string, expectedTotal: string, couponCode?: string) {
    return (await postIdempotent('/orders', { package_id: packageId, expected_total: expectedTotal, coupon_code: couponCode })).data.data as MyOrder;
  },
  // El servidor dice si el cupón aplica y cuánto se pagaría; vuelve a calcularlo al crear la orden
  async validateCoupon(code: string, product: { course_id: string } | { package_id: string }) {
    return (await api.post('/checkout/validate-coupon', { code, ...product })).data.data as CouponCheck;
  },
  async catalogPackages() {
    return (await api.get('/catalog/packages')).data.data as CatalogPackage[];
  },
  async catalogPackage(id: string) {
    return (await api.get(`/catalog/packages/${id}`)).data.data as CatalogPackage;
  },
  async checkoutPackage(id: string) {
    return (await api.get(`/checkout/packages/${id}`)).data.data as CheckoutPackage;
  },
  async myOrders() {
    return (await api.get('/me/orders')).data.data as Order[];
  },
  async myOrder(order: string) {
    return (await api.get(`/me/orders/${order}`)).data.data as MyOrder;
  },
  async cancelMyOrder(order: string) {
    return (await api.post(`/me/orders/${order}/cancel`)).data.data as MyOrder;
  },
  // Prepara el cobro en la pasarela y devuelve a dónde enviar al comprador
  async payOnline(order: string, gateway: string, returnUrl: string) {
    return (await api.post(`/me/orders/${order}/pay`, { gateway, return_url: returnUrl })).data.data as { checkout_url: string };
  },
  // Le pregunta a la pasarela si la orden ya se pagó
  async syncPayment(order: string) {
    return (await api.post(`/me/orders/${order}/pay/sync`)).data.data as MyOrder;
  },
  async orders(params: { search?: string; status?: string; page?: number }) {
    const response = await api.get('/orders', { params });
    return { data: response.data.data as Order[], meta: response.data.meta as PageMeta };
  },
  async order(order: string) {
    return (await api.get(`/orders/${order}`)).data.data as Order;
  },
  // Plan de pagos: lo arma el personal mientras la orden espera su primer pago
  async setInstallments(order: string, installments: { amount: string; due_date: string }[]) {
    return (await api.put(`/orders/${order}/installments`, { installments: installments.map((row) => ({ amount: Number(row.amount), due_date: row.due_date })) })).data.data as Order;
  },
  async removeInstallments(order: string) {
    return (await api.delete(`/orders/${order}/installments`)).data.data as Order;
  },
  // Cuotas por cobrar de toda la academia
  async dueInstallments(filter?: 'OVERDUE' | 'UPCOMING') {
    const response = await api.get('/orders/installments', { params: { filter } });
    return {
      data: response.data.data as DueInstallment[],
      counts: response.data.meta.counts as { ALL: number; OVERDUE: number; UPCOMING: number },
      amounts: response.data.meta.amounts as { pending: string; overdue: string },
      currency: response.data.meta.currency as string,
    };
  },
  // Cuotas vencidas, para el aviso emergente al personal
  async installmentAlerts() {
    return (await api.get('/orders/installments/alerts')).data.data as DueInstallment[];
  },
  // Cobros en revisión de toda la academia
  async paymentReviews() {
    return (await api.get('/orders/payment-reviews')).data.data as PaymentReviewRow[];
  },
  async resolvePaymentReview(review: string, note: string) {
    return (await api.post(`/orders/payment-reviews/${review}/resolve`, { note })).data.data as Order;
  },
  // Vuelve a preguntar a la pasarela por una orden
  async recheckPayment(order: string) {
    return (await api.post(`/orders/${order}/recheck-payment`)).data.data as Order;
  },
  async confirmPayment(order: string, input: ConfirmPaymentInput) {
    return (await postIdempotent(`/orders/${order}/confirm-payment`, input)).data.data as Order;
  },
  async releaseLicenses(order: string, reason: string) {
    return (await api.post(`/orders/${order}/release-licenses`, { reason })).data.data as Order;
  },
  // Reembolso de lo cobrado: en línea lo devuelve la pasarela; por fuera, se registra cómo se devolvió
  // `outsideGateway`: el pago fue en línea, pero la pasarela ya no deja devolverlo y el dinero se devolvió por otro medio
  async refundOrder(order: string, reason: string, reference: string | null, outsideGateway = false) {
    return (await postIdempotent(`/orders/${order}/refund`, { reason, reference, outside_gateway: outsideGateway })).data.data as Order;
  },
  // Lo que espera al personal, en cifras; null = esa persona no atiende ese tema
  async alertSummary() {
    return (await api.get('/alerts/summary')).data.data as {
      payment_reviews: number | null;
      tickets_waiting: number | null;
      pages_in_review: number | null;
      submissions_to_grade: number | null;
      overdue_installments: number | null;
      claims_unanswered: number | null;
    };
  },
  async cancelOrder(order: string) {
    return (await api.post(`/orders/${order}/cancel`)).data.data as Order;
  },
};
