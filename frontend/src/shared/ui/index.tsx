import React, { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { AlertCircle, CheckCircle2, Inbox, Loader2, X, type LucideIcon } from 'lucide-react';

// Piezas compartidas del producto. Las pantallas componen estas piezas y no
// definen colores ni tamaños propios. Cubren los estados obligatorios:
// cargando, vacío, error, procesando y deshabilitado.

/* ───────────────────────────── Acciones ───────────────────────────── */

const BUTTON_VARIANTS = {
  primary:
    'text-white bg-gradient-to-r from-brand-700 to-brand-600 hover:from-brand-800 hover:to-brand-700 shadow-sm shadow-brand-700/30',
  secondary: 'bg-white text-slate-700 border border-slate-300 hover:bg-slate-50 hover:border-slate-400 shadow-xs',
  ghost: 'text-slate-600 hover:bg-slate-100 hover:text-slate-900',
  danger: 'bg-white text-rose-700 border border-rose-200 hover:bg-rose-50 hover:border-rose-300',
};

const BUTTON_SIZES = {
  sm: 'h-8 px-2.5 text-xs gap-1.5',
  md: 'h-9 px-3.5 text-[13px] gap-2',
  lg: 'h-11 px-5 text-sm gap-2',
};

interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: keyof typeof BUTTON_VARIANTS;
  size?: keyof typeof BUTTON_SIZES;
  processing?: boolean;
  icon?: React.ReactNode;
}

export const Button: React.FC<ButtonProps> = ({
  variant = 'secondary',
  size = 'md',
  processing = false,
  icon,
  children,
  disabled,
  className = '',
  type = 'button',
  ...props
}) => (
  <button
    type={type}
    disabled={disabled || processing}
    aria-busy={processing || undefined}
    className={`inline-flex items-center justify-center rounded-lg font-semibold whitespace-nowrap transition-all cursor-pointer active:scale-[0.98] disabled:opacity-55 disabled:cursor-not-allowed disabled:active:scale-100 ${BUTTON_VARIANTS[variant]} ${BUTTON_SIZES[size]} ${className}`}
    {...props}
  >
    {processing ? <Loader2 className="w-4 h-4 animate-spin" /> : icon}
    {children}
  </button>
);

/* ───────────────────────────── Estructura ───────────────────────────── */

interface PageHeaderProps {
  title: string;
  subtitle?: string;
  icon?: LucideIcon;
  action?: React.ReactNode;
}

export const PageHeader: React.FC<PageHeaderProps> = ({ title, subtitle, icon: Icon, action }) => (
  <div className="flex flex-wrap items-center justify-between gap-4 mb-6">
    <div className="flex items-center gap-3.5 min-w-0">
      {Icon && (
        <div className="w-11 h-11 shrink-0 rounded-xl bg-gradient-to-br from-brand-700 to-sky-500 text-white flex items-center justify-center shadow-md shadow-brand-700/25">
          <Icon className="w-5 h-5" />
        </div>
      )}
      <div className="min-w-0">
        <h1 className="text-[22px] leading-7 font-bold text-slate-900 tracking-tight">{title}</h1>
        {subtitle && <p className="text-[13px] text-slate-500 mt-0.5">{subtitle}</p>}
      </div>
    </div>
    {action && <div className="flex items-center gap-3">{action}</div>}
  </div>
);

export const Card: React.FC<{ children: React.ReactNode; className?: string }> = ({ children, className = '' }) => (
  <div className={`bg-white border border-slate-200/80 rounded-2xl shadow-card ${className}`}>{children}</div>
);

export const CardHeader: React.FC<{ title: string; text?: string; action?: React.ReactNode }> = ({
  title,
  text,
  action,
}) => (
  <div className="flex flex-wrap items-center justify-between gap-3 px-5 py-4 border-b border-slate-200/80">
    <div>
      <h2 className="text-sm font-semibold text-slate-900">{title}</h2>
      {text && <p className="text-[13px] text-slate-500 mt-0.5">{text}</p>}
    </div>
    {action}
  </div>
);

/* ───────────────────────────── Datos ───────────────────────────── */

const TONES = {
  neutral: { chip: 'bg-slate-100 text-slate-600 ring-slate-200', dot: 'bg-slate-400', icon: 'bg-slate-100 text-slate-600' },
  brand: { chip: 'bg-brand-50 text-brand-700 ring-brand-200', dot: 'bg-brand-500', icon: 'bg-brand-50 text-brand-700' },
  success: { chip: 'bg-emerald-50 text-emerald-700 ring-emerald-200', dot: 'bg-emerald-500', icon: 'bg-emerald-50 text-emerald-600' },
  warning: { chip: 'bg-amber-50 text-amber-800 ring-amber-200', dot: 'bg-amber-500', icon: 'bg-amber-50 text-amber-600' },
  danger: { chip: 'bg-rose-50 text-rose-700 ring-rose-200', dot: 'bg-rose-500', icon: 'bg-rose-50 text-rose-600' },
  info: { chip: 'bg-sky-50 text-sky-700 ring-sky-200', dot: 'bg-sky-500', icon: 'bg-sky-50 text-sky-600' },
  violet: { chip: 'bg-violet-50 text-violet-700 ring-violet-200', dot: 'bg-violet-500', icon: 'bg-violet-50 text-violet-600' },
};

export type Tone = keyof typeof TONES;

// Un estado nunca depende solo del color: punto + texto
export const Badge: React.FC<{ tone?: Tone; dot?: boolean; children: React.ReactNode }> = ({
  tone = 'neutral',
  dot = false,
  children,
}) => (
  <span
    className={`inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-xs font-medium ring-1 ring-inset whitespace-nowrap ${TONES[tone].chip}`}
  >
    {dot && <span className={`w-1.5 h-1.5 rounded-full ${TONES[tone].dot}`} />}
    {children}
  </span>
);

const AVATAR_COLORS = [
  'from-brand-700 to-sky-500',
  'from-violet-600 to-fuchsia-500',
  'from-emerald-600 to-teal-400',
  'from-amber-600 to-orange-400',
  'from-rose-600 to-pink-400',
  'from-cyan-700 to-sky-400',
];

export const Avatar: React.FC<{ name: string; size?: 'sm' | 'md' | 'lg' }> = ({ name, size = 'md' }) => {
  const initials = name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0])
    .join('')
    .toUpperCase();
  // El color sigue a la persona: mismo nombre, mismo color
  const color = AVATAR_COLORS[[...name].reduce((sum, char) => sum + char.charCodeAt(0), 0) % AVATAR_COLORS.length];
  const dimensions = { sm: 'w-7 h-7 text-[10px]', md: 'w-9 h-9 text-xs', lg: 'w-11 h-11 text-sm' }[size];

  return (
    <span
      aria-hidden="true"
      className={`shrink-0 inline-flex items-center justify-center rounded-full bg-gradient-to-br ${color} ${dimensions} font-semibold text-white`}
    >
      {initials || '?'}
    </span>
  );
};

interface StatCardProps {
  label: string;
  value: React.ReactNode;
  hint?: string;
  icon: LucideIcon;
  tone?: Tone;
}

export const StatCard: React.FC<StatCardProps> = ({ label, value, hint, icon: Icon, tone = 'brand' }) => (
  <Card className="p-5 flex items-start justify-between gap-4 hover:shadow-raised transition-shadow">
    <div className="min-w-0">
      <p className="text-[13px] font-medium text-slate-500">{label}</p>
      <p className="mt-1.5 text-[22px] leading-7 font-bold text-slate-900 tracking-tight tnum break-words">{value}</p>
      {hint && <p className="mt-1 text-xs text-slate-500">{hint}</p>}
    </div>
    <div className={`w-10 h-10 shrink-0 rounded-xl flex items-center justify-center ${TONES[tone].icon}`}>
      <Icon className="w-5 h-5" />
    </div>
  </Card>
);

export const IconChip: React.FC<{ icon: LucideIcon; tone?: Tone }> = ({ icon: Icon, tone = 'brand' }) => (
  <span className={`w-10 h-10 shrink-0 rounded-xl inline-flex items-center justify-center ${TONES[tone].icon}`}>
    <Icon className="w-5 h-5" />
  </span>
);

/* Tabla: encabezado diferenciado, filas con hover y desplazamiento propio */

export const Table: React.FC<{ children: React.ReactNode }> = ({ children }) => (
  <div className="overflow-x-auto">
    <table className="w-full text-[13px] text-left">{children}</table>
  </div>
);

export const THead: React.FC<{ children: React.ReactNode }> = ({ children }) => (
  <thead>
    <tr className="bg-slate-50/80 border-b border-slate-200/80 text-xs text-slate-500">{children}</tr>
  </thead>
);

export const Th: React.FC<{ children?: React.ReactNode; className?: string }> = ({ children, className = '' }) => (
  <th scope="col" className={`px-5 py-3 font-semibold whitespace-nowrap ${className}`}>
    {children}
  </th>
);

export const TBody: React.FC<{ children: React.ReactNode }> = ({ children }) => (
  <tbody className="divide-y divide-slate-100">{children}</tbody>
);

export const Tr: React.FC<React.HTMLAttributes<HTMLTableRowElement>> = ({ className = '', ...props }) => (
  <tr className={`hover:bg-brand-50/40 transition-colors ${className}`} {...props} />
);

export const Td: React.FC<React.TdHTMLAttributes<HTMLTableCellElement>> = ({ className = '', ...props }) => (
  <td className={`px-5 py-3.5 align-middle ${className}`} {...props} />
);

interface PaginationProps {
  page: number;
  lastPage: number;
  total: number;
  noun: string;
  onPage: (page: number) => void;
}

export const Pagination: React.FC<PaginationProps> = ({ page, lastPage, total, noun, onPage }) =>
  lastPage > 1 ? (
    <div className="flex items-center justify-between gap-3 px-5 py-3 border-t border-slate-200/80 text-[13px] text-slate-500">
      <span className="tnum">
        Página {page} de {lastPage} · {total} {noun}
      </span>
      <div className="flex gap-2">
        <Button size="sm" disabled={page <= 1} onClick={() => onPage(page - 1)}>
          Anterior
        </Button>
        <Button size="sm" disabled={page >= lastPage} onClick={() => onPage(page + 1)}>
          Siguiente
        </Button>
      </div>
    </div>
  ) : null;

/* ───────────────────────────── Estados de pantalla ───────────────────────────── */

export const LoadingState: React.FC<{ label?: string }> = ({ label = 'Cargando…' }) => (
  <div className="flex flex-col items-center justify-center gap-3 py-20 text-[13px] text-slate-500" role="status">
    <span className="relative flex w-9 h-9">
      <span className="absolute inset-0 rounded-full border-2 border-brand-100" />
      <span className="absolute inset-0 rounded-full border-2 border-transparent border-t-brand-600 animate-spin" />
    </span>
    {label}
  </div>
);

export const EmptyState: React.FC<{ title: string; text?: string; icon?: LucideIcon; action?: React.ReactNode }> = ({
  title,
  text,
  icon: Icon = Inbox,
  action,
}) => (
  <div className="flex flex-col items-center justify-center py-16 px-6 text-center">
    <div className="w-12 h-12 rounded-2xl bg-brand-50 text-brand-600 flex items-center justify-center mb-4 ring-8 ring-brand-50/50">
      <Icon className="w-5 h-5" />
    </div>
    <p className="text-sm font-semibold text-slate-900">{title}</p>
    {text && <p className="text-[13px] text-slate-500 mt-1 max-w-sm">{text}</p>}
    {action && <div className="mt-5">{action}</div>}
  </div>
);

export const ErrorState: React.FC<{ message: string; onRetry?: () => void }> = ({ message, onRetry }) => (
  <div className="flex flex-col items-center justify-center py-16 px-6 text-center">
    <div className="w-12 h-12 rounded-2xl bg-rose-50 text-rose-600 flex items-center justify-center mb-4 ring-8 ring-rose-50/50">
      <AlertCircle className="w-5 h-5" />
    </div>
    <p className="text-sm font-semibold text-slate-900">No se pudo cargar la información</p>
    <p className="text-[13px] text-slate-500 mt-1 max-w-sm">{message}</p>
    {onRetry && (
      <Button className="mt-5" onClick={onRetry}>
        Reintentar
      </Button>
    )}
  </div>
);

const ALERT_TONES = {
  danger: { box: 'bg-rose-50 border-rose-200 text-rose-800', icon: AlertCircle, role: 'alert' },
  success: { box: 'bg-emerald-50 border-emerald-200 text-emerald-800', icon: CheckCircle2, role: 'status' },
  info: { box: 'bg-sky-50 border-sky-200 text-sky-800', icon: AlertCircle, role: 'status' },
};

export const Alert: React.FC<{ tone?: keyof typeof ALERT_TONES; children: React.ReactNode }> = ({
  tone = 'danger',
  children,
}) => {
  const { box, icon: Icon, role } = ALERT_TONES[tone];

  return (
    <div className={`px-3.5 py-3 border rounded-xl flex items-start gap-2.5 text-[13px] ${box}`} role={role}>
      <Icon className="w-4 h-4 shrink-0 mt-0.5" />
      <span>{children}</span>
    </div>
  );
};

/* ───────────────────────────── Formularios ───────────────────────────── */

export const INPUT_CLASS =
  'w-full min-h-9 px-3 py-1.5 bg-white border border-slate-300 rounded-lg text-[13px] text-slate-900 placeholder-slate-400 shadow-xs transition-all hover:border-slate-400 focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 focus:outline-none disabled:bg-slate-50 disabled:text-slate-500 disabled:hover:border-slate-300';

export const CHECKBOX_CLASS = 'w-4 h-4 shrink-0 rounded accent-brand-600 cursor-pointer disabled:cursor-not-allowed';

export const Field: React.FC<{ label: string; hint?: string; children: React.ReactNode }> = ({
  label,
  hint,
  children,
}) => (
  <label className="block">
    <span className="block text-[13px] font-medium text-slate-700 mb-1.5">{label}</span>
    {children}
    {hint && <span className="block text-xs text-slate-500 mt-1.5">{hint}</span>}
  </label>
);

/* ───────────────────────────── Capas ───────────────────────────── */

interface ModalProps {
  title: string;
  subtitle?: string;
  onClose: () => void;
  children: React.ReactNode;
  footer?: React.ReactNode;
}

export const Modal: React.FC<ModalProps> = ({ title, subtitle, onClose, children, footer }) => {
  useEffect(() => {
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onClose();
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [onClose]);

  // Se monta en <body>: dentro de la pantalla quedaría atrapado bajo la cabecera
  // y limitado al alto del contenido (la animación de entrada crea su propia capa)
  return createPortal(
    <div
      className="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-4 bg-brand-950/55 backdrop-blur-sm"
      role="dialog"
      aria-modal="true"
      aria-label={title}
    >
      <div className="w-full max-w-lg bg-white rounded-2xl shadow-pop flex flex-col max-h-[90dvh] animate-rise">
        <div className="flex items-start justify-between gap-4 px-5 sm:px-6 pt-5 pb-4 border-b border-slate-200/80">
          <div>
            <h2 className="text-base font-semibold text-slate-900">{title}</h2>
            {subtitle && <p className="text-[13px] text-slate-500 mt-0.5">{subtitle}</p>}
          </div>
          <button
            type="button"
            onClick={onClose}
            aria-label="Cerrar"
            className="p-1.5 -mr-1.5 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded-lg cursor-pointer transition-colors"
          >
            <X className="w-4 h-4" />
          </button>
        </div>
        <div className="px-5 sm:px-6 py-5 overflow-y-auto">{children}</div>
        {footer && (
          <div className="px-5 sm:px-6 py-4 border-t border-slate-200/80 bg-slate-50/60 rounded-b-2xl flex justify-end gap-2.5">
            {footer}
          </div>
        )}
      </div>
    </div>,
    document.body,
  );
};

// Confirmación de una acción que no se deshace. Si falla, el error queda a la vista.
export const ConfirmModal: React.FC<{
  title: string;
  text: string;
  confirmLabel: string;
  variant?: 'danger' | 'primary';
  onClose: () => void;
  onConfirm: () => Promise<void>;
}> = ({ title, text, confirmLabel, variant = 'danger', onClose, onConfirm }) => {
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const confirm = async () => {
    setProcessing(true);
    setError(null);
    try {
      await onConfirm();
    } catch (err) {
      const message = (err as { response?: { data?: { message?: string } } }).response?.data?.message;
      setError(message ?? 'No se pudo completar la acción.');
      setProcessing(false);
    }
  };

  return (
    <Modal
      title={title}
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant={variant} processing={processing} onClick={confirm}>
            {confirmLabel}
          </Button>
        </>
      }
    >
      <div className="space-y-3">
        {error && <Alert>{error}</Alert>}
        <p className="text-[13px] leading-relaxed text-slate-600">{text}</p>
      </div>
    </Modal>
  );
};
