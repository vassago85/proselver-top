<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Location;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Home for the location-cleanup primitives that the `locations:dedupe`
 * artisan command and the Address Book "Clean up" UI both need:
 *
 *   - findDuplicateClusters()     -- group same-company + same-normalised
 *                                    (name+address) rows into keeper +
 *                                    absorbed shape ready for merge
 *   - mergeCluster()              -- re-point every FK on the absorbed
 *                                    ids onto the keeper, then soft-delete
 *                                    the absorbed rows.  Wrapped in a DB
 *                                    transaction; safe to re-run
 *   - findIncompleteLocations()   -- locations that never got a real
 *                                    street address (bulk-import stubs)
 *                                    or that lack geocode coordinates
 *   - findUnusedLocations()       -- locations with zero FK references
 *   - countReferences()           -- FK reference count for one location
 *
 * The command class was the source of truth for the FK map; extracting it
 * here means the UI can reuse the exact same merge behaviour without
 * shelling out to artisan, and any future FK we need to re-point is added
 * in one place instead of two.
 */
class LocationMergeService
{
    /**
     * Reference tables that have NO composite unique constraint
     * involving a location FK -- a straight UPDATE is always safe.
     */
    public const SAFE_TABLES = [
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
    public const COMPOSITE_TABLES = [
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
    public const COMPOSITE_ROW_REPOINTS = [
        'transport_routes' => [
            ['table' => 'transport_jobs', 'col' => 'transport_route_id'],
        ],
    ];

    /**
     * Walk the address book and assemble clusters: for each (company,
     * normalised key) group with size > 1, build a "keeper + absorbed"
     * shape ready for processing.
     *
     * Cluster shape:
     *   [
     *     'company_id' => 12,
     *     'company_name' => 'FAW SA',
     *     'display' => 'ANCHOR AUTO -- 55 sample rd',
     *     'keeper'  => ['id' => 100, 'refs' => 5, 'display' => '...'],
     *     'absorbed' => [
     *       ['id' => 101, 'refs' => 1, 'display' => '...'],
     *     ],
     *   ]
     */
    public function findDuplicateClusters(?int $companyId = null): Collection
    {
        $q = Location::query();
        if ($companyId !== null) {
            $q->where('company_id', $companyId);
        }
        $locations = $q->get(['id', 'company_id', 'company_name', 'address']);

        $groups = $locations->groupBy(fn ($l) => $this->clusterKey($l))
            ->filter(fn ($g) => $g->count() > 1);

        if ($groups->isEmpty()) {
            return collect();
        }

        $companyNames = Company::query()
            ->whereIn('id', $groups->flatten(1)->pluck('company_id')->unique()->filter())
            ->pluck('name', 'id');

        return $groups->map(function ($group) use ($companyNames) {
            $rows = $group->map(fn ($l) => [
                'id' => $l->id,
                'refs' => $this->countReferences($l->id),
                'display' => trim(($l->company_name ?? '') . ' -- ' . ($l->address ?? '')),
                'company_id' => $l->company_id,
                'company_name' => $l->company_name,
                'address' => $l->address,
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
                'company_id' => $first->company_id,
                'company_name' => $first->company_id
                    ? ($companyNames[$first->company_id] ?? "#{$first->company_id}")
                    : '(unassigned)',
                'display'  => $keeper['display'],
                'keeper'   => $keeper,
                'absorbed' => $rows,
            ];
        })
        // Process biggest clusters first.
        ->sortByDesc(fn ($c) => count($c['absorbed']))
        ->values();
    }

    /**
     * Locations that are missing pieces of a real street address --
     * classic bulk-import stubs where the raw spreadsheet name was
     * written into the address column and nothing was ever geocoded.
     *
     * Returns Eloquent models so the UI can render + edit them directly.
     *
     * Reasons a row can be "incomplete":
     *   - address is blank
     *   - address is missing lat/lng (never geocoded)
     *   - address equals company_name AND has no digit AND has no comma
     *     -- the classic "we just seeded the name as the street" stub
     */
    public function findIncompleteLocations(?int $companyId = null): Collection
    {
        $q = Location::query()->where('is_active', true);
        if ($companyId !== null) {
            $q->where('company_id', $companyId);
        }

        return $q->get()->filter(fn (Location $l) => $this->isIncomplete($l))->values();
    }

    /**
     * A single-location "is this a bulk-import stub / half-baked entry?"
     * check.  Public so callers building filtered lists (audit exports,
     * dashboards) can share the exact same rule.
     */
    public function isIncomplete(Location $location): bool
    {
        $address = trim((string) $location->address);
        if ($address === '') {
            return true;
        }

        // Missing coordinates -- geocode never resolved.  Routing / tolls
        // can't run without lat+lng so it's effectively broken.
        if (empty($location->latitude) || empty($location->longitude)) {
            return true;
        }

        // Address literally equals the company name (case-insensitive)
        // AND doesn't look like a street.  A real "12 Sample Rd" or
        // "Cnr Foo & Bar" carries a digit or comma; a stub is just the
        // dealer's name written twice.
        $name = trim((string) $location->company_name);
        if ($name !== '' && strcasecmp($name, $address) === 0) {
            $hasDigit = preg_match('/\d/', $address) === 1;
            $hasComma = str_contains($address, ',');
            if (!$hasDigit && !$hasComma) {
                return true;
            }
        }

        return false;
    }

    /**
     * Locations with zero FK references anywhere in the app -- the
     * "purge-unused" set from the artisan command.  Returned as a
     * collection of location models so the UI can display them.
     */
    public function findUnusedLocations(?int $companyId = null): Collection
    {
        $allReferenced = $this->collectReferencedIds();

        $q = Location::query();
        if ($companyId !== null) {
            $q->where('company_id', $companyId);
        }
        return $q->whereNotIn('id', array_keys($allReferenced))
            ->get(['id', 'company_id', 'company_name', 'address']);
    }

    /**
     * FK reference count for one location across every table that
     * points at it.  Used to pick keepers (highest ref count wins) and
     * to warn operators when they're about to absorb a heavily-used row.
     */
    public function countReferences(int $locationId): int
    {
        $total = 0;
        foreach (array_merge(self::SAFE_TABLES, self::COMPOSITE_TABLES) as $ref) {
            if (!$this->tableHasColumn($ref['table'], $ref['col'])) {
                continue;
            }
            $total += (int) DB::table($ref['table'])
                ->where($ref['col'], $locationId)
                ->count();
        }
        return $total;
    }

    /**
     * Merge a cluster of duplicates into one keeper location.
     *
     * Every FK on the absorbed ids is re-pointed to the keeper (safe
     * tables via straight UPDATE; composite-unique tables via
     * merge-or-delete).  The absorbed rows are then soft-deleted.
     * The whole thing runs in a single DB transaction -- on failure
     * nothing is written.
     *
     * Returns the number of absorbed rows that were soft-deleted so
     * callers can print / render "merged X rows into keeper Y".
     *
     * IMPORTANT: never catch-and-continue around DB work inside the
     * transaction.  On PostgreSQL the first failure aborts the whole
     * txn (25P02 -- "commands ignored until end of transaction block")
     * and a swallowed exception then surfaces as a misleading failure
     * on the soft-delete.  Skip missing tables via Schema::hasTable.
     */
    public function mergeCluster(int $keeperId, array $absorbedIds): int
    {
        $absorbedIds = array_values(array_filter(array_map('intval', $absorbedIds), fn ($id) => $id > 0 && $id !== $keeperId));
        if (empty($absorbedIds)) {
            return 0;
        }

        // Refuse to run against a keeper that doesn't exist -- the FK
        // updates below would leave dangling references on Postgres and
        // succeed-with-no-effect on MySQL, both of which are bugs
        // masquerading as a merge.  A missing keeper is caller error.
        if (!Location::whereKey($keeperId)->exists()) {
            return 0;
        }

        // Only merge ids that actually exist -- otherwise the return
        // value overstates work done (e.g. count($absorbedIds) = 1 for
        // an already-deleted row).
        $absorbedIds = Location::whereIn('id', $absorbedIds)->pluck('id')->all();
        if (empty($absorbedIds)) {
            return 0;
        }

        DB::transaction(function () use ($keeperId, $absorbedIds) {
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

            Location::whereIn('id', $absorbedIds)->delete(); // soft-delete
        });

        return count($absorbedIds);
    }

    /**
     * Normalised cluster key for one location -- same shape the bulk
     * importer / audit command / dedupe command all use so nothing
     * clusters two ways depending on caller.
     */
    public function clusterKey(Location $location): string
    {
        $name = preg_replace('/[^a-z0-9]/', '', strtolower((string) $location->company_name));
        $addr = preg_replace('/[^a-z0-9]/', '', strtolower((string) $location->address));
        return ($location->company_id ?? 'NULL') . '|' . $name . '|' . $addr;
    }

    // -----------------------------------------------------------------
    // Composite unique helpers
    // -----------------------------------------------------------------

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
                // Keeper already has this lane -- re-point FKs (e.g.
                // transport_jobs.transport_route_id) then drop the dupe.
                $this->retireCompositeRow($table, (int) $row->id, $keeperRowId);
                continue;
            }

            // SAVEPOINT: on Postgres a unique violation aborts the
            // whole transaction unless we roll back to a savepoint.
            $driver = DB::connection()->getDriverName();
            $sp = 'locmerge_' . $row->id;
            if ($driver === 'pgsql') {
                DB::statement("SAVEPOINT {$sp}");
            }
            try {
                DB::table($table)->where('id', $row->id)->update([$col => $keeperId]);
                if ($driver === 'pgsql') {
                    DB::statement("RELEASE SAVEPOINT {$sp}");
                }
            } catch (\Throwable $e) {
                if ($driver === 'pgsql') {
                    DB::statement("ROLLBACK TO SAVEPOINT {$sp}");
                }
                // Collision the pre-check missed -- find keeper row and retire.
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

    /**
     * Union of every distinct location id referenced by any FK we know
     * about.  Used by `findUnusedLocations` and by callers that want a
     * quick "does anything at all point at this row?" check.
     */
    private function collectReferencedIds(): array
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
        return $allReferenced;
    }

    private function tableHasColumn(string $table, string $column): bool
    {
        return Schema::hasTable($table) && Schema::hasColumn($table, $column);
    }
}
