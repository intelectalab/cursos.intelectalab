import { api } from '../../shared/api/client';
import type { PageMeta } from '../identity/api';
import type { Tone } from '../../shared/ui';

export type CourseStatus = 'DRAFT' | 'PUBLISHED' | 'ARCHIVED';
export type CourseLevel = 'BASIC' | 'INTERMEDIATE' | 'ADVANCED';
// El video es un enlace a YouTube; no se sube al sistema
export type ResourceType = 'VIDEO' | 'DOCUMENT' | 'LINK' | 'TEXT';

export const STATUS_LABEL: Record<CourseStatus, string> = {
  DRAFT: 'Borrador',
  PUBLISHED: 'Publicado',
  ARCHIVED: 'Archivado',
};

export const STATUS_TONE: Record<CourseStatus, Tone> = {
  DRAFT: 'warning',
  PUBLISHED: 'success',
  ARCHIVED: 'neutral',
};

export const LEVEL_LABEL: Record<CourseLevel, string> = {
  BASIC: 'Básico',
  INTERMEDIATE: 'Intermedio',
  ADVANCED: 'Avanzado',
};

export const LANGUAGE_LABEL: Record<string, string> = { es: 'Español', en: 'Inglés', pt: 'Portugués' };

export const RESOURCE_LABEL: Record<ResourceType, string> = {
  VIDEO: 'Video',
  DOCUMENT: 'Documento',
  LINK: 'Enlace',
  TEXT: 'Lección de texto',
};

export interface Teacher {
  id: string;
  name: string;
  email: string;
}

export interface CourseSummary {
  id: string;
  title: string;
  summary: string | null;
  category: string | null;
  level: CourseLevel | null;
  duration_hours: number | null;
  // Días de acceso de cada matrícula nueva; null = no vence
  access_days: number | null;
  language: string;
  status: CourseStatus;
  cover_url: string | null;
  version: number | null;
  pending_changes: boolean;
  published_at: string | null;
  updated_at: string | null;
  sections_count: number;
  resources_count: number;
  teachers: Teacher[];
}

export interface CourseResource {
  id: string;
  type: ResourceType;
  title: string;
  url: string | null;
  // Identificador de YouTube, solo en los videos
  video_id: string | null;
  body: string | null;
  file: { name: string; mime_type: string; size_bytes: number } | null;
}

export interface CourseSection {
  id: string;
  title: string;
  resources: CourseResource[];
}

export interface CourseVersion {
  id: string;
  number: number;
  note: string | null;
  published_at: string;
  published_by: string | null;
  sections_count: number;
  resources_count: number;
}

export interface CourseDetail extends CourseSummary {
  description: string | null;
  sections: CourseSection[];
  versions: CourseVersion[];
}

export interface FileRules {
  max_kb: number;
  extensions: string[];
}

export interface CourseOptions {
  levels: CourseLevel[];
  languages: string[];
  categories: string[];
  resource_types: ResourceType[];
  cover: FileRules;
  material: FileRules;
  // Solo para quien puede asignar profesores
  teachers?: Teacher[];
}

export interface CourseInput {
  title: string;
  summary: string | null;
  description: string | null;
  category: string | null;
  level: CourseLevel | null;
  duration_hours: number | null;
  access_days: number | null;
  language: string;
}

export const EMPTY_COURSE: CourseInput = {
  title: '',
  summary: null,
  description: null,
  category: null,
  level: null,
  duration_hours: null,
  access_days: null,
  language: 'es',
};

export interface ResourceInput {
  type: ResourceType;
  title: string;
  url?: string;
  body?: string;
  file?: File | null;
}

function resourceBody(input: Partial<ResourceInput>): FormData {
  const body = new FormData();
  if (input.type) body.append('type', input.type);
  body.append('title', input.title ?? '');
  if (input.url !== undefined) body.append('url', input.url);
  if (input.body !== undefined) body.append('body', input.body);
  if (input.file) body.append('file', input.file);
  return body;
}

export const coursesApi = {
  async list(params: { search?: string; status?: string; category?: string; page?: number }) {
    const response = await api.get('/courses', { params });
    return { data: response.data.data as CourseSummary[], meta: response.data.meta as PageMeta };
  },
  async options() {
    return (await api.get('/courses/options')).data.data as CourseOptions;
  },
  async get(id: string) {
    const response = await api.get(`/courses/${id}`);
    return {
      course: response.data.data as CourseDetail,
      canEditContent: response.data.meta.can_edit_content as boolean,
      canManageActivities: response.data.meta.can_manage_activities as boolean,
      canReview: response.data.meta.can_review as boolean,
    };
  },
  async create(input: CourseInput) {
    return (await api.post('/courses', input)).data.data as CourseDetail;
  },
  async update(id: string, input: CourseInput) {
    return (await api.put(`/courses/${id}`, input)).data.data as CourseDetail;
  },
  async uploadCover(id: string, file: File) {
    const body = new FormData();
    body.append('file', file);
    return (await api.post(`/courses/${id}/cover`, body)).data.data.cover_url as string;
  },
  removeCover: (id: string) => api.delete(`/courses/${id}/cover`),
  setTeachers: (id: string, userIds: string[]) => api.put(`/courses/${id}/teachers`, { user_ids: userIds }),
  publish: (id: string, note: string | null) => api.post(`/courses/${id}/publish`, { note }),
  archive: (id: string) => api.post(`/courses/${id}/archive`),
  restore: (id: string) => api.post(`/courses/${id}/restore`),

  addSection: (id: string, title: string) => api.post(`/courses/${id}/sections`, { title }),
  renameSection: (id: string, section: string, title: string) => api.put(`/courses/${id}/sections/${section}`, { title }),
  deleteSection: (id: string, section: string) => api.delete(`/courses/${id}/sections/${section}`),
  reorderSections: (id: string, ids: string[]) => api.put(`/courses/${id}/sections/order`, { ids }),

  addResource: (id: string, section: string, input: ResourceInput) =>
    api.post(`/courses/${id}/sections/${section}/resources`, resourceBody(input)),
  // El tipo no cambia; un archivo nuevo reemplaza al anterior
  updateResource: (id: string, resource: string, input: Omit<ResourceInput, 'type'>) =>
    api.post(`/courses/${id}/resources/${resource}`, resourceBody(input)),
  deleteResource: (id: string, resource: string) => api.delete(`/courses/${id}/resources/${resource}`),
  reorderResources: (id: string, section: string, ids: string[]) =>
    api.put(`/courses/${id}/sections/${section}/resources/order`, { ids }),

  // El material es privado: se pide con la sesión y se entrega al navegador como descarga
  async download(id: string, resource: CourseResource) {
    const response = await api.get(`/courses/${id}/resources/${resource.id}/download`, { responseType: 'blob' });
    const url = URL.createObjectURL(response.data as Blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = resource.file?.name ?? resource.title;
    link.click();
    URL.revokeObjectURL(url);
  },
};

export function formatSize(bytes: number): string {
  if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

export function formatDate(value: string | null): string {
  if (!value) return '—';
  return new Date(value).toLocaleDateString('es-PE', { day: 'numeric', month: 'short', year: 'numeric' });
}
