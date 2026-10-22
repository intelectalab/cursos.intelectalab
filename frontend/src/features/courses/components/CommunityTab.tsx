import React, { useCallback, useEffect, useState } from 'react';
import { EyeOff, Megaphone, MessageSquareReply, Pencil, Send, Star, Trash2, Eye } from 'lucide-react';
import { apiErrorMessage } from '../../../shared/api/client';
import { Alert, Badge, Button, Card, CardHeader, CHECKBOX_CLASS, ConfirmModal, EmptyState, ErrorState, Field, INPUT_CLASS, LoadingState, Modal } from '../../../shared/ui';
import { communityApi, formatWhen, type Announcement, type StaffReview } from '../community';
import { Stars } from './Stars';

type Draft = { id: string | null; title: string; body: string; notify: boolean };

/**
 * Pestaña «Anuncios y reseñas» del curso: el docente o el administrador
 * avisan a los alumnos; las reseñas se leen aquí y la academia puede
 * ocultar las que no corresponden.
 */
export const CommunityTab: React.FC<{ courseId: string }> = ({ courseId }) => {
  const [announcements, setAnnouncements] = useState<Announcement[]>([]);
  const [canPublish, setCanPublish] = useState(false);
  const [reviews, setReviews] = useState<Awaited<ReturnType<typeof communityApi.reviews>> | null>(null);
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [errorMessage, setErrorMessage] = useState('');
  const [draft, setDraft] = useState<Draft | null>(null);
  const [saving, setSaving] = useState(false);
  const [draftError, setDraftError] = useState<string | null>(null);
  const [removing, setRemoving] = useState<Announcement | null>(null);
  const [hiding, setHiding] = useState<StaffReview | null>(null);
  const [reason, setReason] = useState('');
  // Filtro de reseñas por estrellas: 0 = todas
  const [stars, setStars] = useState(0);
  const [answering, setAnswering] = useState<StaffReview | null>(null);
  const [answer, setAnswer] = useState('');
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const [list, opinions] = await Promise.all([communityApi.announcements(courseId), communityApi.reviews(courseId)]);
      setAnnouncements(list.data);
      setCanPublish(list.canPublish);
      setReviews(opinions);
      setState('ready');
    } catch (err) {
      setErrorMessage(apiErrorMessage(err, 'No se pudieron cargar los anuncios y las reseñas.'));
      setState('error');
    }
  }, [courseId]);

  useEffect(() => {
    const timer = setTimeout(load, 0);
    return () => clearTimeout(timer);
  }, [load]);

  if (state === 'loading') return <LoadingState />;
  if (state === 'error' || !reviews) return <ErrorState message={errorMessage} onRetry={load} />;

  const save = async () => {
    if (!draft) return;
    setSaving(true);
    setDraftError(null);
    try {
      if (draft.id) {
        await communityApi.update(courseId, draft.id, { title: draft.title, body: draft.body });
        setNotice('Anuncio actualizado.');
      } else {
        await communityApi.publish(courseId, { title: draft.title, body: draft.body, notify: draft.notify });
        setNotice(draft.notify ? 'Anuncio publicado. Los alumnos con acceso reciben también un correo.' : 'Anuncio publicado. Los alumnos lo ven en el aula del curso.');
      }
      setDraft(null);
      await load();
    } catch (err) {
      setDraftError(apiErrorMessage(err, 'No se pudo guardar el anuncio.'));
    } finally {
      setSaving(false);
    }
  };

  const hide = async () => {
    if (!hiding) return;
    setSaving(true);
    setDraftError(null);
    try {
      await communityApi.hide(courseId, hiding.id, reason);
      setHiding(null);
      setReason('');
      await load();
    } catch (err) {
      setDraftError(apiErrorMessage(err, 'No se pudo ocultar la reseña.'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="space-y-5">
      {notice && <Alert tone="success">{notice}</Alert>}

      <Card>
        <CardHeader
          title="Anuncios"
          text="Avisos para los alumnos del curso: los ven arriba en el aula. Puedes avisarles también por correo."
          action={
            canPublish && (
              <Button variant="primary" icon={<Megaphone className="w-4 h-4" />} onClick={() => setDraft({ id: null, title: '', body: '', notify: false })}>
                Nuevo anuncio
              </Button>
            )
          }
        />
        {announcements.length === 0 ? (
          <EmptyState icon={Megaphone} title="Aún no hay anuncios" text="Úsalos para avisar cambios de horario, nuevas clases o recordatorios." />
        ) : (
          <ul className="divide-y divide-slate-100">
            {announcements.map((announcement) => (
              <li key={announcement.id} className="px-5 py-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div className="min-w-0">
                    <p className="text-sm font-semibold text-slate-900">{announcement.title}</p>
                    <p className="text-xs text-slate-500 mt-0.5">
                      {announcement.author ?? 'La academia'} · {formatWhen(announcement.created_at)}
                      {announcement.emailed && ' · enviado también por correo'}
                    </p>
                  </div>
                  {announcement.can_edit && (
                    <div className="flex gap-1.5">
                      <Button size="sm" variant="ghost" icon={<Pencil className="w-4 h-4" />} aria-label={`Editar «${announcement.title}»`} onClick={() => setDraft({ id: announcement.id, title: announcement.title, body: announcement.body, notify: false })} />
                      <Button size="sm" variant="ghost" icon={<Trash2 className="w-4 h-4" />} aria-label={`Borrar «${announcement.title}»`} onClick={() => setRemoving(announcement)} />
                    </div>
                  )}
                </div>
                <p className="text-[13px] text-slate-700 mt-2 whitespace-pre-line">{announcement.body}</p>
              </li>
            ))}
          </ul>
        )}
      </Card>

      <Card>
        <CardHeader
          title="Reseñas de los alumnos"
          text={
            reviews.count > 0 && reviews.average !== null
              ? `Promedio ${reviews.average} de 5 con ${reviews.count} ${reviews.count === 1 ? 'reseña visible' : 'reseñas visibles'}. Se ve en el catálogo público del curso.`
              : 'Los alumnos matriculados opinan desde el aula. Se publican al momento y la academia puede ocultarlas.'
          }
          action={
            reviews.data.length > 0 && (
              <div role="group" aria-label="Filtrar por estrellas" className="flex flex-wrap gap-1">
                {[0, 5, 4, 3, 2, 1].map((value) => {
                  const count = value === 0 ? reviews.data.length : reviews.data.filter((review) => review.rating === value).length;
                  return (
                    <button
                      key={value}
                      type="button"
                      aria-pressed={stars === value}
                      onClick={() => setStars(value)}
                      className={`h-7 px-2.5 rounded-lg text-xs font-semibold cursor-pointer ${stars === value ? 'bg-brand-700 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'}`}
                    >
                      {value === 0 ? 'Todas' : `${value} ★`} ({count})
                    </button>
                  );
                })}
              </div>
            )
          }
        />
        {reviews.data.length === 0 ? (
          <EmptyState icon={Star} title="Aún no hay reseñas" />
        ) : (
          <ul className="divide-y divide-slate-100">
            {reviews.data.filter((review) => stars === 0 || review.rating === stars).map((review) => (
              <li key={review.id} className="px-5 py-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                  <div className="flex flex-wrap items-center gap-2.5">
                    <Stars value={review.rating} />
                    <span className="text-[13px] font-semibold text-slate-900">{review.student.name}</span>
                    <span className="text-xs text-slate-500">{formatWhen(review.updated_at)}</span>
                    {review.status === 'HIDDEN' && <Badge tone="neutral">Oculta</Badge>}
                  </div>
                  {reviews.canModerate && (
                    <div className="flex gap-1.5">
                      <Button
                        size="sm"
                        icon={<MessageSquareReply className="w-4 h-4" />}
                        onClick={() => {
                          setAnswering(review);
                          setAnswer(review.response ?? '');
                        }}
                      >
                        {review.response ? 'Editar respuesta' : 'Responder'}
                      </Button>
                      {review.status === 'VISIBLE' ? (
                        <Button size="sm" icon={<EyeOff className="w-4 h-4" />} onClick={() => setHiding(review)}>
                          Ocultar
                        </Button>
                      ) : (
                        <Button size="sm" icon={<Eye className="w-4 h-4" />} onClick={() => void communityApi.show(courseId, review.id).then(load)}>
                          Volver a mostrar
                        </Button>
                      )}
                    </div>
                  )}
                </div>
                {review.comment && <p className="text-[13px] text-slate-700 mt-2 whitespace-pre-line">{review.comment}</p>}
                {review.status === 'HIDDEN' && review.hidden_reason && <p className="text-xs text-slate-500 mt-1">Motivo para ocultarla: {review.hidden_reason}</p>}
                {review.response && (
                  <p className="mt-2 ml-3 pl-3 border-l-2 border-brand-200 text-[13px] text-slate-600 whitespace-pre-line">
                    <span className="font-semibold text-slate-800">Respuesta de la academia: </span>
                    {review.response}
                  </p>
                )}
              </li>
            ))}
          </ul>
        )}
      </Card>

      {draft && (
        <Modal
          title={draft.id ? 'Editar anuncio' : 'Nuevo anuncio'}
          onClose={() => setDraft(null)}
          footer={
            <>
              <Button onClick={() => setDraft(null)}>Cancelar</Button>
              <Button variant="primary" icon={<Send className="w-4 h-4" />} processing={saving} disabled={!draft.title.trim() || !draft.body.trim()} onClick={save}>
                {draft.id ? 'Guardar' : 'Publicar'}
              </Button>
            </>
          }
        >
          <div className="space-y-3">
            {draftError && <Alert>{draftError}</Alert>}
            <Field label="Título">
              <input className={INPUT_CLASS} maxLength={150} value={draft.title} onChange={(event) => setDraft({ ...draft, title: event.target.value })} />
            </Field>
            <Field label="Mensaje">
              <textarea className={`${INPUT_CLASS} h-auto min-h-32 py-2`} maxLength={5000} value={draft.body} onChange={(event) => setDraft({ ...draft, body: event.target.value })} />
            </Field>
            {!draft.id && (
              <label className="flex items-start gap-2.5 cursor-pointer">
                <input type="checkbox" className={`${CHECKBOX_CLASS} mt-0.5`} checked={draft.notify} onChange={(event) => setDraft({ ...draft, notify: event.target.checked })} />
                <span className="text-[13px] text-slate-700">Avisar también por correo a los alumnos con acceso al curso</span>
              </label>
            )}
          </div>
        </Modal>
      )}

      {answering && (
        <Modal
          title="Responder la reseña"
          subtitle="La respuesta se publica debajo de la reseña, en el catálogo y en el aula del alumno. Déjala vacía para quitarla."
          onClose={() => setAnswering(null)}
          footer={
            <>
              <Button onClick={() => setAnswering(null)}>Cancelar</Button>
              <Button
                variant="primary"
                processing={saving}
                onClick={async () => {
                  setSaving(true);
                  setDraftError(null);
                  try {
                    await communityApi.respond(courseId, answering.id, answer.trim() || null);
                    setAnswering(null);
                    await load();
                  } catch (err) {
                    setDraftError(apiErrorMessage(err, 'No se pudo guardar la respuesta.'));
                  } finally {
                    setSaving(false);
                  }
                }}
              >
                {answer.trim() ? 'Publicar respuesta' : 'Quitar respuesta'}
              </Button>
            </>
          }
        >
          {draftError && (
            <div className="mb-3">
              <Alert>{draftError}</Alert>
            </div>
          )}
          <p className="text-[13px] text-slate-600 mb-3">«{answering.comment ?? 'Sin comentario'}» · {answering.rating} de 5</p>
          <Field label="Respuesta">
            <textarea className={`${INPUT_CLASS} h-auto min-h-28 py-2`} maxLength={1000} aria-label="Respuesta" value={answer} onChange={(event) => setAnswer(event.target.value)} />
          </Field>
        </Modal>
      )}

      {hiding && (
        <Modal
          title="Ocultar reseña"
          subtitle="Deja de verse en el catálogo y no cuenta en el promedio. El alumno puede corregirla y vuelve a verse."
          onClose={() => setHiding(null)}
          footer={
            <>
              <Button onClick={() => setHiding(null)}>Cancelar</Button>
              <Button variant="danger" processing={saving} disabled={reason.trim().length < 3} onClick={hide}>
                Ocultar
              </Button>
            </>
          }
        >
          {draftError && (
            <div className="mb-3">
              <Alert>{draftError}</Alert>
            </div>
          )}
          <Field label="Motivo" hint="Por ejemplo: publicidad, lenguaje ofensivo, no habla del curso.">
            <input className={INPUT_CLASS} maxLength={300} value={reason} onChange={(event) => setReason(event.target.value)} />
          </Field>
        </Modal>
      )}

      {removing && (
        <ConfirmModal
          title="Borrar anuncio"
          text={`«${removing.title}» deja de verse en el aula. Esto no se puede deshacer.`}
          confirmLabel="Borrar"
          onClose={() => setRemoving(null)}
          onConfirm={async () => {
            await communityApi.remove(courseId, removing.id);
            setRemoving(null);
            await load();
          }}
        />
      )}
    </div>
  );
};
