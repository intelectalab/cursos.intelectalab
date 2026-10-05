import { api } from '../../shared/api/client';
import type { PageMeta } from '../identity/api';
import type { ActivityType } from '../academic/api';
import type { CourseLevel, ResourceType } from '../courses/api';

/* ───────────────────────────── Personal: matrículas de un curso ───────────────────────────── */

export interface StudentRef {
  id: string;
  name: string;
  email: string;
}

export interface Enrollment {
  id: string;
  status: 'ACTIVE' | 'CANCELLED';
  source: string;
  enrolled_at: string;
  cancelled_at: string | null;
  student: StudentRef;
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
  sections_count: number;
  resources_count: number;
  activities_count: number;
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
}

export interface ClassroomSection {
  id: string;
  title: string;
  resources: ClassroomResource[];
  activities: ClassroomActivity[];
}

export interface Classroom extends MyCourse {
  description: string | null;
  sections: ClassroomSection[];
}

export const enrollmentsApi = {
  async list(course: string, page = 1) {
    const response = await api.get(`/courses/${course}/enrollments`, { params: { page } });
    return { data: response.data.data as Enrollment[], meta: response.data.meta as PageMeta };
  },
  async candidates(course: string, search?: string) {
    return (await api.get(`/courses/${course}/enrollment-candidates`, { params: { search: search || undefined } })).data.data as StudentRef[];
  },
  async enroll(course: string, studentIds: string[]) {
    return (await api.post(`/courses/${course}/enrollments`, { student_ids: studentIds })).data.meta as {
      created: number;
      already_enrolled: number;
    };
  },
  cancel: (course: string, enrollment: string) => api.delete(`/courses/${course}/enrollments/${enrollment}`),

  async myCourses() {
    return (await api.get('/me/courses')).data.data as MyCourse[];
  },
  async classroom(course: string) {
    const response = await api.get(`/me/courses/${course}`);
    return { classroom: response.data.data as Classroom, gradeScale: response.data.meta.grade_scale as number };
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
