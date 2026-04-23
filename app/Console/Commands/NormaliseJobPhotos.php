<?php

namespace App\Console\Commands;

use App\Models\JobDocument;
use App\Services\ImageNormalizer;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * One-off (or occasional) backfill: re-run the image normaliser over
 * JobDocuments that were uploaded BEFORE orientation handling + EXIF
 * stripping was added. Without this, phone shots that were already in
 * R2 still render sideways because their raw bytes never passed through
 * the normaliser.
 *
 * Safe to re-run — normaliser is idempotent.
 *
 * Examples:
 *   php artisan photos:normalise
 *   php artisan photos:normalise --job=42
 *   php artisan photos:normalise --category=damage_photo --dry-run
 */
class NormaliseJobPhotos extends Command
{
    protected $signature = 'photos:normalise
        {--job= : Restrict to a single job id}
        {--category= : Restrict to one JobDocument category (e.g. damage_photo)}
        {--dry-run : Report what would happen without rewriting anything}';

    protected $description = 'Re-encode job photos with correct EXIF orientation, stripped metadata, and downscaled dimensions.';

    public function handle(ImageNormalizer $normaliser): int
    {
        $query = JobDocument::query()
            ->whereIn('mime_type', ['image/jpeg', 'image/jpg', 'image/png', 'image/webp']);

        if ($jobId = $this->option('job')) {
            $query->where('job_id', (int) $jobId);
        }
        if ($category = $this->option('category')) {
            $query->where('category', $category);
        }

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->info('No photos match the filter.');
            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $this->info(sprintf('Processing %d photo(s)%s...', $total, $dryRun ? ' [DRY RUN]' : ''));

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $processed = $skipped = $failed = 0;

        $query->orderBy('id')->chunkById(100, function ($rows) use ($normaliser, $dryRun, $bar, &$processed, &$skipped, &$failed) {
            foreach ($rows as $doc) {
                try {
                    $result = $this->processOne($doc, $normaliser, $dryRun);
                    if ($result === 'processed') {
                        $processed++;
                    } elseif ($result === 'skipped') {
                        $skipped++;
                    } else {
                        $failed++;
                    }
                } catch (Throwable $e) {
                    $failed++;
                    $this->newLine();
                    $this->warn("Document {$doc->id}: {$e->getMessage()}");
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info(sprintf('Done. processed=%d skipped=%d failed=%d', $processed, $skipped, $failed));

        return self::SUCCESS;
    }

    /**
     * Download the remote object to a temp file, run the normaliser
     * against it, and (unless dry-run) push the normalised bytes back
     * to the same path on the same disk. The file hash and size are
     * refreshed on the row so we don't mislead anyone looking at the
     * documents list.
     */
    protected function processOne(JobDocument $doc, ImageNormalizer $normaliser, bool $dryRun): string
    {
        $disk = Storage::disk($doc->disk);
        if (!$disk->exists($doc->path)) {
            return 'skipped';
        }

        $bytes = $disk->get($doc->path);
        if ($bytes === null || $bytes === '') {
            return 'skipped';
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'img_norm_');
        if ($tmpPath === false) {
            return 'failed';
        }

        try {
            file_put_contents($tmpPath, $bytes);

            // Wrap the temp file as a fake UploadedFile so we can reuse
            // the normaliser as-is without branching on a separate code
            // path. The "test" fourth arg disables move semantics.
            $fake = new UploadedFile(
                $tmpPath,
                $doc->original_filename ?: basename($doc->path),
                $doc->mime_type ?: 'application/octet-stream',
                null,
                true
            );

            $changed = $normaliser->normalise($fake);
            if (!$changed) {
                return 'skipped';
            }

            if ($dryRun) {
                return 'processed';
            }

            // Push the rewritten bytes back to the original disk path.
            $newBytes = file_get_contents($tmpPath);
            if ($newBytes === false) {
                return 'failed';
            }
            $disk->put($doc->path, $newBytes);

            // Update metadata so the row reflects the new file.
            $doc->size_bytes = strlen($newBytes);
            $doc->mime_type  = 'image/jpeg';
            $doc->file_hash  = hash('sha256', $newBytes);
            $doc->save();

            return 'processed';
        } finally {
            @unlink($tmpPath);
        }
    }
}
