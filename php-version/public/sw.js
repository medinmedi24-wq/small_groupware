const CACHE = 'mnm-php-assets-v28';
const ASSETS = ['assets/style.css','assets/admin.css','assets/icons.css','assets/php.css?v=26','assets/app.js?v=7','assets/attendance-sync.js?v=1','icons/mnm-app-192.png','icons/mnm-app-512.png'];
self.addEventListener('install', event => { event.waitUntil(caches.open(CACHE).then(cache => cache.addAll(ASSETS))); self.skipWaiting(); });
self.addEventListener('activate', event => { event.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(k => k.startsWith('mnm-') && k !== CACHE).map(k => caches.delete(k))))); self.clients.claim(); });
self.addEventListener('fetch', event => {
  const url = new URL(event.request.url);
  if (event.request.method !== 'GET' || !ASSETS.some(p => new URL(p, self.registration.scope).href === url.href)) return;
  event.respondWith(fetch(event.request).catch(() => caches.match(event.request)));
});
