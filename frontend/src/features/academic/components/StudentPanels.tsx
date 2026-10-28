import React, { useEffect, useState } from 'react';
import { CalendarClock, CheckCircle2, Download, Hourglass, Paperclip, RotateCcw, Send } from 'lucide-react';
import { apiErrorMessage } from '../../../shared/api/client';
import { Alert, Badge, Button, Field, INPUT_CLASS, LoadingState } from '../../../shared/ui';
import { formatSize } from '../../courses/api';
import { toScale, type SurveyQuestion } from '../api';
import { studentApi, type SurveyState, type TaskState } from '../studentApi';

function formatDate(value: string | null): string {
  if (!value) return '—';
  return new Date(value).toLocaleString('es-PE', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
}

/* ───────────────────────────── Tarea ───────────────────────────── */

export const TaskPanel: React.FC<{
  courseId: string;
  activityId: string;
  allowText: boolean;
  allowFile: boolean;
  state: TaskState;
  gradeScale: number;
  onChanged: () => Promise<void>;
}> = ({ courseId, activityId, allowText, allowFile, state, gradeScale, onChanged }) => {
  const { submission } = state;
  const [editing, setEditing] = useState(false);
  const [body, setBody] = useState(submission?.body ?? '');
  const [file, setFile] = useState<File | null>(null);
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const graded = submission?.status === 'GRADED';
  const returned = submission?.status === 'RETURNED';
  const showForm = state.can_submit && (!submission || (editing && !graded));

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setProcessing(true);
    setError(null);
    try {
      await studentApi.submitTask(courseId, activityId, allowText ? body : '', allowFile ? file : null);
      await onChanged();
      setEditing(false);
      setFile(null);
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo entregar la tarea.'));
    } finally {
      setProcessing(false);
    }
  };

  const download = async () => {
    setError(null);
    try {
      await studentApi.downloadSubmission(courseId, activityId, submission?.file?.name ?? 'entrega');
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo descargar el archivo.'));
    }
  };

  return (
    <div className="space-y-4">
      {error && <Alert>{error}</Alert>}

      {state.due_at && !graded && (
        <div
          className={`flex items-center gap-2.5 px-3.5 py-2.5 rounded-xl border text-[13px] ${
            state.past_due ? 'bg-amber-50 border-amber-200 text-amber-900' : 'bg-slate-50 border-slate-200 text-slate-700'
          }`}
          role="status"
        >
          <CalendarClock className={`w-4 h-4 shrink-0 ${state.past_due ? 'text-amber-600' : 'text-slate-500'}`} />
          <p>
            {state.past_due ? 'El plazo venció el ' : 'Entrega hasta el '}
            <span className="font-semibold">{formatDate(state.due_at)}</span>.
            {state.past_due && !returned && (state.can_submit ? ' Aún puedes entregar: quedará marcada como tardía.' : ' Esta tarea ya no recibe entregas.')}
          </p>
        </div>
      )}

      {submission && !showForm && (
        <>
          {graded ? (
            <div className="flex flex-wrap items-center gap-4 p-4 rounded-xl border bg-emerald-50 border-emerald-200" role="status">
              <CheckCircle2 className="w-8 h-8 text-emerald-600 shrink-0" />
              <div className="flex-1 min-w-0">
                <p className="text-sm font-semibold text-emerald-900">Tarea calificada</p>
                <p className="text-[13px] text-emerald-800">Revisada el {formatDate(submission.graded_at)}</p>
              </div>
              <p className="text-3xl font-bold text-emerald-700 tnum">
                {toScale(submission.grade_percent ?? 0, gradeScale)}
                <span className="text-base font-semibold opacity-70"> / {gradeScale}</span>
              </p>
            </div>
          ) : returned ? (
            <div className="flex items-center gap-3 p-4 rounded-xl border bg-amber-50 border-amber-200 text-amber-900" role="status">
              <RotateCcw className="w-6 h-6 text-amber-600 shrink-0" />
              <p className="text-[13px]">
                <span className="block font-semibold">Tu docente te devolvió la tarea el {formatDate(submission.returned_at)}</span>
                Revisa su comentario y vuelve a entregarla.
              </p>
            </div>
          ) : (
            <div className="flex items-center gap-3 p-4 rounded-xl border bg-accent-50 border-accent-200 text-accent-900" role="status">
              <Hourglass className="w-6 h-6 text-accent-600 shrink-0" />
              <p className="text-[13px]">
                <span className="block font-semibold">Entregada el {formatDate(submission.submitted_at)}</span>
                Tu docente aún no la revisa{state.can_submit ? '; mientras tanto puedes reemplazarla.' : '.'}
              </p>
            </div>
          )}

          {submission.feedback && (
            <div className="p-4 rounded-xl border border-slate-200">
              <p className="text-xs font-semibold text-slate-500 mb-1">Comentario de tu docente</p>
              <p className="text-[13px] leading-relaxed text-slate-700 whitespace-pre-wrap break-words">{submission.feedback}</p>
            </div>
          )}

          <div className="p-4 rounded-xl border border-slate-200 space-y-3">
            <div className="flex flex-wrap items-center gap-2">
              <p className="text-xs font-semibold text-slate-500">Tu entrega</p>
              {submission.is_late && <Badge tone="warning">Fuera de plazo</Badge>}
            </div>
            {submission.body && <p className="text-[13px] leading-relaxed text-slate-700 whitespace-pre-wrap break-words">{submission.body}</p>}
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

          {state.can_submit && (
            <Button variant={returned ? 'primary' : 'secondary'} onClick={() => setEditing(true)}>
              {returned ? 'Volver a entregar' : 'Reemplazar mi entrega'}
            </Button>
          )}
        </>
      )}

      {showForm && (
        <form onSubmit={submit} className="space-y-4">
          {allowText && (
            <Field label={allowFile ? 'Tu respuesta (opcional si adjuntas un archivo)' : 'Tu respuesta'}>
              <textarea
                className={INPUT_CLASS}
                rows={7}
                maxLength={50000}
                required={!allowFile}
                value={body}
                onChange={(event) => setBody(event.target.value)}
              />
            </Field>
          )}
          {allowFile && (
            <Field
              label={allowText ? 'Archivo (opcional si escribes tu respuesta)' : 'Archivo'}
              hint={`PDF, Word, Excel, PowerPoint, imagen o ZIP.${submission?.file ? ` Si no eliges otro, se conserva ${submission.file.name}.` : ''}`}
            >
              <input
                type="file"
                required={!allowText && !submission?.file}
                accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.png,.jpg,.jpeg,.zip"
                onChange={(event) => setFile(event.target.files?.[0] ?? null)}
                className="block w-full text-[13px] text-slate-600 file:mr-3 file:h-9 file:px-3.5 file:rounded-lg file:border file:border-solid file:border-slate-300 file:bg-white file:text-[13px] file:font-semibold file:text-slate-700 file:cursor-pointer hover:file:bg-slate-50"
              />
            </Field>
          )}
          <div className="flex flex-wrap gap-2">
            <Button variant="primary" type="submit" processing={processing} icon={<Send className="w-4 h-4" />}>
              {returned ? 'Volver a entregar' : submission ? 'Reemplazar entrega' : 'Entregar tarea'}
            </Button>
            {submission && <Button onClick={() => setEditing(false)}>Cancelar</Button>}
          </div>
        </form>
      )}
    </div>
  );
};

/* ───────────────────────────── Encuesta ───────────────────────────── */

export const SurveyPanel: React.FC<{
  courseId: string;
  activityId: string;
  state: SurveyState;
  onChanged: () => Promise<void>;
}> = ({ courseId, activityId, state, onChanged }) => {
  const [questions, setQuestions] = useState<Required<SurveyQuestion>[] | null>(null);
  const [answers, setAnswers] = useState<Record<string, string | number>>({});
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (state.answered) return;
    let active = true;
    studentApi
      .surveyQuestions(courseId, activityId)
      .then((result) => active && setQuestions(result))
      .catch((err) => active && setError(apiErrorMessage(err, 'No se pudo cargar la encuesta.')));
    return () => {
      active = false;
    };
  }, [courseId, activityId, state.answered]);

  if (state.answered) {
    return (
      <div className="flex items-center gap-3 p-4 rounded-xl border bg-emerald-50 border-emerald-200 text-emerald-900" role="status">
        <CheckCircle2 className="w-6 h-6 text-emerald-600 shrink-0" />
        <p className="text-[13px]">
          <span className="font-semibold">Ya respondiste esta encuesta.</span> Gracias por tu opinión.
        </p>
      </div>
    );
  }

  if (!questions) return error ? <Alert>{error}</Alert> : <LoadingState />;

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setProcessing(true);
    setError(null);
    try {
      await studentApi.answerSurvey(courseId, activityId, answers);
      await onChanged();
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo enviar la encuesta.'));
      setProcessing(false);
    }
  };

  const set = (id: string, value: string | number) => setAnswers((current) => ({ ...current, [id]: value }));

  return (
    <form onSubmit={submit} className="space-y-5">
      {error && <Alert>{error}</Alert>}
      <p className="text-xs text-slate-500">Tus respuestas se muestran a la academia de forma agrupada, sin tu nombre.</p>

      <ol className="space-y-5">
        {questions.map((question, index) => (
          <li key={question.id}>
            <fieldset>
              <legend className="text-sm font-semibold text-slate-900 mb-2.5">
                {index + 1}. {question.text}
                {!question.required && <span className="ml-2 text-xs font-medium text-slate-500">opcional</span>}
              </legend>

              {question.type === 'SCALE' && (
                <div>
                  <div className="flex gap-2">
                    {[1, 2, 3, 4, 5].map((value) => (
                      <label
                        key={value}
                        className="flex-1 max-w-16 h-11 flex items-center justify-center rounded-xl border border-slate-200 text-sm font-semibold text-slate-700 cursor-pointer hover:border-slate-300 has-[:checked]:border-brand-500 has-[:checked]:bg-brand-600 has-[:checked]:text-white tnum"
                      >
                        <input
                          type="radio"
                          name={question.id}
                          className="sr-only"
                          required={question.required}
                          checked={answers[question.id] === value}
                          onChange={() => set(question.id, value)}
                        />
                        {value}
                      </label>
                    ))}
                  </div>
                  <p className="flex justify-between max-w-[21.5rem] text-xs text-slate-500 mt-1">
                    <span>Muy poco</span>
                    <span>Mucho</span>
                  </p>
                </div>
              )}

              {question.type === 'CHOICE' && (
                <div className="space-y-2">
                  {question.options.map((option) => (
                    <label
                      key={option}
                      className="flex items-center gap-3 px-3.5 py-2.5 rounded-xl border border-slate-200 text-[13px] text-slate-800 cursor-pointer hover:border-slate-300 has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50/60"
                    >
                      <input
                        type="radio"
                        name={question.id}
                        className="w-4 h-4 shrink-0 accent-brand-600"
                        required={question.required}
                        checked={answers[question.id] === option}
                        onChange={() => set(question.id, option)}
                      />
                      {option}
                    </label>
                  ))}
                </div>
              )}

              {question.type === 'TEXT' && (
                <textarea
                  className={INPUT_CLASS}
                  rows={3}
                  maxLength={2000}
                  required={question.required}
                  aria-label={question.text}
                  value={(answers[question.id] as string) ?? ''}
                  onChange={(event) => set(question.id, event.target.value)}
                />
              )}
            </fieldset>
          </li>
        ))}
      </ol>

      <Button variant="primary" type="submit" processing={processing} icon={<Send className="w-4 h-4" />}>
        Enviar encuesta
      </Button>
    </form>
  );
};
