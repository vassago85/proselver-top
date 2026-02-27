const CACHE_NAME = 'proselver-v1';
const STATIC_ASSETS = [
    '/',
    '/manifest.json',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => cache.addAll(STATIC_ASSETS))
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key)))
        )
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    if (event.request.method !== 'GET') return;

    // Network-first for HTML, cache-first for static assets
    if (event.request.headers.get('accept')?.includes('text/html')) {
        event.respondWith(
            fetch(event.request)
                .then((response) => {
                    const clone = response.clone();
                    caches.open(CACHE_NAME).then((cache) => cache.put(event.request, clone));
                    return response;
                })
                .catch(() => caches.match(event.request))
        );
    } else {
        event.respondWith(
            caches.match(event.request).then((cached) => cached || fetch(event.request))
        );
    }
});

// Background sync for offline events
self.addEventListener('sync', (event) => {
    if (event.tag === 'sync-job-events') {
        event.waitUntil(syncJobEvents());
    }
});

async function syncJobEvents() {
    try {
        const db = await openDB();
        const tx = db.transaction('pendingEvents', 'readonly');
        const store = tx.objectStore('pendingEvents');
        const events = await getAllFromStore(store);

        for (const entry of events) {
            try {
                const response = await fetch(`/api/driver/jobs/${entry.jobId}/events`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': `Bearer ${entry.token}`,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ events: [entry.event] }),
                });

                if (response.ok) {
                    const deleteTx = db.transaction('pendingEvents', 'readwrite');
                    deleteTx.objectStore('pendingEvents').delete(entry.id);
                }
            } catch (e) {
                // Will retry on next sync
            }
        }
    } catch (e) {
        console.error('Sync failed:', e);
    }
}

function openDB() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open('ProselverDriver', 1);
        request.onupgradeneeded = (e) => {
            const db = e.target.result;
            if (!db.objectStoreNames.contains('pendingEvents')) {
                db.createObjectStore('pendingEvents', { keyPath: 'id', autoIncrement: true });
            }
            if (!db.objectStoreNames.contains('jobs')) {
                db.createObjectStore('jobs', { keyPath: 'id' });
            }
        };
        request.onsuccess = (e) => resolve(e.target.result);
        request.onerror = (e) => reject(e.target.error);
    });
}

function getAllFromStore(store) {
    return new Promise((resolve, reject) => {
        const request = store.getAll();
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}
