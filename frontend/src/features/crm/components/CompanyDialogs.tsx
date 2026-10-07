import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Globe, KeyRound, Mail, MapPin, Pencil, Phone, Plus, Star, Trash2 } from 'lucide-react';
import { apiErrorMessage } from '../../../shared/api/client';
import { Alert, Avatar, Badge, Button, CHECKBOX_CLASS, Field, INPUT_CLASS, LoadingState, Modal } from '../../../shared/ui';
import {
  blankToNull,
  COMPANY_STATUS_LABEL,
  COMPANY_STATUS_TONE,
  crmApi,
  STAGE_LABEL,
  STAGE_TONE,
  type Company,
  type CompanyDetail,
  type Contact,
  type Person,
} from '../api';
import { OpportunityFormModal, OpportunityModal } from './OpportunityDialogs';

export const CompanyFormModal: React.FC<{
  company?: Company;
  onClose: () => void;
  onSaved: (company: CompanyDetail) => void;
}> = ({ company, onClose, onSaved }) => {
  const [form, setForm] = useState({
    name: company?.name ?? '',
    tax_id: company?.tax_id ?? '',
    industry: company?.industry ?? '',
    website: company?.website ?? '',
    email: company?.email ?? '',
    phone: company?.phone ?? '',
    address: company?.address ?? '',
    city: company?.city ?? '',
    notes: company?.notes ?? '',
  });
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
      name: form.name.trim(),
      tax_id: blankToNull(form.tax_id),
      industry: blankToNull(form.industry),
      website: blankToNull(form.website),
      email: blankToNull(form.email),
      phone: blankToNull(form.phone),
      address: blankToNull(form.address),
      city: blankToNull(form.city),
      notes: blankToNull(form.notes),
    };
    try {
      onSaved(company ? await crmApi.updateCompany(company.id, input) : await crmApi.createCompany(input));
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo guardar la empresa.'));
      setProcessing(false);
    }
  };

  return (
    <Modal
      title={company ? 'Editar empresa' : 'Nueva empresa'}
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="company-form" processing={processing}>
            {company ? 'Guardar cambios' : 'Crear empresa'}
          </Button>
        </>
      }
    >
      <form id="company-form" onSubmit={submit} className="space-y-4">
        {error && <Alert>{error}</Alert>}
        <Field label="Razón social o nombre">
          <input className={INPUT_CLASS} required maxLength={200} autoFocus {...bind('name')} />
        </Field>
        <div className="grid sm:grid-cols-2 gap-4">
          <Field label="RUC" hint="Opcional. No se puede repetir.">
            <input className={INPUT_CLASS} maxLength={20} inputMode="numeric" {...bind('tax_id')} />
          </Field>
          <Field label="Rubro">
            <input className={INPUT_CLASS} maxLength={100} placeholder="Minería, banca, retail…" {...bind('industry')} />
          </Field>
          <Field label="Correo">
            <input className={INPUT_CLASS} type="email" maxLength={150} {...bind('email')} />
          </Field>
          <Field label="Teléfono">
            <input className={INPUT_CLASS} maxLength={30} {...bind('phone')} />
          </Field>
          <Field label="Sitio web">
            <input className={INPUT_CLASS} maxLength={200} {...bind('website')} />
          </Field>
          <Field label="Ciudad">
            <input className={INPUT_CLASS} maxLength={100} {...bind('city')} />
          </Field>
        </div>
        <Field label="Dirección">
          <input className={INPUT_CLASS} maxLength={250} {...bind('address')} />
        </Field>
        <Field label="Notas">
          <textarea className={INPUT_CLASS} rows={3} maxLength={5000} {...bind('notes')} />
        </Field>
      </form>
    </Modal>
  );
};

const ContactFormModal: React.FC<{
  companyId: string;
  contact?: Contact;
  onClose: () => void;
  onSaved: (company: CompanyDetail) => void;
}> = ({ companyId, contact, onClose, onSaved }) => {
  const [form, setForm] = useState({
    first_name: contact?.first_name ?? '',
    last_name: contact?.last_name ?? '',
    position: contact?.position ?? '',
    email: contact?.email ?? '',
    phone: contact?.phone ?? '',
  });
  const [primary, setPrimary] = useState(contact?.is_primary ?? false);
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const bind = (key: keyof typeof form) => ({
    value: form[key],
    onChange: (event: React.ChangeEvent<HTMLInputElement>) => setForm((current) => ({ ...current, [key]: event.target.value })),
  });

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setProcessing(true);
    setError(null);
    const input = {
      first_name: form.first_name.trim(),
      last_name: blankToNull(form.last_name),
      position: blankToNull(form.position),
      email: blankToNull(form.email),
      phone: blankToNull(form.phone),
      is_primary: primary,
    };
    try {
      onSaved(contact ? await crmApi.updateContact(contact.id, input) : await crmApi.createContact(companyId, input));
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo guardar el contacto.'));
      setProcessing(false);
    }
  };

  return (
    <Modal
      title={contact ? 'Editar contacto' : 'Nuevo contacto'}
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="contact-form" processing={processing}>
            Guardar
          </Button>
        </>
      }
    >
      <form id="contact-form" onSubmit={submit} className="space-y-4">
        {error && <Alert>{error}</Alert>}
        <div className="grid sm:grid-cols-2 gap-4">
          <Field label="Nombres">
            <input className={INPUT_CLASS} required maxLength={100} autoFocus {...bind('first_name')} />
          </Field>
          <Field label="Apellidos">
            <input className={INPUT_CLASS} maxLength={100} {...bind('last_name')} />
          </Field>
          <Field label="Cargo">
            <input className={INPUT_CLASS} maxLength={120} {...bind('position')} />
          </Field>
          <Field label="Teléfono">
            <input className={INPUT_CLASS} maxLength={30} {...bind('phone')} />
          </Field>
        </div>
        <Field label="Correo">
          <input className={INPUT_CLASS} type="email" maxLength={150} {...bind('email')} />
        </Field>
        <label className="flex items-center gap-2.5 text-[13px] text-slate-700 cursor-pointer">
          <input type="checkbox" className={CHECKBOX_CLASS} checked={primary} onChange={(event) => setPrimary(event.target.checked)} />
          Es el contacto principal de la empresa
        </label>
      </form>
    </Modal>
  );
};

type Layer =
  | { kind: 'edit' }
  | { kind: 'contact'; contact?: Contact }
  | { kind: 'opportunity-new' }
  | { kind: 'opportunity'; id: string };

// Ficha de la empresa: datos, contactos y oportunidades
export const CompanyModal: React.FC<{
  companyId: string;
  canManage: boolean;
  // Puede abrir la cuenta corporativa: licencias y personas con acceso
  canSeeAccount: boolean;
  owners: Person[];
  onClose: () => void;
  onChanged: () => void;
}> = ({ companyId, canManage, canSeeAccount, owners, onClose, onChanged }) => {
  const [company, setCompany] = useState<CompanyDetail | null>(null);
  const [layer, setLayer] = useState<Layer | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const reload = () =>
    crmApi
      .company(companyId)
      .then(setCompany)
      .catch((err) => setError(apiErrorMessage(err, 'No se pudo cargar la empresa.')));

  useEffect(() => {
    crmApi
      .company(companyId)
      .then(setCompany)
      .catch((err) => setError(apiErrorMessage(err, 'No se pudo cargar la empresa.')));
  }, [companyId]);

  const saved = (detail: CompanyDetail) => {
    setCompany(detail);
    setLayer(null);
    onChanged();
  };

  const removeContact = async (contact: Contact) => {
    setBusy(contact.id);
    setError(null);
    try {
      saved(await crmApi.deleteContact(contact.id));
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo eliminar el contacto.'));
    } finally {
      setBusy(null);
    }
  };

  if (company && layer?.kind === 'edit') {
    return <CompanyFormModal company={company} onClose={() => setLayer(null)} onSaved={saved} />;
  }
  if (company && layer?.kind === 'contact') {
    return <ContactFormModal companyId={company.id} contact={layer.contact} onClose={() => setLayer(null)} onSaved={saved} />;
  }
  if (company && layer?.kind === 'opportunity-new') {
    return (
      <OpportunityFormModal
        company={{ id: company.id, name: company.name }}
        owners={owners}
        onClose={() => setLayer(null)}
        onSaved={() => {
          setLayer(null);
          onChanged();
          void reload();
        }}
      />
    );
  }
  if (company && layer?.kind === 'opportunity') {
    return (
      <OpportunityModal
        opportunityId={layer.id}
        canManage={canManage}
        owners={owners}
        onClose={() => {
          setLayer(null);
          void reload();
        }}
        onChanged={onChanged}
      />
    );
  }

  const facts = company
    ? [
        { icon: Mail, value: company.email },
        { icon: Phone, value: company.phone },
        { icon: Globe, value: company.website },
        { icon: MapPin, value: [company.address, company.city].filter(Boolean).join(', ') },
      ].filter((fact) => fact.value)
    : [];

  return (
    <Modal
      title={company?.name ?? 'Empresa'}
      subtitle={company ? [company.tax_id && `RUC ${company.tax_id}`, company.industry].filter(Boolean).join(' · ') || undefined : undefined}
      onClose={onClose}
      footer={
        <>
          {canSeeAccount && company && (
            <Link
              to={`/crm/accounts/${company.id}`}
              className="inline-flex items-center justify-center gap-2 h-9 px-3.5 rounded-lg text-[13px] font-semibold bg-white text-slate-700 border border-slate-300 hover:bg-slate-50 hover:border-slate-400 shadow-xs mr-auto"
            >
              <KeyRound className="w-3.5 h-3.5" />
              Licencias y portal
            </Link>
          )}
          {canManage && company && (
            <Button icon={<Pencil className="w-3.5 h-3.5" />} onClick={() => setLayer({ kind: 'edit' })}>
              Editar
            </Button>
          )}
          <Button onClick={onClose}>Cerrar</Button>
        </>
      }
    >
      <div className="space-y-5">
        {error && <Alert>{error}</Alert>}
        {!company && !error && <LoadingState />}
        {company && (
          <>
            <div className="flex flex-wrap items-center gap-2">
              <Badge tone={COMPANY_STATUS_TONE[company.status]} dot>
                {COMPANY_STATUS_LABEL[company.status]}
              </Badge>
              {company.owner && <span className="text-xs text-slate-500">A cargo de {company.owner.name}</span>}
            </div>
            {facts.length > 0 && (
              <ul className="space-y-1.5 text-[13px] text-slate-700">
                {facts.map(({ icon: Icon, value }) => (
                  <li key={value} className="flex items-center gap-2">
                    <Icon className="w-3.5 h-3.5 text-slate-500 shrink-0" />
                    <span className="break-all">{value}</span>
                  </li>
                ))}
              </ul>
            )}
            {company.notes && <p className="text-[13px] text-slate-600 whitespace-pre-line">{company.notes}</p>}

            <section>
              <div className="flex items-center justify-between mb-2">
                <p className="text-xs font-semibold text-slate-500">Contactos ({company.contacts.length})</p>
                {canManage && (
                  <Button size="sm" icon={<Plus className="w-3.5 h-3.5" />} onClick={() => setLayer({ kind: 'contact' })}>
                    Agregar
                  </Button>
                )}
              </div>
              {company.contacts.length === 0 ? (
                <p className="text-[13px] text-slate-500">Sin contactos todavía.</p>
              ) : (
                <ul className="rounded-xl border border-slate-200 divide-y divide-slate-100">
                  {company.contacts.map((contact) => (
                    <li key={contact.id} className="flex items-center gap-3 px-3.5 py-2.5">
                      <Avatar name={contact.name} size="sm" />
                      <div className="min-w-0 flex-1">
                        <p className="text-[13px] font-semibold text-slate-900 flex items-center gap-1.5">
                          <span className="truncate">{contact.name}</span>
                          {contact.is_primary && (
                            <span title="Contacto principal" className="inline-flex">
                              <Star className="w-3.5 h-3.5 text-amber-500 fill-amber-400 shrink-0" aria-label="Contacto principal" />
                            </span>
                          )}
                        </p>
                        <p className="text-xs text-slate-500 truncate">{[contact.position, contact.email, contact.phone].filter(Boolean).join(' · ') || 'Sin datos de contacto'}</p>
                      </div>
                      {canManage && (
                        <div className="flex gap-1">
                          <Button size="sm" variant="ghost" aria-label={`Editar a ${contact.name}`} onClick={() => setLayer({ kind: 'contact', contact })}>
                            <Pencil className="w-3.5 h-3.5" />
                          </Button>
                          <Button size="sm" variant="ghost" aria-label={`Eliminar a ${contact.name}`} processing={busy === contact.id} onClick={() => removeContact(contact)}>
                            {busy !== contact.id && <Trash2 className="w-3.5 h-3.5" />}
                          </Button>
                        </div>
                      )}
                    </li>
                  ))}
                </ul>
              )}
            </section>

            <section>
              <div className="flex items-center justify-between mb-2">
                <p className="text-xs font-semibold text-slate-500">Oportunidades ({company.opportunities.length})</p>
                {canManage && (
                  <Button size="sm" icon={<Plus className="w-3.5 h-3.5" />} onClick={() => setLayer({ kind: 'opportunity-new' })}>
                    Nueva
                  </Button>
                )}
              </div>
              {company.opportunities.length === 0 ? (
                <p className="text-[13px] text-slate-500">Sin oportunidades todavía.</p>
              ) : (
                <ul className="rounded-xl border border-slate-200 divide-y divide-slate-100">
                  {company.opportunities.map((opportunity) => (
                    <li key={opportunity.id}>
                      <button
                        type="button"
                        onClick={() => setLayer({ kind: 'opportunity', id: opportunity.id })}
                        className="w-full flex items-center justify-between gap-3 px-3.5 py-2.5 text-left hover:bg-brand-50/40 cursor-pointer transition-colors"
                      >
                        <span className="text-[13px] font-medium text-slate-900 truncate">{opportunity.title}</span>
                        <Badge tone={STAGE_TONE[opportunity.stage]} dot>
                          {STAGE_LABEL[opportunity.stage]}
                        </Badge>
                      </button>
                    </li>
                  ))}
                </ul>
              )}
            </section>
          </>
        )}
      </div>
    </Modal>
  );
};
