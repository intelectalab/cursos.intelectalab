import React, { useCallback, useEffect, useRef, useState } from 'react';
import { LifeBuoy, Paperclip, Plus, X } from 'lucide-react';
import { apiErrorMessage } from '../../../shared/api/client';
import { Alert, Button, Card, ConfirmModal, EmptyState, ErrorState, Field, INPUT_CLASS, LoadingState, Modal, PageHeader } from '../../../shared/ui';
import { formatDateTime, orderCode } from '../../sales/api';
import { supportApi, TICKET_CATEGORY_LABEL, ticketCode, type Ticket, type TicketCategory, type TicketOptions, type TicketSummary } from '../api';
import { ReplyBox, TicketStatusBadge, TicketThread } from '../components/TicketParts';

const NewTicketModal: React.FC<{ onClose: () => void; onCreated: (ticket: Ticket) => void }> = ({ onClose, onCreated }) => {
  const fileInput = useRef<HTMLInputElement>(null);
  const [options, setOptions] = useState<TicketOptions | null>(null);
  const [subject, setSubject] = useState('');
  const [category, setCategory] = useState<TicketCategory | ''>('');
  const [body, setBody] = useState('');
  const [courseId, setCourseId] = useState('');
  const [orderId, setOrderId] = useState('');
  const [files, setFiles] = useState<File[]>([]);
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    supportApi
      .options()
      .then(setOptions)
      .catch((err) => setError(apiErrorMessage(err, 'No se pudo preparar el formulario.')));
  }, []);

  const maxFiles = options?.attachments.max_files ?? 3;

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!category) return;
    setProcessing(true);
    setError(null);
    try {
      onCreated(
        await supportApi.open(
          { subject: subject.trim(), category, body: body.trim(), course_id: courseId || undefined, order_id: orderId || undefined },
          files,
        ),
      );
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo enviar la consulta.'));
      setProcessing(false);
    }
  };

  return (
    <Modal
      title="Nueva consulta"
      subtitle="Cuéntanos qué pasa y el equipo de soporte te responderá aquí mismo."
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="new-ticket-form" processing={processing} disabled={!options}>
            Enviar consulta
          </Button>
        </>
      }
    >
      <form id="new-ticket-form" onSubmit={submit} className="space-y-4">
        {error && <Alert>{error}</Alert>}
        <Field label="¿Sobre qué es?">
          <select className={INPUT_CLASS} required autoFocus value={category} onChange={(event) => setCategory(event.target.value as TicketCategory)}>
            <option value="">Elige un tema</option>
            {(options?.categories ?? []).map((item) => (
              <option key={item} value={item}>
                {TICKET_CATEGORY_LABEL[item]}
              </option>
            ))}
          </select>
        </Field>
        {(category === 'COURSE' || category === 'CERTIFICATE') && options && options.courses.length > 0 && (
          <Field label="Curso (opcional)">
            <select className={INPUT_CLASS} value={courseId} onChange={(event) => setCourseId(event.target.value)}>
              <option value="">Ninguno en particular</option>
              {options.courses.map((course) => (
                <option key={course.id} value={course.id}>
                  {course.title}
                </option>
              ))}
            </select>
          </Field>
        )}
        {category === 'PAYMENT' && options && options.orders.length > 0 && (
          <Field label="Compra (opcional)">
            <select className={INPUT_CLASS} value={orderId} onChange={(event) => setOrderId(event.target.value)}>
              <option value="">Ninguna en particular</option>
              {options.orders.map((order) => (
                <option key={order.id} value={order.id}>
                  Orden {orderCode(order.number)}
                </option>
              ))}
            </select>
          </Field>
        )}
        <Field label="Asunto">
          <input className={INPUT_CLASS} required minLength={5} maxLength={200} placeholder="Ejemplo: no puedo abrir el video de la lección 3" value={subject} onChange={(event) => setSubject(event.target.value)} />
        </Field>
        <Field label="Detalle" hint="Mientras más datos des (qué hiciste, qué mensaje apareció), más rápido se resuelve.">
          <textarea className={INPUT_CLASS} rows={5} required maxLength={5000} value={body} onChange={(event) => setBody(event.target.value)} />
        </Field>
        <div>
          <input
            ref={fileInput}
            type="file"
            multiple
            hidden
            onChange={(event) => {
              // Se copian antes de vaciar el campo: la lista se pierde al limpiarlo
              const picked = Array.from(event.target.files ?? []);
              setFiles((current) => [...current, ...picked].slice(0, maxFiles));
              event.target.value = '';
            }}
          />
          <div className="flex flex-wrap items-center gap-2">
            <Button size="sm" icon={<Paperclip className="w-3.5 h-3.5" />} disabled={files.length >= maxFiles} onClick={() => fileInput.current?.click()}>
              Adjuntar archivo
            </Button>
            {files.map((file, index) => (
              <span key={`${file.name}-${index}`} className="inline-flex items-center gap-1.5 h-7 pl-2.5 pr-1 rounded-md bg-slate-100 text-xs text-slate-700">
                <span className="max-w-[150px] truncate">{file.name}</span>
                <button
                  type="button"
                  aria-label={`Quitar ${file.name}`}
                  className="p-1 text-slate-500 hover:text-rose-600 cursor-pointer"
                  onClick={() => setFiles((current) => current.filter((_, position) => position !== index))}
                >
                  <X className="w-3 h-3" />
                </button>
              </span>
            ))}
          </div>
          {options && (
            <p className="text-xs text-slate-500 mt-1.5">
              Hasta {options.attachments.max_files} archivos ({options.attachments.extensions.join(', ')}), de {Math.round(options.attachments.max_kb / 1024)} MB como máximo cada uno.
            </p>
          )}
        </div>
      </form>
    </Modal>
  );
};

const MyTicketModal: React.FC<{ ticketId: string; onClose: () => void; onChanged: () => void }> = ({ ticketId, onClose, onChanged }) => {
  const [ticket, setTicket] = useState<Ticket | null>(null);
  const [closing, setClosing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    supportApi
      .myTicket(ticketId)
      .then(setTicket)
      .catch((err) => setError(apiErrorMessage(err, 'No se pudo cargar la consulta.')));
  }, [ticketId]);

  const apply = (updated: Ticket) => {
    setTicket(updated);
    onChanged();
  };

  if (closing && ticket) {
    return (
      <ConfirmModal
        title="Cerrar consulta"
        text="Úsalo si tu problema ya se resolvió. Una consulta cerrada no se vuelve a abrir: si necesitas ayuda otra vez, crea una nueva."
        confirmLabel="Cerrar consulta"
        variant="primary"
        onClose={() => setClosing(false)}
        onConfirm={async () => {
          apply(await supportApi.closeMine(ticket.id));
          setClosing(false);
        }}
      />
    );
  }

  return (
    <Modal
      size="wide"
      title={ticket ? ticket.subject : 'Consulta'}
      subtitle={ticket ? `${ticketCode(ticket.number)} · ${TICKET_CATEGORY_LABEL[ticket.category]} · abierta el ${formatDateTime(ticket.created_at)}` : undefined}
      onClose={onClose}
      footer={
        <>
          {ticket && ticket.status !== 'CLOSED' && <Button onClick={() => setClosing(true)}>Ya se resolvió, cerrar</Button>}
          <Button onClick={onClose}>Salir</Button>
        </>
      }
    >
      <div className="space-y-5">
        {error && <Alert>{error}</Alert>}
        {!ticket && !error && <LoadingState />}
        {ticket && (
          <>
            <div className="flex flex-wrap items-center gap-x-4 gap-y-2 text-[13px] text-slate-600">
              <TicketStatusBadge status={ticket.status} />
              {ticket.course && <span>Curso: {ticket.course.title}</span>}
              {ticket.order && <span>Orden {orderCode(ticket.order.number)}</span>}
              {ticket.assignee && <span>Te atiende {ticket.assignee.name}</span>}
            </div>
            <TicketThread ticket={ticket} viewer="requester" />
            {ticket.status === 'CLOSED' ? (
              <Alert tone="info">Esta consulta está cerrada. Si el problema continúa, abre una nueva.</Alert>
            ) : (
              <>
                {ticket.status === 'RESOLVED' && (
                  <Alert tone="success">Soporte marcó esta consulta como resuelta. Si no quedó resuelta, responde aquí y se reabrirá; si no respondes, se cerrará sola en unos días.</Alert>
                )}
                <ReplyBox maxFiles={3} placeholder="Escribe tu respuesta…" onSend={async (body, files) => apply(await supportApi.replyMine(ticket.id, body, files))} />
              </>
            )}
          </>
        )}
      </div>
    </Modal>
  );
};

// Consultas de soporte de quien tiene la sesión
export const MyTicketsPage: React.FC = () => {
  const [tickets, setTickets] = useState<TicketSummary[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [openId, setOpenId] = useState<string | null>(null);
  const [creating, setCreating] = useState(false);

  const load = useCallback(() => {
    supportApi
      .mine()
      .then((loaded) => {
        setTickets(loaded);
        setError(null);
      })
      .catch((err) => setError(apiErrorMessage(err, 'Verifica tu conexión e inténtalo nuevamente.')));
  }, []);

  useEffect(load, [load]);

  const newButton = (
    <Button variant="primary" icon={<Plus className="w-4 h-4" />} onClick={() => setCreating(true)}>
      Nueva consulta
    </Button>
  );

  return (
    <div>
      <PageHeader title="Ayuda" icon={LifeBuoy} subtitle="Tus consultas al equipo de soporte y sus respuestas." action={newButton} />

      <Card>
        {error && <ErrorState message={error} onRetry={load} />}
        {!error && tickets === null && <LoadingState />}
        {!error && tickets?.length === 0 && (
          <EmptyState icon={LifeBuoy} title="No tienes consultas" text="Si algo no funciona o tienes una duda sobre un curso, un pago o un certificado, escríbenos." action={newButton} />
        )}
        {!error && tickets && tickets.length > 0 && (
          <ul className="divide-y divide-slate-100">
            {tickets.map((ticket) => (
              <li key={ticket.id}>
                <button
                  type="button"
                  onClick={() => setOpenId(ticket.id)}
                  className="w-full flex flex-wrap items-center justify-between gap-3 px-5 py-3.5 text-left hover:bg-brand-50/40 cursor-pointer transition-colors"
                >
                  <span className="min-w-0">
                    <span className="block text-[13px] font-semibold text-slate-900 truncate">{ticket.subject}</span>
                    <span className="block text-xs text-slate-500">
                      {ticketCode(ticket.number)} · {TICKET_CATEGORY_LABEL[ticket.category]} · último mensaje {formatDateTime(ticket.last_message_at)}
                    </span>
                  </span>
                  <span className="flex items-center gap-2.5">
                    {!ticket.awaits_staff && (ticket.status === 'OPEN' || ticket.status === 'IN_PROGRESS') && (
                      <span className="text-xs font-semibold text-brand-700">Te respondieron</span>
                    )}
                    <TicketStatusBadge status={ticket.status} />
                  </span>
                </button>
              </li>
            ))}
          </ul>
        )}
      </Card>

      {creating && (
        <NewTicketModal
          onClose={() => setCreating(false)}
          onCreated={(ticket) => {
            setCreating(false);
            setOpenId(ticket.id);
            load();
          }}
        />
      )}
      {openId && <MyTicketModal ticketId={openId} onClose={() => setOpenId(null)} onChanged={load} />}
    </div>
  );
};
