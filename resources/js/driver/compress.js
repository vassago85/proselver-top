/**
 * Client-side image compression for the driver PWA.
 *
 * Targets entry-level Android phones with tight storage and flaky data.
 * Shrinks the longest edge to 1200px and re-encodes as JPEG at 70% quality.
 * A typical 4MP camera frame drops from ~3MB to 150-300KB.
 */

const DEFAULT_MAX_EDGE = 1200;
const DEFAULT_QUALITY = 0.7;

/**
 * Compress a File/Blob (image) to a JPEG Blob. Non-image inputs are returned
 * unchanged so the caller can queue PDFs and other captures unmodified.
 *
 * @param {File|Blob} file
 * @param {{ maxEdge?: number, quality?: number }} [opts]
 * @returns {Promise<Blob>}
 */
export async function compressImage(file, opts = {}) {
    const maxEdge = opts.maxEdge ?? DEFAULT_MAX_EDGE;
    const quality = opts.quality ?? DEFAULT_QUALITY;

    if (!file || !file.type || !file.type.startsWith('image/')) {
        return file;
    }

    const dataUrl = await readAsDataURL(file);
    const img = await loadImage(dataUrl);

    let { width, height } = img;
    if (width <= maxEdge && height <= maxEdge) {
        const passthroughBlob = await encodeToJpeg(img, width, height, quality);
        return passthroughBlob.size < file.size ? passthroughBlob : file;
    }

    if (width >= height) {
        height = Math.round(height * (maxEdge / width));
        width = maxEdge;
    } else {
        width = Math.round(width * (maxEdge / height));
        height = maxEdge;
    }

    return encodeToJpeg(img, width, height, quality);
}

function readAsDataURL(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(reader.result);
        reader.onerror = () => reject(reader.error);
        reader.readAsDataURL(file);
    });
}

function loadImage(src) {
    return new Promise((resolve, reject) => {
        const img = new Image();
        img.onload = () => resolve(img);
        img.onerror = () => reject(new Error('Failed to decode captured image'));
        img.src = src;
    });
}

function encodeToJpeg(img, width, height, quality) {
    return new Promise((resolve, reject) => {
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const ctx = canvas.getContext('2d');
        if (!ctx) {
            reject(new Error('Canvas 2D unavailable'));
            return;
        }
        ctx.drawImage(img, 0, 0, width, height);
        canvas.toBlob(
            (blob) => (blob ? resolve(blob) : reject(new Error('Canvas toBlob failed'))),
            'image/jpeg',
            quality
        );
    });
}
