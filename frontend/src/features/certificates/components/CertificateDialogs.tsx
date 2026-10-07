import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Award, ImageUp, Search, Trash2 } from 'lucide-react';
import { apiErrorMessage } from '../../../shared/api/client';
import { Alert, Button, EmptyState, Field, INPUT_CLASS, LoadingState, Modal } from '../../../shared/ui';
import { SelectAll, SelectRow } from '../../../shared/ui/filters';
import {
  certificatesApi,
  formatDate,
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
export const TemplateModal: React.FC<{ onClose: () => void }> = ({ onClose }) => {
  const fileInput = useRef<HTMLInputElement>(null);
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
    certificatesApi
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
  }, []);

  // La firma se pide aparte, con la sesión
  const hasSignature = template?.has_signature ?? false;
  useEffect(() => {
    if (!hasSignature) return;

    let active = true;
    let url: string | null = null;
    certificatesApi
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
  }, [hasSignature, template]);

  const run = async (kind: 'save' | 'upload' | 'remove', action: () => Promise<CertificateTemplate>, fallback: string) => {
    setBusy(kind);
    setError(null);
    setSaved(false);
    try {
      const result = await action();
      setTemplate(result);
      if (!result.has_signature) setPreview(null);
      if (kind === 'save') setSaved(true);
    } catch (err) {
      setError(apiErrorMessage(err, fallback));
    } finally {
      setBusy(null);
    }
  };

  const save = (event: React.FormEvent) => {
    event.preventDefault();
    void run('save', () => certificatesApi.updateTemplate({ signer_name: name.trim() || null, signer_title: title.trim() || null }), 'No se pudo guardar.');
  };

  const upload = (event: React.ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;

    if (file.size > rules.max_kb * 1024) {
      setError(`La imagen pesa más de ${maxMb} MB.`);
      return;
    }
    void run('upload', () => certificatesApi.uploadSignature(file), 'No se pudo subir la firma.');
  };

  return (
    <Modal
      title="Plantilla del certificado"
      subtitle="Quién firma el certificado y cómo se ve: con el diseño del sistema o con tu propio fondo."
      size="wide"
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cerrar</Button>
          <Button variant="primary" type="submit" form="certificate-template-form" processing={busy === 'save'} disabled={!template}>
            Guardar firmante
          </Button>
        </>
      }
    >
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
                      onClick={() => void run('remove', certificatesApi.removeSignature, 'No se pudo quitar la firma.')}
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
        </form>
      )}
      {template && (
        <div className="mt-5">
          <CertificateDesignSection template={template} maxKb={backgroundMaxKb} onChange={setTemplate} />
        </div>
      )}
    </Modal>
  );
};
