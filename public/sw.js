/* Bharat Wire — push notification service worker. No caching/offline logic here on purpose:
   this app is server-rendered and always wants fresh content, so the worker's only job is push. */

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', event => event.waitUntil(self.clients.claim()));

self.addEventListener('push', event => {
  let data = { title: 'Bharat Wire', body: 'A story just went up.', url: '/' };
  try { if (event.data) data = { ...data, ...event.data.json() }; } catch (e) { /* non-JSON payload, use defaults */ }
  event.waitUntil(self.registration.showNotification(data.title, {
    body: data.body,
    icon: data.icon || '/assets/img/logo-512.png',
    badge: data.badge || '/assets/img/favicon.svg',
    data: { url: data.url || '/' },
    tag: data.url || undefined,
  }));
});

self.addEventListener('notificationclick', event => {
  event.notification.close();
  const url = event.notification.data && event.notification.data.url ? event.notification.data.url : '/';
  event.waitUntil((async () => {
    const clientsList = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const c of clientsList) {
      if (c.url === url && 'focus' in c) return c.focus();
    }
    return self.clients.openWindow(url);
  })());
});
