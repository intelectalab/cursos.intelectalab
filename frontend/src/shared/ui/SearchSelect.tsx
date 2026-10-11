import React, { useEffect, useId, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Check, ChevronDown, Search } from 'lucide-react';

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

// Desplegable con buscador, para los filtros: se abre, se escribe parte del texto y se elige.
// Con teclado: flechas para moverse, Enter para elegir, Escape para cerrar.
export const SearchSelect: React.FC<SearchSelectProps> = ({ value, onChange, children, className = '', disabled = false, 'aria-label': ariaLabel }) => {
  const options = useMemo(() => optionsFrom(children), [children]);
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const [active, setActive] = useState(0);
  const [place, setPlace] = useState<{ left: number; top: number; width: number; up: boolean } | null>(null);
  const trigger = useRef<HTMLButtonElement>(null);
  const panel = useRef<HTMLDivElement>(null);
  const search = useRef<HTMLInputElement>(null);
  const listId = useId();

  const selected = options.find((option) => option.value === String(value));
  const matches = useMemo(() => {
    const wanted = normalize(query.trim());
    return wanted === '' ? options : options.filter((option) => normalize(option.label).includes(wanted));
  }, [options, query]);

  const show = () => {
    if (disabled) return;
    setQuery('');
    setActive(Math.max(0, options.findIndex((option) => option.value === String(value))));
    setOpen(true);
  };

  const close = (focusTrigger = true) => {
    setOpen(false);
    if (focusTrigger) trigger.current?.focus();
  };

  const choose = (option: Option) => {
    if (option.disabled) return;
    close();
    if (option.value !== String(value)) onChange({ target: { value: option.value } } as React.ChangeEvent<HTMLSelectElement>);
  };

  // La lista va fija sobre la ventana, justo bajo el filtro (o encima, si abajo no cabe)
  useLayoutEffect(() => {
    if (!open || !trigger.current) return;

    const locate = () => {
      const rect = trigger.current!.getBoundingClientRect();
      const width = Math.min(Math.max(rect.width, 240), window.innerWidth - 16);
      const up = window.innerHeight - rect.bottom < 300 && rect.top > 300;
      setPlace({ left: Math.max(8, Math.min(rect.left, window.innerWidth - width - 8)), top: up ? rect.top - 6 : rect.bottom + 6, width, up });
    };

    locate();
    window.addEventListener('resize', locate);
    window.addEventListener('scroll', locate, true);
    return () => {
      window.removeEventListener('resize', locate);
      window.removeEventListener('scroll', locate, true);
    };
  }, [open]);

  useEffect(() => {
    if (!open) return;

    const outside = (event: MouseEvent) => {
      const target = event.target as Node;
      if (!panel.current?.contains(target) && !trigger.current?.contains(target)) close(false);
    };
    document.addEventListener('mousedown', outside);
    return () => document.removeEventListener('mousedown', outside);
  }, [open]);

  // La lista aparece recién cuando se sabe dónde va: ahí el cursor pasa al buscador, listo para escribir
  const placed = place !== null;
  useEffect(() => {
    if (open && placed) search.current?.focus();
  }, [open, placed]);

  // La opción señalada con el teclado siempre queda a la vista
  useEffect(() => {
    if (open) panel.current?.querySelector(`[data-index="${active}"]`)?.scrollIntoView({ block: 'nearest' });
  }, [open, active]);

  const onKeyDown = (event: React.KeyboardEvent) => {
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      if (matches.length > 0) setActive((current) => (current + (event.key === 'ArrowDown' ? 1 : -1) + matches.length) % matches.length);
    } else if (event.key === 'Enter') {
      event.preventDefault();
      if (matches[active]) choose(matches[active]);
    } else if (event.key === 'Escape') {
      event.preventDefault();
      event.stopPropagation();
      close();
    } else if (event.key === 'Tab') {
      close(false);
    }
  };

  return (
    <>
      <button
        ref={trigger}
        type="button"
        disabled={disabled}
        role="combobox"
        aria-label={ariaLabel}
        aria-haspopup="listbox"
        aria-expanded={open}
        aria-controls={open ? listId : undefined}
        onClick={() => (open ? close() : show())}
        onKeyDown={(event) => {
          if (!open && (event.key === 'ArrowDown' || event.key === 'ArrowUp')) {
            event.preventDefault();
            show();
          }
        }}
        className={`${className} inline-flex items-center justify-between gap-2 text-left cursor-pointer disabled:cursor-not-allowed`}
      >
        <span className="truncate">{selected?.label ?? ''}</span>
        <ChevronDown className={`w-4 h-4 shrink-0 text-slate-500 transition-transform ${open ? 'rotate-180' : ''}`} />
      </button>

      {open &&
        place &&
        // Fuera del árbol de la pantalla: una tarjeta con animación o con recorte movería o cortaría la lista
        createPortal(
        <div
          ref={panel}
          style={{ left: place.left, top: place.top, width: place.width, transform: place.up ? 'translateY(-100%)' : undefined }}
          className="fixed z-50 bg-white border border-slate-200 rounded-xl shadow-pop overflow-hidden"
          onKeyDown={onKeyDown}
        >
          <div className="relative border-b border-slate-200/80">
            <Search className="w-4 h-4 text-slate-500 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" />
            <input
              ref={search}
              value={query}
              onChange={(event) => {
                setQuery(event.target.value);
                setActive(0);
              }}
              placeholder="Buscar…"
              aria-label={ariaLabel ? `Buscar en: ${ariaLabel}` : 'Buscar opción'}
              aria-controls={listId}
              aria-activedescendant={matches[active] ? `${listId}-${active}` : undefined}
              className="w-full h-10 pl-9 pr-3 text-[13px] text-slate-900 placeholder-slate-400 focus:outline-none"
            />
          </div>
          <ul id={listId} role="listbox" aria-label={ariaLabel} className="max-h-60 overflow-y-auto py-1">
            {matches.length === 0 && <li className="px-3 py-2.5 text-[13px] text-slate-500">Sin coincidencias</li>}
            {matches.map((option, index) => {
              const isSelected = option.value === String(value);
              return (
                <li
                  key={option.value}
                  id={`${listId}-${index}`}
                  data-index={index}
                  role="option"
                  aria-selected={isSelected}
                  aria-disabled={option.disabled}
                  onMouseEnter={() => setActive(index)}
                  // Con mousedown, para elegir antes de que el buscador pierda el foco
                  onMouseDown={(event) => {
                    event.preventDefault();
                    choose(option);
                  }}
                  className={`flex items-center justify-between gap-3 px-3 py-2 text-[13px] cursor-pointer ${index === active ? 'bg-brand-50' : ''} ${
                    isSelected ? 'font-semibold text-brand-800' : 'text-slate-800'
                  } ${option.disabled ? 'opacity-50 cursor-not-allowed' : ''}`}
                >
                  <span className="break-words min-w-0">{option.label}</span>
                  {isSelected && <Check className="w-4 h-4 shrink-0 text-brand-600" />}
                </li>
              );
            })}
          </ul>
        </div>,
          document.body,
        )}
    </>
  );
};
