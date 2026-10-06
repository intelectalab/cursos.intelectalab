import React, { useCallback, useEffect, useState } from 'react';
import { FileText, Plus, Search } from 'lucide-react';
import { useAuth } from '../../auth/AuthContext';
import { apiErrorMessage } from '../../../shared/api/client';
import { Button, Card, EmptyState, ErrorState, INPUT_CLASS, LoadingState, Pagination, Table, TBody, Td, Th, THead, Tr } from '../../../shared/ui';
import type { PageMeta } from '../../identity/api';
import { formatMoney } from '../../sales/api';
import { formatDay, QUOTATION_STATUS_LABEL, quotationCode, quotationsApi, type QuotationStatus, type QuotationSummary } from '../api';
import { QuotationFormModal, QuotationModal } from './QuotationDialogs';
import { QuotationStatusBadge } from './QuotationParts';

export const QuotationsTab: React.FC = () => {
  const { hasPermission } = useAuth();
  const canEdit = hasPermission('quotations.create');

  const [rows, setRows] = useState<QuotationSummary[]>([]);
  const [meta, setMeta] = useState<PageMeta | null>(null);
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [errorMessage, setErrorMessage] = useState('');
  const [openId, setOpenId] = useState<string | null>(null);
  const [creating, setCreating] = useState(false);

  const load = useCallback(async () => {
    try {
      const result = await quotationsApi.list({ search: search || undefined, status: status || undefined, page });
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

  const filtered = search !== '' || status !== '';
  const filter = (apply: () => void) => {
    apply();
    setPage(1);
  };

  return (
    <div>
      <Card>
        <div className="flex flex-wrap items-center gap-3 px-5 py-4 border-b border-slate-200/80">
          <div className="relative flex-1 min-w-[200px] max-w-sm">
            <Search className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" />
            <input
              className={`${INPUT_CLASS} pl-9`}
              placeholder="Buscar por empresa o número"
              aria-label="Buscar cotización"
              value={search}
              onChange={(event) => filter(() => setSearch(event.target.value))}
            />
          </div>
          <select className={`${INPUT_CLASS} !w-44`} aria-label="Filtrar por estado" value={status} onChange={(event) => filter(() => setStatus(event.target.value))}>
            <option value="">Todas</option>
            {(Object.keys(QUOTATION_STATUS_LABEL) as QuotationStatus[]).map((key) => (
              <option key={key} value={key}>
                {QUOTATION_STATUS_LABEL[key]}
              </option>
            ))}
          </select>
          {canEdit && (
            <Button variant="primary" className="ml-auto" icon={<Plus className="w-4 h-4" />} onClick={() => setCreating(true)}>
              Nueva cotización
            </Button>
          )}
        </div>

        {state === 'loading' && <LoadingState />}
        {state === 'error' && <ErrorState message={errorMessage} onRetry={load} />}
        {state === 'ready' && rows.length === 0 && (
          <EmptyState
            icon={FileText}
            title={filtered ? 'Sin resultados con estos filtros' : 'Aún no hay cotizaciones'}
            text={filtered ? 'Prueba con otra búsqueda o cambia el estado.' : 'Una cotización reúne los cursos, las licencias y el precio pactado con una empresa. Al aceptarla nace la venta.'}
          />
        )}
        {state === 'ready' && rows.length > 0 && (
          <Table>
            <THead>
              <Th>Cotización</Th>
              <Th>Empresa</Th>
              <Th className="text-right">Total</Th>
              <Th>Vigente hasta</Th>
              <Th>Estado</Th>
            </THead>
            <TBody>
              {rows.map((quotation) => (
                <Tr key={quotation.id} className="cursor-pointer" onClick={() => setOpenId(quotation.id)}>
                  <Td className="whitespace-nowrap">
                    <button
                      type="button"
                      className="font-semibold text-slate-900 tnum hover:text-brand-700 cursor-pointer"
                      onClick={(event) => {
                        event.stopPropagation();
                        setOpenId(quotation.id);
                      }}
                    >
                      {quotationCode(quotation)}
                    </button>
                    <p className="text-xs text-slate-500">{formatDay(quotation.created_at)}</p>
                  </Td>
                  <Td className="font-medium text-slate-800">{quotation.company.name}</Td>
                  <Td className="text-right font-semibold text-slate-900 tnum whitespace-nowrap">{formatMoney(quotation.total_amount, quotation.currency)}</Td>
                  <Td className="whitespace-nowrap text-slate-700">{formatDay(quotation.valid_until)}</Td>
                  <Td>
                    <QuotationStatusBadge status={quotation.status} />
                  </Td>
                </Tr>
              ))}
            </TBody>
          </Table>
        )}
        {state === 'ready' && meta && <Pagination page={meta.page} lastPage={meta.last_page} total={meta.total} noun="cotizaciones" onPage={setPage} />}
      </Card>

      {creating && (
        <QuotationFormModal
          onClose={() => setCreating(false)}
          onSaved={(quotation) => {
            setCreating(false);
            setOpenId(quotation.id);
            void load();
          }}
        />
      )}
      {openId && (
        <QuotationModal
          quotationId={openId}
          canEdit={canEdit}
          canAnswer={hasPermission('quotations.accept')}
          canSeeOrders={hasPermission(['orders.read', 'orders.support.read'])}
          onClose={() => setOpenId(null)}
          onChanged={load}
        />
      )}
    </div>
  );
};
