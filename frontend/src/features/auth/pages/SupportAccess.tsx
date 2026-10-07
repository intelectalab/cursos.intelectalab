import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../AuthContext';
import { api, apiErrorMessage } from '../../../shared/api/client';
import { clearSession } from '../../../shared/auth/sessionStorage';
import { useTenant } from '../../../shared/tenancy/TenantContext';
import { Alert, LoadingState } from '../../../shared/ui';
import { AuthShell } from '../../../shared/ui/AuthShell';

// Puerta de entrada del soporte de plataforma: llega con un acceso corto y de
// solo lectura en el fragmento de la dirección (que no viaja al servidor).
export const SupportAccess: React.FC = () => {
  const navigate = useNavigate();
  const { tenant } = useTenant();
  const { login } = useAuth();
  const [error, setError] = useState<string | null>(null);
  // Se lee una sola vez, al montar: después se borra de la barra de direcciones
  const [token] = useState(() => new URLSearchParams(window.location.hash.slice(1)).get('token'));

  useEffect(() => {
    let active = true;
    // El acceso no debe quedar en la barra de direcciones ni en el historial
    window.history.replaceState(null, '', window.location.pathname);

    const timer = setTimeout(() => {
      if (!token) {
        setError('Este enlace no trae un acceso de soporte. Ábrelo de nuevo desde la consola de plataforma.');
        return;
      }

      // Si había otra sesión en esta academia, la reemplaza
      clearSession();
      api
        .get('/auth/me', { headers: { Authorization: `Bearer ${token}` } })
        .then((response) => {
          if (!active) return;
          login({ ...response.data.data, token });
          navigate('/dashboard', { replace: true });
        })
        .catch((err) => active && setError(apiErrorMessage(err, 'El acceso de soporte no es válido o ya venció. Ábrelo de nuevo desde la consola de plataforma.')));
    }, 0);

    return () => {
      active = false;
      clearTimeout(timer);
    };
  }, [token, login, navigate]);

  return (
    <AuthShell tagline={tenant?.name} title="Acceso de soporte" subtitle="Entrando en modo solo lectura…">
      {error ? <Alert>{error}</Alert> : <LoadingState label="Abriendo la academia…" />}
    </AuthShell>
  );
};
