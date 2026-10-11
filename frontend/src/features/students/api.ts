import type { OrderStatus } from '../sales/api';
import { api } from '../../shared/api/client';
import type { PageMeta } from '../identity/api';

export type StudentFilter = '' | 'ENROLLED' | 'NOT_ENROLLED' | 'INACTIVE';

export interface StudentRow {
  id: string;
  name: string;
  email: string;
  phone: string | null;
  // Tipo y número, ya unidos; null si no lo registró
  document: string | null;
  status: 'ACTIVE' | 'INACTIVE';
  last_login_at: string | null;
  created_at: string;
  // Matrículas activas y, de ellas, las de cursos ya completados
  active_enrollments: number;
  completed_courses: number;
}

export interface StudentEnrollment {
  id: string;
  course_id: string;
  course_title: string;
  state: 'ACTIVE' | 'COMPLETED' | 'EXPIRED' | 'UPCOMING' | 'CANCELLED';
  source: string;
  enrolled_at: string;
  ends_at: string | null;
  completed_at: string | null;
  // null en una matrícula anulada o de un curso sin publicar
  progress_percent: number | null;
  final_percent: number | null;
}

export interface StudentOrder {
  id: string;
  number: number;
  title: string | null;
  state: OrderStatus;
  total_amount: string;
  currency: string | null;
  created_at: string;
  paid_at: string | null;
}

export interface StudentCertificate {
  id: string;
  course_title: string | null;
  code: string;
  status: 'ISSUED' | 'REVOKED';
  issued_at: string;
}

export interface StudentTicket {
  id: string;
  number: number;
  subject: string;
  status: 'OPEN' | 'IN_PROGRESS' | 'RESOLVED' | 'CLOSED';
  created_at: string;
}

// Ficha completa del alumno ("Alumno 360")
export interface StudentProfile {
  student: StudentRow & { must_change_password: boolean; groups: string[]; company: string | null };
  enrollments: StudentEnrollment[];
  orders: StudentOrder[];
  // Lo pagado, por código de moneda
  paid_totals: Record<string, number>;
  certificates: StudentCertificate[];
  tickets: StudentTicket[];
}

export const studentsApi = {
  async list(params: { search?: string; filter?: string; page?: number }) {
    const response = await api.get('/students', { params });
    return { data: response.data.data as StudentRow[], meta: response.data.meta as PageMeta };
  },
  async profile(id: string) {
    const response = await api.get(`/students/${id}`);
    return { profile: response.data.data as StudentProfile, gradeScale: response.data.meta.grade_scale as number };
  },
};
