import './bootstrap';

/*
 * Service worker policy for the main app:
 *
 *   - We DO NOT register a service worker on the admin / dispatcher / customer
 *     side. The previous /sw.js used a cache-first strategy for every non-HTML
 *     response, which permanently pinned the Vite build bundle and any
 *     vendor JS (incl. Livewire). After a deploy, browsers kept serving
 *     stale JS that crashed on shape changes ("Cannot read properties of
 *     undefined (reading 'forEach')" inside Livewire's success handler).
 *     The same SW was also flagged in docs/SECURITY_AUDIT_2026-04-22.md (H-1)
 *     for caching authenticated HTML across users on shared devices.
 *
 *   - The driver PWA continues to register its own scoped service worker
 *     at /driver-sw.js from the driver layout. That one is safe by design:
 *     it never caches authenticated HTML or /livewire/* and is versioned.
 *
 * We also self-heal any browser that still has the old /sw.js installed by
 * unregistering it on next page load and clearing its caches. After every
 * driver / admin re-opens the site once, the leftover SW disappears.
 */
if ('serviceWorker' in navigator) {
    window.addEventListener('load', async () => {
        try {
            const regs = await navigator.serviceWorker.getRegistrations();
            for (const reg of regs) {
                const scriptUrl = reg.active?.scriptURL || reg.installing?.scriptURL || reg.waiting?.scriptURL || '';
                if (scriptUrl.endsWith('/sw.js')) {
                    await reg.unregister();
                }
            }

            if (window.caches) {
                const keys = await caches.keys();
                await Promise.all(
                    keys
                        .filter((k) => k.startsWith('tcdc-'))
                        .map((k) => caches.delete(k))
                );
            }
        } catch (e) { /* noop — best effort */ }
    });
}
