// Formato de una sección de panel tal como la entrega la API (App\Shared\Dashboard\Panel):
// tarjetas con cifras, gráficos y una tabla. Lo usan la consola de plataforma y cada academia.

export type PanelFormat = 'number' | 'money' | 'percent';
export type PanelTone = 'brand' | 'success' | 'warning' | 'danger' | 'info' | 'violet';

export interface PanelCard {
  label: string;
  value: number;
  format: PanelFormat;
  tone: PanelTone;
  hint: string | null;
  // Lo mismo en el periodo anterior, para comparar
  previous: number | null;
  currency: string | null;
}

export interface PanelChart {
  title: string;
  // donut: partes de un todo · bar: columnas en el tiempo · hbar: ranking
  type: 'donut' | 'bar' | 'hbar';
  format: PanelFormat;
  currency: string | null;
  note: string | null;
  items: { label: string; value: number }[];
}

export interface PanelTable {
  title: string;
  columns: { key: string; label: string; format: 'text' | 'number' | 'money' | 'date' | 'datetime' }[];
  rows: Record<string, string | number | null>[];
  empty: string | null;
}

export interface PanelSection {
  title: string;
  cards: PanelCard[];
  charts: PanelChart[];
  table: PanelTable | null;
}

export interface Period {
  from: string;
  to: string;
}

export type PeriodPreset = 'this_month' | 'last_month' | 'last_3_months' | 'this_year' | 'custom';

export const PERIOD_PRESETS: { value: PeriodPreset; label: string }[] = [
  { value: 'this_month', label: 'Este mes' },
  { value: 'last_month', label: 'Mes anterior' },
  { value: 'last_3_months', label: 'Últimos 3 meses' },
  { value: 'this_year', label: 'Este año' },
  { value: 'custom', label: 'Personalizado' },
];

const day = (date: Date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;

// Fechas de cada periodo predefinido, en la hora del navegador
export function presetPeriod(preset: Exclude<PeriodPreset, 'custom'>, today = new Date()): Period {
  const y = today.getFullYear();
  const m = today.getMonth();
  switch (preset) {
    case 'last_month':
      return { from: day(new Date(y, m - 1, 1)), to: day(new Date(y, m, 0)) };
    case 'last_3_months':
      return { from: day(new Date(y, m - 2, 1)), to: day(today) };
    case 'this_year':
      return { from: day(new Date(y, 0, 1)), to: day(today) };
    default:
      return { from: day(new Date(y, m, 1)), to: day(today) };
  }
}

const number = new Intl.NumberFormat('es-PE', { maximumFractionDigits: 1 });
const money = new Intl.NumberFormat('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const SYMBOLS: Record<string, string> = { PEN: 'S/', USD: 'US$' };

export function formatValue(value: number | null | undefined, format: PanelFormat | 'text' | 'date' | 'datetime', currency?: string | null): string {
  if (value === null || value === undefined) return '—';
  if (format === 'money') return `${SYMBOLS[currency ?? 'PEN'] ?? currency ?? ''} ${money.format(value)}`;
  if (format === 'percent') return `${number.format(value)} %`;
  return number.format(value);
}

// Variación frente al periodo anterior, en palabras cortas
export function comparison(value: number, previous: number | null): string | null {
  if (previous === null) return null;
  if (previous === 0) return value === 0 ? 'Igual que el periodo anterior' : 'Sin datos en el periodo anterior';
  const change = ((value - previous) / Math.abs(previous)) * 100;
  if (Math.abs(change) < 0.5) return 'Igual que el periodo anterior';
  return `${change > 0 ? '+' : '−'}${number.format(Math.abs(change))} % vs. periodo anterior`;
}
