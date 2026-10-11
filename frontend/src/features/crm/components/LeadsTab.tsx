import React, { useCallback, useEffect, useState } from 'react';
import { ArrowRightCircle, CircleCheckBig, FileSpreadsheet, Inbox, Pencil, PhoneCall, Plus, Search, Sparkles, UserPlus, XCircle } from 'lucide-react';
import { apiErrorMessage } from '../../../shared/api/client';
import {
  Avatar,
  Badge,
  Button,
  Card,
  EmptyState,
  ErrorState,
  INPUT_CLASS,
  LoadingState,
  Pagination,
  Table,
  TBody,
  Td,
  Th,
  THead,
  Tr,
} from '../../../shared/ui';
import { FilterCards, HelpTip, type FilterCard } from '../../../shared/ui/filters';
import { ImportModal } from '../../../shared/ui/ImportModal';
import type { PageMeta } from '../../identity/api';
import { crmApi, formatDay, LEAD_SOURCE_LABEL, LEAD_STATUS_LABEL, LEAD_STATUS_TONE, type Lead, type Person } from '../api';
import { ConvertLeadModal, DiscardLeadModal, LeadFormModal } from './LeadDialogs';
import { ExportButton } from '../../../shared/ui/ExportButton';
import { SearchSelect } from '../../../shared/ui/SearchSelect';

type Dialog = { kind: 'create' } | { kind: 'import' } | { kind: 'edit' | 'convert' | 'discard'; lead: Lead };

export const LeadsTab: React.FC<{ canManage: boolean; owners: Person[] }> = ({ canManage, owners }) => {
  const [rows, setRows] = useState<Lead[]>([]);
  const [meta, setMeta] = useState<PageMeta | null>(null);
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('OPEN');
  const [page, setPage] = useState(1);
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [errorMessage, setErrorMessage] = useState('');
  const [dialog, setDialog] = useState<Dialog | null>(null);

  const load = useCallback(async () => {
    try {
      const result = await crmApi.leads({ search: search || undefined, status: status || undefined, page });
      setRows(result.data);
      setMeta(result.meta);
      setState('ready');
    } catch (err) {
      setErrorMessage(apiErrorMessage(err, 'Verifica tu conexión e inténtalo nuevamente.'));
      setState('error');
    }
  }, [search, status, page]);

  useEffect(() => {
    const timer = setTimeout(load, 250);
    return () => clearTimeout(timer);
  }, [load]);

  const filter = (apply: () => void) => {
    apply();
    setPage(1);
  };
  const done = async () => {
    await load();
    setDialog(null);
  };
  const open = (lead: Lead) => lead.status === 'NEW' || lead.status === 'CONTACTED';

  const counts = meta?.counts ?? {};
  const cards: FilterCard[] = [
    { key: '', label: 'Todos', value: counts.ALL ?? null, icon: Inbox, tone: 'brand', help: 'Todos los prospectos registrados que coinciden con la búsqueda.' },
    { key: 'NEW', label: 'Nuevos', value: counts.NEW ?? null, icon: Sparkles, tone: 'info', help: 'Recién registrados: nadie los ha contactado todavía.' },
    { key: 'CONTACTED', label: 'Contactados', value: counts.CONTACTED ?? null, icon: PhoneCall, tone: 'warning', help: 'Ya se habló con ellos, pero aún no son una oportunidad de venta.' },
    { key: 'CONVERTED', label: 'Convertidos', value: counts.CONVERTED ?? null, icon: CircleCheckBig, tone: 'success', help: 'Pasaron a ser una empresa con su contacto y, si se eligió, una oportunidad.' },
    { key: 'DISCARDED', label: 'Descartados', value: counts.DISCARDED ?? null, icon: XCircle, tone: 'neutral', help: 'Se cerraron sin venta, con el motivo anotado.' },
  ];

  return (
    <div>
      <FilterCards cards={cards} active={status} label="Prospectos por estado" onSelect={(key) => filter(() => setStatus(key))} />

      <Card>
        <div className="flex flex-wrap items-center gap-3 px-5 py-4 border-b border-slate-200/80">
          <div className="relative flex-1 min-w-[200px] max-w-sm">
            <Search className="w-4 h-4 text-slate-500 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" />
            <input
              className={`${INPUT_CLASS} pl-9`}
              placeholder="Buscar por nombre, empresa o correo"
              aria-label="Buscar prospecto"
              value={search}
              onChange={(event) => filter(() => setSearch(event.target.value))}
            />
          </div>
          <SearchSelect className={`${INPUT_CLASS} !w-44`} aria-label="Filtrar por estado" value={status} onChange={(event) => filter(() => setStatus(event.target.value))}>
            <option value="OPEN">Por trabajar</option>
            <option value="CONVERTED">Convertidos</option>
            <option value="DISCARDED">Descartados</option>
            <option value="">Todos</option>
          </SearchSelect>
          <HelpTip
            text="Por trabajar reúne nuevos y contactados: los que todavía esperan una acción tuya. Las tarjetas de arriba separan cada estado."
            label="Qué significa el estado de un prospecto"
          />
          <div className="ml-auto flex flex-wrap gap-2">
            <ExportButton path="/crm/leads/export" params={{ search: search || undefined, status: status || undefined }} filename="prospectos.xlsx" label="Exportar" />
            {canManage && (
              <>
                <Button icon={<FileSpreadsheet className="w-4 h-4" />} onClick={() => setDialog({ kind: 'import' })}>
                  Importar
                </Button>
                <Button variant="primary" icon={<Plus className="w-4 h-4" />} onClick={() => setDialog({ kind: 'create' })}>
                  Nuevo prospecto
                </Button>
              </>
            )}
          </div>
        </div>

        {state === 'loading' && <LoadingState />}
        {state === 'error' && <ErrorState message={errorMessage} onRetry={load} />}
        {state === 'ready' && rows.length === 0 && (
          <EmptyState
            icon={UserPlus}
            title={search !== '' || status !== 'OPEN' ? 'Sin resultados con estos filtros' : 'No hay prospectos por trabajar'}
            text={
              search !== '' || status !== 'OPEN'
                ? 'Prueba con otra búsqueda o cambia el estado.'
                : 'Registra a cada persona interesada. Cuando haya un negocio a la vista, conviértela en empresa y oportunidad.'
            }
          />
        )}
        {state === 'ready' && rows.length > 0 && (
          <Table>
            <THead>
              <Th>Prospecto</Th>
              <Th>Contacto</Th>
              <Th>Origen</Th>
              <Th>Estado</Th>
              <Th>A cargo de</Th>
              <Th />
            </THead>
            <TBody>
              {rows.map((lead) => (
                <Tr key={lead.id}>
                  <Td>
                    <div className="flex items-center gap-3">
                      <Avatar name={lead.name} />
                      <div className="min-w-0">
                        <p className="font-semibold text-slate-900 whitespace-nowrap">{lead.name}</p>
                        <p className="text-xs text-slate-500">{[lead.company_name, lead.position].filter(Boolean).join(' · ') || '—'}</p>
                      </div>
                    </div>
                  </Td>
                  <Td className="whitespace-nowrap">
                    <p className="text-slate-700">{lead.email ?? '—'}</p>
                    <p className="text-xs text-slate-500">{lead.phone}</p>
                  </Td>
                  <Td className="whitespace-nowrap">
                    <p className="text-slate-700">{LEAD_SOURCE_LABEL[lead.source]}</p>
                    <p className="text-xs text-slate-500">{formatDay(lead.created_at)}</p>
                  </Td>
                  <Td>
                    <Badge tone={LEAD_STATUS_TONE[lead.status]} dot>
                      {LEAD_STATUS_LABEL[lead.status]}
                    </Badge>
                    {lead.status === 'CONVERTED' && lead.company && <p className="text-xs text-slate-500 mt-1">En {lead.company.name}</p>}
                    {lead.discard_reason && <p className="text-xs text-slate-500 mt-1 max-w-[200px]">{lead.discard_reason}</p>}
                  </Td>
                  <Td className="text-slate-700 whitespace-nowrap">{lead.owner?.name ?? '—'}</Td>
                  <Td>
                    {canManage && open(lead) && (
                      <div className="flex justify-end gap-2">
                        <Button size="sm" variant="ghost" aria-label={`Editar a ${lead.name}`} onClick={() => setDialog({ kind: 'edit', lead })}>
                          <Pencil className="w-3.5 h-3.5" />
                        </Button>
                        <Button size="sm" variant="primary" icon={<ArrowRightCircle className="w-3.5 h-3.5" />} onClick={() => setDialog({ kind: 'convert', lead })}>
                          Convertir
                        </Button>
                        <Button size="sm" variant="danger" icon={<XCircle className="w-3.5 h-3.5" />} onClick={() => setDialog({ kind: 'discard', lead })}>
                          Descartar
                        </Button>
                      </div>
                    )}
                  </Td>
                </Tr>
              ))}
            </TBody>
          </Table>
        )}
        {state === 'ready' && meta && <Pagination page={meta.page} lastPage={meta.last_page} total={meta.total} noun="prospectos" onPage={setPage} />}
      </Card>

      {dialog?.kind === 'create' && <LeadFormModal owners={owners} onClose={() => setDialog(null)} onSaved={done} />}
      {dialog?.kind === 'import' && (
        <ImportModal
          title="Importar prospectos"
          intro="Para cargar una lista de un evento o de un formulario externo. Quedan como nuevos y a tu cargo; un correo que ya está por trabajar no se duplica."
          basePath="/crm/leads/import"
          templateName="plantilla-prospectos.xlsx"
          onClose={() => setDialog(null)}
          onImported={load}
        />
      )}
      {dialog?.kind === 'edit' && <LeadFormModal lead={dialog.lead} owners={owners} onClose={() => setDialog(null)} onSaved={done} />}
      {dialog?.kind === 'convert' && <ConvertLeadModal lead={dialog.lead} onClose={() => setDialog(null)} onConverted={done} />}
      {dialog?.kind === 'discard' && <DiscardLeadModal lead={dialog.lead} onClose={() => setDialog(null)} onDiscarded={done} />}
    </div>
  );
};
