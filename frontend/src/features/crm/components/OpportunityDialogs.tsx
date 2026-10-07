import React, { useEffect, useState } from 'react';
import { ArrowRightLeft, Building2, CalendarClock, Mail, MessageSquareText, Pencil, Phone, StickyNote, UserRound, Users, type LucideIcon } from 'lucide-react';
import { apiErrorMessage } from '../../../shared/api/client';
import { Alert, Badge, Button, Field, INPUT_CLASS, LoadingState, Modal } from '../../../shared/ui';
import { formatMoney } from '../../sales/api';
import {
  ACTIVITY_LABEL,
  ACTIVITY_TYPES,
  blankToNull,
  crmApi,
  formatDay,
  formatMoment,
  STAGE_LABEL,
  STAGES,
  type ActivityType,
  type Company,
  type Contact,
  type Opportunity,
  type OpportunityDetail,
  type Person,
  type Stage,
} from '../api';
import { CompanySelect } from './CompanySelect';

const ACTIVITY_ICON: Record<ActivityType, LucideIcon> = {
  CALL: Phone,
  MEETING: Users,
  EMAIL: Mail,
  NOTE: StickyNote,
  STAGE: ArrowRightLeft,
};

// Alta y edición de una oportunidad. Con `company` fija, la empresa no se elige.
export const OpportunityFormModal: React.FC<{
  opportunity?: Opportunity;
  company?: Person;
  owners: Person[];
  onClose: () => void;
  onSaved: (opportunity: OpportunityDetail) => void;
}> = ({ opportunity, company, owners, onClose, onSaved }) => {
  const [companies, setCompanies] = useState<Company[] | null>(null);
  const [contacts, setContacts] = useState<Contact[]>([]);
  const [companyId, setCompanyId] = useState(opportunity?.company.id ?? company?.id ?? '');
  const [contactId, setContactId] = useState(opportunity?.contact?.id ?? '');
  const [title, setTitle] = useState(opportunity?.title ?? '');
  const [amount, setAmount] = useState(opportunity?.estimated_amount ?? '');
  const [closeDate, setCloseDate] = useState(opportunity?.expected_close_date ?? '');
  const [ownerId, setOwnerId] = useState(opportunity?.owner?.id ?? '');
  const [notes, setNotes] = useState(opportunity?.notes ?? '');
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const fixedCompany = opportunity?.company ?? company;

  useEffect(() => {
    if (fixedCompany) return;
    crmApi
      .companies({ per_page: 100 })
      .then((result) => setCompanies(result.data))
      .catch(() => setCompanies([]));
  }, [fixedCompany]);

  useEffect(() => {
    if (!companyId) return;
    let active = true;
    crmApi
      .company(companyId)
      .then((detail) => active && setContacts(detail.contacts))
      .catch(() => active && setContacts([]));
    return () => {
      active = false;
    };
  }, [companyId]);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setProcessing(true);
    setError(null);
    const input = {
      company_id: companyId,
      contact_id: contactId || null,
      title: title.trim(),
      estimated_amount: amount === '' ? null : Number(amount),
      expected_close_date: closeDate || null,
      notes: blankToNull(notes),
      // Sin elegir a nadie, una oportunidad nueva queda a cargo de quien la crea
      ...(opportunity ? { owner_id: ownerId || null } : ownerId ? { owner_id: ownerId } : {}),
    };
    try {
      onSaved(opportunity ? await crmApi.updateOpportunity(opportunity.id, input) : await crmApi.createOpportunity(input));
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo guardar la oportunidad.'));
      setProcessing(false);
    }
  };

  const noCompanies = !fixedCompany && companies?.length === 0;

  return (
    <Modal
      title={opportunity ? 'Editar oportunidad' : 'Nueva oportunidad'}
      subtitle={fixedCompany?.name}
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="opportunity-form" processing={processing} disabled={noCompanies}>
            {opportunity ? 'Guardar cambios' : 'Crear oportunidad'}
          </Button>
        </>
      }
    >
      <form id="opportunity-form" onSubmit={submit} className="space-y-4">
        {error && <Alert>{error}</Alert>}
        {noCompanies && <Alert tone="info">Primero registra la empresa en la pestaña Empresas: toda oportunidad pertenece a una.</Alert>}
        {!fixedCompany && (
          <Field label="Empresa">
            <CompanySelect
              companies={companies}
              value={companyId}
              onChange={(id) => {
                setCompanyId(id);
                setContactId('');
                setContacts([]);
              }}
            />
          </Field>
        )}
        <Field label="Qué se quiere vender" hint="Ejemplo: 40 licencias de Power BI para el área de finanzas.">
          <input className={INPUT_CLASS} required maxLength={200} autoFocus value={title} onChange={(event) => setTitle(event.target.value)} />
        </Field>
        <div className="grid sm:grid-cols-2 gap-4">
          <Field label="Monto estimado" hint="Opcional. El importe real lo fijará la cotización.">
            <input className={INPUT_CLASS} type="number" min={0} step="0.01" value={amount} onChange={(event) => setAmount(event.target.value)} />
          </Field>
          <Field label="Cierre previsto">
            <input className={INPUT_CLASS} type="date" value={closeDate} onChange={(event) => setCloseDate(event.target.value)} />
          </Field>
        </div>
        <div className="grid sm:grid-cols-2 gap-4">
          <Field label="Contacto">
            <select className={INPUT_CLASS} value={contactId} disabled={!companyId} onChange={(event) => setContactId(event.target.value)}>
              <option value="">Sin contacto</option>
              {contacts.map((contact) => (
                <option key={contact.id} value={contact.id}>
                  {contact.name}
                </option>
              ))}
            </select>
          </Field>
          <Field label="A cargo de">
            <select className={INPUT_CLASS} value={ownerId} onChange={(event) => setOwnerId(event.target.value)}>
              <option value="">{opportunity ? 'Nadie' : 'Yo'}</option>
              {owners.map((owner) => (
                <option key={owner.id} value={owner.id}>
                  {owner.name}
                </option>
              ))}
            </select>
          </Field>
        </div>
        <Field label="Notas">
          <textarea className={INPUT_CLASS} rows={3} maxLength={5000} value={notes} onChange={(event) => setNotes(event.target.value)} />
        </Field>
      </form>
    </Modal>
  );
};

// Detalle de una oportunidad: etapa, datos y seguimiento
export const OpportunityModal: React.FC<{
  opportunityId: string;
  canManage: boolean;
  owners: Person[];
  onClose: () => void;
  onChanged: () => void;
}> = ({ opportunityId, canManage, owners, onClose, onChanged }) => {
  const [opportunity, setOpportunity] = useState<OpportunityDetail | null>(null);
  const [editing, setEditing] = useState(false);
  const [losing, setLosing] = useState(false);
  const [lostReason, setLostReason] = useState('');
  const [activityType, setActivityType] = useState<ActivityType>('CALL');
  const [activityNotes, setActivityNotes] = useState('');
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    crmApi
      .opportunity(opportunityId)
      .then(setOpportunity)
      .catch((err) => setError(apiErrorMessage(err, 'No se pudo cargar la oportunidad.')));
  }, [opportunityId]);

  const run = async (key: string, action: () => Promise<OpportunityDetail>, fallback: string) => {
    setBusy(key);
    setError(null);
    try {
      setOpportunity(await action());
      onChanged();
      return true;
    } catch (err) {
      setError(apiErrorMessage(err, fallback));
      return false;
    } finally {
      setBusy(null);
    }
  };

  const move = (stage: Stage) => {
    if (stage === 'LOST') {
      setLosing(true);
      return;
    }
    setLosing(false);
    void run(stage, () => crmApi.moveOpportunity(opportunityId, stage), 'No se pudo cambiar la etapa.');
  };

  const confirmLost = async (event: React.FormEvent) => {
    event.preventDefault();
    if (await run('LOST', () => crmApi.moveOpportunity(opportunityId, 'LOST', lostReason.trim()), 'No se pudo cambiar la etapa.')) {
      setLosing(false);
      setLostReason('');
    }
  };

  const addActivity = async (event: React.FormEvent) => {
    event.preventDefault();
    if (await run('activity', () => crmApi.addActivity(opportunityId, { type: activityType, notes: activityNotes.trim() }), 'No se pudo registrar el seguimiento.')) {
      setActivityNotes('');
    }
  };

  if (editing && opportunity) {
    return (
      <OpportunityFormModal
        opportunity={opportunity}
        owners={owners}
        onClose={() => setEditing(false)}
        onSaved={(saved) => {
          setOpportunity(saved);
          setEditing(false);
          onChanged();
        }}
      />
    );
  }

  return (
    <Modal
      title={opportunity?.title ?? 'Oportunidad'}
      subtitle={opportunity?.company.name}
      onClose={onClose}
      footer={
        <>
          {canManage && opportunity && (
            <Button icon={<Pencil className="w-3.5 h-3.5" />} onClick={() => setEditing(true)}>
              Editar
            </Button>
          )}
          <Button onClick={onClose}>Cerrar</Button>
        </>
      }
    >
      <div className="space-y-5">
        {error && <Alert>{error}</Alert>}
        {!opportunity && !error && <LoadingState />}
        {opportunity && (
          <>
            <div>
              <p className="text-xs font-semibold text-slate-500 mb-2">Etapa</p>
              <div className="flex flex-wrap gap-1.5">
                {STAGES.map((stage) => {
                  const current = opportunity.stage === stage;
                  return (
                    <button
                      key={stage}
                      type="button"
                      disabled={!canManage || current || busy !== null}
                      aria-pressed={current}
                      onClick={() => move(stage)}
                      className={`h-8 px-3 rounded-full text-xs font-semibold transition-all ${
                        current
                          ? 'text-white bg-gradient-to-r from-brand-700 to-sky-500 shadow-sm shadow-brand-700/30'
                          : 'text-slate-600 bg-slate-100 hover:bg-slate-200 cursor-pointer disabled:cursor-default disabled:hover:bg-slate-100 disabled:opacity-70'
                      }`}
                    >
                      {STAGE_LABEL[stage]}
                    </button>
                  );
                })}
              </div>
              {opportunity.stage === 'LOST' && opportunity.lost_reason && (
                <p className="text-xs text-slate-500 mt-2">Motivo: {opportunity.lost_reason}</p>
              )}
            </div>

            {losing && (
              <form onSubmit={confirmLost} className="rounded-xl border border-rose-200 bg-rose-50/60 p-3.5 space-y-3">
                <Field label="¿Por qué se perdió?" hint="Ejemplo: eligió a otro proveedor, no tiene presupuesto este año.">
                  <textarea className={INPUT_CLASS} rows={2} required minLength={3} maxLength={500} autoFocus value={lostReason} onChange={(event) => setLostReason(event.target.value)} />
                </Field>
                <div className="flex justify-end gap-2">
                  <Button size="sm" onClick={() => setLosing(false)}>
                    Cancelar
                  </Button>
                  <Button size="sm" variant="danger" type="submit" processing={busy === 'LOST'}>
                    Marcar como perdida
                  </Button>
                </div>
              </form>
            )}

            <dl className="grid grid-cols-2 gap-x-4 gap-y-3 text-[13px]">
              <div>
                <dt className="text-xs text-slate-500">Monto estimado</dt>
                <dd className="font-semibold text-slate-900 tnum">
                  {opportunity.estimated_amount ? formatMoney(opportunity.estimated_amount, opportunity.currency) : '—'}
                </dd>
              </div>
              <div>
                <dt className="text-xs text-slate-500">Cierre previsto</dt>
                <dd className="text-slate-800 flex items-center gap-1.5">
                  <CalendarClock className="w-3.5 h-3.5 text-slate-500" />
                  {formatDay(opportunity.expected_close_date)}
                </dd>
              </div>
              <div>
                <dt className="text-xs text-slate-500">Contacto</dt>
                <dd className="text-slate-800 flex items-center gap-1.5">
                  <UserRound className="w-3.5 h-3.5 text-slate-500" />
                  {opportunity.contact?.name ?? '—'}
                </dd>
              </div>
              <div>
                <dt className="text-xs text-slate-500">A cargo de</dt>
                <dd className="text-slate-800 flex items-center gap-1.5">
                  <Building2 className="w-3.5 h-3.5 text-slate-500" />
                  {opportunity.owner?.name ?? 'Nadie'}
                </dd>
              </div>
              {opportunity.notes && (
                <div className="col-span-2">
                  <dt className="text-xs text-slate-500">Notas</dt>
                  <dd className="text-slate-700 whitespace-pre-line">{opportunity.notes}</dd>
                </div>
              )}
            </dl>

            <div>
              <p className="text-xs font-semibold text-slate-500 mb-2">Seguimiento</p>
              {canManage && (
                <form onSubmit={addActivity} className="rounded-xl border border-slate-200 bg-slate-50/60 p-3 mb-3 space-y-2.5">
                  <div className="flex flex-wrap gap-1.5">
                    {ACTIVITY_TYPES.map((type) => (
                      <button
                        key={type}
                        type="button"
                        aria-pressed={activityType === type}
                        onClick={() => setActivityType(type)}
                        className={`h-7 px-2.5 rounded-md text-xs font-medium cursor-pointer transition-colors ${
                          activityType === type ? 'bg-brand-600 text-white' : 'bg-white text-slate-600 border border-slate-200 hover:border-slate-300'
                        }`}
                      >
                        {ACTIVITY_LABEL[type]}
                      </button>
                    ))}
                  </div>
                  <textarea
                    className={INPUT_CLASS}
                    rows={2}
                    required
                    maxLength={5000}
                    placeholder="¿Qué pasó? Ejemplo: pidió la propuesta para 40 personas."
                    aria-label="Detalle del seguimiento"
                    value={activityNotes}
                    onChange={(event) => setActivityNotes(event.target.value)}
                  />
                  <div className="flex justify-end">
                    <Button size="sm" variant="primary" type="submit" icon={<MessageSquareText className="w-3.5 h-3.5" />} processing={busy === 'activity'}>
                      Registrar
                    </Button>
                  </div>
                </form>
              )}
              {opportunity.activities.length === 0 ? (
                <p className="text-[13px] text-slate-500">Todavía no hay seguimiento registrado.</p>
              ) : (
                <ol className="space-y-3">
                  {opportunity.activities.map((activity) => {
                    const Icon = ACTIVITY_ICON[activity.type];
                    return (
                      <li key={activity.id} className="flex gap-3">
                        <span className="w-7 h-7 shrink-0 rounded-full bg-brand-50 text-brand-600 flex items-center justify-center">
                          <Icon className="w-3.5 h-3.5" />
                        </span>
                        <div className="min-w-0">
                          <p className="text-xs text-slate-500">
                            <Badge tone={activity.type === 'STAGE' ? 'brand' : 'neutral'}>{ACTIVITY_LABEL[activity.type]}</Badge>{' '}
                            {formatMoment(activity.occurred_at)}
                            {activity.author && ` · ${activity.author}`}
                          </p>
                          <p className="text-[13px] text-slate-800 mt-1 whitespace-pre-line">{activity.notes}</p>
                        </div>
                      </li>
                    );
                  })}
                </ol>
              )}
            </div>
          </>
        )}
      </div>
    </Modal>
  );
};
