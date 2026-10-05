import React, { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, Compass, ShieldCheck, ShoppingCart } from 'lucide-react';
import { useAuth } from '../../auth/AuthContext';
import { useTenant } from '../../../shared/tenancy/TenantContext';
import { apiError, apiErrorMessage } from '../../../shared/api/client';
import { withNext } from '../../../shared/auth/next';
import { Button, Card, EmptyState, ErrorState, LoadingState } from '../../../shared/ui';
import type { AvailableCourse, AvailableCourseDetail } from '../../enrollments/api';
import { COURSE_CARD_CLASS, salesApi } from '../api';
import { CourseCardBody, CourseCover, CourseFacts, CourseOverview, CoursePrice } from '../components/CourseCatalog';
import { PublicShell } from '../components/PublicShell';

// Catálogo público de la academia: los cursos en venta, sin iniciar sesión
export const PublicCatalogPage: React.FC = () => {
  const { tenant } = useTenant();
  const [courses, setCourses] = useState<AvailableCourse[]>([]);
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [error, setError] = useState('');
  const [attempt, setAttempt] = useState(0);

  useEffect(() => {
    let active = true;
    salesApi
      .catalog()
      .then((result) => {
        if (!active) return;
        setCourses(result);
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
  }, [attempt]);

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
      {state === 'ready' && courses.length === 0 && (
        <Card>
          <EmptyState icon={Compass} title="Aún no hay cursos en venta" text="Vuelve pronto: la academia está preparando su catálogo." />
        </Card>
      )}
      {state === 'ready' && courses.length > 0 && (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-5">
          {courses.map((course) => (
            <Link key={course.id} to={`/catalog/${course.id}`} className={COURSE_CARD_CLASS}>
              <CourseCardBody course={course} action="Ver curso" />
            </Link>
          ))}
        </div>
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
