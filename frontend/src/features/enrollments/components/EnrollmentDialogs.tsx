import React, { useCallback, useEffect, useState } from 'react';
import { GraduationCap, Search } from 'lucide-react';
import { apiErrorMessage } from '../../../shared/api/client';
import { Alert, Avatar, Badge, Button, CHECKBOX_CLASS, EmptyState, Field, INPUT_CLASS, LoadingState, Modal } from '../../../shared/ui';
import {
  enrollmentsApi,
  STATE_LABEL,
  STATE_TONE,
  type EnrollableCourse,
  type Enrollment,
  type EnrollmentState,
  type StudentRef,
} from '../api';

export const StateBadge: React.FC<{ state: EnrollmentState }> = ({ state }) => (
  <Badge tone={STATE_TONE[state]} dot>
    {STATE_LABEL[state]}
  </Badge>
);

// Campos de vigencia, compartidos por el alta y la edición
const ValidityFields: React.FC<{
  startsAt: string;
  endsAt: string;
  defaultHint?: string;
  onChange: (startsAt: string, endsAt: string) => void;
}> = ({ startsAt, endsAt, defaultHint, onChange }) => (
  <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <Field label="Inicio del acceso (opcional)" hint="Vacío: desde ahora.">
      <input className={INPUT_CLASS} type="date" value={startsAt} max={endsAt || undefined} onChange={(event) => onChange(event.target.value, endsAt)} />
    </Field>
    <Field label="Fin del acceso (opcional)" hint={defaultHint ?? 'Vacío: no vence.'}>
      <input className={INPUT_CLASS} type="date" value={endsAt} min={startsAt || undefined} onChange={(event) => onChange(startsAt, event.target.value)} />
    </Field>
  </div>
);

/* ───────────────────────────── Matricular ───────────────────────────── */

export const EnrollModal: React.FC<{
  // Con un curso fijo (desde su pestaña Alumnos) o eligiéndolo (desde la lista general)
  course?: { id: string; access_days: number | null };
  courses?: EnrollableCourse[];
  onClose: () => void;
  onDone: () => Promise<void>;
}> = ({ course, courses = [], onClose, onDone }) => {
  const open = courses.filter((item) => item.open);
  const [courseId, setCourseId] = useState(course?.id ?? open[0]?.id ?? '');
  const [search, setSearch] = useState('');
  const [candidates, setCandidates] = useState<StudentRef[]>([]);
  const [selected, setSelected] = useState<string[]>([]);
  const [startsAt, setStartsAt] = useState('');
  const [endsAt, setEndsAt] = useState('');
  const [loading, setLoading] = useState(true);
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const accessDays = course ? course.access_days : (open.find((item) => item.id === courseId)?.access_days ?? null);

  const load = useCallback(async () => {
    if (!courseId) {
      setLoading(false);
      return;
    }
    setLoading(true);
    try {
      setCandidates(await enrollmentsApi.candidates(courseId, search));
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo cargar la lista de alumnos.'));
    } finally {
      setLoading(false);
    }
  }, [courseId, search]);

  useEffect(() => {
    const timer = setTimeout(load, 250);
    return () => clearTimeout(timer);
  }, [load]);

  const toggle = (id: string) => setSelected((current) => (current.includes(id) ? current.filter((item) => item !== id) : [...current, id]));

  const submit = async () => {
    setProcessing(true);
    setError(null);
    try {
      await enrollmentsApi.enroll(courseId, selected, { starts_at: startsAt || null, ends_at: endsAt || null });
      await onDone();
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo matricular.'));
      setProcessing(false);
    }
  };

  return (
    <Modal
      title="Matricular alumnos"
      subtitle="Tendrán acceso a la versión publicada del curso."
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" processing={processing} disabled={selected.length === 0 || !courseId} onClick={submit}>
            {selected.length > 0 ? `Matricular (${selected.length})` : 'Matricular'}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        {error && <Alert>{error}</Alert>}

        {!course &&
          (open.length === 0 ? (
            <Alert tone="info">No hay cursos publicados. Publica un curso para poder matricular.</Alert>
          ) : (
            <Field label="Curso">
              <select
                className={INPUT_CLASS}
                value={courseId}
                onChange={(event) => {
                  setCourseId(event.target.value);
                  setSelected([]);
                }}
              >
                {open.map((item) => (
                  <option key={item.id} value={item.id}>
                    {item.title}
                  </option>
                ))}
              </select>
            </Field>
          ))}

        {courseId && (
          <>
            <ValidityFields
              startsAt={startsAt}
              endsAt={endsAt}
              defaultHint={accessDays ? `Vacío: ${accessDays} días, como define el curso.` : undefined}
              onChange={(start, end) => {
                setStartsAt(start);
                setEndsAt(end);
              }}
            />

            <div>
              <span className="block text-[13px] font-medium text-slate-700 mb-1.5">Alumnos</span>
              <div className="relative mb-2">
                <Search className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" />
                <input
                  className={`${INPUT_CLASS} pl-9`}
                  placeholder="Buscar por nombre o correo"
                  aria-label="Buscar alumnos"
                  value={search}
                  onChange={(event) => setSearch(event.target.value)}
                />
              </div>
              {loading ? (
                <LoadingState />
              ) : candidates.length === 0 ? (
                <EmptyState
                  icon={GraduationCap}
                  title={search ? 'Sin resultados' : 'No hay alumnos por matricular'}
                  text={search ? undefined : 'Todos los usuarios con rol de alumno ya están en este curso, o aún no has creado ninguno en Usuarios.'}
                />
              ) : (
                <ul className="rounded-xl border border-slate-200 divide-y divide-slate-100 max-h-64 overflow-y-auto">
                  {candidates.map((student) => (
                    <li key={student.id}>
                      <label className="flex items-center gap-3 px-3 py-2.5 cursor-pointer hover:bg-brand-50/30">
                        <input type="checkbox" className={CHECKBOX_CLASS} checked={selected.includes(student.id)} onChange={() => toggle(student.id)} />
                        <Avatar name={student.name} size="sm" />
                        <span className="min-w-0">
                          <span className="block text-[13px] font-semibold text-slate-900 truncate">{student.name}</span>
                          <span className="block text-xs text-slate-500 truncate">{student.email}</span>
                        </span>
                      </label>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          </>
        )}
      </div>
    </Modal>
  );
};

/* ───────────────────────────── Cambiar la vigencia ───────────────────────────── */

export const ValidityModal: React.FC<{ enrollment: Enrollment; onClose: () => void; onDone: () => Promise<void> }> = ({
  enrollment,
  onClose,
  onDone,
}) => {
  const [startsAt, setStartsAt] = useState(enrollment.starts_at ?? '');
  const [endsAt, setEndsAt] = useState(enrollment.ends_at ?? '');
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setProcessing(true);
    setError(null);
    try {
      await enrollmentsApi.updateValidity(enrollment.id, { starts_at: startsAt || null, ends_at: endsAt || null });
      await onDone();
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo cambiar la vigencia.'));
      setProcessing(false);
    }
  };

  return (
    <Modal
      title="Vigencia de la matrícula"
      subtitle={`${enrollment.student.name} · ${enrollment.course.title}`}
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="validity-form" processing={processing}>
            Guardar vigencia
          </Button>
        </>
      }
    >
      <form id="validity-form" onSubmit={submit} className="space-y-4">
        {error && <Alert>{error}</Alert>}
        <ValidityFields
          startsAt={startsAt}
          endsAt={endsAt}
          onChange={(start, end) => {
            setStartsAt(start);
            setEndsAt(end);
          }}
        />
        <p className="text-xs text-slate-500">
          Fuera de estas fechas el alumno no puede entrar al curso, pero conserva sus notas y su avance. El acceso incluye el día de fin completo.
        </p>
      </form>
    </Modal>
  );
};
