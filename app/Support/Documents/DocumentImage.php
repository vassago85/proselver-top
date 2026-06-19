<?php

namespace App\Support\Documents;

use Illuminate\Support\Facades\Storage;

/**
 * Small helper for embedding raster images into Dompdf-rendered
 * documents as base64 data URIs.
 *
 * Dompdf's SVG renderer is patchy and remote fetch is disabled in
 * the PDF pipeline (isRemoteEnabled = false), so every logo /
 * diagram / QR has to be inlined as a data URI.  Both
 * CollectionNoteService and SaleDeliveryNoteService use this so the
 * embedding logic lives in exactly one place.
 */
class DocumentImage
{
    /**
     * Build a data URI from a local filesystem path (e.g.
     * public_path('proselverlogo-2.png')).  Returns null when the
     * file is missing or unreadable so callers fall back cleanly.
     */
    public static function fromLocalPath(?string $path): ?string
    {
        if (!$path || !is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        return self::encode($contents, pathinfo($path, PATHINFO_EXTENSION));
    }

    /**
     * Build a data URI from a file stored on a Laravel storage disk
     * (defaults to the configured default disk -- local in dev,
     * R2/S3 in prod).  Used for company-uploaded logos which live
     * on the same disk as job_documents.  Returns null on any
     * failure so the masthead degrades to text-only.
     */
    public static function fromDisk(?string $path, ?string $disk = null): ?string
    {
        if (!$path) {
            return null;
        }

        $disk = $disk ?: (string) config('filesystems.default');

        try {
            $storage = Storage::disk($disk);
            if (!$storage->exists($path)) {
                return null;
            }
            $contents = $storage->get($path);
        } catch (\Throwable) {
            return null;
        }

        if ($contents === null || $contents === false) {
            return null;
        }

        return self::encode($contents, pathinfo($path, PATHINFO_EXTENSION));
    }

    protected static function encode(string $contents, string $extension): string
    {
        $mime = match (strtolower($extension)) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'gif'         => 'image/gif',
            'webp'        => 'image/webp',
            default       => 'image/png',
        };

        return 'data:' . $mime . ';base64,' . base64_encode($contents);
    }
}
