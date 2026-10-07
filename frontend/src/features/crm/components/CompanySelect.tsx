import React, { useEffect, useState } from 'react';
import { INPUT_CLASS } from '../../../shared/ui';
import { crmApi, type Company } from '../api';

// El selector trae las primeras empresas; con más que eso, se busca por nombre o RUC
const FIRST_PAGE = 100;

interface CompanySelectProps {
  // Primeras empresas, ya cargadas por la ventana (null = cargando)
  companies: Company[] | null;
  value: string;
  onChange: (companyId: string) => void;
}

export const CompanySelect: React.FC<CompanySelectProps> = ({ companies, value, onChange }) => {
  const [query, setQuery] = useState('');
  const [found, setFound] = useState<Company[] | null>(null);
  const [picked, setPicked] = useState<Company | null>(null);

  const searching = query.trim().length >= 2;

  useEffect(() => {
    if (!searching) return;
    let active = true;

    // Espera a que se deje de escribir
    const timer = setTimeout(() => {
      crmApi
        .companies({ search: query.trim(), per_page: 50 })
        .then((result) => active && setFound(result.data))
        .catch(() => active && setFound([]));
    }, 300);

    return () => {
      active = false;
      clearTimeout(timer);
    };
  }, [query, searching]);

  const listed = searching ? found : companies;
  // La empresa elegida sigue en la lista aunque la búsqueda cambie
  const options = picked && listed && !listed.some((item) => item.id === picked.id) ? [picked, ...listed] : (listed ?? []);
  const hasMore = (companies?.length ?? 0) >= FIRST_PAGE;

  let placeholder = 'Elige una empresa';
  if (listed === null) placeholder = searching ? 'Buscando…' : 'Cargando…';
  else if (searching && listed.length === 0) placeholder = 'Ninguna empresa coincide';

  return (
    <div className="space-y-2">
      {hasMore && (
        <input
          className={INPUT_CLASS}
          type="search"
          aria-label="Buscar empresa por nombre o RUC"
          placeholder="Buscar por nombre o RUC…"
          value={query}
          onChange={(event) => {
            setQuery(event.target.value);
            setFound(null);
          }}
        />
      )}
      <select
        className={INPUT_CLASS}
        required
        aria-label="Empresa"
        value={value}
        onChange={(event) => {
          setPicked(options.find((item) => item.id === event.target.value) ?? null);
          onChange(event.target.value);
        }}
      >
        <option value="">{placeholder}</option>
        {options.map((item) => (
          <option key={item.id} value={item.id}>
            {item.name}
          </option>
        ))}
      </select>
      {hasMore && !searching && (
        <p className="text-xs text-slate-500">Se muestran las primeras {FIRST_PAGE} empresas. Escribe para buscar entre todas.</p>
      )}
    </div>
  );
};
