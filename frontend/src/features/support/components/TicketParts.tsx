import React, { useRef, useState } from 'react';
import { Lock, Paperclip, Send, X } from 'lucide-react';
import { apiErrorMessage } from '../../../shared/api/client';
import { Alert, Avatar, Badge, Button, CHECKBOX_CLASS, INPUT_CLASS } from '../../../shared/ui';
import { formatDateTime } from '../../sales/api';
import { formatSize, supportApi, TICKET_STATUS_LABEL, TICKET_STATUS_TONE, type Ticket, type TicketAttachment, type TicketStatus } from '../api';

export const TicketStatusBadge: React.FC<{ status: TicketStatus }> = ({ status }) => (
  <Badge tone={TICKET_STATUS_TONE[status]} dot>
    {TICKET_STATUS_LABEL[status]}
  </Badge>
);

const AttachmentChip: React.FC<{ attachment: TicketAttachment }> = ({ attachment }) => {
  const [busy, setBusy] = useState(false);

  const download = async () => {
    setBusy(true);
    try {
      await supportApi.download(attachment);
    } finally {
      setBusy(false);
    }
  };

  return (
    <button
      type="button"
      onClick={download}
      disabled={busy}
      className="inline-flex items-center gap-1.5 max-w-full h-7 px-2.5 rounded-md bg-white border border-slate-200 text-xs text-slate-700 hover:border-brand-300 hover:text-brand-700 cursor-pointer transition-colors disabled:opacity-60"
    >
      <Paperclip className="w-3 h-3 shrink-0" />
      <span className="truncate">{attachment.name}</span>
      <span className="text-slate-400 shrink-0">{formatSize(attachment.size_bytes)}</span>
    </button>
  );
};

// La conversación del ticket. `viewer` decide de qué lado queda cada mensaje.
export const TicketThread: React.FC<{ ticket: Ticket; viewer: 'requester' | 'staff' }> = ({ ticket, viewer }) => (
  <ol className="space-y-4">
    {ticket.messages.map((message) => {
      const own = viewer === 'staff' ? message.is_staff : !message.is_staff;
      const name = message.author ?? (message.is_staff ? 'Soporte' : 'Usuario');
      return (
        <li key={message.id} className={`flex gap-3 ${own ? 'flex-row-reverse' : ''}`}>
          <Avatar name={name} size="sm" />
          <div className={`min-w-0 max-w-[85%] ${own ? 'items-end' : ''} flex flex-col`}>
            <p className="text-xs text-slate-500 mb-1">
              <span className="font-semibold text-slate-700">{name}</span>
              {message.is_staff && viewer === 'requester' && ' · Soporte'} · {formatDateTime(message.created_at)}
            </p>
            <div
              className={`rounded-2xl px-3.5 py-2.5 text-[13px] leading-relaxed whitespace-pre-line break-words ${
                message.is_internal
                  ? 'bg-amber-50 border border-amber-200 text-amber-900'
                  : own
                    ? 'bg-brand-600 text-white rounded-tr-md'
                    : 'bg-slate-100 text-slate-800 rounded-tl-md'
              }`}
            >
              {message.is_internal && (
                <span className="flex items-center gap-1 text-[11px] font-semibold uppercase tracking-wide text-amber-700 mb-1">
                  <Lock className="w-3 h-3" />
                  Nota interna
                </span>
              )}
              {message.body}
            </div>
            {message.attachments.length > 0 && (
              <div className={`flex flex-wrap gap-1.5 mt-1.5 ${own ? 'justify-end' : ''}`}>
                {message.attachments.map((attachment) => (
                  <AttachmentChip key={attachment.id} attachment={attachment} />
                ))}
              </div>
            )}
          </div>
        </li>
      );
    })}
  </ol>
);

// Caja para escribir un mensaje con adjuntos. El personal puede marcarlo como nota interna.
export const ReplyBox: React.FC<{
  maxFiles: number;
  allowInternal?: boolean;
  placeholder: string;
  onSend: (body: string, files: File[], internal: boolean) => Promise<void>;
}> = ({ maxFiles, allowInternal = false, placeholder, onSend }) => {
  const input = useRef<HTMLInputElement>(null);
  const [body, setBody] = useState('');
  const [files, setFiles] = useState<File[]>([]);
  const [internal, setInternal] = useState(false);
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setProcessing(true);
    setError(null);
    try {
      await onSend(body.trim(), files, internal);
      setBody('');
      setFiles([]);
      setInternal(false);
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo enviar el mensaje.'));
    } finally {
      setProcessing(false);
    }
  };

  const addFiles = (list: FileList | null) => {
    // Se copian antes de vaciar el campo: la lista se pierde al limpiarlo
    const picked = Array.from(list ?? []);
    setFiles((current) => [...current, ...picked].slice(0, maxFiles));
    if (input.current) input.current.value = '';
  };

  return (
    <form onSubmit={submit} className={`rounded-xl border p-3 space-y-2.5 ${internal ? 'border-amber-200 bg-amber-50/60' : 'border-slate-200 bg-slate-50/60'}`}>
      {error && <Alert>{error}</Alert>}
      <textarea
        className={INPUT_CLASS}
        rows={3}
        required
        maxLength={5000}
        placeholder={internal ? 'Nota para el equipo: quien consulta no la verá.' : placeholder}
        aria-label="Mensaje"
        value={body}
        onChange={(event) => setBody(event.target.value)}
      />
      {files.length > 0 && (
        <ul className="flex flex-wrap gap-1.5">
          {files.map((file, index) => (
            <li key={`${file.name}-${index}`} className="inline-flex items-center gap-1.5 h-7 pl-2.5 pr-1 rounded-md bg-white border border-slate-200 text-xs text-slate-700">
              <Paperclip className="w-3 h-3" />
              <span className="max-w-[160px] truncate">{file.name}</span>
              <button
                type="button"
                aria-label={`Quitar ${file.name}`}
                className="p-1 text-slate-400 hover:text-rose-600 cursor-pointer"
                onClick={() => setFiles((current) => current.filter((_, position) => position !== index))}
              >
                <X className="w-3 h-3" />
              </button>
            </li>
          ))}
        </ul>
      )}
      <div className="flex flex-wrap items-center justify-between gap-2.5">
        <div className="flex flex-wrap items-center gap-3">
          <input ref={input} type="file" multiple hidden onChange={(event) => addFiles(event.target.files)} />
          <Button size="sm" icon={<Paperclip className="w-3.5 h-3.5" />} disabled={files.length >= maxFiles} onClick={() => input.current?.click()}>
            Adjuntar
          </Button>
          {allowInternal && (
            <label className="flex items-center gap-2 text-xs text-slate-600 cursor-pointer">
              <input type="checkbox" className={CHECKBOX_CLASS} checked={internal} onChange={(event) => setInternal(event.target.checked)} />
              Nota interna
            </label>
          )}
        </div>
        <Button size="sm" variant="primary" type="submit" icon={<Send className="w-3.5 h-3.5" />} processing={processing}>
          {internal ? 'Guardar nota' : 'Enviar'}
        </Button>
      </div>
    </form>
  );
};
