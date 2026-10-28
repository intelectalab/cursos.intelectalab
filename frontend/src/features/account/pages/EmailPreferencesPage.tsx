import React, { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { MailX } from 'lucide-react';
import { api, apiErrorMessage } from '../../../shared/api/client';
import { Alert, Button, Card, LoadingState } from '../../../shared/ui';
import { PublicShell } from '../../sales/components/PublicShell';
import { EmailChoices, type EmailCategory } from '../components/EmailChoices';

interface Preferences {
  academy: string;
  email: string;
  categories: EmailCategory[];
}

const Hero: React.FC = () => (
  <div className="max-w-2xl">
    <p className="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-sky-200">
      <MailX className="w-4 h-4" />
      Correos
    </p>
    <h1 className="mt-2 text-3xl sm:text-4xl font-bold text-white tracking-tight">Elige qué correos recibes</h1>
    <p className="mt-3 text-sky-100/90 text-[15px] leading-relaxed">Puedes dejar de recibir un tipo de correo y volver a recibirlo cuando quieras.</p>
  </div>
);

/**
 * A esta página lleva el enlace «Dejar de recibir…» al pie de los correos.
 * No pide iniciar sesión: el enlace lleva su firma. La baja se confirma con
 * un botón, para que un programa que abra el enlace no la haga solo.
 */
export const EmailPreferencesPage: React.FC = () => {
  const [params] = useSearchParams();
  const token = params.get('t') ?? '';
  const asked = params.get('c');
  const [data, setData] = useState<Preferences | null>(null);
  const [error, setError] = useState<string | null>(token ? null : 'El enlace está incompleto. Ábrelo tal como llegó en el correo.');
  const [confirming, setConfirming] = useState(false);
  const [confirmed, setConfirmed] = useState(false);

  useEffect(() => {
    if (!token) return;
    let active = true;
    api
      .get(`/email-preferences/${token}`)
      .then((response) => active && setData(response.data.data))
      .catch((err) => active && setError(apiErrorMessage(err, 'No se pudo abrir el enlace.')));

    return () => {
      active = false;
    };
  }, [token]);

  const save = async (choices: Record<string, boolean>) => {
    const response = await api.put(`/email-preferences/${token}`, { categories: choices });
    setData((prev) => (prev ? { ...prev, categories: response.data.data.categories } : prev));
  };

  const saveOrExplain = async (choices: Record<string, boolean>) => {
    try {
      await save(choices);
    } catch (err) {
      throw new Error(apiErrorMessage(err, 'No se pudo guardar.'), { cause: err });
    }
  };

  const target = data?.categories.find((category) => category.key === asked);

  const confirm = async () => {
    if (!target) return;
    setConfirming(true);
    setError(null);
    try {
      await save({ [target.key]: false });
      setConfirmed(true);
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo dar de baja.'));
    } finally {
      setConfirming(false);
    }
  };

  return (
    <PublicShell hero={<Hero />}>
      <div className="max-w-2xl mx-auto space-y-5">
        {error && <Alert>{error}</Alert>}
        {!error && !data && <LoadingState />}

        {data && target && (
          <Card className="p-5 sm:p-6">
            {target.subscribed && !confirmed ? (
              <div className="space-y-4">
                <p className="text-[15px] text-slate-800">
                  ¿Dejar de recibir <strong>«{target.name}»</strong> de {data.academy} en <span className="font-mono">{data.email}</span>?
                </p>
                <p className="text-[13px] text-slate-500">{target.description}</p>
                <Button variant="primary" onClick={confirm} processing={confirming}>
                  Sí, dejar de recibirlos
                </Button>
              </div>
            ) : (
              <Alert tone="success">
                Listo: ya no recibirás «{target.name}» de {data.academy}. Si fue un error, márcalo otra vez abajo y guarda.
              </Alert>
            )}
          </Card>
        )}

        {data && (
          <Card className="p-5 sm:p-6">
            <h2 className="text-sm font-semibold text-slate-900">Todos los correos de {data.academy}</h2>
            <p className="text-[13px] text-slate-500 mb-4">
              Para <span className="font-mono">{data.email}</span>. Desmarca los que no quieras recibir.
            </p>
            {/* La clave cambia con la baja de arriba, para que las casillas la reflejen */}
            <EmailChoices key={confirmed ? 'after' : 'before'} categories={data.categories} onSave={saveOrExplain} />
          </Card>
        )}
      </div>
    </PublicShell>
  );
};
