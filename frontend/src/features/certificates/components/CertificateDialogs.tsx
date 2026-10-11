import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Award, CopyPlus, ImageUp, Pencil, Search, Star, Trash2 } from 'lucide-react';
import { apiErrorMessage } from '../../../shared/api/client';
import { Alert, Badge, Button, CHECKBOX_CLASS, EmptyState, Field, INPUT_CLASS, LoadingState, Modal } from '../../../shared/ui';
import { SelectAll, SelectRow } from '../../../shared/ui/filters';
import {
  certificatesApi,
  formatDate,
  templateApi,
  type Certificate,
  type CertificateCandidate,
  type CertificateTemplate,
  type SignatureRules,
} from '../api';
import { CertificateDesignSection } from './CertificateDesign';

// Emisión manual: alumnos que ya cumplen los requisitos y no tienen certificado vigente
export const IssueModal: React.FC<{ onClose: () => void; onIssued: () => Promise<void> }> = ({ onClose, onIssued }) => {
  const [search, setSearch] = useState('');
  const [candidates, setCandidates] = useState<CertificateCandidate[] | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [selected, setSelected] = useState<string[]>([]);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setCandidates(await certificatesApi.candidates(search));
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo cargar la lista.'));
      setCandidates([]);
    }
  }, [search]);

  useEffect(() => {
    const timer = setTimeout(load, 250);
    return () => clearTimeout(timer);
  }, [load]);

  const issue = async (candidate: CertificateCandidate) => {
    setBusy(candidate.enrollment_id);
    setError(null);
    try {
      await certificatesApi.issue(candidate.enrollment_id);
      await onIssued();
      await load();
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo emitir el certificado.'));
    } finally {
      setBusy(null);
    }
  };

  const issueSelected = async () => {
    setBusy('many');
    setError(null);
    setNotice(null);
    try {
      const result = await certificatesApi.issueMany(selected);
      setNotice(`Se ${result.issued === 1 ? 'emitió 1 certificado' : `emitieron ${result.issued} certificados`}.${result.skipped > 0 ? ` ${result.skipped} ya no cumplían los requisitos.` : ''}`);
      setSelected([]);
      await onIssued();
      await load();
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudieron emitir los certificados.'));
    } finally {
      setBusy(null);
    }
  };

  return (
    <Modal
      title="Emitir certificado"
      subtitle="Alumnos que completaron un curso con certificado y aún no tienen uno vigente."
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cerrar</Button>
          {selected.length > 0 && (
            <Button variant="primary" processing={busy === 'many'} disabled={busy !== null} onClick={issueSelected}>
              Emitir {selected.length} {selected.length === 1 ? 'elegido' : 'elegidos'}
            </Button>
          )}
        </>
      }
    >
      <div className="space-y-4">
        {error && <Alert>{error}</Alert>}
        {notice && <Alert tone="success">{notice}</Alert>}
        <div className="relative">
          <Search className="w-4 h-4 text-slate-500 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" />
          <input
            className={`${INPUT_CLASS} pl-9`}
            placeholder="Buscar por alumno o curso"
            aria-label="Buscar alumno o curso"
            autoFocus
            value={search}
            onChange={(event) => setSearch(event.target.value)}
          />
        </div>

        {candidates === null && <LoadingState />}
        {candidates?.length === 0 && (
          <EmptyState
            icon={Award}
            title="Nadie pendiente de certificado"
            text="Los certificados se emiten solos cuando un alumno completa un curso que los entrega. Aquí aparecen los casos que quedaron sin emitir."
          />
        )}
        {candidates && candidates.length > 0 && (
          <ul className="rounded-xl border border-slate-200 divide-y divide-slate-100">
            <li className="px-3.5 py-2 bg-slate-50/80 rounded-t-xl">
              <SelectAll ids={candidates.map((candidate) => candidate.enrollment_id)} selected={selected} onChange={setSelected}>
                Seleccionar todos ({candidates.length})
              </SelectAll>
            </li>
            {candidates.map((candidate) => (
              <li key={candidate.enrollment_id} className="flex flex-wrap items-center gap-3 px-3.5 py-3">
                <SelectRow id={candidate.enrollment_id} label={candidate.student.name} selected={selected} onChange={setSelected} />
                <div className="min-w-0 flex-1">
                  <p className="text-[13px] font-semibold text-slate-900 truncate">{candidate.student.name}</p>
                  <p className="text-xs text-slate-500 truncate">
                    {candidate.course.title} · completado el {formatDate(candidate.completed_at)}
                  </p>
                </div>
                <Button size="sm" variant="primary" processing={busy === candidate.enrollment_id} disabled={busy !== null} onClick={() => issue(candidate)}>
                  Emitir
                </Button>
              </li>
            ))}
          </ul>
        )}
      </div>
    </Modal>
  );
};

export const RevokeModal: React.FC<{ certificate: Certificate; onClose: () => void; onRevoked: () => Promise<void> }> = ({
  certificate,
  onClose,
  onRevoked,
}) => {
  const [reason, setReason] = useState('');
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setProcessing(true);
    setError(null);
    try {
      await certificatesApi.revoke(certificate.id, reason.trim());
      await onRevoked();
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo anular el certificado.'));
      setProcessing(false);
    }
  };

  return (
    <Modal
      title="Anular certificado"
      subtitle={`${certificate.student.name} · ${certificate.course.title}`}
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="danger" type="submit" form="revoke-certificate-form" processing={processing}>
            Anular certificado
          </Button>
        </>
      }
    >
      <form id="revoke-certificate-form" onSubmit={submit} className="space-y-4">
        {error && <Alert>{error}</Alert>}
        <Alert tone="info">
          El certificado <strong className="font-mono">{certificate.code}</strong> dejará de ser válido: quien lo verifique verá que fue anulado y el
          alumno ya no podrá descargarlo. No se puede deshacer, pero puedes emitirle uno nuevo.
        </Alert>
        <Field label="Motivo" hint="Queda registrado en la auditoría. No se muestra en la verificación pública.">
          <textarea
            className={INPUT_CLASS}
            rows={3}
            required
            minLength={5}
            maxLength={500}
            autoFocus
            value={reason}
            onChange={(event) => setReason(event.target.value)}
          />
        </Field>
      </form>
    </Modal>
  );
};

// Plantilla del certificado: quién firma. El diseño es el mismo para toda la academia.
// Quién firma y cómo se ve una plantilla
const TemplateForm: React.FC<{ templateId: string | null; onSaved: () => void }> = ({ templateId, onSaved }) => {
  const fileInput = useRef<HTMLInputElement>(null);
  const tpl = templateApi(templateId);
  const [template, setTemplate] = useState<CertificateTemplate | null>(null);
  const [rules, setRules] = useState<SignatureRules>({ max_kb: 2048, extensions: [] });
  const [backgroundMaxKb, setBackgroundMaxKb] = useState(2048);
  const [name, setName] = useState('');
  const [title, setTitle] = useState('');
  const [preview, setPreview] = useState<string | null>(null);
  const [busy, setBusy] = useState<'save' | 'upload' | 'remove' | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);

  const maxMb = Math.round((rules.max_kb / 1024) * 10) / 10;

  useEffect(() => {
    let active = true;
    templateApi(templateId)
      .template()
      .then((result) => {
        if (!active) return;
        setTemplate(result.template);
        setRules(result.rules);
        setBackgroundMaxKb(result.backgroundRules.max_kb);
        setName(result.template.signer_name ?? '');
        setTitle(result.template.signer_title ?? '');
      })
      .catch((err) => active && setError(apiErrorMessage(err, 'No se pudo cargar la plantilla.')));
    return () => {
      active = false;
    };
  }, [templateId]);

  // La firma se pide aparte, con la sesión
  const hasSignature = template?.has_signature ?? false;
  useEffect(() => {
    if (!hasSignature) return;

    let active = true;
    let url: string | null = null;
    templateApi(templateId)
      .signatureUrl()
      .then((result) => {
        url = result;
        if (active) setPreview(result);
      })
      .catch(() => undefined);
    return () => {
      active = false;
      if (url) URL.revokeObjectURL(url);
    };
  }, [hasSignature, template, templateId]);

  const run = async (kind: 'save' | 'upload' | 'remove', action: () => Promise<CertificateTemplate>, fallback: string) => {
    setBusy(kind);
    setError(null);
    setSaved(false);
    try {
      const result = await action();
      setTemplate(result);
      if (!result.has_signature) setPreview(null);
      if (kind === 'save') setSaved(true);
      onSaved();
    } catch (err) {
      setError(apiErrorMessage(err, fallback));
    } finally {
      setBusy(null);
    }
  };

  const save = (event: React.FormEvent) => {
    event.preventDefault();
    void run('save', () => tpl.updateTemplate({ signer_name: name.trim() || null, signer_title: title.trim() || null }), 'No se pudo guardar.');
  };

  const upload = (event: React.ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;

    if (file.size > rules.max_kb * 1024) {
      setError(`La imagen pesa más de ${maxMb} MB.`);
      return;
    }
    void run('upload', () => tpl.uploadSignature(file), 'No se pudo subir la firma.');
  };

  return (
    <>
      {!template && !error && <LoadingState />}
      {error && !template && <Alert>{error}</Alert>}
      {template && (
        <form id="certificate-template-form" onSubmit={save} className="space-y-4">
          {error && <Alert>{error}</Alert>}
          {saved && <Alert tone="success">Plantilla guardada. Se aplica a los certificados que se emitan desde ahora.</Alert>}

          <Field label="Nombre de quien firma">
            <input className={INPUT_CLASS} maxLength={150} placeholder="Ej. María Pérez Salas" value={name} onChange={(event) => setName(event.target.value)} />
          </Field>
          <Field label="Cargo">
            <input className={INPUT_CLASS} maxLength={150} placeholder="Ej. Directora académica" value={title} onChange={(event) => setTitle(event.target.value)} />
          </Field>

          <div>
            <p className="text-[13px] font-medium text-slate-700 mb-1.5">Imagen de la firma (opcional)</p>
            <div className="flex flex-wrap items-center gap-4">
              <div className="w-44 h-20 shrink-0 rounded-xl border border-dashed border-slate-300 bg-slate-50 flex items-center justify-center overflow-hidden">
                {preview ? (
                  <img src={preview} alt="Firma cargada" className="max-w-full max-h-full object-contain" />
                ) : (
                  <span className="text-xs text-slate-500">{template.has_signature ? 'Cargando…' : 'Sin firma'}</span>
                )}
              </div>
              <div className="space-y-2">
                <div className="flex flex-wrap gap-2">
                  <Button size="sm" icon={<ImageUp className="w-3.5 h-3.5" />} processing={busy === 'upload'} onClick={() => fileInput.current?.click()}>
                    {template.has_signature ? 'Cambiar firma' : 'Subir firma'}
                  </Button>
                  {template.has_signature && (
                    <Button
                      size="sm"
                      variant="danger"
                      icon={<Trash2 className="w-3.5 h-3.5" />}
                      processing={busy === 'remove'}
                      onClick={() => void run('remove', tpl.removeSignature, 'No se pudo quitar la firma.')}
                    >
                      Quitar
                    </Button>
                  )}
                </div>
                <p className="text-xs text-slate-500">PNG o JPG · máximo {maxMb} MB. Mejor con fondo blanco o transparente. Se guarda al elegirla.</p>
              </div>
            </div>
            <input ref={fileInput} type="file" accept=".png,.jpg,.jpeg" className="hidden" onChange={upload} />
          </div>
          <div>
            <Button variant="primary" type="submit" processing={busy === 'save'}>
              Guardar firmante
            </Button>
          </div>
        </form>
      )}
      {template && (
        <div className="mt-5">
          <CertificateDesignSection template={template} maxKb={backgroundMaxKb} onChange={setTemplate} />
        </div>
      )}
    </>
  );
};

type TemplateList = { templates: CertificateTemplate[]; courses: { id: string; title: string }[]; max: number };

// Plantillas de la academia: una principal y, si hace falta, otras para cursos concretos
export const TemplateModal: React.FC<{ onClose: () => void }> = ({ onClose }) => {
  const [list, setList] = useState<TemplateList | null>(null);
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [mode, setMode] = useState<'create' | 'rename' | null>(null);
  const [name, setName] = useState('');
  const [copy, setCopy] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setList(await certificatesApi.templates());
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudieron cargar las plantillas.'));
    }
  }, []);

  useEffect(() => {
    const timer = setTimeout(load, 0);
    return () => clearTimeout(timer);
  }, [load]);

  // Sin elegir ninguna se trabaja sobre la principal
  const selected = list?.templates.find((template) => template.id === selectedId) ?? list?.templates.find((template) => template.is_default) ?? list?.templates[0] ?? null;

  const act = async (action: () => Promise<void>, fallback: string) => {
    setBusy(true);
    setError(null);
    try {
      await action();
      await load();
      setMode(null);
    } catch (err) {
      setError(apiErrorMessage(err, fallback));
    } finally {
      setBusy(false);
    }
  };

  const submitName = (event: React.FormEvent) => {
    event.preventDefault();
    event.stopPropagation();
    if (mode === 'create') {
      void act(async () => {
        const created = await certificatesApi.createTemplate(name.trim(), copy ? (selected?.id ?? null) : null);
        setSelectedId(created.id);
      }, 'No se pudo crear la plantilla.');
    } else if (selected?.id) {
      const id = selected.id;
      void act(async () => void (await certificatesApi.updateTemplateInfo(id, { name: name.trim() })), 'No se pudo cambiar el nombre.');
    }
  };

  const toggleCourse = (courseId: string) => {
    if (!selected?.id) return;
    const id = selected.id;
    const next = selected.course_ids.includes(courseId) ? selected.course_ids.filter((course) => course !== courseId) : [...selected.course_ids, courseId];
    void act(async () => void (await certificatesApi.updateTemplateInfo(id, { course_ids: next })), 'No se pudo asignar el curso.');
  };

  // Qué otra plantilla usa hoy cada curso, para avisar antes de quitárselo
  const ownerOf = (courseId: string) => list?.templates.find((template) => template.id !== selected?.id && template.course_ids.includes(courseId))?.name;

  return (
    <Modal
      title="Plantillas de certificado"
      subtitle="Quién firma y cómo se ve el certificado. Con una plantilla alcanza; crea otras si algunos cursos necesitan un diseño distinto."
      size="wide"
      onClose={onClose}
      footer={<Button onClick={onClose}>Cerrar</Button>}
    >
      {!list && !error && <LoadingState />}
      {error && <Alert>{error}</Alert>}
      {list && selected && (
        <div className="space-y-5">
          <div className="rounded-xl border border-slate-200 bg-slate-50/70 p-4 space-y-3">
            <div className="flex flex-wrap items-end gap-2.5">
              <div className="flex-1 min-w-[220px]">
                <Field label="Plantilla">
                  <select className={INPUT_CLASS} value={selected.id ?? ''} onChange={(event) => { setSelectedId(event.target.value || null); setMode(null); }}>
                    {list.templates.map((template) => (
                      <option key={template.id ?? 'main'} value={template.id ?? ''}>
                        {template.name}
                        {template.is_default ? ' (principal)' : ''}
                      </option>
                    ))}
                  </select>
                </Field>
              </div>
              <Button
                size="sm"
                icon={<CopyPlus className="w-3.5 h-3.5" />}
                disabled={list.templates.length >= list.max}
                onClick={() => { setName(''); setCopy(true); setMode('create'); }}
              >
                Nueva plantilla
              </Button>
              {selected.id && (
                <Button size="sm" icon={<Pencil className="w-3.5 h-3.5" />} onClick={() => { setName(selected.name); setMode('rename'); }}>
                  Renombrar
                </Button>
              )}
              {selected.id && !selected.is_default && (
                <>
                  <Button
                    size="sm"
                    icon={<Star className="w-3.5 h-3.5" />}
                    processing={busy}
                    onClick={() => { const id = selected.id!; void act(async () => void (await certificatesApi.makeDefaultTemplate(id)), 'No se pudo marcar como principal.'); }}
                  >
                    Hacer principal
                  </Button>
                  <Button
                    size="sm"
                    variant="danger"
                    icon={<Trash2 className="w-3.5 h-3.5" />}
                    processing={busy}
                    onClick={() => { const id = selected.id!; void act(async () => { await certificatesApi.deleteTemplate(id); setSelectedId(null); }, 'No se pudo eliminar la plantilla.'); }}
                  >
                    Eliminar
                  </Button>
                </>
              )}
            </div>

            {mode && (
              <form onSubmit={submitName} className="flex flex-wrap items-end gap-2.5">
                <div className="flex-1 min-w-[220px]">
                  <Field label={mode === 'create' ? 'Nombre de la plantilla nueva' : 'Nombre de la plantilla'}>
                    <input className={INPUT_CLASS} required maxLength={100} autoFocus placeholder="Ej. Diplomados" value={name} onChange={(event) => setName(event.target.value)} />
                  </Field>
                </div>
                {mode === 'create' && (
                  <label className="flex items-center gap-2 text-[13px] text-slate-700 cursor-pointer pb-2.5">
                    <input type="checkbox" className={CHECKBOX_CLASS} checked={copy} onChange={(event) => setCopy(event.target.checked)} />
                    Copiar «{selected.name}»
                  </label>
                )}
                <Button size="sm" variant="primary" type="submit" processing={busy}>
                  {mode === 'create' ? 'Crear' : 'Guardar nombre'}
                </Button>
                <Button size="sm" variant="ghost" onClick={() => setMode(null)}>
                  Cancelar
                </Button>
              </form>
            )}

            {selected.is_default ? (
              <p className="text-xs text-slate-500">
                <Badge tone="success">Principal</Badge> La usan todos los cursos que no eligieron otra plantilla. Los certificados ya emitidos conservan el diseño con el que salieron.
              </p>
            ) : (
              <fieldset>
                <legend className="block text-[13px] font-medium text-slate-700 mb-1.5">Cursos que usan esta plantilla</legend>
                {list.courses.length === 0 ? (
                  <p className="text-xs text-slate-500">Ningún curso entrega certificado todavía.</p>
                ) : (
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1.5">
                    {list.courses.map((course) => (
                      <label key={course.id} className="flex items-start gap-2 text-[13px] text-slate-700 cursor-pointer">
                        <input type="checkbox" className={`${CHECKBOX_CLASS} mt-0.5`} disabled={busy} checked={selected.course_ids.includes(course.id)} onChange={() => toggleCourse(course.id)} />
                        <span>
                          {course.title}
                          {ownerOf(course.id) && <span className="block text-xs text-slate-500">Hoy usa «{ownerOf(course.id)}»</span>}
                        </span>
                      </label>
                    ))}
                  </div>
                )}
                <p className="mt-1.5 text-xs text-slate-500">Los demás cursos usan la plantilla principal. Se guarda al marcar.</p>
              </fieldset>
            )}
          </div>

          <TemplateForm key={selected.id ?? 'main'} templateId={selected.id} onSaved={load} />
        </div>
      )}
    </Modal>
  );
};
