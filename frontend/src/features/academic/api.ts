import { api } from '../../shared/api/client';
import type { PageMeta } from '../identity/api';

/* ───────────────────────────── Banco de preguntas ───────────────────────────── */

export type QuestionType = 'SINGLE' | 'MULTIPLE' | 'TRUE_FALSE' | 'SHORT_TEXT' | 'NUMERIC' | 'MATCHING' | 'ORDERING';

export const QUESTION_TYPES: QuestionType[] = ['SINGLE', 'MULTIPLE', 'TRUE_FALSE', 'SHORT_TEXT', 'NUMERIC', 'MATCHING', 'ORDERING'];

export const QUESTION_LABEL: Record<QuestionType, string> = {
  SINGLE: 'Opción única',
  MULTIPLE: 'Opción múltiple',
  TRUE_FALSE: 'Verdadero o falso',
  SHORT_TEXT: 'Respuesta corta',
  NUMERIC: 'Numérica',
  MATCHING: 'Emparejar',
  ORDERING: 'Ordenar',
};

export const QUESTION_HINT: Record<QuestionType, string> = {
  SINGLE: 'Varias opciones, una sola correcta.',
  MULTIPLE: 'Varias opciones, una o más correctas.',
  TRUE_FALSE: 'Una afirmación que es cierta o falsa.',
  SHORT_TEXT: 'El alumno escribe una palabra o frase.',
  NUMERIC: 'El alumno escribe un número.',
  MATCHING: 'Unir cada elemento con su pareja.',
  ORDERING: 'Poner los elementos en el orden correcto.',
};

export interface ChoiceOption {
  id?: string;
  text: string;
  correct: boolean;
}

export interface MatchingPair {
  id?: string;
  left: string;
  right: string;
}

export interface OrderingItem {
  id?: string;
  text: string;
}

// La forma depende del tipo; coincide con QuestionConfig del backend
export interface QuestionConfig {
  options?: ChoiceOption[];
  answer?: boolean | number;
  answers?: string[];
  tolerance?: number;
  pairs?: MatchingPair[];
  items?: OrderingItem[];
}

export interface QuestionBank {
  id: string;
  name: string;
  description: string | null;
  questions_count: number;
}

export interface Question {
  id: string;
  bank_id: string;
  type: QuestionType;
  statement: string;
  config: QuestionConfig;
  explanation: string | null;
  quizzes_count: number;
}

export interface QuestionInput {
  type: QuestionType;
  statement: string;
  explanation: string | null;
  config: QuestionConfig;
}

/* ───────────────────────────── Actividades ───────────────────────────── */

export type ActivityType = 'QUIZ' | 'TASK' | 'SURVEY' | 'SCORM';

export const ACTIVITY_LABEL: Record<ActivityType, string> = {
  QUIZ: 'Cuestionario',
  TASK: 'Tarea',
  SURVEY: 'Encuesta',
  SCORM: 'Paquete SCORM',
};

export const ACTIVITY_HINT: Record<ActivityType, string> = {
  QUIZ: 'Preguntas que se califican solas.',
  TASK: 'El alumno entrega y el docente revisa.',
  SURVEY: 'Opinión del alumno, sin nota.',
  SCORM: 'Contenido interactivo en .zip (SCORM 1.2 o 2004), con su avance y nota.',
};

export type SurveyQuestionType = 'SCALE' | 'CHOICE' | 'TEXT';

export const SURVEY_LABEL: Record<SurveyQuestionType, string> = {
  SCALE: 'Escala del 1 al 5',
  CHOICE: 'Elegir una opción',
  TEXT: 'Texto libre',
};

export interface SurveyQuestion {
  id?: string;
  type: SurveyQuestionType;
  text: string;
  required: boolean;
  options?: string[];
}

export interface QuizItem {
  question_id: string;
  points: number;
  type: QuestionType;
  statement: string;
}

export interface Activity {
  id: string;
  section_id: string;
  type: ActivityType;
  title: string;
  description: string | null;
  // Peso en la nota final del curso (cuestionarios y tareas); 0 = no cuenta
  weight: number;
  // Cuestionario
  passing_percent?: number;
  max_attempts?: number | null;
  time_limit_minutes?: number | null;
  shuffle?: boolean;
  // Cuestionario: preguntas elegidas del banco. Encuesta: sus propias preguntas.
  questions?: QuizItem[] | SurveyQuestion[];
  // Tarea
  allow_text?: boolean;
  allow_file?: boolean;
  // Plazo en días desde el inicio de la matrícula; null = sin plazo
  due_days?: number | null;
  accept_late?: boolean;
  // SCORM: el paquete subido; null = falta subirlo
  package?: ScormPackageInfo | null;
}

export interface ScormPackageInfo {
  file_name: string;
  version: '1.2' | '2004';
  title: string | null;
  size_bytes: number;
  files_count: number;
  // Nota mínima que define el paquete, en porcentaje
  mastery_score: number | null;
  uploaded_at: string | null;
}

// Lo necesario para abrir un paquete en el aula (o en la vista previa del personal)
export interface ScormLaunchData {
  launch_url: string;
  version: '1.2' | '2004';
  cmi: Record<string, string>;
  // false = vista previa: no se guarda nada
  tracking: boolean;
}

export interface ActivityInput {
  type: ActivityType;
  title: string;
  description: string | null;
  weight?: number;
  passing_percent?: number;
  max_attempts?: number | null;
  time_limit_minutes?: number | null;
  shuffle?: boolean;
  allow_text?: boolean;
  allow_file?: boolean;
  due_days?: number | null;
  accept_late?: boolean;
  questions?: SurveyQuestion[];
}

export const academicApi = {
  async banks() {
    return (await api.get('/question-banks')).data.data as QuestionBank[];
  },
  createBank: (input: { name: string; description: string | null }) => api.post('/question-banks', input),
  updateBank: (id: string, input: { name: string; description: string | null }) => api.put(`/question-banks/${id}`, input),
  deleteBank: (id: string) => api.delete(`/question-banks/${id}`),

  async questions(bank: string, params: { search?: string; type?: string; page?: number; per_page?: number }) {
    const response = await api.get(`/question-banks/${bank}/questions`, { params });
    return { data: response.data.data as Question[], meta: response.data.meta as PageMeta };
  },
  createQuestion: (bank: string, input: QuestionInput) => api.post(`/question-banks/${bank}/questions`, input),
  updateQuestion: (id: string, input: Omit<QuestionInput, 'type'>) => api.put(`/questions/${id}`, input),
  deleteQuestion: (id: string) => api.delete(`/questions/${id}`),

  async activities(course: string) {
    const response = await api.get(`/courses/${course}/activities`);
    return { activities: response.data.data as Activity[], gradeScale: response.data.meta.grade_scale as number };
  },
  createActivity: (course: string, section: string, input: ActivityInput) =>
    api.post(`/courses/${course}/sections/${section}/activities`, input),
  updateActivity: (course: string, activity: string, input: Omit<ActivityInput, 'type'>) =>
    api.put(`/courses/${course}/activities/${activity}`, input),
  deleteActivity: (course: string, activity: string) => api.delete(`/courses/${course}/activities/${activity}`),
  reorderActivities: (course: string, section: string, ids: string[]) =>
    api.put(`/courses/${course}/sections/${section}/activities/order`, { ids }),
  setQuizQuestions: (course: string, activity: string, items: { question_id: string; points: number }[]) =>
    api.put(`/courses/${course}/activities/${activity}/questions`, { items }),

  // SCORM
  async uploadScorm(course: string, activity: string, file: File) {
    const form = new FormData();
    form.append('file', file);
    return (await api.post(`/courses/${course}/activities/${activity}/scorm`, form)).data.data as Activity;
  },
  async scormPreview(course: string, activity: string) {
    return (await api.get(`/courses/${course}/activities/${activity}/scorm/preview`)).data.data as ScormLaunchData;
  },
  async scormLaunch(course: string, activity: string) {
    return (await api.get(`/me/courses/${course}/activities/${activity}/scorm`)).data.data as ScormLaunchData & { state: ScormState };
  },
  async scormCommit(course: string, activity: string, cmi: Record<string, string>) {
    return (await api.post(`/me/courses/${course}/activities/${activity}/scorm`, { cmi })).data.data as ScormState;
  },
  resetScorm: (course: string, enrollment: string, activity: string) => api.delete(`/courses/${course}/enrollments/${enrollment}/activities/${activity}/scorm`),
};

export interface ScormState {
  lesson_status: string;
  score_percent: number | null;
  completed: boolean;
  last_seen_at: string | null;
}

export const SCORM_STATUS_LABEL: Record<string, string> = {
  'not attempted': 'Sin empezar',
  incomplete: 'En curso',
  browsed: 'Revisado',
  completed: 'Completado',
  passed: 'Aprobado',
  failed: 'Desaprobado',
};

// Las notas se guardan en porcentaje y se muestran en la escala de la academia (20 o 100)
export function toScale(percent: number, scale: number): number {
  return Math.round(((percent * scale) / 100) * 100) / 100;
}

export function toPercent(grade: number, scale: number): number {
  return Math.round(((grade * 100) / scale) * 100) / 100;
}
