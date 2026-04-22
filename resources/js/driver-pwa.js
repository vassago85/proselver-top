const LEGACY_DB_NAME = 'ProselverDriver';
const CURRENT_DB_NAME = 'TcdcDriver';
const DB_VERSION = 1;

const OBJECT_STORES = [
    { name: 'pendingEvents', keyPath: 'id', autoIncrement: true },
    { name: 'jobs', keyPath: 'id' },
];

function openDatabase(name) {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(name, DB_VERSION);
        request.onupgradeneeded = (e) => {
            const db = e.target.result;
            for (const spec of OBJECT_STORES) {
                if (!db.objectStoreNames.contains(spec.name)) {
                    db.createObjectStore(spec.name, {
                        keyPath: spec.keyPath,
                        autoIncrement: Boolean(spec.autoIncrement),
                    });
                }
            }
        };
        request.onsuccess = (e) => resolve(e.target.result);
        request.onerror = (e) => reject(e.target.error);
    });
}

async function getAllRecords(db, storeName) {
    if (!db.objectStoreNames.contains(storeName)) return [];
    return new Promise((resolve) => {
        const tx = db.transaction(storeName, 'readonly');
        const request = tx.objectStore(storeName).getAll();
        request.onsuccess = () => resolve(request.result || []);
        request.onerror = () => resolve([]);
    });
}

async function legacyDatabaseExists(name) {
    if (typeof indexedDB.databases !== 'function') {
        // Older browsers: we can't enumerate, so fall back to a best-effort
        // open-and-sniff approach below.
        return true;
    }
    try {
        const list = await indexedDB.databases();
        return list.some((info) => info?.name === name);
    } catch (_) {
        return true;
    }
}

/**
 * One-time migration from the legacy "ProselverDriver" IndexedDB into the
 * new "TcdcDriver" database. Copies queued events + cached jobs, then drops
 * the old database so future boots skip straight to the new one.
 */
async function migrateLegacyIfNeeded() {
    try {
        const marker = 'tcdc-driver-migrated-from-proselver';
        if (localStorage.getItem(marker)) return;
        if (!(await legacyDatabaseExists(LEGACY_DB_NAME))) {
            localStorage.setItem(marker, String(Date.now()));
            return;
        }

        const legacyDb = await openDatabase(LEGACY_DB_NAME);
        const [pending, jobs] = await Promise.all([
            getAllRecords(legacyDb, 'pendingEvents'),
            getAllRecords(legacyDb, 'jobs'),
        ]);
        legacyDb.close();

        if (pending.length || jobs.length) {
            const newDb = await openDatabase(CURRENT_DB_NAME);
            if (pending.length) {
                const tx = newDb.transaction('pendingEvents', 'readwrite');
                const store = tx.objectStore('pendingEvents');
                for (const record of pending) {
                    // Drop the old auto-increment id so entries are appended
                    // cleanly without colliding with anything queued later.
                    const { id: _ignore, ...rest } = record;
                    store.add(rest);
                }
            }
            if (jobs.length) {
                const tx = newDb.transaction('jobs', 'readwrite');
                const store = tx.objectStore('jobs');
                for (const job of jobs) {
                    store.put(job);
                }
            }
            newDb.close();
        }

        await new Promise((resolve) => {
            const req = indexedDB.deleteDatabase(LEGACY_DB_NAME);
            req.onsuccess = req.onerror = req.onblocked = () => resolve();
        });
        localStorage.setItem(marker, String(Date.now()));
    } catch (e) {
        console.warn('Driver IndexedDB migration skipped:', e);
    }
}

class DriverSync {
    constructor() {
        this.dbName = CURRENT_DB_NAME;
        this.dbVersion = DB_VERSION;
        this.migrationPromise = migrateLegacyIfNeeded();
    }

    async openDB() {
        await this.migrationPromise;
        return openDatabase(this.dbName);
    }

    async queueEvent(jobId, event, token) {
        const db = await this.openDB();
        const tx = db.transaction('pendingEvents', 'readwrite');
        tx.objectStore('pendingEvents').add({ jobId, event, token, createdAt: new Date().toISOString() });

        if ('serviceWorker' in navigator && 'SyncManager' in window) {
            const reg = await navigator.serviceWorker.ready;
            await reg.sync.register('sync-job-events');
        }
    }

    async getPendingCount() {
        const db = await this.openDB();
        const tx = db.transaction('pendingEvents', 'readonly');
        const store = tx.objectStore('pendingEvents');
        return new Promise((resolve) => {
            const request = store.count();
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => resolve(0);
        });
    }

    async cacheJobs(jobs) {
        const db = await this.openDB();
        const tx = db.transaction('jobs', 'readwrite');
        const store = tx.objectStore('jobs');
        for (const job of jobs) {
            store.put(job);
        }
    }

    async getCachedJobs() {
        const db = await this.openDB();
        const tx = db.transaction('jobs', 'readonly');
        return new Promise((resolve) => {
            const request = tx.objectStore('jobs').getAll();
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => resolve([]);
        });
    }
}

window.DriverSync = new DriverSync();
