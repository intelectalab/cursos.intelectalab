import React, { useEffect } from 'react';
import { AlertCircle, Inbox, Loader2, X } from 'lucide-react';

// Piezas compartidas del back-office. Cubren los estados obligatorios de
// pantalla: cargando, vacío, error, procesando y deshabilitado.

const BUTTON_VARIANTS = {
  primary: 'bg-[#084586] hover:bg-[#062F5E] text-white shadow-sm',
  secondary: 'bg-white hover:bg-slate-50 text-slate-700 border border-slate-200',
  danger: 'bg-white hover:bg-red-50 text-red-600 border border-red-200',
};

interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: keyof typeof BUTTON_VARIANTS;
  processing?: boolean;
  icon?: React.ReactNode;
}

export const Button: React.FC<ButtonProps> = ({
  variant = 'secondary',
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
    className={`inline-flex items-center justify-center gap-2 px-3.5 py-2 rounded-lg text-xs font-semibold transition-colors cursor-pointer disabled:opacity-60 disabled:cursor-not-allowed ${BUTTON_VARIANTS[variant]} ${className}`}
    {...props}
  >
    {processing ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : icon}
    {children}
  </button>
);

export const PageHeader: React.FC<{ title: string; subtitle?: string; action?: React.ReactNode }> = ({
  title,
  subtitle,
  action,
}) => (
  <div className="flex flex-wrap items-start justify-between gap-3 mb-5">
    <div>
      <h1 className="text-xl font-bold text-slate-900 tracking-tight">{title}</h1>
      {subtitle && <p className="text-xs text-slate-500 mt-1">{subtitle}</p>}
    </div>
    {action}
  </div>
);

export const Card: React.FC<{ children: React.ReactNode; className?: string }> = ({ children, className = '' }) => (
  <div className={`bg-white border border-slate-200 rounded-xl shadow-sm ${className}`}>{children}</div>
);

const BADGE_TONES = {
  neutral: 'bg-slate-100 text-slate-600 border-slate-200',
  success: 'bg-emerald-50 text-emerald-700 border-emerald-200',
  warning: 'bg-amber-50 text-amber-700 border-amber-200',
  info: 'bg-sky-50 text-sky-700 border-sky-200',
};

export const Badge: React.FC<{ tone?: keyof typeof BADGE_TONES; children: React.ReactNode }> = ({
  tone = 'neutral',
  children,
}) => (
  <span
    className={`inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold border whitespace-nowrap ${BADGE_TONES[tone]}`}
  >
    {children}
  </span>
);

export const LoadingState: React.FC<{ label?: string }> = ({ label = 'Cargando…' }) => (
  <div className="flex items-center justify-center gap-2 py-16 text-xs text-slate-500" role="status">
    <Loader2 className="w-4 h-4 animate-spin text-[#084586]" />
    {label}
  </div>
);

export const EmptyState: React.FC<{ title: string; text?: string; action?: React.ReactNode }> = ({
  title,
  text,
  action,
}) => (
  <div className="flex flex-col items-center justify-center py-16 px-6 text-center">
    <div className="w-10 h-10 rounded-full bg-slate-100 text-slate-400 flex items-center justify-center mb-3">
      <Inbox className="w-5 h-5" />
    </div>
    <p className="text-sm font-semibold text-slate-800">{title}</p>
    {text && <p className="text-xs text-slate-500 mt-1 max-w-sm">{text}</p>}
    {action && <div className="mt-4">{action}</div>}
  </div>
);

export const ErrorState: React.FC<{ message: string; onRetry?: () => void }> = ({ message, onRetry }) => (
  <div className="flex flex-col items-center justify-center py-16 px-6 text-center">
    <div className="w-10 h-10 rounded-full bg-red-50 text-red-500 flex items-center justify-center mb-3">
      <AlertCircle className="w-5 h-5" />
    </div>
    <p className="text-sm font-semibold text-slate-800">No se pudo cargar la información</p>
    <p className="text-xs text-slate-500 mt-1 max-w-sm">{message}</p>
    {onRetry && (
      <Button className="mt-4" onClick={onRetry}>
        Reintentar
      </Button>
    )}
  </div>
);

export const Alert: React.FC<{ children: React.ReactNode }> = ({ children }) => (
  <div className="p-3 bg-red-50 border border-red-200 rounded-lg flex items-start gap-2 text-xs text-red-700" role="alert">
    <AlertCircle className="w-4 h-4 shrink-0 mt-0.5" />
    <span>{children}</span>
  </div>
);

export const INPUT_CLASS =
  'w-full px-3 py-2 bg-white border border-slate-200 rounded-lg text-xs text-slate-900 placeholder-slate-400 focus:border-[#0284C7] focus:ring-2 focus:ring-[#0284C7]/15 focus:outline-none transition-all disabled:bg-slate-50 disabled:text-slate-500';

export const Field: React.FC<{ label: string; hint?: string; children: React.ReactNode }> = ({
  label,
  hint,
  children,
}) => (
  <label className="block">
    <span className="block text-[11px] font-semibold text-slate-700 mb-1">{label}</span>
    {children}
    {hint && <span className="block text-[11px] text-slate-400 mt-1">{hint}</span>}
  </label>
);

interface ModalProps {
  title: string;
  onClose: () => void;
  children: React.ReactNode;
  footer?: React.ReactNode;
}

export const Modal: React.FC<ModalProps> = ({ title, onClose, children, footer }) => {
  useEffect(() => {
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onClose();
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [onClose]);

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50" role="dialog" aria-modal="true" aria-label={title}>
      <div className="w-full max-w-lg bg-white rounded-xl shadow-xl flex flex-col max-h-[90vh]">
        <div className="flex items-center justify-between px-5 py-4 border-b border-slate-200">
          <h2 className="text-sm font-bold text-slate-900">{title}</h2>
          <button
            type="button"
            onClick={onClose}
            aria-label="Cerrar"
            className="p-1 text-slate-400 hover:text-slate-700 rounded-md cursor-pointer"
          >
            <X className="w-4 h-4" />
          </button>
        </div>
        <div className="px-5 py-4 overflow-y-auto">{children}</div>
        {footer && <div className="px-5 py-3 border-t border-slate-200 flex justify-end gap-2">{footer}</div>}
      </div>
    </div>
  );
};
