import React, { useCallback, useEffect, useState } from 'react';
import { DatabaseBackup, Download } from 'lucide-react';
import { api, apiErrorMessage } from '../../../shared/api/client';
import { Alert, Button, Card, CardHeader, EmptyState, ErrorState, LoadingState, Table, TBody, Td, Th, THead, Tr } from '../../../shared/ui';

interface Backup {
  name: string;
  size: number;
  created_at: string;
}

const formatSize = (bytes: number) => (bytes >= 1024 ** 3 ? `${(bytes / 1024 ** 3).toFixed(1)} GB` : `${Math.max(1, Math.round(bytes / 1024 ** 2))} MB`);

/**
 * Consola → Respaldos: la copia de cada semana (base de datos y archivos de
 * las academias) para guardarla en el equipo de quien la descarga.
 */
export const BackupsPanel: React.FC = () => {
  const [backups, setBackups] = useState<Backup[]>([]);
  const [configured, setConfigured] = useState(true);
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [errorMessage, setErrorMessage] = useState('');
  const [downloading, setDownloading] = useState<string | null>(null);
  const [downloadError, setDownloadError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const response = await api.get('/platform/backups');
      setBackups(response.data.data as Backup[]);
      setConfigured(response.data.meta.configured as boolean);
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

  const download = async (backup: Backup) => {
    setDownloading(backup.name);
    setDownloadError(null);
    try {
      const response = await api.get(`/platform/backups/${backup.name}`, { responseType: 'blob' });
      const url = URL.createObjectURL(response.data as Blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = backup.name;
      link.click();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (err) {
      setDownloadError(apiErrorMessage(err, 'No se pudo descargar el respaldo.'));
    } finally {
      setDownloading(null);
    }
  };

  if (state === 'loading') return <LoadingState />;
  if (state === 'error') return <ErrorState message={errorMessage} onRetry={load} />;

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-[22px] font-bold text-slate-900 tracking-tight">Respaldos</h1>
        <p className="text-[13px] text-slate-500 mt-1">
          Cada semana se arma una copia con la base de datos y los archivos de todas las academias. Descárgala y guárdala en un lugar seguro: contiene los datos de todas las academias.
        </p>
      </div>

      <Alert tone="info">
        La copia no incluye la clave de la aplicación (el archivo de configuración del servidor), que se guarda aparte. Sin ella no se pueden leer las claves de las pasarelas de pago guardadas en la base. El servidor además conserva una copia diaria de los últimos 30 días.
      </Alert>
      {downloadError && <Alert>{downloadError}</Alert>}

      <Card>
        <CardHeader title="Copias semanales" text="Se conservan las últimas 8 semanas." />
        {backups.length === 0 ? (
          <EmptyState
            icon={DatabaseBackup}
            title="Todavía no hay copias semanales"
            text={configured ? 'La primera se arma el próximo domingo a las 2:30 a. m.' : 'El servidor todavía no armó su primera copia semanal. Se arma los domingos a las 2:30 a. m.'}
          />
        ) : (
          <Table>
            <THead>
              <Th>Copia</Th>
              <Th>Fecha</Th>
              <Th>Tamaño</Th>
              <Th />
            </THead>
            <TBody>
              {backups.map((backup) => (
                <Tr key={backup.name}>
                  <Td className="font-mono text-[13px]">{backup.name}</Td>
                  <Td className="whitespace-nowrap tnum">{new Date(backup.created_at).toLocaleString('es-PE', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })}</Td>
                  <Td className="tnum">{formatSize(backup.size)}</Td>
                  <Td className="text-right">
                    <Button size="sm" icon={<Download className="w-4 h-4" />} processing={downloading === backup.name} disabled={downloading !== null} onClick={() => download(backup)}>
                      Descargar
                    </Button>
                  </Td>
                </Tr>
              ))}
            </TBody>
          </Table>
        )}
      </Card>
    </div>
  );
};
