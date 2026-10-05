import React, { useEffect, useMemo, useRef, useState } from 'react';
import {
  Building2,
  CheckCircle2,
  FileText,
  Globe2,
  GraduationCap,
  ImageUp,
  Phone,
  Settings as SettingsIcon,
  ShieldCheck,
  Trash2,
  type LucideIcon,
} from 'lucide-react';
import { useAuth } from '../../auth/AuthContext';
import { useTenant } from '../../../shared/tenancy/TenantContext';
import { api, apiErrorMessage } from '../../../shared/api/client';
import { Alert, Button, Card, ErrorState, Field, IconChip, INPUT_CLASS, LoadingState, PageHeader, type Tone } from '../../../shared/ui';

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
  terms: string | null;
  privacy_policy: string | null;
  session_minutes: number;
  grade_scale: number;
  logo_url: string | null;
}

interface Limits {
  min: number;
  max: number;
}

interface LogoRules {
  max_kb: number;
  extensions: string[];
}

const CURRENCY_LABEL: Record<string, string> = { PEN: 'Soles (PEN)', USD: 'Dólares (USD)' };

const fetchSettings = async () => {
  const response = await api.get('/settings');
  return {
    settings: response.data.data as Settings,
    currencies: response.data.meta.currencies as string[],
    sessionLimits: response.data.meta.session_minutes as Limits,
    gradeScales: response.data.meta.grade_scales as number[],
    logoRules: response.data.meta.logo as LogoRules,
  };
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

const Section: React.FC<{ title: string; text: string; icon: LucideIcon; tone: Tone; children: React.ReactNode }> = ({
  title,
  text,
  icon,
  tone,
  children,
}) => (
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

  const [form, setForm] = useState<Settings | null>(null);
  const [currencies, setCurrencies] = useState<string[]>([]);
  const [sessionLimits, setSessionLimits] = useState<Limits>({ min: 5, max: 1440 });
  const [gradeScales, setGradeScales] = useState<number[]>([20, 100]);
  const [logoRules, setLogoRules] = useState<LogoRules>({ max_kb: 2048, extensions: [] });
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
        setGradeScales(result.gradeScales);
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

  const set =
    (key: keyof Settings) => (event: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) => {
      setForm({ ...form, [key]: event.target.value });
      setStatus('idle');
    };

  const text = (key: keyof Settings) => ({
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
      setStatus('saved');
      // El nombre de la organización se muestra en la cabecera
      await refresh();
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo guardar la configuración.'));
      setStatus('idle');
    }
  };

  return (
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
          <Field label="Nombre de la plataforma">
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
          <Field label="Escala de notas" hint="Cambiarla no altera las notas ya registradas: solo cómo se muestran.">
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
                <option key={scale} value={scale}>
                  De 0 a {scale}
                </option>
              ))}
            </select>
          </Field>
        </div>
      </Section>

      <Section icon={ShieldCheck} tone="warning" title="Seguridad" text="Protección de las sesiones de todos los usuarios de la organización.">
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
                setForm({ ...form, session_minutes: Number(event.target.value) });
                setStatus('idle');
              }}
            />
          </Field>
        </div>
      </Section>

      <Section icon={FileText} tone="violet" title="Textos legales" text="Contenido que aceptan los usuarios al registrarse y comprar.">
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
          <Field label="Términos y condiciones">
            <textarea {...text('terms')} rows={8} />
          </Field>
          <Field label="Política de privacidad">
            <textarea {...text('privacy_policy')} rows={8} />
          </Field>
        </div>
      </Section>
    </form>
  );
};
