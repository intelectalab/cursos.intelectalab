import { api } from '../../shared/api/client';
import { tenantUrl } from '../../shared/tenancy/hosts';

export interface Plan {
  id: string;
  name: string;
  description: string | null;
  price: string;
  currency: 'PEN' | 'USD';
  // Cada cuántos meses se paga: 1 = mensual, 12 = anual
  billing_months: 1 | 12;
  // null = sin tope de alumnos
  max_students: number | null;
  status: 'ACTIVE' | 'ARCHIVED';
  // Academias que lo tienen hoy (solo en el listado de planes)
  tenants_count?: number;
}

export type PlanInput = Omit<Plan, 'id' | 'tenants_count'>;

// Situación de una academia frente a la plataforma
export interface Subscription {
  // UNLIMITED: sin plan ni vencimiento (academias anteriores a los planes, o de cortesía)
  state: 'TRIAL' | 'TRIAL_EXPIRED' | 'ACTIVE' | 'EXPIRED' | 'UNLIMITED';
  // GRACE: vencida, opera con aviso; READ_ONLY: vencida y sin días de gracia
  access: 'FULL' | 'GRACE' | 'READ_ONLY';
  grace_ends_at: string | null;
  grace_days_left: number | null;
  plan: Plan | null;
  ends_at: string | null;
  days_left: number | null;
  max_students: number | null;
  // Personas con al menos una matrícula activa
  students: number;
  seats_left: number | null;
}

// Orden con la que una academia paga su plan, vista desde la consola
export interface PlatformPlanOrder {
  id: string;
  number: number;
  tenant: { id: string; name: string; slug: string };
  plan_name: string;
  billing_months: number;
  max_students: number | null;
  amount: string;
  currency: string;
  status: 'PENDING' | 'PAID' | 'CANCELLED' | 'EXPIRED';
  // Lo que la academia informó de su pago
  payer_reference: string | null;
  // Constancia que adjuntó
  proof: { name: string; size_bytes: number } | null;
  // La cobró una pasarela (no la confirmó una persona): no se deshace desde la consola
  paid_online: boolean;
  // La pasarela cobró algo que no se aplicó a la orden: hay que resolverlo a mano
  review_reason: string | null;
  // Confirmada a mano: se puede deshacer mientras el plan no haya cambiado después
  can_revert: boolean;
  // Cobrada en línea: se puede devolver el dinero por la pasarela
  can_refund: boolean;
  expires_at: string | null;
  payment_method: string | null;
  payment_reference: string | null;
  notes: string | null;
  resolved_by: string | null;
  created_at: string;
  paid_at: string | null;
  cancelled_at: string | null;
}

export interface TenantRow {
  id: string;
  name: string;
  slug: string;
  status: 'ACTIVE' | 'SUSPENDED';
  created_at: string | null;
  users_count: number;
  students_count: number;
  published_courses_count: number;
  sales_this_month: string;
  currency: string;
  last_login_at: string | null;
  subscription: Subscription | null;
}

export interface TenantAdmin {
  id: string;
  name: string;
  email: string;
  status: 'ACTIVE' | 'INACTIVE';
  last_login_at: string | null;
  must_change_password: boolean;
}

// Lo que la plataforma hizo sobre la academia; el detalle depende de la acción
export interface TenantHistoryEvent {
  id: string;
  action:
    | 'platform.support_session_started'
    | 'tenants.status_changed'
    | 'platform.tenant_updated'
    | 'platform.admin_password_reset'
    | 'platform.subscription_changed';
  before: Record<string, string | number | null> | null;
  after: Record<string, string | number | null> | null;
  created_at: string;
}

export interface TenantData {
  name: string;
  legal_name: string | null;
  tax_id: string | null;
  contact_email: string | null;
  contact_phone: string | null;
  contact_address: string | null;
}

// Ficha de una academia en la consola de plataforma
export interface TenantProfile extends TenantRow, TenantData {
  timezone: string;
  // Órdenes pagadas de los últimos seis meses, del más antiguo al actual
  monthly_sales: { month: string; total: string; orders: number }[];
  enrollments_this_month: number;
  certificates_issued: number;
  storage_bytes: number;
  admins: TenantAdmin[];
  history: TenantHistoryEvent[];
}

export const platformApi = {
  async plans() {
    const response = await api.get('/platform/plans');
    return {
      plans: response.data.data as Plan[],
      trialDays: response.data.meta.trial_days as number,
      trialMaxStudents: response.data.meta.trial_max_students as number,
    };
  },
  async planOrders(status: string | undefined, page: number) {
    const response = await api.get('/platform/plan-orders', { params: { status, page } });
    return {
      data: response.data.data as PlatformPlanOrder[],
      meta: response.data.meta as { page: number; last_page: number; total: number; counts: Record<string, number>; methods: string[] },
    };
  },
  confirmPlanOrder: (id: string, method: string, reference: string | null, notes: string | null) =>
    api.post(`/platform/plan-orders/${id}/confirm`, { method, reference, notes }),
  cancelPlanOrder: (id: string, reason: string) => api.post(`/platform/plan-orders/${id}/cancel`, { reason }),
  // Deshace una confirmación hecha por error: el plan vuelve a como estaba y la orden, a pendiente
  revertPlanOrder: (id: string, reason: string) => api.post(`/platform/plan-orders/${id}/revert`, { reason }),
  // Devuelve por la pasarela un pago de plan cobrado en línea: el plan vuelve a como estaba y la orden queda cancelada
  refundPlanOrder: (id: string, reason: string) => api.post(`/platform/plan-orders/${id}/refund`, { reason }),
  // Abre en otra pestaña la constancia que adjuntó la academia
  async openPlanOrderProof(id: string) {
    const response = await api.get(`/platform/plan-orders/${id}/proof`, { responseType: 'blob' });
    window.open(URL.createObjectURL(response.data), '_blank', 'noopener');
  },
  // Lo que espera a la plataforma: órdenes pendientes, con pago informado y cobros por revisar
  async planOrdersSummary() {
    return (await api.get('/platform/plan-orders/summary')).data.data as { pending: number; informed: number; review: number };
  },
  // Cómo deben pagarle las academias a la plataforma
  async paymentInstructions() {
    return ((await api.get('/platform/billing-settings')).data.data.payment_instructions as string | null) ?? '';
  },
  savePaymentInstructions: (text: string) => api.put('/platform/billing-settings', { payment_instructions: text.trim() || null }),
  createPlan: (input: PlanInput) => api.post('/platform/plans', input),
  updatePlan: (id: string, input: PlanInput) => api.put(`/platform/plans/${id}`, input),
  // planId null = dejar a la academia sin límites; endsAt vacío = un periodo del plan desde hoy
  assignPlan: (tenant: string, planId: string | null, endsAt: string | null) =>
    api.put(`/platform/tenants/${tenant}/subscription`, { plan_id: planId, ends_at: endsAt }),
  extendTrial: (tenant: string, until: string) => api.put(`/platform/tenants/${tenant}/subscription/trial`, { trial_ends_at: until }),
  async profile(id: string) {
    return (await api.get(`/platform/tenants/${id}`)).data.data as TenantProfile;
  },
  async update(id: string, data: TenantData) {
    return (await api.put(`/platform/tenants/${id}`, data)).data.data as TenantProfile;
  },
  // Suspender exige un motivo; reactivar, no
  setStatus: (id: string, status: TenantRow['status'], reason?: string) => api.patch(`/platform/tenants/${id}/status`, { status, reason }),
  resetAdminPassword: (id: string, admin: string, password: string) =>
    api.post(`/platform/tenants/${id}/admins/${admin}/reset-password`, { password }),
  // Abre la academia en otra pestaña, en solo lectura. Devuelve con qué usuario se entró.
  async openSupport(tenant: { id: string }, centralDomain: string) {
    const response = await api.post(`/platform/tenants/${tenant.id}/support-session`);
    const { token, slug, as_user: asUser } = response.data.data as { token: string; slug: string; as_user: string };
    // El acceso viaja en el fragmento de la dirección: no llega a ningún servidor ni queda en sus registros
    window.open(`${tenantUrl(slug, centralDomain, '/support-access')}#token=${encodeURIComponent(token)}`, '_blank', 'noopener');
    return asUser;
  },
};
