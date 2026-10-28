import React, { useEffect, useState } from 'react';
import { Mail } from 'lucide-react';
import { api, apiErrorMessage } from '../../../shared/api/client';
import { Alert, Card, IconChip, LoadingState } from '../../../shared/ui';
import { EmailChoices, type EmailCategory } from './EmailChoices';

/** «Mi cuenta»: qué correos de la academia recibe la persona. */
export const EmailPreferencesCard: React.FC = () => {
  const [categories, setCategories] = useState<EmailCategory[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let active = true;
    api
      .get('/auth/email-preferences')
      .then((response) => active && setCategories(response.data.data.categories))
      .catch((err) => active && setError(apiErrorMessage(err, 'No se pudo leer qué correos recibes.')));

    return () => {
      active = false;
    };
  }, []);

  const save = async (choices: Record<string, boolean>) => {
    try {
      setCategories((await api.put('/auth/email-preferences', { categories: choices })).data.data.categories);
    } catch (err) {
      throw new Error(apiErrorMessage(err, 'No se pudo guardar.'), { cause: err });
    }
  };

  return (
    <Card className="p-5 sm:p-6">
      <div className="flex items-center gap-3.5 mb-5">
        <IconChip icon={Mail} tone="brand" />
        <div>
          <h2 className="text-sm font-semibold text-slate-900">Correos que recibo</h2>
          <p className="text-[13px] text-slate-500">Desmarca los que no quieras recibir.</p>
        </div>
      </div>
      {error && <Alert>{error}</Alert>}
      {!error && !categories && <LoadingState />}
      {categories && <EmailChoices categories={categories} onSave={save} />}
    </Card>
  );
};
