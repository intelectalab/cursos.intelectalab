import React, { useCallback, useEffect, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import { ArrowLeft, CalendarCheck, CheckCheck, Save, UserCheck } from 'lucide-react';
import { apiErrorMessage } from '../../../shared/api/client';
import { Alert, Avatar, Badge, Button, Card, CardHeader, EmptyState, ErrorState, Field, INPUT_CLASS, LoadingState, PageHeader, Table, TBody, Td, Th, THead, Tr } from '../../../shared/ui';
import { ATTENDANCE, attendanceApi, formatDay, type AttendanceDay, type AttendanceStatus } from '../api';

type Summary = Awaited<ReturnType<typeof attendanceApi.summary>>;

const STATUSES = Object.keys(ATTENDANCE) as AttendanceStatus[];

/**
 * Asistencia a las clases en vivo de un curso: se elige el día, la clase del
 * horario y se marca a cada alumno. Abajo, el porcentaje de cada uno.
 */
export const AttendancePage: React.FC = () => {
  const { courseId = '' } = useParams();
  const [searchParams, setSearchParams] = useSearchParams();
  const date = searchParams.get('date') ?? '';

  const [day, setDay] = useState<AttendanceDay | null>(null);
  const [summary, setSummary] = useState<Summary | null>(null);
  const [start, setStart] = useState('');
  const [marks, setMarks] = useState<Record<string, AttendanceStatus>>({});
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [errorMessage, setErrorMessage] = useState('');
  const [saving, setSaving] = useState(false);
  const [result, setResult] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);

  // Al cambiar de clase se cargan las marcas guardadas de esa clase
  const pick = useCallback((data: AttendanceDay, session: string) => {
    setStart(session);
    setMarks(Object.fromEntries(data.students.filter((student) => student.statuses[session]).map((student) => [student.id, student.statuses[session]])));
  }, []);

  const load = useCallback(async () => {
    setState('loading');
    setResult(null);
    try {
      const [data, totals] = await Promise.all([attendanceApi.day(courseId, date || undefined), attendanceApi.summary(courseId)]);
      setDay(data);
      setSummary(totals);
      pick(data, data.sessions[0]?.start ?? '');
      setState('ready');
    } catch (err) {
      setErrorMessage(apiErrorMessage(err, 'No se pudo abrir la asistencia.'));
      setState('error');
    }
  }, [courseId, date, pick]);

  useEffect(() => {
    const timer = setTimeout(load, 0);
    return () => clearTimeout(timer);
  }, [load]);

  if (state === 'loading') return <LoadingState />;
  if (state === 'error' || !day || !summary) return <ErrorState message={errorMessage} onRetry={load} />;

  const future = day.date > day.today;
  const marked = day.students.filter((student) => marks[student.id]).length;

  const save = async () => {
    setSaving(true);
    setResult(null);
    try {
      const records = day.students.filter((student) => marks[student.id]).map((student) => ({ student_id: student.id, status: marks[student.id] }));
      await attendanceApi.save(courseId, { date: day.date, start, records });
      const [fresh, totals] = await Promise.all([attendanceApi.day(courseId, day.date), attendanceApi.summary(courseId)]);
      setDay(fresh);
      setSummary(totals);
      setResult({ tone: 'success', text: `Asistencia guardada: ${records.length} ${records.length === 1 ? 'alumno' : 'alumnos'}.` });
    } catch (err) {
      setResult({ tone: 'danger', text: apiErrorMessage(err, 'No se pudo guardar la asistencia.') });
    } finally {
      setSaving(false);
    }
  };

  return (
    <div>
      <Link to={`/courses/${courseId}`} className="inline-flex items-center gap-1.5 text-[13px] font-semibold text-slate-600 hover:text-brand-700 mb-3">
        <ArrowLeft className="w-4 h-4" /> {day.course.title}
      </Link>
      <PageHeader title="Asistencia a las clases en vivo" icon={UserCheck} subtitle={`${day.course.title}. Las clases salen del horario que confirmó cada docente.`} />

      <div className="space-y-4">
        <Card>
          <div className="flex flex-wrap items-end gap-4 px-5 py-4">
            <div className="w-48">
              <Field label="Día">
                <input
                  type="date"
                  className={INPUT_CLASS}
                  value={day.date}
                  max={day.today}
                  onChange={(event) => event.target.value && setSearchParams({ date: event.target.value })}
                />
              </Field>
            </div>
            {day.sessions.length > 0 && (
              <div role="group" aria-label="Clase" className="flex flex-wrap gap-2">
                {day.sessions.map((session) => (
                  <Button key={session.start} variant={start === session.start ? 'primary' : 'secondary'} onClick={() => pick(day, session.start)}>
                    {session.start}–{session.end} · {session.teacher}
                  </Button>
                ))}
              </div>
            )}
          </div>
        </Card>

        {day.sessions.length === 0 ? (
          <Card>
            <EmptyState icon={CalendarCheck} title={`No hay clase en vivo el ${formatDay(day.date)}`} text="Elige un día que tenga clase según el horario confirmado de los docentes del curso." />
          </Card>
        ) : day.students.length === 0 ? (
          <Card>
            <EmptyState icon={CalendarCheck} title="Este curso aún no tiene alumnos" />
          </Card>
        ) : (
          <Card>
            <CardHeader
              title={`Clase del ${formatDay(day.date)}, ${start}`}
              text={`${marked} de ${day.students.length} marcados. «Justificado» no cuenta para el porcentaje.`}
              action={
                !future && (
                  <Button size="sm" icon={<CheckCheck className="w-4 h-4" />} onClick={() => setMarks(Object.fromEntries(day.students.map((student) => [student.id, marks[student.id] ?? 'PRESENT'])))}>
                    Marcar el resto como presentes
                  </Button>
                )
              }
            />
            {future && (
              <div className="px-5 pt-4">
                <Alert tone="info">Todavía no llega ese día: la asistencia se toma el día de la clase o después.</Alert>
              </div>
            )}
            <ul className="divide-y divide-slate-100">
              {day.students.map((student) => (
                <li key={student.id} className="flex flex-col sm:flex-row sm:items-center gap-2.5 px-5 py-3">
                  <div className="flex items-center gap-3 flex-1 min-w-0">
                    <Avatar name={student.name} size="sm" />
                    <div className="min-w-0">
                      <p className="text-[13px] font-semibold text-slate-900 truncate">{student.name}</p>
                      <p className="text-xs text-slate-500 truncate">{student.email}</p>
                    </div>
                  </div>
                  <div role="radiogroup" aria-label={`Asistencia de ${student.name}`} className="flex flex-wrap gap-1.5">
                    {STATUSES.map((status) => (
                      <button
                        key={status}
                        type="button"
                        role="radio"
                        aria-checked={marks[student.id] === status}
                        disabled={future}
                        onClick={() => setMarks({ ...marks, [student.id]: status })}
                        className={`h-8 px-3 rounded-lg border text-xs font-semibold cursor-pointer transition-colors disabled:opacity-50 disabled:cursor-not-allowed ${
                          marks[student.id] === status ? 'bg-brand-700 border-brand-700 text-white' : 'bg-white border-slate-300 text-slate-700 hover:bg-slate-50'
                        }`}
                      >
                        {ATTENDANCE[status].label}
                      </button>
                    ))}
                  </div>
                </li>
              ))}
            </ul>
            <div className="flex flex-wrap items-center justify-end gap-3 px-5 py-4 border-t border-slate-200/80">
              {result && <div className="flex-1 min-w-[240px]"><Alert tone={result.tone}>{result.text}</Alert></div>}
              <Button variant="primary" icon={<Save className="w-4 h-4" />} processing={saving} disabled={future || marked === 0} onClick={save}>
                Guardar asistencia
              </Button>
            </div>
          </Card>
        )}

        <Card>
          <CardHeader title="Asistencia acumulada" text={`${summary.sessions_taken} ${summary.sessions_taken === 1 ? 'clase' : 'clases'} con asistencia tomada. Cuentan presentes y tardanzas; las faltas justificadas no se cuentan.`} />
          {summary.students.length === 0 ? (
            <EmptyState icon={UserCheck} title="Sin alumnos todavía" />
          ) : (
            <Table>
              <THead>
                <Th>Alumno</Th>
                <Th>Asistió</Th>
                <Th>Faltó</Th>
                <Th>Justificadas</Th>
                <Th>Asistencia</Th>
              </THead>
              <TBody>
                {summary.students.map((student) => (
                  <Tr key={student.id}>
                    <Td>
                      <p className="font-semibold text-slate-900">{student.name}</p>
                      <p className="text-xs text-slate-500">{student.email}</p>
                    </Td>
                    <Td className="tnum">{student.attended}</Td>
                    <Td className="tnum">{student.absent}</Td>
                    <Td className="tnum">{student.excused}</Td>
                    <Td>
                      {student.percent === null ? (
                        <span className="text-slate-500">—</span>
                      ) : (
                        <Badge tone="brand">
                          {student.percent} %
                        </Badge>
                      )}
                    </Td>
                  </Tr>
                ))}
              </TBody>
            </Table>
          )}
        </Card>
      </div>
    </div>
  );
};
