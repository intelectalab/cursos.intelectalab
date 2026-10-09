import { api } from '../../shared/api/client';
import type { PageMeta } from '../identity/api';
import type { ActivityType, QuestionConfig, QuestionType, SurveyQuestion } from './api';

/* ───────────────────────────── Alumno ───────────────────────────── */

export interface AttemptSummary {
  id: string;
  number: number;
  status: 'SUBMITTED' | 'EXPIRED';
  score_percent: number;
  passed: boolean;
  submitted_at: string | null;
}

export interface QuizState {
  attempts: AttemptSummary[];
  best_percent: number | null;
  passed: boolean;
  // null: intentos ilimitados
  attempts_left: number | null;
  // Intentos adicionales que le concedió quien revisa
  extra_attempts: number;
  open_attempt_id: string | null;
}

export interface Submission {
  id: string;
  // RETURNED: quien revisa la devolvió para rehacerla
  status: 'SUBMITTED' | 'GRADED' | 'RETURNED';
  body: string | null;
  file: { name: string; size_bytes: number } | null;
  submitted_at: string;
  is_late: boolean;
  returned_at: string | null;
  grade_percent: number | null;
  feedback: string | null;
  graded_at: string | null;
}

export interface TaskState {
  submission: Submission | null;
  // Fecha límite de este alumno; null = la tarea no tiene plazo
  due_at: string | null;
  past_due: boolean;
  can_submit: boolean;
}

export interface SurveyState {
  answered: boolean;
}

export type ActivityState = QuizState | TaskState | SurveyState;

// Notas del alumno en un curso: solo cuestionarios y tareas
export interface StudentGrades {
  // null: ninguna actividad cuenta para la nota
  final_percent: number | null;
  // Nota mínima para aprobar el curso; null = no se exige
  passing_percent: number | null;
  passed: boolean | null;
  items: {
    id: string;
    type: ActivityType;
    title: string;
    section: string;
    weight: number;
    grade_percent: number | null;
    status?: Submission['status'] | null;
    is_late?: boolean;
  }[];
}

// Lo que ve quien rinde: sin respuestas correctas. Emparejar trae dos columnas; ordenar, los ítems mezclados.
export interface StudentQuestionConfig {
  options?: { id: string; text: string }[];
  left?: { id: string; text: string }[];
  right?: { id: string; text: string }[];
  items?: { id: string; text: string }[];
}

export type Answer = string | boolean | string[] | Record<string, string> | null;

export interface AttemptQuestion {
  id: string;
  type: QuestionType;
  statement: string;
  points: number;
  config: StudentQuestionConfig;
}

export interface OpenAttempt {
  id: string;
  number: number;
  status: 'IN_PROGRESS';
  started_at: string;
  expires_at: string | null;
  server_time: string;
  // Lo que ya llevaba marcado al retomar el intento
  answers: Record<string, Answer>;
  questions: AttemptQuestion[];
}

export interface ResultQuestion extends AttemptQuestion {
  answer: Answer;
  score: number;
  explanation: string | null;
  // Solo cuando ya aprobó o no le quedan intentos
  correct: QuestionConfig | null;
}

export interface AttemptResult {
  id: string;
  number: number;
  status: 'SUBMITTED' | 'EXPIRED';
  score_percent: number;
  passing_percent: number;
  passed: boolean;
  submitted_at: string | null;
  answers_revealed: boolean;
  questions: ResultQuestion[];
}

function saveBlob(blob: Blob, name: string) {
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = name;
  link.click();
  URL.revokeObjectURL(url);
}

export const studentApi = {
  async grades(course: string) {
    return (await api.get(`/me/courses/${course}/grades`)).data.data as StudentGrades;
  },
  async states(course: string) {
    return (await api.get(`/me/courses/${course}/activities`)).data.data as Record<string, ActivityState>;
  },
  async surveyQuestions(course: string, activity: string) {
    return (await api.get(`/me/courses/${course}/activities/${activity}`)).data.data.questions as Required<SurveyQuestion>[];
  },
  async startAttempt(course: string, activity: string) {
    return (await api.post(`/me/courses/${course}/activities/${activity}/attempts`)).data.data as OpenAttempt;
  },
  // Guardado automático mientras responde; no califica
  async saveAttemptDraft(attempt: string, answers: Record<string, Answer>) {
    await api.put(`/me/attempts/${attempt}/draft`, { answers });
  },
  async submitAttempt(attempt: string, answers: Record<string, Answer>) {
    return (await api.post(`/me/attempts/${attempt}/submit`, { answers })).data.data as AttemptResult;
  },
  async attempt(attempt: string) {
    return (await api.get(`/me/attempts/${attempt}`)).data.data as OpenAttempt | AttemptResult;
  },
  async submitTask(course: string, activity: string, body: string, file: File | null) {
    const form = new FormData();
    form.append('body', body);
    if (file) form.append('file', file);
    return (await api.post(`/me/courses/${course}/activities/${activity}/submission`, form)).data.data as Submission;
  },
  async downloadSubmission(course: string, activity: string, name: string) {
    const response = await api.get(`/me/courses/${course}/activities/${activity}/submission/download`, { responseType: 'blob' });
    saveBlob(response.data as Blob, name);
  },
  answerSurvey: (course: string, activity: string, answers: Record<string, string | number>) =>
    api.post(`/me/courses/${course}/activities/${activity}/survey`, { answers }),
};

/* ───────────────────────────── Seguimiento (quien revisa) ───────────────────────────── */

export interface TrackedActivity {
  id: string;
  type: ActivityType;
  title: string;
  section: string;
  // Peso en la nota final; null en las encuestas
  weight: number | null;
}

export interface TrackedStudent {
  enrollment_id: string;
  name: string;
  email: string;
  progress: { percent: number; completed: number; total: number };
  // Promedio ponderado; lo que no tiene nota cuenta como cero. null: nada cuenta para la nota
  final_percent: number | null;
  // null: el curso no exige nota mínima
  passed: boolean | null;
  grades: Record<string, { grade_percent?: number | null; status?: Submission['status'] | null; is_late?: boolean; done?: boolean }>;
}

export interface ReviewSubmission extends Submission {
  activity_id: string;
  activity_title: string;
  student: { name: string; email: string };
}

export interface AttemptGrant {
  attempts: number;
  reason: string;
  granted_by: string | null;
  created_at: string;
}

export interface StudentAttempts {
  student: { name: string; email: string };
  title: string;
  // null: intentos ilimitados
  max_attempts: number | null;
  extra_attempts: number;
  attempts_left: number | null;
  in_progress: boolean;
  can_grant: boolean;
  attempts: AttemptResult[];
  grants: AttemptGrant[];
}

export interface SurveyResults {
  title: string;
  responses: number;
  questions: {
    id: string;
    type: 'SCALE' | 'CHOICE' | 'TEXT';
    text: string;
    answered: number;
    average?: number | null;
    distribution?: Record<string, number>;
    texts?: string[];
  }[];
}

export const reviewApi = {
  async tracking(course: string) {
    const response = await api.get(`/courses/${course}/tracking`);
    return {
      activities: response.data.data.activities as TrackedActivity[],
      students: response.data.data.students as TrackedStudent[],
      gradeScale: response.data.meta.grade_scale as number,
      passingPercent: response.data.meta.passing_percent as number | null,
      pendingSubmissions: response.data.meta.pending_submissions as number,
    };
  },
  async submissions(course: string, page = 1) {
    const response = await api.get(`/courses/${course}/submissions`, { params: { page } });
    return { data: response.data.data as ReviewSubmission[], meta: response.data.meta as PageMeta };
  },
  grade: (course: string, submission: string, gradePercent: number, feedback: string | null) =>
    api.post(`/courses/${course}/submissions/${submission}/grade`, { grade_percent: gradePercent, feedback }),
  async exportGradebook(course: string) {
    const response = await api.get(`/courses/${course}/gradebook/export`, { responseType: 'blob' });
    saveBlob(response.data as Blob, 'notas.xlsx');
  },
  returnSubmission: (course: string, submission: string, feedback: string) =>
    api.post(`/courses/${course}/submissions/${submission}/return`, { feedback }),
  async attempts(course: string, enrollment: string, activity: string) {
    return (await api.get(`/courses/${course}/enrollments/${enrollment}/activities/${activity}/attempts`)).data.data as StudentAttempts;
  },
  grantAttempts: (course: string, enrollment: string, activity: string, attempts: number, reason: string) =>
    api.post(`/courses/${course}/enrollments/${enrollment}/activities/${activity}/extra-attempts`, { attempts, reason }),
  async download(course: string, submission: ReviewSubmission) {
    const response = await api.get(`/courses/${course}/submissions/${submission.id}/download`, { responseType: 'blob' });
    saveBlob(response.data as Blob, submission.file?.name ?? 'entrega');
  },
  async surveyResults(course: string, activity: string) {
    return (await api.get(`/courses/${course}/activities/${activity}/survey-results`)).data.data as SurveyResults;
  },
};
