/**
 * Aziv AI — the app shell (Owner Addendum A, Phase 9).
 *
 * WHAT THIS IS FOR, and what it is deliberately not. The blueprint asks for a
 * PWA-ready product: installable, and useful for the two seconds before a
 * flaky connection answers. It does NOT ask for an offline application, and
 * building one here would be actively wrong — this is a product whose entire
 * purpose is talking to an AI provider over the network.
 *
 * SO ONLY THE SHELL IS CACHED: the compiled stylesheet, the compiled script,
 * the icons, and one offline page. Everything else goes to the network.
 *
 * NOTHING PRIVATE IS EVER STORED. Not a conversation, not an invoice, not a
 * generated image, not an API response. A service worker cache is
 * origin-scoped and survives sign-out, so anything cached here would be
 * readable by the next person to use the device — which is the whole reason
 * `Cache-Control: private, no-store` exists on those responses and why this
 * file refuses to touch them regardless of what a response header says.
 *
 * IT IS SERVED FROM `public/` AS A STATIC FILE, not generated, because a
 * service worker must be reachable at the scope root and must keep working
 * when PHP is the thing that is down.
 */

// Bumped on every release. A stale shell against new server-side markup is the
// classic PWA failure: the customer sees an interface that no longer matches
// the application and clearing it is not something they know how to do.
const VERSION = 'aziv-v1';
const SHELL = `${VERSION}-shell`;

/**
 * The offline page, and nothing that could be personal.
 *
 * The compiled assets are added at install time by reading the build manifest,
 * so this list never names a hashed filename that changes every release.
 */
const OFFLINE_URL = '/offline';

/** Paths whose responses must never be cached, whatever they say. */
const NEVER_CACHE = [
  '/admin',        // the whole panel: every screen is somebody's data
  '/install',      // the installer, which must always reflect real state
  '/chat/',        // conversations
  '/voice/',       // recordings and transcripts
  '/media/',       // generated images and speech
  '/files/',       // uploads
  '/billing',      // invoices
  '/checkout',     // payments
  '/renew',        // signed payment links
  '/livewire/',    // every component update
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    (async () => {
      const cache = await caches.open(SHELL);

      // Best effort. A shell that fails to precache is a slower first paint,
      // never a broken site — so one missing asset must not abort the install
      // and leave the previous worker in place for ever.
      await Promise.allSettled([
        cache.add(new Request(OFFLINE_URL, { cache: 'reload' })),
        cache.add(new Request('/manifest.webmanifest', { cache: 'reload' })),
        cache.add(new Request('/favicon.ico', { cache: 'reload' })),
      ]);

      await self.skipWaiting();
    })(),
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    (async () => {
      // Anything from an older release goes. Leaving it costs the customer
      // storage for assets no page will ever ask for again.
      const names = await caches.keys();
      await Promise.all(names.filter((name) => !name.startsWith(VERSION)).map((name) => caches.delete(name)));
      await self.clients.claim();
    })(),
  );
});

self.addEventListener('fetch', (event) => {
  const { request } = event;

  // GET only. A cached POST is a payment or a message sent twice.
  if (request.method !== 'GET') return;

  const url = new URL(request.url);

  // Same origin only: a provider's CDN is not ours to cache.
  if (url.origin !== self.location.origin) return;

  if (NEVER_CACHE.some((path) => url.pathname.startsWith(path))) return;

  // A NAVIGATION falls back to the offline page. Network first, because a
  // cached page of a live application is worse than a slow one.
  if (request.mode === 'navigate') {
    event.respondWith(
      (async () => {
        try {
          return await fetch(request);
        } catch {
          const cache = await caches.open(SHELL);
          return (await cache.match(OFFLINE_URL)) || Response.error();
        }
      })(),
    );

    return;
  }

  // BUILT ASSETS are content-hashed, so a cached one can never be stale: a
  // changed file has a different name. Cache first is safe and is the whole
  // performance win.
  if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/brand/')) {
    event.respondWith(
      (async () => {
        const cache = await caches.open(SHELL);
        const hit = await cache.match(request);

        if (hit) return hit;

        const response = await fetch(request);

        if (response.ok && response.type === 'basic') {
          cache.put(request, response.clone());
        }

        return response;
      })(),
    );
  }
});
