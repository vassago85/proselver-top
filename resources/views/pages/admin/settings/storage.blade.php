<?php
use App\Models\SystemSetting;
use App\Support\StorageDisk;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

new #[Layout('components.layouts.app')] class extends Component {
    // Default disk selector — drives where new uploads land.
    public string $defaultDisk = 'local';

    // Primary R2 bucket credentials. Secret fields are write-only in the
    // UI: they render empty and only update the stored value when the user
    // explicitly types something in.
    public string $r2AccessKeyId     = '';
    public string $r2SecretAccessKey = '';
    public string $r2Region          = 'auto';
    public string $r2Bucket          = '';
    public string $r2Endpoint        = '';

    public bool $hasR2Secret    = false;
    public bool $hasR2ConfigAll = false;

    // Backup R2 bucket — used by the nightly DB dump job. Kept in a separate
    // account/bucket so a compromised primary credential can't wipe backups.
    public string $r2BackupAccessKeyId     = '';
    public string $r2BackupSecretAccessKey = '';
    public string $r2BackupRegion          = 'auto';
    public string $r2BackupBucket          = '';
    public string $r2BackupEndpoint        = '';

    public bool $hasR2BackupSecret    = false;
    public bool $hasR2BackupConfigAll = false;

    // Backup encryption password — used by `backup:run` to AES-256-CBC the
    // pg_dump before it leaves the host. Decoupled from APP_KEY so rotating
    // the app key doesn't lock anyone out of historical backups.
    public string $backupEncryptionPassword = '';
    public bool   $hasBackupEncryptionPassword = false;

    public function mount(): void
    {
        abort_unless(auth()->user()?->isDeveloper() || auth()->user()?->isSuperAdmin(),
            403, 'You may not manage storage settings.');

        $this->refreshFromDatabase();
    }

    protected function refreshFromDatabase(): void
    {
        // Pull the system-settings values (falling back to whatever env
        // currently has in filesystems.php if a setting is missing) so the
        // form faithfully reflects what the resolver is actually using.
        $this->defaultDisk = (string) SystemSetting::get('storage_default_disk', config('filesystems.default', 'local'));

        $this->r2AccessKeyId = (string) SystemSetting::get('r2_access_key_id', config('filesystems.disks.r2.key', ''));
        $this->r2Region      = (string) (SystemSetting::get('r2_region', config('filesystems.disks.r2.region', 'auto')) ?: 'auto');
        $this->r2Bucket      = (string) SystemSetting::get('r2_bucket',    config('filesystems.disks.r2.bucket', ''));
        $this->r2Endpoint    = (string) SystemSetting::get('r2_endpoint',  config('filesystems.disks.r2.endpoint', ''));
        $this->r2SecretAccessKey = '';
        $this->hasR2Secret    = (bool) SystemSetting::get('r2_secret_access_key', config('filesystems.disks.r2.secret', ''));
        $this->hasR2ConfigAll = StorageDisk::isRemoteDiskReady('r2');

        $this->r2BackupAccessKeyId = (string) SystemSetting::get('r2_backup_access_key_id', config('filesystems.disks.r2-backup.key', ''));
        $this->r2BackupRegion      = (string) (SystemSetting::get('r2_backup_region', config('filesystems.disks.r2-backup.region', 'auto')) ?: 'auto');
        $this->r2BackupBucket      = (string) SystemSetting::get('r2_backup_bucket',    config('filesystems.disks.r2-backup.bucket', ''));
        $this->r2BackupEndpoint    = (string) SystemSetting::get('r2_backup_endpoint',  config('filesystems.disks.r2-backup.endpoint', ''));
        $this->r2BackupSecretAccessKey = '';
        $this->hasR2BackupSecret    = (bool) SystemSetting::get('r2_backup_secret_access_key', config('filesystems.disks.r2-backup.secret', ''));
        $this->hasR2BackupConfigAll = StorageDisk::isRemoteDiskReady('r2-backup');

        // Encryption password is write-only in the UI: re-rendering an existing
        // value into the form would let any logged-in admin lift it just by
        // viewing the page source.
        $this->backupEncryptionPassword    = '';
        $this->hasBackupEncryptionPassword = (bool) SystemSetting::get('backup_encryption_password', '');
    }

    // --- SAVE ACTIONS -------------------------------------------------------

    public function saveDefault(): void
    {
        $this->validate(['defaultDisk' => 'required|in:local,r2,s3']);

        if ($this->defaultDisk !== 'local' && !StorageDisk::isRemoteDiskReady($this->defaultDisk)) {
            session()->flash('error', "Cannot switch default to '{$this->defaultDisk}' — its credentials are incomplete. Save the bucket credentials first.");
            return;
        }

        SystemSetting::set('storage_default_disk', $this->defaultDisk, 'string', 'Default filesystem disk for uploads');

        $this->forgetDiskCaches();
        session()->flash('success', "Default upload disk set to '{$this->defaultDisk}'. New uploads will route there immediately.");
        $this->refreshFromDatabase();
    }

    public function savePrimary(): void
    {
        $this->validate([
            'r2AccessKeyId' => 'required|string|max:255',
            'r2Region'      => 'required|string|max:64',
            'r2Bucket'      => 'required|string|max:255',
            'r2Endpoint'    => 'required|url|max:255',
        ]);

        SystemSetting::set('r2_access_key_id', $this->r2AccessKeyId, 'string', 'Cloudflare R2 access key id');
        SystemSetting::set('r2_region',        $this->r2Region,      'string', 'Cloudflare R2 region (usually auto)');
        SystemSetting::set('r2_bucket',        $this->r2Bucket,      'string', 'Cloudflare R2 primary bucket');
        SystemSetting::set('r2_endpoint',      $this->r2Endpoint,    'string', 'Cloudflare R2 S3 endpoint URL');

        // Only write the secret if the user actually typed something in —
        // otherwise a blind "Save" after page load would wipe the stored
        // key. This is the same write-only pattern we use for Google Maps.
        if ($this->r2SecretAccessKey !== '') {
            SystemSetting::set('r2_secret_access_key', $this->r2SecretAccessKey, 'string', 'Cloudflare R2 secret access key');
        } elseif (!$this->hasR2Secret) {
            $this->addError('r2SecretAccessKey', 'Secret access key is required on first save.');
            return;
        }

        $this->forgetDiskCaches();
        session()->flash('success', 'Primary R2 credentials saved.');
        $this->refreshFromDatabase();
    }

    public function saveBackup(): void
    {
        $this->validate([
            'r2BackupAccessKeyId' => 'required|string|max:255',
            'r2BackupRegion'      => 'required|string|max:64',
            'r2BackupBucket'      => 'required|string|max:255',
            'r2BackupEndpoint'    => 'required|url|max:255',
        ]);

        SystemSetting::set('r2_backup_access_key_id', $this->r2BackupAccessKeyId, 'string', 'Cloudflare R2 backup access key id');
        SystemSetting::set('r2_backup_region',        $this->r2BackupRegion,      'string', 'Cloudflare R2 backup region (usually auto)');
        SystemSetting::set('r2_backup_bucket',        $this->r2BackupBucket,      'string', 'Cloudflare R2 backup bucket');
        SystemSetting::set('r2_backup_endpoint',      $this->r2BackupEndpoint,    'string', 'Cloudflare R2 backup S3 endpoint URL');

        if ($this->r2BackupSecretAccessKey !== '') {
            SystemSetting::set('r2_backup_secret_access_key', $this->r2BackupSecretAccessKey, 'string', 'Cloudflare R2 backup secret access key');
        } elseif (!$this->hasR2BackupSecret) {
            $this->addError('r2BackupSecretAccessKey', 'Secret access key is required on first save.');
            return;
        }

        $this->forgetDiskCaches();
        session()->flash('success', 'Backup R2 credentials saved.');
        $this->refreshFromDatabase();
    }

    // --- CLEAR --------------------------------------------------------------

    public function clearPrimary(): void
    {
        foreach (['r2_access_key_id','r2_secret_access_key','r2_region','r2_bucket','r2_endpoint'] as $k) {
            SystemSetting::set($k, '', 'string');
        }
        // If r2 was the default, drop back to local so we don't 500 on upload.
        if ($this->defaultDisk === 'r2') {
            SystemSetting::set('storage_default_disk', 'local', 'string', 'Default filesystem disk for uploads');
        }
        $this->forgetDiskCaches();
        session()->flash('success', 'Primary R2 credentials cleared. Default disk reset to local if it was r2.');
        $this->refreshFromDatabase();
    }

    public function clearBackup(): void
    {
        foreach (['r2_backup_access_key_id','r2_backup_secret_access_key','r2_backup_region','r2_backup_bucket','r2_backup_endpoint'] as $k) {
            SystemSetting::set($k, '', 'string');
        }
        $this->forgetDiskCaches();
        session()->flash('success', 'Backup R2 credentials cleared.');
        $this->refreshFromDatabase();
    }

    // --- BACKUP ENCRYPTION --------------------------------------------------

    public function saveBackupEncryption(): void
    {
        $this->validate([
            // 16+ chars is a defensible floor for a symmetric password used to
            // protect a database dump that may contain PII for years. We don't
            // upper-bound it — openssl handles arbitrary lengths.
            'backupEncryptionPassword' => 'nullable|string|min:16|max:1024',
        ], [
            'backupEncryptionPassword.min' => 'Backup password must be at least 16 characters.',
        ]);

        if ($this->backupEncryptionPassword === '') {
            $this->addError('backupEncryptionPassword', 'Type the password you want to use to encrypt future backups.');
            return;
        }

        SystemSetting::set('backup_encryption_password', $this->backupEncryptionPassword, 'string', 'AES-256-CBC password for encrypted DB backups');
        session()->flash('success', 'Backup encryption password saved. New nightly dumps will use this password — store it somewhere safe, it is required to restore.');
        $this->refreshFromDatabase();
    }

    public function clearBackupEncryption(): void
    {
        SystemSetting::set('backup_encryption_password', '', 'string');
        session()->flash('success', 'Backup encryption password cleared. Future backups will fall back to APP_KEY (legacy behaviour).');
        $this->refreshFromDatabase();
    }

    /**
     * Trigger `backup:run` synchronously so the operator gets immediate
     * feedback (DB reachable? encryption password set? R2 backup creds
     * working?). The artisan command itself bumps the time limit, but we
     * also raise it here for the Livewire request handler.
     */
    public function runBackupNow(): void
    {
        abort_unless(auth()->user()?->isDeveloper() || auth()->user()?->isSuperAdmin(),
            403, 'You may not trigger backups.');

        if (!$this->hasR2BackupConfigAll) {
            session()->flash('error', 'Backup R2 bucket is not fully configured — fill in every backup-bucket field and save first.');
            return;
        }

        @set_time_limit(0);

        try {
            $exitCode = Artisan::call('backup:run');
            $output   = trim((string) Artisan::output());

            if ($exitCode === 0) {
                $tail = $output !== '' ? (' Output: ' . Str::limit($output, 240)) : '';
                session()->flash('success', '✓ Backup completed successfully.' . $tail);
            } else {
                $tail = $output !== '' ? (' Last output: ' . Str::limit($output, 400)) : '';
                session()->flash('error', "Backup command exited with code {$exitCode}." . $tail . ' Check storage/logs/laravel.log for the full trace.');
            }
        } catch (\Throwable $e) {
            session()->flash('error', 'Backup threw an exception: ' . class_basename($e) . ' — ' . $e->getMessage());
        }
    }

    // --- TEST ---------------------------------------------------------------

    public function testDisk(string $disk): void
    {
        if (!in_array($disk, ['local','r2','r2-backup','s3'], true)) {
            session()->flash('error', 'Unknown disk: ' . $disk);
            return;
        }

        // Re-apply the settings into config so the test hits what was just saved.
        // (The DB writes above already Cache::forget, but a fresh page load
        // would be the only thing that re-runs AppServiceProvider::boot —
        // here we do it manually.)
        $this->forgetDiskCaches(reapply: true);

        if ($disk !== 'local' && !StorageDisk::isRemoteDiskReady($disk)) {
            session()->flash('error', "'{$disk}' is missing required credentials. Fill in every field and save first.");
            return;
        }

        $probePath = 'storage-check/' . now()->format('Y-m-d') . '/' . Str::uuid() . '.txt';
        $probeBody = 'probe:' . now()->toIso8601String();

        try {
            $t0 = microtime(true);
            Storage::disk($disk)->put($probePath, $probeBody);
            $writeMs = (int) round((microtime(true) - $t0) * 1000);

            $readBack = Storage::disk($disk)->get($probePath);
            if ($readBack !== $probeBody) {
                session()->flash('error', "Write OK but read-back content mismatch on '{$disk}' — corrupted or routed to the wrong bucket.");
                Storage::disk($disk)->delete($probePath);
                return;
            }

            Storage::disk($disk)->delete($probePath);
            session()->flash('success', "✓ '{$disk}' is healthy (write→read→delete in {$writeMs}ms).");
        } catch (\Throwable $e) {
            session()->flash('error', "Test failed on '{$disk}': " . class_basename($e) . ' — ' . $e->getMessage());
        }
    }

    /**
     * Invalidate Laravel's filesystem manager disk cache and the local
     * StorageDisk resolver cache, and (optionally) re-run the config
     * hydration we do in AppServiceProvider so tests inside this same
     * request reflect what was just saved.
     */
    protected function forgetDiskCaches(bool $reapply = false): void
    {
        foreach (['r2','r2-backup','s3','local','public'] as $d) {
            try { app('filesystems')->forgetDisk($d); } catch (\Throwable) {}
        }
        \App\Support\StorageDisk::flushCache();

        if ($reapply) {
            // Re-run JUST the storage-config hydration that AppServiceProvider
            // does at boot. We deliberately don't go through the container
            // here — `app(AppServiceProvider::class)` blows up because the
            // base ServiceProvider's untyped $app parameter is unresolvable.
            \App\Providers\AppServiceProvider::hydrateStorageConfigFromDatabase();
        }
    }

    public function with(): array
    {
        return [
            'resolvedUploadDisk' => StorageDisk::forUploads(),
        ];
    }
};
?>
<div>
    <x-slot:header>Storage Settings</x-slot:header>

    @if(session('success'))
        <div class="mb-4 rounded-lg bg-green-50 border border-green-200 p-4 text-sm text-green-700">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-4 rounded-lg bg-red-50 border border-red-200 p-4 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    <div class="max-w-3xl space-y-6">

        {{-- ─── Current state ─── --}}
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">Current state</h3>
                    <p class="mt-1 text-sm text-gray-500">
                        New uploads are currently routed to the
                        <span class="inline-flex items-center rounded-md border border-gray-200 bg-gray-50 px-1.5 py-0.5 text-xs font-mono font-semibold text-gray-800">{{ $resolvedUploadDisk }}</span> disk.
                    </p>
                </div>
                <div class="shrink-0">
                    @if($resolvedUploadDisk === 'local')
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 text-amber-700 border border-amber-200 px-2.5 py-1 text-xs font-medium">
                            <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>
                            Ephemeral — wiped on container rebuild
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-green-50 text-green-700 border border-green-200 px-2.5 py-1 text-xs font-medium">
                            <span class="h-1.5 w-1.5 rounded-full bg-green-500"></span>
                            Cloud — persistent
                        </span>
                    @endif
                </div>
            </div>

            <div class="mt-5 flex flex-col sm:flex-row sm:items-end gap-3">
                <div class="flex-1">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Default upload disk</label>
                    <select wire:model="defaultDisk" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="local">Local (Docker volume) — use for dev / first-boot</option>
                        <option value="r2">Cloudflare R2 (primary)</option>
                    </select>
                    @error('defaultDisk')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    <p class="mt-1.5 text-xs text-gray-400">Local is fine for development but stored photos are lost on every container rebuild. Configure R2 below before switching.</p>
                </div>
                <button wire:click="saveDefault" class="rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-500">
                    Apply
                </button>
            </div>
        </div>

        {{-- ─── Primary R2 ─── --}}
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <div class="flex items-center gap-3 mb-4">
                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-orange-50 text-orange-600">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.5 19a4.5 4.5 0 1 0 0-9h-1.8A7 7 0 1 0 4 16.7"/><path d="M8 22v-6"/><path d="m5 19 3 3 3-3"/></svg>
                </div>
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">Primary bucket (Cloudflare R2)</h3>
                    <p class="text-xs text-gray-500">All user uploads — POD photos, data plate, fuel/odometer, documents.</p>
                </div>
                <div class="ml-auto">
                    @if($hasR2ConfigAll)
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-green-50 text-green-700 border border-green-200 px-2.5 py-1 text-xs font-medium"><span class="h-1.5 w-1.5 rounded-full bg-green-500"></span>Configured</span>
                    @else
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-gray-50 text-gray-600 border border-gray-200 px-2.5 py-1 text-xs font-medium"><span class="h-1.5 w-1.5 rounded-full bg-gray-400"></span>Not configured</span>
                    @endif
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Endpoint URL</label>
                    <input wire:model="r2Endpoint" type="url" placeholder="https://&lt;account&gt;.r2.cloudflarestorage.com" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm font-mono focus:border-blue-500 focus:ring-blue-500">
                    @error('r2Endpoint')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    <p class="mt-1.5 text-xs text-gray-400">From Cloudflare → R2 → Overview → "S3 API".</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Bucket name</label>
                    <input wire:model="r2Bucket" type="text" placeholder="tcdc-uploads" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm font-mono focus:border-blue-500 focus:ring-blue-500">
                    @error('r2Bucket')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Region</label>
                    <input wire:model="r2Region" type="text" placeholder="auto" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm font-mono focus:border-blue-500 focus:ring-blue-500">
                    @error('r2Region')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    <p class="mt-1.5 text-xs text-gray-400">Leave as "auto" for Cloudflare R2.</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Access key ID</label>
                    <input wire:model="r2AccessKeyId" type="text" autocomplete="off" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm font-mono focus:border-blue-500 focus:ring-blue-500">
                    @error('r2AccessKeyId')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <div x-data="{ show: false }">
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Secret access key
                        @if($hasR2Secret) <span class="text-xs font-normal text-green-600">(currently set)</span> @endif
                    </label>
                    <div class="relative">
                        <input wire:model="r2SecretAccessKey" :type="show ? 'text' : 'password'" autocomplete="off"
                            placeholder="{{ $hasR2Secret ? 'Leave blank to keep current key' : 'Paste your secret access key' }}"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2.5 pr-10 text-sm font-mono focus:border-blue-500 focus:ring-blue-500">
                        <button type="button" @click="show = !show" class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-400 hover:text-gray-600">
                            <svg x-show="!show" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg x-show="show" class="h-5 w-5" x-cloak viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" x2="22" y1="2" y2="22"/></svg>
                        </button>
                    </div>
                    @error('r2SecretAccessKey')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="mt-5 flex items-center justify-between gap-3">
                <div class="flex gap-2">
                    <button wire:click="testDisk('r2')" class="rounded-lg border border-green-300 bg-white px-4 py-2.5 text-sm font-semibold text-green-700 hover:bg-green-50" wire:loading.attr="disabled" wire:target="testDisk">
                        <span wire:loading.remove wire:target="testDisk('r2')">Test connection</span>
                        <span wire:loading wire:target="testDisk('r2')">Testing…</span>
                    </button>
                    @if($hasR2ConfigAll)
                        <button wire:click="clearPrimary" wire:confirm="Remove the primary R2 credentials? New uploads will fall back to the local disk." class="rounded-lg border border-red-300 bg-white px-4 py-2.5 text-sm font-semibold text-red-600 hover:bg-red-50">
                            Remove credentials
                        </button>
                    @endif
                </div>
                <button wire:click="savePrimary" class="rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-500">
                    <span wire:loading.remove wire:target="savePrimary">Save primary bucket</span>
                    <span wire:loading wire:target="savePrimary">Saving…</span>
                </button>
            </div>
        </div>

        {{-- ─── Backup R2 ─── --}}
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <div class="flex items-center gap-3 mb-4">
                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-50 text-blue-600">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/></svg>
                </div>
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">Backup bucket (nightly DB dump)</h3>
                    <p class="text-xs text-gray-500">Separate credentials and bucket so a compromised primary key can't delete backups.</p>
                </div>
                <div class="ml-auto">
                    @if($hasR2BackupConfigAll)
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-green-50 text-green-700 border border-green-200 px-2.5 py-1 text-xs font-medium"><span class="h-1.5 w-1.5 rounded-full bg-green-500"></span>Configured</span>
                    @else
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-gray-50 text-gray-600 border border-gray-200 px-2.5 py-1 text-xs font-medium"><span class="h-1.5 w-1.5 rounded-full bg-gray-400"></span>Not configured</span>
                    @endif
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Endpoint URL</label>
                    <input wire:model="r2BackupEndpoint" type="url" placeholder="https://&lt;account&gt;.r2.cloudflarestorage.com" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm font-mono focus:border-blue-500 focus:ring-blue-500">
                    @error('r2BackupEndpoint')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Bucket name</label>
                    <input wire:model="r2BackupBucket" type="text" placeholder="tcdc-backups" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm font-mono focus:border-blue-500 focus:ring-blue-500">
                    @error('r2BackupBucket')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Region</label>
                    <input wire:model="r2BackupRegion" type="text" placeholder="auto" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm font-mono focus:border-blue-500 focus:ring-blue-500">
                    @error('r2BackupRegion')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Access key ID</label>
                    <input wire:model="r2BackupAccessKeyId" type="text" autocomplete="off" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm font-mono focus:border-blue-500 focus:ring-blue-500">
                    @error('r2BackupAccessKeyId')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <div x-data="{ show: false }">
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Secret access key
                        @if($hasR2BackupSecret) <span class="text-xs font-normal text-green-600">(currently set)</span> @endif
                    </label>
                    <div class="relative">
                        <input wire:model="r2BackupSecretAccessKey" :type="show ? 'text' : 'password'" autocomplete="off"
                            placeholder="{{ $hasR2BackupSecret ? 'Leave blank to keep current key' : 'Paste your backup secret access key' }}"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2.5 pr-10 text-sm font-mono focus:border-blue-500 focus:ring-blue-500">
                        <button type="button" @click="show = !show" class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-400 hover:text-gray-600">
                            <svg x-show="!show" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg x-show="show" class="h-5 w-5" x-cloak viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" x2="22" y1="2" y2="22"/></svg>
                        </button>
                    </div>
                    @error('r2BackupSecretAccessKey')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="mt-5 flex items-center justify-between gap-3">
                <div class="flex gap-2">
                    <button wire:click="testDisk('r2-backup')" class="rounded-lg border border-green-300 bg-white px-4 py-2.5 text-sm font-semibold text-green-700 hover:bg-green-50" wire:loading.attr="disabled" wire:target="testDisk">
                        <span wire:loading.remove wire:target="testDisk('r2-backup')">Test connection</span>
                        <span wire:loading wire:target="testDisk('r2-backup')">Testing…</span>
                    </button>
                    @if($hasR2BackupConfigAll)
                        <button wire:click="clearBackup" wire:confirm="Remove the backup R2 credentials? Nightly DB dumps will stop until new credentials are saved." class="rounded-lg border border-red-300 bg-white px-4 py-2.5 text-sm font-semibold text-red-600 hover:bg-red-50">
                            Remove credentials
                        </button>
                    @endif
                </div>
                <button wire:click="saveBackup" class="rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-500">
                    <span wire:loading.remove wire:target="saveBackup">Save backup bucket</span>
                    <span wire:loading wire:target="saveBackup">Saving…</span>
                </button>
            </div>
        </div>

        {{-- ─── Backup encryption ─── --}}
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <div class="flex items-center gap-3 mb-4">
                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-purple-50 text-purple-600">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                </div>
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">Backup encryption password</h3>
                    <p class="text-xs text-gray-500">AES-256-CBC password used to encrypt the nightly database dump before it is uploaded to R2.</p>
                </div>
                <div class="ml-auto">
                    @if($hasBackupEncryptionPassword)
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-green-50 text-green-700 border border-green-200 px-2.5 py-1 text-xs font-medium"><span class="h-1.5 w-1.5 rounded-full bg-green-500"></span>Custom password set</span>
                    @else
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 text-amber-700 border border-amber-200 px-2.5 py-1 text-xs font-medium"><span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>Falling back to APP_KEY</span>
                    @endif
                </div>
            </div>

            <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900 mb-4">
                <p class="font-semibold mb-0.5">Important — losing this password loses your backups</p>
                <p>
                    Encrypted dumps can only be decrypted with the password used at the time the dump was made.
                    Store this somewhere outside the server (password manager, sealed envelope, vault).
                    Changing the password from here only affects <em>future</em> dumps; older dumps stay decryptable with whatever password was active when they were taken.
                </p>
            </div>

            <div x-data="{ show: false }" class="max-w-lg">
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    Encryption password
                    @if($hasBackupEncryptionPassword) <span class="text-xs font-normal text-green-600">(currently set)</span> @endif
                </label>
                <div class="relative">
                    <input wire:model="backupEncryptionPassword" :type="show ? 'text' : 'password'" autocomplete="new-password"
                        placeholder="{{ $hasBackupEncryptionPassword ? 'Leave blank to keep current password' : 'Choose a strong passphrase (16+ chars)' }}"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2.5 pr-10 text-sm font-mono focus:border-blue-500 focus:ring-blue-500">
                    <button type="button" @click="show = !show" class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-400 hover:text-gray-600">
                        <svg x-show="!show" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg x-show="show" class="h-5 w-5" x-cloak viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" x2="22" y1="2" y2="22"/></svg>
                    </button>
                </div>
                @error('backupEncryptionPassword')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                <p class="mt-1.5 text-xs text-gray-400">Restore with: <code class="font-mono text-[11px]">openssl enc -d -aes-256-cbc -pbkdf2 -in dump.sql.enc -out dump.sql -pass pass:&lt;password&gt;</code></p>
            </div>

            <div class="mt-5 flex items-center justify-between gap-3">
                <div class="flex gap-2">
                    <button wire:click="runBackupNow" wire:confirm="Run a full backup now? This dumps the live database, encrypts it, and uploads to R2. Can take a minute or two."
                        class="rounded-lg border border-blue-300 bg-white px-4 py-2.5 text-sm font-semibold text-blue-700 hover:bg-blue-50" wire:loading.attr="disabled" wire:target="runBackupNow">
                        <span wire:loading.remove wire:target="runBackupNow">Run backup now</span>
                        <span wire:loading wire:target="runBackupNow">Backing up — please wait…</span>
                    </button>
                    @if($hasBackupEncryptionPassword)
                        <button wire:click="clearBackupEncryption" wire:confirm="Clear the custom backup password? Future backups will fall back to APP_KEY. Existing encrypted dumps are unaffected."
                            class="rounded-lg border border-red-300 bg-white px-4 py-2.5 text-sm font-semibold text-red-600 hover:bg-red-50">
                            Clear password
                        </button>
                    @endif
                </div>
                <button wire:click="saveBackupEncryption" class="rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-500">
                    <span wire:loading.remove wire:target="saveBackupEncryption">Save password</span>
                    <span wire:loading wire:target="saveBackupEncryption">Saving…</span>
                </button>
            </div>
        </div>

        <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800 max-w-3xl">
            <p class="font-semibold mb-1">How to get Cloudflare R2 credentials</p>
            <ol class="list-decimal pl-5 space-y-0.5 text-blue-900/90">
                <li>Cloudflare dashboard → <b>R2</b> → create bucket.</li>
                <li>R2 → <b>Manage R2 API Tokens</b> → create a token scoped to that bucket with <i>Object Read &amp; Write</i>.</li>
                <li>Copy the <b>Access Key ID</b>, <b>Secret Access Key</b> and the <b>S3 API endpoint</b> from the token overview into the fields above.</li>
                <li>Hit <b>Save primary bucket</b>, then <b>Test connection</b>. A green result means uploads will persist.</li>
            </ol>
        </div>

    </div>
</div>
