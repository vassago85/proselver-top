<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BackupDatabase extends Command
{
    protected $signature = 'backup:run';
    protected $description = 'Create encrypted PostgreSQL backup and upload to R2 backup bucket';

    public function handle(): int
    {
        $this->info('Starting database backup...');

        try {
            $timestamp = now()->format('Y-m-d_His');
            $filename = "backup_{$timestamp}.sql";
            $encryptedFilename = "{$filename}.enc";
            $localPath = storage_path("app/backups/{$filename}");
            $encryptedPath = storage_path("app/backups/{$encryptedFilename}");

            if (!is_dir(storage_path('app/backups'))) {
                mkdir(storage_path('app/backups'), 0755, true);
            }

            $host = config('database.connections.pgsql.host');
            $port = config('database.connections.pgsql.port');
            $database = config('database.connections.pgsql.database');
            $username = config('database.connections.pgsql.username');
            $password = config('database.connections.pgsql.password');

            putenv("PGPASSWORD={$password}");
            $dumpCmd = "pg_dump -h {$host} -p {$port} -U {$username} -d {$database} -F c -f {$localPath}";
            exec($dumpCmd, $output, $exitCode);

            if ($exitCode !== 0) {
                $this->error('pg_dump failed with exit code ' . $exitCode);
                Log::error('Database backup failed', ['exit_code' => $exitCode, 'output' => $output]);
                return Command::FAILURE;
            }

            $key = config('app.key');
            $encryptCmd = "openssl enc -aes-256-cbc -salt -pbkdf2 -in {$localPath} -out {$encryptedPath} -pass pass:{$key}";
            exec($encryptCmd, $output, $exitCode);

            if ($exitCode !== 0) {
                $this->error('Encryption failed');
                @unlink($localPath);
                return Command::FAILURE;
            }

            @unlink($localPath);

            $disk = Storage::disk('r2-backup');
            $remotePath = "daily/{$encryptedFilename}";
            $disk->put($remotePath, file_get_contents($encryptedPath));

            @unlink($encryptedPath);

            $this->info("Backup uploaded: {$remotePath}");
            Log::info('Database backup completed', ['path' => $remotePath, 'size' => filesize($encryptedPath) ?? 0]);

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Backup failed: ' . $e->getMessage());
            Log::error('Database backup exception', ['error' => $e->getMessage()]);
            return Command::FAILURE;
        }
    }
}
