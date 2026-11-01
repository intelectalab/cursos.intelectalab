// Service worker de la aplicación instalable de cada academia.
// Muestra una página propia cuando no hay conexión y los avisos al teléfono. No guarda
// pantallas ni respuestas de la API: el código y los datos siempre llegan del servidor.
// Si cambia offline.html, sube el número de CACHE para que el navegador la vuelva a guardar.

const CACHE = 'sin-conexion-1';
const OFFLINE_PAGE = '/offline.html';

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches
      .open(CACHE)
      .then((cache) => cache.add(new Request(OFFLINE_PAGE, { cache: 'reload' })))
      .then(() => self.skipWaiting()),
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) => Promise.all(keys.filter((key) => key.startsWith('sin-conexion-') && key !== CACHE).map((key) => caches.delete(key))))
      .then(() => self.clients.claim()),
  );
});

// Solo las páginas: si no hay internet, la página «Sin conexión» en lugar del error del navegador
self.addEventListener('fetch', (event) => {
  if (event.request.mode !== 'navigate') return;
  event.respondWith(fetch(event.request).catch(() => caches.match(OFFLINE_PAGE, { cacheName: CACHE })));
});

// Aviso al teléfono: título, texto, ícono de la academia y la pantalla a la que lleva
self.addEventListener('push', (event) => {
  let notice = {};
  try {
    notice = event.data ? event.data.json() : {};
  } catch {
    notice = { body: event.data ? event.data.text() : '' };
  }
  event.waitUntil(
    self.registration.showNotification(notice.title || 'Aviso', {
      body: notice.body || '',
      icon: notice.icon,
      badge: notice.icon,
      tag: notice.tag,
      data: { url: notice.url },
    }),
  );
});

// Al tocar el aviso: la app abierta va a esa pantalla; si no hay ninguna, se abre.
// Solo direcciones de la misma academia.
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const wanted = event.notification.data && event.notification.data.url;
  const url = wanted && wanted.startsWith(self.registration.scope) ? wanted : self.registration.scope;
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
      const open = windows.find((client) => client.url.startsWith(self.registration.scope));
      if (open) return open.focus().then((client) => (client && 'navigate' in client ? client.navigate(url) : client));
      return self.clients.openWindow(url);
    }),
  );
});
