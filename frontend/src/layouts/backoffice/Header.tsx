import React from 'react';
import { useAuth } from '../../features/auth/AuthContext';
import { LogOut, Building2, Bell, Shield, Menu } from 'lucide-react';

interface HeaderProps {
  onToggleSidebar: () => void;
  isCollapsed: boolean;
}

export const Header: React.FC<HeaderProps> = ({ onToggleSidebar }) => {
  const { user, tenant, logout } = useAuth();

  const initials = `${user?.first_name?.[0] ?? ''}${user?.last_name?.[0] ?? ''}`.toUpperCase() || 'U';

  return (
    <header className="h-16 bg-white border-b border-slate-200/80 px-4 sm:px-6 flex items-center justify-between sticky top-0 z-20 select-none shadow-[0_1px_2px_rgba(0,0,0,0.03)]">
      {/* Botón Hamburguesa e Información del Tenant */}
      <div className="flex items-center gap-3">
        <button
          type="button"
          onClick={onToggleSidebar}
          aria-label="Alternar barra lateral"
          className="p-2 text-slate-500 hover:text-[#084586] hover:bg-slate-100 rounded-lg transition-colors cursor-pointer"
        >
          <Menu className="w-5 h-5" />
        </button>

        <div className="flex items-center gap-2.5 bg-slate-50 border border-slate-200/90 px-3 py-1.5 rounded-lg">
          <div className="w-6 h-6 rounded-md bg-[#084586]/10 text-[#084586] flex items-center justify-center">
            <Building2 className="w-3.5 h-3.5 text-[#084586]" />
          </div>
          <div className="hidden sm:flex flex-col">
            <span className="text-[9px] font-bold uppercase tracking-wider text-slate-400 leading-none">
              Organización
            </span>
            <span className="text-xs font-bold text-slate-800 tracking-tight mt-0.5">
              {tenant?.name}
            </span>
          </div>
          <span className="inline-flex items-center px-1.5 py-0.5 rounded-full text-[9px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
            Activo
          </span>
        </div>
      </div>

      {/* Perfil y Acciones */}
      <div className="flex items-center gap-3 sm:gap-4">
        <button
          type="button"
          className="p-2 text-slate-400 hover:text-[#084586] hover:bg-slate-100 rounded-lg transition-colors relative cursor-pointer"
          title="Notificaciones del Sistema"
        >
          <Bell className="w-4 h-4" />
          <span className="absolute top-1.5 right-1.5 w-2 h-2 bg-[#0284C7] rounded-full ring-2 ring-white" />
        </button>

        <div className="h-6 w-px bg-slate-200" />

        <div className="flex items-center gap-3">
          <div className="w-9 h-9 rounded-lg bg-gradient-to-tr from-[#062F5E] via-[#084586] to-[#0284C7] text-white flex items-center justify-center font-bold text-xs shadow-sm shadow-[#062F5E]/20">
            {initials}
          </div>
          <div className="hidden md:block text-left">
            <p className="text-xs font-bold text-slate-900 leading-tight">
              {user?.first_name} {user?.last_name}
            </p>
            <div className="flex items-center gap-1 mt-0.5">
              <Shield className="w-3 h-3 text-[#0284C7]" />
              <span className="text-[10px] font-semibold text-[#084586] uppercase tracking-wide">
                {user?.roles[0]}
              </span>
            </div>
          </div>
        </div>

        <button
          onClick={logout}
          title="Cerrar Sesión"
          className="p-2 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors cursor-pointer ml-1"
        >
          <LogOut className="w-4 h-4" />
        </button>
      </div>
    </header>
  );
};