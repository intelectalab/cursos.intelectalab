import React, { useCallback, useEffect, useState } from 'react';
import { ClipboardList, Search, UserPlus } from 'lucide-react';
import { useAuth } from '../../auth/AuthContext';
import { apiErrorMessage } from '../../../shared/api/client';
import { Button, Card, EmptyState, ErrorState, INPUT_CLASS, LoadingState, PageHeader, Pagination } from '../../../shared/ui';
import type { PageMeta } from '../../identity/api';
import { ENROLLMENT_STATES, enrollmentsApi, type EnrollableCourse, type Enrollment, type EnrollmentState } from '../api';
import { EnrollModal } from '../components/EnrollmentDialogs';
import { EnrollmentTable } from '../components/EnrollmentTable';

// "Con acceso hoy" reúne las activas y las completadas que siguen vigentes
const FILTER_LABEL: Record<EnrollmentState, string> = {
  ACTIVE: 'Con acceso hoy',
  UPCOMING: 'Por iniciar',
  EXPIRED: 'Vencidas',
  COMPLETED: 'Completadas',
  CANCELLED: 'Canceladas',
};

export const EnrollmentsPage: React.FC = () => {
  const { hasPermission } = useAuth();
  const canEnroll = hasPermission('enrollments.create');

  const [rows, setRows] = useState<Enrollment[]>([]);
  const [meta, setMeta] = useState<PageMeta | null>(null);
  const [courses, setCourses] = useState<EnrollableCourse[]>([]);
  const [search, setSearch] = useState('');
  const [courseId, setCourseId] = useState('');
  const [stateFilter, setStateFilter] = useState('');
  const [page, setPage] = useState(1);
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [errorMessage, setErrorMessage] = useState('');
  const [enrolling, setEnrolling] = useState(false);

  const load = useCallback(async () => {
    try {
      const result = await enrollmentsApi.all({
        search: search || undefined,
        course_id: courseId || undefined,
        state: stateFilter || undefined,
        page,
      });
      setRows(result.data);
      setMeta(result.meta);
      setState('ready');
    } catch (err) {
      setErrorMessage(apiErrorMessage(err, 'Verifica tu conexión e inténtalo nuevamente.'));
      setState('error');
    }
  }, [search, courseId, stateFilter, page]);

  // La búsqueda espera a que el usuario termine de escribir
  useEffect(() => {
    const timer = setTimeout(load, 250);
    return () => clearTimeout(timer);
  }, [load]);

  useEffect(() => {
    enrollmentsApi.options().then(setCourses).catch(() => setCourses([]));
  }, []);

  const filtered = search !== '' || courseId !== '' || stateFilter !== '';
  const filter = (apply: () => void) => {
    apply();
    setPage(1);
  };

  return (
    <div>
      <PageHeader
        title="Matrículas"
        icon={ClipboardList}
        subtitle="Quién está matriculado en qué curso, hasta cuándo y cuánto ha avanzado."
        action={
          canEnroll && (
            <Button variant="primary" icon={<UserPlus className="w-4 h-4" />} onClick={() => setEnrolling(true)}>
              Matricular alumnos
            </Button>
          )
        }
      />

      <Card>
        <div className="flex flex-wrap items-center gap-3 px-5 py-4 border-b border-slate-200/80">
          <div className="relative flex-1 min-w-[200px] max-w-sm">
            <Search className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" />
            <input
              className={`${INPUT_CLASS} pl-9`}
              placeholder="Buscar alumno por nombre o correo"
              aria-label="Buscar alumno"
              value={search}
              onChange={(event) => filter(() => setSearch(event.target.value))}
            />
          </div>
          <select
            className={`${INPUT_CLASS} !w-56`}
            aria-label="Filtrar por curso"
            value={courseId}
            onChange={(event) => filter(() => setCourseId(event.target.value))}
          >
            <option value="">Todos los cursos</option>
            {courses.map((course) => (
              <option key={course.id} value={course.id}>
                {course.title}
              </option>
            ))}
          </select>
          <select
            className={`${INPUT_CLASS} !w-44`}
            aria-label="Filtrar por situación"
            value={stateFilter}
            onChange={(event) => filter(() => setStateFilter(event.target.value))}
          >
            <option value="">Todas las situaciones</option>
            {ENROLLMENT_STATES.map((option) => (
              <option key={option} value={option}>
                {FILTER_LABEL[option]}
              </option>
            ))}
          </select>
        </div>

        {state === 'loading' && <LoadingState />}
        {state === 'error' && <ErrorState message={errorMessage} onRetry={load} />}
        {state === 'ready' && rows.length === 0 && (
          <EmptyState
            icon={ClipboardList}
            title={filtered ? 'Sin resultados con estos filtros' : 'Aún no hay matrículas'}
            text={filtered ? 'Prueba con otra búsqueda o cambia los filtros.' : 'Matricula alumnos en un curso publicado para que puedan cursarlo.'}
            action={
              filtered ? (
                <Button
                  onClick={() =>
                    filter(() => {
                      setSearch('');
                      setCourseId('');
                      setStateFilter('');
                    })
                  }
                >
                  Quitar filtros
                </Button>
              ) : (
                canEnroll && (
                  <Button variant="primary" icon={<UserPlus className="w-4 h-4" />} onClick={() => setEnrolling(true)}>
                    Matricular alumnos
                  </Button>
                )
              )
            }
          />
        )}
        {state === 'ready' && rows.length > 0 && (
          <EnrollmentTable
            rows={rows}
            showCourse
            canUpdate={hasPermission('enrollments.update')}
            canCancel={hasPermission('enrollments.cancel')}
            onChanged={load}
          />
        )}
        {state === 'ready' && meta && <Pagination page={meta.page} lastPage={meta.last_page} total={meta.total} noun="matrículas" onPage={setPage} />}
      </Card>

      {enrolling && (
        <EnrollModal
          courses={courses}
          onClose={() => setEnrolling(false)}
          onDone={async () => {
            await load();
            setEnrolling(false);
          }}
        />
      )}
    </div>
  );
};
