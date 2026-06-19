<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Location;
use App\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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
                    $this->error("    ! Skipped: " . $e->getMessage());
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
     */
    private function mergeAbsorbedInto(int $keeperId, array $absorbedIds): void
    {
        foreach (self::SAFE_TABLES as $ref) {
            try {
                DB::table($ref['table'])
                    ->whereIn($ref['col'], $absorbedIds)
                    ->update([$ref['col'] => $keeperId]);
            } catch (\Throwable $e) {
                // Table absent on this env -- skip silently.
            }
        }

        foreach (self::COMPOSITE_TABLES as $ref) {
            try {
                $this->mergeComposite($ref['table'], $ref['col'], $ref['pair'], $keeperId, $absorbedIds);
            } catch (\Throwable $e) {
                // Table absent on this env -- skip silently.
            }
        }
    }

    /**
     * Per-row merge for tables with a composite unique constraint that
     * includes the location FK we're rewriting.  For each source row,
     * check whether a row already exists with col=keeper and the same
     * pair-values; if it does, delete the source instead of UPDATE-ing
     * (which would explode the unique constraint).
     */
    private function mergeComposite(string $table, string $col, array $pair, int $keeperId, array $absorbedIds): void
    {
        $rows = DB::table($table)
            ->whereIn($col, $absorbedIds)
            ->get(array_merge(['id', $col], $pair));

        foreach ($rows as $row) {
            $existing = DB::table($table)
                ->where($col, $keeperId)
                ->when(true, function ($q) use ($row, $pair) {
                    foreach ($pair as $p) {
                        $q->where($p, $row->{$p});
                    }
                })
                ->exists();

            if ($existing) {
                DB::table($table)->where('id', $row->id)->delete();
            } else {
                DB::table($table)->where('id', $row->id)->update([$col => $keeperId]);
            }
        }
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
            try {
                $ids = DB::table($ref['table'])
                    ->whereNotNull($ref['col'])
                    ->distinct()
                    ->pluck($ref['col'])
                    ->all();
                foreach ($ids as $id) {
                    $allReferenced[$id] = true;
                }
            } catch (\Throwable $e) {
                // missing table on this env
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
            try {
                $total += (int) DB::table($ref['table'])->where($ref['col'], $locationId)->count();
            } catch (\Throwable $e) {
                // missing table on this env
            }
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
