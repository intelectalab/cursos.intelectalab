import React, { useCallback, useEffect, useState } from 'react';
import { Building2, ExternalLink, LogOut } from 'lucide-react';
import { api, apiErrorMessage, UNAUTHORIZED_EVENT } from '../../../shared/api/client';
import {
  clearPlatformSession,
  loadPlatformSession,
  savePlatformSession,
  type PlatformSession,
} from '../../../shared/auth/sessionStorage';
import { tenantUrl } from '../../../shared/tenancy/hosts';
import { useTenant } from '../../../shared/tenancy/TenantContext';
import {
  Alert,
  Avatar,
  Badge,
  Button,
  Card,
  EmptyState,
  ErrorState,
  Field,
  INPUT_CLASS,
  LoadingState,
  PageHeader,
  StatCard,
  Table,
  TBody,
  Td,
  Th,
  THead,
  Tr,
} from '../../../shared/ui';
import { AuthShell, BrandMark } from '../../../shared/ui/AuthShell';

interface TenantRow {
  id: string;
  name: string;
  slug: string;
  status: 'ACTIVE' | 'SUSPENDED';
  users_count: number;
  created_at: string | null;
}


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

const fetchTenants = async () => (await api.get('/platform/tenants', { params: { per_page: 100 } })).data.data as TenantRow[];

const Tenants: React.FC = () => {
  const { centralDomain } = useTenant();
  const [rows, setRows] = useState<TenantRow[]>([]);
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [loadError, setLoadError] = useState('');
  const [processing, setProcessing] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [attempt, setAttempt] = useState(0);

  useEffect(() => {
    let active = true;
    fetchTenants()
      .then((data) => {
        if (!active) return;
        setRows(data);
        setState('ready');
      })
      .catch((err) => {
        if (!active) return;
        setLoadError(apiErrorMessage(err, 'Verifica tu conexión e inténtalo nuevamente.'));
        setState('error');
      });

    return () => {
      active = false;
    };
  }, [attempt]);

  const setStatus = async (tenant: TenantRow, status: TenantRow['status']) => {
    const suspending = status === 'SUSPENDED';
    if (
      suspending &&
      !window.confirm(`¿Suspender "${tenant.name}"? Sus usuarios no podrán entrar hasta que la reactives.`)
    ) {
      return;
    }

    setProcessing(tenant.id);
    setError(null);

    try {
      const response = await api.patch(`/platform/tenants/${tenant.id}/status`, { status });
      setRows((prev) => prev.map((row) => (row.id === tenant.id ? response.data.data : row)));
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo cambiar el estado de la organización.'));
    } finally {
      setProcessing(null);
    }
  };

  return (
    <>
      <PageHeader icon={Building2} title="Organizaciones" subtitle="Academias registradas en la plataforma." />
      {error && (
        <div className="mb-4">
          <Alert>{error}</Alert>
        </div>
      )}

      {state === 'ready' && (
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-5">
          <StatCard label="Organizaciones" value={rows.length} icon={Building2} tone="brand" />
          <StatCard
            label="Activas"
            value={rows.filter((row) => row.status === 'ACTIVE').length}
            icon={Building2}
            tone="success"
          />
          <StatCard
            label="Suspendidas"
            value={rows.filter((row) => row.status === 'SUSPENDED').length}
            icon={Building2}
            tone="warning"
          />
        </div>
      )}

      <Card>
        {state === 'loading' && <LoadingState />}
        {state === 'error' && (
          <ErrorState
            message={loadError}
            onRetry={() => {
              setState('loading');
              setAttempt((n) => n + 1);
            }}
          />
        )}
        {state === 'ready' && rows.length === 0 && <EmptyState title="Aún no hay organizaciones" />}

        {state === 'ready' && rows.length > 0 && (
          <Table>
            <THead>
              <Th>Organización</Th>
              <Th>Usuarios</Th>
              <Th>Estado</Th>
              <Th>Alta</Th>
              <Th />
            </THead>
            <TBody>
              {rows.map((tenant) => (
                <Tr key={tenant.id}>
                  <Td>
                    <div className="flex items-center gap-3">
                      <Avatar name={tenant.name} />
                      <div className="min-w-0">
                        <p className="font-semibold text-slate-900 truncate">{tenant.name}</p>
                        <a
                          href={tenantUrl(tenant.slug, centralDomain, '/login')}
                          target="_blank"
                          rel="noreferrer"
                          className="inline-flex items-center gap-1 text-xs text-brand-600 hover:text-brand-800"
                        >
                          {tenant.slug}.{centralDomain}
                          <ExternalLink className="w-3 h-3" />
                        </a>
                      </div>
                    </div>
                  </Td>
                  <Td className="text-slate-600 tnum">{tenant.users_count}</Td>
                  <Td>
                    <Badge tone={tenant.status === 'ACTIVE' ? 'success' : 'warning'} dot>
                      {tenant.status === 'ACTIVE' ? 'Activa' : 'Suspendida'}
                    </Badge>
                  </Td>
                  <Td className="text-slate-500 whitespace-nowrap tnum">
                    {tenant.created_at ? new Date(tenant.created_at).toLocaleDateString('es-PE') : '—'}
                  </Td>
                  <Td className="text-right">
                    {tenant.status === 'ACTIVE' ? (
                      <Button
                        size="sm"
                        variant="danger"
                        onClick={() => setStatus(tenant, 'SUSPENDED')}
                        processing={processing === tenant.id}
                        disabled={processing !== null}
                      >
                        Suspender
                      </Button>
                    ) : (
                      <Button
                        size="sm"
                        onClick={() => setStatus(tenant, 'ACTIVE')}
                        processing={processing === tenant.id}
                        disabled={processing !== null}
                      >
                        Reactivar
                      </Button>
                    )}
                  </Td>
                </Tr>
              ))}
            </TBody>
          </Table>
        )}
      </Card>
    </>
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
        <Tenants />
      </main>
    </div>
  );
};
