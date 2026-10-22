import { api } from '../../shared/api/client';
import type { CatalogItem } from './search';

export interface TopItem {
  course_id: string;
  title: string;
  units: number;
  revenue: string;
}

export interface Insights {
  period: { from: string; to: string; timezone: string };
  currency: string;
  // Cada sección llega en null si el rol no tiene el permiso de su módulo
  sales: { revenue: string; payments: number; orders: number; top: TopItem[] } | null;
  enrollments: { new: number; scope: 'all' | 'assigned'; by_course: { course_id: string; title: string; total: number }[] } | null;
  pending: Record<string, number | null>;
  plan: {
    state: string;
    access: 'FULL' | 'GRACE' | 'READ_ONLY';
    plan: { name: string } | null;
    days_left: number | null;
    grace_days_left: number | null;
    max_students: number | null;
    students: number;
    seats_left: number | null;
  } | null;
}

export interface Catalog {
  currency: string;
  timezone: string;
  // true = personal (ve borradores y va al editor del curso); false = comprador
  staff: boolean;
  items: CatalogItem[];
}

// El catálogo se pide una vez por persona y queda en memoria mientras dure la sesión
let cached: { key: string; promise: Promise<Catalog> } | null = null;

export const assistantApi = {
  async insights(from: string, to: string) {
    return (await api.get('/assistant/insights', { params: { from, to } })).data.data as Insights;
  },
  catalog(key: string): Promise<Catalog> {
    if (cached?.key !== key) {
      const promise = api.get('/assistant/catalog').then((response) => response.data.data as Catalog);
      // Si falla, la próxima pregunta vuelve a intentarlo
      promise.catch(() => {
        if (cached?.promise === promise) cached = null;
      });
      cached = { key, promise };
    }
    return cached.promise;
  },
  forgetCatalog() {
    cached = null;
  },
};
