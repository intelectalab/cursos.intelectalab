import React, { useCallback, useEffect, useState } from 'react';
import { CalendarClock, Plus, Search, Target } from 'lucide-react';
import { apiErrorMessage } from '../../../shared/api/client';
import { Avatar, Button, Card, EmptyState, ErrorState, INPUT_CLASS, LoadingState } from '../../../shared/ui';
import { formatMoney } from '../../sales/api';
import { crmApi, formatDay, STAGE_BAR, type BoardColumn, type Opportunity, type Person } from '../api';
import { OpportunityFormModal, OpportunityModal } from './OpportunityDialogs';
import { SearchSelect } from '../../../shared/ui/SearchSelect';

const OpportunityCard: React.FC<{ opportunity: Opportunity; currency: string; onOpen: () => void }> = ({ opportunity, currency, onOpen }) => (
  <button
    type="button"
    onClick={onOpen}
    className="w-full text-left bg-white border border-slate-200/80 rounded-xl p-3 shadow-xs hover:shadow-raised hover:border-brand-200 hover:-translate-y-px transition-all cursor-pointer"
  >
    <p className="text-[13px] font-semibold text-slate-900 leading-snug">{opportunity.title}</p>
    <p className="text-xs text-slate-500 mt-0.5 truncate">{opportunity.company.name}</p>
    <div className="flex items-center justify-between gap-2 mt-3">
      <span className="text-[13px] font-bold text-slate-900 tnum">
        {opportunity.estimated_amount ? formatMoney(opportunity.estimated_amount, currency) : <span className="font-normal text-slate-500">Sin monto</span>}
      </span>
      {opportunity.owner && <Avatar name={opportunity.owner.name} size="sm" />}
    </div>
    {opportunity.expected_close_date && !opportunity.closed_at && (
      <p className="flex items-center gap-1 text-[11px] text-slate-500 mt-2">
        <CalendarClock className="w-3 h-3" />
        Cierre: {formatDay(opportunity.expected_close_date)}
      </p>
    )}
  </button>
);

// Embudo comercial: una columna por etapa
export const OpportunitiesTab: React.FC<{ canManage: boolean; owners: Person[] }> = ({ canManage, owners }) => {
  const [columns, setColumns] = useState<BoardColumn[]>([]);
  const [currency, setCurrency] = useState('PEN');
  const [search, setSearch] = useState('');
  const [ownerId, setOwnerId] = useState('');
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [errorMessage, setErrorMessage] = useState('');
  const [openId, setOpenId] = useState<string | null>(null);
  const [creating, setCreating] = useState(false);

  const load = useCallback(async () => {
    try {
      const board = await crmApi.board({ search: search || undefined, owner_id: ownerId || undefined });
      setColumns(board.columns);
      setCurrency(board.currency);
      setState('ready');
    } catch (err) {
      setErrorMessage(apiErrorMessage(err, 'Verifica tu conexión e inténtalo nuevamente.'));
      setState('error');
    }
  }, [search, ownerId]);

  useEffect(() => {
    const timer = setTimeout(load, 250);
    return () => clearTimeout(timer);
  }, [load]);

  const total = columns.reduce((sum, column) => sum + column.count, 0);
  const filtered = search !== '' || ownerId !== '';

  return (
    <div>
      <div className="flex flex-wrap items-center gap-3 mb-4">
        <div className="relative flex-1 min-w-[200px] max-w-sm">
          <Search className="w-4 h-4 text-slate-500 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" />
          <input
            className={`${INPUT_CLASS} pl-9`}
            placeholder="Buscar por oportunidad o empresa"
            aria-label="Buscar oportunidad"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
          />
        </div>
        <SearchSelect className={`${INPUT_CLASS} !w-52`} aria-label="Filtrar por responsable" value={ownerId} onChange={(event) => setOwnerId(event.target.value)}>
          <option value="">Todo el equipo</option>
          {owners.map((owner) => (
            <option key={owner.id} value={owner.id}>
              {owner.name}
            </option>
          ))}
        </SearchSelect>
        {canManage && (
          <Button variant="primary" className="ml-auto" icon={<Plus className="w-4 h-4" />} onClick={() => setCreating(true)}>
            Nueva oportunidad
          </Button>
        )}
      </div>

      {state === 'loading' && (
        <Card>
          <LoadingState />
        </Card>
      )}
      {state === 'error' && (
        <Card>
          <ErrorState message={errorMessage} onRetry={load} />
        </Card>
      )}
      {state === 'ready' && total === 0 && (
        <Card>
          <EmptyState
            icon={Target}
            title={filtered ? 'Sin oportunidades con estos filtros' : 'Aún no hay oportunidades'}
            text={
              filtered
                ? 'Prueba con otra búsqueda o cambia el responsable.'
                : 'Una oportunidad es un posible negocio con una empresa. Créala aquí o al convertir un prospecto.'
            }
          />
        </Card>
      )}
      {state === 'ready' && total > 0 && (
        <div className="flex gap-3 overflow-x-auto pb-3 -mx-1 px-1">
          {columns.map((column) => (
            <section key={column.stage} aria-label={column.label} className="w-[268px] shrink-0 rounded-2xl bg-slate-100/70 border border-slate-200/70 flex flex-col">
              <div className={`h-1.5 rounded-t-2xl bg-gradient-to-r ${STAGE_BAR[column.stage]}`} />
              <header className="px-3.5 pt-3 pb-2.5">
                <div className="flex items-center justify-between gap-2">
                  <h3 className="text-[13px] font-semibold text-slate-900">{column.label}</h3>
                  <span className="min-w-6 h-6 px-1.5 rounded-full bg-white border border-slate-200 text-xs font-semibold text-slate-600 inline-flex items-center justify-center tnum">
                    {column.count}
                  </span>
                </div>
                <p className="text-xs text-slate-500 mt-0.5 tnum">{formatMoney(column.amount, currency)}</p>
              </header>
              <div className="px-2.5 pb-2.5 space-y-2 min-h-[72px]">
                {column.items.map((opportunity) => (
                  <OpportunityCard key={opportunity.id} opportunity={opportunity} currency={currency} onOpen={() => setOpenId(opportunity.id)} />
                ))}
                {column.count === 0 && <p className="text-xs text-slate-500 text-center py-5">Sin oportunidades</p>}
                {column.count > column.items.length && (
                  <p className="text-[11px] text-slate-500 text-center pt-1">
                    Se muestran las {column.items.length} más recientes de {column.count}.
                  </p>
                )}
              </div>
            </section>
          ))}
        </div>
      )}

      {creating && (
        <OpportunityFormModal
          owners={owners}
          onClose={() => setCreating(false)}
          onSaved={(saved) => {
            setCreating(false);
            setOpenId(saved.id);
            void load();
          }}
        />
      )}
      {openId && <OpportunityModal opportunityId={openId} canManage={canManage} owners={owners} onClose={() => setOpenId(null)} onChanged={load} />}
    </div>
  );
};
