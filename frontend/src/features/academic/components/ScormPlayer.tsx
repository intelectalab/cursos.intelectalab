import React, { useCallback, useEffect, useRef, useState } from 'react';
import { CheckCircle2, Maximize2, Minimize2, PackageOpen, Play, X } from 'lucide-react';
import { apiErrorMessage } from '../../../shared/api/client';
import { Alert, Badge, Button } from '../../../shared/ui';
import { academicApi, SCORM_STATUS_LABEL, toScale, type ScormLaunchData, type ScormState } from '../api';

// Cada cuánto se guarda solo lo que el paquete fue cambiando, si no lo guardó él
const AUTOSAVE_MS = 30_000;

type ScormWindow = Window & { API?: Record<string, (...args: string[]) => string>; API_1484_11?: Record<string, (...args: string[]) => string> };

/**
 * El paquete SCORM en un marco, con el «puente» que el estándar le pide al
 * aula: window.API (1.2) y window.API_1484_11 (2004). Lo que el paquete
 * escribe se guarda al confirmar (Commit), al cerrar y cada 30 segundos.
 */
export const ScormPlayer: React.FC<{
  title: string;
  data: ScormLaunchData;
  // Guarda lo que cambió; en la vista previa no se pasa
  onCommit?: (values: Record<string, string>) => Promise<ScormState | void>;
  onClose: () => void;
}> = ({ title, data, onCommit, onClose }) => {
  const values = useRef<Record<string, string>>({ ...data.cmi });
  const dirty = useRef<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [wide, setWide] = useState(false);

  const flush = useCallback(async () => {
    const changes = dirty.current;
    if (!onCommit || Object.keys(changes).length === 0) return;
    dirty.current = {};
    setSaving(true);
    try {
      await onCommit(changes);
      setError(null);
    } catch (err) {
      // Lo no guardado vuelve a la cola para el próximo intento
      dirty.current = { ...changes, ...dirty.current };
      setError(apiErrorMessage(err, 'No se pudo guardar tu avance. Se volverá a intentar.'));
    } finally {
      setSaving(false);
    }
  }, [onCommit]);

  useEffect(() => {
    const host = window as ScormWindow;
    let lastError = '0';
    const get = (key: string) => {
      lastError = '0';
      if (key.endsWith('._count')) {
        const prefix = key.slice(0, -'_count'.length);
        const indexes = new Set(Object.keys(values.current).filter((name) => name.startsWith(prefix)).map((name) => name.slice(prefix.length).split('.')[0]));
        return String(indexes.size);
      }
      return values.current[key] ?? '';
    };
    const set = (key: string, value: string) => {
      lastError = '0';
      values.current[key] = String(value);
      dirty.current[key] = String(value);
      return 'true';
    };
    const commit = () => {
      void flush();
      return 'true';
    };
    const ok = () => 'true';
    const common = { GetLastError: () => lastError, GetErrorString: () => '', GetDiagnostic: () => '' };

    host.API = {
      LMSInitialize: ok,
      LMSFinish: commit,
      LMSGetValue: get,
      LMSSetValue: set,
      LMSCommit: commit,
      LMSGetLastError: common.GetLastError,
      LMSGetErrorString: common.GetErrorString,
      LMSGetDiagnostic: common.GetDiagnostic,
    };
    host.API_1484_11 = { Initialize: ok, Terminate: commit, GetValue: get, SetValue: set, Commit: commit, ...common };

    const timer = window.setInterval(() => void flush(), AUTOSAVE_MS);
    return () => {
      window.clearInterval(timer);
      void flush();
      delete host.API;
      delete host.API_1484_11;
    };
  }, [flush]);

  return (
    <div className="fixed inset-0 z-50 bg-slate-900/70 flex items-center justify-center p-2 sm:p-4" role="dialog" aria-modal="true" aria-label={title}>
      <div className={`bg-white rounded-2xl shadow-2xl flex flex-col overflow-hidden w-full ${wide ? 'h-full' : 'max-w-5xl h-[85vh]'}`}>
        <div className="flex items-center gap-3 px-4 py-2.5 border-b border-slate-200">
          <PackageOpen className="w-4 h-4 text-amber-600 shrink-0" />
          <p className="text-[13px] font-semibold text-slate-900 truncate flex-1">{title}</p>
          {!data.tracking && <Badge tone="info">Vista previa: no se guarda</Badge>}
          {data.tracking && <span className="text-xs text-slate-500">{saving ? 'Guardando…' : 'Tu avance se guarda solo'}</span>}
          <button type="button" className="p-1.5 rounded-lg text-slate-500 hover:bg-slate-100 cursor-pointer" aria-label={wide ? 'Achicar' : 'Pantalla completa'} onClick={() => setWide(!wide)}>
            {wide ? <Minimize2 className="w-4 h-4" /> : <Maximize2 className="w-4 h-4" />}
          </button>
          <Button size="sm" icon={<X className="w-4 h-4" />} onClick={onClose}>
            Cerrar
          </Button>
        </div>
        {error && (
          <div className="px-4 pt-2">
            <Alert>{error}</Alert>
          </div>
        )}
        <iframe title={title} src={data.launch_url} className="flex-1 w-full border-0 bg-white" allow="fullscreen; autoplay" />
      </div>
    </div>
  );
};

/** En el aula: el estado del alumno en el paquete y el botón para abrirlo. */
export const ScormPanel: React.FC<{
  courseId: string;
  activityId: string;
  title: string;
  state: ScormState;
  gradeScale: number;
  onChanged: () => Promise<void>;
}> = ({ courseId, activityId, title, state, gradeScale, onChanged }) => {
  const [data, setData] = useState<ScormLaunchData | null>(null);
  const [opening, setOpening] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const open = async () => {
    setOpening(true);
    setError(null);
    try {
      setData(await academicApi.scormLaunch(courseId, activityId));
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo abrir el contenido.'));
    } finally {
      setOpening(false);
    }
  };

  const started = state.lesson_status !== 'not attempted';

  return (
    <div className="space-y-3">
      {error && <Alert>{error}</Alert>}
      <div className="flex flex-wrap items-center gap-2">
        <Badge tone={state.completed ? 'success' : started ? 'warning' : 'neutral'} dot>
          {SCORM_STATUS_LABEL[state.lesson_status] ?? state.lesson_status}
        </Badge>
        {state.score_percent !== null && <Badge tone="brand">Nota: {toScale(state.score_percent, gradeScale)} de {gradeScale}</Badge>}
        {state.completed && (
          <span className="inline-flex items-center gap-1 text-xs font-medium text-emerald-700">
            <CheckCircle2 className="w-4 h-4" /> Cuenta para tu avance
          </span>
        )}
      </div>
      <Button variant="primary" icon={<Play className="w-4 h-4" />} processing={opening} onClick={open}>
        {started ? 'Continuar donde quedé' : 'Empezar'}
      </Button>
      {data && (
        <ScormPlayer
          title={title}
          data={data}
          onCommit={(values) => academicApi.scormCommit(courseId, activityId, values)}
          onClose={() => {
            setData(null);
            void onChanged();
          }}
        />
      )}
    </div>
  );
};

/** Vista previa del personal: abre el paquete del borrador sin guardar nada. */
export const ScormPreviewButton: React.FC<{ courseId: string; activityId: string; title: string; label?: string }> = ({ courseId, activityId, title, label = 'Probar el paquete' }) => {
  const [data, setData] = useState<ScormLaunchData | null>(null);
  const [error, setError] = useState<string | null>(null);

  return (
    <>
      <Button
        size="sm"
        icon={<Play className="w-4 h-4" />}
        onClick={async () => {
          setError(null);
          try {
            setData(await academicApi.scormPreview(courseId, activityId));
          } catch (err) {
            setError(apiErrorMessage(err, 'No se pudo abrir el paquete.'));
          }
        }}
      >
        {label}
      </Button>
      {error && <p className="text-xs text-rose-700 mt-1">{error}</p>}
      {data && <ScormPlayer title={title} data={data} onClose={() => setData(null)} />}
    </>
  );
};
