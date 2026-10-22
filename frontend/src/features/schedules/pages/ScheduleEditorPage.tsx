import React, { useCallback, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, CalendarDays, Copy, Plus, Send, Trash2 } from 'lucide-react';
import { useAuth } from '../../auth/AuthContext';
import { apiErrorMessage } from '../../../shared/api/client';
import { Alert, Badge, Button, Card, CardHeader, EmptyState, ErrorState, Field, INPUT_CLASS, LoadingState, PageHeader } from '../../../shared/ui';
import { WeekGrid } from '../components/WeekGrid';
import { NoClassDaysCard } from '../components/NoClassDaysCard';
import { BLOCK_TYPES, DAYS, formatDay, schedulesApi, STATUS_META, type BlockType, type Schedule, type ScheduleBlock } from '../api';

type Data = Awaited<ReturnType<typeof schedulesApi.show>>;

// Cada fila del editor lleva una clave propia para que React no confunda bloques al borrar
type Row = ScheduleBlock & { key: number };

let nextKey = 1;
const withKey = (block: ScheduleBlock): Row => ({ ...block, key: nextKey++ });

const todayIso = () => {
  const now = new Date();
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
};

const formatDateTime = (value: string) => new Date(value).toLocaleString('es-PE', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });

const StatusAlert: React.FC<{ schedule: Schedule }> = ({ schedule }) => {
  if (schedule.status === 'OBSERVED') {
    return (
      <Alert tone="warning">
        <strong>El docente pidió cambios</strong>
        {schedule.responded_at && ` (${formatDateTime(schedule.responded_at)})`}: «{schedule.observation}». Ajusta el horario y vuelve a enviarlo.
      </Alert>
    );
  }
  if (schedule.status === 'PENDING') {
    return (
      <Alert tone="info">
        Enviado{schedule.sent_by && ` por ${schedule.sent_by}`} ({formatDateTime(schedule.sent_at)}). Espera la confirmación del docente.
      </Alert>
    );
  }
  return (
    <Alert tone="success">
      El docente lo confirmó{schedule.responded_at && ` (${formatDateTime(schedule.responded_at)})`}. Sus clases en vivo ya las ven los alumnos de cada curso.
    </Alert>
  );
};

export const ScheduleEditorPage: React.FC = () => {
  const { teacherId = '' } = useParams();
  const { hasPermission } = useAuth();
  const canManage = hasPermission('schedules.manage');

  const [data, setData] = useState<Data | null>(null);
  const [rows, setRows] = useState<Row[]>([]);
  const [validFrom, setValidFrom] = useState(todayIso);
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [errorMessage, setErrorMessage] = useState('');
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const apply = useCallback((result: Data) => {
    setData(result);
    setRows((result.schedule?.blocks ?? []).map(withKey));
    if (result.schedule) setValidFrom(result.schedule.valid_from);
  }, []);

  const load = useCallback(async () => {
    setState('loading');
    try {
      apply(await schedulesApi.show(teacherId));
      setState('ready');
    } catch (err) {
      setErrorMessage(apiErrorMessage(err, 'No se pudo abrir el horario.'));
      setState('error');
    }
  }, [teacherId, apply]);

  useEffect(() => {
    const timer = setTimeout(load, 0);
    return () => clearTimeout(timer);
  }, [load]);

  const change = (key: number, patch: Partial<ScheduleBlock>) => {
    setNotice(null);
    setRows((current) => current.map((row) => (row.key === key ? { ...row, ...patch } : row)));
  };

  // El bloque nuevo empieza donde termina el último, para no chocar; si ya es tarde, al día siguiente
  const add = () => {
    const last = rows[rows.length - 1];
    const lastEnd = last ? Number(last.end.slice(0, 2)) : 0;
    const sameDay = last !== undefined && lastEnd <= 20;
    const startHour = sameDay ? lastEnd : 9;
    const block: ScheduleBlock = {
      day: last ? (sameDay ? last.day : (last.day % 7) + 1) : 1,
      start: sameDay ? last.end : '09:00',
      end: `${String(startHour + 2).padStart(2, '0')}${sameDay ? last.end.slice(2) : ':00'}`,
      type: 'AVAILABLE',
      course_id: null,
      meeting_url: null,
      note: null,
    };
    setRows((current) => [...current, withKey(block)]);
  };

  // Copia el bloque al día siguiente: lo más común es repetir lo mismo de lunes a viernes
  const duplicate = (row: Row) => {
    setRows((current) => [...current, withKey({ ...row, day: row.day === 7 ? 1 : row.day + 1 })]);
  };

  const save = async () => {
    setSaving(true);
    setSaveError(null);
    try {
      const blocks = rows.map((row) => ({
        day: row.day,
        start: row.start,
        end: row.end,
        type: row.type,
        course_id: row.type === 'LIVE_CLASS' ? row.course_id || null : null,
        meeting_url: row.type === 'LIVE_CLASS' ? row.meeting_url || null : null,
        note: row.note || null,
      }));
      const schedule = await schedulesApi.send(teacherId, { valid_from: validFrom, blocks });
      if (data) apply({ ...data, schedule });
      setNotice(schedule.status === 'CONFIRMED' ? 'No hubo cambios: el horario sigue confirmado.' : 'Horario enviado. El docente recibió un aviso para confirmarlo.');
    } catch (err) {
      setSaveError(apiErrorMessage(err, 'No se pudo guardar el horario.'));
    } finally {
      setSaving(false);
    }
  };

  if (state === 'loading') return <LoadingState />;
  if (state === 'error' || !data) return <ErrorState message={errorMessage} onRetry={load} />;

  const schedule = data.schedule;
  const preview = rows.map((row) => ({ ...row, course_title: data.courses.find((course) => course.id === row.course_id)?.title ?? null }));

  return (
    <div>
      <Link to="/schedules" className="inline-flex items-center gap-1.5 text-[13px] font-semibold text-slate-600 hover:text-brand-700 mb-3">
        <ArrowLeft className="w-4 h-4" /> Horarios
      </Link>
      <PageHeader
        title={`Horario de ${data.teacher.name}`}
        icon={CalendarDays}
        subtitle={`Semana tipo que se repite cada semana desde la fecha que elijas. Horas de la academia (${data.timezone}).`}
        action={schedule && <Badge tone={STATUS_META[schedule.status].tone} dot>{STATUS_META[schedule.status].label}</Badge>}
      />

      <div className="space-y-4">
        {schedule && <StatusAlert schedule={schedule} />}
        {notice && <Alert tone="success">{notice}</Alert>}

        {canManage && (
          <Card>
            <CardHeader
              title="Bloques de la semana"
              text="Agrega los bloques del docente. «Copiar al día siguiente» ayuda a repetir el mismo bloque de lunes a viernes."
              action={
                <Button icon={<Plus className="w-4 h-4" />} onClick={add}>
                  Agregar bloque
                </Button>
              }
            />
            <div className="px-5 py-4 space-y-3">
              <div className="max-w-xs">
                <Field label="Vale desde" hint="Desde este día rige el horario.">
                  <input type="date" className={INPUT_CLASS} value={validFrom} onChange={(event) => setValidFrom(event.target.value)} />
                </Field>
              </div>

              {rows.length === 0 && <p className="text-[13px] text-slate-500">Sin bloques todavía. Usa «Agregar bloque».</p>}

              {rows.map((row, index) => (
                <fieldset key={row.key} className="rounded-xl border border-slate-200 p-3">
                  <legend className="sr-only">Bloque {index + 1}</legend>
                  <div className="grid grid-cols-2 md:grid-cols-[1.1fr_0.8fr_0.8fr_1.3fr_auto] gap-2.5 items-end">
                    <Field label="Día">
                      <select className={INPUT_CLASS} value={row.day} onChange={(event) => change(row.key, { day: Number(event.target.value) })}>
                        {DAYS.map((name, dayIndex) => (
                          <option key={name} value={dayIndex + 1}>
                            {name}
                          </option>
                        ))}
                      </select>
                    </Field>
                    <Field label="Desde">
                      <input type="time" step={300} className={INPUT_CLASS} value={row.start} onChange={(event) => change(row.key, { start: event.target.value })} />
                    </Field>
                    <Field label="Hasta">
                      <input type="time" step={300} className={INPUT_CLASS} value={row.end} onChange={(event) => change(row.key, { end: event.target.value })} />
                    </Field>
                    <Field label="Tipo">
                      <select className={INPUT_CLASS} value={row.type} onChange={(event) => change(row.key, { type: event.target.value as BlockType })}>
                        {(Object.keys(BLOCK_TYPES) as BlockType[]).map((type) => (
                          <option key={type} value={type}>
                            {BLOCK_TYPES[type].label}
                          </option>
                        ))}
                      </select>
                    </Field>
                    <div className="col-span-2 md:col-span-1 flex gap-1.5 justify-end">
                      <Button variant="ghost" size="sm" icon={<Copy className="w-4 h-4" />} onClick={() => duplicate(row)} aria-label={`Copiar el bloque ${index + 1} al día siguiente`}>
                        <span className="md:hidden">Copiar al día siguiente</span>
                      </Button>
                      <Button variant="danger" size="sm" icon={<Trash2 className="w-4 h-4" />} onClick={() => setRows((current) => current.filter((item) => item.key !== row.key))} aria-label={`Quitar el bloque ${index + 1}`} />
                    </div>
                  </div>
                  <div className="grid grid-cols-1 md:grid-cols-3 gap-2.5 mt-2.5">
                    {row.type === 'LIVE_CLASS' && (
                      <>
                        <Field label="Curso">
                          <select className={INPUT_CLASS} value={row.course_id ?? ''} onChange={(event) => change(row.key, { course_id: event.target.value || null })}>
                            <option value="">Sin curso (no lo ven los alumnos)</option>
                            {data.courses.map((course) => (
                              <option key={course.id} value={course.id}>
                                {course.title}
                              </option>
                            ))}
                          </select>
                        </Field>
                        <Field label="Enlace de la videollamada">
                          <input className={INPUT_CLASS} placeholder="https://meet.google.com/…" value={row.meeting_url ?? ''} onChange={(event) => change(row.key, { meeting_url: event.target.value })} />
                        </Field>
                      </>
                    )}
                    <Field label="Nota (opcional)">
                      <input className={INPUT_CLASS} maxLength={200} placeholder="Ej.: aula virtual 2" value={row.note ?? ''} onChange={(event) => change(row.key, { note: event.target.value })} />
                    </Field>
                  </div>
                </fieldset>
              ))}

              {saveError && <Alert tone="danger">{saveError}</Alert>}

              <div className="flex flex-wrap items-center justify-between gap-3 pt-1">
                <p className="text-xs text-slate-500">Al guardar, el docente recibe un aviso y debe confirmarlo. Si ya lo había confirmado y cambias algo, vuelve a pedirle su confirmación.</p>
                <Button variant="primary" icon={<Send className="w-4 h-4" />} processing={saving} disabled={rows.length === 0} onClick={save}>
                  Guardar y enviar al docente
                </Button>
              </div>
            </div>
          </Card>
        )}

        <Card>
          <CardHeader title={canManage ? 'Vista previa' : 'Semana del docente'} text={schedule || rows.length ? `Vale desde el ${formatDay(validFrom)}.` : undefined} />
          <div className="px-5 py-4">
            {preview.length === 0 ? <EmptyState icon={CalendarDays} title="Sin bloques" text={canManage ? 'Agrega bloques arriba para verlos aquí.' : 'Todavía no se armó su horario.'} /> : <WeekGrid blocks={preview} />}
          </div>
        </Card>

        <NoClassDaysCard teacherId={teacherId} canManage={canManage} />

        {schedule?.confirmed && schedule.status !== 'CONFIRMED' && (
          <Card>
            <CardHeader title="Lo que ven hoy los alumnos" text={`Última versión confirmada por el docente, desde el ${formatDay(schedule.confirmed.valid_from)}. Sigue vigente hasta que confirme la nueva.`} />
            <div className="px-5 py-4">
              <WeekGrid blocks={schedule.confirmed.blocks} />
            </div>
          </Card>
        )}
      </div>
    </div>
  );
};
