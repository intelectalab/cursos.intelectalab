import { api } from '../../shared/api/client';
import type { Tone } from '../../shared/ui';
import type { OrderStatus } from '../sales/api';

export type DiscountType = 'PERCENT' | 'FIXED';
// Situación de hoy de una promoción
export type PromotionState = 'ACTIVE' | 'SCHEDULED' | 'EXPIRED' | 'EXHAUSTED' | 'INACTIVE';

export const PROMOTION_STATE_LABEL: Record<PromotionState, string> = {
  ACTIVE: 'Activa',
  SCHEDULED: 'Por empezar',
  EXPIRED: 'Vencida',
  EXHAUSTED: 'Agotada',
  INACTIVE: 'Desactivada',
};
export const PROMOTION_STATE_TONE: Record<PromotionState, Tone> = {
  ACTIVE: 'success',
  SCHEDULED: 'info',
  EXPIRED: 'neutral',
  EXHAUSTED: 'warning',
  INACTIVE: 'neutral',
};

export interface PromotionItemRef {
  type: 'COURSE' | 'PACKAGE';
  id: string;
}

export interface Coupon {
  id: string;
  code: string;
  label: string | null;
  status: 'ACTIVE' | 'INACTIVE';
  uses: number;
}

export interface Promotion {
  id: string;
  name: string;
  discount_type: DiscountType;
  discount_value: string;
  starts_at: string | null;
  ends_at: string | null;
  // Los mismos límites como días de la academia (AAAA-MM-DD), en su zona horaria
  starts_on: string | null;
  ends_on: string | null;
  max_redemptions: number | null;
  once_per_customer: boolean;
  min_purchase: string | null;
  applies_to: 'ALL' | 'SELECTED';
  status: 'ACTIVE' | 'INACTIVE';
  state: PromotionState;
  // Usos que cuentan: órdenes pagadas o esperando su pago
  uses: number;
  items: PromotionItemRef[];
  coupons: Coupon[];
}

export interface PromotionInput {
  name: string;
  discount_type: DiscountType;
  discount_value: number;
  starts_at: string | null;
  ends_at: string | null;
  max_redemptions: number | null;
  once_per_customer: boolean;
  min_purchase: number | null;
  applies_to: 'ALL' | 'SELECTED';
  status?: 'ACTIVE' | 'INACTIVE';
  items: PromotionItemRef[];
}

export interface Redemption {
  id: string;
  coupon_code: string;
  order: { id: string; number: number; status: OrderStatus };
  customer: string;
  currency: string;
  discount_amount: string;
  total_amount: string;
  created_at: string;
}

export interface Product {
  id: string;
  title: string;
  price: string;
}

export interface PromotionOptions {
  currency: string;
  courses: Product[];
  packages: Product[];
}

// Paquete visto por el personal
export interface Package {
  id: string;
  title: string;
  summary: string | null;
  price: string;
  currency: string;
  list_price: string;
  savings: string;
  status: 'ACTIVE' | 'INACTIVE';
  // Activo y con todos sus cursos todavía en venta
  for_sale: boolean;
  courses: { id: string; title: string; price: string | null; for_sale: boolean }[];
  path: string;
}

export interface PackageInput {
  title: string;
  summary: string | null;
  price: number;
  status: 'ACTIVE' | 'INACTIVE';
  course_ids: string[];
}

export const promotionsApi = {
  async list() {
    const response = await api.get('/promotions');
    return { data: response.data.data as Promotion[], currency: response.data.meta.currency as string };
  },
  async options() {
    return (await api.get('/promotions/options')).data.data as PromotionOptions;
  },
  async create(input: PromotionInput & { code: string; label: string | null }) {
    return (await api.post('/promotions', input)).data.data as Promotion;
  },
  async update(id: string, input: PromotionInput) {
    return (await api.put(`/promotions/${id}`, input)).data.data as Promotion;
  },
  async addCoupon(id: string, input: { code: string; label: string | null }) {
    return (await api.post(`/promotions/${id}/coupons`, input)).data.data as Promotion;
  },
  async setCouponStatus(couponId: string, status: 'ACTIVE' | 'INACTIVE') {
    return (await api.put(`/coupons/${couponId}`, { status })).data.data as Promotion;
  },
  async redemptions(id: string) {
    return (await api.get(`/promotions/${id}/redemptions`)).data.data as Redemption[];
  },

  async packages() {
    const response = await api.get('/packages');
    return { data: response.data.data as Package[], currency: response.data.meta.currency as string };
  },
  async createPackage(input: PackageInput) {
    return (await api.post('/packages', input)).data.data as Package;
  },
  async updatePackage(id: string, input: PackageInput) {
    return (await api.put(`/packages/${id}`, input)).data.data as Package;
  },
};
