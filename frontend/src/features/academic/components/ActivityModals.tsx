import React, { useCallback, useEffect, useState } from 'react';
import { ArrowDown, ArrowUp, Plus, Search, Trash2, X } from 'lucide-react';
import { Link } from 'react-router-dom';
import { apiErrorMessage } from '../../../shared/api/client';
import { Alert, Badge, Button, CHECKBOX_CLASS, EmptyState, Field, INPUT_CLASS, LoadingState, Modal } from '../../../shared/ui';
import {
  academicApi,
  ACTIVITY_HINT,
  ACTIVITY_LABEL,
  QUESTION_LABEL,
  SURVEY_LABEL,
  toPercent,
  toScale,
  type Activity,
  type ActivityInput,
  type ActivityType,
  type Question,
  type QuestionBank,
  type QuizItem,
  type SurveyQuestion,
  type SurveyQuestionType,
} from '../api';
import { ACTIVITY_ICON } from '../icons';

const ACTIVITY_TYPES: ActivityType[] = ['QUIZ', 'TASK', 'SURVEY'];
const SURVEY_TYPES: SurveyQuestionType[] = ['SCALE', 'CHOICE', 'TEXT'];

/* ───────────────────────────── Encuesta: sus preguntas ───────────────────────────── */

const SurveyQuestions: React.FC<{ value: SurveyQuestion[]; onChange: (value: SurveyQuestion[]) => void }> = ({ value, onChange }) => {
  const update = (index: number, patch: Partial<SurveyQuestion>) =>
    onChange(value.map((question, i) => (i === index ? { ...question, ...patch } : question)));

  return (
    <fieldset>
      <legend className="block text-[13px] font-medium text-slate-700 mb-1.5">Preguntas de la encuesta</legend>
      <div className="space-y-3">
        {value.map((question, index) => (
          <div key={index} className="p-3 rounded-xl border border-slate-200 bg-slate-50/50 space-y-2">
            <div className="flex items-start gap-2">
              <span className="w-6 h-9 shrink-0 flex items-center justify-center text-xs font-semibold text-slate-500 tnum">{index + 1}</span>
              <input
                className={INPUT_CLASS}
                required
                maxLength={500}
                placeholder="Pregunta"
                aria-label={`Pregunta ${index + 1}`}
                value={question.text}
                onChange={(event) => update(index, { text: event.target.value })}
              />
              <button
                type="button"
                aria-label="Quitar pregunta"
                title="Quitar pregunta"
                onClick={() => onChange(value.filter((_, i) => i !== index))}
                className="p-2 shrink-0 rounded-lg text-slate-500 hover:text-rose-600 hover:bg-rose-50 cursor-pointer"
              >
                <Trash2 className="w-4 h-4" />
              </button>
            </div>
            <div className="flex flex-wrap items-center gap-3 pl-8">
              <select
                className={`${INPUT_CLASS} !w-48`}
                aria-label="Tipo de respuesta"
                value={question.type}
                onChange={(event) => {
                  const type = event.target.value as SurveyQuestionType;
                  update(index, { type, options: type === 'CHOICE' ? (question.options ?? ['', '']) : undefined });
                }}
              >
                {SURVEY_TYPES.map((type) => (
                  <option key={type} value={type}>
                    {SURVEY_LABEL[type]}
                  </option>
                ))}
              </select>
              <label className="inline-flex items-center gap-2 text-[13px] text-slate-600 cursor-pointer">
                <input
                  type="checkbox"
                  className={CHECKBOX_CLASS}
                  checked={question.required}
                  onChange={(event) => update(index, { required: event.target.checked })}
                />
                Obligatoria
              </label>
            </div>
            {question.type === 'CHOICE' && (
              <div className="pl-8 space-y-2">
                {(question.options ?? []).map((option, optionIndex) => (
                  <div key={optionIndex} className="flex items-center gap-2">
                    <input
                      className={INPUT_CLASS}
                      required
                      maxLength={200}
                      placeholder={`Opción ${optionIndex + 1}`}
                      value={option}
                      onChange={(event) =>
                        update(index, { options: (question.options ?? []).map((item, i) => (i === optionIndex ? event.target.value : item)) })
                      }
                    />
                    <button
                      type="button"
                      aria-label="Quitar opción"
                      disabled={(question.options ?? []).length <= 2}
                      onClick={() => update(index, { options: (question.options ?? []).filter((_, i) => i !== optionIndex) })}
                      className="p-1.5 shrink-0 rounded-lg text-slate-500 hover:text-rose-600 hover:bg-rose-50 cursor-pointer disabled:opacity-30 disabled:cursor-not-allowed"
                    >
                      <X className="w-4 h-4" />
                    </button>
                  </div>
                ))}
                <Button
                  size="sm"
                  icon={<Plus className="w-3.5 h-3.5" />}
                  disabled={(question.options ?? []).length >= 10}
                  onClick={() => update(index, { options: [...(question.options ?? []), ''] })}
                >
                  Agregar opción
                </Button>
              </div>
            )}
          </div>
        ))}
      </div>
      <div className="mt-2.5">
        <Button
          size="sm"
          icon={<Plus className="w-3.5 h-3.5" />}
          disabled={value.length >= 50}
          onClick={() => onChange([...value, { type: 'SCALE', text: '', required: true }])}
        >
          Agregar pregunta
        </Button>
      </div>
    </fieldset>
  );
};

/* ───────────────────────────── Alta y edición de una actividad ───────────────────────────── */

export const ActivityModal: React.FC<{
  activity: Activity | null;
  gradeScale: number;
  onClose: () => void;
  onSubmit: (input: ActivityInput) => Promise<void>;
}> = ({ activity, gradeScale, onClose, onSubmit }) => {
  const [type, setType] = useState<ActivityType>(activity?.type ?? 'QUIZ');
  const [title, setTitle] = useState(activity?.title ?? '');
  const [description, setDescription] = useState(activity?.description ?? '');
  // La nota mínima se edita en la escala de la academia y se guarda en porcentaje
  const [passing, setPassing] = useState(String(toScale(activity?.passing_percent ?? 55, gradeScale)));
  const [limited, setLimited] = useState(activity ? activity.max_attempts != null : true);
  const [maxAttempts, setMaxAttempts] = useState(String(activity?.max_attempts ?? 3));
  const [timeLimit, setTimeLimit] = useState(activity?.time_limit_minutes ? String(activity.time_limit_minutes) : '');
  const [shuffle, setShuffle] = useState(activity?.shuffle ?? false);
  const [allowText, setAllowText] = useState(activity?.allow_text ?? true);
  const [allowFile, setAllowFile] = useState(activity?.allow_file ?? true);
  const [questions, setQuestions] = useState<SurveyQuestion[]>(
    activity?.type === 'SURVEY' ? (activity.questions as SurveyQuestion[]) : [{ type: 'SCALE', text: '', required: true }],
  );
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();

    if (type === 'TASK' && !allowText && !allowFile) {
      setError('La tarea debe aceptar un texto, un archivo o ambos.');
      return;
    }

    setProcessing(true);
    setError(null);
    try {
      await onSubmit({
        type,
        title: title.trim(),
        description: description.trim() || null,
        ...(type === 'QUIZ' && {
          passing_percent: toPercent(Number(passing), gradeScale),
          max_attempts: limited ? Number(maxAttempts) : null,
          time_limit_minutes: timeLimit ? Number(timeLimit) : null,
          shuffle,
        }),
        ...(type === 'TASK' && { allow_text: allowText, allow_file: allowFile }),
        ...(type === 'SURVEY' && { questions }),
      });
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo guardar la actividad.'));
      setProcessing(false);
    }
  };

  return (
    <Modal
      title={activity ? `Editar ${ACTIVITY_LABEL[activity.type].toLowerCase()}` : 'Nueva actividad'}
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="activity-form" processing={processing}>
            {activity ? 'Guardar' : 'Agregar actividad'}
          </Button>
        </>
      }
    >
      <form id="activity-form" onSubmit={submit} className="space-y-4">
        {error && <Alert>{error}</Alert>}

        {!activity && (
          <fieldset>
            <legend className="block text-[13px] font-medium text-slate-700 mb-1.5">Tipo de actividad</legend>
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-2">
              {ACTIVITY_TYPES.map((option) => {
                const { icon: Icon, tone } = ACTIVITY_ICON[option];
                const selected = option === type;
                return (
                  <label
                    key={option}
                    className={`flex sm:flex-col items-center sm:items-start gap-3 sm:gap-2 p-3 rounded-xl border cursor-pointer transition-all ${
                      selected ? 'border-brand-500 bg-brand-50/60 ring-4 ring-brand-500/10' : 'border-slate-200 hover:border-slate-300'
                    }`}
                  >
                    <input type="radio" name="activity-type" className="sr-only" checked={selected} onChange={() => setType(option)} />
                    <span className={`w-8 h-8 shrink-0 rounded-lg inline-flex items-center justify-center ${tone}`}>
                      <Icon className="w-4 h-4" />
                    </span>
                    <span>
                      <span className="block text-[13px] font-semibold text-slate-900">{ACTIVITY_LABEL[option]}</span>
                      <span className="block text-xs text-slate-500">{ACTIVITY_HINT[option]}</span>
                    </span>
                  </label>
                );
              })}
            </div>
          </fieldset>
        )}

        <Field label="Título">
          <input className={INPUT_CLASS} required maxLength={200} value={title} onChange={(event) => setTitle(event.target.value)} />
        </Field>

        <Field
          label={type === 'TASK' ? 'Consigna' : 'Indicaciones (opcional)'}
          hint={type === 'TASK' ? 'Qué debe hacer y entregar el alumno.' : undefined}
        >
          <textarea
            className={INPUT_CLASS}
            rows={type === 'TASK' ? 5 : 2}
            required={type === 'TASK'}
            maxLength={20000}
            value={description}
            onChange={(event) => setDescription(event.target.value)}
          />
        </Field>

        {type === 'QUIZ' && (
          <>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <Field label={`Nota mínima para aprobar (de 0 a ${gradeScale})`}>
                <input
                  className={INPUT_CLASS}
                  type="number"
                  required
                  min={0}
                  max={gradeScale}
                  step="any"
                  value={passing}
                  onChange={(event) => setPassing(event.target.value)}
                />
              </Field>
              <Field label="Tiempo límite en minutos (opcional)" hint="Vacío: sin límite de tiempo.">
                <input
                  className={INPUT_CLASS}
                  type="number"
                  min={1}
                  max={600}
                  value={timeLimit}
                  onChange={(event) => setTimeLimit(event.target.value)}
                />
              </Field>
            </div>
            <div className="flex flex-wrap items-center gap-3">
              <label className="inline-flex items-center gap-2 text-[13px] text-slate-700 cursor-pointer">
                <input type="checkbox" className={CHECKBOX_CLASS} checked={limited} onChange={(event) => setLimited(event.target.checked)} />
                Limitar los intentos a
              </label>
              <input
                className={`${INPUT_CLASS} !w-20`}
                type="number"
                min={1}
                max={50}
                required={limited}
                disabled={!limited}
                aria-label="Número máximo de intentos"
                value={maxAttempts}
                onChange={(event) => setMaxAttempts(event.target.value)}
              />
              <span className="text-xs text-slate-500">Cuenta el mejor intento.</span>
            </div>
            <label className="flex items-center gap-2 text-[13px] text-slate-700 cursor-pointer">
              <input type="checkbox" className={CHECKBOX_CLASS} checked={shuffle} onChange={(event) => setShuffle(event.target.checked)} />
              Mostrar las preguntas en orden aleatorio
            </label>
            {!activity && <p className="text-xs text-slate-500">Después de crearlo podrás elegir sus preguntas del banco.</p>}
          </>
        )}

        {type === 'TASK' && (
          <fieldset>
            <legend className="block text-[13px] font-medium text-slate-700 mb-1.5">El alumno puede entregar</legend>
            <div className="flex flex-wrap gap-x-6 gap-y-2">
              <label className="inline-flex items-center gap-2 text-[13px] text-slate-700 cursor-pointer">
                <input type="checkbox" className={CHECKBOX_CLASS} checked={allowText} onChange={(event) => setAllowText(event.target.checked)} />
                Un texto escrito
              </label>
              <label className="inline-flex items-center gap-2 text-[13px] text-slate-700 cursor-pointer">
                <input type="checkbox" className={CHECKBOX_CLASS} checked={allowFile} onChange={(event) => setAllowFile(event.target.checked)} />
                Un archivo
              </label>
            </div>
          </fieldset>
        )}

        {type === 'SURVEY' && <SurveyQuestions value={questions} onChange={setQuestions} />}
      </form>
    </Modal>
  );
};

/* ───────────────────────────── Preguntas de un cuestionario ───────────────────────────── */

interface Chosen {
  question_id: string;
  points: number;
  type: QuizItem['type'];
  statement: string;
}

export const QuizQuestionsModal: React.FC<{
  activity: Activity;
  canReadBanks: boolean;
  onClose: () => void;
  onSubmit: (items: { question_id: string; points: number }[]) => Promise<void>;
}> = ({ activity, canReadBanks, onClose, onSubmit }) => {
  const [chosen, setChosen] = useState<Chosen[]>(() => (activity.questions as QuizItem[]).map((item) => ({ ...item })));
  const [banks, setBanks] = useState<QuestionBank[]>([]);
  const [bankId, setBankId] = useState('');
  const [search, setSearch] = useState('');
  const [available, setAvailable] = useState<Question[]>([]);
  const [loading, setLoading] = useState(canReadBanks);
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!canReadBanks) return;
    academicApi
      .banks()
      .then((result) => {
        setBanks(result);
        setBankId(result[0]?.id ?? '');
        if (result.length === 0) setLoading(false);
      })
      .catch(() => setLoading(false));
  }, [canReadBanks]);

  const loadQuestions = useCallback(async () => {
    if (!bankId) return;
    setLoading(true);
    try {
      const result = await academicApi.questions(bankId, { search: search || undefined, per_page: 100 });
      setAvailable(result.data);
    } catch {
      setAvailable([]);
    } finally {
      setLoading(false);
    }
  }, [bankId, search]);

  useEffect(() => {
    const timer = setTimeout(loadQuestions, 250);
    return () => clearTimeout(timer);
  }, [loadQuestions]);

  const chosenIds = new Set(chosen.map((item) => item.question_id));
  const total = chosen.reduce((sum, item) => sum + item.points, 0);

  const move = (index: number, offset: number) => {
    const next = [...chosen];
    const [item] = next.splice(index, 1);
    next.splice(index + offset, 0, item);
    setChosen(next);
  };

  const save = async () => {
    setProcessing(true);
    setError(null);
    try {
      await onSubmit(chosen.map(({ question_id, points }) => ({ question_id, points })));
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudieron guardar las preguntas.'));
      setProcessing(false);
    }
  };

  return (
    <Modal
      title="Preguntas del cuestionario"
      subtitle={activity.title}
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" processing={processing} onClick={save}>
            Guardar preguntas
          </Button>
        </>
      }
    >
      <div className="space-y-5">
        {error && <Alert>{error}</Alert>}

        <section>
          <h3 className="text-[13px] font-semibold text-slate-900 mb-2">
            En el cuestionario · {chosen.length} {chosen.length === 1 ? 'pregunta' : 'preguntas'}
            {chosen.length > 0 && <span className="font-normal text-slate-500"> · {total} puntos en total</span>}
          </h3>
          {chosen.length === 0 ? (
            <p className="text-[13px] text-slate-500 px-3 py-4 rounded-xl border border-dashed border-slate-300 text-center">
              Aún no hay preguntas. Agrégalas desde el banco, abajo.
            </p>
          ) : (
            <ul className="rounded-xl border border-slate-200 divide-y divide-slate-100">
              {chosen.map((item, index) => (
                <li key={item.question_id} className="flex items-center gap-2 px-3 py-2">
                  <span className="w-5 shrink-0 text-xs font-semibold text-slate-500 tnum">{index + 1}</span>
                  <div className="min-w-0 flex-1">
                    <p className="text-[13px] text-slate-900 truncate">{item.statement}</p>
                    <p className="text-xs text-slate-500">{QUESTION_LABEL[item.type]}</p>
                  </div>
                  <input
                    className={`${INPUT_CLASS} !w-16 shrink-0`}
                    type="number"
                    min={0.25}
                    max={100}
                    step={0.25}
                    aria-label="Puntos"
                    title="Puntos"
                    value={item.points}
                    onChange={(event) =>
                      setChosen(chosen.map((entry, i) => (i === index ? { ...entry, points: event.target.valueAsNumber || 1 } : entry)))
                    }
                  />
                  <button
                    type="button"
                    aria-label="Subir pregunta"
                    disabled={index === 0}
                    onClick={() => move(index, -1)}
                    className="p-1.5 shrink-0 rounded-lg text-slate-500 hover:text-brand-700 hover:bg-brand-50 cursor-pointer disabled:opacity-30 disabled:cursor-not-allowed"
                  >
                    <ArrowUp className="w-4 h-4" />
                  </button>
                  <button
                    type="button"
                    aria-label="Bajar pregunta"
                    disabled={index === chosen.length - 1}
                    onClick={() => move(index, 1)}
                    className="p-1.5 shrink-0 rounded-lg text-slate-500 hover:text-brand-700 hover:bg-brand-50 cursor-pointer disabled:opacity-30 disabled:cursor-not-allowed"
                  >
                    <ArrowDown className="w-4 h-4" />
                  </button>
                  <button
                    type="button"
                    aria-label="Quitar del cuestionario"
                    onClick={() => setChosen(chosen.filter((_, i) => i !== index))}
                    className="p-1.5 shrink-0 rounded-lg text-slate-500 hover:text-rose-600 hover:bg-rose-50 cursor-pointer"
                  >
                    <X className="w-4 h-4" />
                  </button>
                </li>
              ))}
            </ul>
          )}
        </section>

        <section>
          <h3 className="text-[13px] font-semibold text-slate-900 mb-2">Agregar desde el banco</h3>
          {!canReadBanks ? (
            <Alert tone="info">Tu rol no puede consultar los bancos de preguntas.</Alert>
          ) : banks.length === 0 && !loading ? (
            <EmptyState
              title="Aún no hay bancos de preguntas"
              text="Crea las preguntas primero en el banco y vuelve aquí para elegirlas."
              action={
                <Link to="/question-banks" className="text-[13px] font-semibold text-brand-700 hover:underline">
                  Ir al banco de preguntas
                </Link>
              }
            />
          ) : (
            <>
              <div className="flex flex-wrap gap-2 mb-2">
                <select
                  className={`${INPUT_CLASS} !w-auto flex-1 min-w-[140px]`}
                  aria-label="Banco"
                  value={bankId}
                  onChange={(event) => setBankId(event.target.value)}
                >
                  {banks.map((bank) => (
                    <option key={bank.id} value={bank.id}>
                      {bank.name} ({bank.questions_count})
                    </option>
                  ))}
                </select>
                <div className="relative flex-1 min-w-[140px]">
                  <Search className="w-4 h-4 text-slate-500 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" />
                  <input
                    className={`${INPUT_CLASS} pl-9`}
                    placeholder="Buscar"
                    aria-label="Buscar preguntas del banco"
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                  />
                </div>
              </div>
              {loading ? (
                <LoadingState />
              ) : available.length === 0 ? (
                <p className="text-[13px] text-slate-500 px-3 py-4 text-center">No hay preguntas en este banco con esa búsqueda.</p>
              ) : (
                <ul className="rounded-xl border border-slate-200 divide-y divide-slate-100 max-h-64 overflow-y-auto">
                  {available.map((question) => {
                    const added = chosenIds.has(question.id);
                    return (
                      <li key={question.id} className="flex items-center gap-3 px-3 py-2">
                        <div className="min-w-0 flex-1">
                          <p className="text-[13px] text-slate-900 line-clamp-2">{question.statement}</p>
                          <p className="text-xs text-slate-500">{QUESTION_LABEL[question.type]}</p>
                        </div>
                        {added ? (
                          <Badge tone="success">Agregada</Badge>
                        ) : (
                          <Button
                            size="sm"
                            icon={<Plus className="w-3.5 h-3.5" />}
                            onClick={() =>
                              setChosen([...chosen, { question_id: question.id, points: 1, type: question.type, statement: question.statement }])
                            }
                          >
                            Agregar
                          </Button>
                        )}
                      </li>
                    );
                  })}
                </ul>
              )}
            </>
          )}
        </section>
      </div>
    </Modal>
  );
};
