import React, { useCallback, useEffect, useState } from 'react';
import { CalendarClock, GraduationCap, Presentation, UserMinus, UserPlus, Users } from 'lucide-react';
import { useAuth } from '../../auth/AuthContext';
import { apiErrorMessage } from '../../../shared/api/client';
import { Alert, Avatar, Badge, Button, Card, CardHeader, ConfirmModal, EmptyState, ErrorState, LoadingState, Pagination } from '../../../shared/ui';
import { BulkBar, FilterCards, type FilterCard } from '../../../shared/ui/filters';
import type { PageMeta } from '../../identity/api';
import type { CourseDetail } from '../../courses/api';
import { enrollmentsApi, type Enrollment, type ParticipantRole } from '../api';
import { BulkValidityModal, EnrollModal } from './EnrollmentDialogs';
import { EnrollmentTable } from './EnrollmentTable';

type Dialog = { kind: 'add' } | { kind: 'validity' } | { kind: 'cancel' } | { kind: 'teacher'; id: string; name: string };

// Participantes del curso: docentes y alumnos en un mismo lugar, cada uno con su papel
export const ParticipantsTab: React.FC<{ course: CourseDetail; canManageTeachers: boolean; onChanged: () => Promise<void> }> = ({
  course,
  canManageTeachers,
  onChanged,
}) => {
  const { hasPermission } = useAuth();
  const canEnroll = hasPermission('enrollments.create');
  const canUpdate = hasPermission('enrollments.update');
  const canCancel = hasPermission('enrollments.cancel');
  const canSeeStudents = hasPermission(['enrollments.read', 'submissions.review', 'submissions.assigned.review']);

  const [rows, setRows] = useState<Enrollment[]>([]);
  const [meta, setMeta] = useState<PageMeta | null>(null);
  const [page, setPage] = useState(1);
  const [stateFilter, setStateFilter] = useState('');
  const [state, setState] = useState<'loading' | 'ready' | 'error'>(canSeeStudents ? 'loading' : 'ready');
  const [selected, setSelected] = useState<string[]>([]);
  const [dialog, setDialog] = useState<Dialog | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    if (!canSeeStudents) return;
    try {
      const result = await enrollmentsApi.list(course.id, page, stateFilter);
      setRows(result.data);
      setMeta(result.meta);
      setState('ready');
    } catch {
      setState('error');
    }
  }, [course.id, page, stateFilter, canSeeStudents]);

  useEffect(() => {
    const timer = setTimeout(load, 0);
    return () => clearTimeout(timer);
  }, [load]);

  const teachers = course.teachers ?? [];
  const published = course.status === 'PUBLISHED';
  const counts = meta?.counts ?? {};
  // Un alumno solo se matricula en un curso publicado; un docente se puede asignar antes
  const roles: ParticipantRole[] = [...(canEnroll && published ? (['STUDENT'] as const) : []), ...(canManageTeachers ? (['TEACHER'] as const) : [])];

  const cards: FilterCard[] = [
    { key: '', label: 'Alumnos', value: counts.ALL ?? null, icon: Users, tone: 'brand', help: 'Todas las matrículas de este curso, también las canceladas y vencidas.' },
    { key: 'ACTIVE', label: 'Con acceso hoy', value: counts.ACTIVE ?? null, icon: GraduationCap, tone: 'success', help: 'Pueden entrar al curso hoy: su matrícula está activa y dentro de sus fechas.' },
    {
      key: 'EXPIRING',
      label: 'Por vencer',
      value: counts.EXPIRING ?? null,
      icon: CalendarClock,
      tone: 'warning',
      help: `Con acceso hoy, pero su matrícula termina en los próximos ${meta?.expiring_days ?? 7} días.`,
    },
    { key: 'COMPLETED', label: 'Completaron', value: counts.COMPLETED ?? null, icon: GraduationCap, tone: 'info', help: 'Terminaron todo el contenido del curso y siguen con acceso.' },
  ];

  const done = async () => {
    setSelected([]);
    await Promise.all([load(), onChanged()]);
    setDialog(null);
  };

  return (
    <div className="space-y-5">
      {error && <Alert>{error}</Alert>}

      <Card>
        <CardHeader
          title="Participantes"
          text="Cada persona participa con un papel en este curso: docente o alumno. La misma persona puede ser docente aquí y alumna en otro curso."
          action={
            roles.length > 0 && (
              <Button variant="primary" icon={<UserPlus className="w-4 h-4" />} onClick={() => setDialog({ kind: 'add' })}>
                Agregar participantes
              </Button>
            )
          }
        />

        {!published && (
          <div className="px-5 pt-4">
            <Alert tone="info">
              {course.status === 'ARCHIVED'
                ? 'El curso está archivado: no admite alumnos nuevos. Quienes ya estaban matriculados conservan su acceso.'
                : 'Ya puedes asignar docentes. Para matricular alumnos, publica el curso.'}
            </Alert>
          </div>
        )}

        <div className="px-5 py-4">
          <h3 className="flex items-center gap-2 text-[13px] font-semibold text-slate-900 mb-3">
            <Presentation className="w-4 h-4 text-violet-600" /> Docentes
            <Badge tone="violet">{teachers.length}</Badge>
          </h3>
          {teachers.length === 0 ? (
            <p className="text-[13px] text-slate-500">Este curso aún no tiene docentes. Un docente edita el contenido y revisa las tareas solo de los cursos donde participa.</p>
          ) : (
            <ul className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-2.5">
              {teachers.map((teacher) => (
                <li key={teacher.id} className="flex items-center gap-3 px-3 py-2.5 border border-slate-200 rounded-xl">
                  <Avatar name={teacher.name} />
                  <span className="min-w-0 flex-1">
                    <span className="block text-[13px] font-semibold text-slate-900 truncate">{teacher.name}</span>
                    <span className="block text-xs text-slate-500 truncate">{teacher.email}</span>
                  </span>
                  {canManageTeachers && (
                    <Button
                      size="sm"
                      aria-label={`Quitar a ${teacher.name} como docente`}
                      icon={<UserMinus className="w-3.5 h-3.5" />}
                      onClick={() => setDialog({ kind: 'teacher', id: teacher.id, name: teacher.name })}
                    />
                  )}
                </li>
              ))}
            </ul>
          )}
        </div>
      </Card>

      {canSeeStudents && (
        <div>
          <FilterCards cards={cards} active={stateFilter} label="Alumnos por situación" onSelect={(key) => { setStateFilter(key); setPage(1); setSelected([]); }} />

          <Card>
            <CardHeader
              title="Alumnos"
              text={
                course.access_days
                  ? `Cada matrícula nueva dura ${course.access_days} días; puedes cambiar la vigencia de cada alumno.`
                  : 'Las matrículas de este curso no vencen, salvo que les pongas fecha.'
              }
            />
            <BulkBar count={selected.length} noun={['matrícula elegida', 'matrículas elegidas']} onClear={() => setSelected([])}>
              {canUpdate && (
                <Button size="sm" icon={<CalendarClock className="w-3.5 h-3.5" />} onClick={() => setDialog({ kind: 'validity' })}>
                  Cambiar vigencia
                </Button>
              )}
              {canCancel && (
                <Button size="sm" variant="danger" icon={<UserMinus className="w-3.5 h-3.5" />} onClick={() => setDialog({ kind: 'cancel' })}>
                  Cancelar matrículas
                </Button>
              )}
            </BulkBar>

            {state === 'loading' && <LoadingState />}
            {state === 'error' && <ErrorState message="No se pudieron cargar las matrículas." onRetry={load} />}
            {state === 'ready' && rows.length === 0 && (
              <EmptyState
                icon={GraduationCap}
                title={stateFilter ? 'Ningún alumno en esta situación' : 'Aún no hay alumnos en este curso'}
                text={stateFilter ? 'Toca otra tarjeta o quita el filtro.' : 'Los alumnos que agregues aparecerán aquí.'}
              />
            )}
            {state === 'ready' && rows.length > 0 && (
              <EnrollmentTable
                rows={rows}
                canUpdate={canUpdate}
                canCancel={canCancel}
                selection={canUpdate || canCancel ? { selected, onChange: setSelected } : undefined}
                onChanged={load}
              />
            )}
            {state === 'ready' && meta && <Pagination page={meta.page} lastPage={meta.last_page} total={meta.total} noun="matrículas" onPage={setPage} />}
          </Card>
        </div>
      )}

      {dialog?.kind === 'add' && (
        <EnrollModal course={{ id: course.id, access_days: course.access_days }} roles={roles} onClose={() => setDialog(null)} onDone={done} />
      )}
      {dialog?.kind === 'validity' && <BulkValidityModal ids={selected} onClose={() => setDialog(null)} onDone={done} />}
      {dialog?.kind === 'cancel' && (
        <ConfirmModal
          title="Cancelar matrículas"
          text={`${selected.length === 1 ? 'La persona elegida dejará' : `Las ${selected.length} personas elegidas dejarán`} de tener acceso a «${course.title}». Las matrículas quedan en el historial y podrás matricularlas de nuevo.`}
          confirmLabel="Cancelar matrículas"
          onClose={() => setDialog(null)}
          onConfirm={async () => {
            await enrollmentsApi.bulkCancel(selected);
            await done();
          }}
        />
      )}
      {dialog?.kind === 'teacher' && (
        <ConfirmModal
          title="Quitar docente"
          text={`${dialog.name} dejará de ver y editar «${course.title}». Conserva su cuenta y los demás cursos donde participa.`}
          confirmLabel="Quitar docente"
          onClose={() => setDialog(null)}
          onConfirm={async () => {
            try {
              await enrollmentsApi.removeTeacher(course.id, dialog.id);
              await done();
            } catch (err) {
              setError(apiErrorMessage(err, 'No se pudo quitar al docente.'));
              setDialog(null);
            }
          }}
        />
      )}
    </div>
  );
};
