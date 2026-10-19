import React, { useCallback, useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { AlertTriangle, Building2, LineChart, Wallet } from 'lucide-react';
import { api, apiErrorMessage } from '../../../shared/api/client';
import { Card, ErrorState, LoadingState } from '../../../shared/ui';
import { PanelDownloads, PanelView, PeriodPicker } from '../../../shared/ui/panel';
import { presetPeriod, type PanelSection, type Period, type PeriodPreset } from '../../../shared/ui/panelData';

type SectionKey = 'academies' | 'income' | 'usage' | 'alerts';

const SECTIONS: { key: SectionKey; label: string; icon: React.ElementType }[] = [
  { key: 'academies', label: 'Academias', icon: Building2 },
  { key: 'income', label: 'Ingresos', icon: Wallet },
  { key: 'usage', label: 'Uso', icon: LineChart },
  { key: 'alerts', label: 'Alertas', icon: AlertTriangle },
];

const load = async (section: SectionKey, period: Period) => (await api.get(`/platform/dashboard/${section}`, { params: period })).data.data as PanelSection;

const dateLabel = (value: string) => new Intl.DateTimeFormat('es-PE', { day: '2-digit', month: 'long', year: 'numeric' }).format(new Date(`${value}T12:00:00`));

/**
 * Panel de la consola: cómo están las academias, lo que cobra la plataforma,
 * cuánto se usa el sistema y lo que requiere atención, en el periodo elegido.
 */
export const DashboardPanel: React.FC = () => {
  const [section, setSection] = useState<SectionKey>('academies');
  const [preset, setPreset] = useState<PeriodPreset>('this_month');
  const [period, setPeriod] = useState<Period>(() => presetPeriod('this_month'));
  const [data, setData] = useState<PanelSection | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [attempt, setAttempt] = useState(0);
  // Todas las secciones, para el PDF
  const [printable, setPrintable] = useState<{ key: SectionKey; data: PanelSection }[] | null>(null);
  const [printing, setPrinting] = useState(false);

  useEffect(() => {
    let active = true;
    load(section, period)
      .then((result) => {
        if (!active) return;
        setData(result);
        setError(null);
      })
      .catch((err) => active && setError(apiErrorMessage(err, 'No se pudo cargar el panel.')));
    return () => {
      active = false;
    };
  }, [section, period, attempt]);

  // El PDF lo arma la ventana de impresión del navegador con todas las secciones a la vez
  const print = useCallback(async () => {
    setPrinting(true);
    try {
      const all = await Promise.all(SECTIONS.map(async ({ key }) => ({ key, data: await load(key, period) })));
      setPrintable(all);
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo preparar el PDF.'));
      setPrinting(false);
    }
  }, [period]);

  useEffect(() => {
    if (!printable) return;
    const done = () => {
      setPrintable(null);
      setPrinting(false);
    };
    window.addEventListener('afterprint', done, { once: true });
    // Se espera a que los gráficos estén dibujados
    const timer = setTimeout(() => window.print(), 400);
    return () => {
      clearTimeout(timer);
      window.removeEventListener('afterprint', done);
    };
  }, [printable]);

  return (
    <div>
      <Card className="p-5 mb-5">
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div className="flex items-center gap-3.5">
            <span className="w-11 h-11 rounded-xl bg-brand-50 text-brand-700 flex items-center justify-center ring-1 ring-brand-100">
              <LineChart className="w-5 h-5" />
            </span>
            <div>
              <h1 className="text-lg font-bold tracking-tight text-slate-900">Panel de la plataforma</h1>
              <p className="text-[13px] text-slate-500">Academias, ingresos por planes, uso del sistema y lo que requiere atención.</p>
            </div>
          </div>
          <div className="flex flex-col items-end gap-2">
            <PeriodPicker
              preset={preset}
              period={period}
              onChange={(nextPreset, nextPeriod) => {
                setPreset(nextPreset);
                setPeriod(nextPeriod);
              }}
            />
            <PanelDownloads exportPath="/platform/dashboard/export" period={period} filename="panel-plataforma" onPrint={() => void print()} printing={printing} />
          </div>
        </div>
      </Card>

      <div role="tablist" aria-label="Secciones del panel" className="inline-flex flex-wrap gap-1 p-1 mb-5 rounded-xl bg-white border border-slate-200 shadow-xs">
        {SECTIONS.map(({ key, label, icon: Icon }) => (
          <button
            key={key}
            type="button"
            role="tab"
            aria-selected={section === key}
            onClick={() => {
              setSection(key);
              setData(null);
            }}
            className={`inline-flex items-center gap-2 h-9 px-3.5 rounded-lg text-[13px] font-semibold transition-colors cursor-pointer ${
              section === key ? 'bg-brand-700 text-white shadow-xs' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'
            }`}
          >
            <Icon className="w-4 h-4" />
            {label}
          </button>
        ))}
      </div>

      {error && !data ? (
        <Card>
          <ErrorState message={error} onRetry={() => setAttempt((n) => n + 1)} />
        </Card>
      ) : data ? (
        <PanelView section={data} />
      ) : (
        <LoadingState />
      )}

      {/* Fuera de la aplicación (directo en <body>), para que al imprimir no la afecten los contenedores con animación */}
      {printable &&
        createPortal(
        <div className="panel-print">
          <h1 className="text-xl font-bold text-slate-900">Panel de la plataforma · IntelectaLab</h1>
          <p className="text-[13px] text-slate-600 mb-4">
            Del {dateLabel(period.from)} al {dateLabel(period.to)} · generado el {dateLabel(new Date().toISOString().slice(0, 10))}
          </p>
          {printable.map(({ key, data: sectionData }) => (
            <section key={key} className="panel-print-section">
              <h2 className="text-base font-bold text-brand-800 mb-3">{sectionData.title}</h2>
              <PanelView section={sectionData} />
            </section>
          ))}
        </div>,
          document.body,
        )}
    </div>
  );
};
