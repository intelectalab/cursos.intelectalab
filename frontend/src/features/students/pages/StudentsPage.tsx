import React, { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { GraduationCap, Plus, Search, UserCheck, UserMinus, UserX, Users } from 'lucide-react';
import { useAuth } from '../../auth/AuthContext';
import { apiErrorMessage } from '../../../shared/api/client';
import {
  Alert,
  Avatar,
  Badge,
  Button,
  Card,
  CHECKBOX_CLASS,
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
import { FilterCards, type FilterCard } from '../../../shared/ui/filters';
import type { PageMeta } from '../../identity/api';
import { NewPersonModal } from '../../identity/components/NewPersonModal';
import { studentsApi, type StudentFilter, type StudentRow } from '../api';
import { ExportButton } from '../../../shared/ui/ExportButton';
import { SearchSelect } from '../../../shared/ui/SearchSelect';
import { ClearFilters, DateRangeFilter } from '../../../shared/ui/listFilters';
import { useUrlFilters } from '../../../shared/filters/useUrlFilters';
import { useFilterOptions } from '../../../shared/filters/useFilterOptions';

function formatDate(value: string | null): string {
  if (!value) return 'Nunca';
  return new Date(value).toLocaleDateString('es-PE', { day: 'numeric', month: 'short', year: 'numeric' });
}

/* ───────────────────────────── Listado ───────────────────────────── */

export const StudentsPage: React.FC = () => {
  const { hasPermission } = useAuth();
  // Dar de alta exige crear usuarios y poder leer los roles para asignar el de Alumno
  const canCreate = hasPermission('users.create') && hasPermission('roles.read');

  const [rows, setRows] = useState<StudentRow[]>([]);
  const [meta, setMeta] = useState<PageMeta | null>(null);
  // Todos los filtros viven en la dirección de la página
  const { values, set, clear, active: filtered, query } = useUrlFilters({ search: '', filter: '', course_id: '', group_id: '', company_id: '', debt: '', from: '', to: '' });
  const { search } = values;
  const filter = values.filter as StudentFilter;
  const options = useFilterOptions();
  const [page, setPage] = useState(1);
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [errorMessage, setErrorMessage] = useState('');
  const [creating, setCreating] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    setState('loading');
    try {
      const result = await studentsApi.list({ ...query, page });
      setRows(result.data);
      setMeta(result.meta);
      setState('ready');
    } catch (err) {
      setErrorMessage(apiErrorMessage(err, 'Verifica tu conexión e inténtalo nuevamente.'));
      setState('error');
    }
  }, [query, page]);

  // La búsqueda espera a que el usuario termine de escribir
  useEffect(() => {
    const timer = setTimeout(load, 250);
    return () => clearTimeout(timer);
  }, [load]);

  const apply = (patch: Partial<typeof values>) => {
    set(patch);
    setPage(1);
  };
  const counts = meta?.counts ?? {};
  const cards: FilterCard[] = [
    { key: '', label: 'Todos', value: counts.ALL ?? null, icon: Users, tone: 'brand', help: 'Todas las personas con el rol Alumno o con alguna matrícula, según la búsqueda.' },
    { key: 'ENROLLED', label: 'Con cursos', value: counts.ENROLLED ?? null, icon: UserCheck, tone: 'success', help: 'Tienen al menos una matrícula activa, vigente o no.' },
    { key: 'NOT_ENROLLED', label: 'Sin cursos', value: counts.NOT_ENROLLED ?? null, icon: UserMinus, tone: 'warning', help: 'Tienen cuenta pero ninguna matrícula activa: se registraron y no compraron, o se les anuló la matrícula.' },
    { key: 'INACTIVE', label: 'Cuenta inactiva', value: counts.INACTIVE ?? null, icon: UserX, tone: 'neutral', help: 'Su cuenta está desactivada y no pueden iniciar sesión. Conservan su historial.' },
  ];

  return (
    <div>
      <PageHeader
        title="Alumnos"
        icon={GraduationCap}
        subtitle="Quiénes estudian en tu academia. Abre un alumno para ver sus cursos, compras, certificados y consultas en un solo lugar."
        action={
          <>
            <ExportButton path="/students/export" params={query} filename="alumnos.xlsx" />
            {canCreate && (
              <Button variant="primary" icon={<Plus className="w-4 h-4" />} onClick={() => setCreating(true)}>
                Nuevo alumno
              </Button>
            )}
          </>
        }
      />

      <FilterCards cards={cards} active={filter} label="Alumnos por situación" onSelect={(key) => apply({ filter: key })} />

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
              placeholder="Buscar por nombre, correo o documento"
              aria-label="Buscar alumnos"
              value={search}
              onChange={(event) => apply({ search: event.target.value })}
            />
          </div>
          <SearchSelect className={`${INPUT_CLASS} !w-56`} aria-label="Filtrar por curso" value={values.course_id} onChange={(event) => apply({ course_id: event.target.value })}>
            <option value="">Todos los cursos</option>
            {options.courses.map((course) => (
              <option key={course.id} value={course.id}>
                {course.title}
              </option>
            ))}
          </SearchSelect>
          {options.groups.length > 0 && (
            <SearchSelect className={`${INPUT_CLASS} !w-44`} aria-label="Filtrar por grupo" value={values.group_id} onChange={(event) => apply({ group_id: event.target.value })}>
              <option value="">Todos los grupos</option>
              {options.groups.map((group) => (
                <option key={group.id} value={group.id}>
                  {group.name}
                </option>
              ))}
            </SearchSelect>
          )}
          {options.companies.length > 0 && (
            <SearchSelect className={`${INPUT_CLASS} !w-48`} aria-label="Filtrar por empresa" value={values.company_id} onChange={(event) => apply({ company_id: event.target.value })}>
              <option value="">Todas las empresas</option>
              {options.companies.map((company) => (
                <option key={company.id} value={company.id}>
                  {company.name}
                </option>
              ))}
            </SearchSelect>
          )}
          <label className="inline-flex items-center gap-2 text-[13px] text-slate-700 cursor-pointer">
            <input type="checkbox" className={CHECKBOX_CLASS} checked={values.debt === '1'} onChange={(event) => apply({ debt: event.target.checked ? '1' : '' })} />
            Con cuotas vencidas
          </label>
          <DateRangeFilter label="Registrados" from={values.from} to={values.to} onChange={(range) => apply(range)} />
          <ClearFilters active={filtered} onClear={() => apply(Object.fromEntries(Object.keys(values).map((key) => [key, ''])))} />
        </div>

        {state === 'loading' && <LoadingState />}
        {state === 'error' && <ErrorState message={errorMessage} onRetry={load} />}
        {state === 'ready' && rows.length === 0 && (
          <EmptyState
            icon={GraduationCap}
            title={filtered ? 'Sin resultados con estos filtros' : 'Aún no hay alumnos'}
            text={filtered ? 'Prueba con otra búsqueda o quita el filtro.' : 'Aparecerán aquí cuando se registren, los crees o los importes desde Usuarios.'}
            action={
              filtered && (
                <Button
                  onClick={() => {
                    clear();
                    setPage(1);
                  }}
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
              <Th>Alumno</Th>
              <Th>Documento</Th>
              <Th>Cursos</Th>
              <Th>Último acceso</Th>
              <Th>Cuenta</Th>
              <Th />
            </THead>
            <TBody>
              {rows.map((student) => (
                <Tr key={student.id}>
                  <Td>
                    <div className="flex items-center gap-3">
                      <Avatar name={student.name} size="sm" />
                      <div className="min-w-0">
                        <Link to={`/students/${student.id}`} className="font-semibold text-slate-900 hover:text-brand-700 whitespace-nowrap">
                          {student.name}
                        </Link>
                        <p className="text-xs text-slate-500">{student.email}</p>
                      </div>
                    </div>
                  </Td>
                  <Td className="whitespace-nowrap text-slate-600">{student.document ?? <span className="text-slate-300">—</span>}</Td>
                  <Td className="whitespace-nowrap">
                    {student.active_enrollments === 0 ? (
                      <span className="text-slate-500">Sin cursos</span>
                    ) : (
                      <span className="text-slate-800">
                        <span className="font-semibold tnum">{student.active_enrollments}</span>
                        {student.completed_courses > 0 && (
                          <span className="text-xs text-emerald-700 ml-2">
                            {student.completed_courses} {student.completed_courses === 1 ? 'completado' : 'completados'}
                          </span>
                        )}
                      </span>
                    )}
                  </Td>
                  <Td className="whitespace-nowrap text-slate-500 tnum">{formatDate(student.last_login_at)}</Td>
                  <Td>
                    <Badge tone={student.status === 'ACTIVE' ? 'success' : 'neutral'} dot>
                      {student.status === 'ACTIVE' ? 'Activa' : 'Inactiva'}
                    </Badge>
                  </Td>
                  <Td className="text-right">
                    <Link
                      to={`/students/${student.id}`}
                      aria-label={`Ver la ficha de ${student.name}`}
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
        {state === 'ready' && meta && <Pagination page={meta.page} lastPage={meta.last_page} total={meta.total} noun="alumnos" onPage={setPage} />}
      </Card>

      {creating && (
        <NewPersonModal
          roleCode="STUDENT"
          title="Nuevo alumno"
          subtitle="Se crea su cuenta con el rol Alumno. Después podrás matricularlo en sus cursos."
          submitLabel="Crear alumno"
          passwordHint="Mínimo 8 caracteres. Entrégasela al alumno; podrá cambiarla desde su cuenta."
          onClose={() => setCreating(false)}
          onCreated={() => {
            setCreating(false);
            setNotice('Alumno creado. Ya puedes matricularlo desde el curso o desde Matrículas.');
            void load();
          }}
        />
      )}
    </div>
  );
};
