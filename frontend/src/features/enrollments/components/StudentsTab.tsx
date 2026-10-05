import React, { useCallback, useEffect, useState } from 'react';
import { GraduationCap, Search, UserMinus, UserPlus } from 'lucide-react';
import { useAuth } from '../../auth/AuthContext';
import { apiErrorMessage } from '../../../shared/api/client';
import {
  Alert,
  Avatar,
  Badge,
  Button,
  Card,
  CardHeader,
  CHECKBOX_CLASS,
  ConfirmModal,
  EmptyState,
  ErrorState,
  INPUT_CLASS,
  LoadingState,
  Modal,
  Pagination,
  Table,
  TBody,
  Td,
  Th,
  THead,
  Tr,
} from '../../../shared/ui';
import type { PageMeta } from '../../identity/api';
import { formatDate, type CourseDetail } from '../../courses/api';
import { enrollmentsApi, type Enrollment, type StudentRef } from '../api';

const EnrollModal: React.FC<{ courseId: string; onClose: () => void; onDone: () => Promise<void> }> = ({ courseId, onClose, onDone }) => {
  const [search, setSearch] = useState('');
  const [candidates, setCandidates] = useState<StudentRef[]>([]);
  const [selected, setSelected] = useState<string[]>([]);
  const [loading, setLoading] = useState(true);
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
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
      await enrollmentsApi.enroll(courseId, selected);
      await onDone();
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo matricular.'));
      setProcessing(false);
    }
  };

  return (
    <Modal
      title="Matricular alumnos"
      subtitle="Tendrán acceso inmediato a la versión publicada del curso."
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" processing={processing} disabled={selected.length === 0} onClick={submit}>
            {selected.length > 0 ? `Matricular (${selected.length})` : 'Matricular'}
          </Button>
        </>
      }
    >
      <div className="space-y-3">
        {error && <Alert>{error}</Alert>}
        <div className="relative">
          <Search className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" />
          <input
            className={`${INPUT_CLASS} pl-9`}
            placeholder="Buscar por nombre o correo"
            aria-label="Buscar alumnos"
            autoFocus
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
          <ul className="rounded-xl border border-slate-200 divide-y divide-slate-100 max-h-80 overflow-y-auto">
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
    </Modal>
  );
};

export const StudentsTab: React.FC<{ course: CourseDetail }> = ({ course }) => {
  const { hasPermission } = useAuth();
  const canEnroll = hasPermission('enrollments.create');
  const canCancel = hasPermission('enrollments.cancel');

  const [rows, setRows] = useState<Enrollment[]>([]);
  const [meta, setMeta] = useState<PageMeta | null>(null);
  const [page, setPage] = useState(1);
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [dialog, setDialog] = useState<{ kind: 'enroll' } | { kind: 'cancel'; enrollment: Enrollment } | null>(null);

  const load = useCallback(async () => {
    try {
      const result = await enrollmentsApi.list(course.id, page);
      setRows(result.data);
      setMeta(result.meta);
      setState('ready');
    } catch {
      setState('error');
    }
  }, [course.id, page]);

  useEffect(() => {
    const timer = setTimeout(load, 0);
    return () => clearTimeout(timer);
  }, [load]);

  const closeAndReload = async () => {
    await load();
    setDialog(null);
  };

  const published = course.status === 'PUBLISHED';

  return (
    <Card>
      <CardHeader
        title="Alumnos matriculados"
        text="Cada alumno ve la versión publicada del curso desde «Mis cursos»."
        action={
          canEnroll && (
            <Button
              variant="primary"
              icon={<UserPlus className="w-4 h-4" />}
              disabled={!published}
              title={published ? undefined : 'Publica el curso para poder matricular'}
              onClick={() => setDialog({ kind: 'enroll' })}
            >
              Matricular alumnos
            </Button>
          )
        }
      />

      {!published && (
        <div className="px-5 pt-4">
          <Alert tone="info">
            {course.status === 'ARCHIVED'
              ? 'El curso está archivado: no admite matrículas nuevas. Quienes ya estaban matriculados conservan su acceso.'
              : 'Publica el curso para poder matricular alumnos.'}
          </Alert>
        </div>
      )}

      {state === 'loading' && <LoadingState />}
      {state === 'error' && <ErrorState message="No se pudieron cargar las matrículas." onRetry={load} />}
      {state === 'ready' && rows.length === 0 && (
        <EmptyState icon={GraduationCap} title="Aún no hay alumnos en este curso" text="Las matrículas que hagas aparecerán aquí." />
      )}
      {state === 'ready' && rows.length > 0 && (
        <Table>
          <THead>
            <Th>Alumno</Th>
            <Th>Estado</Th>
            <Th>Matriculado</Th>
            {canCancel && <Th />}
          </THead>
          <TBody>
            {rows.map((enrollment) => (
              <Tr key={enrollment.id}>
                <Td>
                  <div className="flex items-center gap-3">
                    <Avatar name={enrollment.student.name} />
                    <div className="min-w-0">
                      <p className="font-semibold text-slate-900 truncate">{enrollment.student.name}</p>
                      <p className="text-xs text-slate-500 truncate">{enrollment.student.email}</p>
                    </div>
                  </div>
                </Td>
                <Td>
                  <Badge tone={enrollment.status === 'ACTIVE' ? 'success' : 'neutral'} dot>
                    {enrollment.status === 'ACTIVE' ? 'Activa' : 'Cancelada'}
                  </Badge>
                </Td>
                <Td className="text-slate-500 whitespace-nowrap tnum">{formatDate(enrollment.enrolled_at)}</Td>
                {canCancel && (
                  <Td className="text-right">
                    {enrollment.status === 'ACTIVE' && (
                      <Button
                        size="sm"
                        variant="danger"
                        icon={<UserMinus className="w-3.5 h-3.5" />}
                        onClick={() => setDialog({ kind: 'cancel', enrollment })}
                      >
                        Cancelar
                      </Button>
                    )}
                  </Td>
                )}
              </Tr>
            ))}
          </TBody>
        </Table>
      )}
      {state === 'ready' && meta && <Pagination page={meta.page} lastPage={meta.last_page} total={meta.total} noun="matrículas" onPage={setPage} />}

      {dialog?.kind === 'enroll' && <EnrollModal courseId={course.id} onClose={() => setDialog(null)} onDone={closeAndReload} />}
      {dialog?.kind === 'cancel' && (
        <ConfirmModal
          title="Cancelar matrícula"
          text={`${dialog.enrollment.student.name} dejará de tener acceso al curso. La matrícula queda en el historial y podrás matricularlo de nuevo.`}
          confirmLabel="Cancelar matrícula"
          onClose={() => setDialog(null)}
          onConfirm={async () => {
            await enrollmentsApi.cancel(course.id, dialog.enrollment.id);
            await closeAndReload();
          }}
        />
      )}
    </Card>
  );
};
