import { api } from '../../shared/api/client';

// Documento estructurado de una página: árbol de nodos, no HTML
export interface PageNode {
  type: string;
  text?: string;
  attrs?: Record<string, unknown>;
  marks?: { type: string; attrs?: Record<string, unknown> }[];
  content?: PageNode[];
}

export interface PageSummary {
  id: string;
  slug: string;
  title: string;
  status: 'DRAFT' | 'PUBLISHED';
  // El borrador tiene cambios que el público todavía no ve
  pending_changes: boolean;
  show_in_footer: boolean;
  published_at: string | null;
  updated_at: string;
  updated_by: string | null;
  // Dirección pública, relativa al subdominio de la academia
  path: string;
}

export interface Page extends PageSummary {
  content: PageNode;
  versions: { version: number; title: string; published_at: string; published_by: string | null; current: boolean }[];
}

export interface PublishedPage {
  slug: string;
  title: string;
  content: PageNode;
  published_at: string;
}

export interface ImageRules {
  max_kb: number;
  extensions: string[];
}

// "Términos y condiciones" → "terminos-y-condiciones"
export function slugify(title: string): string {
  return title
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 80);
}

export const cmsApi = {
  async list() {
    const response = await api.get('/pages');
    return { data: response.data.data as PageSummary[], image: response.data.meta.image as ImageRules };
  },
  async get(id: string) {
    return (await api.get(`/pages/${id}`)).data.data as Page;
  },
  async create(input: { title: string; slug: string }) {
    return (await api.post('/pages', input)).data.data as Page;
  },
  async save(id: string, input: { title: string; slug: string; content: PageNode; show_in_footer: boolean }) {
    return (await api.put(`/pages/${id}`, input)).data.data as Page;
  },
  async publish(id: string) {
    return (await api.post(`/pages/${id}/publish`)).data.data as Page;
  },
  async unpublish(id: string) {
    return (await api.post(`/pages/${id}/unpublish`)).data.data as Page;
  },
  async remove(id: string) {
    await api.delete(`/pages/${id}`);
  },
  async uploadImage(file: File) {
    const body = new FormData();
    body.append('file', file);
    return (await api.post('/pages/media', body)).data.data as { url: string; name: string };
  },

  // Sitio público: sin sesión
  async footerPages() {
    return (await api.get('/site/pages')).data.data as { slug: string; title: string }[];
  },
  async published(slug: string) {
    return (await api.get(`/site/pages/${encodeURIComponent(slug)}`)).data.data as PublishedPage;
  },
};
