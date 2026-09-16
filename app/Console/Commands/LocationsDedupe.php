<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Location;
use App\Services\AuditService;
use App\Services\LocationMergeService;
use Illuminate\Console\Command;

/**
 * Merge duplicate locations within each company.
 *
 * Cluster definition: same company_id + same normalised (company_name +
 * address) -- exactly the key the JobBulkImporter dedupe cache (and the
 * audit command) use.  For each cluster we:
 *
 *   - pick the keeper (highest FK reference count; tie-break: lowest id)
 *   - re-point every FK on the absorbed rows to the keeper
 *   - on composite-unique tables, merge-or-delete
 *   - soft-delete the absorbed location rows
 *   - write a single audit-log entry per cluster
 *
 * All the heavy lifting lives in `App\Services\LocationMergeService` --
 * this command is intentionally a thin CLI over that service so the
 * "Clean up address book" UI can reuse the exact same merge behaviour
 * without shelling out to artisan.
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

    public function __construct(private LocationMergeService $merger)
    {
        parent::__construct();
    }

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

        $clusters = $this->merger->findDuplicateClusters($companyId);

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
                    $merged = $this->merger->mergeCluster((int) $keeper['id'], $absorbedIds);

                    AuditService::log('locations_merged', 'location', $keeper['id'], null, [
                        'company_name' => $companyName,
                        'keeper_id' => $keeper['id'],
                        'absorbed_ids' => $absorbedIds,
                    ]);

                    $totalAbsorbed += $merged;
                } catch (\Throwable $e) {
                    // Prefer the root cause -- on Postgres a swallowed
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
            $unused = $this->merger->findUnusedLocations($companyId);
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
