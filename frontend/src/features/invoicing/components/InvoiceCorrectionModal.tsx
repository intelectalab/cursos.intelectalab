import React, { useState } from 'react';
import { apiErrorMessage } from '../../../shared/api/client';
import { Alert, Button, Field, INPUT_CLASS, Modal } from '../../../shared/ui';
import { formatDay } from '../../enrollments/api';
import { invoicingApi, type Invoice } from '../api';

export type Correction = 'void' | 'credit';

/**
 * Corregir un comprobante aceptado: dentro del plazo de SUNAT se anula;
 * pasado el plazo, se emite una nota de crédito por el total. Ninguna de
 * las dos devuelve el dinero (para eso está «Reembolsar» en la orden).
 */
export const InvoiceCorrectionModal: React.FC<{
  invoice: Invoice;
  mode: Correction;
  onClose: () => void;
  onDone: (invoice: Invoice) => void | Promise<void>;
}> = ({ invoice, mode, onClose, onDone }) => {
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const voiding = mode === 'void';

  const submit = async () => {
    setBusy(true);
    setError(null);
    try {
      const result = voiding ? await invoicingApi.void(invoice.id, reason.trim()) : await invoicingApi.creditNote(invoice.id, reason.trim());
      await onDone(result);
    } catch (err) {
      setError(apiErrorMessage(err, voiding ? 'No se pudo anular el comprobante.' : 'No se pudo emitir la nota de crédito.'));
      setBusy(false);
    }
  };

  return (
    <Modal
      title={voiding ? `Anular ${invoice.code}` : `Nota de crédito para ${invoice.code}`}
      subtitle={
        voiding
          ? `Se puede anular hasta el ${invoice.void_deadline ? formatDay(invoice.void_deadline) : 'plazo de SUNAT'}. SUNAT confirma la anulación al final del día.`
          : 'Corrige el comprobante por el total. Sirve cuando ya pasó el plazo para anularlo.'
      }
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant={voiding ? 'danger' : 'primary'} processing={busy} disabled={reason.trim().length < 5} onClick={() => void submit()}>
            {voiding ? 'Anular comprobante' : 'Emitir nota de crédito'}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <p className="text-[13px] text-slate-600">
          No devuelve el dinero ni cambia la matrícula: para devolver una compra usa «Reembolsar» en la orden, que emite su nota de crédito sola.
          {voiding && ' Después de anularlo podrás emitir otro comprobante con los datos corregidos.'}
        </p>
        <Field label={voiding ? 'Motivo de la anulación' : 'Motivo (figura en la nota de crédito)'} hint="Entre 5 y 100 caracteres.">
          <input
            className={INPUT_CLASS}
            maxLength={100}
            value={reason}
            placeholder="Por ejemplo: Error en el nombre del cliente"
            onChange={(event) => setReason(event.target.value)}
          />
        </Field>
        {error && <Alert>{error}</Alert>}
      </div>
    </Modal>
  );
};
