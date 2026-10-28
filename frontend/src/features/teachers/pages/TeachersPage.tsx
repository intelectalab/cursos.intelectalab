import React, { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { BookOpenCheck, Plus, Presentation, Search, UserMinus, UserX, Users } from 'lucide-react';
import { useAuth } from '../../auth/AuthContext';
import { apiErrorMessage } from '../../../shared/api/client';
import {
  Alert,
  Avatar,
  Badge,
  Button,
  Card,
  EmptyState,
  ErrorState,
  INPUT_CLASS,
  LoadingState,
  PageHeader,
  Pagination,
  Table,
  TBody,
  Td,
  Th,
  THead,
  Tr,
} from '../../../shared/ui';
import { SearchSelect } from '../../../shared/ui/SearchSelect';
import { useUrlFilters } from '../../../shared/filters/useUrlFilters';
import { useFilterOptions } from '../../../shared/filters/useFilterOptions';
import { FilterCards, type FilterCard } from '../../../shared/ui/filters';
import type { PageMeta } from '../../identity/api';
import { NewPersonModal } from '../../identity/components/NewPersonModal';
import { teachersApi, type TeacherFilter, type TeacherRow } from '../api';

function formatDate(value: string | null): string {
  if (!value) return 'Nunca';
  return new Date(value).toLocaleDateString('es-PE', { day: 'numeric', month: 'short', year: 'numeric' });
}

export const TeachersPage: React.FC = () => {
  const { hasPermission } = useAuth();
  // Dar de alta exige crear usuarios y poder leer los roles para asignar el de Docente
  const canCreate = hasPermission('users.create') && hasPermission('roles.read');

  const [rows, setRows] = useState<TeacherRow[]>([]);
  const [meta, setMeta] = useState<PageMeta | null>(null);
  // Los filtros viven en la dirección de la página
  const urlFilters = useUrlFilters({ search: '', filter: '', course_id: '' });
  const { search, course_id: courseId } = urlFilters.values;
  const filter = urlFilters.values.filter as TeacherFilter;
  const setSearch = (value: string) => urlFilters.set({ search: value });
  const setFilter = (value: TeacherFilter) => urlFilters.set({ filter: value });
  const setCourseId = (value: string) => urlFilters.set({ course_id: value });
  const options = useFilterOptions();
  const [page, setPage] = useState(1);
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [errorMessage, setErrorMessage] = useState('');
  const [creating, setCreating] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    setState('loading');
    try {
      const result = await teachersApi.list({ search: search || undefined, filter: filter || undefined, course_id: courseId || undefined, page });
      setRows(result.data);
      setMeta(result.meta);
      setState('ready');
    } catch (err) {
      setErrorMessage(apiErrorMessage(err, 'Verifica tu conexión e inténtalo nuevamente.'));
      setState('error');
    }
  }, [search, filter, courseId, page]);

  // La búsqueda espera a que el usuario termine de escribir
  useEffect(() => {
    const timer = setTimeout(load, 250);
    return () => clearTimeout(timer);
  }, [load]);

  const apply = (change: () => void) => {
    change();
    setPage(1);
  };

  const filtered = search !== '' || filter !== '' || courseId !== '';
  const counts = meta?.counts ?? {};
  const cards: FilterCard[] = [
    { key: '', label: 'Todos', value: counts.ALL ?? null, icon: Users, tone: 'brand', help: 'Todas las personas con el rol Docente o asignadas a algún curso, según la búsqueda.' },
    { key: 'WITH_COURSES', label: 'Con cursos', value: counts.WITH_COURSES ?? null, icon: BookOpenCheck, tone: 'success', help: 'Están asignados a por lo menos un curso que no está archivado.' },
    { key: 'WITHOUT_COURSES', label: 'Sin cursos', value: counts.WITHOUT_COURSES ?? null, icon: UserMinus, tone: 'warning', help: 'Tienen cuenta de docente pero ningún curso vigente asignado. Se asignan desde cada curso, en Participantes.' },
    { key: 'INACTIVE', label: 'Cuenta inactiva', value: counts.INACTIVE ?? null, icon: UserX, tone: 'neutral', help: 'Su cuenta está desactivada y no pueden iniciar sesión.' },
  ];

  return (
    <div>
      <PageHeader
        title="Docentes"
        icon={Presentation}
        subtitle="Quiénes dictan en tu academia, qué cursos tienen y cuántas entregas esperan su revisión."
        action={
          canCreate && (
            <Button variant="primary" icon={<Plus className="w-4 h-4" />} onClick={() => setCreating(true)}>
              Nuevo docente
            </Button>
          )
        }
      />

      <FilterCards cards={cards} active={filter} label="Docentes por situación" onSelect={(key) => apply(() => setFilter(key as TeacherFilter))} />

      {notice && (
        <div className="mb-4">
          <Alert tone="success">{notice}</Alert>
        </div>
      )}

      <Card>
        <div className="flex flex-wrap items-center gap-3 px-5 py-4 border-b border-slate-200/80">
          <div className="relative flex-1 min-w-[200px] max-w-sm">
            <Search className="w-4 h-4 text-slate-500 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" />
            <input
              className={`${INPUT_CLASS} pl-9`}
              placeholder="Buscar por nombre o correo"
              aria-label="Buscar docentes"
              value={search}
              onChange={(event) => apply(() => setSearch(event.target.value))}
            />
          </div>
          <SearchSelect className={`${INPUT_CLASS} !w-56`} aria-label="Filtrar por curso" value={courseId} onChange={(event) => ((value: string) => apply(() => setCourseId(value)))(event.target.value)}>
            <option value="">Todos los cursos</option>
            {options.courses.map((item) => (
              <option key={item.id} value={item.id}>
                {item.title}
              </option>
            ))}
          </SearchSelect>
        </div>

        {state === 'loading' && <LoadingState />}
        {state === 'error' && <ErrorState message={errorMessage} onRetry={load} />}
        {state === 'ready' && rows.length === 0 && (
          <EmptyState
            icon={Presentation}
            title={filtered ? 'Sin resultados con estos filtros' : 'Aún no hay docentes'}
            text={filtered ? 'Prueba con otra búsqueda o quita el filtro.' : 'Crea uno aquí, o da el papel de docente a alguien desde Participantes de un curso.'}
            action={
              filtered && (
                <Button
                  onClick={() =>
                    apply(() => {
                      setSearch('');
                      setFilter('');
                      setCourseId('');
                    })
                  }
                >
                  Quitar filtros
                </Button>
              )
            }
          />
        )}
        {state === 'ready' && rows.length > 0 && (
          <Table>
            <THead>
              <Th>Docente</Th>
              <Th>Cursos</Th>
              <Th>Por calificar</Th>
              <Th>Último acceso</Th>
              <Th>Cuenta</Th>
              <Th />
            </THead>
            <TBody>
              {rows.map((teacher) => (
                <Tr key={teacher.id}>
                  <Td>
                    <div className="flex items-center gap-3">
                      <Avatar name={teacher.name} size="sm" />
                      <div className="min-w-0">
                        <Link to={`/teachers/${teacher.id}`} className="font-semibold text-slate-900 hover:text-brand-700 whitespace-nowrap">
                          {teacher.name}
                        </Link>
                        <p className="text-xs text-slate-500">{teacher.email}</p>
                      </div>
                    </div>
                  </Td>
                  <Td className="whitespace-nowrap">
                    {teacher.courses_count === 0 ? <span className="text-slate-500">Sin cursos</span> : <span className="font-semibold text-slate-800 tnum">{teacher.courses_count}</span>}
                  </Td>
                  <Td className="whitespace-nowrap">
                    {teacher.pending_submissions === 0 ? (
                      <span className="text-slate-500">Al día</span>
                    ) : (
                      <Badge tone="warning" dot>
                        {teacher.pending_submissions} {teacher.pending_submissions === 1 ? 'entrega' : 'entregas'}
                      </Badge>
                    )}
                  </Td>
                  <Td className="whitespace-nowrap text-slate-500 tnum">{formatDate(teacher.last_login_at)}</Td>
                  <Td>
                    <Badge tone={teacher.status === 'ACTIVE' ? 'success' : 'neutral'} dot>
                      {teacher.status === 'ACTIVE' ? 'Activa' : 'Inactiva'}
                    </Badge>
                  </Td>
                  <Td className="text-right">
                    <Link
                      to={`/teachers/${teacher.id}`}
                      aria-label={`Ver la ficha de ${teacher.name}`}
                      className="inline-flex items-center h-8 px-3 rounded-lg border border-slate-300 bg-white text-[13px] font-semibold text-slate-700 hover:bg-slate-50"
                    >
                      Ver ficha
                    </Link>
                  </Td>
                </Tr>
              ))}
            </TBody>
          </Table>
        )}
        {state === 'ready' && meta && <Pagination page={meta.page} lastPage={meta.last_page} total={meta.total} noun="docentes" onPage={setPage} />}
      </Card>

      {creating && (
        <NewPersonModal
          roleCode="TEACHER"
          title="Nuevo docente"
          subtitle="Se crea su cuenta con el rol Docente. Después asígnalo a sus cursos desde Participantes."
          submitLabel="Crear docente"
          passwordHint="Mínimo 8 caracteres. Entrégasela al docente; podrá cambiarla desde su cuenta."
          onClose={() => setCreating(false)}
          onCreated={() => {
            setCreating(false);
            setNotice('Docente creado. Asígnalo a sus cursos desde la pestaña Participantes de cada curso.');
            void load();
          }}
        />
      )}
    </div>
  );
};
