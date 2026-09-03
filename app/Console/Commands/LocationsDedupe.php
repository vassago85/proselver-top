<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Location;
use App\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Merge duplicate locations within each company.
 *
 * Cluster definition: same company_id + same normalised (company_name +
 * address) -- exactly the key the JobBulkImporter dedupe cache (and the
 * audit command) use.  For each cluster we:
 *
 *   - pick the keeper (highest FK reference count; tie-break: lowest id)
 *   - re-point every FK in transport_jobs / dealer_stock / inventory /
 *     trips / trip_stops / body_builder_links / movement_requests /
 *     company_users / transport_routes / route_estimates /
 *     route_toll_plaza_hints from the absorbed rows to the keeper
 *   - on the three tables that hold composite unique constraints on
 *     location pairs (transport_routes, route_estimates,
 *     route_toll_plaza_hints) we do "merge or delete": if pointing the
 *     row at the keeper would collide with an existing row, we delete
 *     the source row instead -- the data is identical at that point
 *   - soft-delete the absorbed location rows
 *   - write a single audit-log entry per cluster
 *
 * Idempotent.  Safe to re-run.  Pair with --dry-run to preview the
 * exact moves before flipping the switch.
 */
class LocationsDedupe extends Command
{
    protected $signature = 'locations:dedupe
        {--company= : Limit to a company id or (partial) name}
        {--dry-run : Show what would change without writing}
        {--purge-unused : Also soft-delete locations that have zero FK references at the end}';

    protected $description = 'Merge duplicate addresses within each company and (optionally) soft-delete unused ones.';

    /**
     * Reference tables that have NO composite unique constraint
     * involving a location FK -- a straight UPDATE is always safe.
     */
    private const SAFE_TABLES = [
        ['table' => 'transport_jobs',     'col' => 'pickup_location_id'],
        ['table' => 'transport_jobs',     'col' => 'delivery_location_id'],
        ['table' => 'transport_jobs',     'col' => 'yard_location_id'],
        ['table' => 'dealer_stock',       'col' => 'current_location_id'],
        ['table' => 'inventory',          'col' => 'current_location_id'],
        ['table' => 'trips',              'col' => 'start_location_id'],
        ['table' => 'trips',              'col' => 'end_location_id'],
        ['table' => 'trip_stops',         'col' => 'location_id'],
        ['table' => 'body_builder_links', 'col' => 'pickup_location_id'],
        ['table' => 'body_builder_links', 'col' => 'delivery_location_id'],
        ['table' => 'movement_requests',  'col' => 'pickup_location_id'],
        ['table' => 'movement_requests',  'col' => 'delivery_location_id'],
        ['table' => 'company_users',      'col' => 'location_id'],
    ];

    /**
     * Tables that hold a composite unique that includes a location FK.
     * For these, a straight UPDATE can hit a uniqueness violation, so
     * we use merge-or-delete: try update, on collision delete instead.
     *
     * 'pair' lists the *other* columns in the composite so we can
     * identify a would-be collision before UPDATE-ing.
     */
    private const COMPOSITE_TABLES = [
        // transport_routes: unique(origin, destination, vehicle_class)
        ['table' => 'transport_routes', 'col' => 'origin_location_id',      'pair' => ['destination_location_id', 'vehicle_class_id']],
        ['table' => 'transport_routes', 'col' => 'destination_location_id', 'pair' => ['origin_location_id',      'vehicle_class_id']],
        // route_estimates: unique(pickup, delivery)
        ['table' => 'route_estimates',  'col' => 'pickup_location_id',      'pair' => ['delivery_location_id']],
        ['table' => 'route_estimates',  'col' => 'delivery_location_id',    'pair' => ['pickup_location_id']],
        // route_toll_plaza_hints: unique(pickup, delivery, toll_plaza)
        ['table' => 'route_toll_plaza_hints', 'col' => 'pickup_location_id',   'pair' => ['delivery_location_id', 'toll_plaza_id']],
        ['table' => 'route_toll_plaza_hints', 'col' => 'delivery_location_id', 'pair' => ['pickup_location_id',   'toll_plaza_id']],
    ];

    /**
     * When a composite row is retired (collision with keeper's row),
     * re-point these FKs to the keeper row before DELETE.  Without this,
     * transport_jobs.transport_route_id blocks deleting the absorbed route.
     */
    private const COMPOSITE_ROW_REPOINTS = [
        'transport_routes' => [
            ['table' => 'transport_jobs', 'col' => 'transport_route_id'],
        ],
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $purgeUnused = (bool) $this->option('purge-unused');

        $companyId = null;
        $companyFilter = trim((string) $this->option('company'));
        if ($companyFilter !== '') {
            $resolved = $this->resolveCompany($companyFilter);
            if (!$resolved) {
                return self::FAILURE;
            }
            $companyId = $resolved->id;
            $this->info("Scope: company {$resolved->name} (#{$resolved->id})");
        } else {
            $this->info('Scope: every company');
        }

        $clusters = $this->findClusters($companyId);

        if ($clusters->isEmpty()) {
            $this->info('No duplicate clusters found. Nothing to merge.');
        } else {
            $this->info("Found {$clusters->count()} duplicate cluster(s).");
            $totalAbsorbed = 0;
            foreach ($clusters as $i => $cluster) {
                $idx = $i + 1;
                $companyName = $cluster['company_name'];
                $keeper = $cluster['keeper'];
                $absorbed = $cluster['absorbed'];

                $this->info('');
                $this->info(sprintf(
                    'Cluster %d -- %s -- "%s"',
                    $idx, $companyName, $this->truncate($cluster['display'], 60),
                ));
                $this->line("  keeper: #{$keeper['id']} ({$keeper['refs']} refs)");
                foreach ($absorbed as $a) {
                    $this->line("    -> absorbs #{$a['id']} ({$a['refs']} refs)");
                }

                if ($dryRun) {
                    $totalAbsorbed += count($absorbed);
                    continue;
                }

                try {
                    $absorbedIds = array_column($absorbed, 'id');
                    DB::transaction(function () use ($keeper, $absorbedIds, $companyName) {
                        $this->mergeAbsorbedInto($keeper['id'], $absorbedIds);
                        Location::whereIn('id', $absorbedIds)->delete(); // soft-delete

                        AuditService::log('locations_merged', 'location', $keeper['id'], null, [
                            'company_name' => $companyName,
                            'keeper_id' => $keeper['id'],
                            'absorbed_ids' => $absorbedIds,
                        ]);
                    });
                    $totalAbsorbed += count($absorbed);
                } catch (\Throwable $e) {
                    // Prefer the root cause — on Postgres a swallowed
                    // mid-txn failure surfaces as the opaque 25P02 on
                    // the next statement.
                    $root = $e;
                    while ($root->getPrevious()) {
                        $root = $root->getPrevious();
                    }
                    $msg = $root->getMessage();
                    if ($root !== $e && !str_contains($e->getMessage(), $msg)) {
                        $msg = $e->getMessage() . ' (cause: ' . $msg . ')';
                    }
                    $this->error("    ! Skipped: " . $msg);
                }
            }

            if ($dryRun) {
                $this->warn("\nDry-run -- nothing was written. {$totalAbsorbed} row(s) would be merged.");
            } else {
                $this->info("\nMerged {$totalAbsorbed} duplicate row(s).");
            }
        }

        // --------------------------------------------------------
        // Optional: also soft-delete locations with no FK refs left.
        // --------------------------------------------------------
        if ($purgeUnused) {
            $this->info('');
            $this->info('Purging unused locations...');
            $unused = $this->findUnused($companyId);
            $this->line("  Found {$unused->count()} unused location(s).");

            if (!$dryRun && $unused->isNotEmpty()) {
                $ids = $unused->pluck('id')->all();
                Location::whereIn('id', $ids)->delete();
                AuditService::log('locations_unused_purged', 'location', null, null, [
                    'count' => count($ids),
                    'company_id' => $companyId,
                ]);
                $this->info("  Soft-deleted {$unused->count()} unused row(s).");
            } elseif ($dryRun) {
                $this->warn('  Dry-run -- would soft-delete ' . $unused->count() . ' row(s).');
            }
        }

        return self::SUCCESS;
    }

    /**
     * For each cluster, do the merge work in a single transaction.
     *
     * IMPORTANT: never catch-and-continue around DB work inside this
     * transaction.  On PostgreSQL the first failure aborts the whole
     * txn (25P02 — "commands ignored until end of transaction block"),
     * and a swallowed exception then surfaces as a misleading failure
     * on the soft-delete.  Skip missing tables via Schema::hasTable.
     */
    private function mergeAbsorbedInto(int $keeperId, array $absorbedIds): void
    {
        foreach (self::SAFE_TABLES as $ref) {
            if (!$this->tableHasColumn($ref['table'], $ref['col'])) {
                continue;
            }
            DB::table($ref['table'])
                ->whereIn($ref['col'], $absorbedIds)
                ->update([$ref['col'] => $keeperId]);
        }

        foreach (self::COMPOSITE_TABLES as $ref) {
            if (!$this->tableHasColumn($ref['table'], $ref['col'])) {
                continue;
            }
            foreach ($ref['pair'] as $pairCol) {
                if (!$this->tableHasColumn($ref['table'], $pairCol)) {
                    continue 2;
                }
            }
            $this->mergeComposite($ref['table'], $ref['col'], $ref['pair'], $keeperId, $absorbedIds);
        }
    }

    /**
     * Per-row merge for tables with a composite unique constraint that
     * includes the location FK we're rewriting.  For each source row,
     * check whether a row already exists with col=keeper and the same
     * pair-values; if it does, delete the source instead of UPDATE-ing
     * (which would explode the unique constraint).
     *
     * Uses a SAVEPOINT per row so a unique-violation on Postgres can be
     * recovered without aborting the outer cluster transaction.
     */
    private function mergeComposite(string $table, string $col, array $pair, int $keeperId, array $absorbedIds): void
    {
        $rows = DB::table($table)
            ->whereIn($col, $absorbedIds)
            ->get(array_merge(['id', $col], $pair));

        foreach ($rows as $row) {
            $keeperRowId = $this->findCompositeKeeperRowId($table, $col, $pair, $keeperId, $row);

            if ($keeperRowId !== null) {
                // Keeper already has this lane — re-point FKs (e.g.
                // transport_jobs.transport_route_id) then drop the dupe.
                $this->retireCompositeRow($table, (int) $row->id, $keeperRowId);
                continue;
            }

            // SAVEPOINT: on Postgres a unique violation aborts the
            // whole transaction unless we roll back to a savepoint.
            $sp = 'dedupe_' . $row->id;
            DB::statement("SAVEPOINT {$sp}");
            try {
                DB::table($table)->where('id', $row->id)->update([$col => $keeperId]);
                DB::statement("RELEASE SAVEPOINT {$sp}");
            } catch (\Throwable $e) {
                DB::statement("ROLLBACK TO SAVEPOINT {$sp}");
                // Collision the pre-check missed — find keeper row and retire.
                $fallbackKeeperId = $this->findCompositeKeeperRowId($table, $col, $pair, $keeperId, $row);
                $this->retireCompositeRow($table, (int) $row->id, $fallbackKeeperId);
            }
        }
    }

    private function findCompositeKeeperRowId(string $table, string $col, array $pair, int $keeperId, object $row): ?int
    {
        $query = DB::table($table)->where($col, $keeperId);
        foreach ($pair as $p) {
            $value = $row->{$p};
            if ($value === null) {
                $query->whereNull($p);
            } else {
                $query->where($p, $value);
            }
        }

        $id = $query->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * Re-point any child FKs from an absorbed composite row onto the
     * keeper's row (or null), then delete the absorbed row.
     */
    private function retireCompositeRow(string $table, int $absorbedRowId, ?int $keeperRowId): void
    {
        foreach (self::COMPOSITE_ROW_REPOINTS[$table] ?? [] as $ref) {
            if (!$this->tableHasColumn($ref['table'], $ref['col'])) {
                continue;
            }
            DB::table($ref['table'])
                ->where($ref['col'], $absorbedRowId)
                ->update([$ref['col'] => $keeperRowId]);
        }

        DB::table($table)->where('id', $absorbedRowId)->delete();
    }

    private function tableHasColumn(string $table, string $column): bool
    {
        return Schema::hasTable($table) && Schema::hasColumn($table, $column);
    }

    /**
     * Walk the address book and assemble clusters: for each (company,
     * normalised key) group with size > 1, build a "keeper + absorbed"
     * shape ready for processing.
     */
    private function findClusters(?int $companyId): \Illuminate\Support\Collection
    {
        $q = Location::query();
        if ($companyId !== null) {
            $q->where('company_id', $companyId);
        }
        $locations = $q->get(['id', 'company_id', 'company_name', 'address']);

        $groups = $locations->groupBy(function ($l) {
            $name = preg_replace('/[^a-z0-9]/', '', strtolower((string) $l->company_name));
            $addr = preg_replace('/[^a-z0-9]/', '', strtolower((string) $l->address));
            return ($l->company_id ?? 'NULL') . '|' . $name . '|' . $addr;
        })->filter(fn ($g) => $g->count() > 1);

        if ($groups->isEmpty()) {
            return collect();
        }

        $companyNames = Company::query()
            ->whereIn('id', $groups->flatten(1)->pluck('company_id')->unique()->filter())
            ->pluck('name', 'id');

        return $groups->map(function ($group) use ($companyNames) {
            $rows = $group->map(fn ($l) => [
                'id' => $l->id,
                'refs' => $this->countRefs($l->id),
                'display' => trim(($l->company_name ?? '') . ' -- ' . ($l->address ?? '')),
                'company_id' => $l->company_id,
            ])
            // Highest refs first; tiebreak: lowest id.
            ->sortBy([
                ['refs', 'desc'],
                ['id', 'asc'],
            ])
            ->values()
            ->all();

            $keeper = array_shift($rows);
            $first = $group->first();
            return [
                'company_name' => $first->company_id ? ($companyNames[$first->company_id] ?? "#{$first->company_id}") : '(unassigned)',
                'display'      => $keeper['display'],
                'keeper'       => $keeper,
                'absorbed'     => $rows,
            ];
        })
        // Process biggest clusters first.
        ->sortByDesc(fn ($c) => count($c['absorbed']))
        ->values();
    }

    private function findUnused(?int $companyId): \Illuminate\Support\Collection
    {
        $allReferenced = [];
        foreach (array_merge(self::SAFE_TABLES, self::COMPOSITE_TABLES) as $ref) {
            if (!$this->tableHasColumn($ref['table'], $ref['col'])) {
                continue;
            }
            $ids = DB::table($ref['table'])
                ->whereNotNull($ref['col'])
                ->distinct()
                ->pluck($ref['col'])
                ->all();
            foreach ($ids as $id) {
                $allReferenced[$id] = true;
            }
        }

        $q = Location::query();
        if ($companyId !== null) {
            $q->where('company_id', $companyId);
        }
        return $q->whereNotIn('id', array_keys($allReferenced))->get(['id', 'company_name', 'address']);
    }

    private function countRefs(int $locationId): int
    {
        $total = 0;
        foreach (array_merge(self::SAFE_TABLES, self::COMPOSITE_TABLES) as $ref) {
            if (!$this->tableHasColumn($ref['table'], $ref['col'])) {
                continue;
            }
            $total += (int) DB::table($ref['table'])->where($ref['col'], $locationId)->count();
        }
        return $total;
    }

    private function resolveCompany(string $needle): ?Company
    {
        if (ctype_digit($needle)) {
            $c = Company::find((int) $needle);
            if (!$c) {
                $this->error("No company with id {$needle}.");
            }
            return $c;
        }

        $matches = Company::where('name', 'like', '%' . $needle . '%')->orderBy('name')->get();
        if ($matches->isEmpty()) {
            $this->error("No company matching '{$needle}'.");
            return null;
        }
        if ($matches->count() > 1) {
            $this->error("'{$needle}' matches " . $matches->count() . ' companies -- be more specific or pass the id:');
            foreach ($matches as $m) {
                $this->line("  #{$m->id}  {$m->name}");
            }
            return null;
        }
        return $matches->first();
    }

    private function truncate(string $s, int $max): string
    {
        $s = preg_replace('/\s+/', ' ', trim($s));
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
    }
}
