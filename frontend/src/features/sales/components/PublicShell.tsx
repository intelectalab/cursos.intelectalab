import React from 'react';
import { Link } from 'react-router-dom';
import { GraduationCap } from 'lucide-react';
import { useAuth } from '../../auth/AuthContext';
import { useTenant } from '../../../shared/tenancy/TenantContext';

const LINK_CLASS = 'inline-flex items-center justify-center h-9 px-3.5 rounded-lg text-[13px] font-semibold transition-colors';

// Marco de las páginas públicas de la academia: se ven sin iniciar sesión
export const PublicShell: React.FC<{ hero?: React.ReactNode; children: React.ReactNode }> = ({ hero, children }) => {
  const { tenant } = useTenant();
  const { isAuthenticated } = useAuth();

  return (
    <div className="min-h-screen flex flex-col bg-canvas">
      <div className="relative overflow-hidden bg-gradient-to-br from-brand-900 via-brand-800 to-brand-600">
        <div className="absolute -top-28 -left-20 w-[420px] h-[420px] rounded-full bg-sky-500/25 blur-[110px] pointer-events-none" />
        <div className="absolute -bottom-32 right-0 w-[420px] h-[420px] rounded-full bg-sky-400/20 blur-[120px] pointer-events-none" />

        <header className="relative max-w-6xl mx-auto px-4 sm:px-6 h-16 flex items-center justify-between gap-4">
          <Link to="/catalog" className="flex items-center gap-3 min-w-0">
            <span className="w-9 h-9 shrink-0 rounded-xl bg-white/10 ring-1 ring-white/20 flex items-center justify-center overflow-hidden">
              {tenant?.logo_url ? (
                <img src={tenant.logo_url} alt="" className="w-full h-full object-contain bg-white" />
              ) : (
                <GraduationCap className="w-5 h-5 text-white" aria-hidden="true" />
              )}
            </span>
            <span className="font-bold text-white tracking-tight truncate">{tenant?.name}</span>
          </Link>

          <nav className="flex items-center gap-2 shrink-0">
            {isAuthenticated ? (
              <Link to="/dashboard" className={`${LINK_CLASS} bg-white text-brand-800 hover:bg-sky-50`}>
                Ir a mi panel
              </Link>
            ) : (
              <>
                <Link to="/login" className={`${LINK_CLASS} text-sky-100 hover:text-white hover:bg-white/10`}>
                  Iniciar sesión
                </Link>
                <Link to="/signup" className={`${LINK_CLASS} bg-white text-brand-800 hover:bg-sky-50`}>
                  Crear cuenta
                </Link>
              </>
            )}
          </nav>
        </header>

        {hero && <div className="relative max-w-6xl mx-auto px-4 sm:px-6 pt-8 pb-12 sm:pt-12 sm:pb-16">{hero}</div>}
      </div>

      <main className="flex-1 w-full max-w-6xl mx-auto px-4 sm:px-6 py-8 sm:py-10">{children}</main>

      <footer className="border-t border-slate-200/80 py-5 text-center text-xs text-slate-500">
        © {new Date().getFullYear()} {tenant?.name}
      </footer>
    </div>
  );
};
