import React from 'react';
import { Link, useLocation } from 'react-router-dom';
import { useAuth } from '../../features/auth/AuthContext';
import { APP_MODULES } from '../../config/modules';
import { Avatar } from '../../shared/ui';
import { ChevronRight, CircleHelp, Globe, LogOut, PanelLeft } from 'lucide-react';
import { useHelp } from '../../features/helpcenter/HelpContext';
import { displayAddress } from '../../shared/tenancy/hosts';
import { InstallButton } from '../../shared/pwa/InstallPrompts';

interface HeaderProps {
  onToggleSidebar: () => void;
  isMenuOpen: boolean;
}

export const Header: React.FC<HeaderProps> = ({ onToggleSidebar, isMenuOpen }) => {
  const { user, tenant, logout } = useAuth();
  const { openHelp } = useHelp();
  const { pathname } = useLocation();

  const current = APP_MODULES.find((module) => pathname.startsWith(module.href));
  const section = current?.name ?? (pathname.startsWith('/account') ? 'Mi cuenta' : '');
  const fullName = `${user?.first_name ?? ''} ${user?.last_name ?? ''}`.trim();

  return (
    <header className="h-16 shrink-0 bg-white/85 backdrop-blur-md border-b border-slate-200/80 px-2.5 sm:px-6 flex items-center justify-between gap-2 sm:gap-4 sticky top-0 z-20">
      {/* Menú y ubicación */}
      <div className="flex items-center gap-1.5 sm:gap-3 min-w-0">
        <button
          type="button"
          onClick={onToggleSidebar}
          aria-label={isMenuOpen ? 'Cerrar menú' : 'Abrir menú'}
          aria-expanded={isMenuOpen}
          aria-controls="app-sidebar"
          className="p-2 text-slate-500 hover:text-brand-700 hover:bg-brand-50 rounded-lg transition-colors cursor-pointer"
        >
          <PanelLeft className="w-5 h-5" />
        </button>

        <nav className="flex items-center gap-1.5 text-[13px] min-w-0" aria-label="Ubicación">
          <span className="hidden sm:inline text-slate-500 truncate">{tenant?.name}</span>
          {section && (
            <>
              <ChevronRight className="hidden sm:inline w-3.5 h-3.5 text-slate-300 shrink-0" />
              <span className="font-semibold text-slate-900 truncate" aria-current="page">
                {section}
              </span>
            </>
          )}
        </nav>
      </div>

      {/* Organización, cuenta y salida */}
      <div className="flex items-center gap-1 sm:gap-3 shrink-0">
        <span className="hidden lg:inline-flex items-center gap-1.5 h-8 px-2.5 rounded-lg bg-brand-50 text-brand-700 text-xs font-medium ring-1 ring-inset ring-brand-100">
          <Globe className="w-3.5 h-3.5" />
          {displayAddress()}
        </span>

        {tenant && <InstallButton name={tenant.name} />}

        {/* Ayuda de la pantalla abierta (y de su pestaña) */}
        <button
          type="button"
          onClick={() => openHelp()}
          title="Ayuda de esta pantalla"
          className="inline-flex items-center gap-1.5 h-9 px-2.5 rounded-lg text-[13px] font-semibold text-slate-600 hover:text-brand-700 hover:bg-brand-50 transition-colors cursor-pointer"
        >
          <CircleHelp className="w-[18px] h-[18px]" />
          <span className="hidden md:inline">Ayuda</span>
        </button>

        <div className="hidden sm:block h-6 w-px bg-slate-200" />

        <Link
          to="/account"
          title="Mi cuenta"
          className="flex items-center gap-2.5 rounded-xl p-1 md:pr-2.5 hover:bg-slate-100 transition-colors"
        >
          <Avatar name={fullName} />
          <div className="hidden md:block text-left leading-tight">
            <p className="text-[13px] font-semibold text-slate-900">{fullName}</p>
            <p className="text-xs text-slate-500">{user?.roles[0]}</p>
          </div>
        </Link>

        <button
          type="button"
          onClick={logout}
          aria-label="Cerrar sesión"
          title="Cerrar sesión"
          className="p-2 text-slate-500 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition-colors cursor-pointer"
        >
          <LogOut className="w-[18px] h-[18px]" />
        </button>
      </div>
    </header>
  );
};
