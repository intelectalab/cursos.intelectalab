import React, { useEffect, useState } from 'react';
import { Sparkles, Trash2 } from 'lucide-react';
import { api, apiErrorMessage } from '../../../shared/api/client';
import { Alert, Button, Card, IconChip, LoadingState } from '../../../shared/ui';

interface LearnedAlias {
  id: string;
  term: string;
  target_type: 'COURSE' | 'PACKAGE';
  title: string | null;
  hits: number;
}

/**
 * Configuración → Asistente: lo que aprendió el buscador. Si un sinónimo
 * lleva al curso equivocado, se borra aquí.
 */
export const AssistantAliasesSection: React.FC = () => {
  const [aliases, setAliases] = useState<LearnedAlias[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);

  useEffect(() => {
    let active = true;
    api
      .get('/assistant/search-aliases/learned')
      .then((response) => active && setAliases(response.data.data as LearnedAlias[]))
      .catch((err) => active && setError(apiErrorMessage(err, 'No se pudieron cargar los sinónimos.')));
    return () => {
      active = false;
    };
  }, []);

  const forget = async (alias: LearnedAlias) => {
    setBusy(alias.id);
    setError(null);
    try {
      setAliases((await api.delete(`/assistant/search-aliases/${alias.id}`)).data.data as LearnedAlias[]);
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo borrar el sinónimo.'));
    } finally {
      setBusy(null);
    }
  };

  return (
    <Card className="p-5 sm:p-6">
      <div className="flex items-center gap-3.5 mb-5">
        <IconChip icon={Sparkles} tone="brand" />
        <div>
          <h2 className="text-sm font-semibold text-slate-900">Asistente: sinónimos aprendidos</h2>
          <p className="text-[13px] text-slate-500">
            Cuando alguien busca algo en el asistente y elige un curso cuyo nombre no lo contiene, el buscador lo aprende. Borra los que lleven al curso equivocado.
          </p>
        </div>
      </div>

      {error && <Alert>{error}</Alert>}
      {!aliases && !error && <LoadingState />}
      {aliases && aliases.length === 0 && <p className="text-[13px] text-slate-500">Todavía no aprendió ningún sinónimo.</p>}
      {aliases && aliases.length > 0 && (
        <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200">
          {aliases.map((alias) => (
            <li key={alias.id} className="flex items-center gap-3 px-3.5 py-2.5 text-[13px]">
              <span className="flex-1 min-w-0">
                <span className="font-semibold text-slate-900">«{alias.term}»</span>
                <span className="text-slate-600"> lleva a {alias.title ?? (alias.target_type === 'PACKAGE' ? 'un paquete que ya no existe' : 'un curso que ya no existe')}</span>
                <span className="block text-xs text-slate-500">{alias.hits === 1 ? 'Elegido 1 vez' : `Elegido ${alias.hits} veces`}</span>
              </span>
              <Button size="sm" variant="ghost" icon={<Trash2 className="w-4 h-4" />} processing={busy === alias.id} aria-label={`Borrar el sinónimo «${alias.term}»`} onClick={() => void forget(alias)} />
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
};
