import { api } from '../../shared/api/client';
import type { Tone } from '../../shared/ui';
import type { PageMeta } from '../identity/api';

export type TicketStatus = 'OPEN' | 'IN_PROGRESS' | 'RESOLVED' | 'CLOSED';
export type TicketCategory = 'ACCESS' | 'COURSE' | 'PAYMENT' | 'CERTIFICATE' | 'OTHER';
export type TicketPriority = 'LOW' | 'NORMAL' | 'HIGH';

export const TICKET_STATUSES: TicketStatus[] = ['OPEN', 'IN_PROGRESS', 'RESOLVED', 'CLOSED'];
export const TICKET_STATUS_LABEL: Record<TicketStatus, string> = {
  OPEN: 'Abierto',
  IN_PROGRESS: 'En atención',
  RESOLVED: 'Resuelto',
  CLOSED: 'Cerrado',
};
export const TICKET_STATUS_TONE: Record<TicketStatus, Tone> = {
  OPEN: 'info',
  IN_PROGRESS: 'warning',
  RESOLVED: 'success',
  CLOSED: 'neutral',
};

export const TICKET_CATEGORIES: TicketCategory[] = ['ACCESS', 'COURSE', 'PAYMENT', 'CERTIFICATE', 'OTHER'];
export const TICKET_CATEGORY_LABEL: Record<TicketCategory, string> = {
  ACCESS: 'Acceso a mi cuenta',
  COURSE: 'Un curso o su contenido',
  PAYMENT: 'Un pago o una compra',
  CERTIFICATE: 'Un certificado',
  OTHER: 'Otro tema',
};

export const TICKET_PRIORITIES: TicketPriority[] = ['LOW', 'NORMAL', 'HIGH'];
export const TICKET_PRIORITY_LABEL: Record<TicketPriority, string> = { LOW: 'Baja', NORMAL: 'Normal', HIGH: 'Alta' };

export interface TicketAttachment {
  id: string;
  name: string;
  size_bytes: number;
}

export interface TicketMessage {
  id: string;
  author: string | null;
  is_staff: boolean;
  // Nota interna: solo la ve el personal
  is_internal: boolean;
  body: string;
  created_at: string;
  attachments: TicketAttachment[];
}

export interface TicketSummary {
  id: string;
  number: number;
  subject: string;
  category: TicketCategory;
  priority: TicketPriority;
  status: TicketStatus;
  requester: { id: string; name: string | null; email: string | null };
  assignee: { id: string; name: string } | null;
  // Le toca responder al personal
  awaits_staff: boolean;
  created_at: string;
  last_message_at: string;
  resolved_at: string | null;
  closed_at: string | null;
}

export interface Ticket extends TicketSummary {
  course: { id: string; title: string } | null;
  order: { id: string; number: number } | null;
  messages: TicketMessage[];
}

export interface TicketOptions {
  categories: TicketCategory[];
  attachments: { max_files: number; max_kb: number; extensions: string[] };
  courses: { id: string; title: string }[];
  orders: { id: string; number: number }[];
}

export interface InboxCounts {
  awaiting: number;
  unassigned: number;
  mine: number;
}

export const ticketCode = (number: number) => `#${String(number).padStart(4, '0')}`;

export function formatSize(bytes: number): string {
  return bytes >= 1024 * 1024 ? `${(bytes / 1024 / 1024).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

// Un mensaje viaja como formulario para poder llevar adjuntos
function messageForm(fields: Record<string, string | null | undefined>, files: File[]): FormData {
  const body = new FormData();
  Object.entries(fields).forEach(([key, value]) => {
    if (value) body.append(key, value);
  });
  files.forEach((file) => body.append('files[]', file));
  return body;
}

export const supportApi = {
  // Quien consulta
  async mine() {
    return (await api.get('/me/tickets')).data.data as TicketSummary[];
  },
  async options() {
    return (await api.get('/me/tickets/options')).data.data as TicketOptions;
  },
  async open(input: { subject: string; category: TicketCategory; body: string; course_id?: string; order_id?: string }, files: File[]) {
    return (await api.post('/tickets', messageForm(input, files))).data.data as Ticket;
  },
  async myTicket(id: string) {
    return (await api.get(`/me/tickets/${id}`)).data.data as Ticket;
  },
  async replyMine(id: string, body: string, files: File[]) {
    return (await api.post(`/me/tickets/${id}/messages`, messageForm({ body }, files))).data.data as Ticket;
  },
  async closeMine(id: string) {
    return (await api.post(`/me/tickets/${id}/close`)).data.data as Ticket;
  },

  // Personal de soporte
  async inbox(params: { search?: string; status?: string; category?: string; assignee?: string; page?: number }) {
    const response = await api.get('/tickets', { params });
    return {
      data: response.data.data as TicketSummary[],
      meta: response.data.meta as PageMeta,
      counts: response.data.meta.counts as InboxCounts,
      // Cuántos hay en cada tarjeta con la búsqueda actual (ALL = todos)
      states: (response.data.meta.states ?? {}) as Record<string, number>,
    };
  },
  async agents() {
    return (await api.get('/tickets/agents')).data.data as { id: string; name: string }[];
  },
  async ticket(id: string) {
    return (await api.get(`/tickets/${id}`)).data.data as Ticket;
  },
  async reply(id: string, body: string, files: File[], internal: boolean) {
    return (await api.post(`/tickets/${id}/messages`, messageForm({ body, is_internal: internal ? '1' : null }, files))).data.data as Ticket;
  },
  async update(id: string, input: { assignee_id?: string | null; priority?: TicketPriority; category?: TicketCategory }) {
    return (await api.put(`/tickets/${id}`, input)).data.data as Ticket;
  },
  async changeStatus(id: string, status: TicketStatus) {
    return (await api.post(`/tickets/${id}/status`, { status })).data.data as Ticket;
  },

  // El adjunto se pide con la sesión y se entrega al navegador como descarga
  async download(attachment: TicketAttachment) {
    const response = await api.get(`/tickets/attachments/${attachment.id}`, { responseType: 'blob' });
    const url = URL.createObjectURL(response.data as Blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = attachment.name;
    link.click();
    URL.revokeObjectURL(url);
  },
};
