import React, { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { ExternalLink, FileText, Plus } from 'lucide-react';
import { useAuth } from '../../auth/AuthContext';
import { apiErrorMessage } from '../../../shared/api/client';
import { Alert, Badge, Button, Card, EmptyState, ErrorState, Field, INPUT_CLASS, LoadingState, Modal, PageHeader, Table, TBody, Td, Th, THead, Tr } from '../../../shared/ui';
import { formatDateTime } from '../../sales/api';
import { cmsApi, slugify, type PageSummary } from '../api';

const NewPageModal: React.FC<{ onClose: () => void }> = ({ onClose }) => {
  const navigate = useNavigate();
  const [title, setTitle] = useState('');
  const [slug, setSlug] = useState('');
  // Mientras no se toque la dirección, sigue al título
  const [slugTouched, setSlugTouched] = useState(false);
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setProcessing(true);
    setError(null);
    try {
      const page = await cmsApi.create({ title: title.trim(), slug });
      navigate(`/pages/${page.id}`);
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo crear la página.'));
      setProcessing(false);
    }
  };

  return (
    <Modal
      title="Nueva página"
      subtitle="Se crea como borrador: nadie la ve hasta que se publique."
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="new-page-form" processing={processing}>
            Crear y escribir
          </Button>
        </>
      }
    >
      <form id="new-page-form" onSubmit={submit} className="space-y-4">
        {error && <Alert>{error}</Alert>}
        <Field label="Título" hint="Ejemplos: Nosotros, Términos y condiciones, Política de privacidad, Preguntas frecuentes.">
          <input
            className={INPUT_CLASS}
            required
            maxLength={150}
            autoFocus
            value={title}
            onChange={(event) => {
              setTitle(event.target.value);
              if (!slugTouched) setSlug(slugify(event.target.value));
            }}
          />
        </Field>
        <Field label="Dirección" hint={`La página quedará en ${window.location.host}/p/${slug || 'direccion'}`}>
          <input
            className={`${INPUT_CLASS} font-mono`}
            required
            minLength={2}
            maxLength={80}
            pattern="[a-z0-9]+(-[a-z0-9]+)*"
            title="Minúsculas, números y guiones"
            value={slug}
            onChange={(event) => {
              setSlug(event.target.value);
              setSlugTouched(true);
            }}
          />
        </Field>
      </form>
    </Modal>
  );
};

// Páginas de la academia: Nosotros, Términos, Privacidad, Preguntas frecuentes…
export const PagesPage: React.FC = () => {
  const { hasPermission } = useAuth();
  const canEdit = hasPermission('cms.update');

  const [pages, setPages] = useState<PageSummary[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [creating, setCreating] = useState(false);

  const load = useCallback(() => {
    cmsApi
      .list()
      .then((result) => {
        setPages(result.data);
        setError(null);
      })
      .catch((err) => setError(apiErrorMessage(err, 'Verifica tu conexión e inténtalo nuevamente.')));
  }, []);

  useEffect(load, [load]);

  const newButton = canEdit && (
    <Button variant="primary" icon={<Plus className="w-4 h-4" />} onClick={() => setCreating(true)}>
      Nueva página
    </Button>
  );

  return (
    <div>
      <PageHeader title="Páginas" icon={FileText} subtitle="Páginas de texto de tu academia que cualquiera puede leer en tu sitio." action={newButton} />

      <Card>
        {error && <ErrorState message={error} onRetry={load} />}
        {!error && pages === null && <LoadingState />}
        {!error && pages?.length === 0 && (
          <EmptyState
            icon={FileText}
            title="Aún no hay páginas"
            text="Crea las páginas informativas de tu academia, como Nosotros, Términos y condiciones o Preguntas frecuentes. Se enlazan al pie de tu catálogo."
            action={newButton}
          />
        )}
        {!error && pages && pages.length > 0 && (
          <Table>
            <THead>
              <Th>Página</Th>
              <Th>Estado</Th>
              <Th>Al pie del sitio</Th>
              <Th>Última edición</Th>
              <Th />
            </THead>
            <TBody>
              {pages.map((page) => (
                <Tr key={page.id}>
                  <Td>
                    <Link to={`/pages/${page.id}`} className="font-semibold text-slate-900 hover:text-brand-700">
                      {page.title}
                    </Link>
                    <p className="text-xs text-slate-500 font-mono">{page.path}</p>
                  </Td>
                  <Td>
                    <div className="flex flex-wrap items-center gap-1.5">
                      <Badge tone={page.status === 'PUBLISHED' ? 'success' : 'neutral'} dot>
                        {page.status === 'PUBLISHED' ? 'Publicada' : 'Borrador'}
                      </Badge>
                      {page.pending_changes && <Badge tone="warning">Cambios sin publicar</Badge>}
                      {page.review_status === 'IN_REVIEW' && <Badge tone="info">En revisión</Badge>}
                      {page.review_status === 'CHANGES_REQUESTED' && <Badge tone="danger">Devuelta</Badge>}
                    </div>
                  </Td>
                  <Td className="text-slate-700">{page.show_in_footer ? 'Sí' : 'No'}</Td>
                  <Td className="whitespace-nowrap">
                    <p className="text-slate-700">{formatDateTime(page.updated_at)}</p>
                    <p className="text-xs text-slate-500">{page.updated_by}</p>
                  </Td>
                  <Td>
                    {page.status === 'PUBLISHED' && (
                      <a
                        href={page.path}
                        target="_blank"
                        rel="noreferrer"
                        className="inline-flex items-center gap-1.5 text-xs font-semibold text-brand-700 hover:text-brand-800 whitespace-nowrap"
                        aria-label={`Ver ${page.title} en el sitio`}
                      >
                        <ExternalLink className="w-3.5 h-3.5" />
                        Ver
                      </a>
                    )}
                  </Td>
                </Tr>
              ))}
            </TBody>
          </Table>
        )}
      </Card>

      {creating && <NewPageModal onClose={() => setCreating(false)} />}
    </div>
  );
};
