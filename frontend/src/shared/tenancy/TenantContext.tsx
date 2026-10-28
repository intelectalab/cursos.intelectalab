import React, { createContext, useCallback, useContext, useEffect, useState } from 'react';
import { Building2, Loader2 } from 'lucide-react';
import { api, apiError } from '../api/client';
import { centralUrl, displayAddress, guessCentralDomain, setTenancyMode, type TenancyMode } from './hosts';
import { applyTheme, type Appearance } from './theme';

export interface CurrentTenant {
  id: string;
  name: string;
  slug: string;
  logo_url: string | null;
  // Colores y pantalla de acceso de la academia (Configuración → Apariencia)
  appearance: Appearance;
}

interface TenantContextType {
  // null en el dominio central de la plataforma
  tenant: CurrentTenant | null;
  centralDomain: string;
  // Refleja en toda la aplicación un cambio hecho en Configuración
  setLogoUrl: (logoUrl: string | null) => void;
  setAppearance: (appearance: Appearance) => void;
}

type State =
  | { status: 'loading' }
  | { status: 'ready'; tenant: CurrentTenant | null; centralDomain: string }
  | { status: 'not_found' | 'inactive' | 'error' };

const TenantContext = createContext<TenantContextType | undefined>(undefined);

// Icono de la pestaña cuando la academia no tiene logo
const DEFAULT_TAB_ICON = '/intelectalab.svg';

const Notice: React.FC<{ title: string; text: string; action?: React.ReactNode }> = ({ title, text, action }) => (
  <div className="relative min-h-screen flex flex-col items-center justify-center overflow-hidden bg-gradient-to-br from-brand-900 via-brand-800 to-brand-600 p-6 text-center">
    <div className="absolute -top-24 -left-24 w-[420px] h-[420px] rounded-full bg-accent-500/25 blur-[110px] pointer-events-none" />
    <div className="relative w-full max-w-[420px] bg-white rounded-3xl shadow-pop p-9">
      <div className="w-12 h-12 mx-auto rounded-2xl bg-brand-50 text-brand-600 flex items-center justify-center mb-4 ring-8 ring-brand-50/50">
        <Building2 className="w-5 h-5" />
      </div>
      <h1 className="text-lg font-bold text-slate-900 tracking-tight">{title}</h1>
      <p className="text-[13px] text-slate-500 mt-1.5">{text}</p>
      {action && <div className="mt-6">{action}</div>}
    </div>
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
        setTenancyMode(response.data.meta.tenancy_mode as TenancyMode | undefined);
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

  // El icono de la pestaña es el logo de la academia; sin logo, o en el dominio central, el de IntelectaLab
  const tabIcon = state.status === 'ready' ? (state.tenant?.logo_url ?? null) : null;

  useEffect(() => {
    let link = document.querySelector<HTMLLinkElement>('link[rel~="icon"]');

    if (!link) {
      link = document.createElement('link');
      link.rel = 'icon';
      document.head.appendChild(link);
    }

    if (tabIcon) {
      // El logo puede ser PNG o JPG: sin tipo fijo, el navegador lo deduce
      link.removeAttribute('type');
      link.href = tabIcon;
    } else {
      link.type = 'image/svg+xml';
      link.href = DEFAULT_TAB_ICON;
    }
  }, [tabIcon]);

  // Los colores de la academia en toda la aplicación; en el dominio central, los de IntelectaLab
  const primary = state.status === 'ready' ? (state.tenant?.appearance?.primary ?? null) : null;
  const accent = state.status === 'ready' ? (state.tenant?.appearance?.accent ?? null) : null;
  useEffect(() => applyTheme(primary, accent), [primary, accent]);

  const setAppearance = useCallback((appearance: Appearance) => {
    setState((current) => (current.status === 'ready' && current.tenant ? { ...current, tenant: { ...current.tenant, appearance } } : current));
  }, []);

  const setLogoUrl = useCallback((logoUrl: string | null) => {
    setState((current) =>
      current.status === 'ready' && current.tenant
        ? { ...current, tenant: { ...current.tenant, logo_url: logoUrl } }
        : current,
    );
  }, []);

  if (state.status === 'loading') {
    return (
      <div className="min-h-screen flex items-center justify-center bg-canvas">
        <Loader2 className="w-6 h-6 animate-spin text-brand-600" aria-label="Cargando" />
      </div>
    );
  }

  if (state.status === 'not_found') {
    return (
      <Notice
        title="Organización no encontrada"
        text={`No existe un espacio de trabajo en ${displayAddress()}. Verifica la dirección o registra tu organización.`}
        action={
          <a
            href={centralUrl(guessCentralDomain(), '/register')}
            className="inline-flex items-center justify-center h-9 px-4 rounded-lg text-[13px] font-semibold text-white bg-gradient-to-r from-brand-700 to-brand-600 hover:from-brand-800 hover:to-brand-700"
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
            className="inline-flex items-center justify-center h-9 px-4 rounded-lg text-[13px] font-semibold text-white bg-gradient-to-r from-brand-700 to-brand-600 hover:from-brand-800 hover:to-brand-700 cursor-pointer"
          >
            Reintentar
          </button>
        }
      />
    );
  }

  return (
    <TenantContext.Provider value={{ tenant: state.tenant, centralDomain: state.centralDomain, setLogoUrl, setAppearance }}>
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
