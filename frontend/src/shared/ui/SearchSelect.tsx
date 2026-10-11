import React, { useEffect, useId, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Check, ChevronDown, Search, X } from 'lucide-react';

interface Option {
  value: string;
  label: string;
  disabled: boolean;
}

interface SearchSelectProps {
  value: string | number;
  // Misma forma que la de un <select>, para poder reemplazarlo sin tocar a quien lo usa
  onChange: (event: React.ChangeEvent<HTMLSelectElement>) => void;
  // Las opciones se escriben igual que en un <select>: <option value="…">Texto</option>
  children: React.ReactNode;
  className?: string;
  disabled?: boolean;
  'aria-label'?: string;
}

// Sin mayúsculas ni tildes: «matricula» encuentra «Matrícula»
const normalize = (text: string) =>
  text
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase();

function textOf(node: React.ReactNode): string {
  if (node === null || node === undefined || typeof node === 'boolean') return '';
  if (typeof node === 'string' || typeof node === 'number') return String(node);
  if (Array.isArray(node)) return node.map(textOf).join('');
  if (React.isValidElement<{ children?: React.ReactNode }>(node)) return textOf(node.props.children);
  return '';
}

// Lee los <option> que recibió, también dentro de fragmentos y grupos
function optionsFrom(children: React.ReactNode): Option[] {
  const found: Option[] = [];

  React.Children.forEach(children, (child) => {
    if (!React.isValidElement<{ value?: string | number; disabled?: boolean; children?: React.ReactNode }>(child)) return;

    if (child.type === 'option') {
      const label = textOf(child.props.children);
      found.push({ value: String(child.props.value ?? label), label, disabled: Boolean(child.props.disabled) });
    } else {
      found.push(...optionsFrom(child.props.children));
    }
  });

  return found;
}

// Las clases de tamaño y de ubicación van en el marco; las de aspecto, en el campo
const LAYOUT_CLASS = /^([a-z0-9]+:)?!?(w-|min-w-|max-w-|m[trblxy]?-|flex-|grow|shrink|basis-)/;

// Filtro en el que se escribe: se hace clic en el campo, se escribe parte del texto y la lista
// de abajo se reduce. Con un filtro puesto, el campo se pinta y muestra una × para quitarlo.
// Con teclado: flechas para moverse, Enter para elegir, Escape para cerrar.
export const SearchSelect: React.FC<SearchSelectProps> = ({ value, onChange, children, className = '', disabled = false, 'aria-label': ariaLabel }) => {
  const options = useMemo(() => optionsFrom(children), [children]);
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const [active, setActive] = useState(0);
  const [place, setPlace] = useState<{ left: number; top: number; width: number; up: boolean } | null>(null);
  const frame = useRef<HTMLDivElement>(null);
  const list = useRef<HTMLUListElement>(null);
  const listId = useId();

  const current = String(value);
  const selected = options.find((option) => option.value === current);
  // La primera opción es la que no filtra («Todos…»): con otra elegida, el filtro está puesto
  const filtering = options.length > 0 && current !== options[0].value;
  const matches = useMemo(() => {
    const wanted = normalize(query.trim());
    return wanted === '' ? options : options.filter((option) => normalize(option.label).includes(wanted));
  }, [options, query]);

  const classes = className.split(/\s+/).filter(Boolean);
  const frameClass = classes.filter((name) => LAYOUT_CLASS.test(name)).join(' ');
  const fieldClass = classes.filter((name) => !LAYOUT_CLASS.test(name)).join(' ');

  const show = (typed = '') => {
    if (disabled) return;
    setQuery(typed);
    setActive(typed === '' ? Math.max(0, options.findIndex((option) => option.value === current)) : 0);
    setOpen(true);
  };

  const pick = (option: Option) => {
    if (option.disabled) return;
    setOpen(false);
    if (option.value !== current) onChange({ target: { value: option.value } } as React.ChangeEvent<HTMLSelectElement>);
  };

  // La lista va fija sobre la ventana, pegada al campo (o encima, si abajo no cabe)
  useLayoutEffect(() => {
    if (!open || !frame.current) return;

    const locate = () => {
      const rect = frame.current!.getBoundingClientRect();
      const width = Math.min(Math.max(rect.width, 220), window.innerWidth - 16);
      const up = window.innerHeight - rect.bottom < 280 && rect.top > 280;
      setPlace({ left: Math.max(8, Math.min(rect.left, window.innerWidth - width - 8)), top: up ? rect.top - 4 : rect.bottom + 4, width, up });
    };

    locate();
    window.addEventListener('resize', locate);
    window.addEventListener('scroll', locate, true);
    return () => {
      window.removeEventListener('resize', locate);
      window.removeEventListener('scroll', locate, true);
    };
  }, [open]);

  // La opción señalada con el teclado siempre queda a la vista
  useEffect(() => {
    if (open) list.current?.querySelector(`[data-index="${active}"]`)?.scrollIntoView({ block: 'nearest' });
  }, [open, active]);

  const onKeyDown = (event: React.KeyboardEvent<HTMLInputElement>) => {
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      if (!open) show();
      else if (matches.length > 0) setActive((index) => (index + (event.key === 'ArrowDown' ? 1 : -1) + matches.length) % matches.length);
    } else if (event.key === 'Enter') {
      if (!open) return;
      event.preventDefault();
      if (matches[active]) pick(matches[active]);
    } else if (event.key === 'Escape' && open) {
      event.preventDefault();
      event.stopPropagation();
      setOpen(false);
    }
  };

  return (
    <div ref={frame} className={`relative inline-flex ${frameClass}`}>
      <Search className={`w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none transition-colors ${open || filtering ? 'text-brand-600' : 'text-slate-400'}`} />
      <input
        type="text"
        role="combobox"
        aria-label={ariaLabel}
        aria-expanded={open}
        aria-controls={open ? listId : undefined}
        aria-autocomplete="list"
        aria-activedescendant={open && matches[active] ? `${listId}-${active}` : undefined}
        autoComplete="off"
        spellCheck={false}
        disabled={disabled}
        // Cerrado muestra lo elegido; abierto, lo que la persona escribe para buscar
        value={open ? query : (selected?.label ?? '')}
        placeholder={open ? (selected?.label ?? 'Buscar…') : undefined}
        onChange={(event) => {
          // Cerrado y con el cursor dentro (después de elegir): la primera letra vuelve a abrir la lista
          if (!open) show((event.nativeEvent as InputEvent).data ?? '');
          else {
            setQuery(event.target.value);
            setActive(0);
          }
        }}
        onMouseDown={() => !open && show()}
        onBlur={() => setOpen(false)}
        onKeyDown={onKeyDown}
        className={`${fieldClass} !w-full !pl-9 truncate ${open ? 'cursor-text' : 'cursor-pointer'} ${
          filtering && !open ? '!pr-14 !border-brand-500 !bg-brand-50/70 font-semibold !text-brand-900' : '!pr-8'
        }`}
      />
      {filtering && !open && !disabled && (
        <button
          type="button"
          aria-label={ariaLabel ? `Quitar filtro: ${ariaLabel}` : 'Quitar filtro'}
          title="Quitar este filtro"
          // Con mousedown, para que el campo no llegue a abrirse
          onMouseDown={(event) => {
            event.preventDefault();
            pick(options[0]);
          }}
          className="absolute right-7 top-1/2 -translate-y-1/2 p-0.5 rounded-full text-brand-700 hover:bg-brand-100 cursor-pointer"
        >
          <X className="w-3.5 h-3.5" />
        </button>
      )}
      <ChevronDown className={`w-4 h-4 absolute right-2.5 top-1/2 -translate-y-1/2 pointer-events-none text-slate-500 transition-transform ${open ? 'rotate-180' : ''}`} />

      {open &&
        place &&
        // Fuera del árbol de la pantalla: una tarjeta con animación o con recorte movería o cortaría la lista
        createPortal(
          <ul
            ref={list}
            id={listId}
            role="listbox"
            aria-label={ariaLabel}
            style={{ left: place.left, top: place.top, width: place.width, transform: place.up ? 'translateY(-100%)' : undefined }}
            className="fixed z-50 max-h-64 overflow-y-auto p-1 bg-white border border-slate-200 rounded-xl shadow-pop"
          >
            {matches.length === 0 && <li className="px-3 py-2.5 text-[13px] text-slate-500">Nada coincide con «{query.trim()}»</li>}
            {matches.map((option, index) => {
              const isSelected = option.value === current;
              return (
                <li
                  key={option.value}
                  id={`${listId}-${index}`}
                  data-index={index}
                  role="option"
                  aria-selected={isSelected}
                  aria-disabled={option.disabled}
                  onMouseEnter={() => setActive(index)}
                  // Con mousedown, para elegir antes de que el campo pierda el foco y cierre la lista
                  onMouseDown={(event) => {
                    event.preventDefault();
                    pick(option);
                  }}
                  className={`flex items-center justify-between gap-3 px-2.5 py-2 rounded-lg text-[13px] cursor-pointer ${index === active ? 'bg-brand-50' : ''} ${
                    isSelected ? 'font-semibold text-brand-800' : 'text-slate-800'
                  } ${option.disabled ? 'opacity-50 cursor-not-allowed' : ''}`}
                >
                  <span className="break-words min-w-0">{option.label}</span>
                  {isSelected && <Check className="w-4 h-4 shrink-0 text-brand-600" />}
                </li>
              );
            })}
          </ul>,
          document.body,
        )}
    </div>
  );
};
