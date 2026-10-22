import React, { useCallback, useEffect, useState } from 'react';
import { CheckCircle2, Save, Send, Server } from 'lucide-react';
import { apiErrorMessage } from '../../../shared/api/client';
import { Alert, Button, Card, CardHeader, ErrorState, Field, INPUT_CLASS, LoadingState } from '../../../shared/ui';
import { mailSettingsApi, type MailMode, type MailSettings, type MailSettingsForm } from '../api';

const formatDateTime = (value: string) => new Date(value).toLocaleString('es-PE', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });

const toForm = (settings: MailSettings): MailSettingsForm => ({
  mode: settings.mode,
  from_name: settings.from_name ?? '',
  reply_to: settings.reply_to ?? '',
  from_address: settings.from_address ?? '',
  host: settings.host ?? '',
  port: settings.port ? String(settings.port) : '',
  security: settings.security,
  username: settings.username ?? '',
  password: '',
});

// Datos habituales de los proveedores más usados, para orientar a quien configura
const PROVIDERS = [
  ['Elastic Email', 'smtp.elasticemail.com', '2525 (TLS)', 'Tu correo de la cuenta y una clave SMTP creada en Elastic'],
  ['Google Workspace (Gmail de empresa)', 'smtp.gmail.com', '587 (TLS)', 'Tu correo y una «contraseña de aplicación»'],
  ['Microsoft 365 (Outlook)', 'smtp.office365.com', '587 (TLS)', 'Tu correo y su contraseña'],
];

const ModeOption: React.FC<{ value: MailMode; current: MailMode; title: string; text: string; onSelect: (mode: MailMode) => void }> = ({ value, current, title, text, onSelect }) => (
  <label className={`flex gap-3 rounded-xl border p-3.5 cursor-pointer transition-colors ${current === value ? 'border-brand-400 bg-brand-50/60' : 'border-slate-200 hover:bg-slate-50'}`}>
    <input type="radio" name="mail-mode" className="mt-0.5 accent-brand-600" checked={current === value} onChange={() => onSelect(value)} />
    <span>
      <span className="block text-[13px] font-semibold text-slate-900">{title}</span>
      <span className="block text-xs text-slate-600 mt-0.5">{text}</span>
    </span>
  </label>
);

/**
 * Configuración → Correos → «Correo de envío»: con qué correo salen los
 * avisos de la academia. Lo recomendado es el suyo propio.
 */
export const MailSenderCard: React.FC = () => {
  const [settings, setSettings] = useState<MailSettings | null>(null);
  const [form, setForm] = useState<MailSettingsForm | null>(null);
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [errorMessage, setErrorMessage] = useState('');
  const [busy, setBusy] = useState<'save' | 'test' | null>(null);
  const [result, setResult] = useState<{ tone: 'success' | 'danger' | 'info'; text: string } | null>(null);

  const load = useCallback(async () => {
    setState('loading');
    try {
      const data = await mailSettingsApi.get();
      setSettings(data);
      setForm(toForm(data));
      setState('ready');
    } catch (err) {
      setErrorMessage(apiErrorMessage(err, 'No se pudo abrir el correo de envío.'));
      setState('error');
    }
  }, []);

  useEffect(() => {
    const timer = setTimeout(load, 0);
    return () => clearTimeout(timer);
  }, [load]);

  if (state === 'loading') return <Card className="mb-5"><LoadingState /></Card>;
  if (state === 'error' || !settings || !form) return <Card className="mb-5"><ErrorState message={errorMessage} onRetry={load} /></Card>;

  const set = (patch: Partial<MailSettingsForm>) => {
    setResult(null);
    setForm({ ...form, ...patch });
  };
  const own = form.mode === 'OWN';

  const save = async () => {
    setBusy('save');
    setResult(null);
    try {
      const data = await mailSettingsApi.save(form);
      setSettings(data);
      setForm(toForm(data));
      setResult({ tone: 'success', text: 'Correo de envío guardado. Los próximos avisos saldrán así.' });
    } catch (err) {
      setResult({ tone: 'danger', text: apiErrorMessage(err, 'No se pudo guardar.') });
    } finally {
      setBusy(null);
    }
  };

  const test = async () => {
    setBusy('test');
    setResult(null);
    try {
      const sent = await mailSettingsApi.test(form);
      setResult(
        sent.delivered
          ? { tone: 'success', text: `Enviamos un correo de prueba a ${sent.sent_to}. Revisa tu bandeja (y la de spam). Si llegó, guarda los cambios.` }
          : { tone: 'info', text: 'La prueba quedó en el registro del servidor: la plataforma todavía no tiene activo su servicio de correo.' },
      );
    } catch (err) {
      setResult({ tone: 'danger', text: apiErrorMessage(err, 'No se pudo enviar la prueba.') });
    } finally {
      setBusy(null);
    }
  };

  return (
    <Card className="mb-5">
      <CardHeader title="Correo de envío" text="Con qué correo salen los avisos de tu academia a sus alumnos y a su personal." />
      <div className="px-5 py-4 space-y-4">
        <div className="grid grid-cols-1 md:grid-cols-2 gap-3" role="radiogroup" aria-label="Con qué correo salen los avisos">
          <ModeOption value="OWN" current={form.mode} onSelect={(mode) => set({ mode })} title="Mi propio correo (recomendado)" text="Los avisos salen desde el correo de tu academia, por tu servidor de correo. Necesitas los datos de conexión que te da tu proveedor." />
          <ModeOption value="PLATFORM" current={form.mode} onSelect={(mode) => set({ mode })} title="El correo de IntelectaLab" text={`Salen desde ${settings.defaults.platform_address} con el nombre de tu academia, y las respuestas llegan a tu correo.`} />
        </div>

        {settings.mode === 'OWN' && settings.last_error && settings.last_error_at && (
          <Alert tone="warning">
            Tu servidor de correo falló el {formatDateTime(settings.last_error_at)}: «{settings.last_error}». Ese aviso salió por el correo de IntelectaLab para no perderse. Revisa los datos y envía una prueba.
          </Alert>
        )}
        {settings.mode === 'OWN' && !settings.last_error && settings.last_sent_at && (
          <p className="flex items-center gap-1.5 text-xs text-emerald-700">
            <CheckCircle2 className="w-4 h-4" /> Último aviso enviado con tu correo: {formatDateTime(settings.last_sent_at)}.
          </p>
        )}

        <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
          <Field label="Nombre del remitente" hint="Es lo que ve el alumno como «De:».">
            <input className={INPUT_CLASS} maxLength={100} placeholder={settings.defaults.from_name} value={form.from_name} onChange={(event) => set({ from_name: event.target.value })} />
          </Field>
          <Field label="Correo para respuestas (opcional)" hint={own ? 'Si lo dejas vacío, responden al correo remitente.' : 'Si lo dejas vacío, se usa el correo de contacto de la academia.'}>
            <input type="email" className={INPUT_CLASS} placeholder={settings.defaults.reply_to ?? 'ayuda@tuacademia.com'} value={form.reply_to} onChange={(event) => set({ reply_to: event.target.value })} />
          </Field>
        </div>

        {own && (
          <div className="rounded-xl border border-slate-200 p-4 space-y-3">
            <p className="flex items-center gap-2 text-[13px] font-semibold text-slate-800">
              <Server className="w-4 h-4 text-slate-500" /> Tu servidor de correo
            </p>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
              <Field label="Correo remitente">
                <input type="email" className={INPUT_CLASS} placeholder="cursos@tuacademia.com" value={form.from_address} onChange={(event) => set({ from_address: event.target.value })} />
              </Field>
              <Field label="Servidor (SMTP)">
                <input className={INPUT_CLASS} placeholder="smtp.elasticemail.com" value={form.host} onChange={(event) => set({ host: event.target.value.trim() })} />
              </Field>
              <div className="grid grid-cols-2 gap-3">
                <Field label="Puerto">
                  <input inputMode="numeric" className={INPUT_CLASS} placeholder="587" value={form.port} onChange={(event) => set({ port: event.target.value.replace(/\D/g, '') })} />
                </Field>
                <Field label="Seguridad">
                  <select className={INPUT_CLASS} value={form.security} onChange={(event) => set({ security: event.target.value as MailSettingsForm['security'] })}>
                    <option value="tls">TLS (recomendado)</option>
                    <option value="ssl">SSL (puerto 465)</option>
                    <option value="none">Sin seguridad</option>
                  </select>
                </Field>
              </div>
              <Field label="Usuario">
                <input className={INPUT_CLASS} autoComplete="off" placeholder="Normalmente, tu correo" value={form.username} onChange={(event) => set({ username: event.target.value })} />
              </Field>
              <Field label="Contraseña" hint={settings.has_password ? 'Hay una guardada. Déjala vacía para conservarla.' : 'Se guarda cifrada y nadie puede verla después.'}>
                <input type="password" className={INPUT_CLASS} autoComplete="new-password" placeholder={settings.has_password ? '•••••••• (guardada)' : ''} value={form.password} onChange={(event) => set({ password: event.target.value })} />
              </Field>
            </div>
            <details className="text-xs text-slate-600">
              <summary className="cursor-pointer font-semibold text-slate-700">¿Dónde encuentro estos datos?</summary>
              <p className="mt-2">Te los da tu proveedor de correo. Los más comunes:</p>
              <ul className="mt-1.5 space-y-1">
                {PROVIDERS.map(([name, host, port, access]) => (
                  <li key={name}>
                    <span className="font-semibold text-slate-800">{name}:</span> servidor {host}, puerto {port}. Usuario y contraseña: {access}.
                  </li>
                ))}
              </ul>
              <p className="mt-1.5">Para que tus correos no caigan en spam, tu proveedor te pedirá registrar tu dominio (registros SPF y DKIM).</p>
            </details>
          </div>
        )}

        {!own && !settings.delivering && <Alert tone="info">Por ahora la plataforma deja los correos en su registro interno: empezarán a salir cuando IntelectaLab active su servicio de correo.</Alert>}
        {result && <Alert tone={result.tone}>{result.text}</Alert>}

        <div className="flex flex-wrap justify-end gap-2.5">
          <Button icon={<Send className="w-4 h-4" />} processing={busy === 'test'} disabled={busy !== null} onClick={test}>
            Enviarme una prueba
          </Button>
          <Button variant="primary" icon={<Save className="w-4 h-4" />} processing={busy === 'save'} disabled={busy !== null} onClick={save}>
            Guardar
          </Button>
        </div>
      </div>
    </Card>
  );
};
