<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Merge one company ("from") into another ("into"), re-pointing every
 * foreign key that references the absorbed company and then deactivating
 * + soft-deleting it. The surviving row keeps its id, name and settings.
 *
 * Built for the Proselver / Trident consolidation (two transporter rows
 * that should be a single carrier holding both the dispatch staff and the
 * driver pool), but written generically so it works for any pair.
 *
 *   php artisan companies:merge "TRIDENT Control & Dispatch Center" "Proselver Technologies" --dry-run
 *   php artisan companies:merge 7 3            # by id, real run
 *
 * Safety:
 *  - Always run --dry-run first and eyeball the move/drop counts.
 *  - The whole merge runs inside one DB transaction — any error rolls
 *    everything back.
 *  - The absorbed company is soft-deleted, not hard-deleted, so the row
 *    survives for audit / recovery. A merge is still effectively
 *    one-way: TAKE A DATABASE BACKUP before the real run.
 */
class MergeCompanies extends Command
{
    protected $signature = 'companies:merge
                            {from : id or name of the company to absorb (goes away)}
                            {into : id or name of the surviving company}
                            {--dry-run : show what would move without writing}';

    protected $description = 'Merge one company into another, re-pointing all foreign keys, then deactivate the absorbed company';

    /**
     * Tables where the company FK is just re-pointed with no uniqueness to
     * worry about: [table, column].
     *
     * @var array<int, array{0:string,1:string}>
     */
    protected array $simple = [
        ['transport_jobs', 'company_id'],
        ['transport_jobs', 'executing_company_id'],
        ['trips', 'company_id'],
        ['locations', 'company_id'],
        ['invoices', 'company_id'],
        ['credit_notes', 'company_id'],
        ['movement_requests', 'requesting_company_id'],
        ['movement_requests', 'target_company_id'],
    ];

    /**
     * Tables with a composite unique that includes the company column. The
     * third element is the "other" column in the unique key — a row on the
     * absorbed company that collides with an existing surviving-company row
     * is dropped (survivor wins) rather than violating the constraint.
     *
     * @var array<int, array{0:string,1:string,2:string}>
     */
    protected array $dedup = [
        ['company_users', 'company_id', 'user_id'],
        ['inventory', 'owner_company_id', 'chassis_number'],
        ['dealer_stock', 'dealer_company_id', 'vin'],
        ['brand_company', 'company_id', 'brand_id'],
        ['body_builder_dealer_links', 'dealer_company_id', 'body_builder_company_id'],
        ['body_builder_dealer_links', 'body_builder_company_id', 'dealer_company_id'],
    ];

    public function handle(): int
    {
        $from = $this->resolve($this->argument('from'));
        $into = $this->resolve($this->argument('into'));

        if (! $from) {
            $this->error("No company matches \"{$this->argument('from')}\".");
            return self::FAILURE;
        }
        if (! $into) {
            $this->error("No company matches \"{$this->argument('into')}\".");
            return self::FAILURE;
        }
        if ($from->id === $into->id) {
            $this->error('The two companies are the same row — nothing to merge.');
            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');

        $this->newLine();
        $this->line("Absorbing  <fg=yellow>{$from->name}</> (id={$from->id}, type={$from->type}, platform_owner=" . ($from->is_platform_owner ? 'yes' : 'no') . ')');
        $this->line("Surviving  <fg=cyan>{$into->name}</> (id={$into->id}, type={$into->type}, platform_owner=" . ($into->is_platform_owner ? 'yes' : 'no') . ')');
        $this->newLine();

        $movePlatform = $from->is_platform_owner && ! $into->is_platform_owner;

        // ---- Preview pass (always computed; only this prints on dry run) ----
        $rows = [];
        foreach ($this->simple as [$table, $col]) {
            if (! $this->tableHasColumn($table, $col)) {
                continue;
            }
            $rows[] = ["{$table}.{$col}", $this->countFor($table, $col, $from->id), '0 (re-point)'];
        }
        foreach ($this->dedup as [$table, $col, $other]) {
            if (! $this->tableHasColumn($table, $col)) {
                continue;
            }
            $drop = $this->conflictCount($table, $col, $other, $from->id, $into->id);
            $total = $this->countFor($table, $col, $from->id);
            $rows[] = ["{$table}.{$col}", $total - $drop, "{$drop} (survivor wins)"];
        }

        $companyRoles = $this->tableHasColumn('roles', 'company_id')
            ? DB::table('roles')->where('company_id', $from->id)->count()
            : 0;
        if ($companyRoles > 0) {
            $rows[] = ['roles.company_id', $companyRoles, '0 (re-point)'];
        }

        $this->table(['Foreign key', 'Rows moved', 'Rows dropped'], $rows);

        if ($movePlatform) {
            $this->warn("Platform-owner flag will move to {$into->name} (single-owner invariant).");
        }
        $this->warn("Then: {$from->name} will be deactivated and soft-deleted.");
        $this->newLine();

        if ($dry) {
            $this->info('Dry run — no changes written. Re-run without --dry-run to apply (back up the DB first).');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($from, $into, $movePlatform) {
            foreach ($this->simple as [$table, $col]) {
                if (! $this->tableHasColumn($table, $col)) {
                    continue;
                }
                DB::table($table)->where($col, $from->id)->update([$col => $into->id]);
            }

            foreach ($this->dedup as [$table, $col, $other]) {
                if (! $this->tableHasColumn($table, $col)) {
                    continue;
                }
                $this->dropConflicts($table, $col, $other, $from->id, $into->id);
                DB::table($table)->where($col, $from->id)->update([$col => $into->id]);
            }

            $this->mergeCompanyRoles($from->id, $into->id);

            if ($movePlatform) {
                // Model invariant on save() clears the flag on every other row.
                $into->update(['is_platform_owner' => true]);
            }

            $from->update(['is_active' => false, 'is_platform_owner' => false]);
            $from->delete();
        });

        $this->info("Merged {$from->name} into {$into->name}. Absorbed row soft-deleted (id={$from->id}).");
        return self::SUCCESS;
    }

    protected function resolve(string $ref): ?Company
    {
        return is_numeric($ref)
            ? Company::find((int) $ref)
            : Company::where('name', $ref)
                ->orWhere('normalized_name', mb_strtolower($ref))
                ->first();
    }

    protected function tableHasColumn(string $table, string $column): bool
    {
        return \Illuminate\Support\Facades\Schema::hasColumn($table, $column);
    }

    protected function countFor(string $table, string $col, int $id): int
    {
        return DB::table($table)->where($col, $id)->count();
    }

    /**
     * Rows on the absorbed company that already have a twin (same "other"
     * column value) on the surviving company — these would violate the
     * composite unique key and are dropped.
     */
    protected function conflictCount(string $table, string $col, string $other, int $fromId, int $intoId): int
    {
        return DB::table("{$table} as a")
            ->where("a.{$col}", $fromId)
            ->whereExists(function ($q) use ($table, $col, $other, $intoId) {
                $q->select(DB::raw(1))
                    ->from("{$table} as b")
                    ->where("b.{$col}", $intoId)
                    ->whereColumn("b.{$other}", "a.{$other}");
            })
            ->count();
    }

    protected function dropConflicts(string $table, string $col, string $other, int $fromId, int $intoId): void
    {
        $ids = DB::table("{$table} as a")
            ->where("a.{$col}", $fromId)
            ->whereExists(function ($q) use ($table, $col, $other, $intoId) {
                $q->select(DB::raw(1))
                    ->from("{$table} as b")
                    ->where("b.{$col}", $intoId)
                    ->whereColumn("b.{$other}", "a.{$other}");
            })
            ->pluck('a.id');

        if ($ids->isNotEmpty()) {
            DB::table($table)->whereIn('id', $ids)->delete();
        }
    }

    /**
     * Company-scoped role clones (slug `*_company_<id>`). Re-point them to the
     * surviving company. If the survivor already owns an equivalent role
     * (same base slug), remap its user_roles onto the survivor's role and
     * drop the now-duplicate role instead.
     */
    protected function mergeCompanyRoles(int $fromId, int $intoId): void
    {
        if (! $this->tableHasColumn('roles', 'company_id')) {
            return;
        }

        $roles = DB::table('roles')->where('company_id', $fromId)->get();

        foreach ($roles as $role) {
            $baseSlug = preg_replace('/_company_\d+$/', '', $role->slug);
            $survivorSlug = $baseSlug . '_company_' . $intoId;

            $existing = DB::table('roles')
                ->where('company_id', $intoId)
                ->where('slug', $survivorSlug)
                ->first();

            if ($existing) {
                DB::table('user_roles')->where('role_id', $role->id)->update(['role_id' => $existing->id]);
                DB::table('role_permissions')->where('role_id', $role->id)->delete();
                DB::table('roles')->where('id', $role->id)->delete();
            } else {
                DB::table('roles')->where('id', $role->id)->update([
                    'company_id' => $intoId,
                    'slug' => $survivorSlug,
                ]);
            }
        }
    }
}
