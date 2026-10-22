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

export interface CourseContext {
  id: string;
  title: string;
  status: 'DRAFT' | 'PUBLISHED' | 'ARCHIVED';
  version: number | null;
  price: string | null;
  currency: string;
  teachers: string[];
  students: { with_access: number; enrolled: number; completed: number; expiring: number };
  // null = el rol no revisa entregas de este curso
  pending_submissions: number | null;
  // null = el rol no ve ventas
  sales: { days: number; units: number; revenue: string; last_sale_at: string | null } | null;
  rating: { average: number; count: number } | null;
  next_class: { date: string; start: string; end: string; teacher: string } | null;
}

export interface StudentRow {
  id: string;
  name: string;
  email: string;
  document: string | null;
  active_enrollments: number;
  completed_courses: number;
}

export interface Debt {
  currency: string;
  total: string;
  overdue: string;
  next_due: string | null;
  orders: number;
}

export interface EnrollmentLine {
  id: string;
  course_id: string;
  course_title: string;
  state: string;
  ends_at: string | null;
  progress_percent: number | null;
  final_percent: number | null;
}

export interface StudentContext {
  student: StudentRow & { status: string };
  enrollments: EnrollmentLine[];
  certificates: { id: string; course_title: string | null; status: string }[];
  tickets: { id: string; number: number; subject: string; status: string }[];
  debt: Debt[];
  grade_scale: number;
}

export interface DaySchedule {
  teacher: string;
  teacher_id: string;
  blocks: { start: string; end: string; type: 'AVAILABLE' | 'LIVE_CLASS' | 'GRADING'; course_title: string | null }[];
}

export interface MineContext {
  enrollments: EnrollmentLine[];
  certificates: { id: string; course_title: string | null; status: string }[];
  debt: Debt[];
  live_classes: { course_id: string; course_title: string; teacher: string; day: number; start: string; end: string; meeting_url: string | null }[];
  grade_scale: number;
}

export interface Companion {
  id: string;
  title: string;
  price: string;
  // Personas que llevaron ambos cursos
  people: number;
}

// Sinónimos aprendidos por el buscador: se piden una vez y se renuevan al aprender uno
let aliasCache: { key: string; promise: Promise<Record<string, string[]>> } | null = null;

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
  async course(id: string) {
    return (await api.get(`/assistant/courses/${id}/context`)).data.data as CourseContext;
  },
  async findStudents(search: string) {
    return (await api.get('/assistant/students', { params: { search } })).data.data as StudentRow[];
  },
  async student(id: string) {
    return (await api.get(`/assistant/students/${id}/context`)).data.data as StudentContext;
  },
  async day(day: number) {
    return (await api.get('/assistant/schedule', { params: { day } })).data.data as DaySchedule[];
  },
  async mine() {
    return (await api.get('/assistant/me')).data.data as MineContext;
  },
  aliases(key: string): Promise<Record<string, string[]>> {
    if (aliasCache?.key !== key) {
      const promise = api
        .get('/assistant/search-aliases')
        .then((response) => (Array.isArray(response.data.data) ? {} : (response.data.data as Record<string, string[]>)))
        // Sin sinónimos la búsqueda funciona igual
        .catch(() => ({}));
      aliasCache = { key, promise };
    }
    return aliasCache.promise;
  },
  // Registro silencioso: si falla, no interrumpe a la persona
  learn(term: string, type: 'COURSE' | 'PACKAGE', id: string) {
    api
      .post('/assistant/search-feedback', { term, target_type: type, target_id: id })
      .then(() => {
        aliasCache = null;
      })
      .catch(() => undefined);
  },
  async companions(ids: string[]) {
    return (await api.get('/assistant/companions', { params: { ids } })).data.data as Companion[];
  },
  forgetCatalog() {
    cached = null;
    aliasCache = null;
  },
};
