import React, { useEffect, useState } from 'react';
import { PlugZap, ReceiptText } from 'lucide-react';
import { api, apiErrorMessage } from '../../../shared/api/client';
import { Alert, Badge, Button, Card, CHECKBOX_CLASS, Field, IconChip, INPUT_CLASS, LoadingState } from '../../../shared/ui';
import { formatDateTime } from '../../sales/api';

// Lo que devuelve el servidor (el token nunca vuelve: solo si hay uno y sus últimos caracteres)
interface Invoicing {
  enabled: boolean;
  service_url: string;
  demo: boolean;
  ruc: string;
  legal_name: string;
  trade_name: string | null;
  address: string;
  ubigeo: string;
  establishment_code: string;
  boleta_series: string;
  factura_series: string;
  igv_mode: 'INCLUDED' | 'EXONERATED';
  // Último número usado de cada serie: el siguiente comprobante sale con el que sigue
  boleta_last_number: number;
  factura_last_number: number;
  has_token: boolean;
  token_hint: string | null;
  last_error: string | null;
  last_ok_at: string | null;
}

type Form = Omit<Invoicing, 'demo' | 'has_token' | 'token_hint' | 'last_error' | 'last_ok_at' | 'trade_name'> & { trade_name: string; token: string };

const EMPTY: Form = {
  enabled: true,
  service_url: '',
  ruc: '',
  legal_name: '',
  trade_name: '',
  address: '',
  ubigeo: '',
  establishment_code: '0000',
  boleta_series: 'B001',
  factura_series: 'F001',
  igv_mode: 'INCLUDED',
  boleta_last_number: 0,
  factura_last_number: 0,
  token: '',
};

/** Configuración → Comprobantes electrónicos: los datos con los que emite la academia y su cuenta de Mifact. */
export const InvoicingSection: React.FC<{ editable: boolean }> = ({ editable }) => {
  const [saved, setSaved] = useState<Invoicing | null | undefined>(undefined);
  const [form, setForm] = useState<Form>(EMPTY);
  const [demoUrl, setDemoUrl] = useState('');
  const [busy, setBusy] = useState<'save' | 'check' | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = (data: Invoicing | null, demo: string) => {
    setSaved(data);
    setForm(data ? { ...data, trade_name: data.trade_name ?? '', token: '' } : { ...EMPTY, service_url: demo });
  };

  useEffect(() => {
    let active = true;
    api
      .get('/settings/invoicing')
      .then((response) => {
        if (!active) return;
        setDemoUrl(response.data.meta.demo_url);
        load(response.data.data, response.data.meta.demo_url);
      })
      .catch((err) => active && setError(apiErrorMessage(err, 'No se pudieron leer los datos de los comprobantes.')));
    return () => {
      active = false;
    };
  }, []);

  const set = <K extends keyof Form>(key: K, value: Form[K]) => setForm((current) => ({ ...current, [key]: value }));

  const save = async (event: React.FormEvent) => {
    event.preventDefault();
    setBusy('save');
    setErrors({});
    setError(null);
    setNotice(null);
    try {
      const response = await api.put('/settings/invoicing', {
        ...form,
        trade_name: form.trade_name || null,
        token: form.token || null,
        boleta_last_number: Number(form.boleta_last_number) || 0,
        factura_last_number: Number(form.factura_last_number) || 0,
      });
      load(response.data.data, demoUrl);
      setNotice('Guardado. La conexión con Mifact funciona.');
    } catch (err) {
      const details = (err as { response?: { data?: { details?: Record<string, string[]> } } }).response?.data?.details;
      if (details) setErrors(Object.fromEntries(Object.entries(details).map(([key, messages]) => [key, messages[0]])));
      setError(apiErrorMessage(err, 'No se pudieron guardar los datos.'));
    } finally {
      setBusy(null);
    }
  };

  const check = async () => {
    setBusy('check');
    setError(null);
    setNotice(null);
    try {
      const result = (await api.post('/settings/invoicing/check')).data.data as { ok: boolean; message: string };
      if (result.ok) setNotice(result.message);
      else setError(result.message);
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo probar la conexión.'));
    } finally {
      setBusy(null);
    }
  };

  const input = (key: keyof Form, props: React.InputHTMLAttributes<HTMLInputElement> = {}) => (
    <input
      className={INPUT_CLASS}
      disabled={!editable}
      value={String(form[key] ?? '')}
      onChange={(event) => set(key, event.target.value as never)}
      aria-invalid={errors[key] ? true : undefined}
      {...props}
    />
  );
  const fieldError = (key: string) => errors[key] && <p className="text-xs text-rose-700 mt-1">{errors[key]}</p>;

  return (
    <Card className="p-5 sm:p-6">
      <div className="flex flex-wrap items-start justify-between gap-3 mb-5">
        <div className="flex items-center gap-3.5">
          <IconChip icon={ReceiptText} tone="brand" />
          <div>
            <h2 className="text-sm font-semibold text-slate-900">Comprobantes electrónicos</h2>
            <p className="text-[13px] text-slate-500">Cada pago confirmado lleva su boleta o factura, emitida con Mifact y enviada a SUNAT.</p>
          </div>
        </div>
        {saved && (
          <div className="flex flex-wrap items-center gap-2">
            {saved.demo && <Badge tone="warning">Ambiente de pruebas</Badge>}
            <Badge tone={saved.enabled ? 'success' : 'neutral'} dot>
              {saved.enabled ? 'Emitiendo' : 'Apagado'}
            </Badge>
          </div>
        )}
      </div>

      {saved === undefined && !error && <LoadingState />}
      {error && <Alert>{error}</Alert>}
      {notice && <Alert tone="success">{notice}</Alert>}
      {saved?.last_ok_at && <p className="text-xs text-slate-500 mb-3">Último comprobante aceptado: {formatDateTime(saved.last_ok_at)}.</p>}

      {saved !== undefined && (
        <form onSubmit={(event) => void save(event)} className="space-y-5">
          <label className="flex items-start gap-2.5 text-[13px] text-slate-700">
            <input type="checkbox" className={`${CHECKBOX_CLASS} mt-0.5`} disabled={!editable} checked={form.enabled} onChange={(event) => set('enabled', event.target.checked)} />
            <span>
              <strong className="font-semibold text-slate-900">Emitir comprobantes al confirmarse cada pago</strong> (también cada cuota). Si lo apagas, los pagos siguen funcionando sin comprobante.
            </span>
          </label>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <Field label="RUC" hint="El de la academia, 11 dígitos.">
              {input('ruc', { inputMode: 'numeric', maxLength: 11 })}
              {fieldError('ruc')}
            </Field>
            <Field label="Razón social" hint="Tal como figura en SUNAT.">
              {input('legal_name', { maxLength: 200 })}
              {fieldError('legal_name')}
            </Field>
            <Field label="Nombre comercial" hint="Opcional.">
              {input('trade_name', { maxLength: 200 })}
            </Field>
            <Field label="Domicilio fiscal">
              {input('address', { maxLength: 250 })}
              {fieldError('address')}
            </Field>
            <Field label="Ubigeo del domicilio" hint="6 dígitos, por ejemplo 150101 (Lima).">
              {input('ubigeo', { inputMode: 'numeric', maxLength: 6 })}
              {fieldError('ubigeo')}
            </Field>
            <Field label="Código de establecimiento" hint="0000 si emites desde el domicilio fiscal.">
              {input('establishment_code', { inputMode: 'numeric', maxLength: 4 })}
              {fieldError('establishment_code')}
            </Field>
            <Field label="Serie de boletas" hint="Empieza con B, por ejemplo B001.">
              {input('boleta_series', { maxLength: 4, onChange: (event) => set('boleta_series', event.target.value.toUpperCase()) })}
              {fieldError('boleta_series')}
            </Field>
            <Field label="Serie de facturas" hint="Empieza con F, por ejemplo F001.">
              {input('factura_series', { maxLength: 4, onChange: (event) => set('factura_series', event.target.value.toUpperCase()) })}
              {fieldError('factura_series')}
            </Field>
            <Field label="Último número emitido (boletas)" hint="Si ya emitiste boletas con esta serie desde Mifact, el último número. Solo puede subir.">
              {input('boleta_last_number', { type: 'number', min: 0 })}
              {fieldError('boleta_last_number')}
            </Field>
            <Field label="Último número emitido (facturas)" hint="Igual que las boletas: el sistema sigue desde el número siguiente.">
              {input('factura_last_number', { type: 'number', min: 0 })}
              {fieldError('factura_last_number')}
            </Field>
            <Field label="IGV de los cursos" hint="El precio que ve el alumno no cambia; cambia cómo se detalla en el comprobante.">
              <select className={INPUT_CLASS} disabled={!editable} value={form.igv_mode} onChange={(event) => set('igv_mode', event.target.value as Form['igv_mode'])}>
                <option value="INCLUDED">El precio incluye IGV (18 %)</option>
                <option value="EXONERATED">Exonerada del IGV (instituto autorizado)</option>
              </select>
            </Field>
          </div>

          <div className="pt-5 border-t border-slate-200/80 grid grid-cols-1 sm:grid-cols-2 gap-4">
            <Field label="Dirección del servicio de Mifact" hint="La de producción te la da Mifact con tu token. Mientras pruebas, usa la de pruebas.">
              {input('service_url', { type: 'url', maxLength: 200 })}
              {editable && form.service_url !== demoUrl && (
                <button type="button" className="text-xs font-semibold text-brand-700 underline mt-1 cursor-pointer" onClick={() => set('service_url', demoUrl)}>
                  Usar la de pruebas
                </button>
              )}
              {fieldError('service_url')}
            </Field>
            <Field label="Token de Mifact" hint={saved?.has_token ? `Guardado (${saved.token_hint}). Déjalo vacío para conservarlo.` : 'Se guarda cifrado y no se vuelve a mostrar.'}>
              {input('token', { type: 'password', autoComplete: 'off', placeholder: saved?.has_token ? '••••••••' : '' })}
              {fieldError('token')}
            </Field>
          </div>

          {editable && (
            <div className="flex flex-wrap justify-end gap-2">
              {saved && (
                <Button type="button" icon={<PlugZap className="w-4 h-4" />} processing={busy === 'check'} disabled={busy !== null} onClick={() => void check()}>
                  Probar conexión
                </Button>
              )}
              <Button type="submit" variant="primary" processing={busy === 'save'} disabled={busy !== null}>
                Guardar
              </Button>
            </div>
          )}
        </form>
      )}
    </Card>
  );
};
