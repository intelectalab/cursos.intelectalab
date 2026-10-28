import React from 'react';
import { Link } from 'react-router-dom';
import { BookOpenCheck, GraduationCap, MessageCircle, Phone } from 'lucide-react';
import { SOCIAL_LABEL, SOCIAL_NETWORKS } from '../../../shared/tenancy/theme';
import { useAuth } from '../../auth/AuthContext';
import { useTenant } from '../../../shared/tenancy/TenantContext';
import { useSitePages } from '../../cms/useSitePages';

const LINK_CLASS = 'inline-flex items-center justify-center h-9 px-3.5 rounded-lg text-[13px] font-semibold transition-colors';

// Marco de las páginas públicas de la academia: se ven sin iniciar sesión
export const PublicShell: React.FC<{ hero?: React.ReactNode; children: React.ReactNode }> = ({ hero, children }) => {
  const { tenant } = useTenant();
  const { isAuthenticated } = useAuth();
  // Páginas publicadas que la academia enlaza arriba o en el pie
  const pages = useSitePages();
  const footer = tenant?.appearance?.footer;

  return (
    <div className="min-h-screen flex flex-col bg-canvas">
      <div className="relative overflow-hidden bg-gradient-to-br from-brand-900 via-brand-800 to-brand-600">
        <div className="absolute -top-28 -left-20 w-[420px] h-[420px] rounded-full bg-accent-500/25 blur-[110px] pointer-events-none" />
        <div className="absolute -bottom-32 right-0 w-[420px] h-[420px] rounded-full bg-accent-400/20 blur-[120px] pointer-events-none" />

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
            {pages
              .filter((page) => page.header)
              .map((page) => (
                <Link key={page.slug} to={`/p/${page.slug}`} className={`${LINK_CLASS} hidden md:inline-flex text-accent-100 hover:text-white hover:bg-white/10`}>
                  {page.title}
                </Link>
              ))}
            {isAuthenticated ? (
              <Link to="/dashboard" className={`${LINK_CLASS} bg-white text-brand-800 hover:bg-accent-50`}>
                Ir a mi panel
              </Link>
            ) : (
              <>
                <Link to="/login" className={`${LINK_CLASS} text-accent-100 hover:text-white hover:bg-white/10`}>
                  Iniciar sesión
                </Link>
                <Link to="/signup" className={`${LINK_CLASS} bg-white text-brand-800 hover:bg-accent-50`}>
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
        {pages
          .filter((page) => page.footer)
          .map((page) => (
          <Link key={page.slug} to={`/p/${page.slug}`} className="font-medium text-slate-600 hover:text-brand-700">
            {page.title}
          </Link>
        ))}
        <Link to="/reclamaciones" className="inline-flex items-center gap-1.5 font-medium text-slate-600 hover:text-brand-700">
          <BookOpenCheck className="w-3.5 h-3.5" />
          Libro de Reclamaciones
        </Link>
        {/* Datos de contacto y redes de la academia (Configuración → Apariencia) */}
        {footer && (footer.text || footer.phone || footer.whatsapp || Object.keys(footer.social).length > 0) && (
          <div className="basis-full flex flex-wrap items-center justify-center gap-x-5 gap-y-2 pt-2">
            {footer.text && <span>{footer.text}</span>}
            {footer.phone && (
              <a href={`tel:${footer.phone.replace(/[^+0-9]/g, '')}`} className="inline-flex items-center gap-1.5 font-medium text-slate-600 hover:text-brand-700">
                <Phone className="w-3.5 h-3.5" />
                {footer.phone}
              </a>
            )}
            {footer.whatsapp && (
              <a href={`https://wa.me/${footer.whatsapp}`} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1.5 font-medium text-slate-600 hover:text-brand-700">
                <MessageCircle className="w-3.5 h-3.5" />
                WhatsApp
              </a>
            )}
            {SOCIAL_NETWORKS.filter((network) => footer.social[network]).map((network) => (
              <a key={network} href={footer.social[network]} target="_blank" rel="noreferrer" className="font-medium text-slate-600 hover:text-brand-700">
                {SOCIAL_LABEL[network]}
              </a>
            ))}
          </div>
        )}
      </footer>
    </div>
  );
};
