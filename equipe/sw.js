// Appli équipe du 76 : réception des notifications (nouvelles demandes, annulations).
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(self.clients.claim()));

self.addEventListener('push', e => {
  let d = {};
  try { d = e.data ? e.data.json() : {}; } catch (_) { d = { corps: e.data && e.data.text() }; }
  e.waitUntil((async () => {
    await self.registration.showNotification(d.titre || 'Le 76', {
      body: d.corps || 'Nouvelle demande de réservation',
      icon: '/equipe/icone-192.png',
      badge: '/equipe/icone-badge.png',
      tag: d.tag || 'resa',
      renotify: true,
      data: { url: d.url || '/equipe/#demandes' },
    });
    const fenetres = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    fenetres.forEach(c => c.postMessage({ type: 'push' }));
  })());
});

self.addEventListener('notificationclick', e => {
  e.notification.close();
  const url = new URL((e.notification.data && e.notification.data.url) || '/equipe/', self.location.origin).href;
  e.waitUntil((async () => {
    const fenetres = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const c of fenetres) {
      if (c.url.includes('/equipe/')) {
        await c.focus();
        try { await c.navigate(url); } catch (_) { c.postMessage({ type: 'push' }); }
        return;
      }
    }
    await self.clients.openWindow(url);
  })());
});
