import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { CheckCircle2, CopyPlus, Pencil, Plus, Send, Trash2, XCircle } from 'lucide-react';
import { apiErrorMessage } from '../../../shared/api/client';
import { Alert, Button, Field, INPUT_CLASS, LoadingState, Modal } from '../../../shared/ui';
import { formatMoney, ORDER_STATUS_LABEL, orderCode } from '../../sales/api';
import {
  blankToNull,
  crmApi,
  formatDay,
  quotationCode,
  quotationsApi,
  type Company,
  type CompanyDetail,
  type Quotation,
  type QuotationOptions,
} from '../api';
import { QuotationItems, QuotationStatusBadge } from './QuotationParts';

interface Line {
  key: number;
  course_id: string;
  quantity: string;
  unit_price: string;
}

const addDays = (days: number) => {
  const date = new Date();
  date.setDate(date.getDate() + days);
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
};

// Alta de una cotización o edición de su borrador
export const QuotationFormModal: React.FC<{
  quotation?: Quotation;
  onClose: () => void;
  onSaved: (quotation: Quotation) => void;
}> = ({ quotation, onClose, onSaved }) => {
  const [options, setOptions] = useState<QuotationOptions | null>(null);
  const [companies, setCompanies] = useState<Company[] | null>(null);
  const [company, setCompany] = useState<CompanyDetail | null>(null);
  const [companyId, setCompanyId] = useState(quotation?.company.id ?? '');
  const [contactId, setContactId] = useState(quotation?.contact?.id ?? '');
  const [opportunityId, setOpportunityId] = useState(quotation?.opportunity?.id ?? '');
  const [validUntil, setValidUntil] = useState(quotation?.valid_until ?? '');
  const [notes, setNotes] = useState(quotation?.notes ?? '');
  const [lines, setLines] = useState<Line[]>(
    quotation?.items.map((item, index) => ({ key: index, course_id: item.course_id, quantity: String(item.quantity), unit_price: item.unit_price })) ?? [
      { key: 0, course_id: '', quantity: '1', unit_price: '' },
    ],
  );
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    quotationsApi
      .options()
      .then((loaded) => {
        setOptions(loaded);
        setValidUntil((current) => current || addDays(loaded.validity_days));
      })
      .catch((err) => setError(apiErrorMessage(err, 'No se pudieron cargar los cursos.')));
    if (!quotation) {
      crmApi
        .companies({ per_page: 100 })
        .then((result) => setCompanies(result.data))
        .catch(() => setCompanies([]));
    }
  }, [quotation]);

  useEffect(() => {
    if (!companyId) return;
    let active = true;
    crmApi
      .company(companyId)
      .then((detail) => active && setCompany(detail))
      .catch(() => active && setCompany(null));
    return () => {
      active = false;
    };
  }, [companyId]);

  const currency = options?.currency ?? 'PEN';
  const course = (id: string) => options?.courses.find((item) => item.id === id);
  const lineTotal = (line: Line) => (Number(line.quantity) || 0) * (Number(line.unit_price) || 0);
  const total = lines.reduce((sum, line) => sum + lineTotal(line), 0);

  const change = (key: number, patch: Partial<Line>) => setLines((current) => current.map((line) => (line.key === key ? { ...line, ...patch } : line)));
  const chooseCourse = (key: number, courseId: string) => {
    const listPrice = course(courseId)?.list_price;
    // Se parte del precio de lista; el vendedor lo ajusta
    change(key, { course_id: courseId, ...(listPrice ? { unit_price: listPrice } : {}) });
  };

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setProcessing(true);
    setError(null);
    const input = {
      contact_id: contactId || null,
      valid_until: validUntil,
      notes: blankToNull(notes),
      items: lines.filter((line) => line.course_id).map((line) => ({ course_id: line.course_id, quantity: Number(line.quantity), unit_price: Number(line.unit_price) })),
    };
    try {
      onSaved(
        quotation
          ? await quotationsApi.update(quotation.id, input)
          : await quotationsApi.create({ ...input, company_id: companyId, opportunity_id: opportunityId || null }),
      );
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo guardar la cotización.'));
      setProcessing(false);
    }
  };

  const openOpportunities = company?.opportunities.filter((item) => item.stage !== 'WON' && item.stage !== 'LOST') ?? [];
  const taken = (line: Line) => lines.filter((other) => other.key !== line.key).map((other) => other.course_id);

  return (
    <Modal
      size="wide"
      title={quotation ? `Editar ${quotationCode(quotation)}` : 'Nueva cotización'}
      subtitle={quotation?.company.name ?? 'Cursos, licencias y precio pactado para una empresa.'}
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="quotation-form" processing={processing} disabled={!options}>
            Guardar borrador
          </Button>
        </>
      }
    >
      <form id="quotation-form" onSubmit={submit} className="space-y-4">
        {error && <Alert>{error}</Alert>}
        {!options && !error && <LoadingState />}
        {options && options.courses.length === 0 && <Alert tone="info">No hay cursos publicados. Publica un curso para poder cotizarlo.</Alert>}
        {options && (
          <>
            <div className="grid sm:grid-cols-2 gap-4">
              {!quotation && (
                <Field label="Empresa">
                  <select
                    className={INPUT_CLASS}
                    required
                    autoFocus
                    value={companyId}
                    onChange={(event) => {
                      setCompanyId(event.target.value);
                      setContactId('');
                      setOpportunityId('');
                      setCompany(null);
                    }}
                  >
                    <option value="">{companies === null ? 'Cargando…' : 'Elige una empresa'}</option>
                    {companies?.map((item) => (
                      <option key={item.id} value={item.id}>
                        {item.name}
                      </option>
                    ))}
                  </select>
                </Field>
              )}
              <Field label="Contacto">
                <select className={INPUT_CLASS} value={contactId} disabled={!company} onChange={(event) => setContactId(event.target.value)}>
                  <option value="">Sin contacto</option>
                  {company?.contacts.map((contact) => (
                    <option key={contact.id} value={contact.id}>
                      {contact.name}
                    </option>
                  ))}
                </select>
              </Field>
              {!quotation && (
                <Field label="Oportunidad" hint="Opcional. Avanzará sola al enviar y al aceptar la cotización.">
                  <select className={INPUT_CLASS} value={opportunityId} disabled={!company} onChange={(event) => setOpportunityId(event.target.value)}>
                    <option value="">Sin oportunidad</option>
                    {openOpportunities.map((opportunity) => (
                      <option key={opportunity.id} value={opportunity.id}>
                        {opportunity.title}
                      </option>
                    ))}
                  </select>
                </Field>
              )}
              <Field label="Vigente hasta" hint="Último día en que el cliente puede aceptarla.">
                <input className={INPUT_CLASS} type="date" required min={addDays(0)} value={validUntil} onChange={(event) => setValidUntil(event.target.value)} />
              </Field>
            </div>

            <div>
              <p className="text-[13px] font-medium text-slate-700 mb-1.5">Cursos</p>
              <div className="space-y-2">
                {lines.map((line) => {
                  const listPrice = course(line.course_id)?.list_price;
                  return (
                    <div key={line.key} className="grid grid-cols-[1fr_auto] sm:grid-cols-[1fr_92px_128px_112px_auto] gap-2 items-start rounded-xl border border-slate-200 bg-slate-50/50 p-2.5">
                      <div className="col-span-2 sm:col-span-1">
                        <select className={INPUT_CLASS} required aria-label="Curso" value={line.course_id} onChange={(event) => chooseCourse(line.key, event.target.value)}>
                          <option value="">Elige un curso</option>
                          {options.courses
                            .filter((item) => !taken(line).includes(item.id))
                            .map((item) => (
                              <option key={item.id} value={item.id}>
                                {item.title}
                              </option>
                            ))}
                        </select>
                        {listPrice && <p className="text-[11px] text-slate-500 mt-1">Precio de lista: {formatMoney(listPrice, currency)}</p>}
                      </div>
                      <input
                        className={INPUT_CLASS}
                        type="number"
                        min={1}
                        max={10000}
                        step={1}
                        required
                        aria-label="Licencias"
                        title="Licencias"
                        placeholder="Licencias"
                        value={line.quantity}
                        onChange={(event) => change(line.key, { quantity: event.target.value })}
                      />
                      <input
                        className={INPUT_CLASS}
                        type="number"
                        min={0}
                        step="0.01"
                        required
                        aria-label="Precio por licencia"
                        title="Precio por licencia"
                        placeholder="Precio c/u"
                        value={line.unit_price}
                        onChange={(event) => change(line.key, { unit_price: event.target.value })}
                      />
                      <p className="hidden sm:flex h-9 items-center justify-end text-[13px] font-semibold text-slate-900 tnum">{formatMoney(lineTotal(line), currency)}</p>
                      <Button
                        variant="ghost"
                        aria-label="Quitar curso"
                        disabled={lines.length === 1}
                        onClick={() => setLines((current) => current.filter((other) => other.key !== line.key))}
                      >
                        <Trash2 className="w-3.5 h-3.5" />
                      </Button>
                    </div>
                  );
                })}
              </div>
              <div className="flex items-center justify-between gap-3 mt-3">
                <Button
                  size="sm"
                  icon={<Plus className="w-3.5 h-3.5" />}
                  disabled={lines.length >= options.courses.length}
                  onClick={() => setLines((current) => [...current, { key: Math.max(...current.map((line) => line.key)) + 1, course_id: '', quantity: '1', unit_price: '' }])}
                >
                  Agregar curso
                </Button>
                <p className="text-[13px] text-slate-600">
                  Total <strong className="text-base text-slate-900 tnum ml-1.5">{formatMoney(total, currency)}</strong>
                </p>
              </div>
              <p className="text-xs text-slate-500 mt-1.5">Precios finales, sin impuestos adicionales. Cada licencia es un cupo para un trabajador en ese curso.</p>
            </div>

            <Field label="Condiciones o notas para el cliente">
              <textarea className={INPUT_CLASS} rows={3} maxLength={5000} placeholder="Forma de pago, plazos, lo que incluye…" value={notes} onChange={(event) => setNotes(event.target.value)} />
            </Field>
          </>
        )}
      </form>
    </Modal>
  );
};

// Detalle de una cotización y las acciones de cada estado
export const QuotationModal: React.FC<{
  quotationId: string;
  canEdit: boolean;
  canAnswer: boolean;
  canSeeOrders: boolean;
  onClose: () => void;
  onChanged: () => void;
}> = ({ quotationId, canEdit, canAnswer, canSeeOrders, onClose, onChanged }) => {
  const [quotation, setQuotation] = useState<Quotation | null>(null);
  const [mode, setMode] = useState<'view' | 'edit' | 'accept' | 'reject'>('view');
  const [text, setText] = useState('');
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    quotationsApi
      .get(quotationId)
      .then(setQuotation)
      .catch((err) => setError(apiErrorMessage(err, 'No se pudo cargar la cotización.')));
  }, [quotationId]);

  const run = async (key: string, action: () => Promise<Quotation>, fallback: string) => {
    setBusy(key);
    setError(null);
    try {
      setQuotation(await action());
      setMode('view');
      setText('');
      onChanged();
    } catch (err) {
      setError(apiErrorMessage(err, fallback));
    } finally {
      setBusy(null);
    }
  };

  if (quotation && mode === 'edit') {
    return (
      <QuotationFormModal
        quotation={quotation}
        onClose={() => setMode('view')}
        onSaved={(saved) => {
          setQuotation(saved);
          setMode('view');
          onChanged();
        }}
      />
    );
  }

  const status = quotation?.status;
  const answering = mode === 'accept' || mode === 'reject';

  return (
    <Modal
      size="wide"
      title={quotation ? `Cotización ${quotationCode(quotation)}` : 'Cotización'}
      subtitle={quotation?.company.name}
      onClose={onClose}
      footer={
        <>
          {quotation && !answering && canEdit && status === 'DRAFT' && (
            <>
              <Button icon={<Pencil className="w-3.5 h-3.5" />} onClick={() => setMode('edit')}>
                Editar
              </Button>
              <Button variant="primary" icon={<Send className="w-3.5 h-3.5" />} processing={busy === 'send'} onClick={() => run('send', () => quotationsApi.send(quotation.id), 'No se pudo enviar la cotización.')}>
                Marcar como enviada
              </Button>
            </>
          )}
          {quotation && !answering && canEdit && (status === 'SENT' || status === 'EXPIRED' || status === 'REJECTED') && (
            <Button icon={<CopyPlus className="w-3.5 h-3.5" />} processing={busy === 'revise'} onClick={() => run('revise', () => quotationsApi.revise(quotation.id), 'No se pudo crear la versión nueva.')}>
              Nueva versión
            </Button>
          )}
          {quotation && !answering && canAnswer && (status === 'SENT' || status === 'EXPIRED') && (
            <Button variant="danger" icon={<XCircle className="w-3.5 h-3.5" />} onClick={() => setMode('reject')}>
              El cliente rechazó
            </Button>
          )}
          {quotation && !answering && canAnswer && status === 'SENT' && (
            <Button variant="primary" icon={<CheckCircle2 className="w-3.5 h-3.5" />} onClick={() => setMode('accept')}>
              El cliente aceptó
            </Button>
          )}
          {!answering && <Button onClick={onClose}>Cerrar</Button>}
          {answering && <Button onClick={() => setMode('view')}>Volver</Button>}
          {quotation && mode === 'accept' && (
            <Button variant="primary" processing={busy === 'accept'} onClick={() => run('accept', () => quotationsApi.accept(quotation.id, blankToNull(text)), 'No se pudo registrar la aceptación.')}>
              Registrar aceptación
            </Button>
          )}
          {quotation && mode === 'reject' && (
            <Button variant="danger" type="submit" form="reject-quotation-form" processing={busy === 'reject'}>
              Registrar rechazo
            </Button>
          )}
        </>
      }
    >
      <div className="space-y-5">
        {error && <Alert>{error}</Alert>}
        {!quotation && !error && <LoadingState />}
        {quotation && (
          <>
            <div className="flex flex-wrap items-center gap-x-4 gap-y-2 text-[13px] text-slate-600">
              <QuotationStatusBadge status={quotation.status} />
              <span>Vigente hasta el {formatDay(quotation.valid_until)}</span>
              {quotation.contact && <span>Contacto: {quotation.contact.name}</span>}
              {quotation.created_by && <span>Por {quotation.created_by}</span>}
            </div>

            {mode === 'accept' && (
              <div className="rounded-xl border border-emerald-200 bg-emerald-50/60 p-3.5 space-y-3">
                <p className="text-[13px] text-emerald-900">
                  Se creará la venta por <strong>{formatMoney(quotation.total_amount, quotation.currency)}</strong> con estos cursos y precios, y {quotation.company.name} pasará a ser cliente. Las licencias se
                  entregan cuando se confirme el pago.
                </p>
                <Field label="Cómo aceptó (opcional)" hint="Ejemplo: por correo del 5 de octubre, orden de compra 4471.">
                  <input className={INPUT_CLASS} maxLength={500} autoFocus value={text} onChange={(event) => setText(event.target.value)} />
                </Field>
              </div>
            )}
            {mode === 'reject' && (
              <form
                id="reject-quotation-form"
                className="rounded-xl border border-rose-200 bg-rose-50/60 p-3.5"
                onSubmit={(event) => {
                  event.preventDefault();
                  void run('reject', () => quotationsApi.reject(quotation.id, text.trim()), 'No se pudo registrar el rechazo.');
                }}
              >
                <Field label="Motivo del rechazo" hint="Después puedes crear una versión nueva con otra propuesta.">
                  <textarea className={INPUT_CLASS} rows={2} required minLength={3} maxLength={500} autoFocus value={text} onChange={(event) => setText(event.target.value)} />
                </Field>
              </form>
            )}

            {status === 'DRAFT' && quotation.version > 1 && (
              <Alert tone="info">Esta es la versión {quotation.version}, aún en borrador. El cliente no puede aceptar nada hasta que la envíes.</Alert>
            )}
            {status === 'ACCEPTED' && (
              <Alert tone="success">
                Aceptada el {formatDay(quotation.accepted_at)}
                {quotation.accepted_via === 'PORTAL' ? ' por el responsable de la empresa, desde su portal' : quotation.accepted_by ? `; la registró ${quotation.accepted_by}` : ''}.
                {quotation.acceptance_note && ` ${quotation.acceptance_note}.`}
                {quotation.order && (
                  <>
                    {' '}
                    Generó la orden <strong>{orderCode(quotation.order.number)}</strong> ({ORDER_STATUS_LABEL[quotation.order.status].toLowerCase()}).{' '}
                    {canSeeOrders && (
                      <Link to="/billing" className="font-semibold underline">
                        Ver en Ventas y pagos
                      </Link>
                    )}
                  </>
                )}
              </Alert>
            )}
            {status === 'REJECTED' && quotation.reject_reason && <Alert>Rechazada: {quotation.reject_reason}</Alert>}

            <QuotationItems items={quotation.items} total={quotation.total_amount} currency={quotation.currency} />

            {quotation.notes && (
              <div>
                <p className="text-xs font-semibold text-slate-500 mb-1">Condiciones</p>
                <p className="text-[13px] text-slate-700 whitespace-pre-line">{quotation.notes}</p>
              </div>
            )}

            {quotation.versions.length > 0 && (
              <div>
                <p className="text-xs font-semibold text-slate-500 mb-2">Versiones enviadas</p>
                <ul className="rounded-xl border border-slate-200 divide-y divide-slate-100 text-[13px]">
                  {quotation.versions.map((version) => (
                    <li key={version.version} className="flex flex-wrap items-center justify-between gap-2 px-3.5 py-2.5">
                      <span className="text-slate-700">
                        <strong className="text-slate-900">Versión {version.version}</strong> · enviada el {formatDay(version.sent_at)} ·{' '}
                        {version.items.map((item) => `${item.quantity} × ${item.title}`).join(', ')}
                      </span>
                      <span className="font-semibold text-slate-900 tnum">{formatMoney(version.total_amount, quotation.currency)}</span>
                    </li>
                  ))}
                </ul>
              </div>
            )}
          </>
        )}
      </div>
    </Modal>
  );
};
