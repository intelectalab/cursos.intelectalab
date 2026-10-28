import React, { useCallback, useEffect, useState } from 'react';
import { AlarmClock, BookOpenCheck, CheckCircle2, ExternalLink, FileSearch, Hourglass, Inbox, MailOpen, Search } from 'lucide-react';
import { FilterCards, type FilterCard } from '../../../shared/ui/filters';
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
  Table,
  TBody,
  Td,
  Th,
  THead,
  Tr,
} from '../../../shared/ui';
import type { PageMeta } from '../../identity/api';
import { formatDay } from '../../crm/api';
import { formatDateTime } from '../../sales/api';
import { claimsApi, CLAIM_TYPE_LABEL, type Claim, type ClaimSummary } from '../api';
import { ClaimSheet, ClaimStatusBadge } from '../components/ClaimSheet';
import { appPath } from '../../../shared/tenancy/hosts';
import { SearchSelect } from '../../../shared/ui/SearchSelect';
import { useUrlFilters } from '../../../shared/filters/useUrlFilters';
import { ClearFilters, DateRangeFilter } from '../../../shared/ui/listFilters';

const ClaimModal: React.FC<{ claimId: string; canManage: boolean; onClose: () => void; onChanged: () => void }> = ({ claimId, canManage, onClose, onChanged }) => {
  const [claim, setClaim] = useState<Claim | null>(null);
  const [mode, setMode] = useState<'view' | 'respond'>('view');
  const [response, setResponse] = useState('');
  const [note, setNote] = useState('');
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    claimsApi
      .get(claimId)
      .then(setClaim)
      .catch((err) => setError(apiErrorMessage(err, 'No se pudo cargar la hoja.')));
  }, [claimId]);

  const run = async (key: string, action: () => Promise<Claim>, fallback: string) => {
    setBusy(key);
    setError(null);
    try {
      setClaim(await action());
      onChanged();
      return true;
    } catch (err) {
      setError(apiErrorMessage(err, fallback));
      return false;
    } finally {
      setBusy(null);
    }
  };

  const pending = claim && claim.status !== 'ANSWERED';

  return (
    <Modal
      size="wide"
      title={claim ? `${CLAIM_TYPE_LABEL[claim.type]} ${claim.code}` : 'Hoja de reclamación'}
      subtitle={claim ? `${claim.consumer.name} · registrado el ${formatDateTime(claim.created_at)}` : undefined}
      onClose={onClose}
      footer={
        mode === 'respond' && claim ? (
          <>
            <Button onClick={() => setMode('view')}>Volver</Button>
            <Button variant="primary" type="submit" form="claim-response-form" processing={busy === 'respond'}>
              Enviar respuesta
            </Button>
          </>
        ) : (
          <>
            <Button onClick={onClose}>Cerrar</Button>
            {claim && canManage && claim.status === 'RECEIVED' && (
              <Button processing={busy === 'review'} onClick={() => run('review', () => claimsApi.review(claim.id), 'No se pudo cambiar el estado.')}>
                Marcar en revisión
              </Button>
            )}
            {claim && canManage && pending && (
              <Button variant="primary" onClick={() => setMode('respond')}>
                Responder
              </Button>
            )}
          </>
        )
      }
    >
      <div className="space-y-5">
        {error && <Alert>{error}</Alert>}
        {!claim && !error && <LoadingState />}
        {claim && mode === 'respond' && (
          <form
            id="claim-response-form"
            className="space-y-4"
            onSubmit={async (event) => {
              event.preventDefault();
              if (await run('respond', () => claimsApi.respond(claim.id, response.trim()), 'No se pudo registrar la respuesta.')) setMode('view');
            }}
          >
            <Alert tone="info">
              Es la respuesta formal al consumidor: la verá al consultar su hoja con su código. <strong>Es una sola y no se puede cambiar después.</strong> El sistema no envía correos todavía: avísale
              también a {claim.consumer.email}.
            </Alert>
            <Field label="Respuesta" hint="Explica qué se revisó, qué se decidió y qué se hará.">
              <textarea className={INPUT_CLASS} rows={8} required minLength={10} maxLength={5000} autoFocus value={response} onChange={(event) => setResponse(event.target.value)} />
            </Field>
          </form>
        )}
        {claim && mode === 'view' && (
          <>
            {claim.overdue && <Alert>El plazo para responder venció el {formatDay(claim.due_at)}.</Alert>}
            <ClaimSheet claim={claim} />

            <section>
              <h3 className="text-[11px] font-semibold uppercase tracking-wide text-slate-500 mb-2">Notas internas</h3>
              {(claim.notes ?? []).length === 0 && <p className="text-[13px] text-slate-500">Sin notas. El consumidor nunca las ve.</p>}
              <ul className="space-y-2">
                {(claim.notes ?? []).map((item) => (
                  <li key={item.id} className="rounded-xl border border-amber-200 bg-amber-50/60 px-3.5 py-2.5">
                    <p className="text-[13px] text-amber-950 whitespace-pre-line">{item.body}</p>
                    <p className="text-xs text-amber-800 mt-1">
                      {formatDateTime(item.at)}
                      {item.by && ` · ${item.by}`}
                    </p>
                  </li>
                ))}
              </ul>
              {canManage && (
                <form
                  className="flex gap-2 mt-3"
                  onSubmit={async (event) => {
                    event.preventDefault();
                    if (await run('note', () => claimsApi.addNote(claim.id, note.trim()), 'No se pudo guardar la nota.')) setNote('');
                  }}
                >
                  <input className={INPUT_CLASS} required maxLength={5000} placeholder="Agregar una nota para el equipo" aria-label="Nota interna" value={note} onChange={(event) => setNote(event.target.value)} />
                  <Button type="submit" processing={busy === 'note'}>
                    Guardar
                  </Button>
                </form>
              )}
            </section>
          </>
        )}
      </div>
    </Modal>
  );
};

// Hojas del Libro de Reclamaciones de la academia
export const ClaimsPage: React.FC = () => {
  const { hasPermission } = useAuth();
  const canManage = hasPermission('claims.manage');

  const [rows, setRows] = useState<ClaimSummary[]>([]);
  const [meta, setMeta] = useState<PageMeta | null>(null);
  const [states, setStates] = useState<Record<string, number>>({});
  // Los filtros viven en la dirección de la página. Se abre en «Sin responder»; «Todas» se guarda como ALL
  const urlFilters = useUrlFilters({ search: '', status: 'PENDING', type: '', from: '', to: '' });
  const { search, type, from, to } = urlFilters.values;
  const status = urlFilters.values.status === 'ALL' ? '' : urlFilters.values.status;
  const setSearch = (value: string) => urlFilters.set({ search: value });
  const setStatus = (value: string) => urlFilters.set({ status: value || 'ALL' });
  const [page, setPage] = useState(1);
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [errorMessage, setErrorMessage] = useState('');
  const [openId, setOpenId] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const result = await claimsApi.list({ search: search || undefined, status: status || undefined, type: type || undefined, from: from || undefined, to: to || undefined, page });
      setRows(result.data);
      setMeta(result.meta);
      setStates(result.states);
      setState('ready');
    } catch (err) {
      setErrorMessage(apiErrorMessage(err, 'Verifica tu conexión e inténtalo nuevamente.'));
      setState('error');
    }
  }, [search, status, type, from, to, page]);

  useEffect(() => {
    const timer = setTimeout(load, 250);
    return () => clearTimeout(timer);
  }, [load]);

  const filter = (apply: () => void) => {
    apply();
    setPage(1);
  };
  const filtered = search !== '' || status !== 'PENDING' || type !== '' || from !== '' || to !== '';
  const cards: FilterCard[] = [
    { key: '', label: 'Todas', value: states.ALL ?? null, icon: BookOpenCheck, tone: 'brand', help: 'Todas las hojas que coinciden con la búsqueda.' },
    { key: 'PENDING', label: 'Sin responder', value: states.PENDING ?? null, icon: Inbox, tone: 'info', help: 'Recibidas o en revisión: todavía no tienen respuesta.' },
    { key: 'DUE_SOON', label: 'Por vencer', value: states.DUE_SOON ?? null, icon: Hourglass, tone: 'warning', help: 'Sin respuesta y su fecha límite es hoy o en los próximos 3 días.' },
    { key: 'OVERDUE', label: 'Fuera de plazo', value: states.OVERDUE ?? null, icon: AlarmClock, tone: 'danger', help: 'Sin respuesta y con la fecha límite vencida. Respóndelas primero.' },
    { key: 'RECEIVED', label: 'Recibidas', value: states.RECEIVED ?? null, icon: MailOpen, tone: 'warning', help: 'Registradas por el consumidor; nadie empezó a revisarlas.' },
    { key: 'IN_REVIEW', label: 'En revisión', value: states.IN_REVIEW ?? null, icon: FileSearch, tone: 'violet', help: 'Alguien del personal las está revisando.' },
    { key: 'ANSWERED', label: 'Respondidas', value: states.ANSWERED ?? null, icon: CheckCircle2, tone: 'success', help: 'Ya tienen respuesta; el consumidor la ve con su código.' },
  ];

  return (
    <div>
      <PageHeader
        title="Libro de Reclamaciones"
        icon={BookOpenCheck}
        subtitle="Reclamos y quejas registrados por los consumidores en el formulario público."
        action={
          <a
            href={appPath('/reclamaciones')}
            target="_blank"
            rel="noreferrer"
            className="inline-flex items-center justify-center gap-2 h-9 px-3.5 rounded-lg text-[13px] font-semibold bg-white text-slate-700 border border-slate-300 hover:bg-slate-50 shadow-xs"
          >
            <ExternalLink className="w-3.5 h-3.5" />
            Ver formulario público
          </a>
        }
      />

      <FilterCards cards={cards} active={status} label="Hojas por estado" onSelect={(key) => filter(() => setStatus(key))} />

      <Card>
        <div className="flex flex-wrap items-center gap-3 px-5 py-4 border-b border-slate-200/80">
          <div className="relative flex-1 min-w-[200px] max-w-sm">
            <Search className="w-4 h-4 text-slate-500 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" />
            <input
              className={`${INPUT_CLASS} pl-9`}
              placeholder="Buscar por código, nombre o documento"
              aria-label="Buscar hoja"
              value={search}
              onChange={(event) => filter(() => setSearch(event.target.value))}
            />
          </div>
          <SearchSelect className={`${INPUT_CLASS} !w-40`} aria-label="Filtrar por tipo" value={type} onChange={(event) => filter(() => urlFilters.set({ type: event.target.value }))}>
            <option value="">Reclamos y quejas</option>
            {(Object.keys(CLAIM_TYPE_LABEL) as (keyof typeof CLAIM_TYPE_LABEL)[]).map((key) => (
              <option key={key} value={key}>
                {CLAIM_TYPE_LABEL[key]}
              </option>
            ))}
          </SearchSelect>
          <DateRangeFilter label="Registradas" from={from} to={to} onChange={(range) => filter(() => urlFilters.set(range))} />
          <ClearFilters active={filtered} onClear={() => filter(() => urlFilters.clear())} />
        </div>

        {state === 'loading' && <LoadingState />}
        {state === 'error' && <ErrorState message={errorMessage} onRetry={load} />}
        {state === 'ready' && rows.length === 0 && (
          <EmptyState
            icon={BookOpenCheck}
            title={filtered ? 'Sin hojas con estos filtros' : 'No hay reclamos ni quejas por responder'}
            text={filtered ? 'Prueba con otra búsqueda o cambia el estado.' : 'El formulario público está enlazado al pie del catálogo de la academia.'}
          />
        )}
        {state === 'ready' && rows.length > 0 && (
          <Table>
            <THead>
              <Th>Hoja</Th>
              <Th>Consumidor</Th>
              <Th>Sobre</Th>
              <Th>Registrado</Th>
              <Th>Responder antes del</Th>
              <Th>Estado</Th>
            </THead>
            <TBody>
              {rows.map((claim) => (
                <Tr key={claim.id} className="cursor-pointer" onClick={() => setOpenId(claim.id)}>
                  <Td className="whitespace-nowrap">
                    <button
                      type="button"
                      className="font-mono text-xs font-semibold text-slate-900 hover:text-brand-700 cursor-pointer"
                      onClick={(event) => {
                        event.stopPropagation();
                        setOpenId(claim.id);
                      }}
                    >
                      {claim.code}
                    </button>
                    <p className="text-xs text-slate-500">{CLAIM_TYPE_LABEL[claim.type]}</p>
                  </Td>
                  <Td className="whitespace-nowrap">
                    <p className="text-slate-800">{claim.consumer.name}</p>
                    <p className="text-xs text-slate-500">
                      {claim.consumer.doc_type} {claim.consumer.doc_number}
                    </p>
                  </Td>
                  <Td className="min-w-[180px] max-w-[280px] text-slate-700 truncate">{claim.good_description}</Td>
                  <Td className="whitespace-nowrap text-slate-700">{formatDay(claim.created_at)}</Td>
                  <Td className="whitespace-nowrap text-slate-700">{claim.answered_at ? '—' : formatDay(claim.due_at)}</Td>
                  <Td>
                    <ClaimStatusBadge status={claim.status} />
                    {claim.overdue && (
                      <p className="mt-1">
                        <Badge tone="danger">Fuera de plazo</Badge>
                      </p>
                    )}
                  </Td>
                </Tr>
              ))}
            </TBody>
          </Table>
        )}
        {state === 'ready' && meta && <Pagination page={meta.page} lastPage={meta.last_page} total={meta.total} noun="hojas" onPage={setPage} />}
      </Card>

      {openId && <ClaimModal claimId={openId} canManage={canManage} onClose={() => setOpenId(null)} onChanged={load} />}
    </div>
  );
};
