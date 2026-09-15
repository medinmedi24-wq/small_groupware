const CACHE = 'mnm-php-assets-v33';
const ASSETS = ['assets/style.css','assets/admin.css','assets/icons.css','assets/php.css?v=27','assets/branding.css?v=3','assets/splash.js?v=3','assets/medinmedi-logo.png','assets/groupware-splash.jpg','assets/app.js?v=7','assets/attendance-sync.js?v=1','icons/groupware-180.png?v=2','icons/groupware-192.png','icons/groupware-512.png'];
self.addEventListener('install', event => { event.waitUntil(caches.open(CACHE).then(cache => cache.addAll(ASSETS))); self.skipWaiting(); });
self.addEventListener('activate', event => { event.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(k => k.startsWith('mnm-') && k !== CACHE).map(k => caches.delete(k))))); self.clients.claim(); });
self.addEventListener('fetch', event => {
  const url = new URL(event.request.url);
  if (event.request.method !== 'GET' || !ASSETS.some(p => new URL(p, self.registration.scope).href === url.href)) return;
  event.respondWith(fetch(event.request).catch(() => caches.match(event.request)));
});
