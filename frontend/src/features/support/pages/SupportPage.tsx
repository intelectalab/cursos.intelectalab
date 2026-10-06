import React, { useCallback, useEffect, useState } from 'react';
import { Headset, Inbox, Search, UserCheck, UserX } from 'lucide-react';
import { useAuth } from '../../auth/AuthContext';
import { apiErrorMessage } from '../../../shared/api/client';
import {
  Alert,
  Badge,
  Button,
  Card,
  EmptyState,
  ErrorState,
  Field,
  INPUT_CLASS,
  LoadingState,
  Modal,
  PageHeader,
  Pagination,
  StatCard,
  Table,
  TBody,
  Td,
  Th,
  THead,
  Tr,
} from '../../../shared/ui';
import type { PageMeta } from '../../identity/api';
import { formatDateTime, orderCode } from '../../sales/api';
import {
  supportApi,
  TICKET_CATEGORIES,
  TICKET_CATEGORY_LABEL,
  TICKET_PRIORITIES,
  TICKET_PRIORITY_LABEL,
  TICKET_STATUS_LABEL,
  TICKET_STATUSES,
  ticketCode,
  type InboxCounts,
  type Ticket,
  type TicketCategory,
  type TicketPriority,
  type TicketStatus,
  type TicketSummary,
} from '../api';
import { ReplyBox, TicketStatusBadge, TicketThread } from '../components/TicketParts';

type Agent = { id: string; name: string };

// Un ticket visto por el personal: conversación, notas internas y gestión
const StaffTicketModal: React.FC<{
  ticketId: string;
  canManage: boolean;
  agents: Agent[];
  onClose: () => void;
  onChanged: () => void;
}> = ({ ticketId, canManage, agents, onClose, onChanged }) => {
  const [ticket, setTicket] = useState<Ticket | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    supportApi
      .ticket(ticketId)
      .then(setTicket)
      .catch((err) => setError(apiErrorMessage(err, 'No se pudo cargar el ticket.')));
  }, [ticketId]);

  const apply = (updated: Ticket) => {
    setTicket(updated);
    onChanged();
  };

  const run = async (action: () => Promise<Ticket>) => {
    setBusy(true);
    setError(null);
    try {
      apply(await action());
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo guardar el cambio.'));
    } finally {
      setBusy(false);
    }
  };

  const closed = ticket?.status === 'CLOSED';
  const editable = canManage && !closed && !busy;

  return (
    <Modal
      size="wide"
      title={ticket ? ticket.subject : 'Ticket'}
      subtitle={ticket ? `${ticketCode(ticket.number)} · ${ticket.requester.name ?? ''} · ${ticket.requester.email ?? ''}` : undefined}
      onClose={onClose}
      footer={
        <>
          {ticket && canManage && !closed && ticket.status !== 'RESOLVED' && (
            <Button variant="primary" processing={busy} onClick={() => run(() => supportApi.changeStatus(ticket.id, 'RESOLVED'))}>
              Marcar como resuelto
            </Button>
          )}
          <Button onClick={onClose}>Salir</Button>
        </>
      }
    >
      <div className="space-y-5">
        {error && <Alert>{error}</Alert>}
        {!ticket && !error && <LoadingState />}
        {ticket && (
          <>
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
              <Field label="Estado">
                <select
                  className={INPUT_CLASS}
                  disabled={!editable}
                  value={ticket.status}
                  onChange={(event) => run(() => supportApi.changeStatus(ticket.id, event.target.value as TicketStatus))}
                >
                  {TICKET_STATUSES.map((status) => (
                    <option key={status} value={status}>
                      {TICKET_STATUS_LABEL[status]}
                    </option>
                  ))}
                </select>
              </Field>
              <Field label="A cargo de">
                <select
                  className={INPUT_CLASS}
                  disabled={!editable}
                  value={ticket.assignee?.id ?? ''}
                  onChange={(event) => run(() => supportApi.update(ticket.id, { assignee_id: event.target.value || null }))}
                >
                  <option value="">Sin asignar</option>
                  {agents.map((agent) => (
                    <option key={agent.id} value={agent.id}>
                      {agent.name}
                    </option>
                  ))}
                </select>
              </Field>
              <Field label="Prioridad">
                <select
                  className={INPUT_CLASS}
                  disabled={!editable}
                  value={ticket.priority}
                  onChange={(event) => run(() => supportApi.update(ticket.id, { priority: event.target.value as TicketPriority }))}
                >
                  {TICKET_PRIORITIES.map((priority) => (
                    <option key={priority} value={priority}>
                      {TICKET_PRIORITY_LABEL[priority]}
                    </option>
                  ))}
                </select>
              </Field>
              <Field label="Tema">
                <select
                  className={INPUT_CLASS}
                  disabled={!editable}
                  value={ticket.category}
                  onChange={(event) => run(() => supportApi.update(ticket.id, { category: event.target.value as TicketCategory }))}
                >
                  {TICKET_CATEGORIES.map((category) => (
                    <option key={category} value={category}>
                      {TICKET_CATEGORY_LABEL[category]}
                    </option>
                  ))}
                </select>
              </Field>
            </div>

            {(ticket.course || ticket.order) && (
              <p className="text-[13px] text-slate-600">
                Se refiere a: {[ticket.course && `curso "${ticket.course.title}"`, ticket.order && `orden ${orderCode(ticket.order.number)}`].filter(Boolean).join(' y ')}.
              </p>
            )}

            <TicketThread ticket={ticket} viewer="staff" />

            {closed ? (
              <Alert tone="info">Ticket cerrado el {formatDateTime(ticket.closed_at)}. Ya no admite mensajes.</Alert>
            ) : (
              canManage && (
                <ReplyBox
                  maxFiles={3}
                  allowInternal
                  placeholder="Escribe la respuesta para quien consulta…"
                  onSend={async (body, files, internal) => apply(await supportApi.reply(ticket.id, body, files, internal))}
                />
              )
            )}
          </>
        )}
      </div>
    </Modal>
  );
};

// Bandeja de soporte de la academia
export const SupportPage: React.FC = () => {
  const { hasPermission } = useAuth();
  const canManage = hasPermission('tickets.manage');

  const [rows, setRows] = useState<TicketSummary[]>([]);
  const [meta, setMeta] = useState<PageMeta | null>(null);
  const [counts, setCounts] = useState<InboxCounts | null>(null);
  const [agents, setAgents] = useState<Agent[]>([]);
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('PENDING');
  const [assignee, setAssignee] = useState('');
  const [category, setCategory] = useState('');
  const [page, setPage] = useState(1);
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [errorMessage, setErrorMessage] = useState('');
  const [openId, setOpenId] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const result = await supportApi.inbox({
        search: search || undefined,
        status: status || undefined,
        assignee: assignee || undefined,
        category: category || undefined,
        page,
      });
      setRows(result.data);
      setMeta(result.meta);
      setCounts(result.counts);
      setState('ready');
    } catch (err) {
      setErrorMessage(apiErrorMessage(err, 'Verifica tu conexión e inténtalo nuevamente.'));
      setState('error');
    }
  }, [search, status, assignee, category, page]);

  useEffect(() => {
    const timer = setTimeout(load, 250);
    return () => clearTimeout(timer);
  }, [load]);

  useEffect(() => {
    supportApi
      .agents()
      .then(setAgents)
      .catch(() => setAgents([]));
  }, []);

  const filter = (apply: () => void) => {
    apply();
    setPage(1);
  };
  const filtered = search !== '' || status !== 'PENDING' || assignee !== '' || category !== '';

  return (
    <div>
      <PageHeader title="Soporte" icon={Headset} subtitle="Consultas de alumnos, compradores y empresas." />

      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <StatCard label="Esperan respuesta" value={counts?.awaiting ?? '—'} hint="Lo último lo escribió quien consulta" icon={Inbox} tone="warning" />
        <StatCard label="Sin asignar" value={counts?.unassigned ?? '—'} hint="Nadie los tomó todavía" icon={UserX} tone="info" />
        <StatCard label="A mi cargo" value={counts?.mine ?? '—'} hint="Abiertos o en atención" icon={UserCheck} tone="brand" />
      </div>

      <Card>
        <div className="flex flex-wrap items-center gap-3 px-5 py-4 border-b border-slate-200/80">
          <div className="relative flex-1 min-w-[200px] max-w-sm">
            <Search className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" />
            <input
              className={`${INPUT_CLASS} pl-9`}
              placeholder="Buscar por asunto, persona o número"
              aria-label="Buscar ticket"
              value={search}
              onChange={(event) => filter(() => setSearch(event.target.value))}
            />
          </div>
          <select className={`${INPUT_CLASS} !w-44`} aria-label="Filtrar por estado" value={status} onChange={(event) => filter(() => setStatus(event.target.value))}>
            <option value="PENDING">Por atender</option>
            {TICKET_STATUSES.map((item) => (
              <option key={item} value={item}>
                {TICKET_STATUS_LABEL[item]}
              </option>
            ))}
            <option value="">Todos</option>
          </select>
          <select className={`${INPUT_CLASS} !w-44`} aria-label="Filtrar por responsable" value={assignee} onChange={(event) => filter(() => setAssignee(event.target.value))}>
            <option value="">Todo el equipo</option>
            <option value="me">A mi cargo</option>
            <option value="none">Sin asignar</option>
            {agents.map((agent) => (
              <option key={agent.id} value={agent.id}>
                {agent.name}
              </option>
            ))}
          </select>
          <select className={`${INPUT_CLASS} !w-48`} aria-label="Filtrar por tema" value={category} onChange={(event) => filter(() => setCategory(event.target.value))}>
            <option value="">Todos los temas</option>
            {TICKET_CATEGORIES.map((item) => (
              <option key={item} value={item}>
                {TICKET_CATEGORY_LABEL[item]}
              </option>
            ))}
          </select>
        </div>

        {state === 'loading' && <LoadingState />}
        {state === 'error' && <ErrorState message={errorMessage} onRetry={load} />}
        {state === 'ready' && rows.length === 0 && (
          <EmptyState
            icon={Headset}
            title={filtered ? 'Sin tickets con estos filtros' : 'No hay tickets por atender'}
            text={filtered ? 'Prueba con otra búsqueda o cambia los filtros.' : 'Cuando un alumno, comprador o empresa pida ayuda, su consulta aparecerá aquí.'}
          />
        )}
        {state === 'ready' && rows.length > 0 && (
          <Table>
            <THead>
              <Th>Ticket</Th>
              <Th>Quién consulta</Th>
              <Th>Tema</Th>
              <Th>Estado</Th>
              <Th>A cargo de</Th>
              <Th>Último mensaje</Th>
            </THead>
            <TBody>
              {rows.map((ticket) => (
                <Tr key={ticket.id} className="cursor-pointer" onClick={() => setOpenId(ticket.id)}>
                  <Td className="min-w-[220px]">
                    <button
                      type="button"
                      className="text-left font-semibold text-slate-900 hover:text-brand-700 cursor-pointer"
                      onClick={(event) => {
                        event.stopPropagation();
                        setOpenId(ticket.id);
                      }}
                    >
                      {ticket.subject}
                    </button>
                    <p className="text-xs text-slate-500 tnum">
                      {ticketCode(ticket.number)}
                      {ticket.priority === 'HIGH' && <span className="ml-2 font-semibold text-rose-600">Prioridad alta</span>}
                    </p>
                  </Td>
                  <Td className="whitespace-nowrap">
                    <p className="text-slate-800">{ticket.requester.name}</p>
                    <p className="text-xs text-slate-500">{ticket.requester.email}</p>
                  </Td>
                  <Td className="text-slate-700">{TICKET_CATEGORY_LABEL[ticket.category]}</Td>
                  <Td>
                    <TicketStatusBadge status={ticket.status} />
                    {ticket.awaits_staff && (
                      <p className="mt-1">
                        <Badge tone="warning">Espera respuesta</Badge>
                      </p>
                    )}
                  </Td>
                  <Td className="text-slate-700 whitespace-nowrap">{ticket.assignee?.name ?? '—'}</Td>
                  <Td className="text-slate-700 whitespace-nowrap">{formatDateTime(ticket.last_message_at)}</Td>
                </Tr>
              ))}
            </TBody>
          </Table>
        )}
        {state === 'ready' && meta && <Pagination page={meta.page} lastPage={meta.last_page} total={meta.total} noun="tickets" onPage={setPage} />}
      </Card>

      {openId && <StaffTicketModal ticketId={openId} canManage={canManage} agents={agents} onClose={() => setOpenId(null)} onChanged={load} />}
    </div>
  );
};
