import React, { useCallback, useEffect, useState } from 'react';
import { NavLink, useLocation, useParams } from 'react-router-dom';
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
import { PlatformEmailsPanel } from '../../emails/components/PlatformEmailsPanel';
import { BackupsPanel } from '../components/BackupsPanel';
import { DashboardPanel } from '../components/DashboardPanel';
import { PaymentsPanel } from '../components/PaymentsPanel';
import { PlansPanel } from '../components/PlansPanel';
import { TenantProfile } from '../components/TenantProfile';
import { TenantsPanel } from '../components/TenantsPanel';
import { platformApi } from '../api';



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
  // Con una academia en la dirección se muestra su ficha; sin ella, la lista
  const { tenantId } = useParams();
  const { pathname } = useLocation();
  const onPlans = pathname === '/platform/plans';
  const onPayments = pathname === '/platform/payments';
  const onPanel = pathname === '/platform/panel';
  const onEmails = pathname === '/platform/emails';
  const onBackups = pathname === '/platform/backups';
  const [session, setSession] = useState<PlatformSession | null>(loadPlatformSession);
  // Órdenes de plan que esperan a la plataforma: el contador de la pestaña Pagos
  const [waiting, setWaiting] = useState<{ pending: number; informed: number; review: number } | null>(null);

  const clear = useCallback(() => {
    clearPlatformSession();
    setSession(null);
  }, []);

  useEffect(() => {
    window.addEventListener(UNAUTHORIZED_EVENT, clear);
    return () => window.removeEventListener(UNAUTHORIZED_EVENT, clear);
  }, [clear]);

  // Se vuelve a contar al cambiar de sección: confirmar o cancelar una orden cambia el número
  useEffect(() => {
    if (!session) return;
    let active = true;
    platformApi
      .planOrdersSummary()
      .then((summary) => active && setWaiting(summary))
      // Sin el contador la consola funciona igual
      .catch(() => undefined);
    return () => {
      active = false;
    };
  }, [session, pathname]);

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
      <main className={`${onPanel || onEmails ? 'max-w-7xl' : 'max-w-6xl'} mx-auto p-6 sm:p-8 animate-rise`}>
        <nav aria-label="Secciones de la consola" className="inline-flex gap-1 p-1 mb-6 rounded-xl bg-slate-100 border border-slate-200/70">
          {[
            { to: '/platform/panel', label: 'Panel', active: onPanel, count: 0, urgent: false },
            { to: '/platform', label: 'Academias', active: !onPlans && !onPayments && !onPanel && !onEmails && !onBackups, count: 0, urgent: false },
            { to: '/platform/plans', label: 'Planes', active: onPlans, count: 0, urgent: false },
            {
              to: '/platform/payments',
              label: 'Pagos',
              active: onPayments,
              count: (waiting?.pending ?? 0) + (waiting?.review ?? 0),
              // Hay pagos que la academia ya informó, o cobros de la pasarela por revisar
              urgent: (waiting?.informed ?? 0) + (waiting?.review ?? 0) > 0,
            },
            { to: '/platform/emails', label: 'Correos', active: onEmails, count: 0, urgent: false },
            { to: '/platform/backups', label: 'Respaldos', active: onBackups, count: 0, urgent: false },
          ].map((item) => (
            <NavLink
              key={item.to}
              to={item.to}
              end
              aria-current={item.active ? 'page' : undefined}
              className={`inline-flex items-center h-9 px-3.5 rounded-lg text-[13px] font-semibold transition-all ${
                item.active ? 'bg-white text-brand-700 shadow-xs' : 'text-slate-600 hover:text-slate-900'
              }`}
            >
              {item.label}
              {item.count > 0 && (
                <span
                  className={`ml-1.5 inline-flex items-center justify-center min-w-5 h-5 px-1.5 rounded-full text-[11px] font-bold tnum ${
                    item.urgent ? 'bg-amber-500 text-white' : 'bg-slate-300 text-slate-700'
                  }`}
                  title={`${waiting?.pending ?? 0} por confirmar, ${waiting?.informed ?? 0} con pago informado, ${waiting?.review ?? 0} cobros por revisar`}
                >
                  {item.count}
                  <span className="sr-only"> órdenes esperan</span>
                </span>
              )}
            </NavLink>
          ))}
        </nav>
        {onPanel ? <DashboardPanel /> : onEmails ? <PlatformEmailsPanel /> : onBackups ? <BackupsPanel /> : onPayments ? <PaymentsPanel /> : onPlans ? <PlansPanel /> : tenantId ? <TenantProfile key={tenantId} tenantId={tenantId} /> : <TenantsPanel />}
      </main>
    </div>
  );
};
