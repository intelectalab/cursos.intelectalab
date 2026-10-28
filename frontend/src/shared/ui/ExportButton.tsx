import React, { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { ChevronDown, Download, FileSpreadsheet, FileText, Sheet, Table2, type LucideIcon } from 'lucide-react';
import { api } from '../api/client';
import { Button } from './index';

interface ExportButtonProps {
  // Ruta de la API que entrega el archivo
  path: string;
  // Los mismos filtros de la pantalla: el archivo trae lo que se está viendo
  params?: Record<string, string | undefined>;
  // Nombre del archivo; la extensión se cambia según el formato elegido
  filename: string;
  label?: string;
}

type Format = 'xlsx' | 'pdf' | 'csv' | 'ods';

const FORMATS: { key: Format; label: string; hint: string; icon: LucideIcon }[] = [
  { key: 'xlsx', label: 'Excel', hint: 'Con los colores de tu academia, totales y filtros', icon: FileSpreadsheet },
  { key: 'pdf', label: 'PDF', hint: 'Para imprimir o enviar (hasta 1 000 filas)', icon: FileText },
  { key: 'csv', label: 'CSV', hint: 'Solo los datos, para otro sistema', icon: Table2 },
  { key: 'ods', label: 'LibreOffice (.ods)', hint: 'Hoja de cálculo libre', icon: Sheet },
];

const MENU_WIDTH = 288;

/** El mensaje del servidor cuando la descarga falla (viene dentro de un archivo, no como texto) */
async function reason(error: unknown): Promise<string | null> {
  const data = (error as { response?: { data?: unknown } }).response?.data;
  if (!(data instanceof Blob)) return null;
  try {
    return (JSON.parse(await data.text()) as { message?: string }).message ?? null;
  } catch {
    return null;
  }
}

// Descarga el listado de la pantalla, con sus filtros, en el formato que se elija
export const ExportButton: React.FC<ExportButtonProps> = ({ path, params, filename, label = 'Descargar' }) => {
  const [open, setOpen] = useState(false);
  // Dónde se dibuja el menú: fuera de la pantalla actual (las pantallas se animan y un menú fijo dentro quedaría corrido)
  const [place, setPlace] = useState<{ top: number; left: number } | null>(null);
  const [exporting, setExporting] = useState<Format | null>(null);
  const [error, setError] = useState<string | null>(null);
  const wrapper = useRef<HTMLDivElement>(null);
  const menu = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;
    const close = (event: MouseEvent | KeyboardEvent) => {
      const target = event.target as Node;
      if (event instanceof KeyboardEvent ? event.key === 'Escape' : !wrapper.current?.contains(target) && !menu.current?.contains(target)) setOpen(false);
    };
    const hide = () => setOpen(false);
    document.addEventListener('mousedown', close);
    document.addEventListener('keydown', close);
    window.addEventListener('resize', hide);
    window.addEventListener('scroll', hide, true);
    return () => {
      document.removeEventListener('mousedown', close);
      document.removeEventListener('keydown', close);
      window.removeEventListener('resize', hide);
      window.removeEventListener('scroll', hide, true);
    };
  }, [open]);

  const toggle = () => {
    const rect = wrapper.current?.getBoundingClientRect();
    if (rect) {
      // Bajo el botón, desde su borde izquierdo; si no cabe, desde el derecho. Nunca fuera de la ventana
      const left = rect.left + MENU_WIDTH <= window.innerWidth - 8 ? rect.left : rect.right - MENU_WIDTH;
      setPlace({ top: rect.bottom + 6, left: Math.min(Math.max(8, left), window.innerWidth - MENU_WIDTH - 8) });
    }
    setOpen((current) => !current);
  };

  const download = async (format: Format) => {
    setOpen(false);
    setExporting(format);
    setError(null);
    try {
      const response = await api.get(path, { params: { ...params, format }, responseType: 'blob' });
      const url = URL.createObjectURL(response.data as Blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = `${filename.replace(/\.[a-z]+$/i, '')}.${format}`;
      link.click();
      URL.revokeObjectURL(url);
    } catch (err) {
      setError((await reason(err)) ?? 'No se pudo descargar. Inténtalo de nuevo.');
    } finally {
      setExporting(null);
    }
  };

  return (
    <div className="flex items-center gap-2">
      {error && (
        <span role="alert" className="text-xs font-medium text-rose-700 max-w-xs">
          {error}
        </span>
      )}
      <div ref={wrapper}>
        <Button icon={<Download className="w-4 h-4" />} processing={exporting !== null} onClick={toggle} aria-haspopup="menu" aria-expanded={open}>
          {label}
          <ChevronDown className="w-3.5 h-3.5 -mr-1" />
        </Button>
      </div>
      {open &&
        place &&
        createPortal(
          <div ref={menu} role="menu" style={{ top: place.top, left: place.left, width: MENU_WIDTH }} className="fixed z-50 rounded-xl border border-slate-200 bg-white shadow-raised p-1.5">
            {FORMATS.map(({ key, label: name, hint, icon: Icon }) => (
              <button
                key={key}
                type="button"
                role="menuitem"
                onClick={() => void download(key)}
                className="w-full flex items-start gap-3 rounded-lg px-3 py-2 text-left hover:bg-brand-50 cursor-pointer"
              >
                <Icon className="w-4 h-4 mt-0.5 text-brand-600 shrink-0" />
                <span>
                  <span className="block text-[13px] font-semibold text-slate-900">{name}</span>
                  <span className="block text-xs text-slate-500">{hint}</span>
                </span>
              </button>
            ))}
          </div>,
          document.body,
        )}
    </div>
  );
};
