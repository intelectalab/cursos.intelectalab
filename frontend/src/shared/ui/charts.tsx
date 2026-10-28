import React, { useState } from 'react';

// Paleta categórica validada para daltonismo (orden fijo: el color sigue a la categoría, nunca se repite).
// Tres tonos claros quedan bajo 3:1 sobre blanco: por eso cada gráfico de partes lleva leyenda con los valores.
const SERIES_COLORS = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948'];
// Una sola serie (columnas en el tiempo, ranking): el color principal de la academia
const SINGLE = 'var(--color-brand-600)';

export interface ChartItem {
  label: string;
  value: number;
}

// Tope del eje en un número redondo (1, 2 o 5 por una potencia de 10)
function niceMax(value: number): number {
  if (value <= 0) return 1;
  const power = 10 ** Math.floor(Math.log10(value));
  const step = [1, 2, 5, 10].find((candidate) => candidate * power >= value) ?? 10;
  return step * power;
}

// Tabla para lectores de pantalla: los valores exactos de cualquier gráfico
const ChartTable: React.FC<{ title: string; items: ChartItem[]; format: (value: number) => string }> = ({ title, items, format }) => (
  <table className="sr-only">
    <caption>{title}</caption>
    <tbody>
      {items.map((item) => (
        <tr key={item.label}>
          <th scope="row">{item.label}</th>
          <td>{format(item.value)}</td>
        </tr>
      ))}
    </tbody>
  </table>
);

/**
 * Partes de un todo. Más de 8 partes se juntan en «Otros». La leyenda lleva el
 * valor y el porcentaje de cada parte, así que el color nunca es lo único que
 * identifica a una.
 */
export const DonutChart: React.FC<{ title: string; items: ChartItem[]; format: (value: number) => string }> = ({ title, items, format }) => {
  const [hover, setHover] = useState<number | null>(null);
  const sorted = items.filter((item) => item.value > 0);
  const parts = sorted.length > 8 ? [...sorted.slice(0, 7), { label: 'Otros', value: sorted.slice(7).reduce((sum, item) => sum + item.value, 0) }] : sorted;
  const total = parts.reduce((sum, item) => sum + item.value, 0);

  if (total <= 0) return <p className="py-10 text-center text-[13px] text-slate-500">Sin datos en este periodo.</p>;

  const size = 168;
  const stroke = 26;
  const radius = (size - stroke) / 2;
  const circumference = 2 * Math.PI * radius;
  // Separación de 2 px entre partes, salvo que haya una sola
  const gap = parts.length > 1 ? 2 : 0;
  // Dónde empieza cada parte, en el largo del círculo
  const starts = parts.map((_, index) => parts.slice(0, index).reduce((sum, part) => sum + (part.value / total) * circumference, 0));
  const active = hover !== null ? parts[hover] : null;

  return (
    <div className="flex flex-col sm:flex-row items-center gap-5">
      <div className="relative shrink-0" style={{ width: size, height: size }} onMouseLeave={() => setHover(null)}>
        <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} role="img" aria-label={`${title}: gráfico de partes. Los valores están en la leyenda.`}>
          <g transform={`rotate(-90 ${size / 2} ${size / 2})`}>
            {parts.map((part, index) => {
              const length = (part.value / total) * circumference;
              const dash = Math.max(length - gap, 0.5);
              return (
                <circle
                  key={part.label}
                  cx={size / 2}
                  cy={size / 2}
                  r={radius}
                  fill="none"
                  stroke={SERIES_COLORS[index % SERIES_COLORS.length]}
                  strokeWidth={hover === index ? stroke + 4 : stroke}
                  strokeDasharray={`${dash} ${circumference - dash}`}
                  strokeDashoffset={-starts[index]}
                  opacity={hover !== null && hover !== index ? 0.35 : 1}
                  onMouseEnter={() => setHover(index)}
                  className="transition-opacity cursor-default"
                />
              );
            })}
          </g>
        </svg>
        <div className="absolute inset-0 flex flex-col items-center justify-center pointer-events-none text-center px-6">
          <span className="text-[11px] text-slate-500 leading-tight line-clamp-2">{active ? active.label : 'Total'}</span>
          <span className="text-[15px] font-bold text-slate-900 tnum">{format(active ? active.value : total)}</span>
          {active && <span className="text-[11px] text-slate-500 tnum">{Math.round((active.value / total) * 100)} %</span>}
        </div>
      </div>
      <ul className="w-full min-w-0 space-y-1.5">
        {parts.map((part, index) => (
          <li
            key={part.label}
            className={`flex items-center gap-2 rounded-md px-1.5 py-1 text-[12px] ${hover === index ? 'bg-slate-100' : ''}`}
            onMouseEnter={() => setHover(index)}
            onMouseLeave={() => setHover(null)}
          >
            <span className="w-2.5 h-2.5 rounded-sm shrink-0" style={{ background: SERIES_COLORS[index % SERIES_COLORS.length] }} aria-hidden="true" />
            <span className="flex-1 min-w-0 truncate text-slate-700">{part.label}</span>
            <span className="font-semibold text-slate-900 tnum">{format(part.value)}</span>
            <span className="w-10 text-right text-slate-500 tnum">{Math.round((part.value / total) * 100)} %</span>
          </li>
        ))}
      </ul>
    </div>
  );
};

/**
 * Columnas de una sola serie en el tiempo. El título dice qué se mide; el
 * valor exacto sale al pasar el cursor.
 */
export const BarChart: React.FC<{ title: string; items: ChartItem[]; format: (value: number) => string; integer?: boolean }> = ({ title, items, format, integer = false }) => {
  const [hover, setHover] = useState<number | null>(null);
  const peak = Math.max(...items.map((item) => item.value), 0);
  const nice = niceMax(peak);
  const top = integer ? Math.max(nice + (nice % 2), 2) : nice;
  const ticks = [top, top / 2, 0];
  const active = hover !== null ? items[hover] : null;
  // Con muchas columnas, una etiqueta de por medio para que no se pisen
  const every = items.length > 8 ? 2 : 1;

  return (
    <div>
      <div className="flex gap-3">
        <div className="flex flex-col justify-between h-48 text-[11px] text-slate-500 tnum text-right shrink-0" aria-hidden="true">
          {ticks.map((tick) => (
            <span key={tick} className="leading-none">
              {format(tick)}
            </span>
          ))}
        </div>
        <div className="flex-1 min-w-0">
          <div className="relative h-48" onMouseLeave={() => setHover(null)}>
            <div className="absolute inset-0 flex flex-col justify-between pointer-events-none" aria-hidden="true">
              {ticks.map((tick) => (
                <span key={tick} className={`block h-px ${tick === 0 ? 'bg-slate-300' : 'bg-slate-200/70'}`} />
              ))}
            </div>
            <div className="absolute inset-0 flex items-end gap-0.5" role="img" aria-label={`${title}: gráfico de columnas. Los valores están en la tabla que sigue.`}>
              {items.map((item, index) => (
                <div key={item.label} className="relative flex-1 h-full flex items-end justify-center" onMouseEnter={() => setHover(index)}>
                  {hover === index && <span className="absolute inset-0 bg-slate-900/[0.04] rounded-sm" />}
                  <span
                    className={`relative block w-full max-w-9 rounded-t transition-opacity ${hover !== null && hover !== index ? 'opacity-45' : ''}`}
                    style={{ height: `${(item.value / top) * 100}%`, minHeight: item.value > 0 ? 2 : 0, background: SINGLE }}
                  />
                </div>
              ))}
            </div>
            {active && hover !== null && (
              <div
                className="absolute -top-2 z-10 -translate-y-full px-2.5 py-1.5 rounded-lg bg-slate-900 text-white shadow-pop pointer-events-none whitespace-nowrap"
                style={hover < items.length / 2 ? { left: `${(hover / items.length) * 100}%` } : { right: `${((items.length - hover - 1) / items.length) * 100}%` }}
                role="status"
              >
                <p className="text-[11px] text-slate-300">{active.label}</p>
                <p className="text-[13px] font-semibold tnum">{format(active.value)}</p>
              </div>
            )}
          </div>
          <div className="flex gap-0.5 mt-1.5 text-[10px] text-slate-500" aria-hidden="true">
            {items.map((item, index) => (
              <span key={item.label} className="flex-1 min-w-0 text-center truncate">
                {index % every === (items.length - 1) % every ? item.label : ''}
              </span>
            ))}
          </div>
        </div>
      </div>
      <ChartTable title={title} items={items} format={format} />
    </div>
  );
};

/**
 * Ranking: barras horizontales de una sola serie, de mayor a menor, con el
 * valor escrito al final de cada una.
 */
export const HBarChart: React.FC<{ title: string; items: ChartItem[]; format: (value: number) => string; max?: number }> = ({ title, items, format, max }) => {
  if (items.length === 0) return <p className="py-10 text-center text-[13px] text-slate-500">Sin datos en este periodo.</p>;
  const top = max ?? Math.max(...items.map((item) => item.value), 0);

  return (
    <div>
      <ul className="space-y-2.5" aria-label={title}>
        {items.map((item) => (
          <li key={item.label} className="grid grid-cols-[minmax(0,9rem)_1fr_auto] sm:grid-cols-[minmax(0,12rem)_1fr_auto] items-center gap-3 text-[12px]">
            <span className="truncate text-slate-700" title={item.label}>
              {item.label}
            </span>
            <span className="h-3 rounded-r bg-slate-100 overflow-hidden" aria-hidden="true">
              <span className="block h-full rounded-r" style={{ width: `${top > 0 ? Math.min(100, (item.value / top) * 100) : 0}%`, minWidth: item.value > 0 ? 2 : 0, background: SINGLE }} />
            </span>
            <span className="font-semibold text-slate-900 tnum text-right">{format(item.value)}</span>
          </li>
        ))}
      </ul>
      <ChartTable title={title} items={items} format={format} />
    </div>
  );
};
