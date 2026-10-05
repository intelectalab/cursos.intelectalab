import React, { createContext, useContext, useEffect, useState } from 'react';
import { Building2, Loader2 } from 'lucide-react';
import { api, apiError } from '../api/client';
import { centralUrl, guessCentralDomain } from './hosts';

export interface CurrentTenant {
  id: string;
  name: string;
  slug: string;
}

interface TenantContextType {
  // null en el dominio central de la plataforma
  tenant: CurrentTenant | null;
  centralDomain: string;
}

type State =
  | { status: 'loading' }
  | { status: 'ready'; tenant: CurrentTenant | null; centralDomain: string }
  | { status: 'not_found' | 'inactive' | 'error' };

const TenantContext = createContext<TenantContextType | undefined>(undefined);

const Notice: React.FC<{ title: string; text: string; action?: React.ReactNode }> = ({ title, text, action }) => (
  <div className="min-h-screen flex flex-col items-center justify-center bg-[#F0F4F8] p-6 text-center">
    <div className="w-12 h-12 rounded-xl bg-[#084586]/10 text-[#084586] flex items-center justify-center mb-4">
      <Building2 className="w-6 h-6" />
    </div>
    <h1 className="text-lg font-bold text-slate-900">{title}</h1>
    <p className="text-xs text-slate-500 mt-1 max-w-sm">{text}</p>
    {action && <div className="mt-5">{action}</div>}
  </div>
);

export const TenantProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const [state, setState] = useState<State>({ status: 'loading' });

  useEffect(() => {
    let active = true;

    api
      .get('/tenant')
      .then((response) => {
        if (!active) return;
        setState({
          status: 'ready',
          tenant: response.data.data,
          centralDomain: response.data.meta.central_domain,
        });
      })
      .catch((error) => {
        if (!active) return;
        const code = apiError(error)?.code;
        setState({
          status: code === 'TENANT_NOT_FOUND' ? 'not_found' : code === 'TENANT_INACTIVE' ? 'inactive' : 'error',
        });
      });

    return () => {
      active = false;
    };
  }, []);

  if (state.status === 'loading') {
    return (
      <div className="min-h-screen flex items-center justify-center bg-[#F0F4F8]">
        <Loader2 className="w-6 h-6 animate-spin text-[#084586]" aria-label="Cargando" />
      </div>
    );
  }

  if (state.status === 'not_found') {
    return (
      <Notice
        title="Organización no encontrada"
        text={`No existe un espacio de trabajo en ${window.location.hostname}. Verifica la dirección o registra tu organización.`}
        action={
          <a
            href={centralUrl(guessCentralDomain(), '/register')}
            className="text-xs font-bold text-[#0284C7] hover:text-[#084586]"
          >
            Registrar nueva organización
          </a>
        }
      />
    );
  }

  if (state.status === 'inactive') {
    return (
      <Notice
        title="Organización suspendida"
        text="Este espacio de trabajo se encuentra suspendido o inactivo. Contacta al administrador de tu organización."
      />
    );
  }

  if (state.status !== 'ready') {
    return (
      <Notice
        title="No se pudo conectar con el servidor"
        text="Verifica tu conexión e inténtalo nuevamente."
        action={
          <button
            type="button"
            onClick={() => window.location.reload()}
            className="text-xs font-bold text-[#0284C7] hover:text-[#084586] cursor-pointer"
          >
            Reintentar
          </button>
        }
      />
    );
  }

  return (
    <TenantContext.Provider value={{ tenant: state.tenant, centralDomain: state.centralDomain }}>
      {children}
    </TenantContext.Provider>
  );
};

// eslint-disable-next-line react-refresh/only-export-components
export const useTenant = () => {
  const context = useContext(TenantContext);
  if (!context) {
    throw new Error('useTenant debe ser utilizado dentro de un TenantProvider');
  }
  return context;
};
