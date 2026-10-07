import { api, postIdempotent } from '../../shared/api/client';
import type { Tone } from '../../shared/ui';
import type { PageMeta } from '../identity/api';
import type { AvailableCourse, AvailableCourseDetail } from '../enrollments/api';

export type OrderStatus = 'PENDING' | 'PAID' | 'CANCELLED' | 'EXPIRED';
export type PaymentMethod = 'TRANSFER' | 'YAPE' | 'CASH' | 'OTHER';

export const ORDER_STATUSES: OrderStatus[] = ['PENDING', 'PAID', 'CANCELLED', 'EXPIRED'];
export const PAYMENT_METHODS: PaymentMethod[] = ['TRANSFER', 'YAPE', 'CASH', 'OTHER'];

export const ORDER_STATUS_LABEL: Record<OrderStatus, string> = {
  PENDING: 'Pendiente de pago',
  PAID: 'Pagada',
  CANCELLED: 'Cancelada',
  EXPIRED: 'Vencida',
};

export const ORDER_STATUS_TONE: Record<OrderStatus, Tone> = {
  PENDING: 'warning',
  PAID: 'success',
  CANCELLED: 'neutral',
  EXPIRED: 'danger',
};

export const PAYMENT_METHOD_LABEL: Record<PaymentMethod, string> = {
  TRANSFER: 'Transferencia o depósito',
  YAPE: 'Yape',
  CASH: 'Efectivo',
  OTHER: 'Otro',
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
  reference: string | null;
  notes: string | null;
  amount: string;
  paid_at: string;
  confirmed_by: string | null;
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
  // Venta a empresa cuyas licencias se entregaron antes del pago
  licenses_released_at: string | null;
  licenses_release_reason: string | null;
  items: OrderItem[];
  payment: OrderPayment | null;
}

// Lo que ve el comprador: mientras la orden espera su pago, cómo pagarla
export interface MyOrder extends Order {
  payment_instructions: { type: string; text: string | null; methods: PaymentMethod[] } | null;
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
  async catalog() {
    return (await api.get('/catalog/courses')).data.data as AvailableCourse[];
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
  async orders(params: { search?: string; status?: string; page?: number }) {
    const response = await api.get('/orders', { params });
    return { data: response.data.data as Order[], meta: response.data.meta as PageMeta };
  },
  async confirmPayment(order: string, input: ConfirmPaymentInput) {
    return (await postIdempotent(`/orders/${order}/confirm-payment`, input)).data.data as Order;
  },
  async releaseLicenses(order: string, reason: string) {
    return (await api.post(`/orders/${order}/release-licenses`, { reason })).data.data as Order;
  },
  async cancelOrder(order: string) {
    return (await api.post(`/orders/${order}/cancel`)).data.data as Order;
  },
};
