import React from 'react';

export const BrandMark: React.FC<{ tagline?: string }> = ({ tagline }) => (
  <div className="flex items-center gap-3">
    <div className="w-10 h-10 rounded-xl bg-white/10 backdrop-blur-md ring-1 ring-white/20 flex items-center justify-center shadow-lg shadow-black/10">
      <svg
        className="w-6 h-6 text-white"
        viewBox="0 0 48 48"
        fill="none"
        stroke="currentColor"
        strokeWidth="2.5"
        strokeLinecap="round"
        strokeLinejoin="round"
        aria-hidden="true"
      >
        <path d="M19 6h10M21 6v10l-11 18a4 4 0 003.4 6h21.2a4 4 0 003.4-6L27 16V6" />
        <path d="M14 28c3 1 7-1 10 0s7 1 10 0" fill="#38BDF8" fillOpacity="0.45" stroke="none" />
        <circle cx="24" cy="22" r="1.5" fill="#38BDF8" />
      </svg>
    </div>
    <div className="leading-tight">
      {tagline ? (
        // Dentro de una academia manda su nombre; la plataforma queda como firma
        <>
          <span className="block font-extrabold text-xl tracking-tight text-white">{tagline}</span>
          <span className="block text-[11px] font-medium text-brand-200">
            con Intelecta<span className="text-sky-400">Lab_</span>
          </span>
        </>
      ) : (
        <span className="font-extrabold text-xl tracking-tight text-white">
          Intelecta<span className="text-sky-400">Lab_</span>
        </span>
      )}
    </div>
  </div>
);

interface AuthShellProps {
  tagline?: string;
  title: string;
  subtitle: string;
  children: React.ReactNode;
  footer?: React.ReactNode;
}

// Fondo de marca para las pantallas de acceso de una sola tarjeta
export const AuthShell: React.FC<AuthShellProps> = ({ tagline, title, subtitle, children, footer }) => (
  <div className="relative min-h-screen flex flex-col items-center justify-center overflow-hidden bg-gradient-to-br from-brand-900 via-brand-800 to-brand-600 p-6">
    <div className="absolute -top-24 -left-24 w-[420px] h-[420px] rounded-full bg-sky-500/25 blur-[110px] pointer-events-none" />
    <div className="absolute -bottom-20 -right-10 w-[420px] h-[420px] rounded-full bg-sky-400/20 blur-[120px] pointer-events-none" />
    <div
      className="absolute inset-0 opacity-[0.07] pointer-events-none"
      style={{
        backgroundImage:
          'linear-gradient(to right, #fff 1px, transparent 1px), linear-gradient(to bottom, #fff 1px, transparent 1px)',
        backgroundSize: '48px 48px',
      }}
    />

    <div className="relative mb-7">
      <BrandMark tagline={tagline} />
    </div>

    <div className="relative w-full max-w-[420px] bg-white rounded-3xl shadow-pop p-8 animate-rise">
      <h1 className="text-xl font-bold text-slate-900 tracking-tight">{title}</h1>
      <p className="text-[13px] text-slate-500 mt-1 mb-6">{subtitle}</p>
      {children}
    </div>

    {footer && <div className="relative mt-6 text-[13px]">{footer}</div>}
  </div>
);
