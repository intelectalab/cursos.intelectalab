import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import { ArrowLeft, ArrowRight, CheckCircle2, Download, ExternalLink, Eye, FileText, Link2, Lock, Paperclip, Video, type LucideIcon } from 'lucide-react';
import { apiErrorMessage } from '../../../shared/api/client';
import { Alert, Badge, Button, Card, EmptyState, ErrorState, LoadingState } from '../../../shared/ui';
import { ACTIVITY_LABEL, toScale } from '../../academic/api';
import { QuizPanel } from '../../academic/components/QuizPanel';
import { StudentGrades } from '../../academic/components/StudentGrades';
import { SurveyPanel, TaskPanel } from '../../academic/components/StudentPanels';
import { ACTIVITY_ICON } from '../../academic/icons';
import { studentApi, type ActivityState, type QuizState, type SurveyState, type TaskState } from '../../academic/studentApi';
import { formatSize, RESOURCE_LABEL, type ResourceType } from '../../courses/api';
import {
  enrollmentsApi,
  type ClassroomActivity,
  type ClassroomContent,
  type ClassroomResource,
  type PreviewMeta,
  type PreviewSource,
  type Progress,
} from '../api';

const RESOURCE_ICON: Record<ResourceType, LucideIcon> = { VIDEO: Video, DOCUMENT: Paperclip, LINK: Link2, TEXT: FileText };

// Recursos y actividades de todas las secciones, en el orden en que se recorren
type Item =
  | { key: string; kind: 'resource'; section: string; locked: boolean; resource: ClassroomResource }
  | { key: string; kind: 'activity'; section: string; locked: boolean; activity: ClassroomActivity };

const PREVIEW_HINT: Record<ClassroomActivity['type'], string> = {
  QUIZ: 'Aquí el alumno rinde el cuestionario. En la vista previa no se abren intentos.',
  TASK: 'Aquí el alumno escribe o adjunta su entrega. En la vista previa no se entrega nada.',
  SURVEY: 'Aquí el alumno responde la encuesta. En la vista previa no se registran respuestas.',
};

const ResourceView: React.FC<{ resource: ClassroomResource; onDownload: () => Promise<void> }> = ({ resource, onDownload }) => {
  const [downloading, setDownloading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const download = async () => {
    setDownloading(true);
    setError(null);
    try {
      await onDownload();
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo descargar el archivo.'));
    } finally {
      setDownloading(false);
    }
  };

  if (resource.type === 'VIDEO' && resource.video_id) {
    return (
      <div className="aspect-video rounded-xl overflow-hidden bg-slate-900">
        <iframe
          className="w-full h-full"
          src={`https://www.youtube-nocookie.com/embed/${resource.video_id}?rel=0`}
          title={resource.title}
          allow="accelerometer; encrypted-media; gyroscope; picture-in-picture; fullscreen"
          referrerPolicy="strict-origin-when-cross-origin"
          allowFullScreen
        />
      </div>
    );
  }

  if (resource.type === 'TEXT') {
    return <div className="text-sm leading-relaxed text-slate-700 whitespace-pre-wrap break-words">{resource.body}</div>;
  }

  if (resource.type === 'LINK' && resource.url) {
    return (
      <div className="flex flex-wrap items-center gap-4 p-4 rounded-xl border border-slate-200 bg-slate-50/60">
        <div className="min-w-0 flex-1">
          <p className="text-[13px] text-slate-500">Este recurso se abre en otra página.</p>
          <p className="text-[13px] font-medium text-slate-900 truncate">{resource.url}</p>
        </div>
        <a
          href={resource.url}
          target="_blank"
          rel="noopener noreferrer"
          className="inline-flex items-center gap-2 h-9 px-3.5 rounded-lg text-[13px] font-semibold text-white bg-gradient-to-r from-brand-700 to-brand-600 hover:from-brand-800 hover:to-brand-700 shadow-sm shadow-brand-700/30"
        >
          <ExternalLink className="w-4 h-4" /> Abrir enlace
        </a>
      </div>
    );
  }

  return (
    <div className="flex flex-wrap items-center gap-4 p-4 rounded-xl border border-slate-200 bg-slate-50/60">
      <span className="w-11 h-11 shrink-0 rounded-xl bg-amber-50 text-amber-600 inline-flex items-center justify-center">
        <Paperclip className="w-5 h-5" />
      </span>
      <div className="min-w-0 flex-1">
        <p className="text-[13px] font-medium text-slate-900 truncate">{resource.file?.name}</p>
        {resource.file && <p className="text-xs text-slate-500">{formatSize(resource.file.size_bytes)}</p>}
        {error && (
          <p className="text-xs font-medium text-rose-700 mt-1" role="alert">
            {error}
          </p>
        )}
      </div>
      <Button variant="primary" icon={<Download className="w-4 h-4" />} processing={downloading} onClick={download}>
        Descargar
      </Button>
    </div>
  );
};

const ActivityView: React.FC<{
  courseId: string;
  activity: ClassroomActivity;
  state: ActivityState | undefined;
  gradeScale: number;
  // Vista previa del personal: se muestra la ficha, sin rendir ni entregar
  preview: boolean;
  onChanged: () => Promise<void>;
}> = ({ courseId, activity, state, gradeScale, preview, onChanged }) => {
  const facts: string[] = [];

  if (activity.type === 'QUIZ') {
    facts.push(`${activity.questions_count} ${activity.questions_count === 1 ? 'pregunta' : 'preguntas'}`);
    if (activity.passing_percent != null) facts.push(`Se aprueba con ${toScale(activity.passing_percent, gradeScale)} de ${gradeScale}`);
    if (activity.time_limit_minutes) facts.push(`${activity.time_limit_minutes} minutos`);
  } else if (activity.type === 'SURVEY') {
    facts.push(`${activity.questions_count} ${activity.questions_count === 1 ? 'pregunta' : 'preguntas'}`);
  }

  return (
    <div className="space-y-4">
      {activity.description && <p className="text-sm leading-relaxed text-slate-700 whitespace-pre-wrap break-words">{activity.description}</p>}
      {facts.length > 0 && (
        <div className="flex flex-wrap gap-2">
          {facts.map((fact) => (
            <Badge key={fact}>{fact}</Badge>
          ))}
        </div>
      )}

      {preview ? (
        <Alert tone="info">{PREVIEW_HINT[activity.type]}</Alert>
      ) : !state ? (
        <LoadingState />
      ) : activity.type === 'QUIZ' ? (
        <QuizPanel courseId={courseId} activityId={activity.id} state={state as QuizState} gradeScale={gradeScale} onChanged={onChanged} />
      ) : activity.type === 'TASK' ? (
        <TaskPanel
          courseId={courseId}
          activityId={activity.id}
          allowText={activity.allow_text ?? false}
          allowFile={activity.allow_file ?? false}
          state={state as TaskState}
          gradeScale={gradeScale}
          onChanged={onChanged}
        />
      ) : (
        <SurveyPanel courseId={courseId} activityId={activity.id} state={state as SurveyState} onChanged={onChanged} />
      )}
    </div>
  );
};

export const ClassroomPage: React.FC<{ preview?: boolean }> = ({ preview = false }) => {
  const { courseId = '' } = useParams();
  const [classroom, setClassroom] = useState<ClassroomContent | null>(null);
  // Vista previa: qué se está mirando (lo publicado o el borrador)
  const [source, setSource] = useState<PreviewSource | undefined>(undefined);
  const [previewMeta, setPreviewMeta] = useState<PreviewMeta | null>(null);
  const [progress, setProgress] = useState<Progress | null>(null);
  const [states, setStates] = useState<Record<string, ActivityState>>({});
  const [gradeScale, setGradeScale] = useState(20);
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [error, setError] = useState('');
  const [attempt, setAttempt] = useState(0);
  // Un enlace puede abrir el aula directamente en un elemento (?item=a-…)
  const [searchParams] = useSearchParams();
  const [currentKey, setCurrentKey] = useState<string | null>(() => searchParams.get('item'));

  useEffect(() => {
    let active = true;
    (preview
      ? enrollmentsApi.preview(courseId, source).then((result) => {
          if (!active) return;
          setClassroom(result.classroom);
          setPreviewMeta(result.meta);
          setGradeScale(result.gradeScale);
          setState('ready');
        })
      : Promise.all([enrollmentsApi.classroom(courseId), studentApi.states(courseId)]).then(([result, activityStates]) => {
          if (!active) return;
          setClassroom(result.classroom);
          setProgress(result.classroom.progress);
          setStates(activityStates);
          setGradeScale(result.gradeScale);
          setState('ready');
        })
    )
      .catch((err) => {
        if (!active) return;
        setError(apiErrorMessage(err, 'Verifica tu conexión e inténtalo nuevamente.'));
        setState('error');
      });
    return () => {
      active = false;
    };
  }, [courseId, attempt, preview, source]);

  // Tras rendir o entregar: estado de las actividades y avance al día
  const refresh = useCallback(async () => {
    const [result, activityStates] = await Promise.all([enrollmentsApi.classroom(courseId), studentApi.states(courseId)]);
    // Con avance secuencial, lo recién completado puede abrir la sección siguiente
    setClassroom(result.classroom);
    setProgress(result.classroom.progress);
    setStates(activityStates);
  }, [courseId]);

  const items = useMemo<Item[]>(
    () =>
      (classroom?.sections ?? []).flatMap((section) => [
        ...section.resources.map((resource): Item => ({ key: `r-${resource.id}`, kind: 'resource', section: section.title, locked: section.locked, resource })),
        ...section.activities.map((activity): Item => ({ key: `a-${activity.id}`, kind: 'activity', section: section.title, locked: section.locked, activity })),
      ]),
    [classroom],
  );

  const found = items.findIndex((item) => item.key === currentKey);
  // Un elemento de una sección cerrada no se abre: se vuelve al primero
  const index = found >= 0 && !items[found].locked ? found : 0;
  const current = items[index] as Item | undefined;
  const openResourceId = current?.kind === 'resource' ? current.resource.id : null;

  // Un recurso cuenta como visto al abrirlo; el servidor lleva la cuenta
  useEffect(() => {
    if (preview || !openResourceId || progress?.resource_ids.includes(openResourceId)) return;
    let active = true;
    enrollmentsApi
      .completeResource(courseId, openResourceId)
      .then((result) => {
        if (!active) return;
        setProgress(result);
        // Con avance secuencial, completar este recurso puede abrir la sección siguiente
        if (classroom?.sequential) {
          enrollmentsApi
            .classroom(courseId)
            .then((fresh) => active && setClassroom(fresh.classroom))
            .catch(() => undefined);
        }
      })
      .catch(() => undefined);
    return () => {
      active = false;
    };
    // El avance se actualiza con la respuesta: no debe volver a disparar la marca
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [courseId, openResourceId]);

  if (state === 'loading') return <LoadingState />;
  if (state === 'error' || !classroom) {
    return (
      <Card>
        <ErrorState
          message={error}
          onRetry={() => {
            setState('loading');
            setAttempt((n) => n + 1);
          }}
        />
      </Card>
    );
  }


  const isDone = (item: Item) =>
    item.kind === 'resource' ? progress?.resource_ids.includes(item.resource.id) : progress?.activity_ids.includes(item.activity.id);

  const go = (key: string) => {
    setCurrentKey(key);
    document.getElementById('classroom-viewer')?.scrollIntoView({ block: 'start', behavior: 'smooth' });
  };

  return (
    <div>
      <Link
        to={preview ? `/courses/${courseId}` : '/my-courses'}
        className="inline-flex items-center gap-1.5 text-[13px] font-medium text-slate-500 hover:text-brand-700 mb-4"
      >
        <ArrowLeft className="w-4 h-4" /> {preview ? 'Volver al curso' : 'Mis cursos'}
      </Link>

      {preview && previewMeta && (
        <div className="flex flex-wrap items-center gap-3 px-4 py-3 mb-5 rounded-xl border border-amber-200 bg-amber-50 text-amber-900" role="status">
          <Eye className="w-5 h-5 shrink-0 text-amber-600" />
          <p className="flex-1 min-w-0 text-[13px]">
            <span className="font-semibold">Vista previa del aula.</span>{' '}
            {previewMeta.source === 'published'
              ? `Así ven los alumnos la versión ${previewMeta.version} publicada.`
              : 'Así se verá el borrador cuando lo publiques.'}{' '}
            No se registra avance ni se rinden actividades.
            {classroom.sequential && ' El curso tiene avance secuencial: aquí se muestra todo abierto.'}
          </p>
          {previewMeta.has_published && previewMeta.pending_changes && (
            <div className="flex gap-1.5" role="group" aria-label="Qué ver en la vista previa">
              <Button size="sm" variant={previewMeta.source === 'published' ? 'primary' : 'secondary'} onClick={() => setSource('published')}>
                Publicado
              </Button>
              <Button size="sm" variant={previewMeta.source === 'draft' ? 'primary' : 'secondary'} onClick={() => setSource('draft')}>
                Borrador
              </Button>
            </div>
          )}
        </div>
      )}

      <div className="flex flex-wrap items-end justify-between gap-4 mb-5">
        <div className="min-w-0">
          {classroom.category && <p className="text-xs font-semibold text-brand-600 mb-1">{classroom.category}</p>}
          <h1 className="text-[22px] leading-7 font-bold text-slate-900 tracking-tight">{classroom.title}</h1>
          {classroom.summary && <p className="text-[13px] text-slate-500 mt-1">{classroom.summary}</p>}
        </div>
        {progress && progress.total > 0 && (
          <div className="w-full sm:w-64">
            <div className="flex items-baseline justify-between text-xs mb-1.5">
              <span className="font-medium text-slate-600">Tu avance</span>
              <span className="font-semibold text-slate-900 tnum">
                {progress.percent}% · {progress.completed} de {progress.total}
              </span>
            </div>
            <div
              className="h-2 rounded-full bg-slate-200 overflow-hidden"
              role="progressbar"
              aria-valuenow={progress.percent}
              aria-valuemin={0}
              aria-valuemax={100}
              aria-label="Avance del curso"
            >
              <div
                className={`h-full rounded-full transition-all duration-500 ${progress.percent === 100 ? 'bg-emerald-500' : 'bg-gradient-to-r from-brand-600 to-sky-500'}`}
                style={{ width: `${progress.percent}%` }}
              />
            </div>
            <StudentGrades courseId={courseId} gradeScale={gradeScale} watch={states} />
          </div>
        )}
      </div>

      {!preview && classroom.sections.some((section) => section.locked) && (
        <div className="mb-5">
          <Alert tone="info">Este curso se recorre en orden: cada sección se abre cuando completas la anterior.</Alert>
        </div>
      )}

      {!current ? (
        <Card>
          <EmptyState title="Este curso aún no tiene contenido" />
        </Card>
      ) : (
        <div className="grid grid-cols-1 lg:grid-cols-[1fr_320px] gap-5 items-start">
          {/* Contenido elegido */}
          <Card className="p-5 sm:p-6 scroll-mt-4">
            <div id="classroom-viewer" className="scroll-mt-24">
              <p className="text-xs font-medium text-slate-500">
                {current.section} ·{' '}
                {current.kind === 'resource' ? RESOURCE_LABEL[current.resource.type] : ACTIVITY_LABEL[current.activity.type]}
              </p>
              <h2 className="text-lg font-semibold text-slate-900 mt-0.5 mb-4">
                {current.kind === 'resource' ? current.resource.title : current.activity.title}
              </h2>

              {current.kind === 'resource' ? (
                <ResourceView
                  key={current.key}
                  resource={current.resource}
                  onDownload={() =>
                    preview
                      ? enrollmentsApi.downloadAsStaff(classroom.course_id, current.resource)
                      : enrollmentsApi.download(classroom.course_id, current.resource)
                  }
                />
              ) : (
                <ActivityView
                  key={current.key}
                  courseId={classroom.course_id}
                  activity={current.activity}
                  state={states[current.activity.id]}
                  gradeScale={gradeScale}
                  preview={preview}
                  onChanged={refresh}
                />
              )}
            </div>

            <div className="flex items-center justify-between gap-3 mt-6 pt-4 border-t border-slate-200/80">
              <Button icon={<ArrowLeft className="w-4 h-4" />} disabled={index === 0} onClick={() => go(items[index - 1].key)}>
                Anterior
              </Button>
              <span className="text-xs text-slate-500 tnum">
                {index + 1} de {items.length}
              </span>
              <Button
                variant="primary"
                disabled={index === items.length - 1 || items[index + 1].locked}
                title={items[index + 1]?.locked ? 'Completa esta sección para abrir la siguiente' : undefined}
                onClick={() => go(items[index + 1].key)}
              >
                Siguiente <ArrowRight className="w-4 h-4" />
              </Button>
            </div>
          </Card>

          {/* Temario */}
          <Card className="overflow-hidden lg:sticky lg:top-4">
            <h2 className="px-4 py-3 text-sm font-semibold text-slate-900 border-b border-slate-200/80">Contenido del curso</h2>
            <nav aria-label="Contenido del curso" className="max-h-[70vh] overflow-y-auto">
              {classroom.sections.map((section, sectionIndex) => (
                <div key={section.id}>
                  <p className="px-4 pt-3 pb-1.5 text-xs font-semibold text-slate-500">
                    {sectionIndex + 1}. {section.title}
                    {section.locked && <span className="block font-normal mt-0.5">Se abre al completar la sección anterior</span>}
                  </p>
                  <ul>
                    {items
                      .filter((item) => (item.kind === 'resource' ? section.resources.includes(item.resource) : section.activities.includes(item.activity)))
                      .map((item) => {
                        const selected = item.key === current.key;
                        const done = isDone(item);
                        const Icon = item.kind === 'resource' ? RESOURCE_ICON[item.resource.type] : ACTIVITY_ICON[item.activity.type].icon;
                        return (
                          <li key={item.key}>
                            <button
                              type="button"
                              aria-current={selected}
                              disabled={item.locked}
                              onClick={() => go(item.key)}
                              className={`w-full flex items-center gap-2.5 px-4 py-2 text-left text-[13px] transition-colors ${
                                item.locked
                                  ? 'text-slate-500 cursor-not-allowed'
                                  : selected
                                    ? 'bg-brand-50 text-brand-800 font-semibold cursor-pointer'
                                    : 'text-slate-700 hover:bg-slate-50 cursor-pointer'
                              }`}
                            >
                              {item.locked ? (
                                <Lock className="w-4 h-4 shrink-0 text-slate-400" aria-label="Cerrado" />
                              ) : (
                                <Icon className={`w-4 h-4 shrink-0 ${selected ? 'text-brand-600' : 'text-slate-500'}`} />
                              )}
                              <span className="truncate flex-1">{item.kind === 'resource' ? item.resource.title : item.activity.title}</span>
                              {done && (
                                <>
                                  <CheckCircle2 className="w-4 h-4 shrink-0 text-emerald-500" aria-hidden="true" />
                                  <span className="sr-only">Completado</span>
                                </>
                              )}
                            </button>
                          </li>
                        );
                      })}
                  </ul>
                </div>
              ))}
              <div className="h-2" />
            </nav>
          </Card>
        </div>
      )}
    </div>
  );
};

// Vista previa del aula para el personal: el mismo recorrido, sin matrícula ni avance
export const ClassroomPreviewPage: React.FC = () => <ClassroomPage preview />;
