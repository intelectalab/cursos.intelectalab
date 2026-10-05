import React from 'react';
import { Navigate, Outlet } from 'react-router-dom';
import { Lock } from 'lucide-react';
import { useAuth } from './AuthContext';
import { EmptyState } from '../../shared/ui';

export const ProtectedRoute: React.FC = () => {
  const { isAuthenticated } = useAuth();

  if (!isAuthenticated) {
    return <Navigate to="/login" replace />;
  }

  return <Outlet />;
};

interface PermissionGateProps {
  permission?: string | string[];
  children: React.ReactNode;
}

// Control visual: la autorización real la aplica el backend en cada endpoint.
export const PermissionGate: React.FC<PermissionGateProps> = ({ permission, children }) => {
  const { hasPermission } = useAuth();

  if (permission && !hasPermission(permission)) {
    return (
      <EmptyState
        icon={Lock}
        title="No tienes acceso a esta sección"
        text="Tu rol no incluye el permiso necesario. Si lo necesitas, pídeselo al administrador de tu organización."
      />
    );
  }

  return <>{children}</>;
};
