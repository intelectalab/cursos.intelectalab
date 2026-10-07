import React, { useCallback, useEffect, useState } from 'react';
import { GraduationCap, Search } from 'lucide-react';
import { apiErrorMessage } from '../../../shared/api/client';
import { Alert, Avatar, Badge, Button, CHECKBOX_CLASS, EmptyState, Field, INPUT_CLASS, LoadingState, Modal } from '../../../shared/ui';
import { SelectAll } from '../../../shared/ui/filters';
import { identityApi, type Group } from '../../identity/api';
import {
  enrollmentsApi,
  STATE_LABEL,
  STATE_TONE,
  type EnrollableCourse,
  type Enrollment,
  type EnrollmentState,
  type ParticipantCandidate,
  type ParticipantRole,
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

/* ───────────────────────────── Agregar participantes ───────────────────────────── */

const ROLE_COPY: Record<ParticipantRole, { label: string; text: string; action: string }> = {
  STUDENT: { label: 'Alumno', text: 'Lleva el curso: ve el contenido publicado, rinde las actividades y recibe su certificado.', action: 'Matricular' },
  TEACHER: { label: 'Docente', text: 'Dicta el curso: edita su contenido, revisa las tareas y sigue el avance de los alumnos.', action: 'Agregar' },
};

export const EnrollModal: React.FC<{
  // Con un curso fijo (desde su pestaña Participantes) o eligiéndolo (desde la lista general)
  course?: { id: string; access_days: number | null };
  courses?: EnrollableCourse[];
  // Papeles que quien abre la ventana puede dar; con uno solo no se muestra el selector
  roles?: ParticipantRole[];
  onClose: () => void;
  onDone: () => Promise<void>;
}> = ({ course, courses = [], roles = ['STUDENT'], onClose, onDone }) => {
  const open = courses.filter((item) => item.open);
  const [courseId, setCourseId] = useState(course?.id ?? open[0]?.id ?? '');
  const [role, setRole] = useState<ParticipantRole>(roles[0]);
  const [search, setSearch] = useState('');
  const [candidates, setCandidates] = useState<ParticipantCandidate[]>([]);
  const [selected, setSelected] = useState<string[]>([]);
  const [groups, setGroups] = useState<Group[]>([]);
  const [groupIds, setGroupIds] = useState<string[]>([]);
  const [startsAt, setStartsAt] = useState('');
  const [endsAt, setEndsAt] = useState('');
  const [loading, setLoading] = useState(true);
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const accessDays = course ? course.access_days : (open.find((item) => item.id === courseId)?.access_days ?? null);
  const copy = ROLE_COPY[role];

  const load = useCallback(async () => {
    if (!courseId) {
      setLoading(false);
      return;
    }
    setLoading(true);
    try {
      setCandidates(await enrollmentsApi.participantCandidates(courseId, search));
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo cargar la lista de personas.'));
    } finally {
      setLoading(false);
    }
  }, [courseId, search]);

  useEffect(() => {
    const timer = setTimeout(load, 250);
    return () => clearTimeout(timer);
  }, [load]);

  // Grupos con al menos una persona; quien no puede verlos simplemente no los tiene como opción
  useEffect(() => {
    identityApi
      .listGroups()
      .then((list) => setGroups(list.filter((group) => group.members_count > 0)))
      .catch(() => setGroups([]));
  }, []);

  const toggle = (id: string) => setSelected((current) => (current.includes(id) ? current.filter((item) => item !== id) : [...current, id]));
  const toggleGroup = (id: string) => setGroupIds((current) => (current.includes(id) ? current.filter((item) => item !== id) : [...current, id]));
  const chosen = selected.length + groupIds.length;

  const submit = async () => {
    setProcessing(true);
    setError(null);
    try {
      await enrollmentsApi.addParticipants(
        courseId,
        selected,
        role,
        role === 'STUDENT' ? { starts_at: startsAt || null, ends_at: endsAt || null } : undefined,
        groupIds,
      );
      await onDone();
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo agregar a las personas elegidas.'));
      setProcessing(false);
    }
  };

  return (
    <Modal
      title={roles.length > 1 ? 'Agregar participantes' : 'Matricular alumnos'}
      subtitle={roles.length > 1 ? 'Alumnos y docentes se agregan desde aquí, cada uno con su papel en este curso.' : 'Tendrán acceso a la versión publicada del curso.'}
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" processing={processing} disabled={chosen === 0 || !courseId} onClick={submit}>
            {groupIds.length > 0
              ? `${copy.action} ${groupIds.length === 1 ? '1 grupo' : `${groupIds.length} grupos`}${selected.length > 0 ? ` y ${selected.length} más` : ''}`
              : selected.length > 0
                ? `${copy.action} (${selected.length})`
                : copy.action}
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
            {roles.length > 1 && (
              <fieldset>
                <legend className="block text-[13px] font-medium text-slate-700 mb-1.5">Papel en este curso</legend>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                  {roles.map((option) => (
                    <label
                      key={option}
                      className={`flex items-start gap-2.5 px-3 py-2.5 border rounded-xl cursor-pointer transition-colors ${
                        role === option ? 'border-brand-300 bg-brand-50' : 'border-slate-200 hover:bg-slate-50'
                      }`}
                    >
                      <input type="radio" name="participant-role" className="mt-0.5 accent-brand-600" checked={role === option} onChange={() => setRole(option)} />
                      <span>
                        <span className="block text-[13px] font-semibold text-slate-900">{ROLE_COPY[option].label}</span>
                        <span className="block text-xs text-slate-600">{ROLE_COPY[option].text}</span>
                      </span>
                    </label>
                  ))}
                </div>
              </fieldset>
            )}

            {role === 'STUDENT' && (
              <ValidityFields
                startsAt={startsAt}
                endsAt={endsAt}
                defaultHint={accessDays ? `Vacío: ${accessDays} días, como define el curso.` : undefined}
                onChange={(start, end) => {
                  setStartsAt(start);
                  setEndsAt(end);
                }}
              />
            )}

            {groups.length > 0 && (
              <fieldset>
                <legend className="block text-[13px] font-medium text-slate-700 mb-1.5">Grupos completos</legend>
                <div className="flex flex-wrap gap-2">
                  {groups.map((group) => {
                    const checked = groupIds.includes(group.id);
                    return (
                      <label
                        key={group.id}
                        className={`inline-flex items-center gap-2 px-3 py-1.5 border rounded-full text-[13px] cursor-pointer transition-colors ${
                          checked ? 'border-brand-300 bg-brand-50 text-brand-800' : 'border-slate-200 text-slate-700 hover:bg-slate-50'
                        }`}
                      >
                        <input type="checkbox" className={CHECKBOX_CLASS} checked={checked} onChange={() => toggleGroup(group.id)} />
                        <span className="font-medium">{group.name}</span>
                        <span className="text-xs text-slate-500 tnum">{group.members_count}</span>
                      </label>
                    );
                  })}
                </div>
                <p className="mt-1.5 text-xs text-slate-500">Entran los miembros activos del grupo que aún no participan en este curso.</p>
              </fieldset>
            )}

            <div>
              <span className="block text-[13px] font-medium text-slate-700 mb-1.5">Personas</span>
              <div className="relative mb-2">
                <Search className="w-4 h-4 text-slate-500 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" />
                <input
                  className={`${INPUT_CLASS} pl-9`}
                  placeholder="Buscar por nombre o correo"
                  aria-label="Buscar personas"
                  value={search}
                  onChange={(event) => setSearch(event.target.value)}
                />
              </div>
              {loading ? (
                <LoadingState />
              ) : candidates.length === 0 ? (
                <EmptyState
                  icon={GraduationCap}
                  title={search ? 'Sin resultados' : 'No queda nadie por agregar'}
                  text={search ? undefined : 'Todos los usuarios activos ya participan en este curso, o aún no has creado ninguno en Usuarios.'}
                />
              ) : (
                <>
                  <div className="flex items-center justify-between gap-3 px-3 py-2 rounded-t-xl border border-b-0 border-slate-200 bg-slate-50/80">
                    <SelectAll ids={candidates.map((person) => person.id)} selected={selected} onChange={setSelected}>
                      Seleccionar todos ({candidates.length})
                    </SelectAll>
                    {selected.length > 0 && <span className="text-xs font-semibold text-brand-700">{selected.length} elegidos</span>}
                  </div>
                  <ul className="rounded-b-xl border border-slate-200 divide-y divide-slate-100 max-h-64 overflow-y-auto">
                    {candidates.map((person) => (
                      <li key={person.id}>
                        <label className="flex items-center gap-3 px-3 py-2.5 cursor-pointer hover:bg-brand-50/30">
                          <input type="checkbox" className={CHECKBOX_CLASS} checked={selected.includes(person.id)} onChange={() => toggle(person.id)} />
                          <Avatar name={person.name} size="sm" />
                          <span className="min-w-0 flex-1">
                            <span className="block text-[13px] font-semibold text-slate-900 truncate">{person.name}</span>
                            <span className="block text-xs text-slate-500 truncate">{person.email}</span>
                          </span>
                          <span className="hidden sm:block text-xs text-slate-500 text-right">{person.roles.join(', ')}</span>
                        </label>
                      </li>
                    ))}
                  </ul>
                  {candidates.length >= 100 && <p className="mt-1.5 text-xs text-slate-500">Se muestran las primeras 100 personas. Escribe para buscar entre todas.</p>}
                </>
              )}
            </div>
          </>
        )}
      </div>
    </Modal>
  );
};

/* ───────────────────────────── Vigencia de varias matrículas ───────────────────────────── */

export const BulkValidityModal: React.FC<{ ids: string[]; onClose: () => void; onDone: () => Promise<void> }> = ({ ids, onClose, onDone }) => {
  const [startsAt, setStartsAt] = useState('');
  const [endsAt, setEndsAt] = useState('');
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setProcessing(true);
    setError(null);
    try {
      await enrollmentsApi.bulkValidity(ids, { starts_at: startsAt || null, ends_at: endsAt || null });
      await onDone();
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo cambiar la vigencia.'));
      setProcessing(false);
    }
  };

  return (
    <Modal
      title="Vigencia de las matrículas elegidas"
      subtitle={`Se aplicará a ${ids.length} ${ids.length === 1 ? 'matrícula' : 'matrículas'}.`}
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="bulk-validity-form" processing={processing}>
            Guardar vigencia
          </Button>
        </>
      }
    >
      <form id="bulk-validity-form" onSubmit={submit} className="space-y-4">
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
          Todas quedarán con estas mismas fechas; dejar un campo vacío le quita ese límite. Las matrículas canceladas no cambian.
        </p>
      </form>
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
