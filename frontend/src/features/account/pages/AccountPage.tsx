import React, { useCallback, useEffect, useState } from 'react';
import { KeyRound, MonitorSmartphone, UserRound } from 'lucide-react';
import { useAuth } from '../../auth/AuthContext';
import { TwoFactorCard } from '../components/TwoFactorCard';
import { EmailPreferencesCard } from '../components/EmailPreferencesCard';
import { api, apiErrorMessage } from '../../../shared/api/client';
import {
  Alert,
  Avatar,
  Badge,
  Button,
  Card,
  CardHeader,
  ErrorState,
  Field,
  IconChip,
  INPUT_CLASS,
  LoadingState,
  PageHeader,
} from '../../../shared/ui';

interface SessionRow {
  id: number;
  current: boolean;
  ip_address: string | null;
  user_agent: string | null;
  created_at: string | null;
  last_used_at: string | null;
  expires_at: string | null;
}

const formatDate = (value: string | null) =>
  value ? new Date(value).toLocaleString('es-PE', { dateStyle: 'short', timeStyle: 'short' }) : '—';

// Resumen legible del dispositivo a partir del user agent
function describeDevice(userAgent: string | null): string {
  if (!userAgent) return 'Dispositivo desconocido';

  const browser =
    [
      ['Edg/', 'Edge'],
      ['OPR/', 'Opera'],
      ['Chrome/', 'Chrome'],
      ['Firefox/', 'Firefox'],
      ['Safari/', 'Safari'],
    ].find(([mark]) => userAgent.includes(mark))?.[1] ?? 'Navegador';

  const system =
    [
      ['Android', 'Android'],
      ['iPhone', 'iPhone'],
      ['iPad', 'iPad'],
      ['Windows', 'Windows'],
      ['Mac OS', 'macOS'],
      ['Linux', 'Linux'],
    ].find(([mark]) => userAgent.includes(mark))?.[1] ?? 'sistema desconocido';

  return `${browser} en ${system}`;
}

const fetchSessions = async () => (await api.get('/auth/sessions')).data.data as SessionRow[];

const PasswordCard: React.FC<{ onChanged: () => void }> = ({ onChanged }) => {
  const [form, setForm] = useState({ current_password: '', password: '', password_confirmation: '' });
  const [status, setStatus] = useState<'idle' | 'processing' | 'done'>('idle');
  const [error, setError] = useState<string | null>(null);

  const set = (key: keyof typeof form) => (event: React.ChangeEvent<HTMLInputElement>) => {
    setForm((prev) => ({ ...prev, [key]: event.target.value }));
    setStatus('idle');
  };

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    if (form.password !== form.password_confirmation) {
      setError('Las contraseñas no coinciden.');
      return;
    }

    setStatus('processing');
    setError(null);

    try {
      await api.put('/auth/password', form);
      setForm({ current_password: '', password: '', password_confirmation: '' });
      setStatus('done');
      onChanged();
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo cambiar la contraseña.'));
      setStatus('idle');
    }
  };

  return (
    <Card className="p-5 sm:p-6">
      <div className="flex items-center gap-3.5 mb-5">
        <IconChip icon={KeyRound} tone="warning" />
        <div>
          <h2 className="text-sm font-semibold text-slate-900">Contraseña</h2>
          <p className="text-[13px] text-slate-500">Al cambiarla se cierran tus otras sesiones.</p>
        </div>
      </div>

      <form onSubmit={handleSubmit} className="space-y-4">
        {error && <Alert>{error}</Alert>}
        {status === 'done' && <Alert tone="success">Contraseña actualizada.</Alert>}
        <Field label="Contraseña actual">
          <input
            className={INPUT_CLASS}
            type="password"
            required
            autoComplete="current-password"
            value={form.current_password}
            onChange={set('current_password')}
          />
        </Field>
        <Field label="Contraseña nueva" hint="Mínimo 8 caracteres.">
          <input
            className={INPUT_CLASS}
            type="password"
            required
            minLength={8}
            autoComplete="new-password"
            value={form.password}
            onChange={set('password')}
          />
        </Field>
        <Field label="Repetir contraseña nueva">
          <input
            className={INPUT_CLASS}
            type="password"
            required
            minLength={8}
            autoComplete="new-password"
            value={form.password_confirmation}
            onChange={set('password_confirmation')}
          />
        </Field>
        <Button variant="primary" type="submit" processing={status === 'processing'}>
          Cambiar contraseña
        </Button>
      </form>
    </Card>
  );
};

export const AccountPage: React.FC = () => {
  const { user, refresh } = useAuth();
  const [sessions, setSessions] = useState<SessionRow[]>([]);
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [loadError, setLoadError] = useState('');
  const [processing, setProcessing] = useState<number | 'others' | null>(null);
  const [error, setError] = useState<string | null>(null);

  const apply = useCallback((rows: SessionRow[]) => {
    setSessions(rows);
    setState('ready');
  }, []);

  const fail = useCallback((err: unknown) => {
    setLoadError(apiErrorMessage(err, 'Verifica tu conexión e inténtalo nuevamente.'));
    setState('error');
  }, []);

  const reload = useCallback(() => fetchSessions().then(apply).catch(fail), [apply, fail]);

  useEffect(() => {
    let active = true;
    fetchSessions()
      .then((rows) => active && apply(rows))
      .catch((err) => active && fail(err));

    return () => {
      active = false;
    };
  }, [apply, fail]);

  const revoke = async (target: number | 'others') => {
    setProcessing(target);
    setError(null);

    try {
      await api.delete(target === 'others' ? '/auth/sessions' : `/auth/sessions/${target}`);
      await reload();
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo cerrar la sesión.'));
    } finally {
      setProcessing(null);
    }
  };

  const others = sessions.filter((session) => !session.current);

  return (
    <div>
      <PageHeader title="Mi cuenta" icon={UserRound} subtitle="Tu perfil, tu contraseña, los correos que recibes y los dispositivos con sesión abierta." />

      <div className="grid grid-cols-1 xl:grid-cols-[380px_1fr] gap-5 items-start">
        <div className="space-y-5">
          <Card className="relative overflow-hidden p-6">
            <div className="absolute inset-x-0 top-0 h-20 bg-gradient-to-r from-brand-800 via-brand-700 to-accent-600" />
            <div className="relative pt-9">
              <span className="inline-flex rounded-full ring-4 ring-white">
                <Avatar name={`${user?.first_name} ${user?.last_name}`} size="lg" />
              </span>
              <p className="mt-3 text-base font-semibold text-slate-900">
                {user?.first_name} {user?.last_name}
              </p>
              <p className="text-[13px] text-slate-500">{user?.email}</p>
              <div className="flex flex-wrap gap-1.5 mt-3">
                {user?.roles.map((role) => (
                  <Badge key={role} tone="brand">
                    {role}
                  </Badge>
                ))}
              </div>
            </div>
          </Card>

          <PasswordCard onChanged={reload} />
          <TwoFactorCard onChanged={() => void refresh()} />
          <EmailPreferencesCard />
        </div>

      <Card>
        <CardHeader
          title="Sesiones activas"
          text="Dispositivos donde tu cuenta tiene una sesión abierta."
          action={
            others.length > 0 && (
              <Button variant="danger" size="sm" onClick={() => revoke('others')} processing={processing === 'others'} disabled={processing !== null}>
                Cerrar las demás sesiones
              </Button>
            )
          }
        />

        {error && (
          <div className="p-4">
            <Alert>{error}</Alert>
          </div>
        )}
        {state === 'loading' && <LoadingState />}
        {state === 'error' && (
          <ErrorState
            message={loadError}
            onRetry={() => {
              setState('loading');
              reload();
            }}
          />
        )}

        {state === 'ready' && (
          <ul className="divide-y divide-slate-100">
            {sessions.map((session) => (
              <li key={session.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                <div className="flex items-center gap-3.5 min-w-0">
                  <IconChip icon={MonitorSmartphone} tone={session.current ? 'success' : 'neutral'} />
                  <div className="min-w-0">
                    <p className="text-[13px] font-semibold text-slate-900 flex items-center gap-2">
                      {describeDevice(session.user_agent)}
                      {session.current && (
                        <Badge tone="success" dot>
                          Esta sesión
                        </Badge>
                      )}
                    </p>
                    <p className="text-xs text-slate-500 mt-0.5 tnum">
                      IP {session.ip_address ?? 'desconocida'} · Inició {formatDate(session.created_at)} · Vence{' '}
                      {formatDate(session.expires_at)}
                    </p>
                  </div>
                </div>
                {!session.current && (
                  <Button size="sm" onClick={() => revoke(session.id)} processing={processing === session.id} disabled={processing !== null}>
                    Cerrar sesión
                  </Button>
                )}
              </li>
            ))}
          </ul>
        )}
      </Card>
      </div>
    </div>
  );
};
