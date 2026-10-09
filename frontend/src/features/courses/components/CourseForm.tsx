import React from 'react';
import { CHECKBOX_CLASS, Field, INPUT_CLASS } from '../../../shared/ui';
import { toPercent, toScale } from '../../academic/api';
import { LANGUAGE_LABEL, LEVEL_LABEL, type CourseInput, type CourseLevel, type CourseOptions } from '../api';

// Campos del curso, compartidos por el alta y la pestaña Información
export const CourseFields: React.FC<{
  value: CourseInput;
  options: CourseOptions | null;
  disabled?: boolean;
  withDescription?: boolean;
  onChange: (value: CourseInput) => void;
}> = ({ value, options, disabled = false, withDescription = false, onChange }) => {
  const set = <K extends keyof CourseInput>(key: K, next: CourseInput[K]) => onChange({ ...value, [key]: next });
  // La nota mínima se escribe en la escala de la academia y se guarda en porcentaje
  const gradeScale = options?.grade_scale ?? 20;
  const text = (key: 'title' | 'summary' | 'description' | 'category') => ({
    className: INPUT_CLASS,
    disabled,
    value: value[key] ?? '',
    onChange: (event: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => set(key, event.target.value || null),
  });

  return (
    <div className="space-y-4">
      <Field label="Título">
        <input {...text('title')} required maxLength={200} placeholder="Ej. Excel para finanzas" />
      </Field>
      <Field label="Resumen (opcional)" hint="Una o dos líneas que se muestran en las tarjetas del curso.">
        <input {...text('summary')} maxLength={300} />
      </Field>
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <Field label="Categoría (opcional)" hint="Escribe una nueva o elige una que ya uses.">
          <input {...text('category')} maxLength={80} list="course-categories" />
          <datalist id="course-categories">
            {(options?.categories ?? []).map((category) => (
              <option key={category} value={category} />
            ))}
          </datalist>
        </Field>
        <Field label="Nivel (opcional)">
          <select
            className={INPUT_CLASS}
            disabled={disabled}
            value={value.level ?? ''}
            onChange={(event) => set('level', (event.target.value || null) as CourseLevel | null)}
          >
            <option value="">Sin nivel</option>
            {(options?.levels ?? []).map((level) => (
              <option key={level} value={level}>
                {LEVEL_LABEL[level]}
              </option>
            ))}
          </select>
        </Field>
        <Field label="Duración en horas (opcional)">
          <input
            className={INPUT_CLASS}
            type="number"
            min={1}
            max={5000}
            disabled={disabled}
            value={value.duration_hours ?? ''}
            onChange={(event) => set('duration_hours', event.target.value ? Number(event.target.value) : null)}
          />
        </Field>
        <Field label="Días de acceso (opcional)" hint="Cuánto dura la matrícula de cada alumno. Vacío: no vence.">
          <input
            className={INPUT_CLASS}
            type="number"
            min={1}
            max={3650}
            placeholder="Sin vencimiento"
            disabled={disabled}
            value={value.access_days ?? ''}
            onChange={(event) => set('access_days', event.target.value ? Number(event.target.value) : null)}
          />
        </Field>
        <Field label="Precio (opcional)" hint="En la moneda de tu academia. Vacío: no está en venta. 0: gratuito.">
          <input
            className={INPUT_CLASS}
            type="number"
            min={0}
            max={99999999.99}
            step="0.01"
            placeholder="No está en venta"
            disabled={disabled}
            value={value.price ?? ''}
            onChange={(event) => set('price', event.target.value !== '' ? Number(event.target.value) : null)}
          />
        </Field>
        <Field
          label={`Nota mínima para aprobar (de 0 a ${gradeScale}, opcional)`}
          hint="Nota final que el alumno debe alcanzar. Si el curso entrega certificado, también se exige para emitirlo. Vacío: no se exige."
        >
          <input
            className={INPUT_CLASS}
            type="number"
            min={0}
            max={gradeScale}
            step="any"
            placeholder="Sin nota mínima"
            disabled={disabled}
            value={value.passing_percent === null ? '' : toScale(value.passing_percent, gradeScale)}
            onChange={(event) => set('passing_percent', event.target.value !== '' ? toPercent(Number(event.target.value), gradeScale) : null)}
          />
        </Field>
        <Field label="Idioma">
          <select className={INPUT_CLASS} disabled={disabled} value={value.language} onChange={(event) => set('language', event.target.value)}>
            {(options?.languages ?? ['es']).map((language) => (
              <option key={language} value={language}>
                {LANGUAGE_LABEL[language] ?? language}
              </option>
            ))}
          </select>
        </Field>
      </div>
      <label className="flex items-start gap-2.5 cursor-pointer select-none">
        <input
          type="checkbox"
          className={`${CHECKBOX_CLASS} mt-0.5`}
          disabled={disabled}
          checked={value.issues_certificate}
          onChange={(event) => set('issues_certificate', event.target.checked)}
        />
        <span>
          <span className="block text-[13px] font-medium text-slate-800">Este curso entrega certificado</span>
          <span className="block text-xs text-slate-500">
            Se emite solo cuando el alumno completa el curso al 100 % (y alcanza la nota mínima, si el curso la tiene), con código y QR de verificación.
          </span>
        </span>
      </label>
      <label className="flex items-start gap-2.5 cursor-pointer select-none">
        <input
          type="checkbox"
          className={`${CHECKBOX_CLASS} mt-0.5`}
          disabled={disabled}
          checked={value.sequential}
          onChange={(event) => set('sequential', event.target.checked)}
        />
        <span>
          <span className="block text-[13px] font-medium text-slate-800">Avance secuencial</span>
          <span className="block text-xs text-slate-500">
            Cada sección se abre cuando el alumno completa la anterior. Dentro de una sección avanza en el orden que quiera. Vale desde que se guarda.
          </span>
        </span>
      </label>
      {withDescription && (
        <Field label="Descripción (opcional)" hint="Qué aprenderá el alumno, a quién va dirigido y qué necesita saber antes.">
          <textarea {...text('description')} rows={8} maxLength={20000} />
        </Field>
      )}
    </div>
  );
};
