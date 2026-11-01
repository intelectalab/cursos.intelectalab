import { api } from '../../shared/api/client';

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

export type InvoiceStatus = 'PENDING' | 'PROCESSING' | 'ACCEPTED' | 'OBSERVED' | 'REJECTED' | 'ERROR';

export interface Invoice {
  id: string;
  code: string;
  type: 'BOLETA' | 'FACTURA';
  status: InvoiceStatus;
  issue_date: string;
  currency: string;
  total: string;
  igv: string;
  customer_name: string;
  customer_doc_number: string;
  description: string;
  has_pdf: boolean;
  // Solo el personal
  message?: string | null;
  attempts?: number;
  can_retry?: boolean;
}

export const INVOICE_STATUS: Record<InvoiceStatus, { label: string; tone: 'neutral' | 'info' | 'success' | 'warning' | 'danger' }> = {
  PENDING: { label: 'Por enviar', tone: 'neutral' },
  PROCESSING: { label: 'En SUNAT', tone: 'info' },
  ACCEPTED: { label: 'Aceptado', tone: 'success' },
  OBSERVED: { label: 'Aceptado con observaciones', tone: 'warning' },
  REJECTED: { label: 'Rechazado', tone: 'danger' },
  ERROR: { label: 'No emitido', tone: 'danger' },
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
  async retry(invoice: string) {
    return (await api.post(`/invoices/${invoice}/retry`)).data.data as Invoice;
  },
  async updateBilling(order: string, billing: BillingInput) {
    return (await api.put(`/orders/${order}/billing`, { billing })).data.data.billing as Billing;
  },
  openPdf: (invoice: string) => openPdf(`/invoices/${invoice}/pdf`),
  openMyPdf: (invoice: string) => openPdf(`/me/invoices/${invoice}/pdf`),
};
