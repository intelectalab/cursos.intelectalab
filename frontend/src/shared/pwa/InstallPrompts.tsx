import React, { useState } from 'react';
import { RefreshCw, Share, Smartphone, SquarePlus, X } from 'lucide-react';
import { Button, Modal } from '../ui';
import { useMediaQuery } from '../ui/useMediaQuery';
import { applyUpdate, promptInstall, useInstallableApp } from './installableApp';

const BANNER_KEY = 'app-install-banner-dismissed';

// localStorage puede no existir (ventana privada, sitio bloqueado): entonces el aviso solo vuelve a salir
const dismissed = (): boolean => {
  try {
    return window.localStorage.getItem(BANNER_KEY) === '1';
  } catch {
    return false;
  }
};

const IosGuide: React.FC<{ name: string; onClose: () => void }> = ({ name, onClose }) => (
  <Modal
    title="Instalar en el iPhone"
    subtitle={`${name} queda en tu pantalla de inicio, como una app.`}
    onClose={onClose}
    footer={
      <Button variant="primary" onClick={onClose}>
        Entendido
      </Button>
    }
  >
    <ol className="space-y-3 text-[13px] text-slate-700">
      <li className="flex items-center gap-3">
        <span className="w-7 h-7 shrink-0 rounded-full bg-brand-50 text-brand-700 font-semibold flex items-center justify-center">1</span>
        <span className="flex items-center gap-1.5">
          Abre esta página en Safari y toca <Share className="w-4 h-4 text-brand-700" aria-label="Compartir" /> <strong>Compartir</strong>.
        </span>
      </li>
      <li className="flex items-center gap-3">
        <span className="w-7 h-7 shrink-0 rounded-full bg-brand-50 text-brand-700 font-semibold flex items-center justify-center">2</span>
        <span className="flex items-center gap-1.5">
          Elige <SquarePlus className="w-4 h-4 text-brand-700" aria-hidden="true" /> <strong>Agregar a inicio</strong>.
        </span>
      </li>
      <li className="flex items-center gap-3">
        <span className="w-7 h-7 shrink-0 rounded-full bg-brand-50 text-brand-700 font-semibold flex items-center justify-center">3</span>
        <span>
          Toca <strong>Agregar</strong>. La encontrarás con el ícono de {name}.
        </span>
      </li>
    </ol>
  </Modal>
);

/** Botón de la barra superior: aparece solo si este navegador puede instalarla */
export const InstallButton: React.FC<{ name: string }> = ({ name }) => {
  const { canInstall, manualInstall } = useInstallableApp();
  const [guide, setGuide] = useState(false);

  if (!canInstall && !manualInstall) return null;

  return (
    <>
      <button
        type="button"
        onClick={() => (canInstall ? void promptInstall() : setGuide(true))}
        title="Instalar como app en este dispositivo"
        className="inline-flex items-center gap-1.5 h-9 px-2.5 rounded-lg text-[13px] font-semibold text-slate-600 hover:text-brand-700 hover:bg-brand-50 transition-colors cursor-pointer"
      >
        <Smartphone className="w-[18px] h-[18px]" />
        <span className="hidden md:inline">Instalar app</span>
      </button>
      {guide && <IosGuide name={name} onClose={() => setGuide(false)} />}
    </>
  );
};

/** Aviso en el teléfono, una sola vez: instalarla para abrirla como una app */
export const InstallBanner: React.FC<{ name: string }> = ({ name }) => {
  const { canInstall, manualInstall } = useInstallableApp();
  const isPhone = useMediaQuery('(max-width: 767px)');
  const [hidden, setHidden] = useState(dismissed);
  const [guide, setGuide] = useState(false);

  if (!isPhone || hidden || (!canInstall && !manualInstall)) return guide ? <IosGuide name={name} onClose={() => setGuide(false)} /> : null;

  const close = () => {
    setHidden(true);
    try {
      window.localStorage.setItem(BANNER_KEY, '1');
    } catch {
      // Sin almacenamiento: se oculta solo por esta vez
    }
  };

  return (
    <div role="status" className="flex items-center gap-3 px-4 py-2.5 bg-brand-50 border-b border-brand-100 text-[13px] text-brand-900">
      <Smartphone className="w-5 h-5 shrink-0 text-brand-700" />
      <p className="flex-1 min-w-0">
        <strong>Instala {name}</strong> en tu teléfono y ábrela como una app.
      </p>
      <Button size="sm" variant="primary" onClick={() => (canInstall ? void promptInstall() : setGuide(true))}>
        Instalar
      </Button>
      <button type="button" onClick={close} aria-label="Ahora no" className="p-1 text-brand-700 hover:text-brand-900 cursor-pointer">
        <X className="w-4 h-4" />
      </button>
      {guide && <IosGuide name={name} onClose={() => setGuide(false)} />}
    </div>
  );
};

/** Hay una versión nueva publicada: se cambia cuando la persona quiere, no a mitad de un trabajo */
export const UpdateNotice: React.FC = () => {
  const { updateReady } = useInstallableApp();

  if (!updateReady) return null;

  return (
    <div role="status" className="flex flex-wrap items-center justify-center gap-x-3 gap-y-1 px-4 py-2 bg-emerald-50 border-b border-emerald-200 text-[13px] text-emerald-900">
      <RefreshCw className="w-4 h-4 shrink-0" />
      <span>Hay una versión nueva del sistema.</span>
      <button type="button" onClick={applyUpdate} className="font-semibold underline cursor-pointer">
        Actualizar ahora
      </button>
    </div>
  );
};
