import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { BookOpenCheck, GraduationCap } from 'lucide-react';
import { useAuth } from '../../auth/AuthContext';
import { useTenant } from '../../../shared/tenancy/TenantContext';
import { api } from '../../../shared/api/client';

const LINK_CLASS = 'inline-flex items-center justify-center h-9 px-3.5 rounded-lg text-[13px] font-semibold transition-colors';

// Marco de las páginas públicas de la academia: se ven sin iniciar sesión
export const PublicShell: React.FC<{ hero?: React.ReactNode; children: React.ReactNode }> = ({ hero, children }) => {
  const { tenant } = useTenant();
  const { isAuthenticated } = useAuth();
  // Páginas publicadas de la academia que se enlazan al pie
  const [pages, setPages] = useState<{ slug: string; title: string }[]>([]);

  useEffect(() => {
    api
      .get('/site/pages')
      .then((response) => setPages(response.data.data))
      .catch(() => setPages([]));
  }, []);

  return (
    <div className="min-h-screen flex flex-col bg-canvas">
      <div className="relative overflow-hidden bg-gradient-to-br from-brand-900 via-brand-800 to-brand-600">
        <div className="absolute -top-28 -left-20 w-[420px] h-[420px] rounded-full bg-sky-500/25 blur-[110px] pointer-events-none" />
        <div className="absolute -bottom-32 right-0 w-[420px] h-[420px] rounded-full bg-sky-400/20 blur-[120px] pointer-events-none" />

        <header className="relative max-w-6xl mx-auto px-4 sm:px-6 h-16 flex items-center justify-between gap-4">
          <Link to="/" className="flex items-center gap-3 min-w-0">
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

      <footer className="border-t border-slate-200/80 py-5 px-4 flex flex-wrap items-center justify-center gap-x-5 gap-y-2 text-xs text-slate-500">
        <span>
          © {new Date().getFullYear()} {tenant?.name}
        </span>
        {pages.map((page) => (
          <Link key={page.slug} to={`/p/${page.slug}`} className="font-medium text-slate-600 hover:text-brand-700">
            {page.title}
          </Link>
        ))}
        <Link to="/reclamaciones" className="inline-flex items-center gap-1.5 font-medium text-slate-600 hover:text-brand-700">
          <BookOpenCheck className="w-3.5 h-3.5" />
          Libro de Reclamaciones
        </Link>
      </footer>
    </div>
  );
};
