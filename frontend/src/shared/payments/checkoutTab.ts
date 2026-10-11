import { useEffect, useRef, useState } from 'react';

// El pago en línea se abre en otra pestaña: la del sistema se queda donde estaba, esperando.
// Las dos pestañas se avisan por este canal para que la del pago se cierre sola al volver.
const CHANNEL = 'checkout-tab';

type Message = { type: 'returned' | 'ack'; key: string };

// Abre la pestaña en el mismo clic. Si se abriera después de esperar al servidor, el navegador la bloquearía
export const openCheckoutTab = (): Window | null => {
  const tab = window.open('', '_blank');
  if (tab) {
    tab.document.title = 'Abriendo el pago…';
    tab.document.body.style.cssText = 'margin:0;display:flex;align-items:center;justify-content:center;height:100vh;font:15px system-ui,sans-serif;color:#475569';
    tab.document.body.textContent = 'Abriendo la página segura del medio de pago…';
  }
  return tab;
};

// Lleva la pestaña a la pasarela. Si el navegador no dejó abrirla, el pago sigue en esta misma (devuelve false)
export const sendToCheckout = (tab: Window | null, url: string): boolean => {
  if (!tab || tab.closed) {
    window.location.assign(url);
    return false;
  }
  tab.opener = null;
  tab.location.replace(url);
  return true;
};

export const closeCheckoutTab = (tab: Window | null) => {
  if (tab && !tab.closed) tab.close();
};

interface Options {
  // Qué se está pagando (una orden): las dos pestañas deben coincidir
  key: string;
  // Esta pestaña abrió el pago en otra y espera el resultado
  waiting: boolean;
  // Esta pestaña es la que vuelve de la pasarela y ya comprobó el pago
  returned: boolean;
  // Vuelve a preguntar por el pago
  onCheck: () => void;
}

// Devuelve true en la pestaña que volvió de la pasarela cuando hay otra esperando: esta ya se puede cerrar
export const useCheckoutTab = ({ key, waiting, returned, onCheck }: Options): boolean => {
  const [spare, setSpare] = useState(false);
  const live = useRef({ waiting, onCheck });

  useEffect(() => {
    live.current = { waiting, onCheck };
  });

  useEffect(() => {
    if (!key || typeof BroadcastChannel === 'undefined') return;
    const channel = new BroadcastChannel(CHANNEL);
    channel.onmessage = (event: MessageEvent<Message>) => {
      if (event.data?.key !== key) return;
      if (event.data.type === 'returned' && live.current.waiting) {
        channel.postMessage({ type: 'ack', key } satisfies Message);
        live.current.onCheck();
      }
      if (event.data.type === 'ack' && returned) {
        setSpare(true);
        window.close();
      }
    };
    if (returned) channel.postMessage({ type: 'returned', key } satisfies Message);
    return () => channel.close();
  }, [key, returned]);

  // Al regresar a la pestaña que espera, se comprueba el pago sin que la persona tenga que pedirlo
  useEffect(() => {
    if (!waiting) return;
    const check = () => {
      if (document.visibilityState === 'visible') live.current.onCheck();
    };
    window.addEventListener('focus', check);
    document.addEventListener('visibilitychange', check);
    return () => {
      window.removeEventListener('focus', check);
      document.removeEventListener('visibilitychange', check);
    };
  }, [waiting]);

  return spare;
};
