import React, { useEffect, useState } from 'react';
import { Link, Navigate, useLocation, useNavigate } from 'react-router-dom';
import { ArrowLeft } from 'lucide-react';
import { useAuth } from '../AuthContext';
import { api, apiErrorMessage } from '../../../shared/api/client';
import { nextPath, withNext } from '../../../shared/auth/next';
import { useTenant } from '../../../shared/tenancy/TenantContext';
import { Alert, Button, Field, INPUT_CLASS } from '../../../shared/ui';
import { AuthShell } from '../../../shared/ui/AuthShell';

const EMPTY = { first_name: '', last_name: '', email: '', password: '', password_confirmation: '' };

// Registro público de un comprador: crea una cuenta de alumno en la academia del subdominio
export const SignUp: React.FC = () => {
  const navigate = useNavigate();
  const location = useLocation();
  const { tenant } = useTenant();
  const { login, isAuthenticated } = useAuth();
  const [form, setForm] = useState(EMPTY);
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [accepted, setAccepted] = useState(false);
  const [legalPages, setLegalPages] = useState<{ slug: string; title: string }[]>([]);

  // Textos legales que la academia tiene publicados (los mismos del pie del sitio)
  useEffect(() => {
    let active = true;
    api
      .get('/site/pages')
      .then((response) => active && setLegalPages(response.data.data))
      .catch(() => undefined);
    return () => {
      active = false;
    };
  }, []);

  const next = nextPath(location.search, '/available-courses');
  const hasNext = new URLSearchParams(location.search).has('next');

  if (isAuthenticated && !processing) {
    return <Navigate to={next} replace />;
  }

  const field = (key: keyof typeof EMPTY) => ({
    className: INPUT_CLASS,
    required: true,
    value: form[key],
    onChange: (event: React.ChangeEvent<HTMLInputElement>) => setForm({ ...form, [key]: event.target.value }),
  });

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();

    if (form.password !== form.password_confirmation) {
      setError('Las contraseñas no coinciden.');
      return;
    }

    setProcessing(true);
    setError(null);

    try {
      const response = await api.post('/auth/register', { ...form, accept_terms: accepted });
      login(response.data.data);
      navigate(next, { replace: true });
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo crear la cuenta. Inténtalo nuevamente.'));
      setProcessing(false);
    }
  };

  return (
    <AuthShell
      tagline={tenant?.name}
      title="Crea tu cuenta"
      subtitle={`Regístrate para comprar y llevar los cursos de ${tenant?.name ?? 'la academia'}.`}
      footer={
        <Link to="/catalog" className="inline-flex items-center gap-1.5 font-semibold text-sky-200 hover:text-white transition-colors">
          <ArrowLeft className="w-4 h-4" />
          Volver al catálogo
        </Link>
      }
    >
      <form onSubmit={handleSubmit} className="space-y-4">
        {error && <Alert>{error}</Alert>}
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <Field label="Nombres">
            <input {...field('first_name')} maxLength={100} autoFocus autoComplete="given-name" />
          </Field>
          <Field label="Apellidos">
            <input {...field('last_name')} maxLength={100} autoComplete="family-name" />
          </Field>
        </div>
        <Field label="Correo electrónico">
          <input {...field('email')} type="email" maxLength={180} autoComplete="email" />
        </Field>
        <Field label="Contraseña" hint="Mínimo 8 caracteres.">
          <input {...field('password')} type="password" minLength={8} autoComplete="new-password" />
        </Field>
        <Field label="Repite la contraseña">
          <input {...field('password_confirmation')} type="password" minLength={8} autoComplete="new-password" />
        </Field>
        <label className="flex items-start gap-2.5 text-[13px] text-slate-600 leading-relaxed">
          <input
            type="checkbox"
            required
            checked={accepted}
            onChange={(event) => setAccepted(event.target.checked)}
            className="mt-0.5 h-4 w-4 shrink-0 rounded border-slate-300 text-brand-600 focus:ring-2 focus:ring-brand-500"
          />
          <span>
            Acepto los términos y condiciones y la política de privacidad de {tenant?.name ?? 'la academia'}.
            {legalPages.length > 0 && (
              <>
                {' '}
                Puedes leerlos aquí:{' '}
                {legalPages.map((page, index) => (
                  <React.Fragment key={page.slug}>
                    {index > 0 && ', '}
                    <a
                      href={`/p/${page.slug}`}
                      target="_blank"
                      rel="noreferrer"
                      className="font-semibold text-brand-700 underline hover:text-brand-900"
                    >
                      {page.title}
                    </a>
                  </React.Fragment>
                ))}
                .
              </>
            )}
          </span>
        </label>
        <Button variant="primary" size="lg" type="submit" className="w-full" processing={processing}>
          Crear cuenta
        </Button>
        <p className="text-[13px] text-slate-500 text-center">
          ¿Ya tienes cuenta?{' '}
          <Link to={hasNext ? withNext('/login', next) : '/login'} className="font-semibold text-brand-700 hover:text-brand-900">
            Inicia sesión
          </Link>
        </p>
      </form>
    </AuthShell>
  );
};
