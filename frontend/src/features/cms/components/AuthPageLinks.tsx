import React from 'react';
import { appPath } from '../../../shared/tenancy/hosts';
import { useSitePages } from '../useSitePages';

/**
 * Enlaces a las páginas que la academia marcó para el inicio de sesión y el
 * registro (por ejemplo, «Preguntas frecuentes» o «Cómo comprar»). Se abren
 * en otra pestaña para no perder lo que se está escribiendo.
 */
export const AuthPageLinks: React.FC<{ className?: string }> = ({ className = '' }) => {
  const pages = useSitePages().filter((page) => page.auth);

  if (pages.length === 0) return null;

  return (
    <nav aria-label="Información de la academia" className={`flex flex-wrap justify-center gap-x-4 gap-y-1.5 text-xs ${className}`}>
      {pages.map((page) => (
        <a key={page.slug} href={appPath(`/p/${page.slug}`)} target="_blank" rel="noreferrer" className="font-medium text-slate-500 hover:text-brand-700">
          {page.title}
        </a>
      ))}
    </nav>
  );
};
