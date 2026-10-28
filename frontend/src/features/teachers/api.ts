import { api } from '../../shared/api/client';
import type { CourseStatus } from '../courses/api';
import type { PageMeta } from '../identity/api';

export type TeacherFilter = '' | 'WITH_COURSES' | 'WITHOUT_COURSES' | 'INACTIVE';

export interface TeacherRow {
  id: string;
  name: string;
  email: string;
  phone: string | null;
  status: 'ACTIVE' | 'INACTIVE';
  last_login_at: string | null;
  created_at: string;
  // Cursos asignados sin contar los archivados, y entregas que esperan revisión en ellos
  courses_count: number;
  pending_submissions: number;
}

export interface TeacherCourse {
  id: string;
  title: string;
  status: CourseStatus;
  version: number | null;
  students: number;
  pending_submissions: number;
  // Desde cuándo espera la entrega más antigua sin revisar
  oldest_pending_at: string | null;
}

export interface TeacherProfile {
  teacher: TeacherRow;
  courses: TeacherCourse[];
  students_total: number;
  // Entregas que calificó esta persona en los últimos días (ver recentDays)
  graded_recently: number;
  // Promedio de las reseñas visibles de sus cursos; null = aún no hay
  rating: { average: number; count: number } | null;
}

export const teachersApi = {
  async list(params: { search?: string; filter?: string; course_id?: string; page?: number }) {
    const response = await api.get('/teachers', { params });
    return { data: response.data.data as TeacherRow[], meta: response.data.meta as PageMeta };
  },
  async profile(id: string) {
    const response = await api.get(`/teachers/${id}`);
    return { profile: response.data.data as TeacherProfile, recentDays: response.data.meta.recent_days as number };
  },
};
