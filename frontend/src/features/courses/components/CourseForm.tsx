import React from 'react';
import { Field, INPUT_CLASS } from '../../../shared/ui';
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
      {withDescription && (
        <Field label="Descripción (opcional)" hint="Qué aprenderá el alumno, a quién va dirigido y qué necesita saber antes.">
          <textarea {...text('description')} rows={8} maxLength={20000} />
        </Field>
      )}
    </div>
  );
};
