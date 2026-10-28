import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { BookOpenCheck, Printer, Search } from 'lucide-react';
import { apiErrorMessage } from '../../../shared/api/client';
import { Alert, Button, Card, CHECKBOX_CLASS, Field, INPUT_CLASS, LoadingState } from '../../../shared/ui';
import { PublicShell } from '../../sales/components/PublicShell';
import { claimsApi, CLAIM_DOC_LABEL, CLAIM_TYPE_HINT, CLAIM_TYPE_LABEL, type Claim, type ClaimDocType, type ClaimFormInfo, type ClaimType } from '../api';
import { ClaimSheet } from '../components/ClaimSheet';

const Hero: React.FC<{ title: string; text: string }> = ({ title, text }) => (
  <div className="max-w-2xl">
    <p className="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-accent-200">
      <BookOpenCheck className="w-4 h-4" />
      Libro de Reclamaciones
    </p>
    <h1 className="mt-2 text-3xl sm:text-4xl font-bold text-white tracking-tight">{title}</h1>
    <p className="mt-3 text-accent-100/90 text-[15px] leading-relaxed">{text}</p>
  </div>
);

const blank = (value: string) => (value.trim() === '' ? null : value.trim());

// Constancia: lo que el consumidor guarda tras registrar su hoja
const Receipt: React.FC<{ claim: Claim }> = ({ claim }) => (
  <div className="max-w-3xl mx-auto space-y-4">
    <Alert tone="success">
      Tu {CLAIM_TYPE_LABEL[claim.type].toLowerCase()} quedó registrado con el código <strong className="font-mono">{claim.code}</strong>. Guárdalo: con él y tu número de documento puedes
      consultar el estado y la respuesta.
    </Alert>
    <Card className="p-5 sm:p-6">
      <ClaimSheet claim={claim} />
    </Card>
    <div className="flex flex-wrap gap-2.5 print:hidden">
      <Button variant="primary" icon={<Printer className="w-4 h-4" />} onClick={() => window.print()}>
        Imprimir o guardar como PDF
      </Button>
      <Link to="/reclamaciones/seguimiento" className="inline-flex items-center justify-center h-9 px-3.5 rounded-lg text-[13px] font-semibold bg-white text-slate-700 border border-slate-300 hover:bg-slate-50">
        Consultar una hoja
      </Link>
    </div>
  </div>
);

// Formulario público del Libro de Reclamaciones
export const ClaimFormPage: React.FC = () => {
  const [info, setInfo] = useState<ClaimFormInfo | null>(null);
  const [claim, setClaim] = useState<Claim | null>(null);
  const [type, setType] = useState<ClaimType>('RECLAMO');
  const [docType, setDocType] = useState<ClaimDocType>('DNI');
  const [goodType, setGoodType] = useState<'PRODUCT' | 'SERVICE'>('SERVICE');
  const [minor, setMinor] = useState(false);
  const [accepted, setAccepted] = useState(false);
  const [form, setForm] = useState({
    first_name: '',
    last_name: '',
    doc_number: '',
    address: '',
    email: '',
    phone: '',
    guardian_name: '',
    guardian_doc_number: '',
    good_description: '',
    amount: '',
    order_reference: '',
    detail: '',
    request: '',
  });
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    claimsApi
      .form()
      .then(setInfo)
      .catch((err) => setError(apiErrorMessage(err, 'No se pudo cargar el formulario.')));
  }, []);

  const bind = (key: keyof typeof form) => ({
    value: form[key],
    onChange: (event: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => setForm((current) => ({ ...current, [key]: event.target.value })),
  });

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setProcessing(true);
    setError(null);
    try {
      const registered = await claimsApi.submit({
        type,
        first_name: form.first_name.trim(),
        last_name: form.last_name.trim(),
        doc_type: docType,
        doc_number: form.doc_number.trim(),
        address: form.address.trim(),
        email: form.email.trim(),
        phone: blank(form.phone),
        is_minor: minor,
        guardian_name: minor ? blank(form.guardian_name) : null,
        guardian_doc_number: minor ? blank(form.guardian_doc_number) : null,
        good_type: goodType,
        good_description: form.good_description.trim(),
        amount: form.amount === '' ? null : Number(form.amount),
        order_reference: blank(form.order_reference),
        detail: form.detail.trim(),
        request: form.request.trim(),
        accepted,
      });
      setClaim(registered);
      window.scrollTo({ top: 0 });
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo registrar tu hoja. Inténtalo nuevamente.'));
    } finally {
      setProcessing(false);
    }
  };

  const choice = (active: boolean) =>
    `flex-1 text-left rounded-xl border px-3.5 py-3 cursor-pointer transition-all ${
      active ? 'border-brand-500 bg-brand-50/60 ring-4 ring-brand-500/10' : 'border-slate-200 bg-white hover:border-slate-300'
    }`;

  if (claim) {
    return (
      <PublicShell hero={<Hero title="Hoja registrada" text="Esta es tu constancia. Imprímela o guárdala." />}>
        <Receipt claim={claim} />
      </PublicShell>
    );
  }

  return (
    <PublicShell
      hero={
        <Hero
          title="Registra tu reclamo o queja"
          text={
            info
              ? `Conforme al Código de Protección y Defensa del Consumidor. Te responderemos en un plazo máximo de ${info.response_days} días hábiles.`
              : 'Conforme al Código de Protección y Defensa del Consumidor.'
          }
        />
      }
    >
      <div className="max-w-3xl mx-auto">
        {!info && !error && (
          <Card>
            <LoadingState />
          </Card>
        )}
        {info && (
          <form onSubmit={submit} className="space-y-5">
            <Card className="p-5 sm:p-6">
              <h2 className="text-sm font-semibold text-slate-900">Proveedor</h2>
              <p className="text-[13px] text-slate-600 mt-1">
                {info.provider.legal_name ?? info.provider.name}
                {info.provider.tax_id && ` · RUC ${info.provider.tax_id}`}
                {info.provider.address && ` · ${info.provider.address}`}
              </p>
            </Card>

            <Card className="p-5 sm:p-6 space-y-4">
              <h2 className="text-sm font-semibold text-slate-900">1. Tus datos</h2>
              <div className="grid sm:grid-cols-2 gap-4">
                <Field label="Nombres">
                  <input className={INPUT_CLASS} required maxLength={100} autoComplete="given-name" {...bind('first_name')} />
                </Field>
                <Field label="Apellidos">
                  <input className={INPUT_CLASS} required maxLength={100} autoComplete="family-name" {...bind('last_name')} />
                </Field>
                <Field label="Tipo de documento">
                  <select className={INPUT_CLASS} value={docType} onChange={(event) => setDocType(event.target.value as ClaimDocType)}>
                    {info.doc_types.map((item) => (
                      <option key={item} value={item}>
                        {CLAIM_DOC_LABEL[item]}
                      </option>
                    ))}
                  </select>
                </Field>
                <Field label="Número de documento" hint="Lo necesitarás para consultar tu hoja.">
                  <input className={INPUT_CLASS} required maxLength={15} {...bind('doc_number')} />
                </Field>
                <Field label="Correo">
                  <input className={INPUT_CLASS} type="email" required maxLength={150} autoComplete="email" {...bind('email')} />
                </Field>
                <Field label="Teléfono (opcional)">
                  <input className={INPUT_CLASS} maxLength={30} autoComplete="tel" {...bind('phone')} />
                </Field>
              </div>
              <Field label="Domicilio">
                <input className={INPUT_CLASS} required maxLength={250} autoComplete="street-address" {...bind('address')} />
              </Field>
              <label className="flex items-center gap-2.5 text-[13px] text-slate-700 cursor-pointer">
                <input type="checkbox" className={CHECKBOX_CLASS} checked={minor} onChange={(event) => setMinor(event.target.checked)} />
                Soy menor de edad
              </label>
              {minor && (
                <div className="grid sm:grid-cols-2 gap-4">
                  <Field label="Padre, madre o apoderado">
                    <input className={INPUT_CLASS} required maxLength={200} {...bind('guardian_name')} />
                  </Field>
                  <Field label="Documento del apoderado">
                    <input className={INPUT_CLASS} required maxLength={20} {...bind('guardian_doc_number')} />
                  </Field>
                </div>
              )}
            </Card>

            <Card className="p-5 sm:p-6 space-y-4">
              <h2 className="text-sm font-semibold text-slate-900">2. Bien contratado</h2>
              <div className="grid sm:grid-cols-2 gap-4">
                <Field label="Tipo">
                  <select className={INPUT_CLASS} value={goodType} onChange={(event) => setGoodType(event.target.value as 'PRODUCT' | 'SERVICE')}>
                    <option value="SERVICE">Servicio (un curso, la atención)</option>
                    <option value="PRODUCT">Producto</option>
                  </select>
                </Field>
                <Field label={`Monto reclamado en ${info.currency} (opcional)`}>
                  <input className={INPUT_CLASS} type="number" min={0} step="0.01" {...bind('amount')} />
                </Field>
              </div>
              <Field label="Descripción" hint="Ejemplo: curso Power BI desde cero.">
                <input className={INPUT_CLASS} required maxLength={500} {...bind('good_description')} />
              </Field>
              <Field label="Número de orden o comprobante (opcional)">
                <input className={INPUT_CLASS} maxLength={60} {...bind('order_reference')} />
              </Field>
            </Card>

            <Card className="p-5 sm:p-6 space-y-4">
              <h2 className="text-sm font-semibold text-slate-900">3. Detalle</h2>
              <div className="flex flex-col sm:flex-row gap-3" role="radiogroup" aria-label="Tipo de hoja">
                {(['RECLAMO', 'QUEJA'] as ClaimType[]).map((item) => (
                  <button key={item} type="button" role="radio" aria-checked={type === item} className={choice(type === item)} onClick={() => setType(item)}>
                    <span className="block text-[13px] font-semibold text-slate-900">{CLAIM_TYPE_LABEL[item]}</span>
                    <span className="block text-xs text-slate-500 mt-0.5">{CLAIM_TYPE_HINT[item]}</span>
                  </button>
                ))}
              </div>
              <Field label="¿Qué ocurrió?">
                <textarea className={INPUT_CLASS} rows={5} required minLength={10} maxLength={5000} {...bind('detail')} />
              </Field>
              <Field label="¿Qué pides?" hint="Ejemplo: que me activen el curso, que me devuelvan el pago.">
                <textarea className={INPUT_CLASS} rows={3} required minLength={5} maxLength={3000} {...bind('request')} />
              </Field>
              <label className="flex items-start gap-2.5 text-[13px] text-slate-700 cursor-pointer">
                <input type="checkbox" className={`${CHECKBOX_CLASS} mt-0.5`} required checked={accepted} onChange={(event) => setAccepted(event.target.checked)} />
                Declaro que los datos consignados son correctos y autorizo que me respondan al correo indicado.
              </label>
              <p className="text-xs text-slate-500">
                La formulación del reclamo no impide acudir a otras vías de solución de controversias ni es requisito previo para interponer una denuncia ante el INDECOPI.
              </p>
            </Card>

            {error && <Alert>{error}</Alert>}
            <div className="flex flex-wrap items-center gap-3">
              <Button variant="primary" size="lg" type="submit" processing={processing}>
                Registrar hoja
              </Button>
              <Link to="/reclamaciones/seguimiento" className="text-[13px] font-semibold text-brand-700 hover:text-brand-800">
                Ya registré una: consultar su estado
              </Link>
            </div>
          </form>
        )}
        {!info && error && <Alert>{error}</Alert>}
      </div>
    </PublicShell>
  );
};

// Seguimiento público: código de la hoja y número de documento
export const ClaimLookupPage: React.FC = () => {
  const [code, setCode] = useState('');
  const [docNumber, setDocNumber] = useState('');
  const [claim, setClaim] = useState<Claim | null>(null);
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setProcessing(true);
    setError(null);
    setClaim(null);
    try {
      setClaim(await claimsApi.lookup(code.trim(), docNumber.trim()));
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo consultar la hoja. Inténtalo nuevamente.'));
    } finally {
      setProcessing(false);
    }
  };

  return (
    <PublicShell hero={<Hero title="Consulta tu hoja" text="Ingresa el código de tu constancia y tu número de documento para ver el estado y la respuesta." />}>
      <div className="max-w-3xl mx-auto space-y-5">
        <Card className="p-5 sm:p-6 print:hidden">
          <form onSubmit={submit} className="grid sm:grid-cols-[1fr_1fr_auto] gap-4 items-end">
            <Field label="Código de la hoja">
              <input className={`${INPUT_CLASS} font-mono uppercase`} required maxLength={20} placeholder="LR-2026-000001" value={code} onChange={(event) => setCode(event.target.value)} />
            </Field>
            <Field label="Número de documento">
              <input className={INPUT_CLASS} required maxLength={20} value={docNumber} onChange={(event) => setDocNumber(event.target.value)} />
            </Field>
            <Button variant="primary" type="submit" icon={<Search className="w-4 h-4" />} processing={processing}>
              Consultar
            </Button>
          </form>
        </Card>
        {error && <Alert>{error}</Alert>}
        {claim && (
          <Card className="p-5 sm:p-6">
            <ClaimSheet claim={claim} />
          </Card>
        )}
        <p className="text-[13px] text-slate-600 print:hidden">
          ¿Aún no registras tu reclamo o queja?{' '}
          <Link to="/reclamaciones" className="font-semibold text-brand-700 hover:text-brand-800">
            Ir al Libro de Reclamaciones
          </Link>
        </p>
      </div>
    </PublicShell>
  );
};
