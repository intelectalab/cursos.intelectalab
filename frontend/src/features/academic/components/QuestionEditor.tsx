import React, { useState } from 'react';
import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-react';
import { apiErrorMessage } from '../../../shared/api/client';
import { Alert, Button, CHECKBOX_CLASS, Field, INPUT_CLASS, Modal } from '../../../shared/ui';
import {
  QUESTION_HINT,
  QUESTION_LABEL,
  QUESTION_TYPES,
  type Question,
  type QuestionConfig,
  type QuestionInput,
  type QuestionType,
} from '../api';

// Punto de partida de cada tipo al crear una pregunta
function emptyConfig(type: QuestionType): QuestionConfig {
  switch (type) {
    case 'SINGLE':
      return { options: [{ text: '', correct: true }, { text: '', correct: false }] };
    case 'MULTIPLE':
      return { options: [{ text: '', correct: true }, { text: '', correct: false }, { text: '', correct: false }] };
    case 'TRUE_FALSE':
      return { answer: true };
    case 'SHORT_TEXT':
      return { answers: [''] };
    case 'NUMERIC':
      return { answer: 0, tolerance: 0 };
    case 'MATCHING':
      return { pairs: [{ left: '', right: '' }, { left: '', right: '' }] };
    case 'ORDERING':
      return { items: [{ text: '' }, { text: '' }] };
  }
}

const RowButton: React.FC<{ label: string; onClick: () => void; disabled?: boolean; children: React.ReactNode }> = ({
  label,
  onClick,
  disabled,
  children,
}) => (
  <button
    type="button"
    aria-label={label}
    title={label}
    disabled={disabled}
    onClick={onClick}
    className="p-1.5 shrink-0 rounded-lg text-slate-500 hover:text-brand-700 hover:bg-brand-50 transition-colors cursor-pointer disabled:opacity-30 disabled:cursor-not-allowed"
  >
    {children}
  </button>
);

const AddRow: React.FC<{ label: string; onClick: () => void; disabled: boolean }> = ({ label, onClick, disabled }) => (
  <Button size="sm" icon={<Plus className="w-3.5 h-3.5" />} onClick={onClick} disabled={disabled}>
    {label}
  </Button>
);

const MAX_ROWS = 10;

/* Editor de la respuesta según el tipo de pregunta */
const ConfigEditor: React.FC<{ type: QuestionType; config: QuestionConfig; onChange: (config: QuestionConfig) => void }> = ({
  type,
  config,
  onChange,
}) => {
  if (type === 'SINGLE' || type === 'MULTIPLE') {
    const options = config.options ?? [];
    const update = (index: number, patch: Partial<(typeof options)[number]>) =>
      onChange({ options: options.map((option, i) => (i === index ? { ...option, ...patch } : option)) });

    return (
      <fieldset>
        <legend className="block text-[13px] font-medium text-slate-700 mb-1.5">
          Opciones · marca {type === 'SINGLE' ? 'la correcta' : 'las correctas'}
        </legend>
        <div className="space-y-2">
          {options.map((option, index) => (
            <div key={index} className="flex items-center gap-2">
              <input
                type={type === 'SINGLE' ? 'radio' : 'checkbox'}
                name="correct-option"
                className={type === 'SINGLE' ? 'w-4 h-4 shrink-0 accent-brand-600 cursor-pointer' : CHECKBOX_CLASS}
                aria-label={`Opción ${index + 1} correcta`}
                checked={option.correct}
                onChange={(event) =>
                  type === 'SINGLE'
                    ? onChange({ options: options.map((item, i) => ({ ...item, correct: i === index })) })
                    : update(index, { correct: event.target.checked })
                }
              />
              <input
                className={INPUT_CLASS}
                required
                maxLength={500}
                placeholder={`Opción ${index + 1}`}
                value={option.text}
                onChange={(event) => update(index, { text: event.target.value })}
              />
              <RowButton
                label="Quitar opción"
                disabled={options.length <= 2}
                onClick={() => onChange({ options: options.filter((_, i) => i !== index) })}
              >
                <Trash2 className="w-4 h-4" />
              </RowButton>
            </div>
          ))}
        </div>
        <div className="mt-2.5">
          <AddRow
            label="Agregar opción"
            disabled={options.length >= MAX_ROWS}
            onClick={() => onChange({ options: [...options, { text: '', correct: false }] })}
          />
        </div>
        {type === 'MULTIPLE' && (
          <p className="text-xs text-slate-500 mt-2">
            Da puntaje parcial: cada acierto suma y cada opción incorrecta marcada resta.
          </p>
        )}
      </fieldset>
    );
  }

  if (type === 'TRUE_FALSE') {
    return (
      <fieldset>
        <legend className="block text-[13px] font-medium text-slate-700 mb-1.5">La afirmación es</legend>
        <div className="flex gap-2">
          {[true, false].map((value) => (
            <label
              key={String(value)}
              className={`flex-1 flex items-center gap-2.5 px-3 h-10 rounded-xl border cursor-pointer text-[13px] font-medium transition-all ${
                config.answer === value ? 'border-brand-500 bg-brand-50/60 text-brand-800' : 'border-slate-200 text-slate-700 hover:border-slate-300'
              }`}
            >
              <input
                type="radio"
                name="true-false"
                className="w-4 h-4 accent-brand-600"
                checked={config.answer === value}
                onChange={() => onChange({ answer: value })}
              />
              {value ? 'Verdadera' : 'Falsa'}
            </label>
          ))}
        </div>
      </fieldset>
    );
  }

  if (type === 'SHORT_TEXT') {
    const answers = config.answers ?? [];
    return (
      <fieldset>
        <legend className="block text-[13px] font-medium text-slate-700 mb-1.5">Respuestas aceptadas</legend>
        <div className="space-y-2">
          {answers.map((answer, index) => (
            <div key={index} className="flex items-center gap-2">
              <input
                className={INPUT_CLASS}
                required
                maxLength={200}
                placeholder={index === 0 ? 'Respuesta correcta' : 'Otra forma válida de escribirla'}
                value={answer}
                onChange={(event) => onChange({ answers: answers.map((item, i) => (i === index ? event.target.value : item)) })}
              />
              <RowButton
                label="Quitar respuesta"
                disabled={answers.length <= 1}
                onClick={() => onChange({ answers: answers.filter((_, i) => i !== index) })}
              >
                <Trash2 className="w-4 h-4" />
              </RowButton>
            </div>
          ))}
        </div>
        <div className="mt-2.5">
          <AddRow label="Agregar variante" disabled={answers.length >= MAX_ROWS} onClick={() => onChange({ answers: [...answers, ''] })} />
        </div>
        <p className="text-xs text-slate-500 mt-2">No importan las mayúsculas, las tildes ni los espacios de más.</p>
      </fieldset>
    );
  }

  if (type === 'NUMERIC') {
    return (
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <Field label="Respuesta correcta">
          <input
            className={INPUT_CLASS}
            type="number"
            step="any"
            required
            value={Number.isNaN(config.answer as number) ? '' : (config.answer as number)}
            onChange={(event) => onChange({ ...config, answer: event.target.valueAsNumber })}
          />
        </Field>
        <Field label="Margen aceptado" hint="Con 0 solo vale el número exacto. Con 0.5, vale hasta medio punto arriba o abajo.">
          <input
            className={INPUT_CLASS}
            type="number"
            step="any"
            min={0}
            value={config.tolerance ?? 0}
            onChange={(event) => onChange({ ...config, tolerance: event.target.valueAsNumber || 0 })}
          />
        </Field>
      </div>
    );
  }

  if (type === 'MATCHING') {
    const pairs = config.pairs ?? [];
    const update = (index: number, patch: Partial<(typeof pairs)[number]>) =>
      onChange({ pairs: pairs.map((pair, i) => (i === index ? { ...pair, ...patch } : pair)) });

    return (
      <fieldset>
        <legend className="block text-[13px] font-medium text-slate-700 mb-1.5">Pares correctos</legend>
        <div className="space-y-2">
          {pairs.map((pair, index) => (
            <div key={index} className="flex items-center gap-2">
              <input
                className={INPUT_CLASS}
                required
                maxLength={300}
                placeholder="Elemento"
                aria-label={`Elemento ${index + 1}`}
                value={pair.left}
                onChange={(event) => update(index, { left: event.target.value })}
              />
              <span className="text-slate-500 shrink-0" aria-hidden="true">
                ↔
              </span>
              <input
                className={INPUT_CLASS}
                required
                maxLength={300}
                placeholder="Su pareja"
                aria-label={`Pareja ${index + 1}`}
                value={pair.right}
                onChange={(event) => update(index, { right: event.target.value })}
              />
              <RowButton label="Quitar par" disabled={pairs.length <= 2} onClick={() => onChange({ pairs: pairs.filter((_, i) => i !== index) })}>
                <Trash2 className="w-4 h-4" />
              </RowButton>
            </div>
          ))}
        </div>
        <div className="mt-2.5">
          <AddRow label="Agregar par" disabled={pairs.length >= MAX_ROWS} onClick={() => onChange({ pairs: [...pairs, { left: '', right: '' }] })} />
        </div>
        <p className="text-xs text-slate-500 mt-2">Al alumno se le muestra la columna de parejas mezclada. Cada par acertado suma.</p>
      </fieldset>
    );
  }

  const items = config.items ?? [];
  const move = (index: number, offset: number) => {
    const next = [...items];
    const [item] = next.splice(index, 1);
    next.splice(index + offset, 0, item);
    onChange({ items: next });
  };

  return (
    <fieldset>
      <legend className="block text-[13px] font-medium text-slate-700 mb-1.5">Elementos, en el orden correcto</legend>
      <div className="space-y-2">
        {items.map((item, index) => (
          <div key={index} className="flex items-center gap-2">
            <span className="w-6 shrink-0 text-center text-xs font-semibold text-slate-500 tnum">{index + 1}</span>
            <input
              className={INPUT_CLASS}
              required
              maxLength={300}
              placeholder={`Elemento ${index + 1}`}
              value={item.text}
              onChange={(event) => onChange({ items: items.map((entry, i) => (i === index ? { ...entry, text: event.target.value } : entry)) })}
            />
            <RowButton label="Subir" disabled={index === 0} onClick={() => move(index, -1)}>
              <ArrowUp className="w-4 h-4" />
            </RowButton>
            <RowButton label="Bajar" disabled={index === items.length - 1} onClick={() => move(index, 1)}>
              <ArrowDown className="w-4 h-4" />
            </RowButton>
            <RowButton label="Quitar elemento" disabled={items.length <= 2} onClick={() => onChange({ items: items.filter((_, i) => i !== index) })}>
              <Trash2 className="w-4 h-4" />
            </RowButton>
          </div>
        ))}
      </div>
      <div className="mt-2.5">
        <AddRow label="Agregar elemento" disabled={items.length >= MAX_ROWS} onClick={() => onChange({ items: [...items, { text: '' }] })} />
      </div>
      <p className="text-xs text-slate-500 mt-2">Al alumno se le muestran mezclados. Cada elemento en su posición suma.</p>
    </fieldset>
  );
};

export const QuestionEditor: React.FC<{
  question: Question | null;
  onClose: () => void;
  onSubmit: (input: QuestionInput) => Promise<void>;
}> = ({ question, onClose, onSubmit }) => {
  const [type, setType] = useState<QuestionType>(question?.type ?? 'SINGLE');
  const [statement, setStatement] = useState(question?.statement ?? '');
  const [explanation, setExplanation] = useState(question?.explanation ?? '');
  const [config, setConfig] = useState<QuestionConfig>(question?.config ?? emptyConfig('SINGLE'));
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setProcessing(true);
    setError(null);
    try {
      await onSubmit({ type, statement: statement.trim(), explanation: explanation.trim() || null, config });
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo guardar la pregunta.'));
      setProcessing(false);
    }
  };

  return (
    <Modal
      title={question ? 'Editar pregunta' : 'Nueva pregunta'}
      subtitle={question ? QUESTION_LABEL[question.type] : undefined}
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="question-form" processing={processing}>
            {question ? 'Guardar' : 'Agregar pregunta'}
          </Button>
        </>
      }
    >
      <form id="question-form" onSubmit={submit} className="space-y-4">
        {error && <Alert>{error}</Alert>}
        {question && question.quizzes_count > 0 && (
          <Alert tone="info">
            Esta pregunta se usa en {question.quizzes_count === 1 ? 'un cuestionario' : `${question.quizzes_count} cuestionarios`}. El cambio
            llegará a los alumnos cuando se publiquen de nuevo esos cursos.
          </Alert>
        )}

        {!question && (
          <Field label="Tipo de pregunta" hint={QUESTION_HINT[type]}>
            <select
              className={INPUT_CLASS}
              value={type}
              onChange={(event) => {
                const next = event.target.value as QuestionType;
                setType(next);
                setConfig(emptyConfig(next));
              }}
            >
              {QUESTION_TYPES.map((option) => (
                <option key={option} value={option}>
                  {QUESTION_LABEL[option]}
                </option>
              ))}
            </select>
          </Field>
        )}

        <Field label="Enunciado">
          <textarea className={INPUT_CLASS} required rows={3} maxLength={5000} value={statement} onChange={(event) => setStatement(event.target.value)} />
        </Field>

        <ConfigEditor type={type} config={config} onChange={setConfig} />

        <Field label="Comentario para el alumno (opcional)" hint="Se muestra después de responder: por qué esa es la respuesta.">
          <textarea className={INPUT_CLASS} rows={2} maxLength={5000} value={explanation} onChange={(event) => setExplanation(event.target.value)} />
        </Field>
      </form>
    </Modal>
  );
};
