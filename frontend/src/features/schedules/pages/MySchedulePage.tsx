import React, { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { CalendarCheck2, CalendarDays, Check, MessageSquareWarning, Video } from 'lucide-react';
import { apiErrorMessage } from '../../../shared/api/client';
import { Alert, Badge, Button, Card, CardHeader, EmptyState, ErrorState, Field, INPUT_CLASS, LoadingState, Modal, PageHeader } from '../../../shared/ui';
import { WeekGrid } from '../components/WeekGrid';
import { DAYS, formatDay, schedulesApi, STATUS_META, type Schedule } from '../api';

/**
 * «Mi horario»: el docente ve la semana que le armó la academia y la
 * confirma, o explica qué hay que cambiar. No la edita.
 */
export const MySchedulePage: React.FC = () => {
  const [schedule, setSchedule] = useState<Schedule | null>(null);
  const [timezone, setTimezone] = useState('');
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [errorMessage, setErrorMessage] = useState('');
  const [confirming, setConfirming] = useState(false);
  const [observing, setObserving] = useState(false);
  const [observation, setObservation] = useState('');
  const [sending, setSending] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    setState('loading');
    try {
      const result = await schedulesApi.mine();
      setSchedule(result.schedule);
      setTimezone(result.timezone);
      setState('ready');
    } catch (err) {
      setErrorMessage(apiErrorMessage(err, 'No se pudo abrir tu horario.'));
      setState('error');
    }
  }, []);

  useEffect(() => {
    const timer = setTimeout(load, 0);
    return () => clearTimeout(timer);
  }, [load]);

  const confirm = async () => {
    setConfirming(true);
    setActionError(null);
    try {
      setSchedule(await schedulesApi.confirm());
      setNotice('Confirmaste tu horario. Tus clases en vivo ya las ven los alumnos de cada curso.');
    } catch (err) {
      setActionError(apiErrorMessage(err, 'No se pudo confirmar.'));
    } finally {
      setConfirming(false);
    }
  };

  const observe = async () => {
    setSending(true);
    setActionError(null);
    try {
      setSchedule(await schedulesApi.observe(observation.trim()));
      setObserving(false);
      setObservation('');
      setNotice('Enviamos tus observaciones. Te avisaremos cuando llegue el horario corregido.');
    } catch (err) {
      setActionError(apiErrorMessage(err, 'No se pudo enviar tu observación.'));
    } finally {
      setSending(false);
    }
  };

  if (state === 'loading') return <LoadingState />;
  if (state === 'error') return <ErrorState message={errorMessage} onRetry={load} />;

  const liveClasses = (schedule?.blocks ?? []).filter((block) => block.type === 'LIVE_CLASS');

  return (
    <div>
      <PageHeader
        title="Mi horario"
        icon={CalendarDays}
        subtitle={`La semana que te armó la academia: cuándo estás disponible, cuándo das tus clases en vivo y cuándo revisas trabajos. Horas de la academia (${timezone}).`}
        action={schedule && <Badge tone={STATUS_META[schedule.status].tone} dot>{STATUS_META[schedule.status].label}</Badge>}
      />

      {!schedule && (
        <Card>
          <EmptyState icon={CalendarDays} title="Todavía no tienes un horario" text="Cuando la academia lo arme, te llegará un aviso para que lo revises y lo confirmes." />
        </Card>
      )}

      {schedule && (
        <div className="space-y-4">
          {notice && <Alert tone="success">{notice}</Alert>}
          {actionError && <Alert tone="danger">{actionError}</Alert>}

          {schedule.status === 'PENDING' && (
            <Card>
              <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 px-5 py-4">
                <div>
                  <p className="text-sm font-semibold text-slate-900">Revisa tu horario y confírmalo</p>
                  <p className="text-[13px] text-slate-600 mt-0.5">
                    {schedule.sent_by ? `${schedule.sent_by} lo preparó` : 'La academia lo preparó'} y vale desde el {formatDay(schedule.valid_from)}. Si algo no te funciona, cuéntale qué cambiar.
                  </p>
                </div>
                <div className="flex flex-wrap gap-2.5">
                  <Button icon={<MessageSquareWarning className="w-4 h-4" />} onClick={() => setObserving(true)}>
                    Pedir cambios
                  </Button>
                  <Button variant="primary" icon={<Check className="w-4 h-4" />} processing={confirming} onClick={confirm}>
                    Confirmar horario
                  </Button>
                </div>
              </div>
            </Card>
          )}
          {schedule.status === 'OBSERVED' && (
            <Alert tone="warning">
              Pediste cambios: «{schedule.observation}». La academia te enviará el horario corregido.
            </Alert>
          )}
          {schedule.status === 'CONFIRMED' && !notice && (
            <Alert tone="success">Confirmaste este horario. Si la academia lo cambia, te llegará un aviso para confirmarlo otra vez.</Alert>
          )}

          <Card>
            <CardHeader title="Semana" text={`Vale desde el ${formatDay(schedule.valid_from)}.`} />
            <div className="px-5 py-4">
              {schedule.blocks.length === 0 ? <p className="text-[13px] text-slate-500">Sin bloques.</p> : <WeekGrid blocks={schedule.blocks} />}
            </div>
          </Card>

          {liveClasses.length > 0 && (
            <Card>
              <CardHeader title="Tus clases en vivo" text="Los alumnos de cada curso las ven con su enlace cuando confirmas." />
              <ul className="divide-y divide-slate-100">
                {liveClasses.map((block) => (
                  <li key={`${block.day}-${block.start}`} className="flex flex-wrap items-center gap-3 px-5 py-3 text-[13px]">
                    <span className="w-9 h-9 rounded-xl bg-violet-50 text-violet-600 flex items-center justify-center shrink-0">
                      <Video className="w-4 h-4" />
                    </span>
                    <div className="min-w-0 flex-1">
                      <p className="font-semibold text-slate-900">{block.course_title ?? 'Sin curso'}</p>
                      <p className="text-slate-600 tnum">
                        {DAYS[block.day - 1]} · {block.start}–{block.end}
                      </p>
                    </div>
                    {block.meeting_url && (
                      <a href={block.meeting_url} target="_blank" rel="noreferrer noopener" className="font-semibold text-brand-700 underline break-all">
                        Enlace de la clase
                      </a>
                    )}
                    {block.course_id && schedule.status === 'CONFIRMED' && (
                      <Link to={`/courses/${block.course_id}/attendance`} className="font-semibold text-brand-700 underline">
                        Tomar asistencia
                      </Link>
                    )}
                  </li>
                ))}
              </ul>
            </Card>
          )}

          {schedule.confirmed && schedule.status !== 'CONFIRMED' && (
            <Card>
              <CardHeader title="Tu horario confirmado anterior" text="Es el que sigue vigente hasta que confirmes el nuevo." />
              <div className="px-5 py-4">
                <WeekGrid blocks={schedule.confirmed.blocks} />
              </div>
            </Card>
          )}
        </div>
      )}

      {observing && (
        <Modal
          title="Pedir cambios en tu horario"
          subtitle="Cuéntale a la academia qué no te funciona. Tu horario confirmado anterior sigue vigente mientras tanto."
          onClose={() => setObserving(false)}
          footer={
            <>
              <Button onClick={() => setObserving(false)}>Cancelar</Button>
              <Button variant="primary" icon={<CalendarCheck2 className="w-4 h-4" />} processing={sending} disabled={observation.trim().length < 5} onClick={observe}>
                Enviar
              </Button>
            </>
          }
        >
          <Field label="¿Qué hay que cambiar?" hint="Ej.: los martes no puedo antes de las 10:00; prefiero dar la clase los jueves.">
            <textarea className={`${INPUT_CLASS} h-auto min-h-28 py-2`} maxLength={1000} value={observation} onChange={(event) => setObservation(event.target.value)} />
          </Field>
        </Modal>
      )}
    </div>
  );
};
