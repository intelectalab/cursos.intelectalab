import React, { useState } from 'react';
import { CalendarRange, FilterX } from 'lucide-react';
import { Button, INPUT_CLASS } from './index';

const pad = (value: number) => String(value).padStart(2, '0');
const day = (date: Date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;

type Preset = 'TODAY' | 'LAST_7' | 'THIS_MONTH' | 'LAST_MONTH';

const PRESETS: Record<Preset, string> = {
  TODAY: 'Hoy',
  LAST_7: 'Últimos 7 días',
  THIS_MONTH: 'Este mes',
  LAST_MONTH: 'Mes pasado',
};

function rangeOf(preset: Preset): { from: string; to: string } {
  const today = new Date();
  switch (preset) {
    case 'TODAY':
      return { from: day(today), to: day(today) };
    case 'LAST_7':
      return { from: day(new Date(today.getFullYear(), today.getMonth(), today.getDate() - 6)), to: day(today) };
    case 'THIS_MONTH':
      return { from: day(new Date(today.getFullYear(), today.getMonth(), 1)), to: day(today) };
    case 'LAST_MONTH':
      return { from: day(new Date(today.getFullYear(), today.getMonth() - 1, 1)), to: day(new Date(today.getFullYear(), today.getMonth(), 0)) };
  }
}

/**
 * Filtro de fechas de los listados: hoy, últimos 7 días, este mes, mes
 * pasado o un rango a mano. Los días se mandan como AAAA-MM-DD.
 */
export const DateRangeFilter: React.FC<{
  from: string;
  to: string;
  onChange: (range: { from: string; to: string }) => void;
  label: string;
}> = ({ from, to, onChange, label }) => {
  const matched = (Object.keys(PRESETS) as Preset[]).find((preset) => {
    const range = rangeOf(preset);
    return range.from === from && range.to === to;
  });
  const [custom, setCustom] = useState(false);
  const selected = !from && !to ? '' : matched && !custom ? matched : 'CUSTOM';

  return (
    <div className="flex flex-wrap items-center gap-2">
      <div className="relative">
        <CalendarRange className="w-4 h-4 text-slate-500 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" />
        <select
          className={`${INPUT_CLASS} !w-48 pl-9`}
          aria-label={label}
          value={selected}
          onChange={(event) => {
            const value = event.target.value;
            setCustom(value === 'CUSTOM');
            if (value === '') onChange({ from: '', to: '' });
            else if (value !== 'CUSTOM') onChange(rangeOf(value as Preset));
          }}
        >
          <option value="">{label}: todas</option>
          {(Object.keys(PRESETS) as Preset[]).map((preset) => (
            <option key={preset} value={preset}>
              {PRESETS[preset]}
            </option>
          ))}
          <option value="CUSTOM">Elegir fechas…</option>
        </select>
      </div>
      {selected === 'CUSTOM' && (
        <>
          <input className={`${INPUT_CLASS} !w-40`} type="date" aria-label={`${label}: desde`} value={from} max={to || undefined} onChange={(event) => onChange({ from: event.target.value, to })} />
          <span className="text-xs text-slate-500">a</span>
          <input className={`${INPUT_CLASS} !w-40`} type="date" aria-label={`${label}: hasta`} value={to} min={from || undefined} onChange={(event) => onChange({ from, to: event.target.value })} />
        </>
      )}
    </div>
  );
};

/** «Quitar filtros»: aparece solo cuando hay alguno puesto. */
export const ClearFilters: React.FC<{ active: boolean; onClear: () => void }> = ({ active, onClear }) =>
  active ? (
    <Button size="sm" variant="ghost" icon={<FilterX className="w-4 h-4" />} onClick={onClear}>
      Quitar filtros
    </Button>
  ) : null;
