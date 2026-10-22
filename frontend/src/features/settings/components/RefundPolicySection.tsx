import React, { useEffect, useState } from 'react';
import { FileText, Save, Sparkles } from 'lucide-react';
import { api, apiErrorMessage } from '../../../shared/api/client';
import { Alert, Button, Card, Field, IconChip, INPUT_CLASS, LoadingState } from '../../../shared/ui';

interface Policy {
  text: string | null;
  days: number | null;
  updated_at: string | null;
  template: string;
  default_days: number;
}

/**
 * Configuración → Política de reembolsos: la academia parte de la plantilla
 * de IntelectaLab, la adapta y la publica. Se muestra a los compradores en
 * la ficha del curso y al comprar; al reembolsar fuera del plazo, el sistema avisa.
 */
export const RefundPolicySection: React.FC<{ editable: boolean }> = ({ editable }) => {
  const [policy, setPolicy] = useState<Policy | null>(null);
  const [text, setText] = useState('');
  const [days, setDays] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  useEffect(() => {
    let active = true;
    api
      .get('/settings/refund-policy')
      .then((response) => {
        if (!active) return;
        const data = response.data.data as Policy;
        setPolicy(data);
        setText(data.text ?? '');
        setDays(data.days ? String(data.days) : '');
      })
      .catch((err) => active && setError(apiErrorMessage(err, 'No se pudo cargar la política de reembolsos.')));
    return () => {
      active = false;
    };
  }, []);

  const useTemplate = () => {
    if (!policy) return;
    const plazo = Number(days) || policy.default_days;
    setText(policy.template.replace(`${policy.default_days} días`, `${plazo} días`));
    setDays(String(plazo));
    setNotice(null);
  };

  const save = async () => {
    setBusy(true);
    setError(null);
    setNotice(null);
    try {
      const response = await api.put('/settings/refund-policy', { text: text.trim() || null, days: days === '' ? null : Number(days) });
      const data = response.data.data as Policy;
      setPolicy((current) => (current ? { ...current, ...data } : current));
      setNotice(data.text ? 'Política publicada. Tus compradores la ven en la ficha del curso y al comprar.' : 'Política quitada: ya no se muestra a los compradores.');
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo guardar la política.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Card className="p-5 sm:p-6">
      <div className="flex items-center gap-3.5 mb-5">
        <IconChip icon={FileText} tone="warning" />
        <div>
          <h2 className="text-sm font-semibold text-slate-900">Política de reembolsos</h2>
          <p className="text-[13px] text-slate-500">Qué devuelves, en qué plazo y cómo. Se muestra a tus compradores antes de pagar.</p>
        </div>
      </div>

      {!policy && !error && <LoadingState />}
      {error && <Alert>{error}</Alert>}

      {policy && (
        <div className="space-y-4">
          {!policy.text && (
            <Alert tone="info">
              Todavía no publicas una política. Puedes partir de la plantilla de IntelectaLab y adaptarla a tu academia: revisa cada punto antes de publicarla.
            </Alert>
          )}
          <div className="max-w-xs">
            <Field label="Plazo para pedir un reembolso (días)" hint="Al reembolsar una compra más antigua, el sistema te avisa (pero te deja seguir).">
              <input className={INPUT_CLASS} type="number" min={1} max={365} disabled={!editable} value={days} onChange={(event) => setDays(event.target.value)} placeholder={String(policy.default_days)} />
            </Field>
          </div>
          <Field label="Texto de la política">
            <textarea className={`${INPUT_CLASS} h-auto min-h-64 py-2 font-[inherit]`} maxLength={10000} disabled={!editable} value={text} onChange={(event) => setText(event.target.value)} />
          </Field>
          {notice && <Alert tone="success">{notice}</Alert>}
          {editable && (
            <div className="flex flex-wrap justify-end gap-2.5">
              <Button icon={<Sparkles className="w-4 h-4" />} onClick={useTemplate}>
                {text ? 'Reemplazar por la plantilla' : 'Usar la plantilla de IntelectaLab'}
              </Button>
              <Button variant="primary" icon={<Save className="w-4 h-4" />} processing={busy} onClick={save}>
                {text.trim() ? 'Guardar y publicar' : 'Guardar'}
              </Button>
            </div>
          )}
        </div>
      )}
    </Card>
  );
};
