import { api } from '../../shared/api/client';
import type { Tone } from '../../shared/ui';

export type BlockType = 'AVAILABLE' | 'LIVE_CLASS' | 'GRADING';
export type ScheduleStatus = 'PENDING' | 'CONFIRMED' | 'OBSERVED';
export type ScheduleFilter = '' | ScheduleStatus | 'NONE';

export interface ScheduleBlock {
  // 1 = lunes … 7 = domingo
  day: number;
  start: string;
  end: string;
  type: BlockType;
  course_id: string | null;
  course_title?: string | null;
  meeting_url: string | null;
  note: string | null;
}

export interface Schedule {
  id: string;
  status: ScheduleStatus;
  valid_from: string;
  blocks: ScheduleBlock[];
  // Lo que el docente pidió cambiar, si lo observó
  observation: string | null;
  sent_at: string;
  sent_by: string | null;
  responded_at: string | null;
  // Lo último que confirmó: lo que ven los alumnos mientras un cambio espera
  confirmed: { valid_from: string; blocks: ScheduleBlock[] } | null;
}

export interface ScheduleRow {
  teacher: { id: string; name: string; email: string };
  courses_count: number;
  schedule: Schedule | null;
  hours: Record<BlockType, number>;
}

export interface LiveClass {
  course_id: string;
  course_title: string;
  teacher: string;
  day: number;
  start: string;
  end: string;
  meeting_url: string | null;
  note: string | null;
  valid_from: string | null;
  // Próximos días de esta clase en que no habrá clase (feriado o permiso del docente)
  cancelled?: { date: string; reason: string | null }[];
}

export interface ScheduleExceptionRow {
  id: string;
  date: string;
  reason: string | null;
}

export const DAYS = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];

// Cada tipo de bloque con su nombre, su color en el calendario y su explicación
export const BLOCK_TYPES: Record<BlockType, { label: string; tone: Tone; block: string; help: string }> = {
  AVAILABLE: {
    label: 'Disponible',
    tone: 'success',
    block: 'bg-emerald-50 border-emerald-300 text-emerald-900',
    help: 'Horas en las que el docente puede atender a la academia o a los alumnos.',
  },
  LIVE_CLASS: {
    label: 'Clase en vivo',
    tone: 'violet',
    block: 'bg-violet-50 border-violet-300 text-violet-900',
    help: 'Clase por videollamada de un curso. Los alumnos del curso la ven con su enlace cuando el docente confirma.',
  },
  GRADING: {
    label: 'Revisión de trabajos',
    tone: 'warning',
    block: 'bg-amber-50 border-amber-300 text-amber-900',
    help: 'Horas que el docente dedica a calificar tareas y responder dudas.',
  },
};

export const STATUS_META: Record<ScheduleStatus, { label: string; tone: Tone }> = {
  PENDING: { label: 'Por confirmar', tone: 'warning' },
  CONFIRMED: { label: 'Confirmado', tone: 'success' },
  OBSERVED: { label: 'Con observaciones', tone: 'danger' },
};

export function formatHours(hours: number): string {
  const whole = Math.floor(hours);
  const minutes = Math.round((hours - whole) * 60);
  if (minutes === 0) return `${whole} h`;
  return whole === 0 ? `${minutes} min` : `${whole} h ${minutes} min`;
}

export function formatDay(value: string): string {
  return new Date(`${value}T12:00:00`).toLocaleDateString('es-PE', { day: 'numeric', month: 'long', year: 'numeric' });
}

export const schedulesApi = {
  async list(params: { search?: string; filter?: string }) {
    const response = await api.get('/schedules', { params });
    return {
      data: response.data.data as ScheduleRow[],
      counts: response.data.meta.counts as Record<string, number>,
      timezone: response.data.meta.timezone as string,
    };
  },
  async show(teacher: string) {
    const response = await api.get(`/schedules/${teacher}`);
    return {
      ...(response.data.data as { teacher: ScheduleRow['teacher']; schedule: Schedule | null; courses: { id: string; title: string }[] }),
      timezone: response.data.meta.timezone as string,
    };
  },
  // Guarda la semana y la envía al docente para que la confirme
  async send(teacher: string, payload: { valid_from: string; blocks: ScheduleBlock[] }) {
    return (await api.put(`/schedules/${teacher}`, payload)).data.data as Schedule;
  },
  async exceptions(teacher: string) {
    return (await api.get(`/schedules/${teacher}/exceptions`)).data.data as ScheduleExceptionRow[];
  },
  async addException(teacher: string, date: string, reason: string | null) {
    return (await api.post(`/schedules/${teacher}/exceptions`, { date, reason })).data.data as ScheduleExceptionRow[];
  },
  async removeException(teacher: string, id: string) {
    return (await api.delete(`/schedules/${teacher}/exceptions/${id}`)).data.data as ScheduleExceptionRow[];
  },
  async mine() {
    const response = await api.get('/me/schedule');
    return { schedule: response.data.data as Schedule | null, timezone: response.data.meta.timezone as string };
  },
  async confirm() {
    return (await api.post('/me/schedule/confirm')).data.data as Schedule;
  },
  async observe(observation: string) {
    return (await api.post('/me/schedule/observe', { observation })).data.data as Schedule;
  },
  async liveClasses() {
    return (await api.get('/me/live-classes')).data.data as LiveClass[];
  },
};

export type AttendanceStatus = 'PRESENT' | 'LATE' | 'ABSENT' | 'EXCUSED';

export const ATTENDANCE: Record<AttendanceStatus, { label: string; tone: Tone }> = {
  PRESENT: { label: 'Presente', tone: 'success' },
  LATE: { label: 'Tarde', tone: 'warning' },
  ABSENT: { label: 'Ausente', tone: 'danger' },
  EXCUSED: { label: 'Justificado', tone: 'neutral' },
};

export interface AttendanceTally {
  sessions: number;
  attended: number;
  absent: number;
  excused: number;
  // null = todavía no hay clases con asistencia que cuenten
  percent: number | null;
}

export interface AttendanceDay {
  course: { id: string; title: string };
  date: string;
  today: string;
  sessions: { start: string; end: string; teacher: string }[];
  students: { id: string; name: string; email: string; statuses: Record<string, AttendanceStatus> }[];
}

export const attendanceApi = {
  async day(course: string, date?: string) {
    return (await api.get(`/courses/${course}/attendance`, { params: date ? { date } : undefined })).data.data as AttendanceDay;
  },
  async save(course: string, payload: { date: string; start: string; records: { student_id: string; status: AttendanceStatus }[] }) {
    return (await api.put(`/courses/${course}/attendance`, payload)).data.data as { saved: number };
  },
  async summary(course: string) {
    return (await api.get(`/courses/${course}/attendance/summary`)).data.data as {
      sessions_taken: number;
      students: ({ id: string; name: string; email: string } & AttendanceTally)[];
    };
  },
  async mine(course: string) {
    return (await api.get(`/me/courses/${course}/attendance`)).data.data as AttendanceTally & {
      records: { date: string; start: string; status: AttendanceStatus }[];
    };
  },
};
