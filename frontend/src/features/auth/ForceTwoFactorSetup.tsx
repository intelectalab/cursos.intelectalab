import React from 'react';
import { useAuth } from './AuthContext';
import { AuthShell } from '../../shared/ui/AuthShell';
import { TwoFactorCard } from '../account/components/TwoFactorCard';

// La academia exige la verificación en dos pasos a su personal: hasta
// activarla no se entra a ninguna pantalla. El servidor bloquea lo demás.
export const ForceTwoFactorSetup: React.FC = () => {
  const { user, tenant, refresh, logout } = useAuth();

  return (
    <AuthShell
      tagline={tenant?.name}
      title="Activa la verificación en dos pasos"
      subtitle={`Hola, ${user?.first_name ?? ''}. ${tenant?.name ?? 'Tu academia'} pide a su personal un código del teléfono además de la contraseña. Actívala para continuar.`}
      footer={
        <button type="button" onClick={logout} className="font-semibold text-sky-200 hover:text-white transition-colors cursor-pointer">
          Cerrar sesión
        </button>
      }
    >
      <TwoFactorCard bare onChanged={() => void refresh()} />
    </AuthShell>
  );
};
