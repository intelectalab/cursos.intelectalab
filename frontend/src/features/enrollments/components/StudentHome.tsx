import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { ArrowRight, CheckCircle2, Compass, GraduationCap, Trophy } from 'lucide-react';
import { useAuth } from '../../auth/AuthContext';
import { Badge, Card, CardHeader, EmptyState, LoadingState } from '../../../shared/ui';
import { ACTIVITY_LABEL, toScale } from '../../academic/api';
import { ACTIVITY_ICON } from '../../academic/icons';
import { enrollmentsApi, type StudentOverview } from '../api';
import { MyCourseCard } from '../pages/MyCoursesPage';

function formatDate(value: string): string {
  return new Date(value).toLocaleDateString('es-PE', { day: 'numeric', month: 'short' });
}

// Inicio del alumno: en qué va, qué tiene pendiente y sus últimas notas
export const StudentHome: React.FC = () => {
  const { hasPermission } = useAuth();
  const [overview, setOverview] = useState<(StudentOverview & { gradeScale: number }) | null>(null);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    let active = true;
    enrollmentsApi
      .overview()
      .then((result) => active && setOverview(result))
      .catch(() => active && setFailed(true));
    return () => {
      active = false;
    };
  }, []);

  if (failed) return null;
  if (!overview) return <LoadingState />;

  const { courses, pending, pending_total: pendingTotal, recent_grades: grades, gradeScale } = overview;

  if (courses.length === 0) {
    return (
      <Card>
        <EmptyState
          icon={GraduationCap}
          title="Aún no tienes cursos en marcha"
          text="Cuando tu academia te matricule en un curso, lo verás aquí."
          action={
            hasPermission('courses.catalog.read') && (
              <Link to="/available-courses" className="inline-flex items-center gap-1.5 text-[13px] font-semibold text-brand-700 hover:underline">
                <Compass className="w-4 h-4" /> Ver los cursos disponibles
              </Link>
            )
          }
        />
      </Card>
    );
  }

  return (
    <div className="space-y-6">
      <section>
        <div className="flex items-baseline justify-between gap-3 mb-3">
          <h2 className="text-sm font-semibold text-slate-900">Tus cursos</h2>
          <Link to="/my-courses" className="inline-flex items-center gap-1 text-[13px] font-semibold text-brand-700 hover:underline">
            Ver todos <ArrowRight className="w-3.5 h-3.5" />
          </Link>
        </div>
        <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4 sm:gap-5">
          {courses.slice(0, 3).map((course) => (
            <MyCourseCard key={course.course_id} course={course} />
          ))}
        </div>
      </section>

      <div className="grid grid-cols-1 xl:grid-cols-2 gap-4 items-start">
        <Card>
          <CardHeader
            title="Lo que tienes pendiente"
            text={
              pendingTotal === 0
                ? 'Estás al día con todas tus actividades.'
                : `${pendingTotal} ${pendingTotal === 1 ? 'actividad' : 'actividades'} por completar.`
            }
          />
          {pending.length === 0 ? (
            <EmptyState icon={CheckCircle2} title="Nada pendiente" text="Cuando haya una actividad por rendir o entregar, aparecerá aquí." />
          ) : (
            <ul className="divide-y divide-slate-100">
              {pending.map((item) => {
                const { icon: Icon, tone } = ACTIVITY_ICON[item.type];
                return (
                  <li key={item.activity_id}>
                    <Link
                      to={`/my-courses/${item.course_id}?item=a-${item.activity_id}`}
                      className="group flex items-center gap-3 px-5 py-3 hover:bg-brand-50/40 transition-colors"
                    >
                      <span className={`w-9 h-9 shrink-0 rounded-xl inline-flex items-center justify-center ${tone}`}>
                        <Icon className="w-4 h-4" />
                      </span>
                      <span className="min-w-0 flex-1">
                        <span className="block text-[13px] font-semibold text-slate-900 truncate">{item.title}</span>
                        <span className="block text-xs text-slate-500 truncate">
                          {ACTIVITY_LABEL[item.type]} · {item.course_title}
                        </span>
                      </span>
                      <ArrowRight className="w-4 h-4 shrink-0 text-slate-300 group-hover:text-brand-600 group-hover:translate-x-0.5 transition-all" />
                    </Link>
                  </li>
                );
              })}
            </ul>
          )}
        </Card>

        <Card>
          <CardHeader title="Tus últimas notas" text={`De 0 a ${gradeScale}.`} />
          {grades.length === 0 ? (
            <EmptyState icon={Trophy} title="Aún no tienes notas" text="Aparecerán cuando rindas un cuestionario o te califiquen una tarea." />
          ) : (
            <ul className="divide-y divide-slate-100">
              {grades.map((grade, index) => (
                <li key={index} className="flex items-center gap-3 px-5 py-3">
                  <span className="min-w-0 flex-1">
                    <span className="block text-[13px] font-semibold text-slate-900 truncate">{grade.title}</span>
                    <span className="block text-xs text-slate-500 truncate">
                      {ACTIVITY_LABEL[grade.type]} · {grade.course_title} · {formatDate(grade.at)}
                    </span>
                  </span>
                  {grade.passed !== null && (
                    <Badge tone={grade.passed ? 'success' : 'neutral'}>{grade.passed ? 'Aprobado' : 'No aprobado'}</Badge>
                  )}
                  <span className="text-lg font-bold text-slate-900 tnum w-12 text-right">{toScale(grade.grade_percent, gradeScale)}</span>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>
    </div>
  );
};
