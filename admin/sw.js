/**
 * THE MOVE :: service worker administrace.
 *
 * Záměrně nic necachuje — administrace musí vždy ukazovat aktuální stav
 * rezervací. Slouží jen k tomu, aby šla přidat na plochu jako aplikace,
 * a aby se při výpadku sítě ukázala srozumitelná hláška místo chyby.
 */

self.addEventListener('install', function (e) {
  self.skipWaiting();
});

self.addEventListener('activate', function (e) {
  e.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', function (e) {
  if (e.request.method !== 'GET') { return; }

  e.respondWith(
    fetch(e.request).catch(function () {
      return new Response(
        '<!doctype html><html lang="cs"><head><meta charset="utf-8">' +
        '<meta name="viewport" content="width=device-width,initial-scale=1">' +
        '<title>Bez připojení</title><style>' +
        'body{font-family:system-ui,sans-serif;max-width:30rem;margin:20vh auto;padding:0 1.5rem;' +
        'line-height:1.6;color:#111}h1{font-size:1.4rem;margin-bottom:.5rem}' +
        'button{margin-top:1.5rem;padding:.7rem 1.3rem;border:1px solid #111;background:#111;' +
        'color:#fff;border-radius:500px;font:inherit;cursor:pointer}</style></head><body>' +
        '<h1>Nejste připojeni k internetu</h1>' +
        '<p>Administrace pracuje s živými daty, takže potřebuje připojení. ' +
        'Jakmile budete zase online, zkuste to znovu.</p>' +
        '<button onclick="location.reload()">Zkusit znovu</button>' +
        '</body></html>',
        { headers: { 'Content-Type': 'text/html; charset=utf-8' } }
      );
    })
  );
});
