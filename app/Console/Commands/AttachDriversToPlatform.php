<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill: attach every user holding the `driver` role to the platform-owner
 * company (ProSelver) via the `company_users` pivot.
 *
 * Why this exists: the order-show "Assign Driver" picker scopes the ProSelver
 * driver pool through User::scopePlatformDrivers(), which requires the driver
 * to be both `role=driver` AND attached to a company where
 * `is_platform_owner = true`. Earlier seeder runs (FleetDriverSeeder) and the
 * admin/drivers/create form created the User + assigned the role but never
 * attached the user to the platform-owner company, leaving the picker empty
 * even though /admin/drivers showed dozens of drivers.
 *
 * This command is idempotent and safe to re-run any time.
 *
 *   php artisan drivers:attach-platform           # backfill, prints summary
 *   php artisan drivers:attach-platform --dry-run # show what WOULD change
 */
class AttachDriversToPlatform extends Command
{
    protected $signature = 'drivers:attach-platform
                            {--dry-run : list affected drivers without writing}';

    protected $description = 'Attach every driver-role user to the platform-owner company so they appear in the ProSelver assignment picker';

    public function handle(): int
    {
        $platform = Company::where('is_platform_owner', true)->first();
        if (! $platform) {
            $this->error('No company has is_platform_owner = true. Flag the ProSelver company first.');
            return self::FAILURE;
        }

        $this->line("Platform-owner company: <fg=cyan>{$platform->name}</> (id={$platform->id})");

        $drivers = User::whereHas('roles', fn ($q) => $q->where('slug', 'driver'))
            ->where('is_active', true)
            ->with('companies:id,is_platform_owner')
            ->orderBy('name')
            ->get();

        if ($drivers->isEmpty()) {
            $this->warn('No active driver-role users found.');
            return self::SUCCESS;
        }

        $toAttach = $drivers->filter(
            fn (User $u) => ! $u->companies->contains(fn ($c) => $c->id === $platform->id)
        )->values();

        $alreadyOk = $drivers->count() - $toAttach->count();
        $this->line("Already linked: <fg=green>{$alreadyOk}</>  ·  Needs link: <fg=yellow>{$toAttach->count()}</>");

        if ($toAttach->isEmpty()) {
            $this->info('Nothing to do.');
            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Username', 'Name'],
            $toAttach->map(fn ($u) => [$u->id, $u->username, $u->name])->all()
        );

        if ($this->option('dry-run')) {
            $this->warn('Dry run — no changes written.');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($toAttach, $platform) {
            foreach ($toAttach as $driver) {
                $driver->companies()->syncWithoutDetaching([$platform->id]);
            }
        });

        $this->info("Attached {$toAttach->count()} driver(s) to {$platform->name}.");
        return self::SUCCESS;
    }
}
