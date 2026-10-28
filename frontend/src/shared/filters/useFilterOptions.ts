import { useEffect, useState } from 'react';
import { api } from '../api/client';

export interface FilterOptions {
  courses: { id: string; title: string }[];
  packages: { id: string; title: string }[];
  groups: { id: string; name: string }[];
  companies: { id: string; name: string }[];
  roles: { id: string; name: string }[];
  teachers: { id: string; name: string }[];
  sellers: { id: string; name: string }[];
}

const EMPTY: FilterOptions = { courses: [], packages: [], groups: [], companies: [], roles: [], teachers: [], sellers: [] };

/**
 * Cursos, grupos, empresas, roles, docentes y vendedores para los filtros de
 * los listados. Cada lista llega vacía si quien entra no puede verla.
 */
export function useFilterOptions(): FilterOptions {
  const [options, setOptions] = useState<FilterOptions>(EMPTY);

  useEffect(() => {
    let active = true;
    api
      .get('/filter-options')
      .then((response) => active && setOptions(response.data.data as FilterOptions))
      .catch(() => undefined);
    return () => {
      active = false;
    };
  }, []);

  return options;
}
