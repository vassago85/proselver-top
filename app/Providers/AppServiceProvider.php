<?php

namespace App\Providers;

use App\Models\Company;
use App\Models\Job;
use App\Models\JobDocument;
use App\Models\MovementRequest;
use App\Models\SystemSetting;
use App\Observers\DealerStockMovementLinker;
use App\Observers\JobObserver;
use App\Policies\CompanyPolicy;
use App\Policies\JobDocumentPolicy;
use App\Policies\JobPolicy;
use App\Policies\MovementRequestPolicy;
use App\Policies\PettyCashEntryPolicy;
use App\Services\TrackSolid\Client as TrackSolidClient;
use App\Services\TrackSolid\TrackSolidClientInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // GPS tracker integration. The interface is the seam tests
        // (and any future second vendor) bind against; the concrete
        // class reads its config from system_settings at runtime so
        // dev/CI environments without creds still boot cleanly.
        $this->app->singleton(TrackSolidClientInterface::class, TrackSolidClient::class);
    }

    public function boot(): void
    {
        Gate::policy(Job::class, JobPolicy::class);
        Gate::policy(Company::class, CompanyPolicy::class);
        Gate::policy(JobDocument::class, JobDocumentPolicy::class);
        Gate::policy(\App\Models\PettyCashEntry::class, PettyCashEntryPolicy::class);
        Gate::policy(MovementRequest::class, MovementRequestPolicy::class);
        Gate::policy(\App\Models\DealerStock::class, \App\Policies\DealerStockPolicy::class);

        // Inventory auto-link is opt-in per environment. While this flag is
        // off (the default) no observer is attached and Job writes don't
        // touch the inventory table — preserving existing dispatch behaviour.
        if (config('features.inventory_link')) {
            Job::observe(JobObserver::class);
        }

        // Dealer stock ledger linker -- always attached.  It only
        // ever writes to dealer_stock (never back to the job) and is
        // a no-op when no matching dealer_stock row exists, so
        // proselver-side movements stay unaffected.
        Job::observe(DealerStockMovementLinker::class);

        static::hydrateStorageConfigFromDatabase();
        static::hydrateMailConfigFromDatabase();

        // Persistent login trail — writes to the `login_history` table so
        // recent sign-ins survive container recreates (nginx access logs
        // don't).  Registered explicitly rather than via event auto-
        // discovery so grep for the listener class name finds this line.
        // The listener wraps every write in try/catch: if the sink breaks
        // it must NEVER block login.
        Event::listen(\Illuminate\Auth\Events\Login::class,  [\App\Listeners\LogLoginActivity::class, 'handleLogin']);
        Event::listen(\Illuminate\Auth\Events\Failed::class, [\App\Listeners\LogLoginActivity::class, 'handleFailed']);
        Event::listen(\Illuminate\Auth\Events\Logout::class, [\App\Listeners\LogLoginActivity::class, 'handleLogout']);

        // Pin Livewire's update endpoint to a stable URL.
        //
        // Livewire 4 randomises the update route per-boot (a hash like
        // "/livewire-7c04dcfc/update") as an anti-fingerprinting measure.
        // The hash gets baked into every rendered page's JavaScript, so
        // when we deploy and the boot regenerates a new hash, every
        // browser tab that's still on a pre-deploy page suddenly posts
        // its next Livewire interaction (pagination, search debounce,
        // wire:click, etc.) to a URL the new app no longer knows about
        // and gets a 405 Method Not Allowed -- which renders as the
        // generic "Oops! An Error Occurred" page from Symfony's default
        // exception handler, not a Livewire-specific message.
        //
        // We've already had ops hit this twice on the production box
        // (most recently /admin/reports → filter FAW → next page after
        // a routine deploy). Hard refresh fixes individual users, but
        // every redeploy creates a fresh wave of broken stale tabs.
        //
        // Pinning the route trades a tiny bit of fingerprint surface
        // (which path the POST hits) for predictability across deploys.
        // It also matches how Livewire 3 has worked for years.
        //
        // CRITICAL: ->middleware('web') is REQUIRED. Livewire's own
        // default registration includes it (HandleRequests::boot at
        // vendor line 26), and dropping it breaks anything that relies
        // on the session being warm during an update — most visibly
        // file uploads, which round-trip through a SECOND endpoint
        // (/livewire-*/upload-file) whose signed-URL validation needs
        // the encrypted session cookie. Without it, selecting a file
        // in a wire:model file input causes the upload fetch to 401/419
        // and Livewire morphs the component back to its initial state,
        // which looks like "the page reset" to the user.
        Livewire::setUpdateRoute(function ($handle) {
            return Route::post('/livewire/update', $handle)->middleware('web');
        });
    }

    /**
     * Let the Admin → Settings → Storage page override what's in
     * config/filesystems.php (which is sourced from env) without requiring
     * a container rebuild. Values are read from system_settings if present
     * and non-empty; otherwise the env-provided defaults remain.
     *
     * This runs very early during boot, before any Storage::disk() call,
     * so the filesystem manager builds its disks with the right creds.
     *
     * Wrapped in try/catch + Schema::hasTable so the app still boots
     * cleanly on a fresh container before the first migration runs.
     *
     * Public + static so the Storage settings page can re-run hydration
     * after a save without having to reinstantiate this provider through
     * the container (its constructor takes an untyped $app, which Laravel
     * cannot auto-wire — calling app(self::class) blows up with an
     * "Unresolvable dependency" BindingResolutionException).
     */
    public static function hydrateStorageConfigFromDatabase(): void
    {
        try {
            if (!Schema::hasTable('system_settings')) {
                return;
            }
        } catch (Throwable) {
            return;
        }

        $apply = function (string $settingKey, string $configKey) {
            $value = SystemSetting::get($settingKey, null);
            if ($value !== null && $value !== '') {
                Config::set($configKey, $value);
            }
        };

        // Default upload disk (local | r2 | s3) — lets an operator flip
        // between local Docker volume and cloud storage without touching .env.
        $apply('storage_default_disk', 'filesystems.default');

        // Primary R2 credentials
        $apply('r2_access_key_id',     'filesystems.disks.r2.key');
        $apply('r2_secret_access_key', 'filesystems.disks.r2.secret');
        $apply('r2_region',            'filesystems.disks.r2.region');
        $apply('r2_bucket',            'filesystems.disks.r2.bucket');
        $apply('r2_endpoint',          'filesystems.disks.r2.endpoint');

        // Backup bucket credentials (separate Cloudflare account / bucket)
        $apply('r2_backup_access_key_id',     'filesystems.disks.r2-backup.key');
        $apply('r2_backup_secret_access_key', 'filesystems.disks.r2-backup.secret');
        $apply('r2_backup_region',            'filesystems.disks.r2-backup.region');
        $apply('r2_backup_bucket',            'filesystems.disks.r2-backup.bucket');
        $apply('r2_backup_endpoint',          'filesystems.disks.r2-backup.endpoint');
    }

    /**
     * Same pattern as hydrateStorageConfigFromDatabase, but for the mail stack:
     * lets the Admin → Settings → Email page configure SMTP / Mailgun creds at
     * runtime without touching .env. Without this, values saved in the UI sit
     * in system_settings but are never applied to Laravel's mail config, so
     * Mail::* uses whatever MAIL_MAILER from .env points at (often `log`,
     * which silently writes to laravel.log and explains "test mail not working").
     *
     * Public + static for the same reasons as the storage hydrator.
     */
    public static function hydrateMailConfigFromDatabase(): void
    {
        try {
            if (!Schema::hasTable('system_settings')) {
                return;
            }
        } catch (Throwable) {
            return;
        }

        $apply = function (string $settingKey, string $configKey) {
            $value = SystemSetting::get($settingKey, null);
            if ($value !== null && $value !== '') {
                Config::set($configKey, $value);
            }
        };

        // Active driver + global "from" identity
        $apply('mail_driver',       'mail.default');
        $apply('mail_from_name',    'mail.from.name');
        $apply('mail_from_address', 'mail.from.address');

        // SMTP transport
        $apply('mail_smtp_host',     'mail.mailers.smtp.host');
        $apply('mail_smtp_port',     'mail.mailers.smtp.port');
        $apply('mail_smtp_username', 'mail.mailers.smtp.username');
        $apply('mail_smtp_password', 'mail.mailers.smtp.password');

        // Symfony's SMTP transport in Laravel 11+ uses `scheme` (smtp / smtps),
        // not the legacy `encryption` key. Map the UI choice accordingly so
        // SSL-on-465 selections actually negotiate TLS at connect time.
        $encryption = SystemSetting::get('mail_smtp_encryption', null);
        if ($encryption === 'ssl') {
            Config::set('mail.mailers.smtp.scheme', 'smtps');
        } elseif ($encryption === 'tls' || $encryption === '') {
            // tls (STARTTLS) is the default Symfony behaviour on 587 — clearing
            // the scheme lets Symfony pick the right one based on the port.
            Config::set('mail.mailers.smtp.scheme', null);
        }

        // Mailgun transport — read by symfony/mailgun-mailer's transport factory
        $apply('mail_mailgun_domain',   'services.mailgun.domain');
        $apply('mail_mailgun_secret',   'services.mailgun.secret');
        $apply('mail_mailgun_endpoint', 'services.mailgun.endpoint');
    }
}
