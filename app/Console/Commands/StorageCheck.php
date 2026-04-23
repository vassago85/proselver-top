<?php

namespace App\Console\Commands;

use App\Support\StorageDisk;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * End-to-end smoke test for a filesystem disk.
 *
 * Writes a tiny probe file, reads it back, verifies the content round-trips,
 * then deletes it. Prints clear per-step diagnostics so an operator doing
 * `docker compose exec app php artisan storage:check` after a deploy can
 * tell at a glance whether R2 credentials actually work — instead of
 * waiting for the first driver photo upload to silently fail.
 *
 * Usage:
 *   php artisan storage:check                # the currently-resolved upload disk
 *   php artisan storage:check --disk=r2      # a specific disk
 *   php artisan storage:check --disk=local --all   # all known disks in sequence
 */
class StorageCheck extends Command
{
    protected $signature = 'storage:check
        {--disk= : Disk name to test (defaults to the resolved upload disk)}
        {--all : Test every configured disk in sequence}';

    protected $description = 'Smoke-test that a filesystem disk can write, read and delete a probe file.';

    public function handle(): int
    {
        $disks = $this->resolveDisksToTest();

        if (empty($disks)) {
            $this->error('No disks to test.');
            return self::FAILURE;
        }

        $allOk = true;
        foreach ($disks as $disk) {
            $this->newLine();
            $this->line("→ Testing disk <comment>{$disk}</comment>");
            $ok = $this->runOne($disk);
            $allOk = $allOk && $ok;
        }

        $this->newLine();
        if ($allOk) {
            $this->info('All storage checks passed.');
            return self::SUCCESS;
        }

        $this->error('One or more storage checks FAILED. See output above.');
        return self::FAILURE;
    }

    /**
     * Decide which disk(s) to test based on the flags and defaults.
     * Excludes backup disks from --all because their creds live in a
     * different env namespace and a false-positive failure there is
     * noisy; run them explicitly with --disk=r2-backup.
     *
     * @return array<int, string>
     */
    private function resolveDisksToTest(): array
    {
        if ($this->option('all')) {
            return array_values(array_filter(
                array_keys(config('filesystems.disks', [])),
                fn ($d) => $d !== 'r2-backup'
            ));
        }

        $requested = $this->option('disk');
        if ($requested) {
            return [$requested];
        }

        return [StorageDisk::forUploads()];
    }

    private function runOne(string $disk): bool
    {
        if (!array_key_exists($disk, config('filesystems.disks', []))) {
            $this->error("  disk '{$disk}' is not configured in config/filesystems.php");
            return false;
        }

        $driver = config("filesystems.disks.{$disk}.driver");
        $this->line("  driver: <comment>{$driver}</comment>");

        if ($driver === 's3' && !StorageDisk::isRemoteDiskReady($disk)) {
            $this->error('  s3-style disk missing required credentials / bucket / endpoint — see config/filesystems.php');
            return false;
        }

        $probePath = 'storage-check/' . now()->format('Y-m-d') . '/' . Str::uuid() . '.txt';
        $probeBody = 'probe:' . now()->toIso8601String();

        try {
            // 1) WRITE
            $t0 = microtime(true);
            Storage::disk($disk)->put($probePath, $probeBody);
            $writeMs = (int) round((microtime(true) - $t0) * 1000);
            $this->line("  ✓ write  ({$writeMs} ms) → {$probePath}");

            // 2) READ
            $t0 = microtime(true);
            $readBack = Storage::disk($disk)->get($probePath);
            $readMs = (int) round((microtime(true) - $t0) * 1000);
            if ($readBack !== $probeBody) {
                $this->error('  ✗ read-back content mismatch — file corrupted or disk misrouting');
                return false;
            }
            $this->line("  ✓ read   ({$readMs} ms)");

            // 3) EXISTS (sanity)
            if (!Storage::disk($disk)->exists($probePath)) {
                $this->error('  ✗ exists() returned false right after write — disk is not persisting');
                return false;
            }
            $this->line('  ✓ exists');

            // 4) DELETE
            $t0 = microtime(true);
            Storage::disk($disk)->delete($probePath);
            $deleteMs = (int) round((microtime(true) - $t0) * 1000);
            $this->line("  ✓ delete ({$deleteMs} ms)");

            if (Storage::disk($disk)->exists($probePath)) {
                $this->error('  ✗ file still exists after delete — permission or eventual-consistency issue');
                return false;
            }

            $this->info("  OK — disk '{$disk}' is healthy.");
            return true;
        } catch (Throwable $e) {
            $this->error('  ✗ ' . get_class($e) . ': ' . $e->getMessage());
            if ($this->getOutput()->isVerbose()) {
                $this->line($e->getTraceAsString());
            }
            // Best-effort cleanup so we don't leave probes behind if read fails.
            try {
                Storage::disk($disk)->delete($probePath);
            } catch (Throwable) {
                // nothing to do — the whole disk is already unhappy.
            }
            return false;
        }
    }
}
