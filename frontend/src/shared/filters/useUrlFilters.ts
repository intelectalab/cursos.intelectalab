import { useCallback, useMemo } from 'react';
import { useSearchParams } from 'react-router-dom';

/**
 * Filtros de un listado guardados en la dirección de la página
 * (?course_id=…&from=…): sobreviven a «Atrás», se pueden compartir con un
 * enlace y la descarga a Excel usa los mismos. Un valor vacío no se escribe.
 */
export function useUrlFilters<T extends Record<string, string>>(defaults: T) {
  const [params, setParams] = useSearchParams();
  const keys = Object.keys(defaults) as (keyof T & string)[];

  const values = useMemo(
    () => Object.fromEntries(keys.map((key) => [key, params.get(key) ?? defaults[key]])) as T,
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [params],
  );

  const set = useCallback(
    (patch: Partial<T>) => {
      // Se parte de la dirección actual y no de la del último render: así dos cambios seguidos no se pisan
      const next = new URLSearchParams(window.location.search);
      for (const [key, value] of Object.entries(patch)) {
        if (value) next.set(key, value as string);
        else next.delete(key);
      }
      setParams(next, { replace: true });
    },
    [setParams],
  );

  const clear = useCallback(() => set(Object.fromEntries(keys.map((key) => [key, ''])) as Partial<T>), [set, keys]);

  const active = keys.some((key) => values[key] !== '' && values[key] !== defaults[key]);

  // Para la API: solo los filtros con valor
  const query = useMemo(() => Object.fromEntries(Object.entries(values).filter(([, value]) => value !== '')) as Partial<T>, [values]);

  return { values, set, clear, active, query };
}
