import React, { useState } from 'react';
import { ArrowDownRight, ArrowUpRight, CalendarRange, FileSpreadsheet, FileText } from 'lucide-react';
import { api, apiErrorMessage } from '../api/client';
import { Alert, Button, Card, INPUT_CLASS } from './index';
import { BarChart, DonutChart, HBarChart } from './charts';
import {
  comparison,
  formatValue,
  PERIOD_PRESETS,
  presetPeriod,
  type PanelCard,
  type PanelChart,
  type PanelSection,
  type Period,
  type PeriodPreset,
} from './panelData';

const TOP_BORDER: Record<PanelCard['tone'], string> = {
  brand: 'border-t-brand-600',
  success: 'border-t-emerald-500',
  warning: 'border-t-amber-500',
  danger: 'border-t-rose-500',
  info: 'border-t-accent-500',
  violet: 'border-t-violet-500',
};

const VALUE_COLOR: Record<PanelCard['tone'], string> = {
  brand: 'text-slate-900',
  success: 'text-slate-900',
  warning: 'text-slate-900',
  danger: 'text-rose-700',
  info: 'text-slate-900',
  violet: 'text-slate-900',
};

const KpiCard: React.FC<{ card: PanelCard }> = ({ card }) => {
  const change = comparison(card.value, card.previous);
  const up = card.previous !== null && card.value > card.previous;
  const down = card.previous !== null && card.value < card.previous;

  return (
    <div className={`bg-white rounded-2xl border border-slate-200/80 border-t-4 ${TOP_BORDER[card.tone]} shadow-card px-4 py-3.5 text-center break-inside-avoid`}>
      <p className="text-[12px] font-medium text-slate-600 leading-tight min-h-[2em] flex items-center justify-center">{card.label}</p>
      <p className={`mt-1 text-[22px] font-bold tnum tracking-tight ${VALUE_COLOR[card.tone]}`}>{formatValue(card.value, card.format, card.currency)}</p>
      {card.hint && <p className="mt-0.5 text-[11px] text-slate-500 leading-snug">{card.hint}</p>}
      {change && (
        <p className="mt-1 inline-flex items-center gap-0.5 text-[11px] font-medium text-slate-600 tnum">
          {up && <ArrowUpRight className="w-3.5 h-3.5 text-emerald-600" aria-hidden="true" />}
          {down && <ArrowDownRight className="w-3.5 h-3.5 text-rose-600" aria-hidden="true" />}
          {change}
        </p>
      )}
    </div>
  );
};

const ChartCard: React.FC<{ chart: PanelChart; wide: boolean }> = ({ chart, wide }) => {
  const format = (value: number) => formatValue(value, chart.format, chart.currency);

  return (
    <Card className={`p-5 break-inside-avoid ${wide ? 'lg:col-span-2' : ''}`}>
      <h3 className="text-[12px] font-bold uppercase tracking-wide text-slate-700">{chart.title}</h3>
      {chart.note && <p className="text-[11px] text-slate-500 mt-0.5">{chart.note}</p>}
      <div className="mt-4">
        {chart.type === 'donut' && <DonutChart title={chart.title} items={chart.items} format={format} />}
        {chart.type === 'bar' && <BarChart title={chart.title} items={chart.items} format={format} integer={chart.format === 'number'} />}
        {chart.type === 'hbar' && <HBarChart title={chart.title} items={chart.items} format={format} max={chart.format === 'percent' ? 100 : undefined} />}
      </div>
    </Card>
  );
};

const dateFormat = new Intl.DateTimeFormat('es-PE', { day: '2-digit', month: 'short', year: 'numeric' });
const dateTimeFormat = new Intl.DateTimeFormat('es-PE', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });

const Cell: React.FC<{ value: string | number | null; format: string; currency: string | null }> = ({ value, format, currency }) => {
  if (value === null || value === undefined || value === '') return <span className="text-slate-400">—</span>;
  // Una fecha sin hora (AAAA-MM-DD) se lee al mediodía, para que la zona horaria no la cambie de día
  if (format === 'date') return <>{dateFormat.format(new Date(/^\d{4}-\d{2}-\d{2}$/.test(String(value)) ? `${value}T12:00:00` : String(value)))}</>;
  if (format === 'datetime') return <>{dateTimeFormat.format(new Date(String(value)))}</>;
  if (format === 'number' || format === 'money') return <span className="tnum">{formatValue(Number(value), format, currency)}</span>;
  return <>{value}</>;
};

/** Una sección de panel completa: tarjetas, gráficos y tabla. */
export const PanelView: React.FC<{ section: PanelSection }> = ({ section }) => {
  const { cards, charts, table } = section;

  return (
    <div className="space-y-5">
      <div className="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-3">
        {cards.map((card) => (
          <KpiCard key={card.label} card={card} />
        ))}
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
        {charts.map((chart, index) => (
          // Las columnas en el tiempo van a lo ancho; con un número impar de gráficos, el último también
          <ChartCard key={chart.title} chart={chart} wide={chart.type === 'bar' || (charts.length % 2 === 1 && index === charts.length - 1)} />
        ))}
      </div>

      {table && (
        <Card className="p-5 break-inside-avoid">
          <h3 className="text-[12px] font-bold uppercase tracking-wide text-slate-700 mb-3">{table.title}</h3>
          {table.rows.length === 0 ? (
            <p className="py-6 text-center text-[13px] text-slate-500">{table.empty ?? 'Sin datos.'}</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-[12.5px]">
                <thead>
                  <tr className="border-b border-slate-200 bg-slate-50">
                    {table.columns.map((column) => (
                      <th key={column.key} scope="col" className={`px-3 py-2 text-[11px] font-semibold uppercase tracking-wide text-slate-600 ${column.format === 'number' || column.format === 'money' ? 'text-right' : 'text-left'}`}>
                        {column.label}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {table.rows.map((row, index) => (
                    <tr key={index} className="border-b border-slate-100 last:border-0">
                      {table.columns.map((column) => (
                        <td key={column.key} className={`px-3 py-2.5 text-slate-800 ${column.format === 'number' || column.format === 'money' ? 'text-right' : ''}`}>
                          <Cell value={row[column.key]} format={column.format} currency={(row.currency as string | null) ?? null} />
                        </td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      )}
    </div>
  );
};

/** Elección del periodo: predefinidos o de una fecha a otra. */
export const PeriodPicker: React.FC<{ preset: PeriodPreset; period: Period; onChange: (preset: PeriodPreset, period: Period) => void }> = ({ preset, period, onChange }) => (
  <div className="flex flex-wrap items-center gap-2">
    <label className="inline-flex items-center gap-2 h-9 pl-3 pr-1 rounded-lg border border-slate-200 bg-white text-[13px] text-slate-700">
      <CalendarRange className="w-4 h-4 text-brand-600" aria-hidden="true" />
      <span className="sr-only">Periodo</span>
      <select
        className="h-8 bg-transparent font-semibold text-slate-800 focus:outline-none cursor-pointer"
        value={preset}
        onChange={(event) => {
          const value = event.target.value as PeriodPreset;
          onChange(value, value === 'custom' ? period : presetPeriod(value));
        }}
      >
        {PERIOD_PRESETS.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </select>
    </label>
    {preset === 'custom' && (
      <>
        <input type="date" aria-label="Desde" className={`${INPUT_CLASS} h-9 w-auto`} value={period.from} max={period.to} onChange={(event) => event.target.value && onChange('custom', { ...period, from: event.target.value })} />
        <span className="text-[13px] text-slate-500">a</span>
        <input type="date" aria-label="Hasta" className={`${INPUT_CLASS} h-9 w-auto`} value={period.to} min={period.from} onChange={(event) => event.target.value && onChange('custom', { ...period, to: event.target.value })} />
      </>
    )}
  </div>
);

/**
 * Descargas del panel: el Excel con los datos de todas las secciones (sirve
 * para una hoja de cálculo o Power BI) y el PDF con los gráficos, que arma la
 * ventana de impresión del navegador («Guardar como PDF»).
 */
export const PanelDownloads: React.FC<{ exportPath?: string; period: Period; filename: string; onPrint: () => void; printing: boolean }> = ({
  exportPath,
  period,
  filename,
  onPrint,
  printing,
}) => {
  const [downloading, setDownloading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const excel = async () => {
    if (!exportPath) return;
    setDownloading(true);
    setError(null);
    try {
      const response = await api.get(exportPath, { params: period, responseType: 'blob' });
      const url = URL.createObjectURL(response.data);
      const link = document.createElement('a');
      link.href = url;
      link.download = `${filename}-${period.from}-al-${period.to}.xlsx`;
      link.click();
      URL.revokeObjectURL(url);
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo descargar el Excel.'));
    } finally {
      setDownloading(false);
    }
  };

  return (
    <div className="flex flex-col items-end gap-2">
      <div className="flex flex-wrap gap-2">
        {exportPath && (
          <Button icon={<FileSpreadsheet className="w-4 h-4" />} processing={downloading} onClick={() => void excel()}>
            Excel (datos)
          </Button>
        )}
        <Button icon={<FileText className="w-4 h-4" />} processing={printing} onClick={onPrint}>
          PDF (gráficos)
        </Button>
      </div>
      {error && <Alert>{error}</Alert>}
    </div>
  );
};
