import React, { useCallback, useEffect, useState } from 'react';
import { GraduationCap, UserPlus } from 'lucide-react';
import { useAuth } from '../../auth/AuthContext';
import { Alert, Button, Card, CardHeader, EmptyState, ErrorState, LoadingState, Pagination } from '../../../shared/ui';
import type { PageMeta } from '../../identity/api';
import type { CourseDetail } from '../../courses/api';
import { enrollmentsApi, type Enrollment } from '../api';
import { EnrollModal } from './EnrollmentDialogs';
import { EnrollmentTable } from './EnrollmentTable';

export const StudentsTab: React.FC<{ course: CourseDetail }> = ({ course }) => {
  const { hasPermission } = useAuth();
  const canEnroll = hasPermission('enrollments.create');

  const [rows, setRows] = useState<Enrollment[]>([]);
  const [meta, setMeta] = useState<PageMeta | null>(null);
  const [page, setPage] = useState(1);
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [enrolling, setEnrolling] = useState(false);

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

  const published = course.status === 'PUBLISHED';

  return (
    <Card>
      <CardHeader
        title="Alumnos matriculados"
        text={
          course.access_days
            ? `Cada matrícula nueva dura ${course.access_days} días; puedes cambiar la vigencia de cada alumno.`
            : 'Las matrículas de este curso no vencen, salvo que les pongas fecha.'
        }
        action={
          canEnroll && (
            <Button
              variant="primary"
              icon={<UserPlus className="w-4 h-4" />}
              disabled={!published}
              title={published ? undefined : 'Publica el curso para poder matricular'}
              onClick={() => setEnrolling(true)}
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
        <EnrollmentTable
          rows={rows}
          canUpdate={hasPermission('enrollments.update')}
          canCancel={hasPermission('enrollments.cancel')}
          onChanged={load}
        />
      )}
      {state === 'ready' && meta && <Pagination page={meta.page} lastPage={meta.last_page} total={meta.total} noun="matrículas" onPage={setPage} />}

      {enrolling && (
        <EnrollModal
          course={{ id: course.id, access_days: course.access_days }}
          onClose={() => setEnrolling(false)}
          onDone={async () => {
            await load();
            setEnrolling(false);
          }}
        />
      )}
    </Card>
  );
};
