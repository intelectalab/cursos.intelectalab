import React, { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, CheckCircle2, ExternalLink, Eye, EyeOff, PenLine, Rocket, Save, Send, Trash2, Undo2 } from 'lucide-react';
import { useAuth } from '../../auth/AuthContext';
import { apiErrorMessage } from '../../../shared/api/client';
import { Alert, Badge, Button, Card, CardHeader, CHECKBOX_CLASS, ConfirmModal, ErrorState, Field, INPUT_CLASS, LoadingState, Modal } from '../../../shared/ui';
import { formatDateTime } from '../../sales/api';
import { cmsApi, type ImageRules, type Page, type PageNode } from '../api';
import { PageContentView } from '../components/PageContentView';
import { PageEditor } from '../components/PageEditor';
import { appPath } from '../../../shared/tenancy/hosts';

type Confirm = 'publish' | 'unpublish' | 'delete' | 'review' | 'return';

// Redacción de una página: borrador, vista previa y publicación
export const PageEditorPage: React.FC = () => {
  const { pageId = '' } = useParams();
  const navigate = useNavigate();
  const { hasPermission } = useAuth();
  const canEdit = hasPermission('cms.update');
  const canPublish = hasPermission('cms.publish');

  const [page, setPage] = useState<Page | null>(null);
  const [imageRules, setImageRules] = useState<ImageRules>({ max_kb: 2048, extensions: ['png', 'jpg', 'jpeg', 'webp'] });
  const [title, setTitle] = useState('');
  const [slug, setSlug] = useState('');
  const [footer, setFooter] = useState(true);
  const [content, setContent] = useState<PageNode>({ type: 'doc', content: [] });
  const [dirty, setDirty] = useState(false);
  const [preview, setPreview] = useState(false);
  const [saving, setSaving] = useState(false);
  const [confirm, setConfirm] = useState<Confirm | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [comment, setComment] = useState('');
  const [returning, setReturning] = useState(false);

  const apply = (loaded: Page) => {
    setPage(loaded);
    setTitle(loaded.title);
    setSlug(loaded.slug);
    setFooter(loaded.show_in_footer);
    setContent(loaded.content);
    setDirty(false);
  };

  useEffect(() => {
    let active = true;
    Promise.all([cmsApi.get(pageId), cmsApi.list()])
      .then(([loaded, list]) => {
        if (!active) return;
        apply(loaded);
        setImageRules(list.image);
      })
      .catch((err) => active && setLoadError(apiErrorMessage(err, 'No se pudo cargar la página.')));
    return () => {
      active = false;
    };
  }, [pageId]);

  // Avisa antes de cerrar la pestaña con cambios sin guardar
  useEffect(() => {
    if (!dirty) return;
    const warn = (event: BeforeUnloadEvent) => event.preventDefault();
    window.addEventListener('beforeunload', warn);
    return () => window.removeEventListener('beforeunload', warn);
  }, [dirty]);

  const touch = (change: () => void) => {
    change();
    setDirty(true);
    setNotice(null);
  };

  const save = async (): Promise<Page | null> => {
    setSaving(true);
    setError(null);
    try {
      const saved = await cmsApi.save(pageId, { title: title.trim(), slug, content, show_in_footer: footer });
      apply(saved);
      return saved;
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo guardar el borrador.'));
      return null;
    } finally {
      setSaving(false);
    }
  };

  const onSave = async (event: React.FormEvent) => {
    event.preventDefault();
    if (await save()) setNotice('Borrador guardado. El público todavía no ve estos cambios.');
  };

  const returnWithComments = async (event: React.FormEvent) => {
    event.preventDefault();
    event.stopPropagation();
    setReturning(true);
    setError(null);
    try {
      apply(await cmsApi.requestChanges(pageId, comment.trim()));
      setNotice('Página devuelta a quien la redactó, con tus observaciones.');
      setComment('');
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo devolver la página.'));
    } finally {
      setReturning(false);
      setConfirm(null);
    }
  };

  if (loadError) {
    return (
      <Card>
        <ErrorState message={loadError} />
      </Card>
    );
  }
  if (!page) {
    return (
      <Card>
        <LoadingState />
      </Card>
    );
  }

  const published = page.status === 'PUBLISHED';
  // Hay algo que publicar: nunca se publicó, o el borrador cambió desde entonces
  const publishable = !published || page.pending_changes || dirty;
  const inReview = page.review_status === 'IN_REVIEW';
  const returned = page.review_status === 'CHANGES_REQUESTED';

  return (
    <form onSubmit={onSave}>
      <Link to="/pages" className="inline-flex items-center gap-1.5 text-[13px] font-medium text-slate-500 hover:text-brand-700 mb-4">
        <ArrowLeft className="w-3.5 h-3.5" />
        Páginas
      </Link>

      <div className="flex flex-wrap items-center justify-between gap-4 mb-5">
        <div className="min-w-0">
          <h1 className="text-[22px] leading-7 font-bold text-slate-900 tracking-tight truncate">{title || 'Página sin título'}</h1>
          <div className="flex flex-wrap items-center gap-2 mt-1.5">
            <Badge tone={published ? 'success' : 'neutral'} dot>
              {published ? 'Publicada' : 'Borrador'}
            </Badge>
            {(page.pending_changes || (published && dirty)) && <Badge tone="warning">Cambios sin publicar</Badge>}
            {inReview && <Badge tone="info">En revisión</Badge>}
            {returned && <Badge tone="danger">Devuelta con observaciones</Badge>}
            {published && (
              <a href={appPath(page.path)} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-xs font-semibold text-brand-700 hover:text-brand-800">
                <ExternalLink className="w-3 h-3" />
                {window.location.host}
                {page.path}
              </a>
            )}
          </div>
        </div>
        <div className="flex flex-wrap gap-2.5">
          <Button icon={preview ? <PenLine className="w-4 h-4" /> : <Eye className="w-4 h-4" />} onClick={() => setPreview((current) => !current)}>
            {preview ? 'Seguir editando' : 'Vista previa'}
          </Button>
          {canEdit && (
            <Button type="submit" icon={<Save className="w-4 h-4" />} processing={saving} disabled={!dirty}>
              Guardar borrador
            </Button>
          )}
          {/* Quien solo redacta pide la publicación; quien publica puede devolver lo que le enviaron */}
          {canEdit && !canPublish && (
            <Button variant="primary" icon={<Send className="w-4 h-4" />} disabled={!publishable || (inReview && !dirty)} onClick={() => setConfirm('review')}>
              {inReview && !dirty ? 'Enviada a revisión' : 'Enviar a revisión'}
            </Button>
          )}
          {canPublish && inReview && (
            <Button icon={<Undo2 className="w-4 h-4" />} onClick={() => setConfirm('return')}>
              Devolver con observaciones
            </Button>
          )}
          {canPublish && (
            <Button variant="primary" icon={<Rocket className="w-4 h-4" />} disabled={!publishable} onClick={() => setConfirm('publish')}>
              {published ? 'Publicar cambios' : 'Publicar'}
            </Button>
          )}
        </div>
      </div>

      {inReview && page.review && (
        <div className="mb-4">
          <Alert tone="info">
            {page.review.requested_at ? `${formatDateTime(page.review.requested_at)}: ` : ''}
            {page.review.requested_by ?? 'alguien del equipo'} envió este borrador a revisión.{' '}
            {canPublish ? 'Revísalo con la vista previa y publícalo, o devuélvelo con tus observaciones.' : 'Quien publica lo revisará. Si lo cambias, tendrás que enviarlo de nuevo.'}
          </Alert>
        </div>
      )}
      {returned && page.review && (
        <div className="mb-4">
          <Alert tone="warning">
            <strong className="font-semibold">
              {page.review.reviewed_by ?? 'Quien revisa'} devolvió esta página{page.review.reviewed_at ? ` el ${formatDateTime(page.review.reviewed_at)}` : ''}:
            </strong>{' '}
            {page.review.comment}
          </Alert>
        </div>
      )}

      {(error || notice) && (
        <div className="mb-4">
          {error && <Alert>{error}</Alert>}
          {notice && !error && <Alert tone="success">{notice}</Alert>}
        </div>
      )}

      <div className="grid lg:grid-cols-[1fr_300px] gap-6 items-start">
        <Card>
          {preview ? (
            <div className="p-6 sm:p-8">
              <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500 mb-3">Vista previa del borrador{dirty ? ' (con cambios sin guardar)' : ''}</p>
              <h1 className="text-2xl sm:text-3xl font-bold text-slate-900 tracking-tight mb-5">{title}</h1>
              {(content.content ?? []).length === 0 ? <p className="text-[13px] text-slate-500">La página está vacía.</p> : <PageContentView content={content} />}
            </div>
          ) : (
            <PageEditor key={page.id} content={content} imageRules={imageRules} editable={canEdit} onChange={(next) => touch(() => setContent(next))} />
          )}
        </Card>

        <div className="space-y-6">
          <Card>
            <CardHeader title="Datos de la página" />
            <div className="p-5 space-y-4">
              <Field label="Título">
                <input className={INPUT_CLASS} required maxLength={150} disabled={!canEdit} value={title} onChange={(event) => touch(() => setTitle(event.target.value))} />
              </Field>
              <Field label="Dirección" hint="Cambiarla rompe los enlaces que ya apunten a la anterior.">
                <input
                  className={`${INPUT_CLASS} font-mono`}
                  required
                  minLength={2}
                  maxLength={80}
                  pattern="[a-z0-9]+(-[a-z0-9]+)*"
                  title="Minúsculas, números y guiones"
                  disabled={!canEdit}
                  value={slug}
                  onChange={(event) => touch(() => setSlug(event.target.value))}
                />
              </Field>
              <label className="flex items-start gap-2.5 text-[13px] text-slate-700 cursor-pointer">
                <input type="checkbox" className={`${CHECKBOX_CLASS} mt-0.5`} disabled={!canEdit} checked={footer} onChange={(event) => touch(() => setFooter(event.target.checked))} />
                <span>
                  Enlazar al pie del sitio
                  <span className="block text-xs text-slate-500">Aparece junto al Libro de Reclamaciones en las páginas públicas.</span>
                </span>
              </label>
              <p className="text-xs text-slate-500">La dirección y el pie se aplican al guardar. El título y el contenido se ven recién al publicar.</p>
            </div>
          </Card>

          <Card>
            <CardHeader title="Publicaciones" />
            <div className="p-5">
              {page.versions.length === 0 ? (
                <p className="text-[13px] text-slate-500">Todavía no se publicó. Mientras tanto nadie fuera del equipo puede verla.</p>
              ) : (
                <ul className="space-y-2.5">
                  {page.versions.map((version) => (
                    <li key={version.version} className="text-[13px]">
                      <p className="font-semibold text-slate-900 flex items-center gap-1.5">
                        Versión {version.version}
                        {version.current && <CheckCircle2 className="w-3.5 h-3.5 text-emerald-500" aria-label="A la vista" />}
                      </p>
                      <p className="text-xs text-slate-500">
                        {formatDateTime(version.published_at)}
                        {version.published_by && ` · ${version.published_by}`}
                      </p>
                    </li>
                  ))}
                </ul>
              )}
              <div className="flex flex-wrap gap-2 mt-4">
                {canPublish && published && (
                  <Button size="sm" icon={<EyeOff className="w-3.5 h-3.5" />} onClick={() => setConfirm('unpublish')}>
                    Retirar del sitio
                  </Button>
                )}
                {canEdit && !published && (
                  <Button size="sm" variant="danger" icon={<Trash2 className="w-3.5 h-3.5" />} onClick={() => setConfirm('delete')}>
                    Eliminar página
                  </Button>
                )}
              </div>
            </div>
          </Card>
        </div>
      </div>

      {confirm === 'publish' && (
        <ConfirmModal
          title={published ? 'Publicar cambios' : 'Publicar página'}
          text={`"${title}" quedará a la vista de cualquiera en ${window.location.host}${page.path}${dirty ? '. Antes se guardarán los cambios que tienes sin guardar' : ''}.`}
          confirmLabel="Publicar"
          variant="primary"
          onClose={() => setConfirm(null)}
          onConfirm={async () => {
            if (dirty && !(await save())) {
              setConfirm(null);
              return;
            }
            apply(await cmsApi.publish(pageId));
            setNotice('Página publicada.');
            setConfirm(null);
          }}
        />
      )}
      {confirm === 'review' && (
        <ConfirmModal
          title="Enviar a revisión"
          text={`Quien publica las páginas revisará "${title}" y la pondrá a la vista o te la devolverá con observaciones${dirty ? '. Antes se guardarán los cambios que tienes sin guardar' : ''}.`}
          confirmLabel="Enviar"
          variant="primary"
          onClose={() => setConfirm(null)}
          onConfirm={async () => {
            if (dirty && !(await save())) {
              setConfirm(null);
              return;
            }
            apply(await cmsApi.submitReview(pageId));
            setNotice('Borrador enviado a revisión.');
            setConfirm(null);
          }}
        />
      )}
      {confirm === 'return' && (
        <Modal
          title="Devolver con observaciones"
          subtitle="La página no se publica. Quien la redactó verá lo que escribas aquí."
          onClose={() => setConfirm(null)}
          footer={
            <>
              <Button onClick={() => setConfirm(null)}>Cancelar</Button>
              <Button variant="primary" type="submit" form="return-page-form" processing={returning}>
                Devolver
              </Button>
            </>
          }
        >
          <form id="return-page-form" onSubmit={returnWithComments}>
            <Field label="Qué hay que corregir">
              <textarea className={INPUT_CLASS} rows={4} required minLength={3} maxLength={1000} autoFocus value={comment} onChange={(event) => setComment(event.target.value)} />
            </Field>
          </form>
        </Modal>
      )}
      {confirm === 'unpublish' && (
        <ConfirmModal
          title="Retirar del sitio"
          text="La página dejará de verse y saldrá del pie del sitio. Conservas el borrador y las versiones: puedes publicarla de nuevo cuando quieras."
          confirmLabel="Retirar"
          onClose={() => setConfirm(null)}
          onConfirm={async () => {
            apply(await cmsApi.unpublish(pageId));
            setNotice('Página retirada del sitio.');
            setConfirm(null);
          }}
        />
      )}
      {confirm === 'delete' && (
        <ConfirmModal
          title="Eliminar página"
          text={`Se eliminará "${page.title}" con su borrador y su historial de versiones. No se puede deshacer.`}
          confirmLabel="Eliminar"
          onClose={() => setConfirm(null)}
          onConfirm={async () => {
            await cmsApi.remove(pageId);
            navigate('/pages');
          }}
        />
      )}
    </form>
  );
};
