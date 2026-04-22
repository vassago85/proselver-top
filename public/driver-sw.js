/*
 * TRIDENT Control & Dispatch Center — Driver PWA service worker.
 *
 * Scope: /driver/   (registered from the driver layout)
 *
 * Responsibilities:
 *  - Pre-cache a minimal app shell so the driver can open the last-known
 *    jobs screen while offline.
 *  - Background Sync: on `driver-upload-queue` tag, nudge all open driver
 *    clients to flush the IndexedDB upload queue. The actual upload logic
 *    lives in resources/js/driver/sync.js (main thread) so it can share
 *    one IndexedDB connection and auth cookie with the UI.
 *  - NEVER cache captured photos. They live only in IndexedDB and are
 *    deleted the moment the server acknowledges each upload.
 *  - NEVER cache authenticated HTML responses. Livewire / session state
 *    would otherwise leak between users on shared devices.
 */

const CACHE_VERSION = 'v2';
const SHELL_CACHE = `trident-driver-shell-${CACHE_VERSION}`;

// Only static, non-authenticated, unchanging assets belong in the shell.
// Hashed build assets are cached opportunistically at runtime below.
const APP_SHELL = [
    '/logo.png',
    '/manifest.webmanifest',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(SHELL_CACHE).then((cache) => cache.addAll(APP_SHELL)).catch(() => {})
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(
                keys
                    .filter((key) => key.startsWith('trident-driver-') && key !== SHELL_CACHE)
                    .map((key) => caches.delete(key))
            )
        )
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const { request } = event;

    if (request.method !== 'GET') return;

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) return;
    if (url.pathname.startsWith('/driver/api/')) return;
    if (url.pathname.startsWith('/livewire/')) return;

    const isHashedBuildAsset = url.pathname.startsWith('/build/');

    if (isHashedBuildAsset) {
        event.respondWith(
            caches.match(request).then((cached) => {
                if (cached) return cached;
                return fetch(request).then((response) => {
                    if (response && response.status === 200 && response.type === 'basic') {
                        const clone = response.clone();
                        caches.open(SHELL_CACHE).then((cache) => cache.put(request, clone));
                    }
                    return response;
                });
            })
        );
        return;
    }

    if (APP_SHELL.includes(url.pathname)) {
        event.respondWith(
            caches.match(request).then((cached) => cached || fetch(request))
        );
    }
});

/*
 * Background Sync — fired by the browser when connectivity returns after a
 * client registered a sync with tag `driver-upload-queue`. We delegate the
 * actual work to any open driver client, or skip silently if none are open
 * (the page-level timer will catch up when the driver opens the tab).
 */
self.addEventListener('sync', (event) => {
    if (event.tag !== 'driver-upload-queue') return;

    event.waitUntil((async () => {
        const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        clients.forEach((client) => {
            try { client.postMessage({ type: 'driver-flush-queue' }); } catch (e) {}
        });
    })());
});

/*
 * Periodic Background Sync (optional, Android only) — same flush nudge.
 */
self.addEventListener('periodicsync', (event) => {
    if (event.tag !== 'driver-upload-queue-periodic') return;

    event.waitUntil((async () => {
        const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        clients.forEach((client) => {
            try { client.postMessage({ type: 'driver-flush-queue' }); } catch (e) {}
        });
    })());
});

self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'driver-sw-skip-waiting') {
        self.skipWaiting();
    }
});
