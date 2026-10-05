import { api } from '../../shared/api/client';
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
  customer: { id: string; name: string; email: string };
  currency: string;
  subtotal_amount: string;
  discount_amount: string;
  total_amount: string;
  created_at: string;
  expires_at: string | null;
  paid_at: string | null;
  cancelled_at: string | null;
  items: OrderItem[];
  payment: OrderPayment | null;
}

// Lo que ve el comprador: mientras la orden espera su pago, cómo pagarla
export interface MyOrder extends Order {
  payment_instructions: { type: string; text: string | null; methods: PaymentMethod[] } | null;
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
  async placeOrder(courseId: string, expectedTotal: string) {
    return (await api.post('/orders', { course_id: courseId, expected_total: expectedTotal })).data.data as MyOrder;
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
    return (await api.post(`/orders/${order}/confirm-payment`, input)).data.data as Order;
  },
  async cancelOrder(order: string) {
    return (await api.post(`/orders/${order}/cancel`)).data.data as Order;
  },
};
