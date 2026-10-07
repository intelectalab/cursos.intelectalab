import React, { useRef, useState } from 'react';
import { CircleAlert, CircleCheckBig, CircleMinus, Download, FileSpreadsheet, Upload } from 'lucide-react';
import { api, apiErrorMessage } from '../api/client';
import { Alert, Badge, Button, Modal } from './index';

type RowStatus = 'OK' | 'SKIP' | 'ERROR';

interface ImportRow {
  line: number;
  status: RowStatus;
  label: string;
  messages: string[];
}

interface ImportResult {
  rows: ImportRow[];
  totals: { total: number; ok: number; skipped: number; errors: number };
}

const STATUS: Record<RowStatus, { label: string; tone: 'success' | 'neutral' | 'danger'; icon: typeof CircleAlert }> = {
  OK: { label: 'Se importa', tone: 'success', icon: CircleCheckBig },
  SKIP: { label: 'Sin cambios', tone: 'neutral', icon: CircleMinus },
  ERROR: { label: 'Con error', tone: 'danger', icon: CircleAlert },
};

// Importar desde una hoja de cálculo en tres pasos: bajar la plantilla, subir
// el archivo para revisarlo y confirmar. Las filas con error no se guardan.
export const ImportModal: React.FC<{
  title: string;
  // Qué se importa y qué pasa con cada fila, en una o dos frases
  intro: string;
  // Dirección base de la importación: {base}/template, {base}/preview y {base}
  basePath: string;
  templateName: string;
  onClose: () => void;
  // Se llama cuando algo se guardó, para refrescar la pantalla de origen
  onImported: () => void | Promise<void>;
}> = ({ title, intro, basePath, templateName, onClose, onImported }) => {
  const input = useRef<HTMLInputElement>(null);
  const [file, setFile] = useState<File | null>(null);
  const [preview, setPreview] = useState<ImportResult | null>(null);
  const [done, setDone] = useState<ImportResult | null>(null);
  const [busy, setBusy] = useState<'template' | 'preview' | 'import' | null>(null);
  const [error, setError] = useState<string | null>(null);

  const downloadTemplate = async () => {
    setBusy('template');
    setError(null);
    try {
      const response = await api.get(`${basePath}/template`, { responseType: 'blob' });
      const url = URL.createObjectURL(response.data as Blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = templateName;
      link.click();
      URL.revokeObjectURL(url);
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo descargar la plantilla.'));
    } finally {
      setBusy(null);
    }
  };

  const send = async (path: string, selected: File): Promise<ImportResult> => {
    const body = new FormData();
    body.append('file', selected);
    const response = await api.post(path, body);
    return { rows: response.data.data, totals: response.data.meta };
  };

  const review = async (selected: File) => {
    setFile(selected);
    setPreview(null);
    setDone(null);
    setBusy('preview');
    setError(null);
    try {
      setPreview(await send(`${basePath}/preview`, selected));
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo leer el archivo.'));
      setFile(null);
    } finally {
      setBusy(null);
      if (input.current) input.current.value = '';
    }
  };

  const confirm = async () => {
    if (!file) return;
    setBusy('import');
    setError(null);
    try {
      const result = await send(basePath, file);
      setDone(result);
      setPreview(null);
      if (result.totals.ok > 0) await onImported();
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo importar el archivo.'));
    } finally {
      setBusy(null);
    }
  };

  const shown = done ?? preview;

  return (
    <Modal
      title={title}
      subtitle={intro}
      size="wide"
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>{done ? 'Cerrar' : 'Cancelar'}</Button>
          {preview && (
            <Button variant="primary" processing={busy === 'import'} disabled={preview.totals.ok === 0} onClick={confirm}>
              {preview.totals.ok === 0 ? 'Nada que importar' : `Importar ${preview.totals.ok} ${preview.totals.ok === 1 ? 'fila' : 'filas'}`}
            </Button>
          )}
        </>
      }
    >
      <div className="space-y-4">
        {error && <Alert>{error}</Alert>}

        {!done && (
          <ol className="grid grid-cols-1 md:grid-cols-2 gap-3">
            <li className="flex items-start gap-3 p-4 border border-slate-200 rounded-xl">
              <span className="w-7 h-7 shrink-0 rounded-full bg-brand-50 text-brand-700 text-[13px] font-bold inline-flex items-center justify-center">1</span>
              <div className="min-w-0">
                <p className="text-[13px] font-semibold text-slate-900">Descarga la plantilla</p>
                <p className="text-xs text-slate-600 mb-2.5">Un Excel con las columnas correctas, un ejemplo y una hoja de instrucciones.</p>
                <Button size="sm" icon={<Download className="w-3.5 h-3.5" />} processing={busy === 'template'} onClick={downloadTemplate}>
                  Descargar plantilla
                </Button>
              </div>
            </li>
            <li className="flex items-start gap-3 p-4 border border-slate-200 rounded-xl">
              <span className="w-7 h-7 shrink-0 rounded-full bg-brand-50 text-brand-700 text-[13px] font-bold inline-flex items-center justify-center">2</span>
              <div className="min-w-0">
                <p className="text-[13px] font-semibold text-slate-900">Sube el archivo lleno</p>
                <p className="text-xs text-slate-600 mb-2.5">Excel (.xlsx) o CSV, hasta 500 filas. Primero se revisa; nada se guarda hasta que confirmes.</p>
                <input
                  ref={input}
                  type="file"
                  accept=".xlsx,.csv"
                  className="sr-only"
                  aria-label="Archivo a importar"
                  onChange={(event) => event.target.files?.[0] && review(event.target.files[0])}
                />
                <Button size="sm" variant="primary" icon={<Upload className="w-3.5 h-3.5" />} processing={busy === 'preview'} onClick={() => input.current?.click()}>
                  {file ? 'Elegir otro archivo' : 'Elegir archivo'}
                </Button>
                {file && (
                  <p className="mt-2 flex items-center gap-1.5 text-xs text-slate-600 truncate">
                    <FileSpreadsheet className="w-3.5 h-3.5 shrink-0" /> {file.name}
                  </p>
                )}
              </div>
            </li>
          </ol>
        )}

        {done && (
          <Alert tone={done.totals.ok > 0 ? 'success' : 'info'}>
            {done.totals.ok > 0
              ? `Se ${done.totals.ok === 1 ? 'importó 1 fila' : `importaron ${done.totals.ok} filas`}.`
              : 'No se importó ninguna fila.'}
            {done.totals.skipped > 0 && ` ${done.totals.skipped === 1 ? '1 ya estaba' : `${done.totals.skipped} ya estaban`} al día.`}
            {done.totals.errors > 0 &&
              ` ${done.totals.errors === 1 ? '1 quedó fuera por errores: corrígela' : `${done.totals.errors} quedaron fuera por errores: corrígelas`} en el archivo y vuelve a subirlo (las ya importadas no se duplican).`}
          </Alert>
        )}

        {shown && (
          <div>
            <div className="flex flex-wrap items-center gap-2 mb-2">
              <p className="text-[13px] font-semibold text-slate-900 mr-1">{done ? 'Resultado' : '3. Revisa antes de confirmar'}</p>
              <Badge tone="success">{shown.totals.ok} {done ? 'importadas' : 'se importan'}</Badge>
              {shown.totals.skipped > 0 && <Badge tone="neutral">{shown.totals.skipped} sin cambios</Badge>}
              {shown.totals.errors > 0 && <Badge tone="danger">{shown.totals.errors} con error</Badge>}
            </div>
            {shown.rows.length === 0 ? (
              <Alert tone="info">El archivo no tiene filas con datos.</Alert>
            ) : (
              <ul className="rounded-xl border border-slate-200 divide-y divide-slate-100 max-h-72 overflow-y-auto">
                {shown.rows.map((row) => {
                  const status = STATUS[row.status];
                  return (
                    <li key={row.line} className={`flex items-start gap-3 px-3.5 py-2.5 ${row.status === 'ERROR' ? 'bg-rose-50/50' : ''}`}>
                      <span className="w-12 shrink-0 text-xs text-slate-500 tnum pt-0.5">Fila {row.line}</span>
                      <div className="min-w-0 flex-1">
                        <p className="text-[13px] font-semibold text-slate-900 truncate">{row.label}</p>
                        {row.messages.map((message) => (
                          <p key={message} className={`text-xs ${row.status === 'ERROR' ? 'text-rose-700' : 'text-slate-600'}`}>
                            {message}
                          </p>
                        ))}
                      </div>
                      <Badge tone={status.tone}>
                        <status.icon className="w-3 h-3" /> {row.status === 'OK' && done ? 'Importada' : status.label}
                      </Badge>
                    </li>
                  );
                })}
              </ul>
            )}
            {preview && preview.totals.errors > 0 && (
              <p className="mt-2 text-xs text-slate-600">
                Puedes importar ahora las filas correctas y corregir después las que tienen error, o corregir el archivo y subirlo de nuevo.
              </p>
            )}
          </div>
        )}
      </div>
    </Modal>
  );
};
