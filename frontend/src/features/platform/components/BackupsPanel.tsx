import React, { useCallback, useEffect, useState } from 'react';
import { CheckCircle2, Clock, Database, DatabaseBackup, Download, GraduationCap, Building2, ShieldAlert, XCircle } from 'lucide-react';
import { api, apiErrorMessage } from '../../../shared/api/client';
import { Alert, Badge, Button, Card, CardHeader, EmptyState, ErrorState, LoadingState, StatCard, Table, TBody, Td, Th, THead, Tr } from '../../../shared/ui';

type Kind = 'DAILY' | 'WEEKLY' | 'MANUAL';

interface Backup {
  name: string;
  kind: Kind;
  size: number;
  created_at: string;
}

interface HistoryEntry {
  kind: Kind;
  started_at: string;
  finished_at: string | null;
  ok: boolean | null;
  size: number | null;
  file: string | null;
  error: string | null;
  by: string | null;
}

interface Status {
  last_success_at: string | null;
  healthy: boolean;
  max_hours: number;
  // PENDING = pedido, espera al servidor; RUNNING = en curso
  request: 'PENDING' | 'RUNNING' | null;
  database_bytes: number | null;
  tenants: number;
  students: number;
  keep_manual: number;
}

const KIND_LABEL: Record<Kind, string> = { DAILY: 'Diario', WEEKLY: 'Semanal', MANUAL: 'Pedido desde aquí' };

const formatSize = (bytes: number | null) =>
  bytes === null ? '—' : bytes >= 1024 ** 3 ? `${(bytes / 1024 ** 3).toFixed(1)} GB` : bytes >= 1024 ** 2 ? `${(bytes / 1024 ** 2).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;

const formatDate = (value: string | null) =>
  value ? new Date(value).toLocaleString('es-PE', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—';

/**
 * Consola → Respaldos: el estado de las copias, «Respaldo ahora», el
 * historial (diarios del servidor, semanales y pedidos desde aquí) y la
 * descarga de los semanales y los manuales para guardarlos en el equipo.
 */
export const BackupsPanel: React.FC = () => {
  const [backups, setBackups] = useState<Backup[]>([]);
  const [history, setHistory] = useState<HistoryEntry[]>([]);
  const [status, setStatus] = useState<Status | null>(null);
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [errorMessage, setErrorMessage] = useState('');
  const [downloading, setDownloading] = useState<string | null>(null);
  const [requesting, setRequesting] = useState(false);
  const [notice, setNotice] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);

  const load = useCallback(async () => {
    try {
      const response = await api.get('/platform/backups');
      setBackups(response.data.data as Backup[]);
      setHistory(response.data.meta.history as HistoryEntry[]);
      setStatus(response.data.meta.status as Status);
      setState('ready');
    } catch (err) {
      setErrorMessage(apiErrorMessage(err, 'No se pudieron leer los respaldos.'));
      setState('error');
    }
  }, []);

  useEffect(() => {
    const timer = setTimeout(load, 0);
    return () => clearTimeout(timer);
  }, [load]);

  // Mientras hay un respaldo pedido o en curso, la pantalla se pone al día sola
  const busy = status?.request !== null && status?.request !== undefined;
  useEffect(() => {
    if (!busy) return;
    const timer = setInterval(load, 15_000);
    return () => clearInterval(timer);
  }, [busy, load]);

  const requestBackup = async () => {
    setRequesting(true);
    setNotice(null);
    try {
      await api.post('/platform/backups');
      setNotice({ tone: 'success', text: 'Respaldo pedido. El servidor lo empieza en menos de un minuto; esta pantalla se actualiza sola.' });
      await load();
    } catch (err) {
      setNotice({ tone: 'danger', text: apiErrorMessage(err, 'No se pudo pedir el respaldo.') });
    } finally {
      setRequesting(false);
    }
  };

  const download = async (backup: Backup) => {
    setDownloading(backup.name);
    setNotice(null);
    try {
      const response = await api.get(`/platform/backups/${backup.name}`, { responseType: 'blob' });
      const url = URL.createObjectURL(response.data as Blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = backup.name;
      link.click();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (err) {
      setNotice({ tone: 'danger', text: apiErrorMessage(err, 'No se pudo descargar el respaldo.') });
    } finally {
      setDownloading(null);
    }
  };

  if (state === 'loading') return <LoadingState />;
  if (state === 'error' || !status) return <ErrorState message={errorMessage} onRetry={load} />;

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-[22px] font-bold text-slate-900 tracking-tight">Respaldos</h1>
          <p className="text-[13px] text-slate-500 mt-1 max-w-3xl">
            El servidor copia cada noche la base de datos y los archivos de todas las academias, y una vez por semana deja una copia para descargar. También puedes pedir una ahora.
          </p>
        </div>
        <Button variant="primary" icon={<DatabaseBackup className="w-4 h-4" />} processing={requesting} disabled={busy} onClick={() => void requestBackup()}>
          {status.request === 'RUNNING' ? 'Respaldando…' : status.request === 'PENDING' ? 'Respaldo pedido…' : 'Respaldo ahora'}
        </Button>
      </div>

      {notice && <Alert tone={notice.tone === 'danger' ? undefined : 'success'}>{notice.text}</Alert>}
      {!status.healthy && (
        <Alert>
          <span className="inline-flex items-center gap-1.5 font-semibold">
            <ShieldAlert className="w-4 h-4" /> Revisa los respaldos.
          </span>{' '}
          {status.last_success_at ? `El último respaldo bueno es del ${formatDate(status.last_success_at)}: hace más de ${status.max_hours} horas.` : 'Todavía no hay ningún respaldo bueno registrado.'} Te
          avisamos también por correo cada mañana mientras siga así.
        </Alert>
      )}

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <StatCard label="Último respaldo bueno" value={status.last_success_at ? formatDate(status.last_success_at) : 'Ninguno'} icon={status.healthy ? CheckCircle2 : Clock} tone={status.healthy ? 'success' : 'danger'} />
        <StatCard label="Tamaño de la base" value={formatSize(status.database_bytes)} icon={Database} tone="brand" />
        <StatCard label="Academias" value={status.tenants} icon={Building2} tone="info" />
        <StatCard label="Alumnos" value={status.students} icon={GraduationCap} tone="violet" />
      </div>

      <Card>
        <CardHeader title="Copias para descargar" text={`Las semanales de las últimas 8 semanas y las últimas ${status.keep_manual} pedidas desde aquí. Contienen los datos de todas las academias: guárdalas en un lugar seguro.`} />
        {backups.length === 0 ? (
          <EmptyState icon={DatabaseBackup} title="Todavía no hay copias para descargar" text="La semanal se arma los domingos a las 2:30 a. m. O pide una con «Respaldo ahora»." />
        ) : (
          <Table>
            <THead>
              <Th>Copia</Th>
              <Th>Tipo</Th>
              <Th>Fecha</Th>
              <Th>Tamaño</Th>
              <Th />
            </THead>
            <TBody>
              {backups.map((backup) => (
                <Tr key={backup.name}>
                  <Td className="font-mono text-[13px]">{backup.name}</Td>
                  <Td>
                    <Badge tone={backup.kind === 'MANUAL' ? 'brand' : 'info'}>{KIND_LABEL[backup.kind]}</Badge>
                  </Td>
                  <Td className="whitespace-nowrap tnum">{formatDate(backup.created_at)}</Td>
                  <Td className="tnum">{formatSize(backup.size)}</Td>
                  <Td className="text-right">
                    <Button size="sm" icon={<Download className="w-4 h-4" />} processing={downloading === backup.name} disabled={downloading !== null} onClick={() => void download(backup)}>
                      Descargar
                    </Button>
                  </Td>
                </Tr>
              ))}
            </TBody>
          </Table>
        )}
      </Card>

      <Card>
        <CardHeader title="Historial" text="Cada respaldo, bueno o fallido: los diarios que hace el servidor (se guardan 30 días en el servidor), los semanales y los pedidos desde aquí." />
        {history.length === 0 ? (
          <EmptyState icon={Clock} title="Sin respaldos registrados todavía" text="El primero aparece después de la copia de esta noche (2:30 a. m.)." />
        ) : (
          <Table>
            <THead>
              <Th>Resultado</Th>
              <Th>Tipo</Th>
              <Th>Empezó</Th>
              <Th>Tamaño</Th>
              <Th>Detalle</Th>
            </THead>
            <TBody>
              {history.map((entry, index) => (
                <Tr key={`${entry.started_at}-${index}`}>
                  <Td>
                    {entry.ok === false ? (
                      <Badge tone="danger">
                        <XCircle className="w-3.5 h-3.5" /> Falló
                      </Badge>
                    ) : (
                      <Badge tone="success">
                        <CheckCircle2 className="w-3.5 h-3.5" /> Bien
                      </Badge>
                    )}
                  </Td>
                  <Td>{KIND_LABEL[entry.kind] ?? entry.kind}</Td>
                  <Td className="whitespace-nowrap tnum">{formatDate(entry.started_at)}</Td>
                  <Td className="tnum">{formatSize(entry.size)}</Td>
                  <Td className="text-xs text-slate-500 max-w-md">{entry.error ?? (entry.by ? `Lo pidió ${entry.by}` : (entry.file ?? ''))}</Td>
                </Tr>
              ))}
            </TBody>
          </Table>
        )}
      </Card>

      <Alert tone="info">
        Las copias no incluyen la clave de la aplicación (el archivo de configuración del servidor), que se guarda aparte: sin ella no se leen las claves de las pasarelas de pago. Restaurar
        una copia se hace en el servidor con el procedimiento de la guía de despliegue, porque devuelve al pasado a todas las academias a la vez.
      </Alert>
    </div>
  );
};
