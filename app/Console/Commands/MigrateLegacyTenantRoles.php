<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Move legacy dealer-tier / oem-tier role users onto the modern
 * customer-tier roles, and set the matching Company::$type, so the whole
 * instance runs on one role model (customer-tier roles + Company.type for
 * dealer/OEM/customer re-skinning).
 *
 * ADDITIVE + SAFE:
 *  - Attaches the equivalent customer_* role; never detaches the legacy
 *    role (so nothing a legacy role still gates can break). Clean up the
 *    legacy roles later once you've confirmed everything works.
 *  - Only sets a company's type when it's currently unset/blank or the
 *    generic "customer" — never overrides a deliberate dealer/oem/bb type.
 *  - --dry-run prints the plan and writes nothing.
 *
 *   php artisan roles:migrate-legacy-tenants --dry-run
 *   php artisan roles:migrate-legacy-tenants
 */
class MigrateLegacyTenantRoles extends Command
{
    protected $signature = 'roles:migrate-legacy-tenants {--dry-run : preview without writing}';

    protected $description = 'Attach customer-tier equivalents to legacy dealer/oem-tier role users and set Company.type';

    /** Legacy role base-slug → modern customer-tier equivalent. */
    protected array $map = [
        'dealer_principal'   => 'customer_owner',
        'sales_manager_new'  => 'customer_admin',
        'sales_manager_used' => 'customer_admin',
        'sales_person_new'   => 'customer_user',
        'sales_person_used'  => 'customer_user',
        'stock_controller'   => 'customer_admin',
        'oem_admin'          => 'customer_owner',
        'oem_planner'        => 'customer_dispatcher',
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $legacyRoles = Role::whereIn('tier', ['dealer', 'oem'])->get();
        if ($legacyRoles->isEmpty()) {
            $this->info('No legacy dealer/oem-tier roles found — nothing to migrate.');
            return self::SUCCESS;
        }

        // base slug (strip _company_<id>) → modern customer slug
        $legacyRoleIdToTarget = [];
        $legacyRoleIdToTier = [];
        foreach ($legacyRoles as $role) {
            $base = preg_replace('/_company_\d+$/', '', $role->slug);
            if (isset($this->map[$base])) {
                $legacyRoleIdToTarget[$role->id] = $this->map[$base];
                $legacyRoleIdToTier[$role->id] = $role->tier;
            }
        }

        $targetRoleIdBySlug = Role::whereIn('slug', array_values($this->map))
            ->pluck('id', 'slug');

        // ---- Plan role attachments -------------------------------------
        $users = User::whereHas('roles', fn ($q) => $q->whereIn('roles.id', array_keys($legacyRoleIdToTarget)))
            ->with('roles')
            ->orderBy('name')
            ->get();

        $roleRows = [];
        $userToAttachRoleIds = [];
        foreach ($users as $user) {
            $targets = $user->roles
                ->pluck('id')
                ->map(fn ($id) => $legacyRoleIdToTarget[$id] ?? null)
                ->filter()
                ->unique()
                ->values();

            $alreadyHas = $user->roles->pluck('slug');
            $toAttach = $targets->reject(fn ($slug) => $alreadyHas->contains($slug));

            if ($toAttach->isNotEmpty()) {
                $userToAttachRoleIds[$user->id] = $toAttach
                    ->map(fn ($slug) => $targetRoleIdBySlug[$slug] ?? null)
                    ->filter()
                    ->all();
                $roleRows[] = [$user->id, $user->name, $user->roles->pluck('slug')->implode(', '), $toAttach->implode(', ')];
            }
        }

        // ---- Plan company type fixes -----------------------------------
        // A company that has a dealer-tier legacy user becomes a dealer;
        // an oem-tier legacy user makes it an OEM. Only when its type is
        // blank or generic "customer".
        $companyDesiredType = [];
        $companyConflicts = [];
        foreach ($users as $user) {
            $tiers = $user->roles->pluck('id')->map(fn ($id) => $legacyRoleIdToTier[$id] ?? null)->filter()->unique();
            foreach ($user->companies()->get(['companies.id']) as $company) {
                foreach ($tiers as $tier) {
                    $type = $tier === 'oem' ? Company::TYPE_OEM : Company::TYPE_DEALER;
                    if (isset($companyDesiredType[$company->id]) && $companyDesiredType[$company->id] !== $type) {
                        $companyConflicts[$company->id] = true;
                    }
                    $companyDesiredType[$company->id] = $type;
                }
            }
        }

        $typeRows = [];
        $companyTypeFixes = [];
        foreach ($companyDesiredType as $companyId => $desired) {
            if (isset($companyConflicts[$companyId])) {
                continue;
            }
            $company = Company::find($companyId);
            if (! $company) {
                continue;
            }
            $current = $company->type;
            if ($current === null || $current === '' || $current === Company::TYPE_CUSTOMER) {
                $companyTypeFixes[$companyId] = $desired;
                $typeRows[] = [$company->id, $company->name, $current ?: '(unset)', $desired];
            }
        }

        // ---- Report -----------------------------------------------------
        $this->newLine();
        $this->line('<options=bold>Role attachments</> (legacy role kept; customer-tier equivalent added):');
        if ($roleRows) {
            $this->table(['User id', 'Name', 'Current roles', 'Will gain'], $roleRows);
        } else {
            $this->line('  None — every legacy user already has the customer-tier equivalent.');
        }

        $this->newLine();
        $this->line('<options=bold>Company type fixes</> (only blank / generic "customer" types):');
        if ($typeRows) {
            $this->table(['Company id', 'Name', 'Current type', 'New type'], $typeRows);
        } else {
            $this->line('  None.');
        }
        if ($companyConflicts) {
            $this->warn('Skipped ' . count($companyConflicts) . ' company(ies) with BOTH dealer and oem legacy users — set their type by hand.');
        }

        if ($dry) {
            $this->newLine();
            $this->info('Dry run — no changes written.');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($userToAttachRoleIds, $companyTypeFixes) {
            foreach ($userToAttachRoleIds as $userId => $roleIds) {
                User::find($userId)?->roles()->syncWithoutDetaching($roleIds);
            }
            foreach ($companyTypeFixes as $companyId => $type) {
                Company::where('id', $companyId)->update(['type' => $type]);
            }
        });

        $this->newLine();
        $this->info('Migrated ' . count($userToAttachRoleIds) . ' user(s) and set type on ' . count($companyTypeFixes) . ' company(ies).');
        return self::SUCCESS;
    }
}
