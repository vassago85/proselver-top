/**
 * Offline upload flusher for the driver PWA.
 *
 * Reads items off the IndexedDB queue (see ./queue.js) and POSTs each one
 * to /driver/api/jobs/{jobId}/documents with the record's id used as
 * client_uuid. On 2xx we delete the local record — that is the literal
 * "remove from phone" step the user asked for.
 *
 * Triggers:
 *   - page load
 *   - `online` event
 *   - `visibilitychange` (tab returns to foreground)
 *   - every 60s while the queue is non-empty
 *   - message `driver-flush-queue` from the service worker (Background Sync)
 *
 * Backoff schedule (applied per record after each failed attempt):
 *   30s, 2m, 10m, 30m, 2h, 6h, 12h   (then give up and flag the record)
 */

import * as queue from './queue.js';

const BACKOFF_STEPS_MS = [
    30_000,
    2 * 60_000,
    10 * 60_000,
    30 * 60_000,
    2 * 60 * 60_000,
    6 * 60 * 60_000,
    12 * 60 * 60_000,
];

const POLL_INTERVAL_MS = 60_000;

const listeners = new Set();
let isFlushing = false;
let pollTimer = null;
let lastSyncAt = null;

export function subscribe(fn) {
    listeners.add(fn);
    fn(snapshot());
    return () => listeners.delete(fn);
}

function snapshot() {
    return {
        flushing: isFlushing,
        lastSyncAt,
    };
}

function notify() {
    const s = snapshot();
    listeners.forEach((fn) => {
        try { fn(s); } catch (e) { /* noop */ }
    });
}

function csrfToken() {
    const el = document.querySelector('meta[name="csrf-token"]');
    return el ? el.getAttribute('content') : null;
}

function shouldDeferForSlowNetwork() {
    const c = navigator.connection;
    if (!c || !c.effectiveType) return false;
    return c.effectiveType === 'slow-2g';
}

async function uploadOne(record) {
    const form = new FormData();
    const filename = record.metadata.originalFilename || `${record.id}.jpg`;
    form.append('file', record.blob, filename);
    form.append('category', record.category);
    form.append('client_uuid', record.id);
    if (record.metadata.capturedAt) form.append('captured_at', record.metadata.capturedAt);
    if (record.metadata.latitude != null) form.append('latitude', String(record.metadata.latitude));
    if (record.metadata.longitude != null) form.append('longitude', String(record.metadata.longitude));
    if (record.metadata.notes) form.append('notes', record.metadata.notes);

    const headers = { 'Accept': 'application/json' };
    const token = csrfToken();
    if (token) headers['X-CSRF-TOKEN'] = token;
    headers['X-Requested-With'] = 'XMLHttpRequest';

    const response = await fetch(`/driver/api/jobs/${record.jobId}/documents`, {
        method: 'POST',
        body: form,
        credentials: 'same-origin',
        headers,
    });

    return response;
}

export async function flush({ force = false } = {}) {
    if (isFlushing) return;
    if (!navigator.onLine && !force) return;
    if (!force && shouldDeferForSlowNetwork()) return;

    isFlushing = true;
    notify();

    try {
        const due = await queue.listDue();

        for (const record of due) {
            try {
                const response = await uploadOne(record);

                if (response.ok) {
                    await queue.remove(record.id);
                    lastSyncAt = Date.now();
                    continue;
                }

                const status = response.status;

                if (status === 401 || status === 419) {
                    // Session expired — stop now so the user sees login again.
                    break;
                }

                if (status === 403 || status === 404 || status === 422) {
                    // Permanent failures. Leave the record for manual action
                    // but record the error so the UI can surface it.
                    record.lastError = `HTTP ${status}`;
                    record.attempts = (record.attempts ?? 0) + 1;
                    record.nextAttemptAt = Number.MAX_SAFE_INTEGER;
                    await queue.update(record);
                    continue;
                }

                // Retryable — 5xx or unexpected codes
                await scheduleRetry(record, `HTTP ${status}`);
            } catch (err) {
                await scheduleRetry(record, err.message || 'network error');
            }
        }
    } finally {
        isFlushing = false;
        notify();
        window.dispatchEvent(new CustomEvent('driver-queue-changed'));
    }
}

async function scheduleRetry(record, error) {
    record.attempts = (record.attempts ?? 0) + 1;
    record.lastError = error;
    const stepIdx = Math.min(record.attempts - 1, BACKOFF_STEPS_MS.length - 1);
    record.nextAttemptAt = Date.now() + BACKOFF_STEPS_MS[stepIdx];
    await queue.update(record);
}

async function requestBackgroundSync() {
    if (!('serviceWorker' in navigator)) return;
    try {
        const reg = await navigator.serviceWorker.ready;
        if (reg && reg.sync) {
            await reg.sync.register('driver-upload-queue');
        }
    } catch (e) { /* browser may not support Background Sync */ }
}

function startPolling() {
    if (pollTimer) return;
    pollTimer = setInterval(async () => {
        const count = await queue.countPending();
        if (count === 0) {
            clearInterval(pollTimer);
            pollTimer = null;
            return;
        }
        flush();
    }, POLL_INTERVAL_MS);
}

export function init() {
    window.addEventListener('online', () => flush());
    window.addEventListener('focus', () => flush());
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') flush();
    });
    window.addEventListener('driver-capture-enqueued', () => {
        flush();
        requestBackgroundSync();
        startPolling();
    });

    if (navigator.serviceWorker) {
        navigator.serviceWorker.addEventListener('message', (event) => {
            if (event.data && event.data.type === 'driver-flush-queue') flush();
        });
    }

    flush();
    startPolling();
}
