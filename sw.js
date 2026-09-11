/* Pizza Party — offline shell.
   Collection/wantlist data is always fetched fresh; the shell and images are
   cached so the app opens instantly and survives a dead connection. */
// Bump VERSION whenever SHELL's contents change -- 'activate' below deletes
// any cache not matching the current VERSION, which is what actually
// evicts a stale app.js/app.css. Bumping the ?v= query strings alone
// updates what a *browser* fetches; it does nothing for entries this
// service worker already has stored under the old cache name.
const VERSION = 'pp-v3';
const SHELL = [
  './',
  './assets/app.css?v=5',
  './assets/app.js?v=4',
  './icons/icon-192.png'
];

self.addEventListener('install', e => {
  e.waitUntil(
    caches.open(VERSION)
      .then(c => c.addAll(SHELL))
      .then(() => self.skipWaiting())
      .catch(() => self.skipWaiting())
  );
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys()
      .then(keys => Promise.all(keys.filter(k => k !== VERSION).map(k => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', e => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== location.origin) return;

  // Passkey ceremonies and the session/discogs status probes must never be
  // served from cache — a challenge is single-use, and cached status would
  // misreport whether writes are unlocked or Discogs is connected.
  if (url.pathname.includes('/api/') && /action=(passkey|session|discogs)/.test(url.search)) {
    return;
  }

  if (url.pathname.includes('/api/')) {
    e.respondWith(
      fetch(req)
        .then(res => {
          const copy = res.clone();
          caches.open(VERSION).then(c => c.put(req, copy));
          return res;
        })
        .catch(() => caches.match(req))
    );
    return;
  }

  if (url.pathname.includes('/icons/')) {
    e.respondWith(
      caches.match(req).then(hit => hit || fetch(req).then(res => {
        const copy = res.clone();
        caches.open(VERSION).then(c => c.put(req, copy));
        return res;
      }))
    );
    return;
  }

  e.respondWith(
    fetch(req)
      .then(res => {
        const copy = res.clone();
        caches.open(VERSION).then(c => c.put(req, copy));
        return res;
      })
      .catch(() => caches.match(req).then(hit => hit || caches.match('./')))
  );
});
