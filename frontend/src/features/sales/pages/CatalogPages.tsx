import React, { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { ArrowLeft, Compass, Search, ShieldCheck, ShoppingCart } from 'lucide-react';
import { useAuth } from '../../auth/AuthContext';
import { useTenant } from '../../../shared/tenancy/TenantContext';
import { apiError, apiErrorMessage } from '../../../shared/api/client';
import { withNext } from '../../../shared/auth/next';
import { Button, Card, EmptyState, ErrorState, INPUT_CLASS, LoadingState } from '../../../shared/ui';
import { LEVEL_LABEL } from '../../courses/api';
import type { AvailableCourse, AvailableCourseDetail } from '../../enrollments/api';
import { COURSE_CARD_CLASS, salesApi, type CatalogMeta, type CatalogPackage } from '../api';
import { PackageCard } from './PackagePages';
import { CourseCardBody, CourseCover, CourseFacts, CourseOverview, CoursePrice } from '../components/CourseCatalog';
import { PublicShell } from '../components/PublicShell';

const SELECT_CLASS = `${INPUT_CLASS} sm:!w-auto sm:min-w-[9.5rem]`;

// Sin mayúsculas ni tildes, como compara el servidor
function plain(text: string): string {
  return text
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase();
}

// Catálogo público de la academia: los cursos en venta, sin iniciar sesión.
// La búsqueda y los filtros viven en la dirección, para poder compartir el enlace.
export const PublicCatalogPage: React.FC = () => {
  const { tenant } = useTenant();
  const [params, setParams] = useSearchParams();
  const search = params.get('q') ?? '';
  const category = params.get('category') ?? '';
  const level = params.get('level') ?? '';
  const price = params.get('price') ?? '';

  const [text, setText] = useState(search);
  const [courses, setCourses] = useState<AvailableCourse[]>([]);
  const [meta, setMeta] = useState<CatalogMeta | null>(null);
  const [packages, setPackages] = useState<CatalogPackage[]>([]);
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [error, setError] = useState('');
  const [attempt, setAttempt] = useState(0);

  const setFilter = useCallback(
    (key: string, value: string) => {
      setParams(
        (current) => {
          const next = new URLSearchParams(current);
          if (value) next.set(key, value);
          else next.delete(key);
          return next;
        },
        { replace: true },
      );
    },
    [setParams],
  );

  // La búsqueda espera a que el visitante termine de escribir
  useEffect(() => {
    if (text.trim() === search) return;
    const timer = setTimeout(() => setFilter('q', text.trim()), 300);
    return () => clearTimeout(timer);
  }, [text, search, setFilter]);

  useEffect(() => {
    let active = true;
    salesApi
      .catalog({
        search: search || undefined,
        category: category || undefined,
        level: level || undefined,
        price: price === 'free' || price === 'paid' ? price : undefined,
      })
      .then((result) => {
        if (!active) return;
        setCourses(result.courses);
        setMeta(result.meta);
        setState('ready');
      })
      .catch((err) => {
        if (!active) return;
        setError(apiErrorMessage(err, 'Verifica tu conexión e inténtalo nuevamente.'));
        setState('error');
      });
    return () => {
      active = false;
    };
  }, [search, category, level, price, attempt]);

  // Si los paquetes fallan, el catálogo de cursos se muestra igual
  useEffect(() => {
    let active = true;
    salesApi
      .catalogPackages()
      .then((bundles) => active && setPackages(bundles))
      .catch(() => undefined);
    return () => {
      active = false;
    };
  }, []);

  const filtered = search !== '' || category !== '' || level !== '' || price !== '';
  const clear = () => {
    setText('');
    setParams({}, { replace: true });
  };

  // Los paquetes no tienen categoría ni nivel: solo responden a la búsqueda por texto
  const words = plain(search).split(' ').filter(Boolean);
  const shownPackages =
    category || level || price
      ? []
      : packages.filter((item) => words.every((word) => plain(`${item.title} ${item.summary ?? ''}`).includes(word)));

  const total = meta?.total ?? 0;
  const hasOptions = meta !== null && (meta.categories.length > 1 || meta.levels.length > 1 || (meta.has_free && meta.has_paid));

  return (
    <PublicShell
      hero={
        <div className="max-w-2xl">
          <p className="text-xs font-semibold uppercase tracking-wider text-sky-300">Catálogo de cursos</p>
          <h1 className="mt-2 text-3xl sm:text-4xl font-extrabold tracking-tight text-white">Aprende con {tenant?.name}</h1>
          <p className="mt-3 text-sm sm:text-base text-brand-100">
            Elige un curso, revisa su temario y empieza cuando quieras. Solo necesitas crear tu cuenta para comprar.
          </p>
        </div>
      }
    >
      {state === 'loading' && <LoadingState />}
      {state === 'error' && (
        <Card>
          <ErrorState
            message={error}
            onRetry={() => {
              setState('loading');
              setAttempt((n) => n + 1);
            }}
          />
        </Card>
      )}
      {state === 'ready' && total === 0 && (
        <Card>
          <EmptyState icon={Compass} title="Aún no hay cursos en venta" text="Vuelve pronto: la academia está preparando su catálogo." />
        </Card>
      )}

      {state === 'ready' && total > 0 && meta && (
        <>
          {/* Buscador y filtros */}
          <Card className="p-4 mb-6">
            <form role="search" onSubmit={(event) => event.preventDefault()} className="flex flex-col sm:flex-row sm:flex-wrap sm:items-center gap-3">
              <div className="relative flex-1 min-w-[220px]">
                <Search className="w-4 h-4 text-slate-500 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" />
                <input
                  type="search"
                  className={`${INPUT_CLASS} pl-9`}
                  placeholder="Buscar un curso"
                  aria-label="Buscar en el catálogo"
                  value={text}
                  onChange={(event) => setText(event.target.value)}
                />
              </div>
              {meta.categories.length > 1 && (
                <select className={SELECT_CLASS} aria-label="Filtrar por categoría" value={category} onChange={(event) => setFilter('category', event.target.value)}>
                  <option value="">Todas las categorías</option>
                  {meta.categories.map((option) => (
                    <option key={option} value={option}>
                      {option}
                    </option>
                  ))}
                </select>
              )}
              {meta.levels.length > 1 && (
                <select className={SELECT_CLASS} aria-label="Filtrar por nivel" value={level} onChange={(event) => setFilter('level', event.target.value)}>
                  <option value="">Todos los niveles</option>
                  {meta.levels.map((option) => (
                    <option key={option} value={option}>
                      {LEVEL_LABEL[option]}
                    </option>
                  ))}
                </select>
              )}
              {meta.has_free && meta.has_paid && (
                <select className={SELECT_CLASS} aria-label="Filtrar por precio" value={price} onChange={(event) => setFilter('price', event.target.value)}>
                  <option value="">Cualquier precio</option>
                  <option value="free">Gratis</option>
                  <option value="paid">De pago</option>
                </select>
              )}
              {filtered && <Button onClick={clear}>Quitar filtros</Button>}
            </form>
            {(filtered || hasOptions) && (
              <p className="text-xs text-slate-500 mt-3" role="status">
                {filtered
                  ? `${courses.length} de ${total} ${total === 1 ? 'curso' : 'cursos'}`
                  : `${total} ${total === 1 ? 'curso' : 'cursos'} en venta`}
              </p>
            )}
          </Card>

          {shownPackages.length > 0 && (
            <section className="mb-8">
              <h2 className="text-lg font-bold text-slate-900 tracking-tight">Paquetes</h2>
              <p className="text-[13px] text-slate-500 mb-4">Varios cursos juntos a mejor precio.</p>
              <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-5">
                {shownPackages.map((item) => (
                  <PackageCard key={item.id} item={item} />
                ))}
              </div>
              {courses.length > 0 && <h2 className="text-lg font-bold text-slate-900 tracking-tight mt-8">Cursos</h2>}
            </section>
          )}

          {courses.length === 0 && shownPackages.length === 0 && (
            <Card>
              <EmptyState
                icon={Compass}
                title="Ningún curso coincide con tu búsqueda"
                text="Prueba con otras palabras o quita los filtros."
                action={<Button onClick={clear}>Quitar filtros</Button>}
              />
            </Card>
          )}

          {courses.length > 0 && (
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-5">
              {courses.map((course) => (
                <Link key={course.id} to={`/catalog/${course.id}`} className={COURSE_CARD_CLASS}>
                  <CourseCardBody course={course} action="Ver curso" />
                </Link>
              ))}
            </div>
          )}
        </>
      )}
    </PublicShell>
  );
};

// Ficha pública de un curso, con el botón de compra
export const PublicCoursePage: React.FC = () => {
  const { courseId = '' } = useParams();
  const navigate = useNavigate();
  const { isAuthenticated } = useAuth();
  const [course, setCourse] = useState<AvailableCourseDetail | null>(null);
  const [state, setState] = useState<'loading' | 'ready' | 'not_found' | 'error'>('loading');
  const [error, setError] = useState('');
  const [attempt, setAttempt] = useState(0);

  useEffect(() => {
    let active = true;
    salesApi
      .catalogCourse(courseId)
      .then((result) => {
        if (!active) return;
        setCourse(result);
        setState('ready');
      })
      .catch((err) => {
        if (!active) return;
        setError(apiErrorMessage(err, 'Verifica tu conexión e inténtalo nuevamente.'));
        setState(apiError(err)?.code === 'NOT_FOUND' ? 'not_found' : 'error');
      });
    return () => {
      active = false;
    };
  }, [courseId, attempt]);

  const checkout = `/checkout/${courseId}`;
  const free = Number(course?.price) === 0;

  return (
    <PublicShell>
      <Link to="/catalog" className="inline-flex items-center gap-1.5 text-[13px] font-semibold text-brand-700 hover:text-brand-900 mb-5">
        <ArrowLeft className="w-4 h-4" /> Todos los cursos
      </Link>

      {state === 'loading' && <LoadingState />}
      {state === 'error' && (
        <Card>
          <ErrorState
            message={error}
            onRetry={() => {
              setState('loading');
              setAttempt((n) => n + 1);
            }}
          />
        </Card>
      )}
      {state === 'not_found' && (
        <Card>
          <EmptyState icon={Compass} title="Este curso no está disponible" text="Puede que ya no esté en venta. Revisa los demás cursos del catálogo." />
        </Card>
      )}
      {state === 'ready' && course && (
        <div className="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_340px] gap-6 items-start">
          <Card className="overflow-hidden">
            <CourseCover url={course.cover_url} className="max-h-72" />
            <div className="p-5 sm:p-6 space-y-5">
              <div>
                {course.category && <p className="text-xs font-semibold text-brand-600 mb-1">{course.category}</p>}
                <h1 className="text-2xl font-bold tracking-tight text-slate-900">{course.title}</h1>
                <div className="mt-3">
                  <CourseFacts course={course} />
                </div>
              </div>
              <CourseOverview detail={course} />
            </div>
          </Card>

          <Card className="p-5 sm:p-6 lg:sticky lg:top-6">
            <p className="text-xs font-semibold text-slate-500">{free ? 'Acceso' : 'Precio'}</p>
            <CoursePrice course={course} className="block text-3xl mt-1" />
            <p className="text-[13px] text-slate-500 mt-2">
              {course.access_days ? `Acceso por ${course.access_days} días desde que se activa tu matrícula.` : 'Acceso sin vencimiento.'}
            </p>

            <Button
              variant="primary"
              size="lg"
              className="w-full mt-5"
              icon={<ShoppingCart className="w-4 h-4" />}
              onClick={() => navigate(isAuthenticated ? checkout : withNext('/signup', checkout))}
            >
              {free ? 'Inscribirme gratis' : 'Comprar ahora'}
            </Button>

            {!isAuthenticated && (
              <p className="text-[13px] text-slate-500 mt-3 text-center">
                ¿Ya tienes cuenta?{' '}
                <Link to={withNext('/login', checkout)} className="font-semibold text-brand-700 hover:text-brand-900">
                  Inicia sesión
                </Link>
              </p>
            )}

            <p className="flex items-start gap-2 text-xs text-slate-500 mt-5 pt-4 border-t border-slate-200/80">
              <ShieldCheck className="w-4 h-4 shrink-0 text-emerald-600" />
              {free
                ? 'Tu acceso se activa apenas te inscribes.'
                : 'Al comprar recibes las instrucciones de pago. Tu acceso se activa cuando la academia confirma el pago.'}
            </p>
          </Card>
        </div>
      )}
    </PublicShell>
  );
};
