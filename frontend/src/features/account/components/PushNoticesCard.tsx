import React, { useEffect, useState } from 'react';
import { BellRing, Send } from 'lucide-react';
import { api, apiErrorMessage } from '../../../shared/api/client';
import { Alert, Button, Card, IconChip, LoadingState } from '../../../shared/ui';
import { disablePush, enablePush, pushState, type PushState } from '../../../shared/pwa/pushNotices';

interface PushSettings {
  enabled: boolean;
  public_key: string | null;
  devices: number;
}

const STATE_TEXT: Record<PushState, string> = {
  unsupported: 'Este navegador no puede recibir avisos. Prueba con Chrome, Edge o Firefox, o instala la academia como app.',
  'install-first': 'En el iPhone y el iPad los avisos llegan solo con la academia instalada: toca «Instalar app» arriba y vuelve a esta pantalla desde la app.',
  blocked: 'Bloqueaste los avisos de este sitio. Para activarlos, permítelos en la configuración del navegador (el candado junto a la dirección) y vuelve aquí.',
  off: 'Recibe en este dispositivo los avisos de tus cursos, los recordatorios y los anuncios, aunque no tengas la academia abierta.',
  on: 'Los avisos llegan a este dispositivo. Los tipos que dejaste de recibir por correo tampoco llegan aquí.',
};

/** «Mi cuenta»: activar los avisos al teléfono en el dispositivo que se está usando. */
export const PushNoticesCard: React.FC = () => {
  const [settings, setSettings] = useState<PushSettings | null>(null);
  const [state, setState] = useState<PushState | null>(null);
  const [busy, setBusy] = useState<'toggle' | 'test' | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  useEffect(() => {
    let active = true;
    Promise.all([api.get('/auth/push'), pushState()])
      .then(([response, current]) => {
        if (!active) return;
        setSettings(response.data.data as PushSettings);
        setState(current);
      })
      .catch((err) => active && setError(apiErrorMessage(err, 'No se pudo leer el estado de los avisos.')));

    return () => {
      active = false;
    };
  }, []);

  const run = async (kind: 'toggle' | 'test', action: () => Promise<void>, fallback: string) => {
    setBusy(kind);
    setError(null);
    setNotice(null);
    try {
      await action();
    } catch (err) {
      setError(apiErrorMessage(err, fallback));
    } finally {
      setBusy(null);
    }
  };

  const toggle = () =>
    run(
      'toggle',
      async () => {
        if (state === 'on') {
          await disablePush();
          setState('off');
        } else if (settings?.public_key) {
          setState(await enablePush(settings.public_key));
        }
        setSettings((await api.get('/auth/push')).data.data as PushSettings);
      },
      'No se pudieron cambiar los avisos de este dispositivo.',
    );

  const test = () =>
    run(
      'test',
      async () => {
        await api.post('/auth/push/test');
        setNotice('Listo: en unos segundos llega el aviso de prueba a tus dispositivos.');
      },
      'No se pudo enviar el aviso de prueba.',
    );

  const loading = !error && (settings === null || state === null);

  return (
    <Card className="p-5 sm:p-6">
      <div className="flex items-center gap-3.5 mb-5">
        <IconChip icon={BellRing} tone="brand" />
        <div>
          <h2 className="text-sm font-semibold text-slate-900">Avisos en este dispositivo</h2>
          <p className="text-[13px] text-slate-500">Los avisos de la academia en tu teléfono o computadora.</p>
        </div>
      </div>
      {error && <Alert>{error}</Alert>}
      {notice && <Alert tone="success">{notice}</Alert>}
      {loading && <LoadingState />}
      {settings && state && !settings.enabled && <p className="text-[13px] text-slate-500">Los avisos al teléfono todavía no están activos en esta academia.</p>}
      {settings?.enabled && state && (
        <div className="space-y-4">
          <p className="text-[13px] text-slate-600">{STATE_TEXT[state]}</p>
          {(state === 'on' || state === 'off') && (
            <div className="flex flex-wrap gap-2">
              <Button variant={state === 'on' ? 'secondary' : 'primary'} icon={<BellRing className="w-4 h-4" />} processing={busy === 'toggle'} disabled={busy !== null} onClick={() => void toggle()}>
                {state === 'on' ? 'Desactivar en este dispositivo' : 'Activar en este dispositivo'}
              </Button>
              {state === 'on' && (
                <Button icon={<Send className="w-4 h-4" />} processing={busy === 'test'} disabled={busy !== null} onClick={() => void test()}>
                  Enviarme un aviso de prueba
                </Button>
              )}
            </div>
          )}
          {settings.devices > 0 && (
            <p className="text-xs text-slate-500">
              {settings.devices === 1 ? 'Tienes los avisos activos en 1 dispositivo.' : `Tienes los avisos activos en ${settings.devices} dispositivos.`} Al cerrar sesión en uno, deja de recibirlos.
            </p>
          )}
        </div>
      )}
    </Card>
  );
};
