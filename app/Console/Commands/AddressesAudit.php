<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Location;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only diagnostic.  Surfaces duplicate / unused entries in the
 * address book so we can plan a clean-up.  Doesn't change a row.
 *
 * Usage:
 *   php artisan addresses:audit                      -- top 20 dupe clusters across all companies
 *   php artisan addresses:audit --company="FAW"      -- scoped to one company
 *   php artisan addresses:audit --limit=50            -- bigger top-N
 *   php artisan addresses:audit --unused              -- include the unused-rows section
 *
 * "Duplicate" here means: same company_id, same normalised
 * company_name + address (lowercased, alphanumerics only).  That's the
 * same key the JobBulkImporter's dedupe cache now uses.
 */
class AddressesAudit extends Command
{
    protected $signature = 'addresses:audit
        {--company= : Limit to a company id or (partial) name}
        {--limit=20 : Top N dupe clusters to print}
        {--unused : Also show counts of locations with no FK references}';

    protected $description = 'Diagnose duplicate / unused entries in the address book (read-only).';

    /**
     * Tables that hold an FK into locations.id, mapped to a single
     * column name so the audit can count references per location.
     */
    private const REFERENCE_TABLES = [
        ['table' => 'transport_jobs',         'col' => 'pickup_location_id'],
        ['table' => 'transport_jobs',         'col' => 'delivery_location_id'],
        ['table' => 'transport_jobs',         'col' => 'yard_location_id'],
        ['table' => 'transport_routes',       'col' => 'origin_location_id'],
        ['table' => 'transport_routes',       'col' => 'destination_location_id'],
        ['table' => 'dealer_stock',           'col' => 'current_location_id'],
        ['table' => 'inventory',              'col' => 'current_location_id'],
        ['table' => 'trips',                  'col' => 'start_location_id'],
        ['table' => 'trips',                  'col' => 'end_location_id'],
        ['table' => 'trip_stops',             'col' => 'location_id'],
        ['table' => 'body_builder_links',     'col' => 'pickup_location_id'],
        ['table' => 'body_builder_links',     'col' => 'delivery_location_id'],
        ['table' => 'movement_requests',      'col' => 'pickup_location_id'],
        ['table' => 'movement_requests',      'col' => 'delivery_location_id'],
        ['table' => 'route_toll_plaza_hints', 'col' => 'pickup_location_id'],
        ['table' => 'route_toll_plaza_hints', 'col' => 'delivery_location_id'],
        ['table' => 'route_estimates',        'col' => 'pickup_location_id'],
        ['table' => 'route_estimates',        'col' => 'delivery_location_id'],
        ['table' => 'company_users',          'col' => 'location_id'],
    ];

    public function handle(): int
    {
        $companyFilter = trim((string) $this->option('company'));
        $limit = max(1, (int) $this->option('limit'));
        $includeUnused = (bool) $this->option('unused');

        $companyId = null;
        $companyName = null;
        if ($companyFilter !== '') {
            $resolved = $this->resolveCompany($companyFilter);
            if (!$resolved) {
                return self::FAILURE;
            }
            [$companyId, $companyName] = [$resolved->id, $resolved->name];
            $this->info("Scope: company {$companyName} (#{$companyId})");
        } else {
            $this->info('Scope: every company');
        }

        // --------------------------------------------------------
        // 1. Headline counts
        // --------------------------------------------------------
        $base = Location::query();
        if ($companyId) {
            $base->where('company_id', $companyId);
        }
        $totalActive = (clone $base)->count();
        $totalDeleted = (clone $base)->onlyTrashed()->count();

        $this->info('');
        $this->info('Headline counts');
        $this->info('---------------');
        $this->line(sprintf('  Active locations:      %d', $totalActive));
        $this->line(sprintf('  Soft-deleted:          %d', $totalDeleted));

        // --------------------------------------------------------
        // 2. Top companies by location count (only useful unscoped)
        // --------------------------------------------------------
        if (!$companyId) {
            $top = Location::query()
                ->selectRaw('company_id, count(*) as n')
                ->groupBy('company_id')
                ->orderByDesc('n')
                ->limit(10)
                ->get();

            $this->info('');
            $this->info('Top 10 companies by address-book size');
            $this->info('-------------------------------------');
            $this->table(
                ['Company', 'Locations'],
                $top->map(function ($r) {
                    $name = $r->company_id
                        ? (Company::find($r->company_id)?->name ?? "#{$r->company_id}")
                        : '(unassigned / shared)';
                    return [$name, $r->n];
                })->all(),
            );
        }

        // --------------------------------------------------------
        // 3. Duplicate clusters (top N)
        // --------------------------------------------------------
        $this->info('');
        $this->info('Duplicate clusters (top ' . $limit . ')');
        $this->info('---------------------------------');
        $this->line('Same company + normalised (company_name + address); count > 1.');
        $this->line('Refs = total FK pointers from jobs/routes/stock/trips/etc.');
        $this->line('');

        $clusters = $this->findClusters($companyId, $limit);

        if ($clusters->isEmpty()) {
            $this->line('  (no duplicates found at this scope)');
        } else {
            $rows = [];
            $totalDupeRows = 0;
            $totalExcess = 0;
            foreach ($clusters as $idx => $cluster) {
                $companyLabel = $cluster['company_name'] ?? '(unassigned)';
                foreach ($cluster['rows'] as $i => $loc) {
                    $rows[] = [
                        $i === 0 ? ($idx + 1) : '',
                        $i === 0 ? $companyLabel : '',
                        '#' . $loc['id'],
                        $this->truncate($loc['display'], 60),
                        $loc['refs'],
                    ];
                }
                $rows[] = ['', '', '', '', ''];
                $totalDupeRows += count($cluster['rows']);
                $totalExcess += count($cluster['rows']) - 1;
            }
            $this->table(['#', 'Company', 'Loc ID', 'Name -- Address', 'Refs'], $rows);

            $this->info(sprintf(
                'Showing %d cluster(s) covering %d duplicate rows; %d row(s) could be merged away.',
                $clusters->count(), $totalDupeRows, $totalExcess,
            ));
        }

        // --------------------------------------------------------
        // 4. Unused locations
        // --------------------------------------------------------
        if ($includeUnused) {
            $this->info('');
            $this->info('Unused locations (no FK references anywhere)');
            $this->info('--------------------------------------------');

            $referencedIds = $this->collectReferencedIds($companyId);

            $unused = (clone $base)
                ->whereNotIn('id', $referencedIds)
                ->orderBy('id')
                ->get(['id', 'company_id', 'company_name', 'address']);

            $this->line(sprintf('  Total unused: %d', $unused->count()));

            if ($unused->isNotEmpty()) {
                $sample = $unused->take(20)->map(function ($l) {
                    $companyName = $l->company_id
                        ? (Company::find($l->company_id)?->name ?? "#{$l->company_id}")
                        : '(unassigned)';
                    return [
                        '#' . $l->id,
                        $companyName,
                        $this->truncate(trim(($l->company_name ?? '') . ' -- ' . ($l->address ?? '')), 70),
                    ];
                })->all();
                $this->table(['Loc ID', 'Company', 'Name -- Address'], $sample);
                if ($unused->count() > 20) {
                    $this->line('  (...and ' . ($unused->count() - 20) . ' more.)');
                }
            }
        } else {
            $this->info('');
            $this->line('Tip: add --unused to also list addresses with no FK references.');
        }

        $this->info('');
        $this->info('Done.  Read-only -- nothing was changed.');

        return self::SUCCESS;
    }

    /**
     * Build the top-N duplicate clusters by normalised (company_name + address)
     * within a company.  Returns a collection of:
     *   ['company_name' => ..., 'rows' => [['id'=>..., 'display'=>..., 'refs'=>...], ...]]
     */
    private function findClusters(?int $companyId, int $limit): \Illuminate\Support\Collection
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

        $sorted = $groups->sortByDesc(fn ($g) => $g->count())->take($limit);

        $companyNames = Company::query()
            ->whereIn('id', $sorted->flatten(1)->pluck('company_id')->unique()->filter())
            ->pluck('name', 'id');

        return $sorted->map(function ($group) use ($companyNames) {
            $first = $group->first();
            $rows = $group->map(function ($l) {
                return [
                    'id'      => $l->id,
                    'display' => trim(($l->company_name ?? '') . ' -- ' . ($l->address ?? '')),
                    'refs'    => $this->countRefs($l->id),
                ];
            })->sortByDesc('refs')->values()->all();

            return [
                'company_name' => $first->company_id ? ($companyNames[$first->company_id] ?? "#{$first->company_id}") : '(unassigned)',
                'rows'         => $rows,
            ];
        })->values();
    }

    /**
     * Single-location FK reference count across every table that
     * points at locations.id.
     */
    private function countRefs(int $locationId): int
    {
        $total = 0;
        foreach (self::REFERENCE_TABLES as $ref) {
            try {
                $total += (int) DB::table($ref['table'])
                    ->where($ref['col'], $locationId)
                    ->count();
            } catch (\Throwable $e) {
                // Skip silently if a table doesn't exist on this
                // environment (e.g. an optional plugin migration).
            }
        }
        return $total;
    }

    /**
     * Union of every location id that's referenced from any of the
     * reference tables -- used for the "unused" section.  Restricted
     * by company when --company is set so we only union ids for that
     * company's locations.
     */
    private function collectReferencedIds(?int $companyId): array
    {
        $allowedIds = null;
        if ($companyId !== null) {
            $allowedIds = Location::where('company_id', $companyId)
                ->pluck('id')
                ->all();
            if (empty($allowedIds)) {
                return [];
            }
        }

        $referenced = [];
        foreach (self::REFERENCE_TABLES as $ref) {
            try {
                $ids = DB::table($ref['table'])
                    ->whereNotNull($ref['col'])
                    ->when($allowedIds, fn ($q) => $q->whereIn($ref['col'], $allowedIds))
                    ->distinct()
                    ->pluck($ref['col'])
                    ->all();
                foreach ($ids as $id) {
                    $referenced[$id] = true;
                }
            } catch (\Throwable $e) {
                // table absent on this env -- skip
            }
        }
        return array_keys($referenced);
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
