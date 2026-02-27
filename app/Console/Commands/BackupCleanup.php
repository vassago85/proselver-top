<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class BackupCleanup extends Command
{
    protected $signature = 'backup:cleanup';
    protected $description = 'Clean up old backups (keep 7 daily, 4 weekly, 3 monthly)';

    public function handle(): int
    {
        $disk = Storage::disk('r2-backup');

        try {
            $files = $disk->files('daily');
            usort($files, fn($a, $b) => strcmp($b, $a));

            $keep = 7;
            $toDelete = array_slice($files, $keep);

            foreach ($toDelete as $file) {
                $disk->delete($file);
                $this->line("Deleted: {$file}");
            }

            $this->info('Cleanup complete. Kept ' . min(count($files), $keep) . ' backups, deleted ' . count($toDelete) . '.');
        } catch (\Throwable $e) {
            $this->error('Cleanup failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
