import React, { useEffect, useMemo, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import {
  Building2,
  CheckCircle2,
  FileText,
  Globe2,
  GraduationCap,
  ImageUp,
  Mail,
  Pencil,
  Phone,
  Plus,
  Settings as SettingsIcon,
  ShieldCheck, BookOpenCheck,
  ShoppingCart,
  Trash2,
  type LucideIcon,
} from 'lucide-react';
import { useAuth } from '../../auth/AuthContext';
import { useTenant } from '../../../shared/tenancy/TenantContext';
import { api, apiErrorMessage } from '../../../shared/api/client';
import {
  Alert,
  Badge,
  Button,
  Card,
  ConfirmModal,
  ErrorState,
  Field,
  IconChip,
  CHECKBOX_CLASS,
  INPUT_CLASS,
  LoadingState,
  Modal,
  PageHeader,
  type Tone,
} from '../../../shared/ui';
import { ExchangeRateSection } from '../components/ExchangeRateSection';
import { HolidaysSection } from '../components/HolidaysSection';
import { PaymentGatewaysSection } from '../components/PaymentGatewaysSection';
import { PlanCard } from '../components/PlanCard';

interface Settings {
  name: string;
  legal_name: string | null;
  tax_id: string | null;
  primary_currency: string;
  secondary_currency: string | null;
  timezone: string;
  contact_email: string | null;
  contact_phone: string | null;
  contact_address: string | null;
  session_minutes: number;
  payment_instructions: string | null;
  order_expiration_hours: number;
  claim_response_days: number;
  grade_scale: number;
  // Exigir la verificación en dos pasos a todo el personal
  require_two_factor: boolean;
  logo_url: string | null;
}

interface Limits {
  min: number;
  max: number;
}

interface GradeScale {
  id: string | null;
  name: string;
  max_score: number;
  built_in: boolean;
  in_use: boolean;
}

interface GradeScaleList {
  scales: GradeScale[];
  gradeScale: number;
}

type ScaleDialog = { kind: 'edit'; scale: GradeScale | null } | { kind: 'delete'; scale: GradeScale };

interface LogoRules {
  max_kb: number;
  extensions: string[];
}

const CURRENCY_LABEL: Record<string, string> = {
  PEN: 'Soles (PEN)',
  USD: 'Dólares (USD)',
};

const fetchSettings = async () => {
  const response = await api.get('/settings');
  return {
    settings: response.data.data as Settings,
    currencies: response.data.meta.currencies as string[],
    sessionLimits: response.data.meta.session_minutes as Limits,
    orderLimits: response.data.meta.order_expiration_hours as Limits,
    gradeScales: response.data.meta.grade_scales as GradeScale[],
    maxScoreLimits: response.data.meta.grade_scale_max_score as Limits,
    logoRules: response.data.meta.logo as LogoRules,
  };
};

const toScaleList = (response: { data: { data: GradeScale[]; meta: { grade_scale: number } } }): GradeScaleList => ({
  scales: response.data.data,
  gradeScale: response.data.meta.grade_scale,
});

// Las escalas se guardan al momento, aparte del formulario: son una lista que la academia reutiliza
const GradeScaleModal: React.FC<{
  scale: GradeScale | null;
  limits: Limits;
  onClose: () => void;
  onSaved: (list: GradeScaleList) => void;
}> = ({ scale, limits, onClose, onSaved }) => {
  const [name, setName] = useState(scale?.name ?? '');
  const [maxScore, setMaxScore] = useState(scale ? String(scale.max_score) : '');
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setProcessing(true);
    setError(null);
    const input = { name: name.trim(), max_score: Number(maxScore) };
    try {
      const response = await (scale ? api.put(`/settings/grade-scales/${scale.id}`, input) : api.post('/settings/grade-scales', input));
      onSaved(toScaleList(response));
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo guardar la escala.'));
      setProcessing(false);
    }
  };

  return (
    <Modal
      title={scale ? 'Editar escala' : 'Nueva escala de notas'}
      subtitle={scale ? undefined : 'Queda guardada para que la uses cuando quieras.'}
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="grade-scale-form" processing={processing}>
            {scale ? 'Guardar' : 'Crear escala'}
          </Button>
        </>
      }
    >
      <form id="grade-scale-form" onSubmit={submit} className="space-y-4">
        {error && <Alert>{error}</Alert>}
        {scale?.in_use && (
          <Alert tone="info">Esta escala está en uso: si cambias la nota máxima, las notas se mostrarán con el nuevo valor.</Alert>
        )}
        <Field label="Nombre">
          <input
            className={INPUT_CLASS}
            required
            maxLength={80}
            autoFocus
            placeholder="Ej. Sobre 10"
            value={name}
            onChange={(event) => setName(event.target.value)}
          />
        </Field>
        <Field label="Nota máxima" hint={`Número entero entre ${limits.min} y ${limits.max}. Las notas irán de 0 a ese valor.`}>
          <input
            className={INPUT_CLASS}
            type="number"
            required
            step={1}
            min={limits.min}
            max={limits.max}
            placeholder="Ej. 10"
            value={maxScore}
            onChange={(event) => setMaxScore(event.target.value)}
          />
        </Field>
      </form>
    </Modal>
  );
};

// El logo se guarda al elegirlo, aparte del formulario: es un archivo, no un dato del formulario
const LogoField: React.FC<{
  logoUrl: string | null;
  rules: LogoRules;
  editable: boolean;
  onChange: (logoUrl: string | null) => void;
}> = ({ logoUrl, rules, editable, onChange }) => {
  const input = useRef<HTMLInputElement>(null);
  const [busy, setBusy] = useState<'upload' | 'remove' | null>(null);
  const [error, setError] = useState<string | null>(null);

  const formats = rules.extensions.filter((extension) => extension !== 'jpeg').map((extension) => extension.toUpperCase());
  const maxMb = Math.round((rules.max_kb / 1024) * 10) / 10;

  const upload = async (event: React.ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;

    if (file.size > rules.max_kb * 1024) {
      setError(`La imagen pesa más de ${maxMb} MB.`);
      return;
    }

    setBusy('upload');
    setError(null);
    try {
      const body = new FormData();
      body.append('file', file);
      const response = await api.post('/settings/logo', body);
      onChange(response.data.data.logo_url);
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo subir el logo.'));
    } finally {
      setBusy(null);
    }
  };

  const remove = async () => {
    setBusy('remove');
    setError(null);
    try {
      await api.delete('/settings/logo');
      onChange(null);
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo quitar el logo.'));
    } finally {
      setBusy(null);
    }
  };

  return (
    <div className="mb-5 pb-5 border-b border-slate-200/80">
      <span className="block text-[13px] font-medium text-slate-700 mb-2">Logo</span>
      <div className="flex flex-wrap items-center gap-4">
        <div className="w-20 h-20 shrink-0 rounded-2xl border border-slate-200 bg-slate-50 flex items-center justify-center overflow-hidden">
          {logoUrl ? (
            <img src={logoUrl} alt="Logo de la organización" className="w-full h-full object-contain p-1.5" />
          ) : (
            <Building2 className="w-7 h-7 text-slate-300" aria-hidden="true" />
          )}
        </div>
        <div className="min-w-0">
          {editable && (
            <div className="flex flex-wrap gap-2 mb-2">
              <input
                ref={input}
                type="file"
                className="sr-only"
                accept={rules.extensions.map((extension) => `.${extension}`).join(',')}
                onChange={upload}
                aria-label="Archivo del logo"
                tabIndex={-1}
              />
              <Button
                icon={<ImageUp className="w-4 h-4" />}
                processing={busy === 'upload'}
                disabled={busy !== null}
                onClick={() => input.current?.click()}
              >
                {logoUrl ? 'Cambiar logo' : 'Subir logo'}
              </Button>
              {logoUrl && (
                <Button
                  variant="danger"
                  icon={<Trash2 className="w-4 h-4" />}
                  processing={busy === 'remove'}
                  disabled={busy !== null}
                  onClick={remove}
                >
                  Quitar
                </Button>
              )}
            </div>
          )}
          <p className="text-xs text-slate-500">
            {formats.join(', ')} · máximo {maxMb} MB. Se muestra en el menú y se guarda al elegirlo.
          </p>
          {error && (
            <p className="text-xs font-medium text-rose-700 mt-1.5" role="alert">
              {error}
            </p>
          )}
        </div>
      </div>
    </div>
  );
};

const Section: React.FC<{
  title: string;
  text: string;
  icon: LucideIcon;
  tone: Tone;
  children: React.ReactNode;
}> = ({ title, text, icon, tone, children }) => (
  <Card className="p-5 sm:p-6">
    <div className="flex items-center gap-3.5 mb-5">
      <IconChip icon={icon} tone={tone} />
      <div>
        <h2 className="text-sm font-semibold text-slate-900">{title}</h2>
        <p className="text-[13px] text-slate-500">{text}</p>
      </div>
    </div>
    {children}
  </Card>
);

export const SettingsPage: React.FC = () => {
  const { hasPermission, refresh } = useAuth();
  const { setLogoUrl } = useTenant();
  const editable = hasPermission('tenant_settings.update');

  const [rateVersion, setRateVersion] = useState(0);
  const [form, setForm] = useState<Settings | null>(null);
  const [currencies, setCurrencies] = useState<string[]>([]);
  const [sessionLimits, setSessionLimits] = useState<Limits>({
    min: 5,
    max: 1440,
  });
  const [orderLimits, setOrderLimits] = useState<Limits>({ min: 1, max: 720 });
  const [gradeScales, setGradeScales] = useState<GradeScale[]>([]);
  const [maxScoreLimits, setMaxScoreLimits] = useState<Limits>({
    min: 1,
    max: 1000,
  });
  const [scaleDialog, setScaleDialog] = useState<ScaleDialog | null>(null);
  const [logoRules, setLogoRules] = useState<LogoRules>({
    max_kb: 2048,
    extensions: [],
  });
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [loadError, setLoadError] = useState('');
  const [status, setStatus] = useState<'idle' | 'processing' | 'saved'>('idle');
  const [error, setError] = useState<string | null>(null);
  const [attempt, setAttempt] = useState(0);

  const timezones = useMemo(() => Intl.supportedValuesOf('timeZone'), []);

  useEffect(() => {
    let active = true;
    fetchSettings()
      .then((result) => {
        if (!active) return;
        setForm(result.settings);
        setCurrencies(result.currencies);
        setSessionLimits(result.sessionLimits);
        setOrderLimits(result.orderLimits);
        setGradeScales(result.gradeScales);
        setMaxScoreLimits(result.maxScoreLimits);
        setLogoRules(result.logoRules);
        setState('ready');
      })
      .catch((err) => {
        if (!active) return;
        setLoadError(apiErrorMessage(err, 'Verifica tu conexión e inténtalo nuevamente.'));
        setState('error');
      });

    return () => {
      active = false;
    };
  }, [attempt]);

  if (state === 'loading') return <LoadingState />;
  if (state === 'error' || !form) {
    return (
      <ErrorState
        message={loadError}
        onRetry={() => {
          setState('loading');
          setAttempt((n) => n + 1);
        }}
      />
    );
  }

  const set = (key: keyof Settings) => (event: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) => {
    setForm({ ...form, [key]: event.target.value });
    setStatus('idle');
  };

  const text = (key: Exclude<keyof Settings, 'require_two_factor'>) => ({
    className: INPUT_CLASS,
    disabled: !editable,
    value: form[key] ?? '',
    onChange: set(key),
  });

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    setStatus('processing');
    setError(null);

    // Los campos opcionales vacíos se envían como null; el logo viaja aparte
    const payload = Object.fromEntries(
      Object.entries(form)
        .filter(([key]) => key !== 'logo_url')
        .map(([key, value]) => [key, value === '' ? null : value]),
    );

    try {
      const response = await api.put('/settings', payload);
      setForm(response.data.data);
      setGradeScales((scales) => scales.map((scale) => ({ ...scale, in_use: scale.max_score === response.data.data.grade_scale })));
      setStatus('saved');
      // El nombre de la organización se muestra en la cabecera
      await refresh();
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo guardar la configuración.'));
      setStatus('idle');
    }
  };

  // Tras crear, editar o eliminar una escala: si la elegida en el formulario ya no existe, vuelve a la que está en uso
  const applyScales = ({ scales, gradeScale }: GradeScaleList) => {
    setGradeScales(scales);
    if (!scales.some((scale) => scale.max_score === form.grade_scale)) {
      setForm({ ...form, grade_scale: gradeScale });
    }
    setScaleDialog(null);
  };

  return (
    <>
      <form onSubmit={handleSubmit} className="space-y-5">
        <PageHeader
          title="Configuración"
          icon={SettingsIcon}
          subtitle="Datos generales de tu organización."
          action={
            editable && (
              <div className="flex items-center gap-3">
                {status === 'saved' && (
                  <span className="flex items-center gap-1.5 text-[13px] font-medium text-emerald-700" role="status">
                    <CheckCircle2 className="w-4 h-4" /> Cambios guardados
                  </span>
                )}
                <Button variant="primary" type="submit" processing={status === 'processing'}>
                  Guardar cambios
                </Button>
              </div>
            )
          }
        />

        {error && <Alert>{error}</Alert>}

        <PlanCard />

        <Section icon={Building2} tone="brand" title="Organización" text="Identidad de la academia en la plataforma y en sus documentos.">
          <LogoField
            logoUrl={form.logo_url}
            rules={logoRules}
            editable={editable}
            onChange={(logoUrl) => {
              setForm({ ...form, logo_url: logoUrl });
              setLogoUrl(logoUrl);
            }}
          />
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <Field label="Nombre de la academia">
              <input {...text('name')} required maxLength={150} />
            </Field>
            <Field label="Razón social">
              <input {...text('legal_name')} maxLength={200} />
            </Field>
            <Field label="Identificación fiscal">
              <input {...text('tax_id')} maxLength={30} />
            </Field>
          </div>
        </Section>

        <Section icon={Globe2} tone="success" title="Región y monedas" text="Monedas en las que vendes y zona horaria de la operación.">
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <Field label="Moneda principal">
              <select {...text('primary_currency')}>
                {currencies.map((code) => (
                  <option key={code} value={code}>
                    {CURRENCY_LABEL[code] ?? code}
                  </option>
                ))}
              </select>
            </Field>
            <Field label="Moneda secundaria">
              <select {...text('secondary_currency')}>
                <option value="">Ninguna</option>
                {currencies
                  .filter((code) => code !== form.primary_currency)
                  .map((code) => (
                    <option key={code} value={code}>
                      {CURRENCY_LABEL[code] ?? code}
                    </option>
                  ))}
              </select>
            </Field>
            <Field label="Zona horaria">
              <select {...text('timezone')}>
                {timezones.map((zone) => (
                  <option key={zone} value={zone}>
                    {zone}
                  </option>
                ))}
              </select>
            </Field>
          </div>
        </Section>

        <Section icon={Phone} tone="info" title="Datos de contacto" text="Se muestran a tus alumnos y clientes.">
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <Field label="Correo">
              <input {...text('contact_email')} type="email" maxLength={180} />
            </Field>
            <Field label="Teléfono">
              <input {...text('contact_phone')} maxLength={40} />
            </Field>
            <Field label="Dirección">
              <input {...text('contact_address')} maxLength={255} />
            </Field>
          </div>
        </Section>

        <Section icon={GraduationCap} tone="violet" title="Calificaciones" text="Cómo se muestran las notas de cuestionarios y tareas.">
          <div className="max-w-xs">
            <Field label="Escala de notas en uso" hint="Cambiarla no altera las notas ya registradas: solo cómo se muestran.">
              <select
                className={INPUT_CLASS}
                disabled={!editable}
                value={form.grade_scale}
                onChange={(event) => {
                  setForm({ ...form, grade_scale: Number(event.target.value) });
                  setStatus('idle');
                }}
              >
                {gradeScales.map((scale) => (
                  <option key={scale.max_score} value={scale.max_score}>
                    {scale.name} · de 0 a {scale.max_score}
                  </option>
                ))}
              </select>
            </Field>
          </div>

          <div className="mt-6 pt-5 border-t border-slate-200/80">
            <div className="flex flex-wrap items-center justify-between gap-3 mb-3">
              <div>
                <h3 className="text-[13px] font-semibold text-slate-900">Escalas guardadas</h3>
                <p className="text-xs text-slate-500">Crea las escalas que necesites y elige arriba cuál usar.</p>
              </div>
              {editable && (
                <Button size="sm" icon={<Plus className="w-3.5 h-3.5" />} onClick={() => setScaleDialog({ kind: 'edit', scale: null })}>
                  Nueva escala
                </Button>
              )}
            </div>
            <ul className="divide-y divide-slate-200/80 border border-slate-200/80 rounded-xl">
              {gradeScales.map((scale) => (
                <li key={scale.max_score} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                  <div className="flex flex-wrap items-center gap-x-3 gap-y-1 min-w-0">
                    <span className="text-[13px] font-semibold text-slate-900">{scale.name}</span>
                    <span className="text-[13px] text-slate-500 tnum">De 0 a {scale.max_score}</span>
                    {scale.in_use && (
                      <Badge tone="success" dot>
                        En uso
                      </Badge>
                    )}
                  </div>
                  {scale.built_in ? (
                    <span className="text-xs text-slate-500">Del sistema</span>
                  ) : (
                    editable && (
                      <div className="flex items-center gap-2">
                        <Button
                          size="sm"
                          icon={<Pencil className="w-3.5 h-3.5" />}
                          aria-label={`Editar la escala ${scale.name}`}
                          onClick={() => setScaleDialog({ kind: 'edit', scale })}
                        >
                          Editar
                        </Button>
                        <Button
                          size="sm"
                          variant="danger"
                          icon={<Trash2 className="w-3.5 h-3.5" />}
                          aria-label={`Eliminar la escala ${scale.name}`}
                          onClick={() => setScaleDialog({ kind: 'delete', scale })}
                        >
                          Eliminar
                        </Button>
                      </div>
                    )
                  )}
                </li>
              ))}
            </ul>
          </div>
        </Section>

        <Section icon={ShoppingCart} tone="success" title="Ventas" text="Cómo pagan tus alumnos los cursos que compran.">
          <div className="space-y-4">
            <Field
              label="Instrucciones de pago"
              hint={`Se muestran al comprador en su orden. Los precios de los cursos se cobran en ${CURRENCY_LABEL[form.primary_currency] ?? form.primary_currency}, tu moneda principal.`}
            >
              <textarea
                {...text('payment_instructions')}
                rows={5}
                maxLength={5000}
                placeholder="Ej. Transferencia al BCP, cuenta 000-0000000-0-00 a nombre de la academia. Yape al 999 999 999. Envía tu comprobante indicando el número de orden."
              />
            </Field>
            <div className="max-w-xs">
              <Field
                label="Plazo para pagar una orden (horas)"
                hint={`Entre ${orderLimits.min} y ${orderLimits.max}. Pasado el plazo, la orden vence y el alumno debe generar otra.`}
              >
                <input
                  className={INPUT_CLASS}
                  type="number"
                  required
                  min={orderLimits.min}
                  max={orderLimits.max}
                  disabled={!editable}
                  value={form.order_expiration_hours}
                  onChange={(event) => {
                    setForm({ ...form, order_expiration_hours: Number(event.target.value) });
                    setStatus('idle');
                  }}
                />
              </Field>
            </div>
          </div>
        </Section>

        <Section
          icon={BookOpenCheck}
          tone="info"
          title="Libro de Reclamaciones"
          text="Formulario público donde cualquier consumidor registra un reclamo o una queja. Usa la razón social, el RUC y la dirección de arriba."
        >
          <div className="max-w-xs">
            <Field label="Plazo para responder (días hábiles)" hint="Entre 1 y 60. Se le informa al consumidor en su constancia. No cuenta sábados ni domingos.">
              <input
                className={INPUT_CLASS}
                type="number"
                required
                min={1}
                max={60}
                disabled={!editable}
                value={form.claim_response_days}
                onChange={(event) => {
                  setForm({ ...form, claim_response_days: Number(event.target.value) });
                  setStatus('idle');
                }}
              />
            </Field>
          </div>
        </Section>

        <Section
          icon={ShieldCheck}
          tone="warning"
          title="Seguridad"
          text="Protección de las sesiones de todos los usuarios de la organización."
        >
          <div className="max-w-xs">
            <Field
              label="Cierre de sesión por inactividad (minutos)"
              hint={`Entre ${sessionLimits.min} y ${sessionLimits.max}. Aplica a las sesiones que se inicien desde ahora.`}
            >
              <input
                className={INPUT_CLASS}
                type="number"
                required
                min={sessionLimits.min}
                max={sessionLimits.max}
                disabled={!editable}
                value={form.session_minutes}
                onChange={(event) => {
                  setForm({
                    ...form,
                    session_minutes: Number(event.target.value),
                  });
                  setStatus('idle');
                }}
              />
            </Field>
          </div>
          <label className="flex items-start gap-3 mt-4 cursor-pointer">
            <input
              type="checkbox"
              className={`${CHECKBOX_CLASS} mt-0.5`}
              disabled={!editable}
              checked={form.require_two_factor}
              onChange={(event) => {
                setForm({ ...form, require_two_factor: event.target.checked });
                setStatus('idle');
              }}
            />
            <span>
              <span className="block text-[13px] font-medium text-slate-800">Exigir la verificación en dos pasos a todo el personal</span>
              <span className="block text-xs text-slate-500 mt-0.5">
                Quien no sea alumno ni responsable de empresa deberá activarla al entrar: además de su contraseña, un código de la aplicación de su teléfono. Para exigirla, actívala primero en «Mi cuenta».
              </span>
            </span>
          </label>
        </Section>

        {hasPermission('email_templates.manage') && (
          <Section icon={Mail} tone="info" title="Correos automáticos" text="Los avisos que la academia envía sola: cuenta nueva, matrícula, pagos, certificado…">
            <p className="text-[13px] text-slate-600">
              Puedes cambiar el texto de cada correo, apagar los que no necesites y enviarte una prueba en{' '}
              <Link to="/settings/emails" className="font-semibold text-brand-700 underline hover:text-brand-900">
                Correos
              </Link>
              . La pantalla trae una guía paso a paso.
            </p>
          </Section>
        )}

        <Section icon={FileText} tone="violet" title="Textos legales" text="Términos, privacidad y otros textos que ven tus alumnos y clientes.">
          <p className="text-[13px] text-slate-600">
            Los textos legales se escriben y publican en{' '}
            <Link to="/pages" className="font-semibold text-brand-700 underline hover:text-brand-900">
              Páginas
            </Link>
            . Las que marques para el pie del sitio se enlazan en el catálogo y en el registro, donde quien crea su cuenta debe aceptarlas.
          </p>
        </Section>
      </form>

      <div className="mt-5">
        <ExchangeRateSection editable={editable} onChanged={() => setRateVersion((version) => version + 1)} />
      </div>

      <div className="mt-5">
        {/* Vuelve a cargar al cambiar el tipo de cambio, que decide si PayPal se ofrece */}
        <PaymentGatewaysSection key={rateVersion} editable={editable} />
      </div>

      <div className="mt-5">
        <HolidaysSection editable={editable} />
      </div>

      {scaleDialog?.kind === 'edit' && (
        <GradeScaleModal scale={scaleDialog.scale} limits={maxScoreLimits} onClose={() => setScaleDialog(null)} onSaved={applyScales} />
      )}
      {scaleDialog?.kind === 'delete' && (
        <ConfirmModal
          title="Eliminar escala"
          text={`Se eliminará la escala «${scaleDialog.scale.name}» (de 0 a ${scaleDialog.scale.max_score}). Las notas ya registradas no cambian.`}
          confirmLabel="Eliminar"
          onClose={() => setScaleDialog(null)}
          onConfirm={async () => applyScales(toScaleList(await api.delete(`/settings/grade-scales/${scaleDialog.scale.id}`)))}
        />
      )}
    </>
  );
};
