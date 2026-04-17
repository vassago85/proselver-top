/**
 * IndexedDB-backed offline upload queue for the driver PWA.
 *
 * Why IndexedDB (not localStorage or Cache API):
 *   - localStorage is synchronous, string-only, and capped at ~5MB.
 *   - Cache API is designed for HTTP request/response pairs, not
 *     user-captured blobs that we want to delete once acknowledged.
 *   - IndexedDB can store Blobs natively with a ~1GB quota on Chrome
 *     Android and trivially supports delete-on-sync.
 *
 * Record shape:
 *   {
 *     id: uuid                         // client_uuid sent to the server
 *     jobId: number                    // transport_jobs.id
 *     category: string                 // JobDocument::allowedCategories()
 *     blob: Blob                       // compressed JPEG / PDF
 *     metadata: {
 *       capturedAt: ISO string,
 *       latitude: number|null,
 *       longitude: number|null,
 *       notes: string|null,
 *       originalFilename: string,
 *     }
 *     createdAt: number                // Date.now()
 *     attempts: number                 // incremented on each failed upload
 *     nextAttemptAt: number            // Date.now() gate for backoff
 *     lastError: string|null
 *   }
 */

const DB_NAME = 'trident-driver';
const DB_VERSION = 1;
const STORE_UPLOADS = 'uploads';

let dbPromise = null;

function openDb() {
    if (dbPromise) return dbPromise;
    dbPromise = new Promise((resolve, reject) => {
        const req = indexedDB.open(DB_NAME, DB_VERSION);
        req.onupgradeneeded = (ev) => {
            const db = ev.target.result;
            if (!db.objectStoreNames.contains(STORE_UPLOADS)) {
                const store = db.createObjectStore(STORE_UPLOADS, { keyPath: 'id' });
                store.createIndex('by_jobId', 'jobId', { unique: false });
                store.createIndex('by_jobAndCategory', ['jobId', 'category'], { unique: false });
                store.createIndex('by_nextAttemptAt', 'nextAttemptAt', { unique: false });
            }
        };
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
    });
    return dbPromise;
}

function tx(mode) {
    return openDb().then((db) => db.transaction(STORE_UPLOADS, mode).objectStore(STORE_UPLOADS));
}

function promisify(req) {
    return new Promise((resolve, reject) => {
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
    });
}

export function generateId() {
    if (typeof crypto !== 'undefined' && crypto.randomUUID) return crypto.randomUUID();
    // RFC4122 v4 fallback
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;
        const v = c === 'x' ? r : (r & 0x3) | 0x8;
        return v.toString(16);
    });
}

/**
 * Enqueue a capture. Returns the stored record (with generated id).
 */
export async function enqueue({ jobId, category, blob, metadata = {} }) {
    if (!jobId || !category || !(blob instanceof Blob)) {
        throw new Error('enqueue(): jobId, category, and blob are required');
    }

    const record = {
        id: generateId(),
        jobId: Number(jobId),
        category,
        blob,
        metadata: {
            capturedAt: metadata.capturedAt ?? new Date().toISOString(),
            latitude: metadata.latitude ?? null,
            longitude: metadata.longitude ?? null,
            notes: metadata.notes ?? null,
            originalFilename: metadata.originalFilename ?? `capture-${Date.now()}.jpg`,
        },
        createdAt: Date.now(),
        attempts: 0,
        nextAttemptAt: 0,
        lastError: null,
    };

    const store = await tx('readwrite');
    await promisify(store.add(record));
    return record;
}

export async function list() {
    const store = await tx('readonly');
    return promisify(store.getAll());
}

export async function listByJob(jobId) {
    const store = await tx('readonly');
    const idx = store.index('by_jobId');
    return promisify(idx.getAll(Number(jobId)));
}

export async function countByJobAndCategory(jobId, category) {
    const store = await tx('readonly');
    const idx = store.index('by_jobAndCategory');
    return promisify(idx.count(IDBKeyRange.only([Number(jobId), category])));
}

export async function countPending() {
    const store = await tx('readonly');
    return promisify(store.count());
}

export async function get(id) {
    const store = await tx('readonly');
    return promisify(store.get(id));
}

export async function remove(id) {
    const store = await tx('readwrite');
    return promisify(store.delete(id));
}

export async function update(record) {
    const store = await tx('readwrite');
    return promisify(store.put(record));
}

/**
 * Items whose backoff gate has elapsed.
 */
export async function listDue() {
    const all = await list();
    const now = Date.now();
    return all.filter((r) => (r.nextAttemptAt ?? 0) <= now);
}
