<?php

namespace App\Console\Commands;

use App\Models\SystemSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BackupDatabase extends Command
{
    protected $signature = 'backup:run';
    protected $description = 'Create encrypted PostgreSQL backup and upload to R2 backup bucket';

    /**
     * Encryption password resolution order:
     *   1. system_settings.backup_encryption_password   (set in Admin → Settings → Storage)
     *   2. config('app.key')                            (legacy default — kept so dumps
     *                                                    encrypted before the dashboard
     *                                                    field existed remain decryptable)
     *
     * Decoupling the backup password from APP_KEY means rotating the app key
     * (e.g. after a staff change) doesn't lock you out of historical dumps,
     * and operators can hand a single password to a restore engineer without
     * exposing the live application secret.
     */
    public function handle(): int
    {
        $this->info('Starting database backup...');

        // pg_dump on a large database can run for minutes; PHP's default
        // 30s limit would kill it midway and leave a half-written .sql on disk.
        @set_time_limit(0);

        $localPath     = null;
        $encryptedPath = null;

        try {
            $timestamp         = now()->format('Y-m-d_His');
            $filename          = "backup_{$timestamp}.sql";
            $encryptedFilename = "{$filename}.enc";
            $localPath         = storage_path("app/backups/{$filename}");
            $encryptedPath     = storage_path("app/backups/{$encryptedFilename}");

            if (!is_dir(storage_path('app/backups'))) {
                mkdir(storage_path('app/backups'), 0755, true);
            }

            $host     = (string) config('database.connections.pgsql.host');
            $port     = (string) config('database.connections.pgsql.port');
            $database = (string) config('database.connections.pgsql.database');
            $username = (string) config('database.connections.pgsql.username');
            $password = (string) config('database.connections.pgsql.password');

            // Pass the DB password via environment, never on the command line.
            // (Same idea as the openssl step below: anything in the argv shows
            // up in `ps` and process audit logs.)
            putenv("PGPASSWORD={$password}");

            $dumpCmd = sprintf(
                'pg_dump -h %s -p %s -U %s -d %s -F c -f %s 2>&1',
                escapeshellarg($host),
                escapeshellarg($port),
                escapeshellarg($username),
                escapeshellarg($database),
                escapeshellarg($localPath),
            );

            exec($dumpCmd, $dumpOutput, $exitCode);

            if ($exitCode !== 0) {
                $this->error('pg_dump failed with exit code ' . $exitCode);
                Log::error('Database backup failed at pg_dump stage', [
                    'exit_code' => $exitCode,
                    'output'    => $dumpOutput,
                ]);
                return Command::FAILURE;
            }

            // Resolve encryption password — operator-set value takes precedence
            // over APP_KEY so changing the app key doesn't break historical
            // backups (those decrypt with whatever password was active at the
            // time of dump).
            $encryptionPassword = (string) (SystemSetting::get('backup_encryption_password', null) ?: config('app.key'));

            if ($encryptionPassword === '') {
                $this->error('No backup encryption password is configured (system_settings.backup_encryption_password is blank and APP_KEY is empty).');
                Log::error('Database backup failed: no encryption password available');
                return Command::FAILURE;
            }

            // openssl reads the password from BACKUP_ENC_PASS in the environment
            // (`-pass env:VAR`), keeping it off the command line. This matters:
            // pre-existing code used `-pass pass:{$key}` which leaked the key
            // to anyone who could run `ps` on the host.
            putenv("BACKUP_ENC_PASS={$encryptionPassword}");

            $encryptCmd = sprintf(
                'openssl enc -aes-256-cbc -salt -pbkdf2 -in %s -out %s -pass env:BACKUP_ENC_PASS 2>&1',
                escapeshellarg($localPath),
                escapeshellarg($encryptedPath),
            );

            exec($encryptCmd, $encryptOutput, $exitCode);

            // Wipe the password from the environment as soon as openssl is done
            // so it doesn't linger for any later child process.
            putenv('BACKUP_ENC_PASS');

            if ($exitCode !== 0) {
                $this->error('Encryption failed');
                Log::error('Database backup failed at encryption stage', [
                    'exit_code' => $exitCode,
                    'output'    => $encryptOutput,
                ]);
                @unlink($localPath);
                return Command::FAILURE;
            }

            // Done with the plaintext dump.
            @unlink($localPath);
            $localPath = null;

            // Capture size BEFORE upload+unlink — previously the log line ran
            // `filesize($encryptedPath)` after the file was deleted, which silently
            // logged 0 (filesize() returns false on missing files, and false ?? 0
            // is false, not 0 — so the field was misleading either way).
            $sizeBytes = (int) (@filesize($encryptedPath) ?: 0);

            $disk       = Storage::disk('r2-backup');
            $remotePath = "daily/{$encryptedFilename}";
            $disk->put($remotePath, file_get_contents($encryptedPath));

            @unlink($encryptedPath);
            $encryptedPath = null;

            $this->info("Backup uploaded: {$remotePath} ({$sizeBytes} bytes)");
            Log::info('Database backup completed', [
                'path'         => $remotePath,
                'size'         => $sizeBytes,
                'password_src' => SystemSetting::get('backup_encryption_password', null) ? 'system_settings' : 'app_key',
            ]);

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Backup failed: ' . $e->getMessage());
            Log::error('Database backup exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return Command::FAILURE;
        } finally {
            // Defensive: never leave plaintext or encrypted dumps lying around
            // on the local disk if we crashed mid-flight. R2 is the only
            // intended persistence target.
            if ($localPath && is_file($localPath)) {
                @unlink($localPath);
            }
            if ($encryptedPath && is_file($encryptedPath)) {
                @unlink($encryptedPath);
            }
            putenv('PGPASSWORD');
            putenv('BACKUP_ENC_PASS');
        }
    }
}
