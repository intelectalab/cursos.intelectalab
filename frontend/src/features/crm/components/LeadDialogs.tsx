import React, { useEffect, useState } from 'react';
import { apiErrorMessage } from '../../../shared/api/client';
import { Alert, Button, CHECKBOX_CLASS, Field, INPUT_CLASS, Modal } from '../../../shared/ui';
import { blankToNull, crmApi, LEAD_SOURCE_LABEL, type Company, type Lead, type LeadSource, type Person } from '../api';

export const LeadFormModal: React.FC<{
  lead?: Lead;
  owners: Person[];
  onClose: () => void;
  onSaved: () => Promise<void>;
}> = ({ lead, owners, onClose, onSaved }) => {
  const [form, setForm] = useState({
    first_name: lead?.first_name ?? '',
    last_name: lead?.last_name ?? '',
    company_name: lead?.company_name ?? '',
    position: lead?.position ?? '',
    email: lead?.email ?? '',
    phone: lead?.phone ?? '',
    interest: lead?.interest ?? '',
  });
  const [source, setSource] = useState<LeadSource>(lead?.source ?? 'WEB');
  const [status, setStatus] = useState<'NEW' | 'CONTACTED'>(lead?.status === 'CONTACTED' ? 'CONTACTED' : 'NEW');
  const [ownerId, setOwnerId] = useState(lead?.owner?.id ?? '');
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const bind = (key: keyof typeof form) => ({
    value: form[key],
    onChange: (event: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => setForm((current) => ({ ...current, [key]: event.target.value })),
  });

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setProcessing(true);
    setError(null);
    const input = {
      first_name: form.first_name.trim(),
      last_name: blankToNull(form.last_name),
      company_name: blankToNull(form.company_name),
      position: blankToNull(form.position),
      email: blankToNull(form.email),
      phone: blankToNull(form.phone),
      interest: blankToNull(form.interest),
      source,
    };
    try {
      if (lead) {
        await crmApi.updateLead(lead.id, { ...input, status, owner_id: ownerId || null });
      } else {
        // Sin elegir a nadie, queda a cargo de quien lo registra
        await crmApi.createLead(ownerId ? { ...input, owner_id: ownerId } : input);
      }
      await onSaved();
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo guardar el prospecto.'));
      setProcessing(false);
    }
  };

  return (
    <Modal
      title={lead ? 'Editar prospecto' : 'Nuevo prospecto'}
      subtitle="Una persona interesada en capacitar a su empresa."
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="lead-form" processing={processing}>
            {lead ? 'Guardar cambios' : 'Registrar prospecto'}
          </Button>
        </>
      }
    >
      <form id="lead-form" onSubmit={submit} className="space-y-4">
        {error && <Alert>{error}</Alert>}
        <div className="grid sm:grid-cols-2 gap-4">
          <Field label="Nombres">
            <input className={INPUT_CLASS} required maxLength={100} autoFocus {...bind('first_name')} />
          </Field>
          <Field label="Apellidos">
            <input className={INPUT_CLASS} maxLength={100} {...bind('last_name')} />
          </Field>
          <Field label="Empresa">
            <input className={INPUT_CLASS} maxLength={200} {...bind('company_name')} />
          </Field>
          <Field label="Cargo">
            <input className={INPUT_CLASS} maxLength={120} {...bind('position')} />
          </Field>
          <Field label="Correo">
            <input className={INPUT_CLASS} type="email" maxLength={150} {...bind('email')} />
          </Field>
          <Field label="Teléfono" hint="Basta el correo o el teléfono.">
            <input className={INPUT_CLASS} maxLength={30} {...bind('phone')} />
          </Field>
          <Field label="Cómo llegó">
            <select className={INPUT_CLASS} value={source} onChange={(event) => setSource(event.target.value as LeadSource)}>
              {(Object.keys(LEAD_SOURCE_LABEL) as LeadSource[]).map((key) => (
                <option key={key} value={key}>
                  {LEAD_SOURCE_LABEL[key]}
                </option>
              ))}
            </select>
          </Field>
          <Field label="A cargo de">
            <select className={INPUT_CLASS} value={ownerId} onChange={(event) => setOwnerId(event.target.value)}>
              <option value="">{lead ? 'Nadie' : 'Yo'}</option>
              {owners.map((owner) => (
                <option key={owner.id} value={owner.id}>
                  {owner.name}
                </option>
              ))}
            </select>
          </Field>
        </div>
        <Field label="Qué le interesa">
          <textarea className={INPUT_CLASS} rows={3} maxLength={2000} placeholder="Ejemplo: capacitar a 40 personas en Power BI antes de fin de año." {...bind('interest')} />
        </Field>
        {lead && (
          <label className="flex items-center gap-2.5 text-[13px] text-slate-700 cursor-pointer">
            <input type="checkbox" className={CHECKBOX_CLASS} checked={status === 'CONTACTED'} onChange={(event) => setStatus(event.target.checked ? 'CONTACTED' : 'NEW')} />
            Ya se le contactó
          </label>
        )}
      </form>
    </Modal>
  );
};

// Convertir: el prospecto pasa a ser contacto de una empresa y, si hay negocio a la vista, una oportunidad
export const ConvertLeadModal: React.FC<{ lead: Lead; onClose: () => void; onConverted: () => Promise<void> }> = ({ lead, onClose, onConverted }) => {
  const [companies, setCompanies] = useState<Company[]>([]);
  const [mode, setMode] = useState<'new' | 'existing'>('new');
  const [companyId, setCompanyId] = useState('');
  const [name, setName] = useState(lead.company_name ?? '');
  const [taxId, setTaxId] = useState('');
  const [withOpportunity, setWithOpportunity] = useState(true);
  const [title, setTitle] = useState('');
  const [amount, setAmount] = useState('');
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    crmApi
      .companies({ per_page: 100 })
      .then((result) => setCompanies(result.data))
      .catch(() => setCompanies([]));
  }, []);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setProcessing(true);
    setError(null);
    try {
      await crmApi.convertLead(lead.id, {
        ...(mode === 'existing' ? { company_id: companyId } : { company: { name: name.trim(), tax_id: blankToNull(taxId) } }),
        ...(withOpportunity ? { opportunity: { title: title.trim(), estimated_amount: amount === '' ? null : Number(amount) } } : {}),
      });
      await onConverted();
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo convertir el prospecto.'));
      setProcessing(false);
    }
  };

  const option = (value: 'new' | 'existing', label: string) => (
    <button
      type="button"
      aria-pressed={mode === value}
      onClick={() => setMode(value)}
      className={`flex-1 h-8 rounded-md text-xs font-semibold cursor-pointer transition-colors ${mode === value ? 'bg-white text-brand-700 shadow-xs' : 'text-slate-600 hover:text-slate-900'}`}
    >
      {label}
    </button>
  );

  return (
    <Modal
      title="Convertir prospecto"
      subtitle={`${lead.name} pasará a ser contacto de la empresa.`}
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="convert-lead-form" processing={processing}>
            Convertir
          </Button>
        </>
      }
    >
      <form id="convert-lead-form" onSubmit={submit} className="space-y-4">
        {error && <Alert>{error}</Alert>}
        {companies.length > 0 && (
          <div className="flex gap-1 p-1 rounded-lg bg-slate-100">
            {option('new', 'Empresa nueva')}
            {option('existing', 'Empresa ya registrada')}
          </div>
        )}
        {mode === 'new' ? (
          <div className="grid sm:grid-cols-2 gap-4">
            <Field label="Razón social o nombre">
              <input className={INPUT_CLASS} required maxLength={200} autoFocus value={name} onChange={(event) => setName(event.target.value)} />
            </Field>
            <Field label="RUC" hint="Opcional.">
              <input className={INPUT_CLASS} maxLength={20} inputMode="numeric" value={taxId} onChange={(event) => setTaxId(event.target.value)} />
            </Field>
          </div>
        ) : (
          <Field label="Empresa">
            <select className={INPUT_CLASS} required value={companyId} onChange={(event) => setCompanyId(event.target.value)}>
              <option value="">Elige una empresa</option>
              {companies.map((company) => (
                <option key={company.id} value={company.id}>
                  {company.name}
                </option>
              ))}
            </select>
          </Field>
        )}

        <label className="flex items-center gap-2.5 text-[13px] text-slate-700 cursor-pointer">
          <input type="checkbox" className={CHECKBOX_CLASS} checked={withOpportunity} onChange={(event) => setWithOpportunity(event.target.checked)} />
          Crear también una oportunidad
        </label>
        {withOpportunity && (
          <div className="grid sm:grid-cols-[1fr_150px] gap-4">
            <Field label="Qué se quiere vender">
              <input className={INPUT_CLASS} required maxLength={200} placeholder="40 licencias de Power BI" value={title} onChange={(event) => setTitle(event.target.value)} />
            </Field>
            <Field label="Monto estimado">
              <input className={INPUT_CLASS} type="number" min={0} step="0.01" value={amount} onChange={(event) => setAmount(event.target.value)} />
            </Field>
          </div>
        )}
      </form>
    </Modal>
  );
};

export const DiscardLeadModal: React.FC<{ lead: Lead; onClose: () => void; onDiscarded: () => Promise<void> }> = ({ lead, onClose, onDiscarded }) => {
  const [reason, setReason] = useState('');
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setProcessing(true);
    setError(null);
    try {
      await crmApi.discardLead(lead.id, reason.trim());
      await onDiscarded();
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo descartar el prospecto.'));
      setProcessing(false);
    }
  };

  return (
    <Modal
      title="Descartar prospecto"
      subtitle={lead.name}
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="danger" type="submit" form="discard-lead-form" processing={processing}>
            Descartar
          </Button>
        </>
      }
    >
      <form id="discard-lead-form" onSubmit={submit} className="space-y-4">
        {error && <Alert>{error}</Alert>}
        <Field label="Motivo" hint="Ejemplo: no responde, no es el público de la academia, dato duplicado.">
          <textarea className={INPUT_CLASS} rows={3} required minLength={3} maxLength={500} autoFocus value={reason} onChange={(event) => setReason(event.target.value)} />
        </Field>
      </form>
    </Modal>
  );
};
