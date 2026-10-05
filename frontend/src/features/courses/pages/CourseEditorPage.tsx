import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import {
  Archive,
  ArchiveRestore,
  ArrowLeft,
  BookOpen,
  ChartNoAxesColumn,
  CheckCircle2,
  GraduationCap,
  History,
  ImageUp,
  Info,
  Layers,
  Rocket,
  Trash2,
  Users,
  type LucideIcon,
} from 'lucide-react';
import { useAuth } from '../../auth/AuthContext';
import { apiErrorMessage } from '../../../shared/api/client';
import {
  Alert,
  Avatar,
  Badge,
  Button,
  Card,
  CardHeader,
  CHECKBOX_CLASS,
  ConfirmModal,
  EmptyState,
  ErrorState,
  Field,
  INPUT_CLASS,
  LoadingState,
  Modal,
  Table,
  TBody,
  Td,
  Th,
  THead,
  Tr,
} from '../../../shared/ui';
import {
  coursesApi,
  formatDate,
  STATUS_LABEL,
  STATUS_TONE,
  type CourseDetail,
  type CourseInput,
  type CourseOptions,
} from '../api';
import { CourseFields } from '../components/CourseForm';
import { ContentTab } from '../components/ContentTab';
import { academicApi, type Activity } from '../../academic/api';
import { SectionActivities } from '../../academic/components/SectionActivities';
import { StudentsTab } from '../../enrollments/components/StudentsTab';
import { TrackingTab } from '../../academic/components/TrackingTab';

type TabId = 'content' | 'info' | 'teachers' | 'students' | 'tracking' | 'versions';

const TABS: { id: TabId; label: string; icon: LucideIcon }[] = [
  { id: 'content', label: 'Contenido', icon: Layers },
  { id: 'info', label: 'Información', icon: Info },
  { id: 'teachers', label: 'Profesores', icon: Users },
  { id: 'students', label: 'Alumnos', icon: GraduationCap },
  { id: 'tracking', label: 'Seguimiento', icon: ChartNoAxesColumn },
  { id: 'versions', label: 'Versiones', icon: History },
];

function toInput(course: CourseDetail): CourseInput {
  return {
    title: course.title,
    summary: course.summary,
    description: course.description,
    category: course.category,
    level: course.level,
    duration_hours: course.duration_hours,
    access_days: course.access_days,
    price: course.price !== null ? Number(course.price) : null,
    language: course.language,
  };
}

/* ───────────────────────────── Información ───────────────────────────── */

const CoverField: React.FC<{
  course: CourseDetail;
  options: CourseOptions;
  editable: boolean;
  onChanged: () => Promise<void>;
}> = ({ course, options, editable, onChanged }) => {
  const input = useRef<HTMLInputElement>(null);
  const [busy, setBusy] = useState<'upload' | 'remove' | null>(null);
  const [error, setError] = useState<string | null>(null);
  const maxMb = Math.round((options.cover.max_kb / 1024) * 10) / 10;

  const run = async (kind: 'upload' | 'remove', action: () => Promise<unknown>) => {
    setBusy(kind);
    setError(null);
    try {
      await action();
      await onChanged();
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo actualizar la portada.'));
    } finally {
      setBusy(null);
    }
  };

  const upload = (event: React.ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;
    if (file.size > options.cover.max_kb * 1024) {
      setError(`La imagen pesa más de ${maxMb} MB.`);
      return;
    }
    run('upload', () => coursesApi.uploadCover(course.id, file));
  };

  return (
    <div>
      <div className="relative aspect-[16/9] rounded-xl overflow-hidden bg-gradient-to-br from-brand-800 via-brand-700 to-sky-500">
        {course.cover_url ? (
          <img src={course.cover_url} alt="Portada del curso" className="w-full h-full object-cover" />
        ) : (
          <div className="absolute inset-0 flex items-center justify-center">
            <BookOpen className="w-10 h-10 text-white/40" aria-hidden="true" />
          </div>
        )}
      </div>
      {editable && (
        <div className="flex flex-wrap gap-2 mt-3">
          <input
            ref={input}
            type="file"
            className="sr-only"
            tabIndex={-1}
            aria-label="Archivo de la portada"
            accept={options.cover.extensions.map((extension) => `.${extension}`).join(',')}
            onChange={upload}
          />
          <Button
            size="sm"
            icon={<ImageUp className="w-3.5 h-3.5" />}
            processing={busy === 'upload'}
            disabled={busy !== null}
            onClick={() => input.current?.click()}
          >
            {course.cover_url ? 'Cambiar portada' : 'Subir portada'}
          </Button>
          {course.cover_url && (
            <Button
              size="sm"
              variant="danger"
              icon={<Trash2 className="w-3.5 h-3.5" />}
              processing={busy === 'remove'}
              disabled={busy !== null}
              onClick={() => run('remove', () => coursesApi.removeCover(course.id))}
            >
              Quitar
            </Button>
          )}
        </div>
      )}
      <p className="text-xs text-slate-500 mt-2">Imagen horizontal (16:9) · PNG, JPG o WebP · máximo {maxMb} MB.</p>
      {error && (
        <p className="text-xs font-medium text-rose-700 mt-1.5" role="alert">
          {error}
        </p>
      )}
    </div>
  );
};

const InfoTab: React.FC<{
  course: CourseDetail;
  options: CourseOptions;
  editable: boolean;
  onChanged: () => Promise<void>;
}> = ({ course, options, editable, onChanged }) => {
  const [form, setForm] = useState<CourseInput>(() => toInput(course));
  const [status, setStatus] = useState<'idle' | 'processing' | 'saved'>('idle');
  const [error, setError] = useState<string | null>(null);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setStatus('processing');
    setError(null);
    try {
      await coursesApi.update(course.id, form);
      await onChanged();
      setStatus('saved');
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudieron guardar los datos.'));
      setStatus('idle');
    }
  };

  return (
    <div className="grid grid-cols-1 lg:grid-cols-[1fr_320px] gap-5 items-start">
      <Card className="p-5 sm:p-6">
        <form onSubmit={submit} className="space-y-5">
          {error && <Alert>{error}</Alert>}
          <CourseFields
            value={form}
            options={options}
            disabled={!editable}
            withDescription
            onChange={(value) => {
              setForm(value);
              setStatus('idle');
            }}
          />
          {editable && (
            <div className="flex items-center gap-3 pt-1">
              <Button variant="primary" type="submit" processing={status === 'processing'}>
                Guardar datos
              </Button>
              {status === 'saved' && (
                <span className="flex items-center gap-1.5 text-[13px] font-medium text-emerald-700" role="status">
                  <CheckCircle2 className="w-4 h-4" /> Guardado
                </span>
              )}
            </div>
          )}
        </form>
      </Card>

      <Card className="p-5">
        <h3 className="text-sm font-semibold text-slate-900 mb-3">Portada</h3>
        <CoverField course={course} options={options} editable={editable} onChanged={onChanged} />
      </Card>
    </div>
  );
};

/* ───────────────────────────── Profesores ───────────────────────────── */

const TeachersTab: React.FC<{
  course: CourseDetail;
  options: CourseOptions;
  editable: boolean;
  onChanged: () => Promise<void>;
}> = ({ course, options, editable, onChanged }) => {
  const [selected, setSelected] = useState<string[]>(() => course.teachers.map((teacher) => teacher.id));
  const [status, setStatus] = useState<'idle' | 'processing' | 'saved'>('idle');
  const [error, setError] = useState<string | null>(null);

  // Sin permiso para asignar, solo se listan los profesores actuales
  const candidates = editable ? (options.teachers ?? []) : course.teachers;

  const toggle = (id: string) => {
    setSelected((current) => (current.includes(id) ? current.filter((item) => item !== id) : [...current, id]));
    setStatus('idle');
  };

  const save = async () => {
    setStatus('processing');
    setError(null);
    try {
      await coursesApi.setTeachers(course.id, selected);
      await onChanged();
      setStatus('saved');
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudieron guardar los profesores.'));
      setStatus('idle');
    }
  };

  return (
    <Card>
      <CardHeader
        title="Profesores del curso"
        text="Un profesor ve y edita el contenido solo de los cursos donde está asignado."
      />
      {error && (
        <div className="px-5 pt-4">
          <Alert>{error}</Alert>
        </div>
      )}
      {candidates.length === 0 ? (
        <EmptyState
          icon={Users}
          title={editable ? 'No hay usuarios con rol de profesor' : 'Este curso no tiene profesores asignados'}
          text={editable ? 'Crea un usuario con el rol Profesor en Usuarios y vuelve aquí para asignarlo.' : undefined}
        />
      ) : (
        <ul className="divide-y divide-slate-100">
          {candidates.map((teacher) => (
            <li key={teacher.id}>
              <label className={`flex items-center gap-3 px-5 py-3 ${editable ? 'cursor-pointer hover:bg-brand-50/30' : ''}`}>
                {editable && (
                  <input
                    type="checkbox"
                    className={CHECKBOX_CLASS}
                    checked={selected.includes(teacher.id)}
                    onChange={() => toggle(teacher.id)}
                  />
                )}
                <Avatar name={teacher.name} />
                <span className="min-w-0">
                  <span className="block text-[13px] font-semibold text-slate-900 truncate">{teacher.name}</span>
                  <span className="block text-xs text-slate-500 truncate">{teacher.email}</span>
                </span>
              </label>
            </li>
          ))}
        </ul>
      )}
      {editable && candidates.length > 0 && (
        <div className="flex items-center gap-3 px-5 py-4 border-t border-slate-200/80">
          <Button variant="primary" processing={status === 'processing'} onClick={save}>
            Guardar profesores
          </Button>
          {status === 'saved' && (
            <span className="flex items-center gap-1.5 text-[13px] font-medium text-emerald-700" role="status">
              <CheckCircle2 className="w-4 h-4" /> Guardado
            </span>
          )}
        </div>
      )}
    </Card>
  );
};

/* ───────────────────────────── Versiones ───────────────────────────── */

const VersionsTab: React.FC<{ course: CourseDetail }> = ({ course }) => (
  <Card>
    <CardHeader
      title="Historial de publicaciones"
      text="Cada publicación guarda una copia del curso. Los alumnos ven siempre la última."
    />
    {course.versions.length === 0 ? (
      <EmptyState icon={History} title="Este curso aún no se ha publicado" text="Al publicarlo nacerá la versión 1." />
    ) : (
      <Table>
        <THead>
          <Th>Versión</Th>
          <Th>Publicada</Th>
          <Th>Por</Th>
          <Th>Contenido</Th>
          <Th>Nota</Th>
        </THead>
        <TBody>
          {course.versions.map((version) => (
            <Tr key={version.id}>
              <Td className="whitespace-nowrap">
                <span className="font-semibold text-slate-900 tnum">v{version.number}</span>
                {version.number === course.version && (
                  <span className="ml-2">
                    <Badge tone="success" dot>
                      Vigente
                    </Badge>
                  </span>
                )}
              </Td>
              <Td className="whitespace-nowrap text-slate-600 tnum">{formatDate(version.published_at)}</Td>
              <Td className="whitespace-nowrap text-slate-600">{version.published_by ?? '—'}</Td>
              <Td className="whitespace-nowrap text-slate-600 tnum">
                {version.sections_count} secc. · {version.resources_count} rec.
              </Td>
              <Td className="text-slate-600 min-w-[180px]">{version.note ?? '—'}</Td>
            </Tr>
          ))}
        </TBody>
      </Table>
    )}
  </Card>
);

/* ───────────────────────────── Publicación ───────────────────────────── */

const PublishModal: React.FC<{ course: CourseDetail; onClose: () => void; onDone: () => Promise<void> }> = ({
  course,
  onClose,
  onDone,
}) => {
  const [note, setNote] = useState('');
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const next = (course.version ?? 0) + 1;

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setProcessing(true);
    setError(null);
    try {
      await coursesApi.publish(course.id, note.trim() || null);
      await onDone();
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo publicar el curso.'));
      setProcessing(false);
    }
  };

  return (
    <Modal
      title={course.version ? `Publicar versión ${next}` : 'Publicar curso'}
      subtitle={
        course.version
          ? 'Los alumnos pasarán a ver el contenido tal como está ahora.'
          : 'El curso quedará publicado con el contenido tal como está ahora.'
      }
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="publish-form" processing={processing} icon={<Rocket className="w-4 h-4" />}>
            Publicar
          </Button>
        </>
      }
    >
      <form id="publish-form" onSubmit={submit} className="space-y-4">
        {error && <Alert>{error}</Alert>}
        <Field label="Nota de la versión (opcional)" hint="Para tu equipo: qué cambió en esta publicación.">
          <input
            className={INPUT_CLASS}
            maxLength={300}
            placeholder={course.version ? 'Ej. Se agregó el módulo 3' : 'Ej. Lanzamiento'}
            value={note}
            onChange={(event) => setNote(event.target.value)}
          />
        </Field>
      </form>
    </Modal>
  );
};

/* ───────────────────────────── Página ───────────────────────────── */

export const CourseEditorPage: React.FC = () => {
  const { courseId = '' } = useParams();
  const { hasPermission } = useAuth();
  const canUpdate = hasPermission('courses.update');
  const canPublish = hasPermission('courses.publish');

  const [course, setCourse] = useState<CourseDetail | null>(null);
  const [canEditContent, setCanEditContent] = useState(false);
  const [canManageActivities, setCanManageActivities] = useState(false);
  const [canReview, setCanReview] = useState(false);
  const [activities, setActivities] = useState<Activity[]>([]);
  const [gradeScale, setGradeScale] = useState(20);
  const [options, setOptions] = useState<CourseOptions | null>(null);
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [loadError, setLoadError] = useState('');
  const [tab, setTab] = useState<TabId>('content');
  const [dialog, setDialog] = useState<'publish' | 'archive' | 'restore' | null>(null);
  const [attempt, setAttempt] = useState(0);

  const reload = useCallback(async () => {
    const [result, academic] = await Promise.all([coursesApi.get(courseId), academicApi.activities(courseId)]);
    setCourse(result.course);
    setCanEditContent(result.canEditContent);
    setCanManageActivities(result.canManageActivities);
    setCanReview(result.canReview);
    setActivities(academic.activities);
    setGradeScale(academic.gradeScale);
  }, [courseId]);

  useEffect(() => {
    let active = true;
    Promise.all([coursesApi.get(courseId), coursesApi.options(), academicApi.activities(courseId)])
      .then(([result, loadedOptions, academic]) => {
        if (!active) return;
        setCourse(result.course);
        setCanEditContent(result.canEditContent);
        setCanManageActivities(result.canManageActivities);
        setCanReview(result.canReview);
        setActivities(academic.activities);
        setGradeScale(academic.gradeScale);
        setOptions(loadedOptions);
        setState('ready');
      })
      .catch((err) => {
        if (!active) return;
        setLoadError(apiErrorMessage(err, 'Verifica tu conexión e inténtalo nuevamente.'));
        setState('error');
      });

    return () => {
      active = false;
    };
  }, [courseId, attempt]);

  const retry = () => {
    setState('loading');
    setAttempt((n) => n + 1);
  };

  if (state === 'loading') return <LoadingState />;
  if (state === 'error' || !course || !options) {
    return (
      <Card>
        <ErrorState message={loadError} onRetry={retry} />
      </Card>
    );
  }

  const archived = course.status === 'ARCHIVED';
  const hasContent = course.resources_count > 0 || activities.length > 0;
  const nothingNew = course.status === 'PUBLISHED' && !course.pending_changes;
  const publishHint = !hasContent
    ? 'Agrega al menos una sección con un recurso o una actividad para poder publicar.'
    : nothingNew
      ? 'No hay cambios nuevos que publicar.'
      : undefined;

  const closeAndReload = async () => {
    await reload();
    setDialog(null);
  };

  return (
    <div>
      <Link to="/courses" className="inline-flex items-center gap-1.5 text-[13px] font-medium text-slate-500 hover:text-brand-700 mb-4">
        <ArrowLeft className="w-4 h-4" /> Cursos
      </Link>

      {/* Cabecera del curso */}
      <div className="flex flex-wrap items-start justify-between gap-4 mb-5">
        <div className="flex items-start gap-4 min-w-0">
          <div className="hidden sm:block w-28 shrink-0 aspect-[16/9] rounded-xl overflow-hidden bg-gradient-to-br from-brand-800 via-brand-700 to-sky-500 shadow-card">
            {course.cover_url ? (
              <img src={course.cover_url} alt="" className="w-full h-full object-cover" />
            ) : (
              <div className="w-full h-full flex items-center justify-center">
                <BookOpen className="w-6 h-6 text-white/50" aria-hidden="true" />
              </div>
            )}
          </div>
          <div className="min-w-0">
            <h1 className="text-[22px] leading-7 font-bold text-slate-900 tracking-tight">{course.title}</h1>
            <div className="flex flex-wrap items-center gap-2 mt-2">
              <Badge tone={STATUS_TONE[course.status]} dot>
                {STATUS_LABEL[course.status]}
              </Badge>
              {course.version && <Badge tone="brand">Versión {course.version}</Badge>}
              {course.pending_changes && <Badge tone="info">Cambios sin publicar</Badge>}
              {course.category && <span className="text-[13px] text-slate-500">{course.category}</span>}
            </div>
          </div>
        </div>

        {canPublish && (
          <div className="flex flex-wrap items-center gap-2">
            {archived ? (
              <Button icon={<ArchiveRestore className="w-4 h-4" />} onClick={() => setDialog('restore')}>
                Restaurar
              </Button>
            ) : (
              <>
                <Button icon={<Archive className="w-4 h-4" />} onClick={() => setDialog('archive')}>
                  Archivar
                </Button>
                <Button
                  variant="primary"
                  icon={<Rocket className="w-4 h-4" />}
                  disabled={publishHint !== undefined}
                  title={publishHint}
                  onClick={() => setDialog('publish')}
                >
                  {course.version ? 'Publicar cambios' : 'Publicar'}
                </Button>
              </>
            )}
          </div>
        )}
      </div>

      {archived && (
        <div className="mb-5">
          <Alert tone="info">Este curso está archivado: no se puede modificar ni publicar hasta restaurarlo.</Alert>
        </div>
      )}
      {!archived && course.pending_changes && (
        <div className="mb-5">
          <Alert tone="info">
            Hay cambios que los alumnos todavía no ven. Seguirán viendo la versión {course.version} hasta que publiques.
          </Alert>
        </div>
      )}
      {!archived && canPublish && !course.version && publishHint && (
        <div className="mb-5">
          <Alert tone="info">{publishHint}</Alert>
        </div>
      )}

      {/* Pestañas */}
      <div className="flex gap-1 mb-5 border-b border-slate-200 overflow-x-auto overflow-y-hidden" role="tablist" aria-label="Secciones del curso">
        {TABS.filter(({ id }) => (id !== 'students' || hasPermission('enrollments.read') || canReview) && (id !== 'tracking' || canReview)).map(({ id, label, icon: Icon }) => (
          <button
            key={id}
            type="button"
            role="tab"
            aria-selected={tab === id}
            onClick={() => setTab(id)}
            className={`inline-flex items-center gap-2 px-3.5 h-10 -mb-px border-b-2 text-[13px] font-semibold whitespace-nowrap transition-colors cursor-pointer ${
              tab === id ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-800'
            }`}
          >
            <Icon className="w-4 h-4" />
            {label}
            {id === 'versions' && course.versions.length > 0 && (
              <span className="text-xs font-medium text-slate-400 tnum">{course.versions.length}</span>
            )}
          </button>
        ))}
      </div>

      {tab === 'content' && (
        <ContentTab
          course={course}
          options={options}
          editable={canEditContent && !archived}
          onChanged={reload}
          sectionExtra={(section) => (
            <SectionActivities
              courseId={course.id}
              sectionId={section.id}
              activities={activities.filter((activity) => activity.section_id === section.id)}
              gradeScale={gradeScale}
              editable={canManageActivities && !archived}
              canReadBanks={hasPermission('question_banks.read')}
              onChanged={reload}
            />
          )}
        />
      )}
      {tab === 'info' && <InfoTab course={course} options={options} editable={canUpdate && !archived} onChanged={reload} />}
      {tab === 'teachers' && <TeachersTab course={course} options={options} editable={canUpdate} onChanged={reload} />}
      {tab === 'students' && <StudentsTab course={course} />}
      {tab === 'tracking' && <TrackingTab courseId={course.id} />}
      {tab === 'versions' && <VersionsTab course={course} />}

      {dialog === 'publish' && <PublishModal course={course} onClose={() => setDialog(null)} onDone={closeAndReload} />}
      {dialog === 'archive' && (
        <ConfirmModal
          title="Archivar curso"
          text="El curso dejará de ofrecerse y no se podrá modificar. Podrás restaurarlo cuando quieras; no se borra nada."
          confirmLabel="Archivar"
          onClose={() => setDialog(null)}
          onConfirm={async () => {
            await coursesApi.archive(course.id);
            await closeAndReload();
          }}
        />
      )}
      {dialog === 'restore' && (
        <ConfirmModal
          title="Restaurar curso"
          text={
            course.version
              ? `El curso volverá a estar publicado con la versión ${course.version}.`
              : 'El curso volverá a ser un borrador.'
          }
          confirmLabel="Restaurar"
          variant="primary"
          onClose={() => setDialog(null)}
          onConfirm={async () => {
            await coursesApi.restore(course.id);
            await closeAndReload();
          }}
        />
      )}
    </div>
  );
};
