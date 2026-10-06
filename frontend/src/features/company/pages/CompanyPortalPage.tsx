import React, { useCallback, useEffect, useState } from 'react';
import { Building2, CheckCircle2, FileText, XCircle } from 'lucide-react';
import { apiErrorMessage } from '../../../shared/api/client';
import { Alert, Button, Card, CardHeader, Field, INPUT_CLASS, LoadingState, Modal, PageHeader } from '../../../shared/ui';
import { formatDay, quotationCode, type QuotationSummary } from '../../crm/api';
import { QuotationItems, QuotationStatusBadge } from '../../crm/components/QuotationParts';
import { formatMoney, orderCode } from '../../sales/api';
import { portalApi, type Account, type PortalQuotation } from '../api';
import { AccountView } from '../components/AccountView';

// La cotización tal como la ve la empresa, con su respuesta
const QuotationModal: React.FC<{ quotationId: string; onClose: () => void; onChanged: () => void }> = ({ quotationId, onClose, onChanged }) => {
  const [quotation, setQuotation] = useState<PortalQuotation | null>(null);
  const [mode, setMode] = useState<'view' | 'accept' | 'reject'>('view');
  const [reason, setReason] = useState('');
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    portalApi
      .quotation(quotationId)
      .then(setQuotation)
      .catch((err) => setError(apiErrorMessage(err, 'No se pudo cargar la cotización.')));
  }, [quotationId]);

  const run = async (action: () => Promise<PortalQuotation>, fallback: string) => {
    setProcessing(true);
    setError(null);
    try {
      setQuotation(await action());
      setMode('view');
      onChanged();
    } catch (err) {
      setError(apiErrorMessage(err, fallback));
    } finally {
      setProcessing(false);
    }
  };

  const open = quotation?.status === 'SENT';

  return (
    <Modal
      size="wide"
      title={quotation ? `Cotización ${quotationCode(quotation)}` : 'Cotización'}
      subtitle={quotation ? `Vigente hasta el ${formatDay(quotation.valid_until)}` : undefined}
      onClose={onClose}
      footer={
        mode === 'view' ? (
          <>
            <Button onClick={onClose}>Cerrar</Button>
            {open && (
              <>
                <Button variant="danger" icon={<XCircle className="w-3.5 h-3.5" />} onClick={() => setMode('reject')}>
                  Rechazar
                </Button>
                <Button variant="primary" icon={<CheckCircle2 className="w-3.5 h-3.5" />} onClick={() => setMode('accept')}>
                  Aceptar cotización
                </Button>
              </>
            )}
          </>
        ) : (
          <>
            <Button onClick={() => setMode('view')}>Volver</Button>
            {mode === 'accept' && quotation && (
              <Button variant="primary" processing={processing} onClick={() => run(() => portalApi.accept(quotation.id), 'No se pudo aceptar la cotización.')}>
                Confirmar aceptación
              </Button>
            )}
            {mode === 'reject' && (
              <Button variant="danger" type="submit" form="portal-reject-form" processing={processing}>
                Confirmar rechazo
              </Button>
            )}
          </>
        )
      }
    >
      <div className="space-y-5">
        {error && <Alert>{error}</Alert>}
        {!quotation && !error && <LoadingState />}
        {quotation && (
          <>
            <QuotationStatusBadge status={quotation.status} />

            {mode === 'accept' && (
              <Alert tone="info">
                Al aceptar, tu empresa se compromete a la compra por <strong>{formatMoney(quotation.total_amount, quotation.currency)}</strong>. La academia te indicará cómo pagar y, confirmado el
                pago, recibirás las licencias para asignarlas a tus trabajadores.
              </Alert>
            )}
            {mode === 'reject' && (
              <form
                id="portal-reject-form"
                onSubmit={(event) => {
                  event.preventDefault();
                  void run(() => portalApi.reject(quotation.id, reason.trim()), 'No se pudo rechazar la cotización.');
                }}
              >
                <Field label="¿Por qué la rechazas?" hint="La academia lo verá y podrá enviarte otra propuesta.">
                  <textarea className={INPUT_CLASS} rows={3} required minLength={3} maxLength={500} autoFocus value={reason} onChange={(event) => setReason(event.target.value)} />
                </Field>
              </form>
            )}

            {quotation.status === 'ACCEPTED' && (
              <Alert tone="success">
                Aceptada el {formatDay(quotation.accepted_at)}.
                {quotation.order && ` Generó la orden ${orderCode(quotation.order.number)}${quotation.order.status === 'PAID' ? ', ya pagada' : ', pendiente de pago'}.`}
              </Alert>
            )}
            {quotation.status === 'EXPIRED' && <Alert tone="info">Esta cotización venció. Pide a la academia que te envíe una nueva.</Alert>}
            {quotation.status === 'REJECTED' && quotation.reject_reason && <Alert tone="info">Rechazada: {quotation.reject_reason}</Alert>}

            <QuotationItems items={quotation.items} total={quotation.total_amount} currency={quotation.currency} />
            <p className="text-xs text-slate-500">Precios finales. Cada licencia es un cupo para un trabajador en ese curso.</p>

            {quotation.notes && (
              <div>
                <p className="text-xs font-semibold text-slate-500 mb-1">Condiciones</p>
                <p className="text-[13px] text-slate-700 whitespace-pre-line">{quotation.notes}</p>
              </div>
            )}
          </>
        )}
      </div>
    </Modal>
  );
};

// Portal del Responsable de empresa: licencias, trabajadores, compras y cotizaciones de su empresa
export const CompanyPortalPage: React.FC = () => {
  const [company, setCompany] = useState<Account['company'] | null>(null);
  const [quotations, setQuotations] = useState<QuotationSummary[]>([]);
  const [openId, setOpenId] = useState<string | null>(null);
  // Cambia para que la cuenta se vuelva a leer tras responder una cotización
  const [refresh, setRefresh] = useState(0);

  const loadQuotations = useCallback(() => {
    portalApi
      .quotations()
      .then(setQuotations)
      .catch(() => setQuotations([]));
  }, []);

  useEffect(loadQuotations, [loadQuotations]);

  const onLoaded = useCallback((account: Account) => setCompany(account.company), []);

  return (
    <div>
      <PageHeader
        title={company?.name ?? 'Mi empresa'}
        icon={Building2}
        subtitle={company?.tax_id ? `RUC ${company.tax_id} · Licencias, trabajadores y compras de tu empresa.` : 'Licencias, trabajadores y compras de tu empresa.'}
      />

      {quotations.length > 0 && (
        <Card className="mb-6">
          <CardHeader title="Cotizaciones" text="Propuestas que la academia envió a tu empresa." />
          <ul className="divide-y divide-slate-100">
            {quotations.map((quotation) => (
              <li key={quotation.id}>
                <button
                  type="button"
                  onClick={() => setOpenId(quotation.id)}
                  className="w-full flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-left hover:bg-brand-50/40 cursor-pointer transition-colors"
                >
                  <span className="flex items-center gap-3 min-w-0">
                    <span className="w-9 h-9 shrink-0 rounded-xl bg-brand-50 text-brand-600 flex items-center justify-center">
                      <FileText className="w-4 h-4" />
                    </span>
                    <span>
                      <span className="block text-[13px] font-semibold text-slate-900 tnum">{quotationCode(quotation)}</span>
                      <span className="block text-xs text-slate-500">
                        {quotation.status === 'SENT' ? `Responde antes del ${formatDay(quotation.valid_until)}` : `Enviada el ${formatDay(quotation.sent_at)}`}
                      </span>
                    </span>
                  </span>
                  <span className="flex items-center gap-3">
                    <span className="text-[13px] font-semibold text-slate-900 tnum">{formatMoney(quotation.total_amount, quotation.currency)}</span>
                    <QuotationStatusBadge status={quotation.status} />
                  </span>
                </button>
              </li>
            ))}
          </ul>
        </Card>
      )}

      <AccountView key={refresh} base="/company" staff={false} canAssign canAddMembers onLoaded={onLoaded} />

      {openId && (
        <QuotationModal
          quotationId={openId}
          onClose={() => setOpenId(null)}
          onChanged={() => {
            loadQuotations();
            setRefresh((value) => value + 1);
          }}
        />
      )}
    </div>
  );
};
