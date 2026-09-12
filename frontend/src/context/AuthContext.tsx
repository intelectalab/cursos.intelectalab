import React, { createContext, useContext, useState, useEffect } from 'react';
import type { AuthSession, User, Tenant } from '../types/auth';
import { api } from '../api/client';

interface AuthContextType {
  session: AuthSession | null;
  user: User | null;
  tenant: Tenant | null;
  permissions: string[];
  isAuthenticated: boolean;
  login: (data: AuthSession) => void;
  logout: () => void;
  hasPermission: (permission: string) => boolean;
}

const AuthContext = createContext<AuthContextType | undefined>(undefined);

export const AuthProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const [session, setSession] = useState<AuthSession | null>(() => {
    const saved = localStorage.getItem('erp_session');
    return saved ? JSON.parse(saved) : null;
  });

  useEffect(() => {
    if (session?.token) {
      api.defaults.headers.common['Authorization'] = `Bearer ${session.token}`;
      localStorage.setItem('erp_session', JSON.stringify(session));
    } else {
      delete api.defaults.headers.common['Authorization'];
      localStorage.removeItem('erp_session');
    }
  }, [session]);

  const login = (data: AuthSession) => setSession(data);
  const logout = () => setSession(null);

  const hasPermission = (permission: string) => {
    return session?.permissions.includes(permission) ?? false;
  };

  return (
    <AuthContext.Provider
      value={{
        session,
        user: session?.user ?? null,
        tenant: session?.tenant ?? null,
        permissions: session?.permissions ?? [],
        isAuthenticated: !!session,
        login,
        logout,
        hasPermission,
      }}
    >
      {children}
    </AuthContext.Provider>
  );
};

export const useAuth = () => {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error('useAuth debe ser utilizado dentro de un AuthProvider');
  }
  return context;
};