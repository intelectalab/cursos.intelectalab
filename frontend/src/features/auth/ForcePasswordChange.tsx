import React, { useState } from 'react';
import { useAuth } from './AuthContext';
import { api, apiErrorMessage } from '../../shared/api/client';
import { Alert, Button, Field, INPUT_CLASS } from '../../shared/ui';
import { AuthShell } from '../../shared/ui/AuthShell';

// Quien entra con una contraseña temporal (cuenta creada por importación) debe
// poner la suya antes de usar el sistema. El servidor bloquea todo lo demás.
export const ForcePasswordChange: React.FC = () => {
  const { user, tenant, refresh, logout } = useAuth();
  const [form, setForm] = useState({ current_password: '', password: '', password_confirmation: '' });
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const field = (key: keyof typeof form) => ({
    className: INPUT_CLASS,
    type: 'password',
    required: true,
    value: form[key],
    onChange: (event: React.ChangeEvent<HTMLInputElement>) => setForm({ ...form, [key]: event.target.value }),
  });

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();

    if (form.password !== form.password_confirmation) {
      setError('Las contraseñas nuevas no coinciden.');
      return;
    }

    setProcessing(true);
    setError(null);
    try {
      await api.put('/auth/password', form);
      await refresh();
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo cambiar la contraseña.'));
      setProcessing(false);
    }
  };

  return (
    <AuthShell
      tagline={tenant?.name}
      title="Crea tu contraseña"
      subtitle={`Hola, ${user?.first_name ?? ''}. Entraste con una contraseña temporal: cámbiala por una que solo tú conozcas para continuar.`}
      footer={
        <button type="button" onClick={logout} className="font-semibold text-sky-200 hover:text-white transition-colors cursor-pointer">
          Cerrar sesión
        </button>
      }
    >
      <form onSubmit={submit} className="space-y-4">
        {error && <Alert>{error}</Alert>}
        <Field label="Contraseña temporal" hint="La que usaste para entrar (tu número de documento).">
          <input {...field('current_password')} autoFocus autoComplete="current-password" />
        </Field>
        <Field label="Contraseña nueva" hint="Mínimo 8 caracteres y distinta de la temporal.">
          <input {...field('password')} minLength={8} autoComplete="new-password" />
        </Field>
        <Field label="Repite la contraseña nueva">
          <input {...field('password_confirmation')} minLength={8} autoComplete="new-password" />
        </Field>
        <Button variant="primary" size="lg" type="submit" className="w-full" processing={processing}>
          Guardar y continuar
        </Button>
      </form>
    </AuthShell>
  );
};
