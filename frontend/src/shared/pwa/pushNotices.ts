// Avisos al teléfono en este dispositivo: pedir el permiso, suscribirse al servicio de
// avisos del navegador y registrar el dispositivo en la academia (o quitarlo).
import { api } from '../api/client';
import { basename } from '../tenancy/hosts';
import { isIos, isStandalone } from './installableApp';

export type PushState =
  // El navegador no tiene avisos (o la página no es segura)
  | 'unsupported'
  // iPhone y iPad: solo con la academia instalada en la pantalla de inicio
  | 'install-first'
  // La persona los bloqueó: se reactivan desde la configuración del navegador
  | 'blocked'
  | 'off'
  | 'on';

const supported = (): boolean => window.isSecureContext && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

async function registration(): Promise<ServiceWorkerRegistration | null> {
  if (!supported()) return null;
  return (await navigator.serviceWorker.getRegistration(`${basename}/`)) ?? null;
}

async function currentSubscription(): Promise<PushSubscription | null> {
  return (await (await registration())?.pushManager.getSubscription()) ?? null;
}

export async function pushState(): Promise<PushState> {
  if (isIos() && !isStandalone()) return 'install-first';
  if (!supported()) return 'unsupported';
  if (Notification.permission === 'denied') return 'blocked';
  return (await currentSubscription()) ? 'on' : 'off';
}

// La llave pública llega en base64 de URL; el navegador la quiere en bytes
const keyBytes = (key: string): Uint8Array<ArrayBuffer> => {
  const padded = `${key}${'='.repeat((4 - (key.length % 4)) % 4)}`.replace(/-/g, '+').replace(/_/g, '/');
  return Uint8Array.from(atob(padded), (character) => character.charCodeAt(0));
};

export async function enablePush(publicKey: string): Promise<PushState> {
  const worker = (await registration()) ?? (await navigator.serviceWorker.ready);

  if ((await Notification.requestPermission()) !== 'granted') {
    return Notification.permission === 'denied' ? 'blocked' : 'off';
  }

  const options = { userVisibleOnly: true, applicationServerKey: keyBytes(publicKey) };
  let subscription: PushSubscription;
  try {
    subscription = await worker.pushManager.subscribe(options);
  } catch (error) {
    // Una suscripción vieja con otra llave impide la nueva: se quita y se intenta otra vez
    const previous = await worker.pushManager.getSubscription();
    if (!previous) throw new PushError(error);
    await previous.unsubscribe();
    try {
      subscription = await worker.pushManager.subscribe(options);
    } catch (retryError) {
      throw new PushError(retryError);
    }
  }
  await api.post('/auth/push/subscriptions', subscription.toJSON());

  return 'on';
}

// El error del navegador explicado: qué pasó y qué hacer, con el detalle técnico para soporte
export class PushError extends Error {
  constructor(cause: unknown) {
    const name = cause instanceof Error ? cause.name : 'Error';
    const detail = cause instanceof Error ? `${cause.name}: ${cause.message}` : String(cause);
    const advice =
      name === 'NotAllowedError'
        ? 'El teléfono no dio permiso para los avisos. Permítelos para esta app en la configuración del teléfono y vuelve a intentarlo.'
        : name === 'AbortError'
          ? 'El servicio de avisos del teléfono no respondió. Revisa que Chrome esté actualizado y tenga conexión, y vuelve a intentarlo.'
          : 'El navegador no pudo activar los avisos.';
    super(`${advice} (Detalle: ${detail})`, { cause });
    this.name = 'PushError';
  }
}

export async function disablePush(): Promise<void> {
  const subscription = await currentSubscription();
  if (!subscription) return;

  await api.delete('/auth/push/subscriptions', { data: { endpoint: subscription.endpoint } });
  await subscription.unsubscribe();
}

// Al cerrar sesión este dispositivo deja de recibir avisos de la cuenta: puede ser un teléfono compartido
export async function forgetDeviceOnLogout(): Promise<void> {
  try {
    await disablePush();
  } catch {
    // Si falla, el aviso que llegue a este dispositivo se borra solo cuando el servicio lo rechace
  }
}
