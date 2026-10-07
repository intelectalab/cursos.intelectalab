import React, { useState } from 'react';
import { ArrowDown, ArrowUp, ListPlus, Pencil, Plus, Trash2, TriangleAlert, type LucideIcon } from 'lucide-react';
import { apiErrorMessage } from '../../../shared/api/client';
import { Button, ConfirmModal } from '../../../shared/ui';
import { academicApi, ACTIVITY_LABEL, toScale, type Activity, type QuizItem, type SurveyQuestion } from '../api';
import { ACTIVITY_ICON } from '../icons';
import { ActivityModal, QuizQuestionsModal } from './ActivityModals';

const IconButton: React.FC<{ label: string; icon: LucideIcon; onClick: () => void; disabled?: boolean; danger?: boolean }> = ({
  label,
  icon: Icon,
  onClick,
  disabled = false,
  danger = false,
}) => (
  <button
    type="button"
    aria-label={label}
    title={label}
    disabled={disabled}
    onClick={onClick}
    className={`p-1.5 rounded-lg transition-colors cursor-pointer disabled:opacity-30 disabled:cursor-not-allowed ${
      danger ? 'text-slate-500 hover:text-rose-600 hover:bg-rose-50' : 'text-slate-500 hover:text-brand-700 hover:bg-brand-50'
    }`}
  >
    <Icon className="w-4 h-4" />
  </button>
);

// Resumen de la configuración y, si la hay, la razón por la que aún no se puede publicar
function describe(activity: Activity, gradeScale: number): { text: string; problem?: string } {
  const count = activity.questions?.length ?? 0;

  if (activity.type === 'QUIZ') {
    const points = (activity.questions as QuizItem[]).reduce((sum, item) => sum + item.points, 0);
    const parts = [
      `${count} ${count === 1 ? 'pregunta' : 'preguntas'}${count > 0 ? ` · ${points} pts` : ''}`,
      `aprueba con ${toScale(activity.passing_percent ?? 0, gradeScale)} de ${gradeScale}`,
      activity.max_attempts ? `${activity.max_attempts} ${activity.max_attempts === 1 ? 'intento' : 'intentos'}` : 'intentos ilimitados',
    ];
    if (activity.time_limit_minutes) parts.push(`${activity.time_limit_minutes} min`);
    return { text: parts.join(' · '), problem: count === 0 ? 'Sin preguntas' : undefined };
  }

  if (activity.type === 'TASK') {
    const accepts = [activity.allow_text && 'texto', activity.allow_file && 'archivo'].filter(Boolean).join(' y ');
    return { text: `Entrega: ${accepts}` };
  }

  const required = (activity.questions as SurveyQuestion[]).filter((question) => question.required).length;
  return {
    text: `${count} ${count === 1 ? 'pregunta' : 'preguntas'}${count > 0 ? ` · ${required} obligatorias` : ''}`,
    problem: count === 0 ? 'Sin preguntas' : undefined,
  };
}

type Dialog = { kind: 'edit'; activity: Activity | null } | { kind: 'questions'; activity: Activity } | { kind: 'delete'; activity: Activity };

export const SectionActivities: React.FC<{
  courseId: string;
  sectionId: string;
  activities: Activity[];
  gradeScale: number;
  editable: boolean;
  canReadBanks: boolean;
  onChanged: () => Promise<void>;
}> = ({ courseId, sectionId, activities, gradeScale, editable, canReadBanks, onChanged }) => {
  const [dialog, setDialog] = useState<Dialog | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const move = async (index: number, offset: number) => {
    const ids = activities.map((activity) => activity.id);
    const [id] = ids.splice(index, 1);
    ids.splice(index + offset, 0, id);

    setBusy(true);
    setError(null);
    try {
      await academicApi.reorderActivities(courseId, sectionId, ids);
      await onChanged();
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo reordenar.'));
    } finally {
      setBusy(false);
    }
  };

  const save = async (action: () => Promise<unknown>) => {
    await action();
    await onChanged();
    setDialog(null);
  };

  if (activities.length === 0 && !editable) return null;

  return (
    <div className="border-t border-slate-200/80 bg-slate-50/40">
      <p className="px-4 sm:px-5 pt-3 text-[11px] font-semibold uppercase tracking-wide text-slate-500">Actividades</p>

      {error && (
        <p className="px-5 pt-2 text-xs font-medium text-rose-700" role="alert">
          {error}
        </p>
      )}

      <ul className="divide-y divide-slate-100">
        {activities.map((activity, index) => {
          const { icon: Icon, tone } = ACTIVITY_ICON[activity.type];
          const { text, problem } = describe(activity, gradeScale);
          return (
            <li key={activity.id} className="flex items-center gap-3 px-4 sm:px-5 py-3">
              <span className={`w-9 h-9 shrink-0 rounded-xl inline-flex items-center justify-center ${tone}`}>
                <Icon className="w-4 h-4" />
              </span>
              <div className="min-w-0 flex-1">
                <p className="text-[13px] font-medium text-slate-900 truncate">{activity.title}</p>
                <p className="text-xs text-slate-500 truncate">
                  {ACTIVITY_LABEL[activity.type]} · {text}
                </p>
                {problem && (
                  <p className="inline-flex items-center gap-1 mt-0.5 text-xs font-medium text-amber-700">
                    <TriangleAlert className="w-3.5 h-3.5" /> {problem}: no se podrá publicar el curso
                  </p>
                )}
              </div>
              {editable && (
                <div className="flex items-center shrink-0">
                  {activity.type === 'QUIZ' && (
                    <IconButton label="Elegir preguntas" icon={ListPlus} onClick={() => setDialog({ kind: 'questions', activity })} />
                  )}
                  <IconButton label="Subir actividad" icon={ArrowUp} disabled={busy || index === 0} onClick={() => move(index, -1)} />
                  <IconButton
                    label="Bajar actividad"
                    icon={ArrowDown}
                    disabled={busy || index === activities.length - 1}
                    onClick={() => move(index, 1)}
                  />
                  <IconButton label="Editar actividad" icon={Pencil} onClick={() => setDialog({ kind: 'edit', activity })} />
                  <IconButton label="Eliminar actividad" icon={Trash2} danger onClick={() => setDialog({ kind: 'delete', activity })} />
                </div>
              )}
            </li>
          );
        })}
      </ul>

      {editable && (
        <div className="px-4 sm:px-5 py-3">
          <Button size="sm" icon={<Plus className="w-3.5 h-3.5" />} onClick={() => setDialog({ kind: 'edit', activity: null })}>
            Agregar actividad
          </Button>
        </div>
      )}

      {dialog?.kind === 'edit' && (
        <ActivityModal
          activity={dialog.activity}
          gradeScale={gradeScale}
          onClose={() => setDialog(null)}
          onSubmit={(input) =>
            save(() =>
              dialog.activity
                ? academicApi.updateActivity(courseId, dialog.activity.id, input)
                : academicApi.createActivity(courseId, sectionId, input),
            )
          }
        />
      )}
      {dialog?.kind === 'questions' && (
        <QuizQuestionsModal
          activity={dialog.activity}
          canReadBanks={canReadBanks}
          onClose={() => setDialog(null)}
          onSubmit={(items) => save(() => academicApi.setQuizQuestions(courseId, dialog.activity.id, items))}
        />
      )}
      {dialog?.kind === 'delete' && (
        <ConfirmModal
          title="Eliminar actividad"
          text={`Se eliminará «${dialog.activity.title}». Lo ya publicado no cambia hasta que publiques de nuevo.`}
          confirmLabel="Eliminar actividad"
          onClose={() => setDialog(null)}
          onConfirm={() => save(() => academicApi.deleteActivity(courseId, dialog.activity.id))}
        />
      )}
    </div>
  );
};
