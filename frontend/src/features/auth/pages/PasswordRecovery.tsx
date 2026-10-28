import React, { useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { ArrowLeft } from 'lucide-react';
import { api, apiErrorMessage } from '../../../shared/api/client';
import { useTenant } from '../../../shared/tenancy/TenantContext';
import { Alert, Button, Field, INPUT_CLASS } from '../../../shared/ui';
import { AuthShell } from '../../../shared/ui/AuthShell';

const Shell: React.FC<{ title: string; subtitle: string; children: React.ReactNode }> = ({
  title,
  subtitle,
  children,
}) => {
  const { tenant } = useTenant();

  return (
    <AuthShell
      tagline={tenant?.name}
      title={title}
      subtitle={subtitle}
      footer={
        <Link to="/login" className="inline-flex items-center gap-1.5 font-semibold text-accent-200 hover:text-white transition-colors">
          <ArrowLeft className="w-4 h-4" />
          Volver a iniciar sesión
        </Link>
      }
    >
      {children}
    </AuthShell>
  );
};

const Success: React.FC<{ children: React.ReactNode }> = ({ children }) => <Alert tone="success">{children}</Alert>;

export const ForgotPassword: React.FC = () => {
  const [email, setEmail] = useState('');
  const [status, setStatus] = useState<'idle' | 'processing' | 'sent'>('idle');
  const [error, setError] = useState<string | null>(null);

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    setStatus('processing');
    setError(null);

    try {
      await api.post('/auth/forgot-password', { email });
      setStatus('sent');
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo enviar la solicitud. Inténtalo nuevamente.'));
      setStatus('idle');
    }
  };

  return (
    <Shell title="Recuperar contraseña" subtitle="Te enviaremos un enlace para crear una contraseña nueva.">
      {status === 'sent' ? (
        <Success>
          Si <strong>{email}</strong> pertenece a una cuenta activa, recibirás un correo con el enlace en unos
          minutos. Revisa también tu carpeta de correo no deseado.
        </Success>
      ) : (
        <form onSubmit={handleSubmit} className="space-y-4">
          {error && <Alert>{error}</Alert>}
          <Field label="Correo electrónico">
            <input
              className={INPUT_CLASS}
              type="email"
              required
              autoFocus
              value={email}
              onChange={(event) => setEmail(event.target.value)}
            />
          </Field>
          <Button variant="primary" size="lg" type="submit" className="w-full" processing={status === 'processing'}>
            Enviar enlace
          </Button>
        </form>
      )}
    </Shell>
  );
};

export const ResetPassword: React.FC = () => {
  const [params] = useSearchParams();
  const token = params.get('token') ?? '';
  const email = params.get('email') ?? '';
  // Viene de la invitación de una cuenta nueva: todavía no tiene contraseña
  const invitation = params.get('invitacion') === '1';

  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [status, setStatus] = useState<'idle' | 'processing' | 'done'>('idle');
  const [error, setError] = useState<string | null>(null);

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    if (password !== confirmation) {
      setError('Las contraseñas no coinciden.');
      return;
    }

    setStatus('processing');
    setError(null);

    try {
      await api.post('/auth/reset-password', { token, email, password, password_confirmation: confirmation });
      setStatus('done');
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo cambiar la contraseña. Inténtalo nuevamente.'));
      setStatus('idle');
    }
  };

  if (!token || !email) {
    return (
      <Shell title="Enlace no válido" subtitle="El enlace está incompleto o fue modificado.">
        <Link to="/forgot-password" className="text-[13px] font-semibold text-brand-600 hover:text-brand-800">
          Solicitar un enlace nuevo
        </Link>
      </Shell>
    );
  }

  return (
    <Shell title={invitation ? 'Crea tu contraseña' : 'Crear contraseña nueva'} subtitle={invitation ? `Te damos la bienvenida. Con esta contraseña y tu correo (${email}) entrarás a tu cuenta.` : `Cuenta: ${email}`}>
      {status === 'done' ? (
        <Success>{invitation ? 'Tu contraseña quedó creada. Ya puedes iniciar sesión.' : 'Tu contraseña se cambió y se cerraron tus sesiones abiertas. Ya puedes iniciar sesión.'}</Success>
      ) : (
        <form onSubmit={handleSubmit} className="space-y-4">
          {error && (
            <Alert>
              {error}{' '}
              <Link to="/forgot-password" className="font-semibold underline">
                Solicitar un enlace nuevo
              </Link>
            </Alert>
          )}
          <Field label={invitation ? 'Contraseña' : 'Contraseña nueva'} hint="Mínimo 8 caracteres.">
            <input
              className={INPUT_CLASS}
              type="password"
              required
              minLength={8}
              autoFocus
              autoComplete="new-password"
              value={password}
              onChange={(event) => setPassword(event.target.value)}
            />
          </Field>
          <Field label="Repetir contraseña">
            <input
              className={INPUT_CLASS}
              type="password"
              required
              minLength={8}
              autoComplete="new-password"
              value={confirmation}
              onChange={(event) => setConfirmation(event.target.value)}
            />
          </Field>
          <Button variant="primary" size="lg" type="submit" className="w-full" processing={status === 'processing'}>
            {invitation ? 'Crear contraseña' : 'Cambiar contraseña'}
          </Button>
        </form>
      )}
    </Shell>
  );
};
