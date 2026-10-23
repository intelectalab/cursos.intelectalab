import React, { useState } from 'react';
import { FileArchive, Upload } from 'lucide-react';
import { apiErrorMessage } from '../../../shared/api/client';
import { Alert, Button, Modal } from '../../../shared/ui';
import { formatSize } from '../../courses/api';
import { academicApi, type Activity } from '../api';
import { ScormPreviewButton } from './ScormPlayer';

// Igual que el servidor: el servidor web acepta hasta 64 MB por petición
const MAX_BYTES = 60 * 1024 * 1024;

/**
 * Sube (o reemplaza) el paquete SCORM de una actividad y permite probarlo.
 * El paquete nuevo lo ven los alumnos cuando se publica el curso.
 */
export const ScormPackageModal: React.FC<{
  courseId: string;
  activity: Activity;
  onClose: () => void;
  onUploaded: () => Promise<void>;
}> = ({ courseId, activity, onClose, onUploaded }) => {
  const [current, setCurrent] = useState(activity.package ?? null);
  const [file, setFile] = useState<File | null>(null);
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const upload = async () => {
    if (!file) return;
    if (file.size > MAX_BYTES) {
      setError('El paquete pesa más de 60 MB.');
      return;
    }
    setProcessing(true);
    setError(null);
    setNotice(null);
    try {
      const updated = await academicApi.uploadScorm(courseId, activity.id, file);
      setCurrent(updated.package ?? null);
      setFile(null);
      setNotice('Paquete listo. Pruébalo y publica el curso para que lo vean los alumnos.');
      await onUploaded();
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo subir el paquete.'));
    } finally {
      setProcessing(false);
    }
  };

  return (
    <Modal
      title="Paquete SCORM"
      subtitle={activity.title}
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cerrar</Button>
          <Button variant="primary" icon={<Upload className="w-4 h-4" />} disabled={!file} processing={processing} onClick={upload}>
            {current ? 'Reemplazar paquete' : 'Subir paquete'}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        {error && <Alert>{error}</Alert>}
        {notice && <Alert tone="success">{notice}</Alert>}

        {current ? (
          <div className="flex items-start gap-3 rounded-xl border border-slate-200 p-3.5">
            <FileArchive className="w-5 h-5 text-amber-600 shrink-0 mt-0.5" />
            <div className="min-w-0 flex-1 text-[13px]">
              <p className="font-semibold text-slate-900 truncate">{current.title ?? current.file_name}</p>
              <p className="text-xs text-slate-500">
                SCORM {current.version} · {current.file_name} · {formatSize(current.size_bytes)} · {current.files_count} archivos
                {current.mastery_score !== null && ` · aprueba con ${current.mastery_score} %`}
              </p>
              <div className="mt-2">
                <ScormPreviewButton courseId={courseId} activityId={activity.id} title={activity.title} />
              </div>
            </div>
          </div>
        ) : (
          <p className="text-[13px] text-slate-600">Todavía no hay paquete. Sin él no se puede publicar el curso.</p>
        )}

        <label className="block rounded-xl border-2 border-dashed border-slate-300 hover:border-brand-400 p-5 text-center cursor-pointer">
          <input type="file" accept=".zip,application/zip" className="sr-only" onChange={(event) => setFile(event.target.files?.[0] ?? null)} />
          <Upload className="w-5 h-5 mx-auto text-slate-400" />
          <span className="block mt-1.5 text-[13px] font-semibold text-slate-800">{file ? file.name : 'Elegir el archivo .zip'}</span>
          <span className="block text-xs text-slate-500">
            {file ? formatSize(file.size) : 'Exportado como SCORM 1.2 o 2004 desde tu herramienta (Articulate, iSpring, Captivate…). Hasta 60 MB.'}
          </span>
        </label>

        <ul className="text-xs text-slate-500 space-y-1 list-disc pl-4">
          <li>El sistema revisa que traiga su imsmanifest.xml y la página de inicio.</li>
          <li>Reemplazar el paquete no cambia lo que ven los alumnos hasta que publiques el curso.</li>
          <li>Cada alumno tiene un solo intento, que retoma donde quedó. Desde Seguimiento puedes reiniciarlo.</li>
        </ul>
      </div>
    </Modal>
  );
};
