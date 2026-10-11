import React, { useCallback, useEffect, useState } from 'react';
import { Ban, CalendarClock, CircleCheckBig, ClipboardList, GraduationCap, Hourglass, Search, UserMinus, UserPlus } from 'lucide-react';
import { useAuth } from '../../auth/AuthContext';
import { apiErrorMessage } from '../../../shared/api/client';
import { Button, Card, ConfirmModal, EmptyState, ErrorState, INPUT_CLASS, LoadingState, PageHeader, Pagination } from '../../../shared/ui';
import { BulkBar, FilterCards, HelpTip, type FilterCard } from '../../../shared/ui/filters';
import type { PageMeta } from '../../identity/api';
import { enrollmentsApi, type EnrollableCourse, type Enrollment } from '../api';
import { BulkValidityModal, EnrollModal } from '../components/EnrollmentDialogs';
import { EnrollmentTable } from '../components/EnrollmentTable';
import { ExportButton } from '../../../shared/ui/ExportButton';
import { SearchSelect } from '../../../shared/ui/SearchSelect';

// Situaciones por las que se puede filtrar y qué significa cada una
const SITUATIONS: { key: string; label: string; help: string }[] = [
  { key: 'ACTIVE', label: 'Con acceso hoy', help: 'Pueden entrar al curso hoy: matrícula activa y dentro de sus fechas. Incluye a quienes ya lo completaron.' },
  { key: 'EXPIRING', label: 'Por vencer', help: 'Con acceso hoy, pero su matrícula termina dentro de pocos días.' },
  { key: 'EXPIRED', label: 'Vencidas', help: 'Su fecha de fin ya pasó: no pueden entrar, pero conservan su avance y sus notas.' },
  { key: 'COMPLETED', label: 'Completadas', help: 'Terminaron todo el contenido del curso y siguen con acceso.' },
  { key: 'UPCOMING', label: 'Por iniciar', help: 'Matrícula activa cuya fecha de inicio aún no llega.' },
  { key: 'CANCELLED', label: 'Canceladas', help: 'El personal las canceló. Quedan en el historial y no dan acceso.' },
];

export const EnrollmentsPage: React.FC = () => {
  const { hasPermission } = useAuth();
  const canEnroll = hasPermission('enrollments.create');
  const canUpdate = hasPermission('enrollments.update');
  const canCancel = hasPermission('enrollments.cancel');

  const [rows, setRows] = useState<Enrollment[]>([]);
  const [meta, setMeta] = useState<PageMeta | null>(null);
  const [courses, setCourses] = useState<EnrollableCourse[]>([]);
  const [search, setSearch] = useState('');
  const [courseId, setCourseId] = useState('');
  const [stateFilter, setStateFilter] = useState('');
  const [page, setPage] = useState(1);
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [errorMessage, setErrorMessage] = useState('');
  const [selected, setSelected] = useState<string[]>([]);
  const [dialog, setDialog] = useState<'enroll' | 'validity' | 'cancel' | null>(null);

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
    setSelected([]);
  };

  const counts = meta?.counts ?? {};
  const days = meta?.expiring_days ?? 7;
  const cards: FilterCard[] = [
    { key: '', label: 'Todas', value: counts.ALL ?? null, icon: ClipboardList, tone: 'brand', help: 'Todas las matrículas que coinciden con la búsqueda y el curso elegidos.' },
    { key: 'ACTIVE', label: 'Con acceso hoy', value: counts.ACTIVE ?? null, icon: GraduationCap, tone: 'success', help: SITUATIONS[0].help },
    { key: 'EXPIRING', label: 'Por vencer', value: counts.EXPIRING ?? null, icon: Hourglass, tone: 'warning', help: `Con acceso hoy, pero su matrícula termina en los próximos ${days} días.` },
    { key: 'EXPIRED', label: 'Vencidas', value: counts.EXPIRED ?? null, icon: CalendarClock, tone: 'danger', help: SITUATIONS[2].help },
    { key: 'COMPLETED', label: 'Completadas', value: counts.COMPLETED ?? null, icon: CircleCheckBig, tone: 'info', help: SITUATIONS[3].help },
    { key: 'CANCELLED', label: 'Canceladas', value: counts.CANCELLED ?? null, icon: Ban, tone: 'neutral', help: SITUATIONS[5].help },
  ];

  const done = async () => {
    setSelected([]);
    await load();
    setDialog(null);
  };

  return (
    <div>
      <PageHeader
        title="Matrículas"
        icon={ClipboardList}
        subtitle="Quién está matriculado en qué curso, hasta cuándo y cuánto ha avanzado."
        action={
          <>
            <ExportButton
              path="/enrollments/export"
              params={{ search: search || undefined, course_id: courseId || undefined, state: stateFilter || undefined }}
              filename="matriculas.xlsx"
            />
            {canEnroll && (
              <Button variant="primary" icon={<UserPlus className="w-4 h-4" />} onClick={() => setDialog('enroll')}>
                Matricular alumnos
              </Button>
            )}
          </>
        }
      />

      <FilterCards cards={cards} active={stateFilter} label="Matrículas por situación" onSelect={(key) => filter(() => setStateFilter(key))} />

      <Card>
        <div className="flex flex-wrap items-center gap-3 px-5 py-4 border-b border-slate-200/80">
          <div className="relative flex-1 min-w-[200px] max-w-sm">
            <Search className="w-4 h-4 text-slate-500 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" />
            <input
              className={`${INPUT_CLASS} pl-9`}
              placeholder="Buscar alumno por nombre o correo"
              aria-label="Buscar alumno"
              value={search}
              onChange={(event) => filter(() => setSearch(event.target.value))}
            />
          </div>
          <div className="flex items-center gap-1.5">
            <SearchSelect
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
            </SearchSelect>
            <HelpTip text="Muestra solo las matrículas de un curso. Las tarjetas de arriba se recalculan para ese curso." label="Qué hace el filtro por curso" />
          </div>
          <div className="flex items-center gap-1.5">
            <SearchSelect
              className={`${INPUT_CLASS} !w-48`}
              aria-label="Filtrar por situación"
              value={stateFilter}
              onChange={(event) => filter(() => setStateFilter(event.target.value))}
            >
              <option value="">Todas las situaciones</option>
              {SITUATIONS.map((option) => (
                <option key={option.key} value={option.key}>
                  {option.label}
                </option>
              ))}
            </SearchSelect>
            <HelpTip
              text={stateFilter ? (SITUATIONS.find((option) => option.key === stateFilter)?.help ?? '') : 'La situación se deduce de las fechas de cada matrícula. Elige una para ver qué significa.'}
              label="Qué significa la situación elegida"
            />
          </div>
        </div>

        <BulkBar count={selected.length} noun={['matrícula elegida', 'matrículas elegidas']} onClear={() => setSelected([])}>
          {canUpdate && (
            <Button size="sm" icon={<CalendarClock className="w-3.5 h-3.5" />} onClick={() => setDialog('validity')}>
              Cambiar vigencia
            </Button>
          )}
          {canCancel && (
            <Button size="sm" variant="danger" icon={<UserMinus className="w-3.5 h-3.5" />} onClick={() => setDialog('cancel')}>
              Cancelar matrículas
            </Button>
          )}
        </BulkBar>

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
                  <Button variant="primary" icon={<UserPlus className="w-4 h-4" />} onClick={() => setDialog('enroll')}>
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
            canUpdate={canUpdate}
            canCancel={canCancel}
            selection={canUpdate || canCancel ? { selected, onChange: setSelected } : undefined}
            onChanged={load}
          />
        )}
        {state === 'ready' && meta && <Pagination page={meta.page} lastPage={meta.last_page} total={meta.total} noun="matrículas" onPage={setPage} />}
      </Card>

      {dialog === 'enroll' && <EnrollModal courses={courses} onClose={() => setDialog(null)} onDone={done} />}
      {dialog === 'validity' && <BulkValidityModal ids={selected} onClose={() => setDialog(null)} onDone={done} />}
      {dialog === 'cancel' && (
        <ConfirmModal
          title="Cancelar matrículas"
          text={`${selected.length === 1 ? 'La matrícula elegida dejará' : `Las ${selected.length} matrículas elegidas dejarán`} de dar acceso a su curso. Quedan en el historial y podrás matricular a esas personas de nuevo.`}
          confirmLabel="Cancelar matrículas"
          onClose={() => setDialog(null)}
          onConfirm={async () => {
            await enrollmentsApi.bulkCancel(selected);
            await done();
          }}
        />
      )}
    </div>
  );
};
