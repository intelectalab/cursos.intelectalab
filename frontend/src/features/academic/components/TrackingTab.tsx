import React, { useCallback, useEffect, useState } from 'react';
import { BarChart3, ClipboardCheck, Download, Paperclip, Users } from 'lucide-react';
import { apiErrorMessage } from '../../../shared/api/client';
import {
  Alert,
  Avatar,
  Badge,
  Button,
  Card,
  CardHeader,
  EmptyState,
  ErrorState,
  Field,
  INPUT_CLASS,
  LoadingState,
  Modal,
  Table,
  TBody,
  Td,
  Th,
  THead,
  Tr,
} from '../../../shared/ui';
import { formatSize } from '../../courses/api';
import { toPercent, toScale } from '../api';
import { reviewApi, type ReviewSubmission, type SurveyResults, type TrackedActivity, type TrackedStudent } from '../studentApi';

function formatDate(value: string | null): string {
  if (!value) return '—';
  return new Date(value).toLocaleString('es-PE', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
}

/* ───────────────────────────── Calificar una entrega ───────────────────────────── */

const GradeModal: React.FC<{
  courseId: string;
  submission: ReviewSubmission;
  gradeScale: number;
  onClose: () => void;
  onDone: () => Promise<void>;
}> = ({ courseId, submission, gradeScale, onClose, onDone }) => {
  const [grade, setGrade] = useState(submission.grade_percent != null ? String(toScale(submission.grade_percent, gradeScale)) : '');
  const [feedback, setFeedback] = useState(submission.feedback ?? '');
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setProcessing(true);
    setError(null);
    try {
      await reviewApi.grade(courseId, submission.id, toPercent(Number(grade), gradeScale), feedback.trim() || null);
      await onDone();
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo guardar la calificación.'));
      setProcessing(false);
    }
  };

  const download = async () => {
    setError(null);
    try {
      await reviewApi.download(courseId, submission);
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo descargar el archivo.'));
    }
  };

  return (
    <Modal
      title={submission.activity_title}
      subtitle={`${submission.student.name} · entregada el ${formatDate(submission.submitted_at)}`}
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="grade-form" processing={processing}>
            {submission.status === 'GRADED' ? 'Actualizar nota' : 'Calificar'}
          </Button>
        </>
      }
    >
      <form id="grade-form" onSubmit={submit} className="space-y-4">
        {error && <Alert>{error}</Alert>}

        <div className="p-3.5 rounded-xl border border-slate-200 bg-slate-50/60 space-y-3">
          {submission.body ? (
            <p className="text-[13px] leading-relaxed text-slate-700 whitespace-pre-wrap break-words max-h-56 overflow-y-auto">{submission.body}</p>
          ) : (
            !submission.file && <p className="text-[13px] text-slate-500">Entrega sin contenido.</p>
          )}
          {submission.file && (
            <div className="flex flex-wrap items-center gap-3">
              <Paperclip className="w-4 h-4 text-slate-500 shrink-0" />
              <span className="text-[13px] text-slate-800 truncate flex-1 min-w-0">
                {submission.file.name} · {formatSize(submission.file.size_bytes)}
              </span>
              <Button size="sm" icon={<Download className="w-3.5 h-3.5" />} onClick={download}>
                Descargar
              </Button>
            </div>
          )}
        </div>

        <Field label={`Nota (de 0 a ${gradeScale})`}>
          <input
            className={`${INPUT_CLASS} max-w-32`}
            type="number"
            required
            min={0}
            max={gradeScale}
            step="any"
            autoFocus
            value={grade}
            onChange={(event) => setGrade(event.target.value)}
          />
        </Field>
        <Field label="Comentario para el alumno (opcional)">
          <textarea className={INPUT_CLASS} rows={4} maxLength={5000} value={feedback} onChange={(event) => setFeedback(event.target.value)} />
        </Field>
        {submission.status !== 'GRADED' && (
          <p className="text-xs text-slate-500">Una vez calificada, el alumno ya no podrá reemplazar su entrega.</p>
        )}
      </form>
    </Modal>
  );
};

/* ───────────────────────────── Resultados de una encuesta ───────────────────────────── */

const SurveyResultsModal: React.FC<{ courseId: string; activity: TrackedActivity; onClose: () => void }> = ({ courseId, activity, onClose }) => {
  const [results, setResults] = useState<SurveyResults | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let active = true;
    reviewApi
      .surveyResults(courseId, activity.id)
      .then((data) => active && setResults(data))
      .catch((err) => active && setError(apiErrorMessage(err, 'No se pudieron cargar los resultados.')));
    return () => {
      active = false;
    };
  }, [courseId, activity.id]);

  return (
    <Modal title={activity.title} subtitle="Resultados agrupados, sin nombres" onClose={onClose} footer={<Button onClick={onClose}>Cerrar</Button>}>
      {error && <Alert>{error}</Alert>}
      {!results && !error && <LoadingState />}
      {results && results.responses === 0 && <EmptyState icon={BarChart3} title="Aún no hay respuestas" />}
      {results && results.responses > 0 && (
        <div className="space-y-5">
          <p className="text-[13px] text-slate-500">
            {results.responses} {results.responses === 1 ? 'alumno respondió' : 'alumnos respondieron'}.
          </p>
          {results.questions.map((question, index) => {
            const max = Math.max(1, ...Object.values(question.distribution ?? {}));
            return (
              <section key={question.id}>
                <h3 className="text-[13px] font-semibold text-slate-900">
                  {index + 1}. {question.text}
                </h3>
                {question.type === 'SCALE' && (
                  <p className="text-xs text-slate-500 mt-0.5">
                    Promedio <span className="font-semibold text-slate-800 tnum">{question.average ?? '—'}</span> de 5 · {question.answered}{' '}
                    {question.answered === 1 ? 'respuesta' : 'respuestas'}
                  </p>
                )}
                {question.distribution && (
                  <ul className="mt-2 space-y-1.5">
                    {Object.entries(question.distribution).map(([label, count]) => (
                      <li key={label} className="grid grid-cols-[minmax(0,8rem)_1fr_2rem] items-center gap-2 text-xs">
                        <span className="truncate text-slate-600">{label}</span>
                        <span className="h-2 rounded-full bg-slate-100 overflow-hidden">
                          <span className="block h-full rounded-full bg-brand-500" style={{ width: `${(count / max) * 100}%` }} />
                        </span>
                        <span className="text-right font-semibold text-slate-800 tnum">{count}</span>
                      </li>
                    ))}
                  </ul>
                )}
                {question.texts &&
                  (question.texts.length === 0 ? (
                    <p className="text-xs text-slate-500 mt-1">Sin respuestas.</p>
                  ) : (
                    <ul className="mt-2 space-y-1.5">
                      {question.texts.map((text, textIndex) => (
                        <li key={textIndex} className="px-3 py-2 rounded-lg bg-slate-50 text-[13px] text-slate-700 whitespace-pre-wrap break-words">
                          {text}
                        </li>
                      ))}
                    </ul>
                  ))}
              </section>
            );
          })}
        </div>
      )}
    </Modal>
  );
};

/* ───────────────────────────── Pestaña ───────────────────────────── */

function gradeCell(activity: TrackedActivity, student: TrackedStudent, gradeScale: number): React.ReactNode {
  const grade = student.grades[activity.id];

  if (activity.type === 'SURVEY') return grade?.done ? <Badge tone="success">Respondida</Badge> : <span className="text-slate-300">—</span>;
  if (activity.type === 'TASK' && grade?.status === 'SUBMITTED') return <Badge tone="info">Por revisar</Badge>;
  if (grade?.grade_percent == null) return <span className="text-slate-300">—</span>;

  return <span className="font-semibold text-slate-900 tnum">{toScale(grade.grade_percent, gradeScale)}</span>;
}

export const TrackingTab: React.FC<{ courseId: string }> = ({ courseId }) => {
  const [activities, setActivities] = useState<TrackedActivity[]>([]);
  const [students, setStudents] = useState<TrackedStudent[]>([]);
  const [submissions, setSubmissions] = useState<ReviewSubmission[]>([]);
  const [gradeScale, setGradeScale] = useState(20);
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [error, setError] = useState('');
  const [dialog, setDialog] = useState<{ kind: 'grade'; submission: ReviewSubmission } | { kind: 'survey'; activity: TrackedActivity } | null>(null);

  const load = useCallback(async () => {
    try {
      const [tracking, list] = await Promise.all([reviewApi.tracking(courseId), reviewApi.submissions(courseId)]);
      setActivities(tracking.activities);
      setStudents(tracking.students);
      setGradeScale(tracking.gradeScale);
      setSubmissions(list.data);
      setState('ready');
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo cargar el seguimiento.'));
      setState('error');
    }
  }, [courseId]);

  useEffect(() => {
    const timer = setTimeout(load, 0);
    return () => clearTimeout(timer);
  }, [load]);

  if (state === 'loading') return <LoadingState />;
  if (state === 'error') {
    return (
      <Card>
        <ErrorState message={error} onRetry={load} />
      </Card>
    );
  }

  const pending = submissions.filter((submission) => submission.status === 'SUBMITTED');
  const surveys = activities.filter((activity) => activity.type === 'SURVEY');

  return (
    <div className="space-y-5">
      {/* Entregas de tareas */}
      <Card>
        <CardHeader
          title="Entregas de tareas"
          text={
            pending.length > 0
              ? `${pending.length} ${pending.length === 1 ? 'entrega espera' : 'entregas esperan'} tu revisión.`
              : 'No hay entregas pendientes de revisar.'
          }
        />
        {submissions.length === 0 ? (
          <EmptyState icon={ClipboardCheck} title="Aún no hay entregas" text="Cuando un alumno entregue una tarea, aparecerá aquí." />
        ) : (
          <Table>
            <THead>
              <Th>Alumno</Th>
              <Th>Tarea</Th>
              <Th>Entregada</Th>
              <Th>Estado</Th>
              <Th />
            </THead>
            <TBody>
              {submissions.map((submission) => (
                <Tr key={submission.id}>
                  <Td>
                    <div className="flex items-center gap-3">
                      <Avatar name={submission.student.name} size="sm" />
                      <span className="font-semibold text-slate-900 whitespace-nowrap">{submission.student.name}</span>
                    </div>
                  </Td>
                  <Td className="min-w-[160px] text-slate-700">{submission.activity_title}</Td>
                  <Td className="whitespace-nowrap text-slate-500 tnum">{formatDate(submission.submitted_at)}</Td>
                  <Td className="whitespace-nowrap">
                    {submission.status === 'GRADED' ? (
                      <Badge tone="success" dot>
                        {toScale(submission.grade_percent ?? 0, gradeScale)} de {gradeScale}
                      </Badge>
                    ) : (
                      <Badge tone="info" dot>
                        Por revisar
                      </Badge>
                    )}
                  </Td>
                  <Td className="text-right">
                    <Button
                      size="sm"
                      variant={submission.status === 'GRADED' ? 'secondary' : 'primary'}
                      onClick={() => setDialog({ kind: 'grade', submission })}
                    >
                      {submission.status === 'GRADED' ? 'Ver' : 'Revisar'}
                    </Button>
                  </Td>
                </Tr>
              ))}
            </TBody>
          </Table>
        )}
      </Card>

      {/* Avance y notas */}
      <Card>
        <CardHeader title="Avance y notas" text={`Notas de 0 a ${gradeScale}. En los cuestionarios cuenta el mejor intento.`} />
        {students.length === 0 ? (
          <EmptyState icon={Users} title="Este curso aún no tiene alumnos" text="Matricúlalos desde la pestaña Alumnos." />
        ) : (
          <Table>
            <THead>
              <Th>Alumno</Th>
              <Th>Avance</Th>
              {activities.map((activity) => (
                <Th key={activity.id}>
                  <span className="block max-w-[140px] truncate" title={`${activity.section} · ${activity.title}`}>
                    {activity.title}
                  </span>
                </Th>
              ))}
            </THead>
            <TBody>
              {students.map((student) => (
                <Tr key={student.enrollment_id}>
                  <Td>
                    <div className="flex items-center gap-3">
                      <Avatar name={student.name} size="sm" />
                      <div className="min-w-0">
                        <p className="font-semibold text-slate-900 whitespace-nowrap">{student.name}</p>
                        <p className="text-xs text-slate-500">{student.email}</p>
                      </div>
                    </div>
                  </Td>
                  <Td>
                    <div className="flex items-center gap-2 min-w-[120px]">
                      <span className="flex-1 h-1.5 rounded-full bg-slate-200 overflow-hidden">
                        <span
                          className={`block h-full rounded-full ${student.progress.percent === 100 ? 'bg-emerald-500' : 'bg-brand-500'}`}
                          style={{ width: `${student.progress.percent}%` }}
                        />
                      </span>
                      <span className="text-xs font-semibold text-slate-800 tnum w-9 text-right">{student.progress.percent}%</span>
                    </div>
                  </Td>
                  {activities.map((activity) => (
                    <Td key={activity.id} className="whitespace-nowrap">
                      {gradeCell(activity, student, gradeScale)}
                    </Td>
                  ))}
                </Tr>
              ))}
            </TBody>
          </Table>
        )}
      </Card>

      {surveys.length > 0 && (
        <Card>
          <CardHeader title="Encuestas" text="Resultados agrupados; las respuestas no se muestran con nombre." />
          <ul className="divide-y divide-slate-100">
            {surveys.map((activity) => (
              <li key={activity.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                <div className="min-w-0">
                  <p className="text-[13px] font-semibold text-slate-900 truncate">{activity.title}</p>
                  <p className="text-xs text-slate-500 truncate">{activity.section}</p>
                </div>
                <Button size="sm" icon={<BarChart3 className="w-3.5 h-3.5" />} onClick={() => setDialog({ kind: 'survey', activity })}>
                  Ver resultados
                </Button>
              </li>
            ))}
          </ul>
        </Card>
      )}

      {dialog?.kind === 'grade' && (
        <GradeModal
          courseId={courseId}
          submission={dialog.submission}
          gradeScale={gradeScale}
          onClose={() => setDialog(null)}
          onDone={async () => {
            await load();
            setDialog(null);
          }}
        />
      )}
      {dialog?.kind === 'survey' && <SurveyResultsModal courseId={courseId} activity={dialog.activity} onClose={() => setDialog(null)} />}
    </div>
  );
};
