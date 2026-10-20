import React, { useCallback, useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { LineChart } from 'lucide-react';
import { api, apiErrorMessage } from '../api/client';
import { Card, ErrorState, LoadingState } from './index';
import { PanelDownloads, PanelView, PeriodPicker } from './panel';
import { presetPeriod, type PanelSection, type Period, type PeriodPreset } from './panelData';

export interface PanelTab {
  key: string;
  label: string;
  icon: React.ElementType;
}

interface PanelPageProps {
  title: string;
  subtitle: string;
  // Título del PDF, que no lleva el menú de la aplicación
  printTitle: string;
  tabs: PanelTab[];
  // Ruta de la API: cada pestaña es {basePath}/{key}
  basePath: string;
  // Sin ruta de descarga no se ofrece el Excel
  exportPath?: string;
  filename: string;
}

const dateLabel = (value: string) => new Intl.DateTimeFormat('es-PE', { day: '2-digit', month: 'long', year: 'numeric' }).format(new Date(`${value}T12:00:00`));

/**
 * Pantalla de un panel: periodo, descargas, pestañas y la sección elegida.
 * La usan la consola de la plataforma y cada academia.
 */
export const PanelPage: React.FC<PanelPageProps> = ({ title, subtitle, printTitle, tabs, basePath, exportPath, filename }) => {
  const [section, setSection] = useState(tabs[0]?.key ?? '');
  const [preset, setPreset] = useState<PeriodPreset>('this_month');
  const [period, setPeriod] = useState<Period>(() => presetPeriod('this_month'));
  const [data, setData] = useState<PanelSection | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [attempt, setAttempt] = useState(0);
  // Todas las secciones, para el PDF
  const [printable, setPrintable] = useState<{ key: string; data: PanelSection }[] | null>(null);
  const [printing, setPrinting] = useState(false);

  const load = useCallback(async (key: string, range: Period) => (await api.get(`${basePath}/${key}`, { params: range })).data.data as PanelSection, [basePath]);

  useEffect(() => {
    if (!section) return;
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
  }, [section, period, attempt, load]);

  // El PDF lo arma la ventana de impresión del navegador con todas las secciones a la vez
  const print = useCallback(async () => {
    setPrinting(true);
    try {
      const all = await Promise.all(tabs.map(async ({ key }) => ({ key, data: await load(key, period) })));
      setPrintable(all);
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo preparar el PDF.'));
      setPrinting(false);
    }
  }, [period, tabs, load]);

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
              <h1 className="text-lg font-bold tracking-tight text-slate-900">{title}</h1>
              <p className="text-[13px] text-slate-500">{subtitle}</p>
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
            <PanelDownloads exportPath={exportPath} period={period} filename={filename} onPrint={() => void print()} printing={printing} />
          </div>
        </div>
      </Card>

      {tabs.length > 1 && (
        <div role="tablist" aria-label="Secciones del panel" className="inline-flex flex-wrap gap-1 p-1 mb-5 rounded-xl bg-white border border-slate-200 shadow-xs">
          {tabs.map(({ key, label, icon: Icon }) => (
            <button
              key={key}
              type="button"
              role="tab"
              aria-selected={section === key}
              onClick={() => {
                // La misma pestaña no se vuelve a pedir: no se borra lo que ya se ve
                if (key === section) return;
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
      )}

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
            <h1 className="text-xl font-bold text-slate-900">{printTitle}</h1>
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
