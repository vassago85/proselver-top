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
 * On a fresh production database the is_platform_owner flag is also unset
 * (only DemoSeeder ever flips it). Pass --set-platform=<id|name> to flag
 * the correct company in the same run and then immediately backfill the
 * driver pivots.
 *
 * This command is idempotent and safe to re-run any time.
 *
 *   php artisan drivers:attach-platform                           # backfill only
 *   php artisan drivers:attach-platform --dry-run                 # preview
 *   php artisan drivers:attach-platform --set-platform=12         # flag company id 12 + backfill
 *   php artisan drivers:attach-platform --set-platform="ProSelver" # flag by name + backfill
 */
class AttachDriversToPlatform extends Command
{
    protected $signature = 'drivers:attach-platform
                            {--dry-run : list affected drivers without writing}
                            {--set-platform= : flag this company id (numeric) or name as is_platform_owner before backfilling}';

    protected $description = 'Attach every driver-role user to the platform-owner company so they appear in the ProSelver assignment picker';

    public function handle(): int
    {
        // Optional: flag a company as platform owner in the same run.
        // The Company model already enforces the single-owner invariant
        // (any other rows currently flagged get unflagged automatically).
        if ($target = $this->option('set-platform')) {
            $company = is_numeric($target)
                ? Company::find((int) $target)
                : Company::where('name', $target)
                    ->orWhere('normalized_name', mb_strtolower($target))
                    ->first();

            if (! $company) {
                $this->error("No company matches \"{$target}\". Run without --set-platform to list candidates.");
                return self::FAILURE;
            }

            if (! $company->is_platform_owner) {
                if ($this->option('dry-run')) {
                    $this->warn("Dry run — would flag <fg=cyan>{$company->name}</> (id={$company->id}) as platform owner.");
                } else {
                    $company->update(['is_platform_owner' => true]);
                    $this->info("Flagged <fg=cyan>{$company->name}</> (id={$company->id}) as the platform-owner company.");
                }
            } else {
                $this->line("<fg=cyan>{$company->name}</> is already the platform-owner — nothing to flag.");
            }
        }

        $platform = Company::where('is_platform_owner', true)->first();
        if (! $platform) {
            $this->error('No company has is_platform_owner = true.');
            $this->newLine();
            $this->line('Pick the company that operates this platform (most likely a transporter row) and re-run with:');
            $this->line('  <fg=yellow>php artisan drivers:attach-platform --set-platform=<id></>');
            $this->newLine();
            $this->line('Candidate companies (transporters first):');

            $candidates = Company::orderByRaw("CASE WHEN type = 'transporter' THEN 0 ELSE 1 END")
                ->orderBy('name')
                ->limit(20)
                ->get(['id', 'name', 'type']);

            if ($candidates->isEmpty()) {
                $this->warn('  (no companies in this database — seed companies first)');
            } else {
                $this->table(
                    ['ID', 'Name', 'Type'],
                    $candidates->map(fn ($c) => [$c->id, $c->name, $c->type])->all()
                );
            }
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
