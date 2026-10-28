import React from 'react';
import { Link } from 'react-router-dom';
import { Compass } from 'lucide-react';

// Dirección que no existe: en vez de mandar a otra pantalla sin decir nada, se avisa y se ofrece volver
export const NotFoundPage: React.FC = () => (
  <div className="min-h-screen flex items-center justify-center px-4 bg-gradient-to-br from-brand-900 via-brand-800 to-brand-600">
    <div className="max-w-md w-full text-center bg-white rounded-3xl shadow-raised p-8 sm:p-10">
      <span className="inline-flex w-14 h-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-700 ring-1 ring-brand-100 mb-5">
        <Compass className="w-7 h-7" aria-hidden="true" />
      </span>
      <p className="text-xs font-semibold tracking-[0.18em] text-accent-600">ERROR 404</p>
      <h1 className="mt-2 text-xl font-bold tracking-tight text-slate-900">Esta dirección no existe</h1>
      <p className="mt-2 text-[13px] leading-relaxed text-slate-600">Puede que el enlace esté mal escrito o que la página se haya movido.</p>
      <Link
        to="/"
        className="mt-6 inline-flex items-center justify-center h-10 px-5 rounded-xl bg-brand-800 text-white text-[13px] font-semibold hover:bg-brand-900 transition-colors"
      >
        Ir al inicio
      </Link>
    </div>
  </div>
);
