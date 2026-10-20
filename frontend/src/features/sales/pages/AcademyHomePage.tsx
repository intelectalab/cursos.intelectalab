import React, { useEffect, useState } from 'react';
import { Link, Navigate } from 'react-router-dom';
import { ArrowRight, Award, BookOpen, Clock, LogIn } from 'lucide-react';
import { useAuth } from '../../auth/AuthContext';
import { useTenant } from '../../../shared/tenancy/TenantContext';
import { EmptyState, LoadingState } from '../../../shared/ui';
import type { AvailableCourse } from '../../enrollments/api';
import { COURSE_CARD_CLASS, salesApi } from '../api';
import { CourseCardBody } from '../components/CourseCatalog';
import { PublicShell } from '../components/PublicShell';

// Cuántos cursos se muestran en la portada; el resto está en el catálogo
const FEATURED = 6;

const BUTTON = 'inline-flex items-center justify-center gap-2 h-11 px-5 rounded-xl text-[14px] font-semibold transition-colors';

/**
 * Portada de la academia: su nombre, sus cursos destacados y los accesos.
 * Quien ya tiene sesión abierta va directo a su Inicio.
 */
export const AcademyHomePage: React.FC = () => {
  const { tenant } = useTenant();
  const { isAuthenticated } = useAuth();
  const [courses, setCourses] = useState<AvailableCourse[] | null>(null);
  const [total, setTotal] = useState(0);

  useEffect(() => {
    if (isAuthenticated) return;
    let active = true;
    salesApi
      .catalog()
      .then((result) => {
        if (!active) return;
        setCourses(result.courses.slice(0, FEATURED));
        setTotal(result.meta?.total ?? result.courses.length);
      })
      // Sin cursos que mostrar la portada sigue sirviendo para entrar
      .catch(() => active && setCourses([]));
    return () => {
      active = false;
    };
  }, [isAuthenticated]);

  if (isAuthenticated) return <Navigate to="/dashboard" replace />;

  const hero = (
    <div className="max-w-2xl">
      <h1 className="text-3xl sm:text-5xl font-extrabold tracking-tight leading-[1.08] text-white">{tenant?.name}</h1>
      <p className="mt-4 text-[15px] sm:text-base leading-relaxed text-sky-100/90">
        Aprende a tu ritmo, desde cualquier dispositivo, y obtén tu certificado al terminar cada curso.
      </p>
      <div className="mt-7 flex flex-wrap gap-3">
        <Link to="/catalog" className={`${BUTTON} bg-white text-brand-800 hover:bg-sky-50`}>
          Ver cursos
          <ArrowRight className="w-4 h-4" aria-hidden="true" />
        </Link>
        <Link to="/login" className={`${BUTTON} text-white ring-1 ring-white/30 hover:bg-white/10`}>
          <LogIn className="w-4 h-4" aria-hidden="true" />
          Ya tengo una cuenta
        </Link>
      </div>
      <ul className="mt-8 flex flex-wrap gap-x-6 gap-y-2 text-[13px] text-sky-100/85">
        <li className="inline-flex items-center gap-1.5">
          <Clock className="w-4 h-4" aria-hidden="true" />A tu propio ritmo
        </li>
        <li className="inline-flex items-center gap-1.5">
          <BookOpen className="w-4 h-4" aria-hidden="true" />
          Videos, lecturas y evaluaciones
        </li>
        <li className="inline-flex items-center gap-1.5">
          <Award className="w-4 h-4" aria-hidden="true" />
          Certificado verificable
        </li>
      </ul>
    </div>
  );

  return (
    <PublicShell hero={hero}>
      <div className="flex flex-wrap items-end justify-between gap-3 mb-5">
        <div>
          <h2 className="text-xl font-bold tracking-tight text-slate-900">Cursos destacados</h2>
          <p className="text-[13px] text-slate-500">Elige uno para ver su temario y precio.</p>
        </div>
        {total > FEATURED && (
          <Link to="/catalog" className="inline-flex items-center gap-1.5 text-[13px] font-semibold text-brand-700 hover:text-brand-800">
            Ver los {total} cursos
            <ArrowRight className="w-4 h-4" aria-hidden="true" />
          </Link>
        )}
      </div>

      {courses === null ? (
        <LoadingState />
      ) : courses.length === 0 ? (
        <EmptyState icon={BookOpen} title="Pronto habrá cursos aquí" text="La academia todavía no publica cursos. Si ya tienes una cuenta, entra para ver los tuyos." />
      ) : (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-5">
          {courses.map((course) => (
            <Link key={course.id} to={`/catalog/${course.id}`} className={COURSE_CARD_CLASS}>
              <CourseCardBody course={course} action="Ver curso" />
            </Link>
          ))}
        </div>
      )}

      <div className="mt-10 rounded-3xl bg-gradient-to-br from-brand-50 to-sky-50 ring-1 ring-brand-100 p-6 sm:p-8 flex flex-wrap items-center justify-between gap-5">
        <div>
          <h2 className="text-lg font-bold text-slate-900">¿Primera vez aquí?</h2>
          <p className="text-[13px] text-slate-600">Crea tu cuenta gratis para comprar cursos y seguir tu avance.</p>
        </div>
        <Link to="/signup" className={`${BUTTON} bg-brand-800 text-white hover:bg-brand-900`}>
          Crear mi cuenta
        </Link>
      </div>
    </PublicShell>
  );
};
