// Aplicación instalable: el manifiesto y el ícono de la academia, el service worker
// (página «Sin conexión»), el pedido de instalación del navegador y el aviso de versión nueva.
import { useSyncExternalStore } from 'react';
import { basename, pathTenant } from '../tenancy/hosts';

interface InstallPromptEvent extends Event {
  prompt: () => Promise<void>;
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
}

// Cada cuánto se revisa si se publicó una versión nueva (además de al volver a la pestaña)
const VERSION_CHECK_MS = 15 * 60 * 1000;

let installEvent: InstallPromptEvent | null = null;
let updateReady = false;
let version = 0;
const listeners = new Set<() => void>();

const emit = () => {
  version += 1;
  listeners.forEach((listener) => listener());
};

// En el modo por ruta el navegador pide el manifiesto sin el encabezado de la academia
const tenantQuery = pathTenant ? `?tenant=${pathTenant}` : '';

// Se escucha antes de montar la aplicación: el navegador avisa una sola vez, al cargar
export function listenForInstall(): void {
  window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    installEvent = event as InstallPromptEvent;
    emit();
  });
  window.addEventListener('appinstalled', () => {
    installEvent = null;
    emit();
  });
}

function setHeadElement(selector: string, create: () => HTMLElement, apply: (element: HTMLElement) => void): void {
  let element = document.head.querySelector<HTMLElement>(selector);
  if (!element) {
    element = create();
    document.head.appendChild(element);
  }
  apply(element);
}

function setLink(rel: string, href: string): void {
  setHeadElement(
    `link[rel="${rel}"]`,
    () => Object.assign(document.createElement('link'), { rel }),
    (element) => ((element as HTMLLinkElement).href = href),
  );
}

// Color de la barra del sistema en el teléfono: el principal de la academia
export function setThemeColor(color: string): void {
  setHeadElement(
    'meta[name="theme-color"]',
    () => Object.assign(document.createElement('meta'), { name: 'theme-color' }),
    (element) => element.setAttribute('content', color),
  );
}

function registerWorker(): void {
  if (!('serviceWorker' in navigator) || !window.isSecureContext) return;
  navigator.serviceWorker.register('/sw.js', { scope: `${basename}/` }).catch(() => undefined);
}

// El código de la aplicación lleva un nombre distinto en cada publicación: si index.html
// apunta a otro, hay versión nueva. En desarrollo no hay nombre con versión y no se revisa.
const appScript = (html: Document | string): string | null => {
  const source = typeof html === 'string' ? html.match(/<script[^>]+src="(\/assets\/[^"]+\.js)"/)?.[1] : html.querySelector('script[src^="/assets/"]')?.getAttribute('src');
  return source ?? null;
};

function watchForNewVersion(): void {
  const current = appScript(document);
  if (!current) return;

  const check = () => {
    if (updateReady || document.visibilityState !== 'visible') return;
    fetch('/index.html', { cache: 'no-store' })
      .then((response) => (response.ok ? response.text() : null))
      .then((html) => {
        const published = html ? appScript(html) : null;
        if (published && published !== current) {
          updateReady = true;
          emit();
        }
      })
      .catch(() => undefined);
  };

  window.setInterval(check, VERSION_CHECK_MS);
  document.addEventListener('visibilitychange', check);
}

// Solo dentro de una academia: la portada de la plataforma no se instala
export function setupInstallableApp(): void {
  setLink('manifest', `/api/v1/app/manifest${tenantQuery}`);
  setLink('apple-touch-icon', `/api/v1/app/icon/192${tenantQuery}`);
  registerWorker();
  watchForNewVersion();
}

export async function promptInstall(): Promise<void> {
  const event = installEvent;
  if (!event) return;
  installEvent = null;
  emit();
  await event.prompt();
}

// La versión nueva se toma recargando: index.html no se guarda en caché
export const applyUpdate = (): void => window.location.reload();

export const isStandalone = (): boolean =>
  window.matchMedia('(display-mode: standalone)').matches || (navigator as Navigator & { standalone?: boolean }).standalone === true;

// iPhone y iPad no tienen botón de instalar: se instala desde Compartir → Agregar a inicio
export const isIos = (): boolean =>
  /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

const subscribe = (listener: () => void) => {
  listeners.add(listener);
  return () => listeners.delete(listener);
};

export function useInstallableApp(): { canInstall: boolean; manualInstall: boolean; updateReady: boolean } {
  useSyncExternalStore(subscribe, () => version);
  const installed = isStandalone();

  return {
    canInstall: !installed && installEvent !== null,
    manualInstall: !installed && installEvent === null && isIos(),
    updateReady,
  };
}
