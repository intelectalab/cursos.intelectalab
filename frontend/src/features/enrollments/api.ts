import { api } from '../../shared/api/client';
import type { PageMeta } from '../identity/api';
import type { ActivityType } from '../academic/api';
import type { CourseLevel, ResourceType } from '../courses/api';
import type { Tone } from '../../shared/ui';

/* ───────────────────────────── Personal: matrículas de un curso ───────────────────────────── */

export interface StudentRef {
  id: string;
  name: string;
  email: string;
}

// Papel de una persona dentro de un curso
export type ParticipantRole = 'STUDENT' | 'TEACHER';

export interface ParticipantCandidate extends StudentRef {
  // Roles que tiene en la academia, para reconocerla en la lista
  roles: string[];
}

// Situación de una matrícula hoy: se deduce de su estado y de sus fechas de vigencia
export type EnrollmentState = 'ACTIVE' | 'UPCOMING' | 'EXPIRED' | 'COMPLETED' | 'CANCELLED';

export const STATE_LABEL: Record<EnrollmentState, string> = {
  ACTIVE: 'Activa',
  UPCOMING: 'Por iniciar',
  EXPIRED: 'Vencida',
  COMPLETED: 'Completada',
  CANCELLED: 'Cancelada',
};

export const STATE_TONE: Record<EnrollmentState, Tone> = {
  ACTIVE: 'success',
  UPCOMING: 'info',
  EXPIRED: 'warning',
  COMPLETED: 'brand',
  CANCELLED: 'neutral',
};

export const ENROLLMENT_STATES: EnrollmentState[] = ['ACTIVE', 'UPCOMING', 'EXPIRED', 'COMPLETED', 'CANCELLED'];

export interface Enrollment {
  id: string;
  status: 'ACTIVE' | 'CANCELLED';
  state: EnrollmentState;
  source: string;
  enrolled_at: string;
  // Vigencia en días completos (AAAA-MM-DD); null = sin límite
  starts_at: string | null;
  ends_at: string | null;
  completed_at: string | null;
  cancelled_at: string | null;
  course: { id: string; title: string };
  student: StudentRef;
  progress_percent?: number;
}

export interface Validity {
  starts_at: string | null;
  ends_at: string | null;
}

export interface EnrollableCourse {
  id: string;
  title: string;
  // Solo los publicados admiten matrículas nuevas
  open: boolean;
  access_days: number | null;
}

/* ───────────────────────────── Alumno: sus cursos y el aula ───────────────────────────── */

export interface MyCourse {
  course_id: string;
  title: string;
  summary: string | null;
  category: string | null;
  level: CourseLevel | null;
  duration_hours: number | null;
  language: string | null;
  cover_url: string | null;
  enrolled_at: string;
  state: EnrollmentState;
  starts_at: string | null;
  ends_at: string | null;
  completed_at: string | null;
  sections_count: number;
  resources_count: number;
  activities_count: number;
  // Solo en el listado de Mis cursos
  progress_percent?: number;
}

// Avance calculado en el servidor sobre la versión publicada
export interface Progress {
  percent: number;
  completed: number;
  total: number;
  resource_ids: string[];
  activity_ids: string[];
}

export interface ClassroomResource {
  id: string;
  type: ResourceType;
  title: string;
  url: string | null;
  video_id: string | null;
  body: string | null;
  file: { name: string; size_bytes: number } | null;
}

// Ficha de la actividad: las preguntas no viajan hasta que el alumno la rinde
export interface ClassroomActivity {
  id: string;
  type: ActivityType;
  title: string;
  description: string | null;
  questions_count?: number;
  passing_percent?: number | null;
  max_attempts?: number | null;
  time_limit_minutes?: number | null;
  allow_text?: boolean;
  allow_file?: boolean;
  due_days?: number | null;
  accept_late?: boolean;
}

export interface ClassroomSection {
  id: string;
  title: string;
  // Avance secuencial: cerrada hasta completar las anteriores; llega sin contenido
  locked: boolean;
  resources: ClassroomResource[];
  activities: ClassroomActivity[];
}

export interface Classroom extends MyCourse {
  description: string | null;
  sequential: boolean;
  sections: ClassroomSection[];
  progress: Progress;
}

// Lo que el aula necesita para dibujarse; también lo trae la vista previa del personal
export type ClassroomContent = Pick<Classroom, 'course_id' | 'title' | 'summary' | 'category' | 'description' | 'sequential' | 'sections'>;

export type PreviewSource = 'draft' | 'published';

export interface PreviewMeta {
  source: PreviewSource;
  version: number | null;
  has_published: boolean;
  pending_changes: boolean;
}

// Inicio del alumno
export interface StudentOverview {
  courses: MyCourse[];
  pending: { course_id: string; course_title: string; activity_id: string; type: ActivityType; title: string }[];
  pending_total: number;
  recent_grades: {
    type: 'QUIZ' | 'TASK';
    course_id: string;
    course_title: string;
    title: string;
    grade_percent: number;
    passed: boolean | null;
    at: string;
  }[];
}

// Curso publicado en el que el alumno aún no está: solo la ficha
export interface AvailableCourse {
  id: string;
  title: string;
  summary: string | null;
  category: string | null;
  level: CourseLevel | null;
  duration_hours: number | null;
  language: string | null;
  cover_url: string | null;
  sections_count: number;
  resources_count: number;
  activities_count: number;
  // null = no está en venta (solo matrícula manual); "0.00" = gratuito
  price: string | null;
  currency: string;
  issues_certificate: boolean;
  // Promedio de las reseñas visibles (solo en el catálogo público); null = aún no hay
  rating?: { average: number; count: number } | null;
}

export interface AvailableCourseDetail extends AvailableCourse {
  description: string | null;
  access_days: number | null;
  teachers: string[];
  sections: { title: string; items: { kind: 'RESOURCE' | 'ACTIVITY'; type: ResourceType | ActivityType; title: string }[] }[];
  // Reseñas visibles más recientes (solo en el catálogo público)
  reviews?: { rating: number; comment: string | null; author: string; updated_at: string }[];
}

// Fecha de vigencia (AAAA-MM-DD) sin corrimiento por zona horaria
export function formatDay(value: string | null): string {
  if (!value) return '—';
  const [year, month, day] = value.split('-').map(Number);
  return new Date(year, month - 1, day).toLocaleDateString('es-PE', { day: 'numeric', month: 'short', year: 'numeric' });
}

export function describeValidity(startsAt: string | null, endsAt: string | null): string {
  if (startsAt && endsAt) return `${formatDay(startsAt)} – ${formatDay(endsAt)}`;
  if (endsAt) return `Hasta el ${formatDay(endsAt)}`;
  if (startsAt) return `Desde el ${formatDay(startsAt)}`;
  return 'Sin vencimiento';
}

export const enrollmentsApi = {
  async list(course: string, page = 1, state?: string) {
    const response = await api.get(`/courses/${course}/enrollments`, { params: { page, state: state || undefined } });
    return { data: response.data.data as Enrollment[], meta: response.data.meta as PageMeta };
  },
  async candidates(course: string, search?: string) {
    return (await api.get(`/courses/${course}/enrollment-candidates`, { params: { search: search || undefined } })).data.data as StudentRef[];
  },
  async all(params: { course_id?: string; search?: string; state?: string; page?: number }) {
    const response = await api.get('/enrollments', { params });
    return { data: response.data.data as Enrollment[], meta: response.data.meta as PageMeta };
  },
  async options() {
    return (await api.get('/enrollments/options')).data.data.courses as EnrollableCourse[];
  },
  // Usuarios activos que aún no participan en el curso, ni como alumnos ni como docentes
  async participantCandidates(course: string, search?: string) {
    return (await api.get(`/courses/${course}/participant-candidates`, { params: { search: search || undefined } })).data.data as ParticipantCandidate[];
  },
  // Personas sueltas, grupos completos o ambos. De un grupo entran sus miembros activos que aún no participan
  async addParticipants(course: string, userIds: string[], role: ParticipantRole, validity?: Validity, groupIds: string[] = []) {
    return (await api.post(`/courses/${course}/participants`, { user_ids: userIds, group_ids: groupIds, role, ...validity })).data.data as {
      added: number;
      skipped: number;
      roles_granted: number;
    };
  },
  removeTeacher: (course: string, user: string) => api.delete(`/courses/${course}/teachers/${user}`),
  async bulkCancel(ids: string[]) {
    return (await api.post('/enrollments/bulk-cancel', { enrollment_ids: ids })).data.data as { updated: number; skipped: number };
  },
  async bulkValidity(ids: string[], validity: Validity) {
    return (await api.post('/enrollments/bulk-validity', { enrollment_ids: ids, ...validity })).data.data as { updated: number; skipped: number };
  },
  updateValidity: (enrollment: string, validity: Validity) => api.put(`/enrollments/${enrollment}`, validity),
  cancelById: (enrollment: string) => api.delete(`/enrollments/${enrollment}`),
  async enroll(course: string, studentIds: string[], validity?: Validity) {
    return (await api.post(`/courses/${course}/enrollments`, { student_ids: studentIds, ...validity })).data.meta as {
      created: number;
      already_enrolled: number;
    };
  },
  cancel: (course: string, enrollment: string) => api.delete(`/courses/${course}/enrollments/${enrollment}`),

  async overview() {
    const response = await api.get('/me/overview');
    return { ...(response.data.data as StudentOverview), gradeScale: response.data.meta.grade_scale as number };
  },
  async availableCourses() {
    return (await api.get('/me/available-courses')).data.data as AvailableCourse[];
  },
  async availableCourse(course: string) {
    return (await api.get(`/me/available-courses/${course}`)).data.data as AvailableCourseDetail;
  },
  async myCourses() {
    return (await api.get('/me/courses')).data.data as MyCourse[];
  },
  async classroom(course: string) {
    const response = await api.get(`/me/courses/${course}`);
    return { classroom: response.data.data as Classroom, gradeScale: response.data.meta.grade_scale as number };
  },
  // Personal: el aula como la ve un alumno, de lo publicado o del borrador
  async preview(course: string, source?: PreviewSource) {
    const response = await api.get(`/courses/${course}/preview`, { params: { source } });
    return {
      classroom: response.data.data as ClassroomContent,
      gradeScale: response.data.meta.grade_scale as number,
      meta: response.data.meta as PreviewMeta,
    };
  },
  async downloadAsStaff(course: string, resource: ClassroomResource) {
    const response = await api.get(`/courses/${course}/resources/${resource.id}/download`, { responseType: 'blob' });
    const url = URL.createObjectURL(response.data as Blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = resource.file?.name ?? resource.title;
    link.click();
    URL.revokeObjectURL(url);
  },
  async completeResource(course: string, resource: string) {
    return (await api.post(`/me/courses/${course}/resources/${resource}/complete`)).data.data as Progress;
  },
  // El material es privado: se pide con la sesión y se entrega al navegador como descarga
  async download(course: string, resource: ClassroomResource) {
    const response = await api.get(`/me/courses/${course}/resources/${resource.id}/download`, { responseType: 'blob' });
    const url = URL.createObjectURL(response.data as Blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = resource.file?.name ?? resource.title;
    link.click();
    URL.revokeObjectURL(url);
  },
};
