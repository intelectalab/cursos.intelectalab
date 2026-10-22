import { api } from '../../shared/api/client';

// Anuncios del docente a sus alumnos y reseñas de los alumnos sobre el curso

export interface Announcement {
  id: string;
  title: string;
  body: string;
  author: string | null;
  // Se avisó también por correo a los alumnos
  emailed: boolean;
  created_at: string;
  updated_at: string;
  can_edit?: boolean;
}

export interface Review {
  id: string;
  rating: number;
  comment: string | null;
  status: 'VISIBLE' | 'HIDDEN';
  updated_at: string;
  // Respuesta pública de la academia
  response: string | null;
  responded_at: string | null;
}

export interface StaffReview extends Review {
  student: { id: string; name: string; email: string };
  hidden_reason: string | null;
}

// Promedio de las reseñas visibles; null = todavía no hay
export type Rating = { average: number; count: number } | null;

export const communityApi = {
  async announcements(course: string) {
    const response = await api.get(`/courses/${course}/announcements`);
    return { data: response.data.data as Announcement[], canPublish: response.data.meta.can_publish as boolean };
  },
  async publish(course: string, payload: { title: string; body: string; notify: boolean }) {
    return (await api.post(`/courses/${course}/announcements`, payload)).data.data as Announcement;
  },
  async update(course: string, id: string, payload: { title: string; body: string }) {
    return (await api.put(`/courses/${course}/announcements/${id}`, payload)).data.data as Announcement;
  },
  async remove(course: string, id: string) {
    await api.delete(`/courses/${course}/announcements/${id}`);
  },
  async reviews(course: string) {
    const response = await api.get(`/courses/${course}/reviews`);
    return {
      data: response.data.data as StaffReview[],
      average: response.data.meta.average as number | null,
      count: response.data.meta.count as number,
      canModerate: response.data.meta.can_moderate as boolean,
    };
  },
  async hide(course: string, review: string, reason: string) {
    return (await api.post(`/courses/${course}/reviews/${review}/hide`, { reason })).data.data as Review;
  },
  async show(course: string, review: string) {
    return (await api.post(`/courses/${course}/reviews/${review}/show`)).data.data as Review;
  },
  async respond(course: string, review: string, response: string | null) {
    return (await api.put(`/courses/${course}/reviews/${review}/response`, { response })).data.data as Review;
  },
  async myAnnouncements(course: string) {
    return (await api.get(`/me/courses/${course}/announcements`)).data.data as Announcement[];
  },
  async myReview(course: string) {
    return (await api.get(`/me/courses/${course}/review`)).data.data as Review | null;
  },
  async saveMyReview(course: string, payload: { rating: number; comment: string | null }) {
    return (await api.put(`/me/courses/${course}/review`, payload)).data.data as Review;
  },
};

export const formatWhen = (value: string) => new Date(value).toLocaleDateString('es-PE', { day: 'numeric', month: 'short', year: 'numeric' });
