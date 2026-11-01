import React, { useState } from 'react';
import { Archive, Download } from 'lucide-react';
import { api, apiErrorMessage } from '../../../shared/api/client';
import { Alert, Button, Card, IconChip } from '../../../shared/ui';

/**
 * Configuración → Mis datos: el Administrador descarga en un ZIP todos los
 * datos de su academia (en Excel) y sus archivos, como copia propia.
 */
export const DataExportCard: React.FC = () => {
  const [downloading, setDownloading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const download = async () => {
    setDownloading(true);
    setError(null);
    try {
      // Una academia grande tarda: sin límite de espera
      const response = await api.get('/settings/data-export', { responseType: 'blob', timeout: 0 });
      const disposition = String(response.headers['content-disposition'] ?? '');
      const url = URL.createObjectURL(response.data as Blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = /filename="?([^";]+)"?/.exec(disposition)?.[1] ?? 'datos-de-la-academia.zip';
      link.click();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo preparar la descarga. Inténtalo de nuevo en unos minutos.'));
    } finally {
      setDownloading(false);
    }
  };

  return (
    <Card className="p-5 sm:p-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div className="flex items-start gap-3.5 max-w-2xl">
          <IconChip icon={Archive} tone="info" />
          <div>
            <h2 className="text-sm font-semibold text-slate-900">Mis datos</h2>
            <p className="text-[13px] text-slate-500">
              Descarga una copia de tu academia: personas, cursos, matrículas, órdenes, pagos y certificados en Excel, y todos tus archivos (logo, portadas, materiales, entregas). No incluye
              contraseñas. Guárdala en un lugar seguro: tiene datos personales de tus alumnos.
            </p>
          </div>
        </div>
        <Button icon={<Download className="w-4 h-4" />} processing={downloading} onClick={() => void download()}>
          {downloading ? 'Preparando…' : 'Descargar los datos de mi academia'}
        </Button>
      </div>
      {error && (
        <div className="mt-4">
          <Alert>{error}</Alert>
        </div>
      )}
    </Card>
  );
};
