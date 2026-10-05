import React, { useMemo } from 'react';
import { NavLink } from 'react-router-dom';
import { useAuth } from '../../features/auth/AuthContext';
import { APP_MODULES, type ModuleItem } from '../../config/modules';
import { Layers } from 'lucide-react';
import { can } from '../../shared/auth/permissions';

interface SidebarProps {
  isCollapsed: boolean;
}

export const Sidebar: React.FC<SidebarProps> = ({ isCollapsed }) => {
  const { user, tenant, permissions } = useAuth();

  // Filtrado RBAC contra los permisos de la sesión (el backend es quien autoriza)
  const authorizedModules = useMemo(() => {
    if (!user) return [];

    // Sin permiso definido: visible para cualquier usuario autenticado (ej. Dashboard)
    return APP_MODULES.filter(
      (module: ModuleItem) => !module.permission || can(permissions, module.permission),
    );
  }, [user, permissions]);

  return (
    <aside
      className={`bg-[#052347] border-r border-[#031830] flex flex-col justify-between shrink-0 select-none transition-all duration-300 ease-in-out ${
        isCollapsed ? 'w-20' : 'w-64'
      }`}
    >
      {/* 1. Cabecera Fija del Logo */}
      <div
        className={`h-16 flex items-center border-b border-white/10 bg-[#041b37] shrink-0 transition-all duration-300 ${
          isCollapsed ? 'justify-center px-2' : 'gap-3 px-5'
        }`}
      >
        <div className="w-9 h-9 rounded-lg bg-gradient-to-br from-[#084586] to-[#0284C7] p-2 flex items-center justify-center shadow-md shadow-[#0284C7]/20 border border-white/15 shrink-0">
          <svg 
            className="w-full h-full text-white" 
            viewBox="0 0 48 48" 
            fill="none" 
            stroke="currentColor" 
            strokeWidth="2.5" 
            strokeLinecap="round" 
            strokeLinejoin="round"
          >
            <path d="M19 6h10M21 6v10l-11 18a4 4 0 003.4 6h21.2a4 4 0 003.4-6L27 16V6" />
            <path d="M14 28c3 1 7-1 10 0s7 1 10 0" fill="#38BDF8" fillOpacity="0.45" stroke="none" />
            <circle cx="24" cy="22" r="1.5" fill="currentColor" />
          </svg>
        </div>

        {!isCollapsed && (
          <div className="leading-tight overflow-hidden whitespace-nowrap">
            <span className="text-white font-black text-sm tracking-tight">
              Intelecta<span className="text-[#38BDF8]">Lab_</span>
            </span>
            <span className="block text-[9px] uppercase font-semibold text-blue-200 tracking-wider">
              SaaS Enterprise
            </span>
          </div>
        )}
      </div>

      {/* 2. Área de Módulos con Scrollbar Oculta / Independiente */}
      <div className="flex-1 overflow-y-auto overflow-x-hidden px-3 pt-5 pb-2 scrollbar-thin scrollbar-thumb-white/10">
        {!isCollapsed && (
          <p className="px-3 text-[10px] font-bold uppercase tracking-wider text-blue-300/60 mb-2">
            Módulos Habilitados ({authorizedModules.length})
          </p>
        )}

        <nav className="space-y-1.5">
          {authorizedModules.map((item) => (
            <NavLink
              key={item.id}
              to={item.href}
              title={isCollapsed ? item.name : undefined}
              className={({ isActive }) =>
                `group flex items-center rounded-lg text-xs font-medium transition-all duration-150 ${
                  isCollapsed ? 'justify-center h-10 w-10 mx-auto' : 'gap-3 px-3.5 py-2.5'
                } ${
                  isActive
                    ? 'bg-gradient-to-r from-[#084586] to-[#0a54a3] text-white shadow-sm shadow-[#0284C7]/25 border-l-2 border-[#38BDF8]'
                    : 'text-blue-100/75 hover:bg-white/5 hover:text-white'
                }`
              }
            >
              {({ isActive }) => (
                <>
                  <item.icon 
                    className={`w-4 h-4 shrink-0 transition-colors ${
                      isActive ? 'text-[#38BDF8]' : 'text-blue-300/70 group-hover:text-blue-200'
                    }`} 
                  />
                  {!isCollapsed && <span className="truncate">{item.name}</span>}
                </>
              )}
            </NavLink>
          ))}
        </nav>
      </div>

      {/* 3. Pie Fijo del Sidebar */}
      <div className="p-3 border-t border-white/10 bg-[#041b37]/60 shrink-0">
        <div
          className={`flex items-center gap-2.5 px-3 py-2 rounded-lg bg-white/5 border border-white/10 ${
            isCollapsed ? 'justify-center px-0' : ''
          }`}
          title={isCollapsed ? `Organización: ${tenant?.slug ?? ''}` : undefined}
        >
          <Layers className="w-4 h-4 text-[#38BDF8] shrink-0" />
          {!isCollapsed && (
            <div className="overflow-hidden">
              <p className="text-[10px] uppercase tracking-wider font-semibold text-blue-200">
                Organización
              </p>
              <p className="text-[11px] font-bold text-white truncate">
                <span className="text-[#38BDF8]">{window.location.hostname}</span>
              </p>
            </div>
          )}
        </div>
      </div>
    </aside>
  );
};