import React, { useEffect, useState } from 'react';
import { Megaphone, MessageSquareHeart } from 'lucide-react';
import { apiErrorMessage } from '../../../shared/api/client';
import { Alert, Button, INPUT_CLASS } from '../../../shared/ui';
import { communityApi, formatWhen, type Announcement, type Review } from '../community';
import { Stars } from './Stars';

/** Anuncios del curso, arriba en el aula. Si no hay, no se muestra nada. */
export const ClassroomAnnouncements: React.FC<{ courseId: string }> = ({ courseId }) => {
  const [items, setItems] = useState<Announcement[]>([]);
  const [all, setAll] = useState(false);

  useEffect(() => {
    let active = true;
    communityApi
      .myAnnouncements(courseId)
      .then((data) => active && setItems(data))
      .catch(() => undefined);
    return () => {
      active = false;
    };
  }, [courseId]);

  if (items.length === 0) return null;

  const shown = all ? items : items.slice(0, 2);

  return (
    <section aria-label="Anuncios del curso" className="mb-5 rounded-2xl border border-sky-200 bg-gradient-to-r from-sky-50 to-white px-4 py-3.5">
      <div className="flex items-center gap-2.5 mb-2">
        <span className="w-8 h-8 rounded-xl bg-sky-100 text-sky-700 flex items-center justify-center shrink-0">
          <Megaphone className="w-4 h-4" />
        </span>
        <h2 className="text-sm font-semibold text-slate-900">Anuncios</h2>
      </div>
      <ul className="divide-y divide-sky-100">
        {shown.map((item) => (
          <li key={item.id} className="py-2.5">
            <p className="text-[13px] font-semibold text-slate-900">{item.title}</p>
            <p className="text-xs text-slate-500">
              {item.author ?? 'La academia'} · {formatWhen(item.created_at)}
            </p>
            <p className="text-[13px] text-slate-700 mt-1 whitespace-pre-line">{item.body}</p>
          </li>
        ))}
      </ul>
      {items.length > 2 && (
        <button type="button" className="text-xs font-semibold text-sky-700 underline cursor-pointer" onClick={() => setAll(!all)}>
          {all ? 'Ver menos' : `Ver los ${items.length} anuncios`}
        </button>
      )}
    </section>
  );
};

/** «Tu opinión del curso»: el alumno califica de 1 a 5 y comenta; puede cambiarla. */
export const ClassroomReview: React.FC<{ courseId: string }> = ({ courseId }) => {
  const [review, setReview] = useState<Review | null>(null);
  const [rating, setRating] = useState(0);
  const [comment, setComment] = useState('');
  const [editing, setEditing] = useState(false);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);

  useEffect(() => {
    let active = true;
    communityApi
      .myReview(courseId)
      .then((data) => {
        if (!active) return;
        setReview(data);
        setRating(data?.rating ?? 0);
        setComment(data?.comment ?? '');
      })
      .catch(() => undefined);
    return () => {
      active = false;
    };
  }, [courseId]);

  const save = async () => {
    setSaving(true);
    setMessage(null);
    try {
      const saved = await communityApi.saveMyReview(courseId, { rating, comment: comment.trim() || null });
      setReview(saved);
      setEditing(false);
      setMessage({ tone: 'success', text: '¡Gracias! Tu opinión ayuda a otros a elegir el curso.' });
    } catch (err) {
      setMessage({ tone: 'danger', text: apiErrorMessage(err, 'No se pudo guardar tu opinión.') });
    } finally {
      setSaving(false);
    }
  };

  const form = !review || editing;

  return (
    <section aria-label="Tu opinión del curso" className="mt-5 rounded-2xl border border-slate-200 bg-white px-4 py-3.5">
      <div className="flex items-center gap-2.5 mb-2">
        <span className="w-8 h-8 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center shrink-0">
          <MessageSquareHeart className="w-4 h-4" />
        </span>
        <h2 className="text-sm font-semibold text-slate-900">Tu opinión del curso</h2>
      </div>

      {message && (
        <div className="mb-2">
          <Alert tone={message.tone}>{message.text}</Alert>
        </div>
      )}

      {form ? (
        <div className="space-y-2.5">
          <Stars value={rating} onChange={setRating} size="md" />
          <textarea
            className={`${INPUT_CLASS} h-auto min-h-20 py-2`}
            maxLength={1000}
            placeholder="¿Qué te pareció? (opcional)"
            aria-label="Comentario"
            value={comment}
            onChange={(event) => setComment(event.target.value)}
          />
          <div className="flex gap-2">
            <Button variant="primary" size="sm" processing={saving} disabled={rating === 0} onClick={save}>
              {review ? 'Guardar cambios' : 'Publicar mi opinión'}
            </Button>
            {review && (
              <Button size="sm" onClick={() => setEditing(false)}>
                Cancelar
              </Button>
            )}
          </div>
          <p className="text-xs text-slate-500">Se publica con tu nombre y la inicial de tu apellido en la página pública del curso.</p>
        </div>
      ) : (
        <div className="space-y-1.5">
          <div className="flex flex-wrap items-center gap-2.5">
            <Stars value={review.rating} />
            <button type="button" className="text-xs font-semibold text-brand-700 underline cursor-pointer" onClick={() => setEditing(true)}>
              Cambiar
            </button>
          </div>
          {review.comment && <p className="text-[13px] text-slate-700 whitespace-pre-line">{review.comment}</p>}
          {review.status === 'HIDDEN' && <p className="text-xs text-amber-700">La academia ocultó tu opinión. Si la corriges, vuelve a publicarse.</p>}
          {review.response && (
            <p className="mt-1 pl-3 border-l-2 border-brand-200 text-[13px] text-slate-600 whitespace-pre-line">
              <span className="font-semibold text-slate-800">Respuesta de la academia: </span>
              {review.response}
            </p>
          )}
        </div>
      )}
    </section>
  );
};
