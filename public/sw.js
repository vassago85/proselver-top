/*
 * TRIDENT main-app service worker — DEPRECATED, self-uninstalling stub.
 *
 * The previous version of this file was a cache-first SW that pinned the
 * Vite build bundle and Livewire JS, causing post-deploy crashes inside
 * Livewire's success handler (stale JS shape, undefined .components.forEach)
 * and was flagged by docs/SECURITY_AUDIT_2026-04-22.md (H-1) for caching
 * authenticated HTML across users on shared devices.
 *
 * This file remains in the build only so that browsers which already have
 * the old SW installed will fetch THIS replacement on their next update
 * check, immediately self-unregister, and drop any caches it created. The
 * resources/js/app.js entry point also explicitly unregisters /sw.js on
 * page load as a faster path to the same outcome.
 *
 * The driver PWA continues to use its own scoped service worker at
 * /driver-sw.js — that one is unaffected.
 */

self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        try {
            const keys = await caches.keys();
            await Promise.all(
                keys
                    .filter((k) => k.startsWith('tcdc-'))
                    .map((k) => caches.delete(k))
            );
            await self.registration.unregister();
        } catch (e) { /* noop */ }
    })());
});

// No fetch handler — every request goes straight to the network.
