import React, { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Ban, CircleAlert, CircleCheckBig, FileMinus, FileText, Hourglass, Receipt, RotateCcw, Search } from 'lucide-react';
import { useAuth } from '../../auth/AuthContext';
import { apiErrorMessage } from '../../../shared/api/client';
import { Alert, Badge, Button, Card, EmptyState, ErrorState, INPUT_CLASS, LoadingState, PageHeader, Pagination } from '../../../shared/ui';
import { FilterCards, HelpTip, type FilterCard } from '../../../shared/ui/filters';
import { ExportButton } from '../../../shared/ui/ExportButton';
import { SearchSelect } from '../../../shared/ui/SearchSelect';
import { DateRangeFilter } from '../../../shared/ui/listFilters';
import { useUrlFilters } from '../../../shared/filters/useUrlFilters';
import type { PageMeta } from '../../identity/api';
import { formatDay } from '../../enrollments/api';
import { formatMoney } from '../../sales/api';
import { INVOICE_STATUS, INVOICE_TYPE, invoicingApi, type Invoice, type InvoiceType } from '../api';
import { InvoiceCorrectionModal, type Correction } from '../components/InvoiceCorrectionModal';

const orderCode = (number: number) => `#${String(number).padStart(5, '0')}`;

/**
 * Comercial → Comprobantes: todas las boletas, facturas y notas de crédito de
 * la academia, con su estado en SUNAT. Desde aquí se descargan, se reintentan
 * las que no salieron y se anulan o corrigen las que tienen un error.
 */
export const InvoicesPage: React.FC = () => {
  const { hasPermission } = useAuth();
  const canFix = hasPermission('payments.confirm');

  const [rows, setRows] = useState<Invoice[]>([]);
  const [meta, setMeta] = useState<PageMeta | null>(null);
  const urlFilters = useUrlFilters({ search: '', state: '', type: '', from: '', to: '' });
  const { search, state: stateFilter, type } = urlFilters.values;
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState<'loading' | 'ready' | 'error'>('loading');
  const [errorMessage, setErrorMessage] = useState('');
  const [busy, setBusy] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [correcting, setCorrecting] = useState<{ invoice: Invoice; mode: Correction } | null>(null);

  const load = useCallback(async () => {
    try {
      const result = await invoicingApi.all({ ...urlFilters.query, page });
      setRows(result.data);
      setMeta(result.meta);
      setStatus('ready');
    } catch (err) {
      setErrorMessage(apiErrorMessage(err, 'Verifica tu conexión e inténtalo nuevamente.'));
      setStatus('error');
    }
  }, [urlFilters.query, page]);

  // La búsqueda espera a que el usuario termine de escribir
  useEffect(() => {
    const timer = setTimeout(load, 250);
    return () => clearTimeout(timer);
  }, [load]);

  const filter = (apply: () => void) => {
    apply();
    setPage(1);
  };

  const run = async (key: string, action: () => Promise<unknown>, fallback: string) => {
    setBusy(key);
    setActionError(null);
    try {
      await action();
    } catch (err) {
      setActionError(apiErrorMessage(err, fallback));
    } finally {
      setBusy(null);
    }
  };

  const counts = meta?.counts ?? {};
  const cards: FilterCard[] = [
    { key: '', label: 'Todos', value: counts.ALL ?? null, icon: Receipt, tone: 'brand', help: 'Todos los comprobantes que coinciden con la búsqueda, el tipo y las fechas elegidos.' },
    { key: 'VALID', label: 'Vigentes', value: counts.VALID ?? null, icon: CircleCheckBig, tone: 'success', help: 'Aceptados por SUNAT (también los aceptados con observaciones).' },
    { key: 'IN_PROGRESS', label: 'En camino', value: counts.IN_PROGRESS ?? null, icon: Hourglass, tone: 'info', help: 'Por enviar, esperando la respuesta de SUNAT o con la anulación pedida. Se revisan solos cada 5 minutos.' },
    { key: 'PROBLEM', label: 'Con problema', value: counts.PROBLEM ?? null, icon: CircleAlert, tone: 'danger', help: 'Rechazados por SUNAT o que no se pudieron emitir. Revisa el motivo, corrige los datos en la orden y reintenta.' },
    { key: 'VOIDED', label: 'Anulados', value: counts.VOIDED ?? null, icon: Ban, tone: 'neutral', help: 'Anulados en SUNAT, o que ya no salieron porque el pago se devolvió antes.' },
  ];

  return (
    <div>
      <PageHeader
        title="Comprobantes"
        icon={Receipt}
        subtitle="Boletas, facturas y notas de crédito emitidas por cada pago, con su estado en SUNAT."
        action={<ExportButton path="/invoices/export" params={urlFilters.query} filename="comprobantes.xlsx" />}
      />

      <FilterCards cards={cards} active={stateFilter} label="Comprobantes por situación" onSelect={(key) => filter(() => urlFilters.set({ state: key }))} />

      <Card>
        <div className="flex flex-wrap items-center gap-3 px-5 py-4 border-b border-slate-200/80">
          <div className="relative flex-1 min-w-[200px] max-w-sm">
            <Search className="w-4 h-4 text-slate-500 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" />
            <input
              className={`${INPUT_CLASS} pl-9`}
              placeholder="Número (B001-12), cliente o documento"
              aria-label="Buscar comprobante"
              value={search}
              onChange={(event) => filter(() => urlFilters.set({ search: event.target.value }))}
            />
          </div>
          <div className="flex items-center gap-1.5">
            <SearchSelect className={`${INPUT_CLASS} !w-48`} aria-label="Filtrar por tipo" value={type} onChange={(event) => filter(() => urlFilters.set({ type: event.target.value }))}>
              <option value="">Todos los tipos</option>
              {(Object.keys(INVOICE_TYPE) as InvoiceType[]).map((key) => (
                <option key={key} value={key}>
                  {INVOICE_TYPE[key]}
                </option>
              ))}
            </SearchSelect>
            <HelpTip text="Las notas de crédito corrigen una boleta o factura: salen solas al reembolsar, o desde «Nota de crédito» cuando ya pasó el plazo para anular." label="Qué es cada tipo" />
          </div>
          <DateRangeFilter label="Emitidos" from={urlFilters.values.from} to={urlFilters.values.to} onChange={(range) => filter(() => urlFilters.set(range))} />
        </div>

        {actionError && (
          <div className="px-5 pt-4">
            <Alert>{actionError}</Alert>
          </div>
        )}
        {status === 'loading' && <LoadingState />}
        {status === 'error' && <ErrorState message={errorMessage} onRetry={load} />}
        {status === 'ready' && rows.length === 0 && (
          <EmptyState
            icon={Receipt}
            title={urlFilters.active ? 'Sin resultados con estos filtros' : 'Aún no hay comprobantes'}
            text={
              urlFilters.active
                ? 'Prueba con otra búsqueda o cambia los filtros.'
                : 'Se emiten solos al confirmarse cada pago, cuando la academia activa los comprobantes en Configuración → Comprobantes electrónicos.'
            }
            action={urlFilters.active ? <Button onClick={() => filter(() => urlFilters.clear())}>Quitar filtros</Button> : undefined}
          />
        )}
        {status === 'ready' && rows.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full text-[13px]">
              <thead className="bg-slate-50 text-left text-xs text-slate-500">
                <tr>
                  <th scope="col" className="px-5 py-2.5 font-medium">Comprobante</th>
                  <th scope="col" className="px-3 py-2.5 font-medium">Emitido</th>
                  <th scope="col" className="px-3 py-2.5 font-medium">Cliente</th>
                  <th scope="col" className="px-3 py-2.5 font-medium text-right">Total</th>
                  <th scope="col" className="px-3 py-2.5 font-medium">Estado</th>
                  <th scope="col" className="px-3 py-2.5 font-medium">Orden</th>
                  <th scope="col" className="px-5 py-2.5 font-medium text-right">
                    <span className="sr-only">Acciones</span>
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {rows.map((invoice) => {
                  const note = invoice.type === 'NOTA_CREDITO';
                  return (
                    <tr key={invoice.id} className="align-top">
                      <td className="px-5 py-3">
                        <p className="font-semibold text-slate-900 tnum whitespace-nowrap">{invoice.code}</p>
                        <p className="text-xs text-slate-500">
                          {INVOICE_TYPE[invoice.type]}
                          {invoice.reference_code && ` · corrige ${invoice.reference_code}`}
                          {invoice.credited && ' · con nota de crédito'}
                        </p>
                      </td>
                      <td className="px-3 py-3 whitespace-nowrap text-slate-600">{formatDay(invoice.issue_date)}</td>
                      <td className="px-3 py-3">
                        <p className="text-slate-900">{invoice.customer_name}</p>
                        {invoice.customer_doc_number && invoice.customer_doc_number !== '-' && <p className="text-xs text-slate-500 tnum">{invoice.customer_doc_number}</p>}
                      </td>
                      <td className={`px-3 py-3 text-right tnum whitespace-nowrap font-semibold ${note ? 'text-rose-700' : 'text-slate-900'}`}>
                        {note ? '− ' : ''}
                        {formatMoney(invoice.total, invoice.currency)}
                      </td>
                      <td className="px-3 py-3 max-w-[260px]">
                        <Badge tone={INVOICE_STATUS[invoice.status].tone} dot>
                          {INVOICE_STATUS[invoice.status].label}
                        </Badge>
                        {invoice.message && invoice.status !== 'ACCEPTED' && <p className="mt-1 text-xs text-slate-600">{invoice.message}</p>}
                        {note && invoice.reason && <p className="mt-1 text-xs text-slate-500">Motivo: {invoice.reason}</p>}
                      </td>
                      <td className="px-3 py-3 whitespace-nowrap">
                        {invoice.order_number ? (
                          <Link className="text-brand-700 hover:underline tnum" to={`/billing?order=${invoice.order_id}`}>
                            {orderCode(invoice.order_number)}
                          </Link>
                        ) : (
                          '—'
                        )}
                      </td>
                      <td className="px-5 py-3">
                        <div className="flex flex-wrap justify-end gap-1.5">
                          {invoice.has_pdf && (
                            <Button size="sm" icon={<FileText className="w-4 h-4" />} processing={busy === `pdf-${invoice.id}`} disabled={busy !== null} onClick={() => void run(`pdf-${invoice.id}`, () => invoicingApi.openPdf(invoice.id), 'No se pudo abrir el PDF.')}>
                              PDF
                            </Button>
                          )}
                          {canFix && invoice.can_retry && (
                            <Button
                              size="sm"
                              variant="secondary"
                              icon={<RotateCcw className="w-4 h-4" />}
                              processing={busy === `retry-${invoice.id}`}
                              disabled={busy !== null}
                              onClick={() => void run(`retry-${invoice.id}`, async () => {
                                await invoicingApi.retry(invoice.id);
                                await load();
                              }, 'No se pudo volver a emitir.')}
                            >
                              {invoice.status === 'VOIDED' ? 'Emitir otro' : 'Reintentar'}
                            </Button>
                          )}
                          {canFix && invoice.can_void && (
                            <Button size="sm" variant="ghost" icon={<Ban className="w-4 h-4" />} disabled={busy !== null} onClick={() => setCorrecting({ invoice, mode: 'void' })}>
                              Anular
                            </Button>
                          )}
                          {canFix && invoice.can_credit && !invoice.can_void && (
                            <Button size="sm" variant="ghost" icon={<FileMinus className="w-4 h-4" />} disabled={busy !== null} onClick={() => setCorrecting({ invoice, mode: 'credit' })}>
                              Nota de crédito
                            </Button>
                          )}
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
        {status === 'ready' && meta && <Pagination page={meta.page} lastPage={meta.last_page} total={meta.total} noun="comprobantes" onPage={setPage} />}
      </Card>

      {correcting && (
        <InvoiceCorrectionModal
          invoice={correcting.invoice}
          mode={correcting.mode}
          onClose={() => setCorrecting(null)}
          onDone={async () => {
            setCorrecting(null);
            await load();
          }}
        />
      )}
    </div>
  );
};
