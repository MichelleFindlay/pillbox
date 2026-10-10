// Pillbox service worker.
// Makes the app installable and gives it an offline page. Only static files
// (styles, scripts, icons) are cached - pages with health data never are.
'use strict';

const CACHE = 'pillbox-static-v3';
const PRECACHE = [
  'offline.html',
  'assets/style.css',
  'assets/app.js',
  'assets/logo.svg',
  'assets/icons/icon-192.png',
];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k.startsWith('pillbox-') && k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') {
    return;
  }
  const url = new URL(request.url);
  if (url.origin !== self.location.origin) {
    return;
  }

  // Pages: always from the network; show the offline page if that fails.
  if (request.mode === 'navigate') {
    event.respondWith(fetch(request).catch(() => caches.match(new URL('offline.html', self.registration.scope).href)));
    return;
  }

  // Static assets: serve from cache, refresh in the background.
  const scopePath = new URL(self.registration.scope).pathname;
  if (url.pathname.startsWith(scopePath + 'assets/')) {
    event.respondWith(
      caches.open(CACHE).then((cache) =>
        cache.match(request).then((cached) => {
          const network = fetch(request).then((response) => {
            if (response.ok) {
              // Keep only the newest version of each file (URLs carry ?v=<mtime>).
              cache.keys().then((keys) => keys
                .filter((k) => new URL(k.url).pathname === url.pathname && k.url !== request.url)
                .forEach((k) => cache.delete(k)));
              cache.put(request, response.clone());
            }
            return response;
          });
          return cached || network.catch(() => cache.match(request, { ignoreSearch: true }));
        })
      )
    );
  }
});

// Push notifications (sent by the scheduled tasks; the message is encrypted for this device).
self.addEventListener('push', (event) => {
  let msg = { title: 'Pillbox', body: 'You have a reminder.' };
  try { msg = Object.assign(msg, event.data ? event.data.json() : {}); } catch (e) { /* keep the default */ }
  event.waitUntil(self.registration.showNotification(msg.title, {
    body: msg.body,
    tag: msg.tag || 'pillbox',
    renotify: true,
    icon: new URL('assets/icons/icon-192.png', self.registration.scope).href,
    badge: new URL('assets/icons/icon-192.png', self.registration.scope).href,
    data: { url: msg.url || self.registration.scope },
  }));
});

// Tapping a notification opens (or focuses) Pillbox at the right page.
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = new URL(event.notification.data && event.notification.data.url ? event.notification.data.url : '', self.registration.scope).href;
  event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
    for (const w of windows) {
      if (w.url.startsWith(self.registration.scope) && 'focus' in w) {
        return w.navigate(target).then((c) => (c || w).focus());
      }
    }
    return self.clients.openWindow(target);
  }));
});
