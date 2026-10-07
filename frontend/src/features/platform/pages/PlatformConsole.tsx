import React, { useCallback, useEffect, useState } from 'react';
import { LogOut } from 'lucide-react';
import { api, apiErrorMessage, UNAUTHORIZED_EVENT } from '../../../shared/api/client';
import {
  clearPlatformSession,
  loadPlatformSession,
  savePlatformSession,
  type PlatformSession,
} from '../../../shared/auth/sessionStorage';
import { Alert, Avatar, Button, Field, INPUT_CLASS } from '../../../shared/ui';
import { AuthShell, BrandMark } from '../../../shared/ui/AuthShell';
import { TenantsPanel } from '../components/TenantsPanel';



const PlatformLogin: React.FC<{ onLogin: (session: PlatformSession) => void }> = ({ onLogin }) => {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    setProcessing(true);
    setError(null);

    try {
      const response = await api.post('/platform/auth/login', { email, password });
      onLogin(response.data.data);
    } catch (err) {
      setError(apiErrorMessage(err, 'Credenciales no válidas o error en el servidor.'));
      setProcessing(false);
    }
  };

  return (
    <AuthShell
      tagline="Administración de la plataforma"
      title="Acceso de plataforma"
      subtitle="Exclusivo del equipo de IntelectaLab."
    >
      <form onSubmit={handleSubmit} className="space-y-4">
        {error && <Alert>{error}</Alert>}
        <Field label="Correo electrónico">
          <input className={INPUT_CLASS} type="email" required autoFocus value={email} onChange={(e) => setEmail(e.target.value)} />
        </Field>
        <Field label="Contraseña">
          <input
            className={INPUT_CLASS}
            type="password"
            required
            autoComplete="current-password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
          />
        </Field>
        <Button variant="primary" size="lg" type="submit" className="w-full" processing={processing}>
          Iniciar sesión
        </Button>
      </form>
    </AuthShell>
  );
};

// Consola del administrador de plataforma: solo existe en el dominio central.
export const PlatformConsole: React.FC = () => {
  const [session, setSession] = useState<PlatformSession | null>(loadPlatformSession);

  const clear = useCallback(() => {
    clearPlatformSession();
    setSession(null);
  }, []);

  useEffect(() => {
    window.addEventListener(UNAUTHORIZED_EVENT, clear);
    return () => window.removeEventListener(UNAUTHORIZED_EVENT, clear);
  }, [clear]);

  if (!session) {
    return (
      <PlatformLogin
        onLogin={(data) => {
          savePlatformSession(data);
          setSession(data);
        }}
      />
    );
  }

  const logout = async () => {
    try {
      await api.post('/platform/auth/logout');
    } catch {
      // La sesión local se cierra aunque el servidor no responda
    }
    clear();
  };

  return (
    <div className="min-h-screen bg-canvas">
      <header className="relative overflow-hidden bg-gradient-to-r from-brand-900 via-brand-800 to-brand-700">
        <div className="absolute -top-24 right-10 w-72 h-72 rounded-full bg-sky-400/25 blur-3xl pointer-events-none" />
        <div className="relative max-w-6xl mx-auto h-16 px-6 flex items-center justify-between gap-4">
          <BrandMark tagline="Administración de la plataforma" />
          <div className="flex items-center gap-3">
            <div className="hidden sm:flex items-center gap-2.5">
              <Avatar name={session.admin.name} size="sm" />
              <div className="leading-tight">
                <p className="text-[13px] font-semibold text-white">{session.admin.name}</p>
                <p className="text-xs text-brand-200">{session.admin.email}</p>
              </div>
            </div>
            <button
              type="button"
              onClick={logout}
              className="inline-flex items-center gap-2 h-9 px-3 rounded-lg bg-white/10 ring-1 ring-white/20 text-[13px] font-semibold text-white hover:bg-white/20 transition-colors cursor-pointer"
            >
              <LogOut className="w-4 h-4" />
              Cerrar sesión
            </button>
          </div>
        </div>
      </header>
      <main className="max-w-6xl mx-auto p-6 sm:p-8 animate-rise">
        <TenantsPanel />
      </main>
    </div>
  );
};
