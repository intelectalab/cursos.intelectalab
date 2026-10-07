import React from 'react';
import { Award, BookOpen, Clock, FileStack, FileText, GraduationCap, Link2, ListChecks, Paperclip, Video, type LucideIcon } from 'lucide-react';
import { ACTIVITY_ICON } from '../../academic/icons';
import type { ActivityType } from '../../academic/api';
import { LANGUAGE_LABEL, LEVEL_LABEL, type ResourceType } from '../../courses/api';
import type { AvailableCourse, AvailableCourseDetail } from '../../enrollments/api';
import { priceLabel } from '../api';

// Piezas de la ficha de un curso, compartidas por el catálogo público, "Cursos disponibles" y el checkout

const RESOURCE_ICON: Record<ResourceType, LucideIcon> = { VIDEO: Video, DOCUMENT: Paperclip, LINK: Link2, TEXT: FileText };

export const CourseCover: React.FC<{ url: string | null; className?: string }> = ({ url, className = '' }) => (
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

export const CourseFacts: React.FC<{ course: AvailableCourse }> = ({ course }) => (
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
    {course.issues_certificate && (
      <span className="inline-flex items-center gap-1.5">
        <Award className="w-3.5 h-3.5" /> Con certificado
      </span>
    )}
  </div>
);

// Precio destacado: importe, "Gratis", o nada si el curso no está en venta
export const CoursePrice: React.FC<{ course: AvailableCourse; className?: string }> = ({ course, className = '' }) => {
  const label = priceLabel(course.price, course.currency);
  if (!label) return null;

  return (
    <span className={`font-bold tnum ${Number(course.price) === 0 ? 'text-emerald-700' : 'text-slate-900'} ${className}`}>{label}</span>
  );
};

// Tarjeta del catálogo. El contenedor (enlace o botón) lo pone quien la usa.
export const CourseCardBody: React.FC<{ course: AvailableCourse; action: string }> = ({ course, action }) => (
  <>
    <CourseCover url={course.cover_url} className="w-full" />
    <div className="flex flex-col flex-1 w-full p-4">
      {course.category && <p className="text-xs font-semibold text-brand-600 mb-1">{course.category}</p>}
      <h2 className="text-[15px] leading-5 font-semibold text-slate-900 group-hover:text-brand-700 transition-colors line-clamp-2">{course.title}</h2>
      {course.summary && <p className="text-[13px] text-slate-500 mt-1.5 line-clamp-2">{course.summary}</p>}
      <div className="mt-3">
        <CourseFacts course={course} />
      </div>
      <div className="mt-auto pt-4 flex items-end justify-between gap-3">
        <span className="text-[13px] font-semibold text-brand-700">{action}</span>
        <CoursePrice course={course} className="text-base" />
      </div>
    </div>
  </>
);

// Presentación y temario, sin el contenido
export const CourseOverview: React.FC<{ detail: AvailableCourseDetail }> = ({ detail }) => (
  <div className="space-y-5">
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
                    <Icon className="w-3.5 h-3.5 shrink-0 text-slate-500" />
                    <span className="truncate">{item.title}</span>
                  </li>
                );
              })}
            </ul>
          </li>
        ))}
      </ol>
    </section>
  </div>
);
