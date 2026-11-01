import React from 'react';
import { Field, INPUT_CLASS } from '../../../shared/ui';
import { BOLETA_ID_THRESHOLD, type BillingInput } from '../api';

const DOC_TYPES = [
  { value: 'DNI', label: 'DNI' },
  { value: 'CE', label: 'Carné de extranjería' },
  { value: 'PASAPORTE', label: 'Pasaporte' },
];

/**
 * Boleta o factura, con los datos de cada una. Sin tocar nada queda una
 * boleta con los datos de la cuenta del comprador.
 */
export const BillingFields: React.FC<{
  value: BillingInput;
  onChange: (value: BillingInput) => void;
  // Total en soles: desde S/ 700 la boleta pide documento
  total: number;
  currency: string;
  // Hay un documento en la cuenta del comprador (la boleta puede usarlo)
  accountDocument?: string | null;
  errors?: Record<string, string>;
  // Lo llena el personal (venta asistida o corrección): los datos vacíos salen de la cuenta del alumno
  forStudent?: boolean;
}> = ({ value, onChange, total, currency, accountDocument, errors = {}, forStudent = false }) => {
  const account = forStudent ? 'el de la cuenta del alumno' : 'el de tu cuenta';
  const set = (changes: Partial<BillingInput>) => onChange({ ...value, ...changes });
  const needsDocument = currency !== 'PEN' || total >= BOLETA_ID_THRESHOLD;
  const error = (key: string) => errors[`billing.${key}`] && <p className="text-xs text-rose-700 mt-1">{errors[`billing.${key}`]}</p>;

  return (
    <fieldset className="space-y-3">
      <legend className="text-[13px] font-semibold text-slate-900 mb-2">Comprobante</legend>
      <div className="flex flex-wrap gap-2" role="radiogroup" aria-label="Tipo de comprobante">
        {(['BOLETA', 'FACTURA'] as const).map((type) => (
          <button
            key={type}
            type="button"
            role="radio"
            aria-checked={value.type === type}
            onClick={() => set({ type })}
            className={`h-9 px-4 rounded-lg text-[13px] font-semibold border transition-colors cursor-pointer ${
              value.type === type ? 'bg-brand-600 border-brand-600 text-white' : 'bg-white border-slate-300 text-slate-700 hover:border-slate-400'
            }`}
          >
            {type === 'BOLETA' ? 'Boleta' : 'Factura'}
          </button>
        ))}
      </div>

      {value.type === 'BOLETA' ? (
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <Field label="Documento" hint={accountDocument ? `Vacío: ${account} (${accountDocument}).` : needsDocument ? `Obligatorio para este importe (vacío: ${account}).` : 'Opcional.'}>
            <div className="flex gap-2">
              <div className="w-32 shrink-0">
                <select className={INPUT_CLASS} value={value.doc_type ?? 'DNI'} onChange={(event) => set({ doc_type: event.target.value })} aria-label="Tipo de documento">
                  {DOC_TYPES.map((doc) => (
                    <option key={doc.value} value={doc.value}>
                      {doc.label}
                    </option>
                  ))}
                </select>
              </div>
              <input
                className={INPUT_CLASS}
                value={value.doc_number ?? ''}
                maxLength={12}
                inputMode={(value.doc_type ?? 'DNI') === 'DNI' ? 'numeric' : 'text'}
                onChange={(event) => set({ doc_number: event.target.value, doc_type: value.doc_type ?? 'DNI' })}
                aria-label="Número de documento"
              />
            </div>
            {error('doc_number')}
          </Field>
          <Field label="Nombre" hint={`Vacío: ${account}.`}>
            <input className={INPUT_CLASS} value={value.name ?? ''} maxLength={200} onChange={(event) => set({ name: event.target.value })} />
          </Field>
        </div>
      ) : (
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <Field label="RUC">
            <input className={INPUT_CLASS} value={value.ruc ?? ''} maxLength={11} inputMode="numeric" onChange={(event) => set({ ruc: event.target.value })} />
            {error('ruc')}
          </Field>
          <Field label="Razón social">
            <input className={INPUT_CLASS} value={value.name ?? ''} maxLength={200} onChange={(event) => set({ name: event.target.value })} />
            {error('name')}
          </Field>
          <div className="sm:col-span-2">
            <Field label="Dirección fiscal">
              <input className={INPUT_CLASS} value={value.address ?? ''} maxLength={250} onChange={(event) => set({ address: event.target.value })} />
              {error('address')}
            </Field>
          </div>
        </div>
      )}
    </fieldset>
  );
};
