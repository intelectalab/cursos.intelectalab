import React, { useState } from 'react';
import { Download } from 'lucide-react';
import { api } from '../api/client';
import { Button } from './index';

interface ExportButtonProps {
  // Ruta de la API que entrega el Excel
  path: string;
  // Los mismos filtros de la pantalla: el archivo trae lo que se está viendo
  params?: Record<string, string | undefined>;
  filename: string;
  label?: string;
}

// Descarga en Excel el listado de la pantalla, con sus filtros
export const ExportButton: React.FC<ExportButtonProps> = ({ path, params, filename, label = 'Exportar a Excel' }) => {
  const [exporting, setExporting] = useState(false);
  const [failed, setFailed] = useState(false);

  const download = async () => {
    setExporting(true);
    setFailed(false);
    try {
      const response = await api.get(path, { params, responseType: 'blob' });
      const url = URL.createObjectURL(response.data as Blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = filename;
      link.click();
      URL.revokeObjectURL(url);
    } catch {
      setFailed(true);
    } finally {
      setExporting(false);
    }
  };

  return (
    <div className="flex items-center gap-2">
      {failed && (
        <span role="alert" className="text-xs font-medium text-rose-700">
          No se pudo descargar. Inténtalo de nuevo.
        </span>
      )}
      <Button icon={<Download className="w-4 h-4" />} processing={exporting} onClick={download}>
        {label}
      </Button>
    </div>
  );
};
