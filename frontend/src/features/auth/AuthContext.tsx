import React, { createContext, useCallback, useContext, useEffect, useState } from 'react';
import type { AuthSession, User, Tenant } from './types';
import { api, UNAUTHORIZED_EVENT } from '../../shared/api/client';
import { can } from '../../shared/auth/permissions';
import { clearSession, loadSession, saveSession } from '../../shared/auth/sessionStorage';

interface AuthContextType {
  session: AuthSession | null;
  user: User | null;
  tenant: Tenant | null;
  permissions: string[];
  isAuthenticated: boolean;
  login: (data: AuthSession) => void;
  logout: () => Promise<void>;
  hasPermission: (permission: string | string[]) => boolean;
}

const AuthContext = createContext<AuthContextType | undefined>(undefined);

export const AuthProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const [session, setSession] = useState<AuthSession | null>(loadSession);

  const login = useCallback((data: AuthSession) => {
    saveSession(data);
    setSession(data);
  }, []);

  const clear = useCallback(() => {
    clearSession();
    setSession(null);
  }, []);

  const logout = useCallback(async () => {
    try {
      await api.post('/auth/logout');
    } catch {
      // La sesión local se cierra aunque el servidor no responda
    }
    clear();
  }, [clear]);

  // La sesión guardada se revalida contra el servidor al cargar la aplicación:
  // roles y permisos pueden haber cambiado, o el token haber sido revocado.
  useEffect(() => {
    const saved = loadSession();
    if (!saved) return;

    let active = true;
    api
      .get('/auth/me')
      .then((response) => {
        if (active) login({ ...response.data.data, token: saved.token });
      })
      .catch(() => {
        // Un 401 cierra la sesión mediante UNAUTHORIZED_EVENT
      });

    return () => {
      active = false;
    };
  }, [login]);

  useEffect(() => {
    window.addEventListener(UNAUTHORIZED_EVENT, clear);
    return () => window.removeEventListener(UNAUTHORIZED_EVENT, clear);
  }, [clear]);

  const permissions = session?.permissions ?? [];

  return (
    <AuthContext.Provider
      value={{
        session,
        user: session?.user ?? null,
        tenant: session?.tenant ?? null,
        permissions,
        isAuthenticated: !!session,
        login,
        logout,
        hasPermission: (permission) => can(permissions, permission),
      }}
    >
      {children}
    </AuthContext.Provider>
  );
};

// eslint-disable-next-line react-refresh/only-export-components
export const useAuth = () => {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error('useAuth debe ser utilizado dentro de un AuthProvider');
  }
  return context;
};
