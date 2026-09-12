import React from 'react';
import { Navigate, Outlet } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';

interface ProtectedRouteProps {
  requiredPermission?: string;
}

export const ProtectedRoute: React.FC<ProtectedRouteProps> = ({ requiredPermission }) => {
  const { isAuthenticated, hasPermission } = useAuth();

  if (!isAuthenticated) {
    return <Navigate to="/login" replace />;
  }

  if (requiredPermission && !hasPermission(requiredPermission)) {
    return (
      <div className="flex h-full min-h-[400px] flex-col items-center justify-center p-6 text-center">
        <div className="rounded-full bg-amber-50 p-4 text-amber-600 mb-3">
          <span className="font-semibold text-sm">403</span>
        </div>
        <h2 className="text-lg font-bold text-slate-800">Acceso Denegado</h2>
        <p className="text-xs text-slate-500 mt-1 max-w-sm">
          No cuentas con el permiso requerido ({requiredPermission}) para ver esta sección.
        </p>
      </div>
    );
  }

  return <Outlet />;
};