import React, { useEffect, useMemo, useState } from 'react';
import { Building2, CheckCircle2, FileText, Globe2, Phone, Settings as SettingsIcon, type LucideIcon } from 'lucide-react';
import { useAuth } from '../../auth/AuthContext';
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
}

const CURRENCY_LABEL: Record<string, string> = { PEN: 'Soles (PEN)', USD: 'Dólares (USD)' };

const fetchSettings = async () => {
  const response = await api.get('/settings');
  return { settings: response.data.data as Settings, currencies: response.data.meta.currencies as string[] };
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
  const editable = hasPermission('tenant_settings.update');

  const [form, setForm] = useState<Settings | null>(null);
  const [currencies, setCurrencies] = useState<string[]>([]);
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

    // Los campos opcionales vacíos se envían como null
    const payload = Object.fromEntries(Object.entries(form).map(([key, value]) => [key, value === '' ? null : value]));

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
