import { api } from '../../shared/api/client';
import type { Tone } from '../../shared/ui';
import type { PageMeta } from '../identity/api';

export type ClaimType = 'RECLAMO' | 'QUEJA';
export type ClaimStatus = 'RECEIVED' | 'IN_REVIEW' | 'ANSWERED';
export type ClaimDocType = 'DNI' | 'CE' | 'PASAPORTE' | 'RUC';

export const CLAIM_TYPE_LABEL: Record<ClaimType, string> = { RECLAMO: 'Reclamo', QUEJA: 'Queja' };
export const CLAIM_TYPE_HINT: Record<ClaimType, string> = {
  RECLAMO: 'Disconformidad con el producto o servicio contratado.',
  QUEJA: 'Malestar con la atención recibida, sin relación con el producto o servicio.',
};

export const CLAIM_STATUS_LABEL: Record<ClaimStatus, string> = { RECEIVED: 'Registrado', IN_REVIEW: 'En revisión', ANSWERED: 'Respondido' };
export const CLAIM_STATUS_TONE: Record<ClaimStatus, Tone> = { RECEIVED: 'info', IN_REVIEW: 'warning', ANSWERED: 'success' };

export const CLAIM_DOC_LABEL: Record<ClaimDocType, string> = { DNI: 'DNI', CE: 'Carné de extranjería', PASAPORTE: 'Pasaporte', RUC: 'RUC' };

export interface ClaimProvider {
  name: string;
  legal_name: string | null;
  tax_id: string | null;
  address: string | null;
}

export interface ClaimSummary {
  id: string;
  code: string;
  type: ClaimType;
  status: ClaimStatus;
  consumer: { name: string; doc_type: ClaimDocType; doc_number: string };
  good_description: string;
  created_at: string;
  due_at: string;
  answered_at: string | null;
  // Sin respuesta y fuera de plazo
  overdue: boolean;
}

// La hoja completa. El consumidor la ve sin notas internas ni nombres del personal.
export interface Claim extends Omit<ClaimSummary, 'consumer'> {
  consumer: {
    name: string;
    doc_type: ClaimDocType;
    doc_number: string;
    address: string;
    email: string;
    phone: string | null;
    is_minor: boolean;
    guardian_name: string | null;
    guardian_doc_number: string | null;
  };
  good: { type: 'PRODUCT' | 'SERVICE'; description: string; amount: string | null; currency: string | null; order_reference: string | null };
  detail: string;
  request: string;
  provider: ClaimProvider;
  history: { status: ClaimStatus; at: string; by?: string | null }[];
  response: { body: string; at: string; by?: string | null } | null;
  notes?: { id: string; body: string; at: string; by: string | null }[];
}

export interface ClaimFormInfo {
  provider: ClaimProvider;
  response_days: number;
  currency: string;
  doc_types: ClaimDocType[];
}

export interface ClaimInput {
  type: ClaimType;
  first_name: string;
  last_name: string;
  doc_type: ClaimDocType;
  doc_number: string;
  address: string;
  email: string;
  phone: string | null;
  is_minor: boolean;
  guardian_name: string | null;
  guardian_doc_number: string | null;
  good_type: 'PRODUCT' | 'SERVICE';
  good_description: string;
  amount: number | null;
  order_reference: string | null;
  detail: string;
  request: string;
  accepted: boolean;
}

export const claimsApi = {
  // Público: sin sesión
  async form() {
    return (await api.get('/claims/form')).data.data as ClaimFormInfo;
  },
  async submit(input: ClaimInput) {
    return (await api.post('/claims', input)).data.data as Claim;
  },
  // El documento viaja en el cuerpo, no en la dirección
  async lookup(code: string, docNumber: string) {
    return (await api.post('/claims/lookup', { code, doc_number: docNumber })).data.data as Claim;
  },

  // Personal
  async list(params: { search?: string; status?: string; type?: string; page?: number }) {
    const response = await api.get('/claims', { params });
    return {
      data: response.data.data as ClaimSummary[],
      meta: response.data.meta as PageMeta,
      counts: response.data.meta.counts as { pending: number; overdue: number },
      // Cuántas hay en cada tarjeta con la búsqueda actual (ALL = todas)
      states: (response.data.meta.states ?? {}) as Record<string, number>,
    };
  },
  async get(id: string) {
    return (await api.get(`/claims/${id}`)).data.data as Claim;
  },
  async review(id: string) {
    return (await api.post(`/claims/${id}/review`)).data.data as Claim;
  },
  async respond(id: string, body: string) {
    return (await api.post(`/claims/${id}/respond`, { body })).data.data as Claim;
  },
  async addNote(id: string, body: string) {
    return (await api.post(`/claims/${id}/notes`, { body })).data.data as Claim;
  },
};
