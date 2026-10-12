import React, { useCallback, useEffect, useRef, useState } from 'react';
import { ArrowDown, ArrowUp, CheckCircle2, CircleAlert, Clock, Play, RotateCcw, XCircle } from 'lucide-react';
import { apiError, apiErrorMessage } from '../../../shared/api/client';
import { Alert, Badge, Button, CHECKBOX_CLASS, INPUT_CLASS, LoadingState } from '../../../shared/ui';
import { toScale, type QuestionConfig } from '../api';
import {
  studentApi,
  type Answer,
  type AttemptQuestion,
  type AttemptResult,
  type OpenAttempt,
  type QuizState,
  type ResultQuestion,
} from '../studentApi';

const RADIO_CLASS = 'w-4 h-4 shrink-0 accent-brand-600 cursor-pointer';
const OPTION_CLASS =
  'flex items-center gap-3 px-3.5 py-2.5 rounded-xl border border-slate-200 text-[13px] text-slate-800 cursor-pointer hover:border-slate-300 has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50/60';

function formatDate(value: string | null): string {
  if (!value) return '—';
  return new Date(value).toLocaleString('es-PE', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
}

/* ───────────────────────────── Responder una pregunta ───────────────────────────── */

const QuestionInput: React.FC<{ question: AttemptQuestion; value: Answer; onChange: (value: Answer) => void }> = ({
  question,
  value,
  onChange,
}) => {
  const { config, type, id } = question;

  if (type === 'SINGLE') {
    return (
      <div className="space-y-2">
        {config.options?.map((option) => (
          <label key={option.id} className={OPTION_CLASS}>
            <input type="radio" name={id} className={RADIO_CLASS} checked={value === option.id} onChange={() => onChange(option.id)} />
            {option.text}
          </label>
        ))}
      </div>
    );
  }

  if (type === 'MULTIPLE') {
    const selected = Array.isArray(value) ? value : [];
    return (
      <div className="space-y-2">
        <p className="text-xs text-slate-500">Puede haber más de una correcta.</p>
        {config.options?.map((option) => (
          <label key={option.id} className={OPTION_CLASS}>
            <input
              type="checkbox"
              className={CHECKBOX_CLASS}
              checked={selected.includes(option.id)}
              onChange={(event) => onChange(event.target.checked ? [...selected, option.id] : selected.filter((item) => item !== option.id))}
            />
            {option.text}
          </label>
        ))}
      </div>
    );
  }

  if (type === 'TRUE_FALSE') {
    return (
      <div className="grid grid-cols-2 gap-2">
        {[true, false].map((option) => (
          <label key={String(option)} className={OPTION_CLASS}>
            <input type="radio" name={id} className={RADIO_CLASS} checked={value === option} onChange={() => onChange(option)} />
            {option ? 'Verdadero' : 'Falso'}
          </label>
        ))}
      </div>
    );
  }

  if (type === 'SHORT_TEXT' || type === 'NUMERIC') {
    return (
      <input
        className={`${INPUT_CLASS} max-w-sm`}
        inputMode={type === 'NUMERIC' ? 'decimal' : undefined}
        placeholder={type === 'NUMERIC' ? 'Escribe un número' : 'Escribe tu respuesta'}
        aria-label="Tu respuesta"
        maxLength={200}
        value={typeof value === 'string' ? value : ''}
        onChange={(event) => onChange(event.target.value)}
      />
    );
  }

  if (type === 'MATCHING') {
    const pairs = value && typeof value === 'object' && !Array.isArray(value) ? value : {};
    return (
      <div className="space-y-2">
        {config.left?.map((left) => (
          <div key={left.id} className="grid grid-cols-1 sm:grid-cols-2 gap-2 items-center">
            <span className="text-[13px] font-medium text-slate-800">{left.text}</span>
            <select
              className={INPUT_CLASS}
              aria-label={`Pareja de ${left.text}`}
              value={pairs[left.id] ?? ''}
              onChange={(event) => onChange({ ...pairs, [left.id]: event.target.value })}
            >
              <option value="">Elige su pareja…</option>
              {config.right?.map((right) => (
                <option key={right.id} value={right.id}>
                  {right.text}
                </option>
              ))}
            </select>
          </div>
        ))}
      </div>
    );
  }

  // ORDERING: la respuesta es la lista de ids en el orden que deja el alumno
  const order = Array.isArray(value) ? value : [];
  const texts = new Map(config.items?.map((item) => [item.id, item.text]));
  const move = (index: number, offset: number) => {
    const next = [...order];
    const [item] = next.splice(index, 1);
    next.splice(index + offset, 0, item);
    onChange(next);
  };

  return (
    <ol className="space-y-2">
      {order.map((itemId, index) => (
        <li key={itemId} className="flex items-center gap-2 px-3 py-2 rounded-xl border border-slate-200 text-[13px] text-slate-800">
          <span className="w-5 shrink-0 text-xs font-semibold text-slate-500 tnum">{index + 1}</span>
          <span className="flex-1 min-w-0">{texts.get(itemId)}</span>
          <button
            type="button"
            aria-label="Subir"
            disabled={index === 0}
            onClick={() => move(index, -1)}
            className="p-1.5 rounded-lg text-slate-500 hover:text-brand-700 hover:bg-brand-50 cursor-pointer disabled:opacity-30 disabled:cursor-not-allowed"
          >
            <ArrowUp className="w-4 h-4" />
          </button>
          <button
            type="button"
            aria-label="Bajar"
            disabled={index === order.length - 1}
            onClick={() => move(index, 1)}
            className="p-1.5 rounded-lg text-slate-500 hover:text-brand-700 hover:bg-brand-50 cursor-pointer disabled:opacity-30 disabled:cursor-not-allowed"
          >
            <ArrowDown className="w-4 h-4" />
          </button>
        </li>
      ))}
    </ol>
  );
};

/* ───────────────────────────── Textos de una respuesta ───────────────────────────── */

function describeAnswer(question: ResultQuestion): string {
  const { answer, config, type } = question;
  const empty = answer === null || answer === '' || (Array.isArray(answer) && answer.length === 0);
  if (empty) return 'Sin responder';

  const optionText = (optionId: string) => config.options?.find((option) => option.id === optionId)?.text ?? '—';

  switch (type) {
    case 'SINGLE':
      return optionText(answer as string);
    case 'MULTIPLE':
      return (answer as string[]).map(optionText).join(', ');
    case 'TRUE_FALSE':
      return answer ? 'Verdadero' : 'Falso';
    case 'MATCHING':
      return (config.left ?? [])
        .map((left) => {
          const chosen = (answer as Record<string, string>)[left.id];
          return `${left.text} → ${config.right?.find((right) => right.id === chosen)?.text ?? 'sin elegir'}`;
        })
        .join(' · ');
    case 'ORDERING':
      return (answer as string[]).map((itemId) => config.items?.find((item) => item.id === itemId)?.text ?? '—').join(' → ');
    default:
      return String(answer);
  }
}

function describeCorrect(type: ResultQuestion['type'], correct: QuestionConfig): string {
  switch (type) {
    case 'SINGLE':
    case 'MULTIPLE':
      return (correct.options ?? [])
        .filter((option) => option.correct)
        .map((option) => option.text)
        .join(', ');
    case 'TRUE_FALSE':
      return correct.answer ? 'Verdadero' : 'Falso';
    case 'SHORT_TEXT':
      return (correct.answers ?? []).join(' / ');
    case 'NUMERIC':
      return correct.tolerance ? `${correct.answer} (± ${correct.tolerance})` : String(correct.answer);
    case 'MATCHING':
      return (correct.pairs ?? []).map((pair) => `${pair.left} → ${pair.right}`).join(' · ');
    case 'ORDERING':
      return (correct.items ?? []).map((item) => item.text).join(' → ');
  }
}

/* ───────────────────────────── Resultado ───────────────────────────── */

// Pregunta por pregunta: lo que se respondió, su puntaje y, si ya se puede ver, la respuesta correcta.
// La usa el alumno en su resultado y quien revisa en el detalle de un intento.
export const AttemptQuestions: React.FC<{ result: AttemptResult; answerLabel?: string }> = ({ result, answerLabel = 'Tu respuesta' }) => (
  <ol className="space-y-3">
    {result.questions.map((question, index) => {
      const tone = question.score >= 1 ? 'success' : question.score > 0 ? 'warning' : 'danger';
      const label = question.score >= 1 ? 'Correcta' : question.score > 0 ? 'Parcial' : 'Incorrecta';
      return (
        <li key={question.id} className="p-4 rounded-xl border border-slate-200">
          <div className="flex flex-wrap items-start justify-between gap-2 mb-2">
            <p className="text-[13px] font-semibold text-slate-900 flex-1 min-w-0">
              {index + 1}. {question.statement}
            </p>
            <Badge tone={tone} dot>
              {label} · {Math.round(question.score * question.points * 100) / 100} de {question.points} pts
            </Badge>
          </div>
          <p className="text-[13px] text-slate-600">
            <span className="font-medium text-slate-700">{answerLabel}:</span> {describeAnswer(question)}
          </p>
          {question.correct && question.score < 1 && (
            <p className="text-[13px] text-emerald-800 mt-1">
              <span className="font-medium">Respuesta correcta:</span> {describeCorrect(question.type, question.correct)}
            </p>
          )}
          {question.explanation && result.answers_revealed && (
            <p className="text-[13px] text-slate-500 mt-2 pt-2 border-t border-slate-100">{question.explanation}</p>
          )}
        </li>
      );
    })}
  </ol>
);

const ResultView: React.FC<{ result: AttemptResult; gradeScale: number; onBack: () => void }> = ({ result, gradeScale, onBack }) => (
  <div className="space-y-5">
    <div
      className={`flex flex-wrap items-center gap-4 p-4 rounded-xl border ${
        result.passed ? 'bg-emerald-50 border-emerald-200' : 'bg-rose-50 border-rose-200'
      }`}
      role="status"
    >
      {result.passed ? <CheckCircle2 className="w-9 h-9 text-emerald-600 shrink-0" /> : <XCircle className="w-9 h-9 text-rose-600 shrink-0" />}
      <div className="flex-1 min-w-0">
        <p className={`text-sm font-semibold ${result.passed ? 'text-emerald-900' : 'text-rose-900'}`}>
          {result.status === 'EXPIRED'
            ? result.passed
              ? 'Se acabó el tiempo, pero aprobaste'
              : 'Se acabó el tiempo'
            : result.passed
              ? 'Aprobaste'
              : 'No alcanzaste la nota mínima'}
        </p>
        <p className={`text-[13px] ${result.passed ? 'text-emerald-800' : 'text-rose-800'}`}>
          Intento {result.number} · se aprueba con {toScale(result.passing_percent, gradeScale)}
          {result.status === 'EXPIRED' && ' · se calificó lo que habías guardado'}
        </p>
      </div>
      <p className={`text-3xl font-bold tnum ${result.passed ? 'text-emerald-700' : 'text-rose-700'}`}>
        {toScale(result.score_percent, gradeScale)}
        <span className="text-base font-semibold opacity-70"> / {gradeScale}</span>
      </p>
    </div>

    {!result.answers_revealed && (
      <Alert tone="info">Las respuestas correctas se muestran cuando apruebas o cuando ya no te quedan intentos.</Alert>
    )}

    <AttemptQuestions result={result} />

    <Button onClick={onBack}>Volver al cuestionario</Button>
  </div>
);

/* ───────────────────────────── Intento en curso ───────────────────────────── */

function initialAnswers(attempt: OpenAttempt): Record<string, Answer> {
  // Ordenar parte del orden recibido: si el alumno no mueve nada, esa es su respuesta
  const blank = Object.fromEntries(
    attempt.questions.map((question) => [question.id, question.type === 'ORDERING' ? (question.config.items ?? []).map((item) => item.id) : null]),
  );

  // Al retomar un intento, lo que ya había marcado
  return { ...blank, ...attempt.answers };
}

// Espera a que deje de responder antes de guardar
const AUTOSAVE_MS = 1200;

const TakingView: React.FC<{ attempt: OpenAttempt; onFinished: (result: AttemptResult | null) => void }> = ({ attempt, onFinished }) => {
  const [answers, setAnswers] = useState<Record<string, Answer>>(() => initialAnswers(attempt));
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [remaining, setRemaining] = useState<number | null>(null);
  const latest = useRef(answers);
  const sent = useRef(false);

  useEffect(() => {
    latest.current = answers;
  }, [answers]);

  // Guardado automático: si cierra la pestaña o se le va la conexión, retoma donde quedó
  const touched = useRef(false);
  useEffect(() => {
    if (!touched.current) {
      touched.current = true;
      return;
    }

    const timer = setTimeout(() => {
      // Un fallo aquí no interrumpe al alumno: las respuestas siguen en pantalla y viajan al enviar
      if (!sent.current) studentApi.saveAttemptDraft(attempt.id, answers).catch(() => undefined);
    }, AUTOSAVE_MS);

    return () => clearTimeout(timer);
  }, [answers, attempt.id]);

  const submit = useCallback(async () => {
    if (sent.current) return;
    sent.current = true;
    setProcessing(true);
    setError(null);
    try {
      onFinished(await studentApi.submitAttempt(attempt.id, latest.current));
    } catch (err) {
      // El servidor ya cerró el intento (tiempo vencido): se vuelve a la ficha con el estado al día
      if (apiError(err)?.code === 'ATTEMPT_CLOSED') {
        onFinished(null);
        return;
      }
      sent.current = false;
      setError(apiErrorMessage(err, 'No se pudo enviar. Revisa tu conexión e inténtalo de nuevo.'));
      setProcessing(false);
    }
  }, [attempt.id, onFinished]);

  // Cuenta regresiva según el reloj del servidor; al llegar a cero se envía solo
  useEffect(() => {
    if (!attempt.expires_at) return;

    const offset = new Date(attempt.server_time).getTime() - Date.now();
    const deadline = new Date(attempt.expires_at).getTime();
    const tick = () => {
      const seconds = Math.max(0, Math.round((deadline - (Date.now() + offset)) / 1000));
      setRemaining(seconds);
      if (seconds === 0) submit();
    };

    const first = setTimeout(tick, 0);
    const timer = setInterval(tick, 1000);
    return () => {
      clearTimeout(first);
      clearInterval(timer);
    };
  }, [attempt.expires_at, attempt.server_time, submit]);

  const answered = attempt.questions.filter((question) => {
    const value = answers[question.id];
    return value !== null && value !== '' && !(Array.isArray(value) && value.length === 0);
  }).length;

  return (
    <form
      onSubmit={(event) => {
        event.preventDefault();
        submit();
      }}
      className="space-y-5"
    >
      <div className="sticky top-0 z-10 -mx-1 px-1 py-2 bg-white/95 backdrop-blur flex flex-wrap items-center justify-between gap-2 border-b border-slate-200/80">
        <span className="text-[13px] text-slate-600 tnum">
          Intento {attempt.number} · {answered} de {attempt.questions.length} respondidas
        </span>
        {remaining !== null && (
          <span
            className={`inline-flex items-center gap-1.5 text-[13px] font-semibold tnum ${remaining <= 60 ? 'text-rose-700' : 'text-slate-800'}`}
            role="timer"
            aria-live={remaining <= 60 ? 'polite' : 'off'}
          >
            <Clock className="w-4 h-4" />
            {Math.floor(remaining / 60)}:{String(remaining % 60).padStart(2, '0')}
          </span>
        )}
      </div>

      {error && <Alert>{error}</Alert>}

      <ol className="space-y-5">
        {attempt.questions.map((question, index) => (
          <li key={question.id}>
            <fieldset>
              <legend className="text-sm font-semibold text-slate-900 mb-2.5">
                {index + 1}. {question.statement}
                <span className="ml-2 text-xs font-medium text-slate-500">
                  {question.points} {question.points === 1 ? 'pt' : 'pts'}
                </span>
              </legend>
              <QuestionInput
                question={question}
                value={answers[question.id]}
                onChange={(value) => setAnswers((current) => ({ ...current, [question.id]: value }))}
              />
            </fieldset>
          </li>
        ))}
      </ol>

      <div className="flex flex-wrap items-center gap-3 pt-2">
        <Button variant="primary" type="submit" processing={processing}>
          Enviar respuestas
        </Button>
        {answered < attempt.questions.length && (
          <span className="inline-flex items-center gap-1.5 text-xs text-amber-700">
            <CircleAlert className="w-4 h-4" /> Te faltan {attempt.questions.length - answered} por responder.
          </span>
        )}
      </div>
    </form>
  );
};

/* ───────────────────────────── Ficha del cuestionario ───────────────────────────── */

export const QuizPanel: React.FC<{
  courseId: string;
  activityId: string;
  state: QuizState;
  gradeScale: number;
  onChanged: () => Promise<void>;
}> = ({ courseId, activityId, state, gradeScale, onChanged }) => {
  const [attempt, setAttempt] = useState<OpenAttempt | null>(null);
  const [result, setResult] = useState<AttemptResult | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const start = async () => {
    setLoading(true);
    setError(null);
    try {
      setAttempt(await studentApi.startAttempt(courseId, activityId));
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo abrir el intento.'));
      await onChanged();
    } finally {
      setLoading(false);
    }
  };

  const review = async (attemptId: string) => {
    setLoading(true);
    setError(null);
    try {
      setResult((await studentApi.attempt(attemptId)) as AttemptResult);
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo cargar el intento.'));
    } finally {
      setLoading(false);
    }
  };

  if (loading) return <LoadingState />;

  if (attempt) {
    return (
      <TakingView
        attempt={attempt}
        onFinished={async (finished) => {
          await onChanged();
          setAttempt(null);
          setResult(finished);
        }}
      />
    );
  }

  if (result) return <ResultView result={result} gradeScale={gradeScale} onBack={() => setResult(null)} />;

  const exhausted = state.attempts_left === 0;

  return (
    <div className="space-y-4">
      {error && <Alert>{error}</Alert>}

      {state.attempts.length > 0 && (
        <div
          className={`flex flex-wrap items-center gap-3 p-4 rounded-xl border ${
            state.passed ? 'bg-emerald-50 border-emerald-200 text-emerald-900' : 'bg-slate-50 border-slate-200 text-slate-800'
          }`}
        >
          {state.passed ? <CheckCircle2 className="w-6 h-6 text-emerald-600 shrink-0" /> : <CircleAlert className="w-6 h-6 text-slate-500 shrink-0" />}
          <p className="flex-1 min-w-0 text-[13px]">
            <span className="font-semibold">{state.passed ? 'Aprobado.' : 'Aún no aprobado.'}</span> Tu mejor nota es{' '}
            <span className="font-semibold tnum">
              {toScale(state.best_percent ?? 0, gradeScale)} de {gradeScale}
            </span>
            .
          </p>
        </div>
      )}

      <div className="flex flex-wrap items-center gap-3">
        <Button
          variant="primary"
          icon={state.open_attempt_id ? <Play className="w-4 h-4" /> : state.attempts.length > 0 ? <RotateCcw className="w-4 h-4" /> : <Play className="w-4 h-4" />}
          disabled={exhausted && !state.open_attempt_id}
          onClick={start}
        >
          {state.open_attempt_id ? 'Continuar el intento' : state.attempts.length > 0 ? 'Intentar de nuevo' : 'Comenzar'}
        </Button>
        <span className="text-xs text-slate-500">
          {state.attempts_left === null
            ? 'Intentos ilimitados.'
            : exhausted
              ? 'Ya usaste todos tus intentos.'
              : `Te ${state.attempts_left === 1 ? 'queda 1 intento' : `quedan ${state.attempts_left} intentos`}.`}{' '}
          {state.extra_attempts > 0 && !exhausted && 'Tu docente te dio un intento adicional. '}
          Cuenta el mejor.
        </span>
      </div>

      {state.attempts.length > 0 && (
        <div>
          <h3 className="text-[13px] font-semibold text-slate-900 mb-2">Tus intentos</h3>
          <ul className="rounded-xl border border-slate-200 divide-y divide-slate-100">
            {state.attempts.map((item) => (
              <li key={item.id} className="flex flex-wrap items-center gap-3 px-3.5 py-2.5 text-[13px]">
                <span className="font-medium text-slate-900">Intento {item.number}</span>
                <Badge tone={item.passed ? 'success' : item.status === 'EXPIRED' ? 'warning' : 'neutral'} dot>
                  {item.status === 'EXPIRED' ? 'Tiempo vencido' : item.passed ? 'Aprobado' : 'No aprobado'}
                </Badge>
                <span className="font-semibold text-slate-800 tnum">
                  {toScale(item.score_percent, gradeScale)} / {gradeScale}
                </span>
                <span className="text-slate-500 flex-1 min-w-0">{formatDate(item.submitted_at)}</span>
                <Button size="sm" onClick={() => review(item.id)}>
                  Ver detalle
                </Button>
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
};
