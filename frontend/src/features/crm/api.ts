import { api } from '../../shared/api/client';
import type { Tone } from '../../shared/ui';
import type { PageMeta } from '../identity/api';

export interface Person {
  id: string;
  name: string;
}

/* ───────────────────────────── Prospectos ───────────────────────────── */

export type LeadStatus = 'NEW' | 'CONTACTED' | 'CONVERTED' | 'DISCARDED';
export type LeadSource = 'WEB' | 'REFERRAL' | 'EVENT' | 'CALL' | 'SOCIAL' | 'OTHER';

export const LEAD_STATUS_LABEL: Record<LeadStatus, string> = {
  NEW: 'Nuevo',
  CONTACTED: 'Contactado',
  CONVERTED: 'Convertido',
  DISCARDED: 'Descartado',
};
export const LEAD_STATUS_TONE: Record<LeadStatus, Tone> = {
  NEW: 'info',
  CONTACTED: 'warning',
  CONVERTED: 'success',
  DISCARDED: 'neutral',
};

export const LEAD_SOURCE_LABEL: Record<LeadSource, string> = {
  WEB: 'Página web',
  REFERRAL: 'Recomendación',
  EVENT: 'Evento',
  CALL: 'Llamada',
  SOCIAL: 'Redes sociales',
  OTHER: 'Otro',
};

export interface Lead {
  id: string;
  first_name: string;
  last_name: string | null;
  name: string;
  company_name: string | null;
  position: string | null;
  email: string | null;
  phone: string | null;
  source: LeadSource;
  interest: string | null;
  status: LeadStatus;
  discard_reason: string | null;
  owner: Person | null;
  created_at: string;
  converted_at: string | null;
  // Dónde terminó el prospecto al convertirse
  company: Person | null;
  opportunity_id: string | null;
}

export interface LeadInput {
  first_name: string;
  last_name: string | null;
  company_name: string | null;
  position: string | null;
  email: string | null;
  phone: string | null;
  source: LeadSource;
  interest: string | null;
  owner_id?: string | null;
  status?: 'NEW' | 'CONTACTED';
}

export interface ConvertLeadInput {
  company_id?: string;
  company?: { name: string; tax_id: string | null };
  opportunity?: { title: string; estimated_amount: number | null };
}

/* ───────────────────────────── Empresas ───────────────────────────── */

export type CompanyStatus = 'PROSPECT' | 'CUSTOMER';

export const COMPANY_STATUS_LABEL: Record<CompanyStatus, string> = { PROSPECT: 'Prospecto', CUSTOMER: 'Cliente' };
export const COMPANY_STATUS_TONE: Record<CompanyStatus, Tone> = { PROSPECT: 'info', CUSTOMER: 'success' };

export interface Company {
  id: string;
  name: string;
  tax_id: string | null;
  industry: string | null;
  website: string | null;
  email: string | null;
  phone: string | null;
  address: string | null;
  city: string | null;
  notes: string | null;
  status: CompanyStatus;
  owner: Person | null;
  contacts_count: number;
  open_opportunities_count: number;
  created_at: string;
}

export interface Contact {
  id: string;
  company_id: string;
  first_name: string;
  last_name: string | null;
  name: string;
  position: string | null;
  email: string | null;
  phone: string | null;
  is_primary: boolean;
}

export interface CompanyDetail extends Company {
  contacts: Contact[];
  opportunities: Opportunity[];
}

export type CompanyInput = Pick<Company, 'name' | 'tax_id' | 'industry' | 'website' | 'email' | 'phone' | 'address' | 'city' | 'notes'>;
export type ContactInput = Pick<Contact, 'first_name' | 'last_name' | 'position' | 'email' | 'phone' | 'is_primary'>;

/* ───────────────────────────── Oportunidades ───────────────────────────── */

export type Stage = 'NEW' | 'CONTACTED' | 'PROPOSAL' | 'NEGOTIATION' | 'WON' | 'LOST';

export const STAGES: Stage[] = ['NEW', 'CONTACTED', 'PROPOSAL', 'NEGOTIATION', 'WON', 'LOST'];

export const STAGE_LABEL: Record<Stage, string> = {
  NEW: 'Nueva',
  CONTACTED: 'Contactada',
  PROPOSAL: 'Propuesta enviada',
  NEGOTIATION: 'Negociación',
  WON: 'Ganada',
  LOST: 'Perdida',
};
export const STAGE_TONE: Record<Stage, Tone> = {
  NEW: 'info',
  CONTACTED: 'brand',
  PROPOSAL: 'violet',
  NEGOTIATION: 'warning',
  WON: 'success',
  LOST: 'neutral',
};
// Franja de color que encabeza cada columna del tablero
export const STAGE_BAR: Record<Stage, string> = {
  NEW: 'from-sky-500 to-sky-400',
  CONTACTED: 'from-brand-700 to-brand-500',
  PROPOSAL: 'from-violet-600 to-fuchsia-500',
  NEGOTIATION: 'from-amber-500 to-orange-400',
  WON: 'from-emerald-600 to-teal-400',
  LOST: 'from-slate-400 to-slate-300',
};

export type ActivityType = 'CALL' | 'MEETING' | 'EMAIL' | 'NOTE' | 'STAGE';

export const ACTIVITY_TYPES: Exclude<ActivityType, 'STAGE'>[] = ['CALL', 'MEETING', 'EMAIL', 'NOTE'];
export const ACTIVITY_LABEL: Record<ActivityType, string> = {
  CALL: 'Llamada',
  MEETING: 'Reunión',
  EMAIL: 'Correo',
  NOTE: 'Nota',
  STAGE: 'Cambio de etapa',
};

export interface Opportunity {
  id: string;
  title: string;
  stage: Stage;
  company: Person;
  contact: Person | null;
  estimated_amount: string | null;
  expected_close_date: string | null;
  notes: string | null;
  lost_reason: string | null;
  owner: Person | null;
  created_at: string;
  closed_at: string | null;
}

export interface Activity {
  id: string;
  type: ActivityType;
  notes: string;
  occurred_at: string;
  author: string | null;
}

export interface OpportunityDetail extends Opportunity {
  activities: Activity[];
  // Moneda de la academia, para mostrar el monto estimado
  currency: string;
}

export interface OpportunityInput {
  company_id: string;
  contact_id: string | null;
  title: string;
  estimated_amount: number | null;
  expected_close_date: string | null;
  notes: string | null;
  owner_id?: string | null;
}

export interface BoardColumn {
  stage: Stage;
  label: string;
  count: number;
  amount: string;
  items: Opportunity[];
}

/* ───────────────────────────── Utilidades ───────────────────────────── */

export const blankToNull = (value: string): string | null => (value.trim() === '' ? null : value.trim());

export function formatDay(value: string | null): string {
  if (!value) return '—';
  // Las fechas sin hora (cierre previsto) no deben correrse por la zona horaria
  const date = value.length === 10 ? new Date(`${value}T12:00:00`) : new Date(value);
  return date.toLocaleDateString('es-PE', { day: 'numeric', month: 'short', year: 'numeric' });
}

export function formatMoment(value: string): string {
  return new Date(value).toLocaleString('es-PE', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
}

// El detalle de una oportunidad llega con la moneda de la academia
const opportunityDetail = (response: { data: { data: Omit<OpportunityDetail, 'currency'>; meta: { currency: string } } }): OpportunityDetail => ({
  ...response.data.data,
  currency: response.data.meta.currency,
});

export const crmApi = {
  async owners() {
    return (await api.get('/crm/owners')).data.data as Person[];
  },

  async leads(params: { search?: string; status?: string; page?: number }) {
    const response = await api.get('/crm/leads', { params });
    return { data: response.data.data as Lead[], meta: response.data.meta as PageMeta };
  },
  async createLead(input: LeadInput) {
    return (await api.post('/crm/leads', input)).data.data as Lead;
  },
  async updateLead(id: string, input: LeadInput) {
    return (await api.put(`/crm/leads/${id}`, input)).data.data as Lead;
  },
  async convertLead(id: string, input: ConvertLeadInput) {
    return (await api.post(`/crm/leads/${id}/convert`, input)).data.data as Lead;
  },
  async discardLead(id: string, reason: string) {
    return (await api.post(`/crm/leads/${id}/discard`, { reason })).data.data as Lead;
  },

  async companies(params: { search?: string; status?: string; page?: number; per_page?: number }) {
    const response = await api.get('/crm/companies', { params });
    return { data: response.data.data as Company[], meta: response.data.meta as PageMeta };
  },
  async company(id: string) {
    return (await api.get(`/crm/companies/${id}`)).data.data as CompanyDetail;
  },
  async createCompany(input: CompanyInput) {
    return (await api.post('/crm/companies', input)).data.data as CompanyDetail;
  },
  async updateCompany(id: string, input: CompanyInput) {
    return (await api.put(`/crm/companies/${id}`, input)).data.data as CompanyDetail;
  },
  async createContact(companyId: string, input: ContactInput) {
    return (await api.post(`/crm/companies/${companyId}/contacts`, input)).data.data as CompanyDetail;
  },
  async updateContact(id: string, input: ContactInput) {
    return (await api.put(`/crm/contacts/${id}`, input)).data.data as CompanyDetail;
  },
  async deleteContact(id: string) {
    return (await api.delete(`/crm/contacts/${id}`)).data.data as CompanyDetail;
  },

  async board(params: { search?: string; owner_id?: string }) {
    const response = await api.get('/crm/opportunities/board', { params });
    return { columns: response.data.data as BoardColumn[], currency: response.data.meta.currency as string };
  },
  async opportunity(id: string) {
    return opportunityDetail(await api.get(`/crm/opportunities/${id}`));
  },
  async createOpportunity(input: OpportunityInput) {
    return opportunityDetail(await api.post('/crm/opportunities', input));
  },
  async updateOpportunity(id: string, input: OpportunityInput) {
    return opportunityDetail(await api.put(`/crm/opportunities/${id}`, input));
  },
  async moveOpportunity(id: string, stage: Stage, lostReason?: string) {
    return opportunityDetail(await api.post(`/crm/opportunities/${id}/stage`, { stage, lost_reason: lostReason }));
  },
  async addActivity(id: string, input: { type: ActivityType; notes: string }) {
    return opportunityDetail(await api.post(`/crm/opportunities/${id}/activities`, input));
  },
};

/* ───────────────────────────── Cotizaciones ───────────────────────────── */

export type QuotationStatus = 'DRAFT' | 'SENT' | 'ACCEPTED' | 'REJECTED' | 'EXPIRED';

export const QUOTATION_STATUS_LABEL: Record<QuotationStatus, string> = {
  DRAFT: 'Borrador',
  SENT: 'Enviada',
  ACCEPTED: 'Aceptada',
  REJECTED: 'Rechazada',
  EXPIRED: 'Vencida',
};
export const QUOTATION_STATUS_TONE: Record<QuotationStatus, Tone> = {
  DRAFT: 'neutral',
  SENT: 'info',
  ACCEPTED: 'success',
  REJECTED: 'danger',
  EXPIRED: 'warning',
};

export interface QuotationItem {
  course_id: string;
  title: string;
  quantity: number;
  // Precio de lista del curso al cotizar (referencia) y precio pactado por licencia
  list_price: string | null;
  unit_price: string;
  total_amount: string;
}

export interface QuotationSummary {
  id: string;
  number: number;
  version: number;
  status: QuotationStatus;
  company: Person;
  currency: string;
  total_amount: string;
  valid_until: string;
  created_at: string;
  sent_at: string | null;
  accepted_at: string | null;
}

export interface Quotation extends QuotationSummary {
  contact: Person | null;
  opportunity: { id: string; title: string } | null;
  notes: string | null;
  items: QuotationItem[];
  created_by: string | null;
  accepted_by: string | null;
  // PORTAL = la aceptó el responsable de la empresa; STAFF = la registró el personal
  accepted_via: 'PORTAL' | 'STAFF' | null;
  acceptance_note: string | null;
  rejected_at: string | null;
  reject_reason: string | null;
  order: { id: string; number: number; status: 'PENDING' | 'PAID' | 'CANCELLED' | 'EXPIRED' } | null;
  versions: { version: number; total_amount: string; sent_at: string; valid_until: string; items: QuotationItem[] }[];
}

export interface QuotationInput {
  company_id?: string;
  opportunity_id?: string | null;
  contact_id: string | null;
  valid_until: string;
  notes: string | null;
  items: { course_id: string; quantity: number; unit_price: number }[];
}

export interface QuotationOptions {
  currency: string;
  validity_days: number;
  courses: { id: string; title: string; list_price: string | null }[];
}

export const quotationCode = (quotation: { number: number; version: number }) =>
  `COT-${String(quotation.number).padStart(4, '0')}${quotation.version > 1 ? ` v${quotation.version}` : ''}`;

export const quotationsApi = {
  async list(params: { search?: string; status?: string; page?: number }) {
    const response = await api.get('/quotations', { params });
    return { data: response.data.data as QuotationSummary[], meta: response.data.meta as PageMeta };
  },
  async options() {
    return (await api.get('/quotations/options')).data.data as QuotationOptions;
  },
  async get(id: string) {
    return (await api.get(`/quotations/${id}`)).data.data as Quotation;
  },
  async create(input: QuotationInput) {
    return (await api.post('/quotations', input)).data.data as Quotation;
  },
  async update(id: string, input: QuotationInput) {
    return (await api.put(`/quotations/${id}`, input)).data.data as Quotation;
  },
  async send(id: string) {
    return (await api.post(`/quotations/${id}/send`)).data.data as Quotation;
  },
  async revise(id: string) {
    return (await api.post(`/quotations/${id}/revise`)).data.data as Quotation;
  },
  async accept(id: string, note: string | null) {
    return (await api.post(`/quotations/${id}/accept`, { note })).data.data as Quotation;
  },
  async reject(id: string, reason: string) {
    return (await api.post(`/quotations/${id}/reject`, { reason })).data.data as Quotation;
  },
};
