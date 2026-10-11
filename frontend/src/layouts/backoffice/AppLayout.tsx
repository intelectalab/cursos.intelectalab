import React, { Suspense, useCallback, useState } from 'react';
import { Outlet, useLocation } from 'react-router-dom';
import { ShieldAlert } from 'lucide-react';
import { useAuth } from '../../features/auth/AuthContext';
import { DebtAlerts } from '../../features/sales/components/DebtAlerts';
import { PlanBanner } from '../../features/settings/components/PlanBanner';
import { Sidebar } from './Sidebar';
import { Header } from './Header';
import { LoadingState } from '../../shared/ui';
import { DESKTOP_QUERY, useMediaQuery } from '../../shared/ui/useMediaQuery';

export const AppLayout: React.FC = () => {
  const [isCollapsed, setIsCollapsed] = useState(false);
  const [isDrawerOpen, setIsDrawerOpen] = useState(false);
  const isDesktop = useMediaQuery(DESKTOP_QUERY);
  const { pathname } = useLocation();
  const { session, logout, hasPermission } = useAuth();

  // Al pasar a escritorio el panel deja de existir: no debe reabrirse al volver a móvil
  if (isDesktop && isDrawerOpen) setIsDrawerOpen(false);

  // En escritorio el botón contrae el menú a íconos; en móvil y tableta abre el panel
  const toggleSidebar = () => {
    if (isDesktop) setIsCollapsed((prev) => !prev);
    else setIsDrawerOpen((prev) => !prev);
  };

  const closeDrawer = useCallback(() => setIsDrawerOpen(false), []);

  // dvh: en el teléfono descuenta la barra del navegador
  return (
    <div className="flex h-dvh bg-canvas overflow-hidden">
      {/* Primer elemento al tabular: evita recorrer todo el menú para llegar a la pantalla */}
      <a
        href="#contenido"
        className="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-[60] focus:px-4 focus:py-2 focus:rounded-lg focus:bg-white focus:text-brand-800 focus:text-sm focus:font-semibold focus:shadow-pop"
      >
        Saltar al contenido
      </a>
      <Sidebar
        isCollapsed={isDesktop && isCollapsed}
        isDrawer={!isDesktop}
        isDrawerOpen={isDrawerOpen}
        onClose={closeDrawer}
      />
      <div className="flex flex-col flex-1 min-w-0 overflow-hidden">
        {session?.support_mode && (
          <div role="status" className="flex flex-wrap items-center justify-center gap-x-3 gap-y-1 px-4 py-2 bg-amber-100 border-b border-amber-200 text-[13px] text-amber-900">
            <ShieldAlert className="w-4 h-4 shrink-0" />
            <span>
              <strong>Modo soporte de plataforma.</strong> Estás viendo esta academia como su administrador, en solo lectura: no puedes cambiar nada.
            </span>
            <button type="button" onClick={logout} className="font-semibold underline cursor-pointer">
              Salir
            </button>
          </div>
        )}
        {!session?.support_mode && hasPermission('tenant_settings.read') && <PlanBanner />}
        <Header onToggleSidebar={toggleSidebar} isMenuOpen={isDesktop ? !isCollapsed : isDrawerOpen} />
        <main id="contenido" tabIndex={-1} className="flex-1 overflow-y-auto focus:outline-none">
          {/* La clave reinicia la animación de entrada al cambiar de pantalla */}
          <div key={pathname} className="max-w-[1400px] mx-auto p-4 sm:p-7 lg:p-8 animate-rise">
            <Suspense fallback={<LoadingState />}>
              <Outlet />
            </Suspense>
          </div>
        </main>
        {/* Quien cobra ve aquí, sobre cualquier pantalla, las cuotas que vencieron */}
        {!session?.support_mode && <DebtAlerts />}
      </div>
    </div>
  );
};
