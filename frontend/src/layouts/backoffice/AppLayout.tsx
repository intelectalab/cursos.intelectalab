import React, { useState } from 'react';
import { Outlet, useLocation } from 'react-router-dom';
import { Sidebar } from './Sidebar';
import { Header } from './Header';

export const AppLayout: React.FC = () => {
  const [isCollapsed, setIsCollapsed] = useState(false);
  const { pathname } = useLocation();

  const toggleSidebar = () => setIsCollapsed((prev) => !prev);

  return (
    <div className="flex h-screen bg-canvas overflow-hidden">
      <Sidebar isCollapsed={isCollapsed} />
      <div className="flex flex-col flex-1 min-w-0 overflow-hidden">
        <Header onToggleSidebar={toggleSidebar} isCollapsed={isCollapsed} />
        <main className="flex-1 overflow-y-auto">
          {/* La clave reinicia la animación de entrada al cambiar de pantalla */}
          <div key={pathname} className="max-w-[1400px] mx-auto p-5 sm:p-7 lg:p-8 animate-rise">
            <Outlet />
          </div>
        </main>
      </div>
    </div>
  );
};
