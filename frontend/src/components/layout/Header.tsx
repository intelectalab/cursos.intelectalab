import React from 'react';
import { useAuth } from '../../context/AuthContext';
import { LogOut, Building2, UserCircle } from 'lucide-react';

export const Header: React.FC = () => {
  const { user, tenant, logout } = useAuth();

  return (
    <header className="h-16 bg-white border-b border-slate-200 px-6 flex items-center justify-between sticky top-0 z-10">
      <div className="flex items-center gap-3">
        <div className="flex items-center gap-2 bg-slate-100 px-3 py-1.5 rounded-md border border-slate-200">
          <Building2 className="w-4 h-4 text-slate-500" />
          <span className="text-xs font-semibold text-slate-700 tracking-tight">
            {tenant?.name || 'Organización'}
          </span>
        </div>
      </div>

      <div className="flex items-center gap-4">
        <div className="flex items-center gap-2.5 text-right">
          <UserCircle className="w-8 h-8 text-slate-400" />
          <div className="text-left">
            <p className="text-xs font-semibold text-slate-800">
              {user?.first_name} {user?.last_name}
            </p>
            <p className="text-[10px] font-medium text-slate-500 uppercase tracking-wide">
              {user?.roles[0] || 'Usuario'}
            </p>
          </div>
        </div>

        <button
          onClick={logout}
          title="Cerrar Sesión"
          className="p-2 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors cursor-pointer"
        >
          <LogOut className="w-4 h-4" />
        </button>
      </div>
    </header>
  );
};