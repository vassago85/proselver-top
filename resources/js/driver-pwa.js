class DriverSync {
    constructor() {
        this.dbName = 'ProselverDriver';
        this.dbVersion = 1;
    }

    async openDB() {
        return new Promise((resolve, reject) => {
            const request = indexedDB.open(this.dbName, this.dbVersion);
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
