import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { ArrowRight, BookOpen, CalendarClock, Clock, Compass, FileStack, GraduationCap, ListChecks } from 'lucide-react';
import { useAuth } from '../../auth/AuthContext';
import { apiErrorMessage } from '../../../shared/api/client';
import { Badge, Card, EmptyState, ErrorState, LoadingState, PageHeader } from '../../../shared/ui';
import { LEVEL_LABEL } from '../../courses/api';
import { enrollmentsApi, formatDay, type MyCourse } from '../api';

// Tarjeta de un curso del alumno; también la usa su pantalla de inicio
export const MyCourseCard: React.FC<{ course: MyCourse }> = ({ course }) => {
  const percent = course.progress_percent ?? 0;
  const locked = course.state === 'EXPIRED' || course.state === 'UPCOMING';

  const body = (
    <>
      <div className="relative aspect-[16/9] bg-gradient-to-br from-brand-800 via-brand-700 to-sky-500 overflow-hidden">
        {course.cover_url ? (
          <img src={course.cover_url} alt="" className={`w-full h-full object-cover ${locked ? 'grayscale opacity-70' : ''}`} loading="lazy" />
        ) : (
          <div className="absolute inset-0 flex items-center justify-center">
            <BookOpen className="w-10 h-10 text-white/40" aria-hidden="true" />
          </div>
        )}
        {course.state !== 'ACTIVE' && (
          <div className="absolute top-3 left-3">
            <Badge tone={course.state === 'COMPLETED' ? 'success' : course.state === 'EXPIRED' ? 'warning' : 'info'} dot>
              {course.state === 'COMPLETED' ? 'Completado' : course.state === 'EXPIRED' ? 'Acceso vencido' : 'Aún no empieza'}
            </Badge>
          </div>
        )}
      </div>
      <div className="flex flex-col flex-1 p-4">
        {course.category && <p className="text-xs font-semibold text-brand-600 mb-1">{course.category}</p>}
        <h2 className="text-[15px] leading-5 font-semibold text-slate-900 group-hover:text-brand-700 transition-colors line-clamp-2">{course.title}</h2>
        {course.summary && <p className="text-[13px] text-slate-500 mt-1.5 line-clamp-2">{course.summary}</p>}
        <div className="flex flex-wrap gap-x-4 gap-y-1.5 mt-3 text-xs text-slate-500">
          <span className="inline-flex items-center gap-1.5">
            <FileStack className="w-3.5 h-3.5" /> {course.resources_count} {course.resources_count === 1 ? 'recurso' : 'recursos'}
          </span>
          {course.activities_count > 0 && (
            <span className="inline-flex items-center gap-1.5">
              <ListChecks className="w-3.5 h-3.5" /> {course.activities_count} {course.activities_count === 1 ? 'actividad' : 'actividades'}
            </span>
          )}
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

        <div className="mt-auto pt-4">
          <div className="flex items-baseline justify-between text-xs mb-1.5">
            <span className="text-slate-500">Avance</span>
            <span className="font-semibold text-slate-800 tnum">{percent}%</span>
          </div>
          <div className="h-1.5 rounded-full bg-slate-200 overflow-hidden">
            <div
              className={`h-full rounded-full ${percent === 100 ? 'bg-emerald-500' : 'bg-gradient-to-r from-brand-600 to-sky-500'}`}
              style={{ width: `${percent}%` }}
            />
          </div>
        </div>

        {locked ? (
          <p className="inline-flex items-center gap-1.5 pt-3 text-xs font-medium text-amber-700">
            <CalendarClock className="w-4 h-4 shrink-0" />
            {course.state === 'EXPIRED'
              ? `Tu acceso venció el ${formatDay(course.ends_at)}. Pide a tu academia que lo extienda.`
              : `Podrás entrar desde el ${formatDay(course.starts_at)}.`}
          </p>
        ) : (
          <div className="flex items-center justify-between gap-3 pt-3">
            <span className="inline-flex items-center gap-1.5 text-[13px] font-semibold text-brand-700">
              {percent === 100 ? 'Repasar el curso' : percent ? 'Continuar' : 'Entrar al curso'}
              <ArrowRight className="w-4 h-4 group-hover:translate-x-0.5 transition-transform" />
            </span>
            {course.ends_at && <span className="text-xs text-slate-500">Hasta el {formatDay(course.ends_at)}</span>}
          </div>
        )}
      </div>
    </>
  );

  const frame = 'flex flex-col bg-white border border-slate-200/80 rounded-2xl shadow-card overflow-hidden';

  // Sin acceso no hay a dónde entrar: la tarjeta informa, no enlaza
  return locked ? (
    <div className={frame}>{body}</div>
  ) : (
    <Link to={`/my-courses/${course.course_id}`} className={`group ${frame} hover:shadow-raised hover:-translate-y-0.5 transition-all`}>
      {body}
    </Link>
  );
};

export const MyCoursesPage: React.FC = () => {
  const { hasPermission } = useAuth();
  const [courses, setCourses] = useState<MyCourse[]>([]);
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [error, setError] = useState('');
  const [attempt, setAttempt] = useState(0);

  useEffect(() => {
    let active = true;
    enrollmentsApi
      .myCourses()
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
    <div>
      <PageHeader title="Mis cursos" icon={GraduationCap} subtitle="Los cursos en los que estás matriculado." />

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
          <EmptyState
            icon={GraduationCap}
            title="Aún no tienes cursos"
            text="Cuando tu academia te matricule en un curso, aparecerá aquí."
            action={
              hasPermission('courses.catalog.read') && (
                <Link to="/available-courses" className="inline-flex items-center gap-1.5 text-[13px] font-semibold text-brand-700 hover:underline">
                  <Compass className="w-4 h-4" /> Ver los cursos disponibles
                </Link>
              )
            }
          />
        </Card>
      )}
      {state === 'ready' && courses.length > 0 && (
        <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4 gap-4 sm:gap-5">
          {courses.map((course) => (
            <MyCourseCard key={course.course_id} course={course} />
          ))}
        </div>
      )}
    </div>
  );
};
