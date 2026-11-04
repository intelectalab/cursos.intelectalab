import { api } from '../../shared/api/client';
import type { PageMeta } from '../identity/api';

// Lo que pide el comprador al comprar: boleta (con o sin documento) o factura (RUC, razón social, dirección)
export interface BillingInput {
  type: 'BOLETA' | 'FACTURA';
  doc_type?: string | null;
  doc_number?: string | null;
  ruc?: string | null;
  name?: string | null;
  address?: string | null;
}

/** SUNAT pide el documento en boletas desde este importe en soles */
export const BOLETA_ID_THRESHOLD = 700;

/** Lo que se manda: sin campos vacíos (el servidor completa la boleta con la cuenta) */
export function billingPayload(value: BillingInput): BillingInput {
  if (value.type === 'FACTURA') return { type: 'FACTURA', ruc: value.ruc?.trim(), name: value.name?.trim(), address: value.address?.trim() };
  const docNumber = value.doc_number?.trim();
  return { type: 'BOLETA', name: value.name?.trim() || null, doc_type: docNumber ? (value.doc_type ?? 'DNI') : null, doc_number: docNumber || null };
}

// Lo guardado en la orden
export interface Billing {
  type: 'BOLETA' | 'FACTURA';
  doc_type: string | null;
  doc_number: string | null;
  name: string;
  address: string | null;
}

export type InvoiceStatus = 'PENDING' | 'PROCESSING' | 'ACCEPTED' | 'OBSERVED' | 'REJECTED' | 'ERROR' | 'VOIDING' | 'VOIDED' | 'CANCELLED';

export type InvoiceType = 'BOLETA' | 'FACTURA' | 'NOTA_CREDITO';

export interface Invoice {
  id: string;
  code: string;
  type: InvoiceType;
  status: InvoiceStatus;
  issue_date: string;
  currency: string;
  total: string;
  igv: string;
  customer_name: string;
  customer_doc_number: string;
  description: string;
  // En una nota de crédito: el comprobante que corrige y el motivo
  reference_code: string | null;
  reason: string | null;
  // Ya tiene una nota de crédito que lo corrige
  credited: boolean;
  has_pdf: boolean;
  // Solo el personal
  order_id?: string;
  order_number?: number | null;
  message?: string | null;
  attempts?: number;
  can_retry?: boolean;
  // Dentro del plazo de SUNAT se anula; después, se corrige con una nota de crédito
  can_void?: boolean;
  can_credit?: boolean;
  void_deadline?: string | null;
}

export const INVOICE_TYPE: Record<InvoiceType, string> = {
  BOLETA: 'Boleta',
  FACTURA: 'Factura',
  NOTA_CREDITO: 'Nota de crédito',
};

export const INVOICE_STATUS: Record<InvoiceStatus, { label: string; tone: 'neutral' | 'info' | 'success' | 'warning' | 'danger' }> = {
  PENDING: { label: 'Por enviar', tone: 'neutral' },
  PROCESSING: { label: 'En SUNAT', tone: 'info' },
  ACCEPTED: { label: 'Aceptado', tone: 'success' },
  OBSERVED: { label: 'Aceptado con observaciones', tone: 'warning' },
  REJECTED: { label: 'Rechazado', tone: 'danger' },
  ERROR: { label: 'No emitido', tone: 'danger' },
  VOIDING: { label: 'Anulación pedida', tone: 'warning' },
  VOIDED: { label: 'Anulado', tone: 'neutral' },
  CANCELLED: { label: 'Sin emitir (pago devuelto)', tone: 'neutral' },
};

// El PDF lo da el servidor con la sesión: se abre en una pestaña nueva
async function openPdf(path: string): Promise<void> {
  const tab = window.open('', '_blank');
  const response = await api.get(path, { responseType: 'blob' });
  const url = URL.createObjectURL(response.data as Blob);
  if (tab) tab.location.href = url;
  else window.location.assign(url);
  window.setTimeout(() => URL.revokeObjectURL(url), 60_000);
}

export const invoicingApi = {
  async enabled() {
    return Boolean((await api.get('/invoicing/status')).data.data.enabled);
  },
  async orderInvoices(order: string) {
    const response = await api.get(`/orders/${order}/invoices`);
    return { invoices: response.data.data as Invoice[], billing: (response.data.meta?.billing ?? null) as Billing | null };
  },
  async myInvoices(order: string) {
    return (await api.get(`/me/orders/${order}/invoices`)).data.data as Invoice[];
  },
  // Todos los comprobantes de la academia, con filtros
  async all(params: Record<string, string | number | undefined>) {
    const response = await api.get('/invoices', { params });
    return { data: response.data.data as Invoice[], meta: response.data.meta as PageMeta };
  },
  async retry(invoice: string) {
    return (await api.post(`/invoices/${invoice}/retry`)).data.data as Invoice;
  },
  async void(invoice: string, reason: string) {
    return (await api.post(`/invoices/${invoice}/void`, { reason })).data.data as Invoice;
  },
  async creditNote(invoice: string, reason: string) {
    return (await api.post(`/invoices/${invoice}/credit-note`, { reason })).data.data as Invoice;
  },
  async updateBilling(order: string, billing: BillingInput) {
    return (await api.put(`/orders/${order}/billing`, { billing })).data.data.billing as Billing;
  },
  openPdf: (invoice: string) => openPdf(`/invoices/${invoice}/pdf`),
  openMyPdf: (invoice: string) => openPdf(`/me/invoices/${invoice}/pdf`),
};
