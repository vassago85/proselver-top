<?php

namespace App\Services;

use App\Exceptions\PodDiskUnavailableException;
use App\Models\Job;
use App\Models\JobDocument;
use App\Models\User;
use App\Support\StorageDisk;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes a proof of delivery onto the dedicated pods disk.
 *
 * Layout is {job number}/{VIN}/{timestamp}-{filename} so the file can
 * be found on the mounted disk by either value, without opening the app.
 * A job with no VIN is filed under "no-vin". The job_documents row still
 * points at the job, which is what the Documents search uses.
 */
class PodStore
{
    /**
     * @param  array{client_uuid?: ?string, captured_at?: mixed, latitude?: mixed, longitude?: mixed, notes?: ?string}  $extra
     */
    public function store(Job $job, UploadedFile $file, ?User $uploader, array $extra = []): JobDocument
    {
        $disk = StorageDisk::forPods();
        $directory = $this->directory($job);
        $filename = now()->format('Ymd-His').'-'.Str::lower(Str::random(4)).'-'.$this->segment(
            $file->getClientOriginalName() ?: 'pod',
            'pod'
        );

        try {
            $path = $file->storeAs($directory, $filename, $disk);
        } catch (Throwable $e) {
            Log::error('POD disk write failed', [
                'job_id' => $job->id,
                'disk' => $disk,
                'error' => $e->getMessage(),
            ]);
            throw new PodDiskUnavailableException(
                'The POD disk is not available. Check that the extra disk is mounted.'
            );
        }

        if ($path === false || $path === '') {
            throw new PodDiskUnavailableException(
                'The POD disk is not available. Check that the extra disk is mounted.'
            );
        }

        $realPath = $file->getRealPath();
        $mime = $realPath && is_file($realPath)
            ? (@mime_content_type($realPath) ?: $file->getMimeType())
            : $file->getMimeType();
        $sizeBytes = $realPath && is_file($realPath)
            ? (@filesize($realPath) ?: $file->getSize())
            : $file->getSize();

        return JobDocument::create([
            'job_id' => $job->id,
            'uploaded_by_user_id' => $uploader?->id,
            'category' => JobDocument::CATEGORY_POD,
            'disk' => $disk,
            'path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $mime,
            'size_bytes' => $sizeBytes,
            'file_hash' => $realPath && is_file($realPath) ? hash_file('sha256', $realPath) : null,
            'client_uuid' => $extra['client_uuid'] ?? null,
            'captured_at' => $extra['captured_at'] ?? null,
            'latitude' => $extra['latitude'] ?? null,
            'longitude' => $extra['longitude'] ?? null,
            'notes' => $extra['notes'] ?? null,
        ]);
    }

    /**
     * Relative directory on the pods disk: "{job number}/{vin}".
     */
    public function directory(Job $job): string
    {
        return $this->segment($job->job_number ?: (string) $job->id, 'job')
            .'/'.$this->segment($job->vin ?: 'no-vin', 'no-vin');
    }

    /**
     * Keep path segments to characters that are safe on the mounted disk.
     */
    private function segment(string $value, string $fallback): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($value)) ?? '';
        $clean = trim($clean, '-.');

        return $clean !== '' ? $clean : $fallback;
    }
}
