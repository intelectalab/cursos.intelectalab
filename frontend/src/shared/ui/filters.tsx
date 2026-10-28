import React, { useEffect, useId, useRef, useState } from 'react';
import { CircleHelp, X, type LucideIcon } from 'lucide-react';
import { CHECKBOX_CLASS, type Tone } from './index';

/* ───────────────────────────── Ayuda ───────────────────────────── */

// Explica en una frase qué significa un filtro o una cifra. Se abre al pasar el
// cursor, al enfocarlo con el teclado o al tocarlo.
export const HelpTip: React.FC<{ text: string; label?: string; className?: string }> = ({ text, label = 'Qué significa', className = '' }) => {
  const [open, setOpen] = useState(false);
  const id = useId();
  const wrapper = useRef<HTMLSpanElement>(null);

  useEffect(() => {
    if (!open) return;

    const close = (event: MouseEvent | KeyboardEvent) => {
      if (event instanceof KeyboardEvent ? event.key === 'Escape' : !wrapper.current?.contains(event.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', close);
    document.addEventListener('keydown', close);
    return () => {
      document.removeEventListener('mousedown', close);
      document.removeEventListener('keydown', close);
    };
  }, [open]);

  return (
    <span
      ref={wrapper}
      // Con posición propia (absolute) ya es el marco del globo de ayuda
      className={`inline-flex ${className.includes('absolute') ? '' : 'relative'} ${className}`}
      onMouseEnter={() => setOpen(true)}
      onMouseLeave={() => setOpen(false)}
    >
      <button
        type="button"
        aria-label={label}
        aria-describedby={open ? id : undefined}
        aria-expanded={open}
        onClick={(event) => {
          // Dentro de una tarjeta que filtra, pedir ayuda no debe aplicar el filtro
          event.stopPropagation();
          setOpen((current) => !current);
        }}
        onFocus={() => setOpen(true)}
        onBlur={() => setOpen(false)}
        className="p-0.5 rounded-full text-slate-500 hover:text-brand-700 cursor-pointer"
      >
        <CircleHelp className="w-3.5 h-3.5" />
      </button>
      {open && (
        <span
          id={id}
          role="tooltip"
          className={`absolute z-30 top-full ${className.includes('absolute') ? 'right-0' : 'left-1/2 -translate-x-1/2'} mt-1.5 w-56 max-w-[70vw] px-3 py-2 rounded-lg bg-slate-900 text-white text-xs font-normal leading-relaxed text-left shadow-pop normal-case tracking-normal`}
        >
          {text}
        </span>
      )}
    </span>
  );
};

/* ───────────────────────────── Tarjetas que filtran ───────────────────────────── */

const CARD_TONES: Record<Tone, { icon: string; ring: string; bar: string }> = {
  neutral: { icon: 'bg-slate-100 text-slate-600', ring: 'ring-slate-400 border-slate-400', bar: 'bg-slate-400' },
  brand: { icon: 'bg-brand-50 text-brand-700', ring: 'ring-brand-500 border-brand-500', bar: 'bg-gradient-to-r from-brand-700 to-accent-500' },
  success: { icon: 'bg-emerald-50 text-emerald-600', ring: 'ring-emerald-500 border-emerald-500', bar: 'bg-emerald-500' },
  warning: { icon: 'bg-amber-50 text-amber-600', ring: 'ring-amber-500 border-amber-500', bar: 'bg-amber-500' },
  danger: { icon: 'bg-rose-50 text-rose-600', ring: 'ring-rose-500 border-rose-500', bar: 'bg-rose-500' },
  info: { icon: 'bg-accent-50 text-accent-600', ring: 'ring-accent-500 border-accent-500', bar: 'bg-accent-500' },
  violet: { icon: 'bg-violet-50 text-violet-600', ring: 'ring-violet-500 border-violet-500', bar: 'bg-violet-500' },
};

export interface FilterCard {
  // '' = todos
  key: string;
  label: string;
  value: number | null;
  icon: LucideIcon;
  tone: Tone;
  // Qué cuenta la tarjeta, en una frase
  help: string;
}

// Fila de cifras por estado encima de un listado. Tocar una tarjeta filtra el
// listado por ese estado; tocarla otra vez (o "Todos") quita el filtro.
export const FilterCards: React.FC<{
  cards: FilterCard[];
  active: string;
  onSelect: (key: string) => void;
  label: string;
}> = ({ cards, active, onSelect, label }) => {
  const total = cards.find((card) => card.key === '')?.value ?? null;

  return (
    <div role="group" aria-label={label} className="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-6 gap-3 mb-5">
      {cards.map((card) => {
        const selected = active === card.key;
        const tone = CARD_TONES[card.tone];
        const share = total && card.value !== null && card.key !== '' ? Math.round((card.value / total) * 100) : null;

        return (
          <div
            key={card.key}
            className={`relative bg-white border rounded-2xl shadow-card transition-all hover:shadow-raised hover:-translate-y-0.5 ${
              selected ? `ring-2 ${tone.ring}` : 'border-slate-200/80'
            }`}
          >
            <button
              type="button"
              aria-pressed={selected}
              onClick={() => onSelect(selected ? '' : card.key)}
              className="w-full text-left p-4 rounded-2xl cursor-pointer"
            >
              <span className="flex items-start justify-between gap-2">
                <span className={`w-9 h-9 shrink-0 rounded-xl inline-flex items-center justify-center ${tone.icon}`}>
                  <card.icon className="w-[18px] h-[18px]" />
                </span>
                {selected && card.key !== '' && (
                  <span className="inline-flex items-center gap-1 mr-6 text-[11px] font-semibold text-slate-600">
                    Filtrando <X className="w-3 h-3" aria-hidden="true" />
                  </span>
                )}
              </span>
              <span className="block mt-3 text-2xl leading-7 font-bold text-slate-900 tracking-tight tnum">{card.value ?? '—'}</span>
              <span className="block mt-0.5 text-[13px] font-medium text-slate-600">{card.label}</span>
              <span className="block mt-3 h-1 rounded-full bg-slate-100 overflow-hidden" aria-hidden="true">
                <span className={`block h-full rounded-full ${tone.bar}`} style={{ width: `${card.key === '' ? 100 : (share ?? 0)}%` }} />
              </span>
            </button>
            <HelpTip text={card.help} label={`Qué significa «${card.label}»`} className="absolute top-3.5 right-3" />
          </div>
        );
      })}
    </div>
  );
};

/* ───────────────────────────── Selección de varias filas ───────────────────────────── */

// Casilla "seleccionar todo" de una lista: marcada, vacía o a medias
export const SelectAll: React.FC<{
  ids: string[];
  selected: string[];
  onChange: (selected: string[]) => void;
  // Texto visible junto a la casilla; sin él queda solo la casilla (cabecera de tabla)
  children?: React.ReactNode;
  disabled?: boolean;
}> = ({ ids, selected, onChange, children, disabled = false }) => {
  const box = useRef<HTMLInputElement>(null);
  const marked = ids.filter((id) => selected.includes(id)).length;
  const all = ids.length > 0 && marked === ids.length;

  useEffect(() => {
    if (box.current) box.current.indeterminate = marked > 0 && !all;
  }, [marked, all]);

  return (
    <label className={`inline-flex items-center gap-2.5 text-[13px] font-medium text-slate-700 ${disabled || ids.length === 0 ? '' : 'cursor-pointer'}`}>
      <input
        ref={box}
        type="checkbox"
        className={CHECKBOX_CLASS}
        aria-label={children ? undefined : 'Seleccionar todo'}
        disabled={disabled || ids.length === 0}
        checked={all}
        // Conserva lo marcado fuera de esta lista (otra página, otra búsqueda)
        onChange={() => onChange(all ? selected.filter((id) => !ids.includes(id)) : [...new Set([...selected, ...ids])])}
      />
      {children}
    </label>
  );
};

// Casilla de una fila
export const SelectRow: React.FC<{ id: string; label: string; selected: string[]; onChange: (selected: string[]) => void }> = ({
  id,
  label,
  selected,
  onChange,
}) => (
  <input
    type="checkbox"
    className={CHECKBOX_CLASS}
    aria-label={`Seleccionar ${label}`}
    checked={selected.includes(id)}
    onChange={() => onChange(selected.includes(id) ? selected.filter((item) => item !== id) : [...selected, id])}
  />
);

// Barra que aparece al marcar filas, con lo que se puede hacer con todas a la vez
export const BulkBar: React.FC<{ count: number; noun: [string, string]; onClear: () => void; children: React.ReactNode }> = ({
  count,
  noun,
  onClear,
  children,
}) =>
  count === 0 ? null : (
    <div className="flex flex-wrap items-center gap-3 px-5 py-2.5 bg-brand-50/70 border-b border-brand-100" role="region" aria-label="Acciones sobre la selección">
      <span className="text-[13px] font-semibold text-brand-900" role="status">
        {count} {count === 1 ? noun[0] : noun[1]}
      </span>
      <div className="flex flex-wrap items-center gap-2">{children}</div>
      <button type="button" onClick={onClear} className="ml-auto text-[13px] font-semibold text-brand-700 hover:text-brand-900 cursor-pointer">
        Quitar selección
      </button>
    </div>
  );
