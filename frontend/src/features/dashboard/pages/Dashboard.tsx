import React from 'react';
import { useAuth } from '../../auth/AuthContext';
import { Building2, ShieldCheck, Globe, Key } from 'lucide-react';

export const Dashboard: React.FC = () => {
  const { user, tenant, permissions } = useAuth();

  return (
    <div className="space-y-6">
      <div className="bg-white border border-slate-200 rounded-xl p-6 shadow-sm">
        <h1 className="text-xl font-bold text-slate-900 tracking-tight">
          Bienvenido, {user?.first_name} {user?.last_name}
        </h1>
        <p className="text-xs text-slate-500 mt-1">
          Sesión operativa activa en la infraestructura multi-tenant aislada.
        </p>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div className="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
          <div className="flex items-center gap-3">
            <Building2 className="w-8 h-8 text-slate-700 bg-slate-100 p-1.5 rounded-lg" />
            <div>
              <p className="text-[11px] text-slate-500 uppercase font-semibold">Tenant Activo</p>
              <p className="text-sm font-bold text-slate-800">{tenant?.name}</p>
            </div>
          </div>
        </div>

        <div className="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
          <div className="flex items-center gap-3">
            <ShieldCheck className="w-8 h-8 text-emerald-600 bg-emerald-50 p-1.5 rounded-lg" />
            <div>
              <p className="text-[11px] text-slate-500 uppercase font-semibold">Rol Asignado</p>
              <p className="text-sm font-bold text-slate-800">{user?.roles.join(', ')}</p>
            </div>
          </div>
        </div>

        <div className="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
          <div className="flex items-center gap-3">
            <Key className="w-8 h-8 text-indigo-600 bg-indigo-50 p-1.5 rounded-lg" />
            <div>
              <p className="text-[11px] text-slate-500 uppercase font-semibold">Permisos RBAC</p>
              <p className="text-sm font-bold text-slate-800">{permissions.length} activos</p>
            </div>
          </div>
        </div>

        <div className="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
          <div className="flex items-center gap-3">
            <Globe className="w-8 h-8 text-cyan-600 bg-cyan-50 p-1.5 rounded-lg" />
            <div>
              <p className="text-[11px] text-slate-500 uppercase font-semibold">Subdominio</p>
              <p className="text-sm font-bold text-slate-800">{tenant?.slug}</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};