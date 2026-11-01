// Service worker de la aplicación instalable de cada academia.
// Solo muestra una página propia cuando no hay conexión. No guarda pantallas ni
// respuestas de la API: el código y los datos siempre llegan del servidor.
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
