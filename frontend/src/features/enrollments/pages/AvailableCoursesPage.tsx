import React, { useEffect, useState } from 'react';
import { BookOpen, Clock, Compass, FileStack, FileText, GraduationCap, Link2, ListChecks, Paperclip, Video, type LucideIcon } from 'lucide-react';
import { apiErrorMessage } from '../../../shared/api/client';
import { Alert, Button, Card, EmptyState, ErrorState, LoadingState, Modal, PageHeader } from '../../../shared/ui';
import { ACTIVITY_ICON } from '../../academic/icons';
import type { ActivityType } from '../../academic/api';
import { LANGUAGE_LABEL, LEVEL_LABEL, type ResourceType } from '../../courses/api';
import { enrollmentsApi, type AvailableCourse, type AvailableCourseDetail } from '../api';

const RESOURCE_ICON: Record<ResourceType, LucideIcon> = { VIDEO: Video, DOCUMENT: Paperclip, LINK: Link2, TEXT: FileText };

const Cover: React.FC<{ url: string | null; className?: string }> = ({ url, className = '' }) => (
  <div className={`relative aspect-[16/9] bg-gradient-to-br from-brand-800 via-brand-700 to-sky-500 overflow-hidden ${className}`}>
    {url ? (
      <img src={url} alt="" className="w-full h-full object-cover" loading="lazy" />
    ) : (
      <div className="absolute inset-0 flex items-center justify-center">
        <BookOpen className="w-10 h-10 text-white/40" aria-hidden="true" />
      </div>
    )}
  </div>
);

const Facts: React.FC<{ course: AvailableCourse }> = ({ course }) => (
  <div className="flex flex-wrap gap-x-4 gap-y-1.5 text-xs text-slate-500">
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
);

// Ficha del curso: presentación y temario, sin el contenido
const CourseSheet: React.FC<{ course: AvailableCourse; onClose: () => void }> = ({ course, onClose }) => {
  const [detail, setDetail] = useState<AvailableCourseDetail | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let active = true;
    enrollmentsApi
      .availableCourse(course.id)
      .then((result) => active && setDetail(result))
      .catch((err) => active && setError(apiErrorMessage(err, 'No se pudo cargar el curso.')));
    return () => {
      active = false;
    };
  }, [course.id]);

  return (
    <Modal title={course.title} subtitle={course.category ?? undefined} onClose={onClose} footer={<Button onClick={onClose}>Cerrar</Button>}>
      <div className="space-y-5">
        <Cover url={course.cover_url} className="rounded-xl" />
        <Facts course={course} />
        {error && <Alert>{error}</Alert>}
        {!detail && !error && <LoadingState />}
        {detail && (
          <>
            {(detail.description ?? detail.summary) && (
              <p className="text-[13px] leading-relaxed text-slate-700 whitespace-pre-wrap break-words">{detail.description ?? detail.summary}</p>
            )}

            <dl className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2 text-[13px]">
              {detail.teachers.length > 0 && (
                <div>
                  <dt className="text-xs font-semibold text-slate-500">{detail.teachers.length === 1 ? 'Profesor' : 'Profesores'}</dt>
                  <dd className="text-slate-800">{detail.teachers.join(', ')}</dd>
                </div>
              )}
              {detail.language && (
                <div>
                  <dt className="text-xs font-semibold text-slate-500">Idioma</dt>
                  <dd className="text-slate-800">{LANGUAGE_LABEL[detail.language] ?? detail.language}</dd>
                </div>
              )}
              <div>
                <dt className="text-xs font-semibold text-slate-500">Acceso</dt>
                <dd className="text-slate-800">{detail.access_days ? `${detail.access_days} días desde la matrícula` : 'Sin vencimiento'}</dd>
              </div>
            </dl>

            <section>
              <h3 className="text-[13px] font-semibold text-slate-900 mb-2">Temario</h3>
              <ol className="rounded-xl border border-slate-200 divide-y divide-slate-100">
                {detail.sections.map((section, index) => (
                  <li key={index} className="px-3.5 py-3">
                    <p className="text-[13px] font-semibold text-slate-900">
                      {index + 1}. {section.title}
                    </p>
                    <ul className="mt-1.5 space-y-1">
                      {section.items.map((item, itemIndex) => {
                        const Icon = item.kind === 'RESOURCE' ? RESOURCE_ICON[item.type as ResourceType] : ACTIVITY_ICON[item.type as ActivityType].icon;
                        return (
                          <li key={itemIndex} className="flex items-center gap-2 text-[13px] text-slate-600">
                            <Icon className="w-3.5 h-3.5 shrink-0 text-slate-400" />
                            <span className="truncate">{item.title}</span>
                          </li>
                        );
                      })}
                    </ul>
                  </li>
                ))}
              </ol>
            </section>

            <Alert tone="info">Para cursarlo, pide a tu academia que te matricule.</Alert>
          </>
        )}
      </div>
    </Modal>
  );
};

export const AvailableCoursesPage: React.FC = () => {
  const [courses, setCourses] = useState<AvailableCourse[]>([]);
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [error, setError] = useState('');
  const [attempt, setAttempt] = useState(0);
  const [open, setOpen] = useState<AvailableCourse | null>(null);

  useEffect(() => {
    let active = true;
    enrollmentsApi
      .availableCourses()
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
      <PageHeader title="Cursos disponibles" icon={Compass} subtitle="Otros cursos de tu academia en los que aún no estás matriculado." />

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
          <EmptyState icon={Compass} title="No hay otros cursos por ahora" text="Ya estás en todos los cursos publicados de tu academia, o aún no se publica ninguno." />
        </Card>
      )}
      {state === 'ready' && courses.length > 0 && (
        <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4 gap-4 sm:gap-5">
          {courses.map((course) => (
            <button
              key={course.id}
              type="button"
              onClick={() => setOpen(course)}
              className="group flex flex-col text-left bg-white border border-slate-200/80 rounded-2xl shadow-card overflow-hidden hover:shadow-raised hover:-translate-y-0.5 transition-all cursor-pointer"
            >
              <Cover url={course.cover_url} className="w-full" />
              <div className="flex flex-col flex-1 w-full p-4">
                {course.category && <p className="text-xs font-semibold text-brand-600 mb-1">{course.category}</p>}
                <h2 className="text-[15px] leading-5 font-semibold text-slate-900 group-hover:text-brand-700 transition-colors line-clamp-2">
                  {course.title}
                </h2>
                {course.summary && <p className="text-[13px] text-slate-500 mt-1.5 line-clamp-2">{course.summary}</p>}
                <div className="mt-3">
                  <Facts course={course} />
                </div>
                <span className="mt-auto pt-4 text-[13px] font-semibold text-brand-700">Ver de qué trata</span>
              </div>
            </button>
          ))}
        </div>
      )}

      {open && <CourseSheet course={open} onClose={() => setOpen(null)} />}
    </div>
  );
};
