import { useEffect, useState } from 'react';
import { api } from '../../shared/api/client';

/** Página publicada de la academia y dónde se enlaza (Páginas → «Dónde aparece»). */
export interface SitePage {
  slug: string;
  title: string;
  footer: boolean;
  header: boolean;
  auth: boolean;
  student_menu: boolean;
}

/**
 * Las páginas que la academia enlaza en su sitio, ya en su orden. Se piden
 * sin sesión: son públicas.
 */
export function useSitePages(): SitePage[] {
  const [pages, setPages] = useState<SitePage[]>([]);

  useEffect(() => {
    let active = true;
    api
      .get('/site/pages')
      .then((response) => active && setPages(response.data.data as SitePage[]))
      .catch(() => undefined);
    return () => {
      active = false;
    };
  }, []);

  return pages;
}
