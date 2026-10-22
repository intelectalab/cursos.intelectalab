import React, { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, BookOpen, ClipboardCheck, ClipboardList, Mail, Phone, Star, Users } from 'lucide-react';
import { apiErrorMessage } from '../../../shared/api/client';
import { Avatar, Badge, Card, CardHeader, EmptyState, ErrorState, LoadingState, StatCard, Table, TBody, Td, Th, THead, Tr } from '../../../shared/ui';
import { STATUS_LABEL, STATUS_TONE } from '../../courses/api';
import { teachersApi, type TeacherProfile } from '../api';

function formatDate(value: string | null): string {
  if (!value) return '—';
  return new Date(value).toLocaleDateString('es-PE', { day: 'numeric', month: 'short', year: 'numeric' });
}

// Días completos que lleva esperando la entrega más antigua
function waitingDays(since: string): number {
  return Math.max(0, Math.floor((Date.now() - new Date(since).getTime()) / 86_400_000));
}

// Ficha del docente: sus cursos y su carga de calificación. Solo lectura.
export const TeacherProfilePage: React.FC = () => {
  const { teacherId = '' } = useParams();
  const [profile, setProfile] = useState<TeacherProfile | null>(null);
  const [recentDays, setRecentDays] = useState(30);
  const [error, setError] = useState<string | null>(null);
  const [attempt, setAttempt] = useState(0);

  useEffect(() => {
    let active = true;
    teachersApi
      .profile(teacherId)
      .then((result) => {
        if (!active) return;
        setProfile(result.profile);
        setRecentDays(result.recentDays);
        setError(null);
      })
      .catch((err) => active && setError(apiErrorMessage(err, 'No se pudo cargar la ficha del docente.')));
    return () => {
      active = false;
    };
  }, [teacherId, attempt]);

  if (error) {
    return (
      <Card>
        <ErrorState message={error} onRetry={() => setAttempt((n) => n + 1)} />
      </Card>
    );
  }
  if (!profile) return <LoadingState />;

  const { teacher, courses } = profile;

  return (
    <div>
      <Link to="/teachers" className="inline-flex items-center gap-1.5 text-[13px] font-medium text-slate-500 hover:text-brand-700 mb-4">
        <ArrowLeft className="w-4 h-4" /> Docentes
      </Link>

      <Card className="p-5 mb-5">
        <div className="flex flex-wrap items-start gap-4">
          <Avatar name={teacher.name} size="lg" />
          <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-center gap-2">
              <h1 className="text-[22px] leading-7 font-bold text-slate-900 tracking-tight">{teacher.name}</h1>
              <Badge tone={teacher.status === 'ACTIVE' ? 'success' : 'neutral'} dot>
                {teacher.status === 'ACTIVE' ? 'Cuenta activa' : 'Cuenta inactiva'}
              </Badge>
            </div>
            <div className="flex flex-wrap gap-x-5 gap-y-1.5 mt-2 text-[13px] text-slate-600">
              <span className="inline-flex items-center gap-1.5">
                <Mail className="w-4 h-4 text-slate-500" />
                {teacher.email}
              </span>
              {teacher.phone && (
                <span className="inline-flex items-center gap-1.5">
                  <Phone className="w-4 h-4 text-slate-500" />
                  {teacher.phone}
                </span>
              )}
            </div>
            <p className="text-xs text-slate-500 mt-2">
              Registrado el {formatDate(teacher.created_at)} · Último acceso: {teacher.last_login_at ? formatDate(teacher.last_login_at) : 'nunca'}
            </p>
          </div>
        </div>
      </Card>

      <div className="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-5">
        <StatCard label="Cursos a cargo" value={teacher.courses_count} hint="sin contar archivados" icon={BookOpen} tone="info" />
        <StatCard label="Alumnos" value={profile.students_total} hint="con matrícula activa en sus cursos" icon={Users} tone="brand" />
        <StatCard
          label="Por calificar"
          value={teacher.pending_submissions}
          hint={teacher.pending_submissions === 0 ? 'está al día' : 'entregas esperan revisión'}
          icon={ClipboardList}
          tone="warning"
        />
        <StatCard label="Calificó" value={profile.graded_recently} hint={`entregas en los últimos ${recentDays} días`} icon={ClipboardCheck} tone="success" />
        <StatCard
          label="Valoración"
          value={profile.rating ? `${profile.rating.average.toFixed(1)} / 5` : '—'}
          hint={profile.rating ? `${profile.rating.count} ${profile.rating.count === 1 ? 'reseña' : 'reseñas'} de sus cursos` : 'sus cursos aún no tienen reseñas'}
          icon={Star}
          tone="warning"
        />
      </div>

      <Card>
        <CardHeader
          title="Cursos asignados"
          text="Lo que está por calificar es de todo el curso: si el curso tiene varios docentes, la carga es compartida."
        />
        {courses.length === 0 ? (
          <EmptyState icon={BookOpen} title="No tiene cursos asignados" text="Asígnalo desde la pestaña Participantes de un curso." />
        ) : (
          <Table>
            <THead>
              <Th>Curso</Th>
              <Th>Estado</Th>
              <Th>Alumnos</Th>
              <Th>Por calificar</Th>
              <Th>Espera desde</Th>
            </THead>
            <TBody>
              {courses.map((course) => (
                <Tr key={course.id}>
                  <Td className="min-w-[200px]">
                    <Link to={`/courses/${course.id}`} className="font-semibold text-slate-900 hover:text-brand-700">
                      {course.title}
                    </Link>
                  </Td>
                  <Td className="whitespace-nowrap">
                    <Badge tone={STATUS_TONE[course.status]} dot>
                      {STATUS_LABEL[course.status]}
                    </Badge>
                  </Td>
                  <Td className="whitespace-nowrap text-slate-800 tnum">{course.students}</Td>
                  <Td className="whitespace-nowrap">
                    {course.pending_submissions === 0 ? (
                      <span className="text-slate-500">Al día</span>
                    ) : (
                      <Badge tone="warning" dot>
                        {course.pending_submissions} {course.pending_submissions === 1 ? 'entrega' : 'entregas'}
                      </Badge>
                    )}
                  </Td>
                  <Td className="whitespace-nowrap text-slate-500 tnum">
                    {course.oldest_pending_at ? (
                      <>
                        {formatDate(course.oldest_pending_at)}
                        <span className="ml-2 text-xs">
                          ({waitingDays(course.oldest_pending_at) === 0 ? 'hoy' : `hace ${waitingDays(course.oldest_pending_at)} d`})
                        </span>
                      </>
                    ) : (
                      '—'
                    )}
                  </Td>
                </Tr>
              ))}
            </TBody>
          </Table>
        )}
      </Card>
    </div>
  );
};
