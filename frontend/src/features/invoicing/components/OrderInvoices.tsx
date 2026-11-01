import React, { useCallback, useEffect, useState } from 'react';
import { FileText, Pencil, RotateCcw } from 'lucide-react';
import { apiErrorMessage } from '../../../shared/api/client';
import { Alert, Badge, Button, Modal } from '../../../shared/ui';
import { formatMoney } from '../../sales/api';
import { billingPayload, INVOICE_STATUS, invoicingApi, type Billing, type BillingInput, type Invoice } from '../api';
import { BillingFields } from './BillingFields';

const toInput = (billing: Billing | null): BillingInput =>
  billing?.type === 'FACTURA'
    ? { type: 'FACTURA', ruc: billing.doc_number, name: billing.name, address: billing.address }
    : { type: 'BOLETA', doc_type: billing?.doc_type ?? 'DNI', doc_number: billing?.doc_number ?? '', name: billing?.name ?? '' };

/**
 * Los comprobantes de una orden. El personal ve el motivo de un rechazo,
 * corrige los datos del comprobante y reintenta; el comprador los descarga.
 */
// Mientras un comprobante sigue en camino, la lista se vuelve a pedir cada 5 segundos (hasta un minuto)
const POLL_MS = 5000;
const POLL_TIMES = 12;

export const OrderInvoices: React.FC<{
  orderId: string;
  staff: boolean;
  canFix?: boolean;
  total?: number;
  currency?: string;
  // La orden ya tiene un pago: su comprobante puede estar saliendo aunque la lista aún esté vacía
  awaiting?: boolean;
}> = ({ orderId, staff, canFix = false, total = 0, currency = 'PEN', awaiting = false }) => {
  const [invoices, setInvoices] = useState<Invoice[] | null>(null);
  const [billing, setBilling] = useState<Billing | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [editing, setEditing] = useState<BillingInput | null>(null);
  const [formErrors, setFormErrors] = useState<Record<string, string>>({});
  const [polls, setPolls] = useState(0);

  // El personal recibe también los datos del comprobante guardados en la orden
  const fetchInvoices = useCallback(
    async () => (staff ? invoicingApi.orderInvoices(orderId) : { invoices: await invoicingApi.myInvoices(orderId), billing: null }),
    [orderId, staff],
  );

  const load = async () => {
    const result = await fetchInvoices();
    setInvoices(result.invoices);
    setBilling(result.billing);
  };

  useEffect(() => {
    let active = true;
    fetchInvoices()
      .then((result) => {
        if (!active) return;
        setInvoices(result.invoices);
        setBilling(result.billing);
      })
      .catch((err) => active && setError(apiErrorMessage(err, 'No se pudieron leer los comprobantes.')));
    return () => {
      active = false;
    };
  }, [fetchInvoices]);

  const inFlight = invoices !== null && (invoices.some((invoice) => invoice.status === 'PENDING' || invoice.status === 'PROCESSING') || (awaiting && invoices.length === 0));
  useEffect(() => {
    if (!inFlight || polls >= POLL_TIMES) return;
    const timer = window.setTimeout(() => {
      fetchInvoices()
        .then((result) => {
          setInvoices(result.invoices);
          setBilling(result.billing);
        })
        .catch(() => undefined)
        .finally(() => setPolls((count) => count + 1));
    }, POLL_MS);
    return () => window.clearTimeout(timer);
  }, [inFlight, polls, fetchInvoices]);

  const run = async (key: string, action: () => Promise<void>, fallback: string) => {
    setBusy(key);
    setError(null);
    try {
      await action();
    } catch (err) {
      setError(apiErrorMessage(err, fallback));
    } finally {
      setBusy(null);
    }
  };

  const saveBilling = async () => {
    if (!editing) return;
    setBusy('billing');
    setFormErrors({});
    try {
      setBilling(await invoicingApi.updateBilling(orderId, billingPayload(editing)));
      setEditing(null);
    } catch (err) {
      const details = (err as { response?: { data?: { details?: Record<string, string[]> } } }).response?.data?.details;
      if (details) setFormErrors(Object.fromEntries(Object.entries(details).map(([key, messages]) => [key, messages[0]])));
      else setError(apiErrorMessage(err, 'No se pudieron guardar los datos del comprobante.'));
    } finally {
      setBusy(null);
    }
  };

  // Sin comprobantes (la academia no los emite, o aún no hay pagos): no se muestra nada
  if (invoices !== null && invoices.length === 0 && !billing) return null;

  return (
    <section className="space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h3 className="text-[13px] font-semibold text-slate-900">Comprobantes</h3>
        {staff && canFix && (
          <Button size="sm" variant="ghost" icon={<Pencil className="w-4 h-4" />} onClick={() => setEditing(toInput(billing))}>
            Datos del comprobante
          </Button>
        )}
      </div>
      {staff && billing && (
        <p className="text-xs text-slate-500">
          {billing.type === 'FACTURA' ? 'Factura' : 'Boleta'} a nombre de {billing.name}
          {billing.doc_number ? ` (${billing.doc_type === 'RUC' ? 'RUC' : billing.doc_type} ${billing.doc_number})` : ' (sin documento)'}.
        </p>
      )}
      {error && <Alert>{error}</Alert>}
      {invoices?.length === 0 && <p className="text-xs text-slate-500">El comprobante se emite cuando se confirma el pago.</p>}
      <ul className="divide-y divide-slate-200/80 rounded-xl border border-slate-200/80">
        {invoices?.map((invoice) => (
          <li key={invoice.id} className="flex flex-wrap items-center gap-x-3 gap-y-1.5 px-3.5 py-2.5 text-[13px]">
            <span className="font-semibold text-slate-900 tnum">
              {invoice.type === 'FACTURA' ? 'Factura' : 'Boleta'} {invoice.code}
            </span>
            <Badge tone={INVOICE_STATUS[invoice.status].tone} dot>
              {INVOICE_STATUS[invoice.status].label}
            </Badge>
            <span className="text-slate-500 tnum">{formatMoney(invoice.total, invoice.currency)}</span>
            <span className="flex-1" />
            {invoice.has_pdf && (
              <Button
                size="sm"
                icon={<FileText className="w-4 h-4" />}
                processing={busy === `pdf-${invoice.id}`}
                disabled={busy !== null}
                onClick={() => void run(`pdf-${invoice.id}`, () => (staff ? invoicingApi.openPdf(invoice.id) : invoicingApi.openMyPdf(invoice.id)), 'No se pudo abrir el PDF.')}
              >
                PDF
              </Button>
            )}
            {staff && canFix && invoice.can_retry && (
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
                Reintentar
              </Button>
            )}
            {staff && invoice.message && invoice.status !== 'ACCEPTED' && <p className="basis-full text-xs text-slate-600">{invoice.message}</p>}
          </li>
        ))}
      </ul>

      {editing && (
        <Modal
          title="Datos del comprobante"
          subtitle="Se usan en el próximo comprobante y al reintentar uno que no salió."
          onClose={() => setEditing(null)}
          footer={
            <>
              <Button onClick={() => setEditing(null)}>Cancelar</Button>
              <Button variant="primary" processing={busy === 'billing'} onClick={() => void saveBilling()}>
                Guardar
              </Button>
            </>
          }
        >
          <BillingFields value={editing} onChange={setEditing} total={total} currency={currency} errors={formErrors} forStudent />
        </Modal>
      )}
    </section>
  );
};
