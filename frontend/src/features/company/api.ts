import { api } from '../../shared/api/client';
import type { Quotation, QuotationSummary } from '../crm/api';
import type { OrderStatus } from '../sales/api';

// Licencia asignada a un trabajador, con su avance en el curso
export interface Assignment {
  id: string;
  license_id: string;
  student: { id: string; name: string; email: string | null };
  assigned_at: string;
  // Avance general del curso (0 a 100); las notas no se muestran a la empresa
  progress: number;
  completed_at: string | null;
  access_until: string | null;
  enrollment_status: string | null;
  certificate_code: string | null;
  // Se puede retirar mientras el trabajador no haya empezado
  can_revoke: boolean;
}

// Licencias de la empresa en un curso
export interface CoursePool {
  course: { id: string; title: string; access_days: number | null };
  total: number;
  assigned: number;
  available: number;
  expired: number;
  // Fecha límite más próxima para asignar lo que queda
  assign_before: string | null;
  assignments: Assignment[];
}

export interface Member {
  id: string;
  name: string;
  email: string;
  is_manager: boolean;
  active: boolean;
  licenses: number;
}

export interface AccountOrder {
  id: string;
  number: number;
  status: OrderStatus;
  currency: string;
  total_amount: string;
  created_at: string;
  paid_at: string | null;
  licenses_released_at: string | null;
}

export interface Account {
  company: { id: string; name: string; tax_id: string | null };
  courses: CoursePool[];
  members: Member[];
  orders: AccountOrder[];
  pending_quotations: number;
}

export interface MemberInput {
  first_name: string;
  last_name: string;
  email: string;
  password: string | null;
  is_manager?: boolean;
}

// Lo que el responsable ve de una cotización: sin el historial interno de la academia
export type PortalQuotation = Omit<Quotation, 'versions' | 'created_by' | 'opportunity' | 'acceptance_note'>;

// La misma cuenta se consulta desde el portal del responsable ('/company')
// o, por el personal, eligiendo la empresa ('/corporate-accounts/{id}')
export const accountApi = (base: string) => ({
  async get() {
    return (await api.get(base)).data.data as Account;
  },
  async addMember(input: MemberInput) {
    return (await api.post(`${base}/members`, input)).data.data as Account;
  },
  async assign(courseId: string, studentIds: string[]) {
    return (await api.post(`${base}/courses/${courseId}/assign`, { student_ids: studentIds })).data.data as Account;
  },
  async revoke(assignmentId: string) {
    return (await api.post(`${base}/assignments/${assignmentId}/revoke`)).data.data as Account;
  },
});

export const portalApi = {
  async quotations() {
    return (await api.get('/company/quotations')).data.data as QuotationSummary[];
  },
  async quotation(id: string) {
    return (await api.get(`/company/quotations/${id}`)).data.data as PortalQuotation;
  },
  async accept(id: string) {
    return (await api.post(`/company/quotations/${id}/accept`)).data.data as PortalQuotation;
  },
  async reject(id: string, reason: string) {
    return (await api.post(`/company/quotations/${id}/reject`, { reason })).data.data as PortalQuotation;
  },
};
