import React, { useState } from 'react';
import {
  ArrowDown,
  ArrowUp,
  Download,
  FileText,
  Layers,
  Link2,
  Paperclip,
  Pencil,
  Play,
  Plus,
  Trash2,
  Video,
  type LucideIcon,
} from 'lucide-react';
import { apiErrorMessage } from '../../../shared/api/client';
import { Alert, Button, Card, ConfirmModal, EmptyState, Field, INPUT_CLASS, Modal, type Tone } from '../../../shared/ui';
import {
  coursesApi,
  formatSize,
  RESOURCE_LABEL,
  type CourseDetail,
  type CourseOptions,
  type CourseResource,
  type CourseSection,
  type ResourceType,
} from '../api';

const RESOURCE_ICON: Record<ResourceType, { icon: LucideIcon; tone: string }> = {
  VIDEO: { icon: Video, tone: 'bg-rose-50 text-rose-600' },
  DOCUMENT: { icon: Paperclip, tone: 'bg-amber-50 text-amber-600' },
  LINK: { icon: Link2, tone: 'bg-sky-50 text-sky-600' },
  TEXT: { icon: FileText, tone: 'bg-violet-50 text-violet-600' },
};

const RESOURCE_HINT: Record<ResourceType, string> = {
  VIDEO: 'Una clase alojada en YouTube.',
  DOCUMENT: 'Un archivo que el alumno descarga.',
  LINK: 'Una página o herramienta externa.',
  TEXT: 'Contenido escrito dentro del curso.',
};

const IconButton: React.FC<{
  label: string;
  icon: LucideIcon;
  onClick: () => void;
  disabled?: boolean;
  tone?: Extract<Tone, 'neutral' | 'danger'>;
}> = ({ label, icon: Icon, onClick, disabled = false, tone = 'neutral' }) => (
  <button
    type="button"
    aria-label={label}
    title={label}
    disabled={disabled}
    onClick={onClick}
    className={`p-1.5 rounded-lg transition-colors cursor-pointer disabled:opacity-30 disabled:cursor-not-allowed ${
      tone === 'danger' ? 'text-slate-400 hover:text-rose-600 hover:bg-rose-50' : 'text-slate-400 hover:text-brand-700 hover:bg-brand-50'
    }`}
  >
    <Icon className="w-4 h-4" />
  </button>
);

/* ───────────────────────────── Formularios ───────────────────────────── */

const SectionModal: React.FC<{
  section: CourseSection | null;
  onClose: () => void;
  onSubmit: (title: string) => Promise<void>;
}> = ({ section, onClose, onSubmit }) => {
  const [title, setTitle] = useState(section?.title ?? '');
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setProcessing(true);
    setError(null);
    try {
      await onSubmit(title.trim());
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo guardar la sección.'));
      setProcessing(false);
    }
  };

  return (
    <Modal
      title={section ? 'Renombrar sección' : 'Nueva sección'}
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="section-form" processing={processing}>
            {section ? 'Guardar' : 'Agregar sección'}
          </Button>
        </>
      }
    >
      <form id="section-form" onSubmit={submit} className="space-y-4">
        {error && <Alert>{error}</Alert>}
        <Field label="Título de la sección">
          <input
            className={INPUT_CLASS}
            required
            maxLength={200}
            autoFocus
            placeholder="Ej. Módulo 1: Fundamentos"
            value={title}
            onChange={(event) => setTitle(event.target.value)}
          />
        </Field>
      </form>
    </Modal>
  );
};

const ResourceModal: React.FC<{
  resource: CourseResource | null;
  options: CourseOptions;
  onClose: () => void;
  onSubmit: (input: { type: ResourceType; title: string; url?: string; body?: string; file?: File | null }) => Promise<void>;
}> = ({ resource, options, onClose, onSubmit }) => {
  const [type, setType] = useState<ResourceType>(resource?.type ?? 'VIDEO');
  const [title, setTitle] = useState(resource?.title ?? '');
  const [url, setUrl] = useState(resource?.url ?? '');
  const [body, setBody] = useState(resource?.body ?? '');
  const [file, setFile] = useState<File | null>(null);
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const maxMb = Math.round(options.material.max_kb / 1024);
  const formats = options.material.extensions.map((extension) => extension.toUpperCase()).join(', ');

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();

    if (type === 'DOCUMENT' && file && file.size > options.material.max_kb * 1024) {
      setError(`El archivo pesa más de ${maxMb} MB.`);
      return;
    }

    setProcessing(true);
    setError(null);
    try {
      await onSubmit({
        type,
        title: title.trim(),
        url: type === 'LINK' || type === 'VIDEO' ? url.trim() : undefined,
        body: type === 'TEXT' ? body : undefined,
        file: type === 'DOCUMENT' ? file : undefined,
      });
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo guardar el recurso.'));
      setProcessing(false);
    }
  };

  return (
    <Modal
      title={resource ? 'Editar recurso' : 'Nuevo recurso'}
      subtitle={resource ? RESOURCE_LABEL[resource.type] : undefined}
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="resource-form" processing={processing}>
            {resource ? 'Guardar' : 'Agregar recurso'}
          </Button>
        </>
      }
    >
      <form id="resource-form" onSubmit={submit} className="space-y-4">
        {error && <Alert>{error}</Alert>}

        {!resource && (
          <fieldset>
            <legend className="block text-[13px] font-medium text-slate-700 mb-1.5">Tipo de recurso</legend>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
              {options.resource_types.map((option) => {
                const { icon: Icon, tone } = RESOURCE_ICON[option];
                const selected = option === type;
                return (
                  <label
                    key={option}
                    className={`flex items-center gap-3 p-3 rounded-xl border cursor-pointer transition-all ${
                      selected ? 'border-brand-500 bg-brand-50/60 ring-4 ring-brand-500/10' : 'border-slate-200 hover:border-slate-300'
                    }`}
                  >
                    <input
                      type="radio"
                      name="resource-type"
                      className="sr-only"
                      checked={selected}
                      onChange={() => setType(option)}
                    />
                    <span className={`w-8 h-8 shrink-0 rounded-lg inline-flex items-center justify-center ${tone}`}>
                      <Icon className="w-4 h-4" />
                    </span>
                    <span>
                      <span className="block text-[13px] font-semibold text-slate-900">{RESOURCE_LABEL[option]}</span>
                      <span className="block text-xs text-slate-500">{RESOURCE_HINT[option]}</span>
                    </span>
                  </label>
                );
              })}
            </div>
          </fieldset>
        )}

        <Field label="Título">
          <input className={INPUT_CLASS} required maxLength={200} value={title} onChange={(event) => setTitle(event.target.value)} />
        </Field>

        {type === 'DOCUMENT' && (
          <Field
            label={resource ? 'Reemplazar archivo (opcional)' : 'Archivo'}
            hint={`${formats} · máximo ${maxMb} MB.${resource?.file ? ` Actual: ${resource.file.name}.` : ''}`}
          >
            <input
              type="file"
              required={!resource}
              accept={options.material.extensions.map((extension) => `.${extension}`).join(',')}
              onChange={(event) => setFile(event.target.files?.[0] ?? null)}
              className="block w-full text-[13px] text-slate-600 file:mr-3 file:h-9 file:px-3.5 file:rounded-lg file:border file:border-solid file:border-slate-300 file:bg-white file:text-[13px] file:font-semibold file:text-slate-700 file:cursor-pointer hover:file:bg-slate-50"
            />
          </Field>
        )}
        {type === 'VIDEO' && (
          <Field
            label="Enlace del video en YouTube"
            hint="Pega el enlace tal como lo copias de YouTube. Si el video está como «No listado», solo lo verá quien tenga el enlace; uno «Privado» no se puede reproducir aquí."
          >
            <input
              className={INPUT_CLASS}
              type="url"
              required
              maxLength={2000}
              placeholder="https://www.youtube.com/watch?v=…"
              value={url}
              onChange={(event) => setUrl(event.target.value)}
            />
          </Field>
        )}
        {type === 'LINK' && (
          <Field label="Dirección" hint="Debe empezar con http:// o https://">
            <input
              className={INPUT_CLASS}
              type="url"
              required
              maxLength={2000}
              placeholder="https://"
              value={url}
              onChange={(event) => setUrl(event.target.value)}
            />
          </Field>
        )}
        {type === 'TEXT' && (
          <Field label="Contenido">
            <textarea className={INPUT_CLASS} required rows={10} maxLength={50000} value={body} onChange={(event) => setBody(event.target.value)} />
          </Field>
        )}
      </form>
    </Modal>
  );
};

/* ───────────────────────────── Pestaña ───────────────────────────── */

type Dialog =
  | { kind: 'section'; section: CourseSection | null }
  | { kind: 'resource'; section: CourseSection; resource: CourseResource | null }
  | { kind: 'video'; resource: CourseResource }
  | { kind: 'delete-section'; section: CourseSection }
  | { kind: 'delete-resource'; resource: CourseResource };

function moved<T>(items: T[], index: number, offset: number): T[] {
  const next = [...items];
  const [item] = next.splice(index, 1);
  next.splice(index + offset, 0, item);
  return next;
}

export const ContentTab: React.FC<{
  course: CourseDetail;
  options: CourseOptions;
  editable: boolean;
  onChanged: () => Promise<void>;
  // Contenido que otros módulos muestran dentro de cada sección (actividades)
  sectionExtra?: (section: CourseSection) => React.ReactNode;
}> = ({ course, options, editable, onChanged, sectionExtra }) => {
  const [dialog, setDialog] = useState<Dialog | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  // Acciones directas (mover, descargar): el error se muestra arriba de la lista
  const run = async (action: () => Promise<unknown>, reload = true) => {
    setBusy(true);
    setError(null);
    try {
      await action();
      if (reload) await onChanged();
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo completar la acción.'));
    } finally {
      setBusy(false);
    }
  };

  // Acciones de un diálogo: si fallan, el diálogo muestra el error y sigue abierto
  const save = async (action: () => Promise<unknown>) => {
    await action();
    await onChanged();
    setDialog(null);
  };

  return (
    <div className="space-y-4">
      {error && <Alert>{error}</Alert>}

      {course.sections.length === 0 && (
        <Card>
          <EmptyState
            icon={Layers}
            title="Este curso aún no tiene contenido"
            text="Organízalo en secciones (módulos o unidades) y agrega recursos dentro de cada una."
            action={
              editable && (
                <Button variant="primary" icon={<Plus className="w-4 h-4" />} onClick={() => setDialog({ kind: 'section', section: null })}>
                  Agregar la primera sección
                </Button>
              )
            }
          />
        </Card>
      )}

      {course.sections.map((section, sectionIndex) => (
        <Card key={section.id} className="overflow-hidden">
          <div className="flex items-center gap-3 px-4 sm:px-5 py-3.5 bg-slate-50/70 border-b border-slate-200/80">
            <span className="w-7 h-7 shrink-0 rounded-lg bg-brand-700 text-white text-xs font-bold inline-flex items-center justify-center tnum">
              {sectionIndex + 1}
            </span>
            <div className="min-w-0 flex-1">
              <h3 className="text-sm font-semibold text-slate-900 truncate">{section.title}</h3>
              <p className="text-xs text-slate-500">
                {section.resources.length} {section.resources.length === 1 ? 'recurso' : 'recursos'}
              </p>
            </div>
            {editable && (
              <div className="flex items-center shrink-0">
                <IconButton
                  label="Subir sección"
                  icon={ArrowUp}
                  disabled={busy || sectionIndex === 0}
                  onClick={() =>
                    run(() => coursesApi.reorderSections(course.id, moved(course.sections, sectionIndex, -1).map((item) => item.id)))
                  }
                />
                <IconButton
                  label="Bajar sección"
                  icon={ArrowDown}
                  disabled={busy || sectionIndex === course.sections.length - 1}
                  onClick={() =>
                    run(() => coursesApi.reorderSections(course.id, moved(course.sections, sectionIndex, 1).map((item) => item.id)))
                  }
                />
                <IconButton label="Renombrar sección" icon={Pencil} onClick={() => setDialog({ kind: 'section', section })} />
                <IconButton label="Eliminar sección" icon={Trash2} tone="danger" onClick={() => setDialog({ kind: 'delete-section', section })} />
              </div>
            )}
          </div>

          <ul className="divide-y divide-slate-100">
            {section.resources.map((resource, resourceIndex) => {
              const { icon: Icon, tone } = RESOURCE_ICON[resource.type];
              return (
                <li key={resource.id} className="flex items-center gap-3 px-4 sm:px-5 py-3 hover:bg-brand-50/30 transition-colors">
                  {resource.video_id ? (
                    <button
                      type="button"
                      aria-label={`Ver el video ${resource.title}`}
                      title="Ver video"
                      onClick={() => setDialog({ kind: 'video', resource })}
                      className="group/video relative w-16 shrink-0 aspect-video rounded-lg overflow-hidden bg-slate-900 cursor-pointer"
                    >
                      <img
                        src={`https://i.ytimg.com/vi/${resource.video_id}/mqdefault.jpg`}
                        alt=""
                        loading="lazy"
                        className="w-full h-full object-cover opacity-80 group-hover/video:opacity-100 transition-opacity"
                      />
                      <span className="absolute inset-0 flex items-center justify-center">
                        <span className="w-6 h-6 rounded-full bg-black/60 text-white inline-flex items-center justify-center">
                          <Play className="w-3 h-3 fill-current" />
                        </span>
                      </span>
                    </button>
                  ) : (
                    <span className={`w-9 h-9 shrink-0 rounded-xl inline-flex items-center justify-center ${tone}`}>
                      <Icon className="w-4 h-4" />
                    </span>
                  )}
                  <div className="min-w-0 flex-1">
                    <p className="text-[13px] font-medium text-slate-900 truncate">{resource.title}</p>
                    <p className="text-xs text-slate-500 truncate">
                      {RESOURCE_LABEL[resource.type]}
                      {resource.file && ` · ${resource.file.name} · ${formatSize(resource.file.size_bytes)}`}
                      {resource.type === 'VIDEO' && ' · YouTube'}
                      {resource.type === 'LINK' && resource.url && (
                        <>
                          {' · '}
                          <a href={resource.url} target="_blank" rel="noopener noreferrer" className="text-brand-600 hover:underline">
                            {resource.url}
                          </a>
                        </>
                      )}
                      {resource.body && ` · ${resource.body.slice(0, 90)}`}
                    </p>
                  </div>
                  <div className="flex items-center shrink-0">
                    {resource.file && (
                      <IconButton
                        label="Descargar archivo"
                        icon={Download}
                        disabled={busy}
                        onClick={() => run(() => coursesApi.download(course.id, resource), false)}
                      />
                    )}
                    {editable && (
                      <>
                        <IconButton
                          label="Subir recurso"
                          icon={ArrowUp}
                          disabled={busy || resourceIndex === 0}
                          onClick={() =>
                            run(() =>
                              coursesApi.reorderResources(
                                course.id,
                                section.id,
                                moved(section.resources, resourceIndex, -1).map((item) => item.id),
                              ),
                            )
                          }
                        />
                        <IconButton
                          label="Bajar recurso"
                          icon={ArrowDown}
                          disabled={busy || resourceIndex === section.resources.length - 1}
                          onClick={() =>
                            run(() =>
                              coursesApi.reorderResources(
                                course.id,
                                section.id,
                                moved(section.resources, resourceIndex, 1).map((item) => item.id),
                              ),
                            )
                          }
                        />
                        <IconButton label="Editar recurso" icon={Pencil} onClick={() => setDialog({ kind: 'resource', section, resource })} />
                        <IconButton
                          label="Eliminar recurso"
                          icon={Trash2}
                          tone="danger"
                          onClick={() => setDialog({ kind: 'delete-resource', resource })}
                        />
                      </>
                    )}
                  </div>
                </li>
              );
            })}
          </ul>

          {section.resources.length === 0 && (
            <p className="px-5 py-5 text-[13px] text-slate-500">Esta sección aún no tiene recursos.</p>
          )}

          {editable && (
            <div className="px-4 sm:px-5 py-3 border-t border-slate-100">
              <Button size="sm" icon={<Plus className="w-3.5 h-3.5" />} onClick={() => setDialog({ kind: 'resource', section, resource: null })}>
                Agregar recurso
              </Button>
            </div>
          )}

          {sectionExtra?.(section)}
        </Card>
      ))}

      {editable && course.sections.length > 0 && (
        <Button icon={<Plus className="w-4 h-4" />} onClick={() => setDialog({ kind: 'section', section: null })}>
          Agregar sección
        </Button>
      )}

      {dialog?.kind === 'section' && (
        <SectionModal
          section={dialog.section}
          onClose={() => setDialog(null)}
          onSubmit={(title) =>
            save(() =>
              dialog.section ? coursesApi.renameSection(course.id, dialog.section.id, title) : coursesApi.addSection(course.id, title),
            )
          }
        />
      )}

      {dialog?.kind === 'resource' && (
        <ResourceModal
          resource={dialog.resource}
          options={options}
          onClose={() => setDialog(null)}
          onSubmit={(input) =>
            save(() =>
              dialog.resource
                ? coursesApi.updateResource(course.id, dialog.resource.id, input)
                : coursesApi.addResource(course.id, dialog.section.id, input),
            )
          }
        />
      )}

      {dialog?.kind === 'video' && dialog.resource.video_id && (
        <Modal title={dialog.resource.title} subtitle="Así lo verá el alumno" onClose={() => setDialog(null)}>
          <div className="aspect-video rounded-xl overflow-hidden bg-slate-900">
            <iframe
              className="w-full h-full"
              src={`https://www.youtube-nocookie.com/embed/${dialog.resource.video_id}?rel=0`}
              title={dialog.resource.title}
              allow="accelerometer; encrypted-media; gyroscope; picture-in-picture; fullscreen"
              referrerPolicy="strict-origin-when-cross-origin"
              allowFullScreen
            />
          </div>
        </Modal>
      )}

      {dialog?.kind === 'delete-section' && (
        <ConfirmModal
          title="Eliminar sección"
          text={
            dialog.section.resources.length > 0
              ? `Se eliminará "${dialog.section.title}" junto con sus ${dialog.section.resources.length} recursos. Lo ya publicado no cambia hasta que publiques de nuevo.`
              : `Se eliminará "${dialog.section.title}".`
          }
          confirmLabel="Eliminar sección"
          onClose={() => setDialog(null)}
          onConfirm={() => save(() => coursesApi.deleteSection(course.id, dialog.section.id))}
        />
      )}

      {dialog?.kind === 'delete-resource' && (
        <ConfirmModal
          title="Eliminar recurso"
          text={`Se eliminará "${dialog.resource.title}". Lo ya publicado no cambia hasta que publiques de nuevo.`}
          confirmLabel="Eliminar recurso"
          onClose={() => setDialog(null)}
          onConfirm={() => save(() => coursesApi.deleteResource(course.id, dialog.resource.id))}
        />
      )}
    </div>
  );
};
