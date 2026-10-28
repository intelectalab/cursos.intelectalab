import React, { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { FileQuestion } from 'lucide-react';
import { apiError } from '../../../shared/api/client';
import { Card, EmptyState, ErrorState, LoadingState } from '../../../shared/ui';
import { formatDate } from '../../certificates/api';
import { PublicShell } from '../../sales/components/PublicShell';
import { cmsApi, type PublishedPage } from '../api';
import { PageContentView } from '../components/PageContentView';

type State = { status: 'loading' } | { status: 'ready'; page: PublishedPage } | { status: 'missing' } | { status: 'error' };

// Página pública de la academia: /p/{dirección}
export const PublicPage: React.FC = () => {
  const { slug = '' } = useParams();
  // Se guarda para qué dirección es el resultado: al cambiar de página vuelve a "cargando" sin un efecto extra
  const [result, setResult] = useState<{ slug: string; state: State } | null>(null);

  useEffect(() => {
    let active = true;
    // El título de la pestaña vuelve a ser el de la aplicación al salir de la página
    const previousTitle = document.title;
    cmsApi
      .published(slug)
      .then((page) => {
        if (!active) return;
        setResult({ slug, state: { status: 'ready', page } });
        document.title = page.title;
      })
      .catch((err) => active && setResult({ slug, state: { status: apiError(err)?.code === 'NOT_FOUND' ? 'missing' : 'error' } }));
    return () => {
      active = false;
      document.title = previousTitle;
    };
  }, [slug]);

  const state: State = result?.slug === slug ? result.state : { status: 'loading' };

  const hero =
    state.status === 'ready' ? (
      <div className="max-w-3xl">
        <h1 className="text-3xl sm:text-4xl font-bold text-white tracking-tight">{state.page.title}</h1>
        <p className="mt-3 text-sm text-accent-100/80">Actualizado el {formatDate(state.page.published_at)}</p>
      </div>
    ) : undefined;

  return (
    <PublicShell hero={hero}>
      <div className="max-w-3xl mx-auto">
        {state.status === 'loading' && (
          <Card>
            <LoadingState />
          </Card>
        )}
        {state.status === 'error' && (
          <Card>
            <ErrorState message="Verifica tu conexión e inténtalo nuevamente." />
          </Card>
        )}
        {state.status === 'missing' && (
          <Card>
            <EmptyState
              icon={FileQuestion}
              title="Esta página no existe"
              text="Puede que la dirección esté mal escrita o que la página ya no esté publicada."
              action={
                <Link to="/catalog" className="inline-flex items-center justify-center h-9 px-3.5 rounded-lg text-[13px] font-semibold bg-white text-slate-700 border border-slate-300 hover:bg-slate-50">
                  Ir al catálogo
                </Link>
              }
            />
          </Card>
        )}
        {state.status === 'ready' && (
          <Card className="p-6 sm:p-9">
            <PageContentView content={state.page.content} />
          </Card>
        )}
      </div>
    </PublicShell>
  );
};
