<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Single source of truth for picking the right filesystem disk for uploads.
 *
 * Before this helper, every upload site copy-pasted:
 *     $disk = config('filesystems.default') === 'local' ? 'local' : 'r2';
 * That hard-codes R2 regardless of what was actually configured, gives zero
 * feedback when credentials are missing (files silently go into the
 * ephemeral container volume), and has to be kept in sync across 6+ files.
 *
 * This class centralises three concerns:
 *   1. Honour FILESYSTEM_DISK if it's set AND the target disk is properly
 *      configured (bucket + credentials present for s3/r2 style drivers).
 *   2. Fall back to `local` with a log warning when the requested remote
 *      disk isn't configured, so dev can still smoke-test uploads without
 *      R2 credentials but operators see the warning in laravel.log.
 *   3. Give one place to change the policy later (e.g. route POD photos to
 *      a different bucket from petty-cash slips).
 *
 * Usage:
 *   $disk = StorageDisk::forUploads();
 *   $path = $file->store('jobs/'.$job->uuid.'/documents', $disk);
 *
 * Later reads must still use the `disk` column stored alongside the file,
 * because a file uploaded to `local` when R2 was misconfigured should be
 * read back from `local` even after R2 comes online.
 */
class StorageDisk
{
    /**
     * Cached result so we don't re-log the "falling back" warning on every
     * call within a single request.
     */
    protected static ?string $cachedUploadDisk = null;

    /**
     * Pick the disk to use for new uploads. Returns one of the keys defined
     * in config('filesystems.disks').
     */
    public static function forUploads(): string
    {
        if (self::$cachedUploadDisk !== null) {
            return self::$cachedUploadDisk;
        }

        $requested = (string) config('filesystems.default', 'local');

        if ($requested === 'local' || $requested === 'public') {
            return self::$cachedUploadDisk = $requested;
        }

        if (self::isRemoteDiskReady($requested)) {
            return self::$cachedUploadDisk = $requested;
        }

        // Misconfigured remote disk — never silently burn user data into an
        // ephemeral volume without telling anyone.
        Log::warning("StorageDisk: FILESYSTEM_DISK='{$requested}' is missing credentials; falling back to 'local'. Uploads will not survive a container rebuild.");

        return self::$cachedUploadDisk = 'local';
    }

    /**
     * Public assets (app logos, driver PWA icons) live on the `public` disk
     * so they can be served with a plain static URL. Pinned here so callers
     * don't have to care whether the default remote disk is configured.
     */
    public static function forPublicAssets(): string
    {
        return 'public';
    }

    /**
     * Returns true when the given disk looks fully configured. For s3-style
     * drivers that means bucket + key + secret + endpoint are all set. For
     * local disks this is always true.
     *
     * Kept intentionally conservative: we only consider a disk "ready" when
     * every required knob is populated. Better to fall back to local and
     * log a warning than to 500 on every upload.
     */
    public static function isRemoteDiskReady(string $disk): bool
    {
        $cfg = config("filesystems.disks.{$disk}");
        if (!is_array($cfg)) {
            return false;
        }

        $driver = $cfg['driver'] ?? null;

        if ($driver === 'local') {
            return true;
        }

        if ($driver === 's3') {
            $required = ['key', 'secret', 'bucket', 'region'];
            foreach ($required as $k) {
                if (empty($cfg[$k])) {
                    return false;
                }
            }
            // R2-style (non-AWS) deployments also need an explicit endpoint.
            // Pure AWS S3 allows endpoint to be null.
            if (($disk === 'r2' || $disk === 'r2-backup') && empty($cfg['endpoint'])) {
                return false;
            }
            return true;
        }

        return false;
    }

    /**
     * Reset the cached disk decision. Meant for use in tests only — callers
     * in normal request flow should never need to touch this.
     */
    public static function flushCache(): void
    {
        self::$cachedUploadDisk = null;
    }
}
