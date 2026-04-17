/**
 * Entry point for the driver PWA. Loaded only from the driver layout via
 * Vite, so this code never ships to the admin / customer pages.
 *
 * Responsibilities:
 *   - Register the driver-scoped service worker
 *   - Start the offline upload flusher
 *   - Expose a reactive `driverQueue` Alpine store for the UI
 *   - Expose `window.driverCapture` helpers used by Blade components
 */

import { compressImage } from './compress.js';
import * as queue from './queue.js';
import * as sync from './sync.js';

async function registerServiceWorker() {
    if (!('serviceWorker' in navigator)) return;
    try {
        await navigator.serviceWorker.register('/driver-sw.js', { scope: '/driver/' });
    } catch (err) {
        console.warn('[driver] service worker registration failed', err);
    }
}

async function captureFromInput({ input, jobId, category, notes = null }) {
    const file = input.files && input.files[0];
    if (!file) return null;

    const blob = await compressImage(file);

    let coords = { lat: null, lng: null };
    try {
        coords = await getCurrentPosition();
    } catch (e) { /* geolocation optional */ }

    const record = await queue.enqueue({
        jobId,
        category,
        blob,
        metadata: {
            originalFilename: file.name,
            latitude: coords.lat,
            longitude: coords.lng,
            notes,
        },
    });

    input.value = '';
    window.dispatchEvent(new CustomEvent('driver-capture-enqueued', { detail: { record } }));
    return record;
}

function getCurrentPosition() {
    return new Promise((resolve, reject) => {
        if (!navigator.geolocation) {
            resolve({ lat: null, lng: null });
            return;
        }
        navigator.geolocation.getCurrentPosition(
            (pos) => resolve({ lat: pos.coords.latitude, lng: pos.coords.longitude }),
            () => resolve({ lat: null, lng: null }),
            { enableHighAccuracy: false, timeout: 4000, maximumAge: 60_000 }
        );
    });
}

async function refreshStore(store) {
    const items = await queue.list();
    store.pending = items.length;
    store.items = items.map((r) => ({
        id: r.id,
        jobId: r.jobId,
        category: r.category,
        createdAt: r.createdAt,
        attempts: r.attempts,
        lastError: r.lastError,
        nextAttemptAt: r.nextAttemptAt,
    }));
}

function initAlpineStore() {
    document.addEventListener('alpine:init', () => {
        if (!window.Alpine) return;

        window.Alpine.store('driverQueue', {
            pending: 0,
            items: [],
            flushing: false,
            lastSyncAt: null,
            online: navigator.onLine,

            init() {
                const self = this;
                refreshStore(self);

                sync.subscribe((snap) => {
                    self.flushing = snap.flushing;
                    self.lastSyncAt = snap.lastSyncAt;
                });

                window.addEventListener('online', () => (self.online = true));
                window.addEventListener('offline', () => (self.online = false));
                window.addEventListener('driver-queue-changed', () => refreshStore(self));
                window.addEventListener('driver-capture-enqueued', () => refreshStore(self));
            },

            forceSync() {
                sync.flush({ force: true });
            },

            async removeItem(id) {
                await queue.remove(id);
                await refreshStore(this);
            },
        });
    });
}

function initInstallPrompt() {
    let deferred = null;

    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        deferred = e;
        window.dispatchEvent(new CustomEvent('driver-install-available'));
    });

    window.driverInstall = {
        available: () => deferred !== null,
        async prompt() {
            if (!deferred) return null;
            deferred.prompt();
            const choice = await deferred.userChoice;
            deferred = null;
            window.dispatchEvent(new CustomEvent('driver-install-dismissed'));
            return choice;
        },
    };
}

window.driverCapture = {
    fromInput: captureFromInput,
    listByJob: queue.listByJob,
    countByJobAndCategory: queue.countByJobAndCategory,
    countPending: queue.countPending,
    remove: queue.remove,
    forceSync: () => sync.flush({ force: true }),
};

initAlpineStore();
initInstallPrompt();
registerServiceWorker();
sync.init();
