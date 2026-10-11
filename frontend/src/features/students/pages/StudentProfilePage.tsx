import React, { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, Award, BookOpen, Building2, LifeBuoy, Mail, Phone, Receipt, UsersRound, type LucideIcon } from 'lucide-react';
import { apiErrorMessage } from '../../../shared/api/client';
import { Avatar, Badge, Card, CardHeader, EmptyState, ErrorState, LoadingState, StatCard, Table, TBody, Td, Th, THead, Tr, type Tone } from '../../../shared/ui';
import { toScale } from '../../academic/api';
import { OrderStatusBadge } from '../../sales/components/OrderParts';
import { studentsApi, type StudentEnrollment, type StudentProfile, type StudentTicket } from '../api';

function formatDate(value: string | null): string {
  if (!value) return '—';
  return new Date(value).toLocaleDateString('es-PE', { day: 'numeric', month: 'short', year: 'numeric' });
}

const ENROLLMENT_STATE: Record<StudentEnrollment['state'], { label: string; tone: Tone }> = {
  ACTIVE: { label: 'En curso', tone: 'info' },
  COMPLETED: { label: 'Completado', tone: 'success' },
  EXPIRED: { label: 'Acceso vencido', tone: 'warning' },
  UPCOMING: { label: 'Por iniciar', tone: 'neutral' },
  CANCELLED: { label: 'Anulada', tone: 'neutral' },
};

const TICKET_STATE: Record<StudentTicket['status'], { label: string; tone: Tone }> = {
  OPEN: { label: 'Abierto', tone: 'warning' },
  IN_PROGRESS: { label: 'En atención', tone: 'info' },
  RESOLVED: { label: 'Resuelto', tone: 'success' },
  CLOSED: { label: 'Cerrado', tone: 'neutral' },
};

const Fact: React.FC<{ icon: LucideIcon; children: React.ReactNode }> = ({ icon: Icon, children }) => (
  <span className="inline-flex items-center gap-1.5 text-[13px] text-slate-600">
    <Icon className="w-4 h-4 text-slate-500 shrink-0" />
    {children}
  </span>
);

// Ficha completa del alumno ("Alumno 360"): solo lectura
export const StudentProfilePage: React.FC = () => {
  const { studentId = '' } = useParams();
  const [profile, setProfile] = useState<StudentProfile | null>(null);
  const [gradeScale, setGradeScale] = useState(20);
  const [error, setError] = useState<string | null>(null);
  const [attempt, setAttempt] = useState(0);

  useEffect(() => {
    let active = true;
    studentsApi
      .profile(studentId)
      .then((result) => {
        if (!active) return;
        setProfile(result.profile);
        setGradeScale(result.gradeScale);
        setError(null);
      })
      .catch((err) => active && setError(apiErrorMessage(err, 'No se pudo cargar la ficha del alumno.')));
    return () => {
      active = false;
    };
  }, [studentId, attempt]);

  if (error) {
    return (
      <Card>
        <ErrorState message={error} onRetry={() => setAttempt((n) => n + 1)} />
      </Card>
    );
  }
  if (!profile) return <LoadingState />;

  const { student, enrollments, orders, certificates, tickets } = profile;
  const paid = Object.entries(profile.paid_totals);
  const openTickets = tickets.filter((ticket) => ticket.status === 'OPEN' || ticket.status === 'IN_PROGRESS').length;
  const validCertificates = certificates.filter((certificate) => certificate.status === 'ISSUED').length;

  return (
    <div>
      <Link to="/students" className="inline-flex items-center gap-1.5 text-[13px] font-medium text-slate-500 hover:text-brand-700 mb-4">
        <ArrowLeft className="w-4 h-4" /> Alumnos
      </Link>

      {/* Quién es */}
      <Card className="p-5 mb-5">
        <div className="flex flex-wrap items-start gap-4">
          <Avatar name={student.name} size="lg" />
          <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-center gap-2">
              <h1 className="text-[22px] leading-7 font-bold text-slate-900 tracking-tight">{student.name}</h1>
              <Badge tone={student.status === 'ACTIVE' ? 'success' : 'neutral'} dot>
                {student.status === 'ACTIVE' ? 'Cuenta activa' : 'Cuenta inactiva'}
              </Badge>
              {student.must_change_password && <Badge tone="warning">Aún no cambia su contraseña temporal</Badge>}
            </div>
            <div className="flex flex-wrap gap-x-5 gap-y-1.5 mt-2">
              <Fact icon={Mail}>{student.email}</Fact>
              {student.phone && <Fact icon={Phone}>{student.phone}</Fact>}
              {student.company && <Fact icon={Building2}>{student.company}</Fact>}
              {student.groups.length > 0 && <Fact icon={UsersRound}>{student.groups.join(', ')}</Fact>}
            </div>
            <p className="text-xs text-slate-500 mt-2">
              {student.document ? `${student.document} · ` : ''}Registrado el {formatDate(student.created_at)} · Último acceso:{' '}
              {student.last_login_at ? formatDate(student.last_login_at) : 'nunca'}
            </p>
          </div>
        </div>
      </Card>

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
        <StatCard
          label="Cursos activos"
          value={student.active_enrollments}
          hint={`${student.completed_courses} ${student.completed_courses === 1 ? 'completado' : 'completados'}`}
          icon={BookOpen}
          tone="info"
        />
        <StatCard
          label="Pagado"
          value={paid.length === 0 ? '—' : paid.map(([currency, amount]) => `${currency} ${amount.toFixed(2)}`).join(' · ')}
          hint={`${orders.length} ${orders.length === 1 ? 'compra' : 'compras'}`}
          icon={Receipt}
          tone="success"
        />
        <StatCard label="Certificados" value={validCertificates} hint="vigentes" icon={Award} tone="warning" />
        <StatCard label="Consultas abiertas" value={openTickets} hint={`${tickets.length} en total`} icon={LifeBuoy} tone="violet" />
      </div>

      <div className="space-y-5">
        <Card>
          <CardHeader title="Cursos" text={`Avance y nota del curso (de 0 a ${gradeScale}). Lo que no tiene nota cuenta como cero.`} />
          {enrollments.length === 0 ? (
            <EmptyState icon={BookOpen} title="No tiene matrículas" text="Matricúlalo desde el curso o desde Matrículas." />
          ) : (
            <Table>
              <THead>
                <Th>Curso</Th>
                <Th>Situación</Th>
                <Th>Avance</Th>
                <Th>Nota</Th>
                <Th>Matriculado</Th>
                <Th>Vence</Th>
              </THead>
              <TBody>
                {enrollments.map((enrollment) => (
                  <Tr key={enrollment.id}>
                    <Td className="min-w-[180px]">
                      <Link to={`/courses/${enrollment.course_id}`} className="font-semibold text-slate-900 hover:text-brand-700">
                        {enrollment.course_title}
                      </Link>
                    </Td>
                    <Td className="whitespace-nowrap">
                      <Badge tone={ENROLLMENT_STATE[enrollment.state].tone} dot>
                        {ENROLLMENT_STATE[enrollment.state].label}
                      </Badge>
                    </Td>
                    <Td>
                      {enrollment.progress_percent === null ? (
                        <span className="text-slate-300">—</span>
                      ) : (
                        <div className="flex items-center gap-2 min-w-[110px]">
                          <span className="flex-1 h-1.5 rounded-full bg-slate-200 overflow-hidden">
                            <span
                              className={`block h-full rounded-full ${enrollment.progress_percent === 100 ? 'bg-emerald-500' : 'bg-brand-500'}`}
                              style={{ width: `${enrollment.progress_percent}%` }}
                            />
                          </span>
                          <span className="text-xs font-semibold text-slate-800 tnum w-9 text-right">{enrollment.progress_percent}%</span>
                        </div>
                      )}
                    </Td>
                    <Td className="whitespace-nowrap font-semibold text-slate-900 tnum">
                      {enrollment.final_percent === null ? <span className="font-normal text-slate-300">—</span> : toScale(enrollment.final_percent, gradeScale)}
                    </Td>
                    <Td className="whitespace-nowrap text-slate-500 tnum">{formatDate(enrollment.enrolled_at)}</Td>
                    <Td className="whitespace-nowrap text-slate-500 tnum">{enrollment.ends_at ? formatDate(`${enrollment.ends_at}T12:00:00`) : 'No vence'}</Td>
                  </Tr>
                ))}
              </TBody>
            </Table>
          )}
        </Card>

        <Card>
          <CardHeader title="Compras" text="Sus órdenes, de la más reciente a la más antigua." />
          {orders.length === 0 ? (
            <EmptyState icon={Receipt} title="No tiene compras" text="Las matrículas hechas por el personal o por una empresa no generan una orden a su nombre." />
          ) : (
            <Table>
              <THead>
                <Th>Orden</Th>
                <Th>Qué compró</Th>
                <Th>Total</Th>
                <Th>Estado</Th>
                <Th>Fecha</Th>
              </THead>
              <TBody>
                {orders.map((order) => (
                  <Tr key={order.id}>
                    <Td className="whitespace-nowrap font-semibold text-slate-900 tnum">#{String(order.number).padStart(4, '0')}</Td>
                    <Td className="min-w-[180px] text-slate-700">{order.title ?? '—'}</Td>
                    <Td className="whitespace-nowrap text-slate-800 tnum">
                      {order.currency} {Number(order.total_amount).toFixed(2)}
                    </Td>
                    <Td className="whitespace-nowrap">
                      <OrderStatusBadge status={order.state} />
                    </Td>
                    <Td className="whitespace-nowrap text-slate-500 tnum">{formatDate(order.created_at)}</Td>
                  </Tr>
                ))}
              </TBody>
            </Table>
          )}
        </Card>

        <div className="grid grid-cols-1 lg:grid-cols-2 gap-5 items-start">
          <Card>
            <CardHeader title="Certificados" />
            {certificates.length === 0 ? (
              <EmptyState icon={Award} title="Aún no tiene certificados" />
            ) : (
              <ul className="divide-y divide-slate-100">
                {certificates.map((certificate) => (
                  <li key={certificate.id} className="flex flex-wrap items-center gap-3 px-5 py-3">
                    <div className="min-w-0 flex-1">
                      <p className="text-[13px] font-semibold text-slate-900 truncate">{certificate.course_title ?? 'Curso'}</p>
                      <p className="text-xs text-slate-500 tnum">
                        {certificate.code} · {formatDate(certificate.issued_at)}
                      </p>
                    </div>
                    <Badge tone={certificate.status === 'ISSUED' ? 'success' : 'danger'} dot>
                      {certificate.status === 'ISSUED' ? 'Vigente' : 'Anulado'}
                    </Badge>
                  </li>
                ))}
              </ul>
            )}
          </Card>

          <Card>
            <CardHeader title="Consultas a soporte" />
            {tickets.length === 0 ? (
              <EmptyState icon={LifeBuoy} title="No ha hecho consultas" />
            ) : (
              <ul className="divide-y divide-slate-100">
                {tickets.map((ticket) => (
                  <li key={ticket.id} className="flex flex-wrap items-center gap-3 px-5 py-3">
                    <div className="min-w-0 flex-1">
                      <p className="text-[13px] font-semibold text-slate-900 truncate">{ticket.subject}</p>
                      <p className="text-xs text-slate-500 tnum">
                        #{String(ticket.number).padStart(4, '0')} · {formatDate(ticket.created_at)}
                      </p>
                    </div>
                    <Badge tone={TICKET_STATE[ticket.status].tone} dot>
                      {TICKET_STATE[ticket.status].label}
                    </Badge>
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </div>
      </div>
    </div>
  );
};
