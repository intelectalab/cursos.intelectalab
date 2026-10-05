import { useCallback, useSyncExternalStore } from 'react';

// Punto de corte del back-office: desde aquí el menú lateral va fijo al costado;
// por debajo es un panel desplegable. Coincide con el prefijo `lg:` de Tailwind.
export const DESKTOP_QUERY = '(min-width: 1024px)';

export const useMediaQuery = (query: string): boolean => {
  const subscribe = useCallback(
    (onChange: () => void) => {
      const media = window.matchMedia(query);
      media.addEventListener('change', onChange);
      return () => media.removeEventListener('change', onChange);
    },
    [query],
  );

  return useSyncExternalStore(subscribe, () => window.matchMedia(query).matches);
};
