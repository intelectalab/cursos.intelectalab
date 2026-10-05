import React, { useCallback, useState } from 'react';
import { Outlet, useLocation } from 'react-router-dom';
import { Sidebar } from './Sidebar';
import { Header } from './Header';
import { DESKTOP_QUERY, useMediaQuery } from '../../shared/ui/useMediaQuery';

export const AppLayout: React.FC = () => {
  const [isCollapsed, setIsCollapsed] = useState(false);
  const [isDrawerOpen, setIsDrawerOpen] = useState(false);
  const isDesktop = useMediaQuery(DESKTOP_QUERY);
  const { pathname } = useLocation();

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
      <Sidebar
        isCollapsed={isDesktop && isCollapsed}
        isDrawer={!isDesktop}
        isDrawerOpen={isDrawerOpen}
        onClose={closeDrawer}
      />
      <div className="flex flex-col flex-1 min-w-0 overflow-hidden">
        <Header onToggleSidebar={toggleSidebar} isMenuOpen={isDesktop ? !isCollapsed : isDrawerOpen} />
        <main className="flex-1 overflow-y-auto">
          {/* La clave reinicia la animación de entrada al cambiar de pantalla */}
          <div key={pathname} className="max-w-[1400px] mx-auto p-4 sm:p-7 lg:p-8 animate-rise">
            <Outlet />
          </div>
        </main>
      </div>
    </div>
  );
};
