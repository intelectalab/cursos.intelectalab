import React, { useState } from 'react';
import { Alert, Button, CHECKBOX_CLASS } from '../../../shared/ui';

export interface EmailCategory {
  key: string;
  name: string;
  description: string;
  subscribed: boolean;
}

/**
 * Los tipos de correo que la persona puede dejar de recibir, con una casilla
 * por tipo. Lo usan «Mi cuenta» y la página del enlace al pie de los correos.
 */
export const EmailChoices: React.FC<{
  categories: EmailCategory[];
  onSave: (choices: Record<string, boolean>) => Promise<void>;
}> = ({ categories, onSave }) => {
  const [choices, setChoices] = useState<Record<string, boolean>>(() =>
    Object.fromEntries(categories.map((category) => [category.key, category.subscribed])),
  );
  const [status, setStatus] = useState<'idle' | 'saving' | 'saved'>('idle');
  const [error, setError] = useState<string | null>(null);

  const changed = categories.some((category) => choices[category.key] !== category.subscribed);

  const save = async () => {
    setStatus('saving');
    setError(null);
    try {
      await onSave(choices);
      setStatus('saved');
    } catch (err) {
      setError(err instanceof Error ? err.message : 'No se pudo guardar.');
      setStatus('idle');
    }
  };

  return (
    <div className="space-y-4">
      {error && <Alert>{error}</Alert>}
      {status === 'saved' && !changed && <Alert tone="success">Listo: guardamos qué correos quieres recibir.</Alert>}
      <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200">
        {categories.map((category) => (
          <li key={category.key}>
            <label className="flex items-start gap-3 px-4 py-3 cursor-pointer hover:bg-slate-50">
              <input
                type="checkbox"
                className={`${CHECKBOX_CLASS} mt-0.5`}
                checked={choices[category.key] ?? true}
                onChange={(event) => {
                  setChoices((prev) => ({ ...prev, [category.key]: event.target.checked }));
                  setStatus('idle');
                }}
              />
              <span>
                <span className="block text-[13px] font-semibold text-slate-900">{category.name}</span>
                <span className="block text-xs text-slate-500">{category.description}</span>
              </span>
            </label>
          </li>
        ))}
      </ul>
      <p className="text-xs text-slate-500">
        Los correos de tu cuenta, tus compras y pagos, tus consultas y tus reclamos siempre te llegan.
      </p>
      <Button variant="primary" onClick={save} processing={status === 'saving'} disabled={!changed}>
        Guardar
      </Button>
    </div>
  );
};
