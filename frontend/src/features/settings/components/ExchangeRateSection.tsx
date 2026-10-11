import React, { useEffect, useState } from 'react';
import { ArrowLeftRight, RefreshCw } from 'lucide-react';
import { api, apiErrorMessage } from '../../../shared/api/client';
import { Alert, Badge, Button, Card, Field, IconChip, INPUT_CLASS, LoadingState } from '../../../shared/ui';

type Mode = 'MANUAL' | 'AUTO';

interface Rate {
  id: string;
  // Soles por dólar con los que se cobra, ya con el recargo
  rate: number;
  source: string;
  // Lo que publicó la fuente, antes del recargo
  official_rate: number | null;
  margin_percent: number | null;
  effective_date: string;
  created_at: string;
  // null = la actualización automática
  created_by: string | null;
}

interface ExchangeState {
  mode: Mode;
  source: string;
  margin_percent: number;
  current: Rate | null;
  // Lleva días sin cambiar
  stale: boolean;
  history: Rate[];
}

interface Meta {
  sources: { code: string; label: string }[];
  max_margin: number;
  stale_days: number;
}

const PATH = '/settings/exchange-rate';

const formatDay = (value: string) => {
  const [year, month, day] = value.split('-').map(Number);
  return new Date(year, month - 1, day).toLocaleDateString('es-PE', { day: 'numeric', month: 'short', year: 'numeric' });
};

const sourceLabel = (rate: Rate) => (rate.source === 'MANUAL' ? 'Manual' : rate.source);

// Cuántos dólares se cobran por S/ 100 con un tipo de cambio
const usdFor100 = (rate: number) => (rate > 0 ? (100 / rate).toFixed(2) : '—');

// Tipo de cambio de la academia: escrito a mano o tomado solo de SUNAT cada día, con su historial
export const ExchangeRateSection: React.FC<{ editable: boolean; onChanged?: () => void }> = ({ editable, onChanged }) => {
  const [state, setState] = useState<ExchangeState | null>(null);
  const [meta, setMeta] = useState<Meta>({ sources: [], max_margin: 20, stale_days: 3 });
  const [mode, setMode] = useState<Mode>('MANUAL');
  const [margin, setMargin] = useState('0');
  const [manual, setManual] = useState('');
  const [busy, setBusy] = useState<'settings' | 'manual' | 'refresh' | null>(null);
  const [message, setMessage] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);
  const [showAll, setShowAll] = useState(false);

  const apply = (data: ExchangeState) => {
    setState(data);
    setMode(data.mode);
    setMargin(String(data.margin_percent));
    setManual(data.mode === 'MANUAL' && data.current ? String(data.current.rate) : '');
  };

  useEffect(() => {
    let active = true;
    api
      .get(PATH)
      .then((response) => {
        if (!active) return;
        apply(response.data.data);
        setMeta(response.data.meta);
      })
      .catch((err) => active && setMessage({ tone: 'danger', text: apiErrorMessage(err, 'No se pudo cargar el tipo de cambio.') }));
    return () => {
      active = false;
    };
  }, []);

  const run = async (kind: 'settings' | 'manual' | 'refresh', request: () => Promise<{ data: { data: ExchangeState } }>, done: string, fallback: string) => {
    setBusy(kind);
    setMessage(null);
    try {
      apply((await request()).data.data);
      setMessage({ tone: 'success', text: done });
      onChanged?.();
    } catch (err) {
      setMessage({ tone: 'danger', text: apiErrorMessage(err, fallback) });
    } finally {
      setBusy(null);
    }
  };

  // Los formularios no se envían con el de Configuración que los rodea
  const submit = (action: () => void) => (event: React.FormEvent) => {
    event.preventDefault();
    event.stopPropagation();
    action();
  };

  const saveSettings = submit(() =>
    void run(
      'settings',
      () => api.put(PATH, { mode, source: state?.source, margin_percent: mode === 'AUTO' ? Number(margin) || 0 : 0 }),
      mode === 'AUTO' ? 'Listo: el tipo de cambio se actualiza solo cada día.' : 'Listo: el tipo de cambio lo escribes tú.',
      'No se pudo guardar. Si elegiste automático, puede que la fuente no haya respondido: inténtalo de nuevo.',
    ),
  );

  const saveManual = submit(() => void run('manual', () => api.post(`${PATH}/manual`, { rate: Number(manual) }), 'Tipo de cambio guardado: vale para los pagos que se inicien desde ahora.', 'No se pudo guardar el tipo de cambio.'));

  const settingsDirty = state !== null && (mode !== state.mode || (mode === 'AUTO' && Number(margin) !== state.margin_percent));
  const current = state?.current ?? null;
  const history = state ? (showAll ? state.history : state.history.slice(0, 5)) : [];
  const source = meta.sources.find((item) => item.code === state?.source);

  return (
    <Card className="p-5 sm:p-6">
      <div className="flex items-center gap-3.5 mb-5">
        <IconChip icon={ArrowLeftRight} tone="info" />
        <div>
          <h2 className="text-sm font-semibold text-slate-900">Tipo de cambio</h2>
          <p className="text-[13px] text-slate-500">Soles por dólar con los que se cobra en dólares lo que vendes en soles. Hoy lo usa PayPal.</p>
        </div>
      </div>

      {message && (
        <div className="mb-4">
          <Alert tone={message.tone}>{message.text}</Alert>
        </div>
      )}
      {!state && !message && <LoadingState />}

      {state && (
        <div className="space-y-5">
          <div className="rounded-xl border border-slate-200 bg-slate-50/60 p-4">
            {current ? (
              <div className="flex flex-wrap items-center gap-x-6 gap-y-2">
                <div>
                  <p className="text-xs font-medium text-slate-500">Vigente</p>
                  <p className="text-2xl font-bold text-slate-900 tnum">S/ {current.rate.toFixed(4)}</p>
                </div>
                <div className="text-[13px] text-slate-600 space-y-0.5">
                  <p className="flex flex-wrap items-center gap-1.5">
                    <Badge tone={current.source === 'MANUAL' ? 'neutral' : 'info'}>{sourceLabel(current)}</Badge>
                    del {formatDay(current.effective_date)}
                    {state.stale && <Badge tone="warning">Sin cambios hace más de {meta.stale_days} días</Badge>}
                  </p>
                  {current.official_rate !== null && (
                    <p>
                      Oficial S/ {current.official_rate.toFixed(4)}
                      {current.margin_percent ? ` · recargo de ${current.margin_percent} %` : ' · sin recargo'}
                    </p>
                  )}
                  <p>
                    Un precio de S/ 100 se cobra como <strong className="font-semibold text-slate-900">US$ {usdFor100(current.rate)}</strong>.
                  </p>
                </div>
              </div>
            ) : (
              <p className="text-[13px] text-slate-600">Todavía no hay un tipo de cambio. Mientras falte, los medios de pago en dólares no se ofrecen a quien compra.</p>
            )}
          </div>

          <form onSubmit={saveSettings} className="space-y-3">
            <fieldset disabled={!editable} className="space-y-2">
              <legend className="block text-[13px] font-medium text-slate-700 mb-1.5">Cómo se obtiene</legend>
              <label className="flex items-start gap-2.5 text-[13px] text-slate-700 cursor-pointer">
                <input type="radio" name="exchange-mode" className="mt-0.5" checked={mode === 'MANUAL'} onChange={() => setMode('MANUAL')} />
                <span>
                  <span className="font-medium">Manual</span>
                  <span className="block text-xs text-slate-500">Lo escribes tú y vale hasta que lo cambies. No se actualiza solo.</span>
                </span>
              </label>
              <label className="flex items-start gap-2.5 text-[13px] text-slate-700 cursor-pointer">
                <input type="radio" name="exchange-mode" className="mt-0.5" checked={mode === 'AUTO'} onChange={() => setMode('AUTO')} />
                <span>
                  <span className="font-medium">Automático: {source?.label ?? 'SUNAT'}</span>
                  <span className="block text-xs text-slate-500">Se toma cada mañana el tipo de cambio oficial del día. Si un día la fuente no responde, se conserva el último valor.</span>
                </span>
              </label>
            </fieldset>

            {mode === 'AUTO' && (
              <div className="flex flex-wrap items-end gap-3">
                <div className="w-56">
                  <Field label="Recargo (opcional, %)" hint={`De 0 a ${meta.max_margin}. Sirve para cubrir la comisión de la pasarela.`}>
                    <input
                      className={INPUT_CLASS}
                      type="number"
                      inputMode="decimal"
                      step="0.1"
                      min="0"
                      max={meta.max_margin}
                      disabled={!editable}
                      value={margin}
                      onChange={(event) => setMargin(event.target.value)}
                    />
                  </Field>
                </div>
                <p className="text-xs text-slate-500 pb-2.5 max-w-sm">
                  Con {Number(margin) || 0} %, lo que sin recargo se cobraría como US$ 100.00 se cobra como US$ {(100 * (1 + (Number(margin) || 0) / 100)).toFixed(2)}.
                </p>
              </div>
            )}

            {editable && (
              <div className="flex flex-wrap gap-2">
                <Button type="submit" variant="primary" processing={busy === 'settings'} disabled={!settingsDirty}>
                  Guardar
                </Button>
                {state.mode === 'AUTO' && !settingsDirty && (
                  <Button
                    icon={<RefreshCw className="w-4 h-4" />}
                    processing={busy === 'refresh'}
                    onClick={() => void run('refresh', () => api.post(`${PATH}/refresh`), 'Tipo de cambio actualizado con el valor de hoy.', 'La fuente no respondió. Se mantiene el último valor.')}
                  >
                    Actualizar ahora
                  </Button>
                )}
              </div>
            )}
          </form>

          {state.mode === 'MANUAL' && !settingsDirty && (
            <form onSubmit={saveManual} className="flex flex-wrap items-end gap-2">
              <div className="w-56">
                <Field label="Tipo de cambio (soles por dólar)">
                  <input
                    className={INPUT_CLASS}
                    type="number"
                    inputMode="decimal"
                    step="0.0001"
                    min="0.1"
                    max="100"
                    required
                    disabled={!editable}
                    placeholder="3.75"
                    value={manual}
                    onChange={(event) => setManual(event.target.value)}
                  />
                </Field>
              </div>
              {editable && (
                <Button type="submit" processing={busy === 'manual'} disabled={current !== null && Number(manual) === current.rate}>
                  Registrar
                </Button>
              )}
              <p className="text-xs text-slate-500 pb-2.5">Con ese valor, S/ 100 se cobrarían como US$ {usdFor100(Number(manual))}.</p>
            </form>
          )}

          {state.history.length > 0 && (
            <div>
              <h3 className="text-[13px] font-medium text-slate-700 mb-1.5">Historial</h3>
              <div className="overflow-x-auto rounded-xl border border-slate-200">
                <table className="w-full text-[13px]">
                  <thead className="bg-slate-50 text-left text-xs text-slate-500">
                    <tr>
                      <th scope="col" className="px-3 py-2 font-medium">Día</th>
                      <th scope="col" className="px-3 py-2 font-medium">Soles por dólar</th>
                      <th scope="col" className="px-3 py-2 font-medium">Fuente</th>
                      <th scope="col" className="px-3 py-2 font-medium">Registrado por</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {history.map((rate) => (
                      <tr key={rate.id}>
                        <td className="px-3 py-2 whitespace-nowrap">{formatDay(rate.effective_date)}</td>
                        <td className="px-3 py-2 tnum font-semibold text-slate-900">{rate.rate.toFixed(4)}</td>
                        <td className="px-3 py-2">
                          {sourceLabel(rate)}
                          {rate.official_rate !== null && (
                            <span className="text-slate-500">
                              {' '}
                              · oficial {rate.official_rate.toFixed(4)}
                              {rate.margin_percent ? ` + ${rate.margin_percent} %` : ''}
                            </span>
                          )}
                        </td>
                        <td className="px-3 py-2 text-slate-600">{rate.created_by ?? 'Actualización automática'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              {state.history.length > 5 && (
                <button type="button" className="mt-1.5 text-xs font-semibold text-brand-700 underline cursor-pointer" onClick={() => setShowAll((value) => !value)}>
                  {showAll ? 'Ver menos' : `Ver los últimos ${state.history.length}`}
                </button>
              )}
            </div>
          )}
        </div>
      )}
    </Card>
  );
};
