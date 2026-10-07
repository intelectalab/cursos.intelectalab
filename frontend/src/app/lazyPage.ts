import { lazy, type ComponentType } from 'react';

const RELOAD_FLAG = 'erp:chunk-reload';

/**
 * Pantalla que se descarga recién cuando se abre. Si el archivo ya no existe
 * (se publicó una versión nueva mientras la pestaña seguía abierta), recarga
 * la página una vez para tomar la versión vigente.
 */
export function lazyPage<M extends Record<string, unknown>, K extends keyof M>(load: () => Promise<M>, name: K) {
  return lazy(async () => {
    try {
      const module = await load();
      sessionStorage.removeItem(RELOAD_FLAG);
      return { default: module[name] as ComponentType };
    } catch (error) {
      if (!sessionStorage.getItem(RELOAD_FLAG)) {
        sessionStorage.setItem(RELOAD_FLAG, '1');
        window.location.reload();
        // La recarga está en curso: se deja la pantalla en espera
        return new Promise<{ default: ComponentType }>(() => undefined);
      }
      throw error;
    }
  });
}
