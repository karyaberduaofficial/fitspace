/* ============================================================================
 * FitSpace PWA — Zero-Leak Service Worker
 * HANYA men-cache aset statis publik.
 * DILARANG KERAS men-cache rute /api/*, data pribadi, data anak, atau transaksi!
 * ============================================================================ */

const CACHE_NAME = 'fitspace-static-v2';
const STATIC_ASSETS = [
  '/',
  '/manifest.webmanifest',
  '/styles/tokens.css',
  '/styles/main.css',
  '/scripts/app.js',
  '/icons/icon.svg',
  '/icons/fitspace-logo.svg'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(STATIC_ASSETS)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);
  // NEVER cache cross-origin requests, APIs, auth data, health probes, or report downloads
  if (
    url.origin !== self.location.origin ||
    url.pathname.startsWith('/api/') ||
    url.pathname === '/healthz' ||
    req.headers.has('Authorization')
  ) {
    return;
  }

  event.respondWith(
    fetch(req)
      .then((res) => {
        if (res.ok && res.type === 'basic') {
          const cc = (res.headers.get('Cache-Control') || '').toLowerCase();
          if (!cc.includes('no-store') && !cc.includes('private')) {
            const copy = res.clone();
            caches.open(CACHE_NAME).then((cache) => cache.put(req, copy)).catch(() => {});
          }
        }
        return res;
      })
      .catch(() => caches.match(req).then((cached) => cached || caches.match('/')))
  );
});

self.addEventListener('push', (event) => {
  if (!event.data) return;
  let data = { title: 'FitSpace PWA', body: 'Ada pembaruan jadwal atau kelas terbaru.' };
  try {
    data = Object.assign(data, event.data.json());
  } catch {
    data.body = event.data.text();
  }
  event.waitUntil(
    self.registration.showNotification(data.title, {
      body: data.body,
      icon: '/icons/icon.svg',
      badge: '/icons/icon.svg',
      tag: data.tag || 'fitspace-notif'
    })
  );
});
