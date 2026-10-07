import React, { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Archive, BookOpen, Clock, FileStack, GraduationCap, Layers, PencilRuler, Plus, Rocket, Search } from 'lucide-react';
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
  Modal,
  PageHeader,
  Pagination,
} from '../../../shared/ui';
import { FilterCards, HelpTip, type FilterCard } from '../../../shared/ui/filters';
import type { PageMeta } from '../../identity/api';
import {
  coursesApi,
  EMPTY_COURSE,
  LEVEL_LABEL,
  STATUS_LABEL,
  STATUS_TONE,
  type CourseInput,
  type CourseOptions,
  type CourseSummary,
} from '../api';
import { CourseFields } from '../components/CourseForm';

const NewCourseModal: React.FC<{ options: CourseOptions | null; onClose: () => void }> = ({ options, onClose }) => {
  const navigate = useNavigate();
  const [form, setForm] = useState<CourseInput>(EMPTY_COURSE);
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setProcessing(true);
    setError(null);
    try {
      const course = await coursesApi.create(form);
      // El curso nace como borrador: se continúa armando su contenido
      navigate(`/courses/${course.id}`);
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo crear el curso.'));
      setProcessing(false);
    }
  };

  return (
    <Modal
      title="Nuevo curso"
      subtitle="Se crea como borrador. Nadie lo verá hasta que lo publiques."
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="new-course" processing={processing}>
            Crear y armar contenido
          </Button>
        </>
      }
    >
      <form id="new-course" onSubmit={submit} className="space-y-4">
        {error && <Alert>{error}</Alert>}
        <CourseFields value={form} options={options} onChange={setForm} />
      </form>
    </Modal>
  );
};

const CourseCard: React.FC<{ course: CourseSummary }> = ({ course }) => (
  <Link
    to={`/courses/${course.id}`}
    className="group flex flex-col bg-white border border-slate-200/80 rounded-2xl shadow-card overflow-hidden hover:shadow-raised hover:-translate-y-0.5 transition-all"
  >
    <div className="relative aspect-[16/9] bg-gradient-to-br from-brand-800 via-brand-700 to-sky-500 overflow-hidden">
      {course.cover_url ? (
        <img src={course.cover_url} alt="" className="w-full h-full object-cover" loading="lazy" />
      ) : (
        <div className="absolute inset-0 flex items-center justify-center">
          <BookOpen className="w-10 h-10 text-white/40" aria-hidden="true" />
        </div>
      )}
      <div className="absolute top-3 left-3 flex flex-wrap gap-1.5">
        <Badge tone={STATUS_TONE[course.status]} dot>
          {STATUS_LABEL[course.status]}
        </Badge>
        {course.pending_changes && <Badge tone="info">Cambios sin publicar</Badge>}
      </div>
    </div>

    <div className="flex flex-col flex-1 p-4">
      {course.category && <p className="text-xs font-semibold text-brand-600 mb-1">{course.category}</p>}
      <h2 className="text-[15px] leading-5 font-semibold text-slate-900 group-hover:text-brand-700 transition-colors line-clamp-2">
        {course.title}
      </h2>
      {course.summary && <p className="text-[13px] text-slate-500 mt-1.5 line-clamp-2">{course.summary}</p>}

      <div className="flex flex-wrap gap-x-4 gap-y-1.5 mt-3 text-xs text-slate-500">
        <span className="inline-flex items-center gap-1.5">
          <Layers className="w-3.5 h-3.5" /> {course.sections_count} {course.sections_count === 1 ? 'sección' : 'secciones'}
        </span>
        <span className="inline-flex items-center gap-1.5">
          <FileStack className="w-3.5 h-3.5" /> {course.resources_count} {course.resources_count === 1 ? 'recurso' : 'recursos'}
        </span>
        {course.duration_hours && (
          <span className="inline-flex items-center gap-1.5">
            <Clock className="w-3.5 h-3.5" /> {course.duration_hours} h
          </span>
        )}
        {course.level && (
          <span className="inline-flex items-center gap-1.5">
            <GraduationCap className="w-3.5 h-3.5" /> {LEVEL_LABEL[course.level]}
          </span>
        )}
      </div>

      <div className="flex items-center justify-between gap-3 mt-auto pt-4">
        <div className="flex -space-x-1.5" title={course.teachers.map((teacher) => teacher.name).join(', ') || undefined}>
          {course.teachers.slice(0, 4).map((teacher) => (
            <span key={teacher.id} className="rounded-full ring-2 ring-white">
              <Avatar name={teacher.name} size="sm" />
            </span>
          ))}
          {course.teachers.length === 0 && <span className="text-xs text-slate-500">Sin profesores</span>}
        </div>
        <span className="text-xs font-medium text-slate-500 tnum">{course.version ? `Versión ${course.version}` : 'Sin publicar'}</span>
      </div>
    </div>
  </Link>
);

export const CoursesPage: React.FC = () => {
  const { hasPermission } = useAuth();
  const canCreate = hasPermission('courses.create');
  const seesAll = hasPermission('courses.read');

  const [rows, setRows] = useState<CourseSummary[]>([]);
  const [meta, setMeta] = useState<PageMeta | null>(null);
  const [options, setOptions] = useState<CourseOptions | null>(null);
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [category, setCategory] = useState('');
  const [page, setPage] = useState(1);
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [errorMessage, setErrorMessage] = useState('');
  const [creating, setCreating] = useState(false);

  const load = useCallback(async () => {
    setState('loading');
    try {
      const result = await coursesApi.list({
        search: search || undefined,
        status: status || undefined,
        category: category || undefined,
        page,
      });
      setRows(result.data);
      setMeta(result.meta);
      setState('ready');
    } catch (err) {
      setErrorMessage(apiErrorMessage(err, 'Verifica tu conexión e inténtalo nuevamente.'));
      setState('error');
    }
  }, [search, status, category, page]);

  // La búsqueda espera a que el usuario termine de escribir
  useEffect(() => {
    const timer = setTimeout(load, 250);
    return () => clearTimeout(timer);
  }, [load]);

  useEffect(() => {
    coursesApi.options().then(setOptions).catch(() => setOptions(null));
  }, []);

  const filtered = search !== '' || status !== '' || category !== '';
  const counts = meta?.counts ?? {};
  const cards: FilterCard[] = [
    { key: '', label: 'Todos', value: counts.ALL ?? null, icon: BookOpen, tone: 'brand', help: 'Todos los cursos que coinciden con la búsqueda y la categoría.' },
    { key: 'DRAFT', label: 'Borradores', value: counts.DRAFT ?? null, icon: PencilRuler, tone: 'warning', help: 'Aún no se publican: solo los ve el personal. No admiten alumnos.' },
    { key: 'PUBLISHED', label: 'Publicados', value: counts.PUBLISHED ?? null, icon: Rocket, tone: 'success', help: 'Sus alumnos los pueden llevar. Si tienen precio, aparecen también en el catálogo público.' },
    { key: 'ARCHIVED', label: 'Archivados', value: counts.ARCHIVED ?? null, icon: Archive, tone: 'neutral', help: 'Retirados: no admiten alumnos nuevos ni se venden. Quienes ya estaban matriculados conservan su acceso.' },
  ];
  const clearFilters = () => {
    setSearch('');
    setStatus('');
    setCategory('');
    setPage(1);
  };

  return (
    <div>
      <PageHeader
        title="Cursos"
        icon={BookOpen}
        subtitle={seesAll ? 'Crea los cursos de tu academia, arma su contenido y publícalos.' : 'Cursos en los que estás asignado como profesor.'}
        action={
          canCreate && (
            <Button variant="primary" icon={<Plus className="w-4 h-4" />} onClick={() => setCreating(true)}>
              Nuevo curso
            </Button>
          )
        }
      />

      <FilterCards
        cards={cards}
        active={status}
        label="Cursos por estado"
        onSelect={(key) => {
          setStatus(key);
          setPage(1);
        }}
      />

      <Card className="mb-5">
        <div className="flex flex-wrap items-center gap-3 px-4 sm:px-5 py-4">
          <div className="relative flex-1 min-w-[200px] max-w-sm">
            <Search className="w-4 h-4 text-slate-500 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" />
            <input
              className={`${INPUT_CLASS} pl-9`}
              placeholder="Buscar por título"
              aria-label="Buscar cursos"
              value={search}
              onChange={(event) => {
                setSearch(event.target.value);
                setPage(1);
              }}
            />
          </div>
          <select
            className={`${INPUT_CLASS} !w-44`}
            aria-label="Filtrar por estado"
            value={status}
            onChange={(event) => {
              setStatus(event.target.value);
              setPage(1);
            }}
          >
            <option value="">Todos los estados</option>
            <option value="DRAFT">Borradores</option>
            <option value="PUBLISHED">Publicados</option>
            <option value="ARCHIVED">Archivados</option>
          </select>
          <HelpTip
            text="Borrador: en preparación, solo lo ve el personal. Publicado: sus alumnos lo pueden llevar. Archivado: retirado, sin alumnos nuevos."
            label="Qué significa el estado de un curso"
          />
          {(options?.categories.length ?? 0) > 0 && (
            <select
              className={`${INPUT_CLASS} !w-48`}
              aria-label="Filtrar por categoría"
              value={category}
              onChange={(event) => {
                setCategory(event.target.value);
                setPage(1);
              }}
            >
              <option value="">Todas las categorías</option>
              {options?.categories.map((item) => (
                <option key={item} value={item}>
                  {item}
                </option>
              ))}
            </select>
          )}
        </div>
      </Card>

      {state === 'loading' && <LoadingState />}
      {state === 'error' && (
        <Card>
          <ErrorState message={errorMessage} onRetry={load} />
        </Card>
      )}
      {state === 'ready' && rows.length === 0 && (
        <Card>
          <EmptyState
            icon={BookOpen}
            title={filtered ? 'Sin resultados con estos filtros' : seesAll ? 'Aún no hay cursos' : 'Aún no tienes cursos asignados'}
            text={
              filtered
                ? 'Prueba con otra búsqueda o cambia los filtros.'
                : seesAll
                  ? 'Crea el primero: empieza como borrador y lo publicas cuando esté listo.'
                  : 'Cuando te asignen como profesor de un curso, aparecerá aquí.'
            }
            action={
              filtered ? (
                <Button onClick={clearFilters}>Quitar filtros</Button>
              ) : (
                canCreate && (
                  <Button variant="primary" icon={<Plus className="w-4 h-4" />} onClick={() => setCreating(true)}>
                    Nuevo curso
                  </Button>
                )
              )
            }
          />
        </Card>
      )}

      {state === 'ready' && rows.length > 0 && (
        <>
          <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4 gap-4 sm:gap-5">
            {rows.map((course) => (
              <CourseCard key={course.id} course={course} />
            ))}
          </div>
          {meta && meta.last_page > 1 && (
            <Card className="mt-5">
              <Pagination page={meta.page} lastPage={meta.last_page} total={meta.total} noun="cursos" onPage={setPage} />
            </Card>
          )}
        </>
      )}

      {creating && <NewCourseModal options={options} onClose={() => setCreating(false)} />}
    </div>
  );
};
