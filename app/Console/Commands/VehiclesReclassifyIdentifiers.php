<?php

namespace App\Console\Commands;

use App\Models\Job;
use App\Models\MovementRequest;
use App\Models\DealerStock;
use App\Support\VehicleIdentifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Historical backfill: rows where a registration was captured into
 * the `vin` column get moved into `registration`.  This is the one-
 * off cleanup for the "operator typed a plate into the VIN field"
 * problem that motivated the smart-input rework.
 *
 * Safe to run multiple times -- every write is guarded on the current
 * classification of the row, so once a value has been moved to the
 * registration column it won't be picked up again.
 *
 * Tables covered:
 *   - transport_jobs       (vin nullable, registration nullable)
 *   - movement_requests    (vin nullable, registration nullable)
 *
 * `dealer_stock.vin` is NOT NULL and unique-per-dealer, so we only
 * REPORT rows that look wrong there -- fixing them needs the schema
 * change flagged in the plan.  Same story for `inventory` (its vin
 * column is optional so anything there is fine).
 */
class VehiclesReclassifyIdentifiers extends Command
{
    protected $signature = 'vehicles:reclassify-identifiers
        {--dry-run : Show what would change without writing}
        {--limit= : Optional row cap per table for a quick trial run}';

    protected $description = 'Move mis-entered registrations out of vin columns and into the correct registration column.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;

        $this->line($dry
            ? '<comment>DRY RUN</comment> — no changes will be written.'
            : '<info>LIVE RUN</info> — changes will be committed.');
        $this->newLine();

        $summary = [
            'transport_jobs' => $this->processJobs($dry, $limit),
            'movement_requests' => $this->processMovementRequests($dry, $limit),
        ];

        $this->reportDealerStock();

        $this->newLine();
        $this->line('<info>Summary</info>');
        foreach ($summary as $table => $result) {
            $this->line(sprintf(
                '  %-24s scanned=%d moved=%d cleared=%d skipped_conflict=%d',
                $table,
                $result['scanned'],
                $result['moved'],
                $result['cleared'],
                $result['skipped_conflict'],
            ));
        }

        return self::SUCCESS;
    }

    /**
     * Scan `transport_jobs`.  For every row whose `vin` value
     * classifies as a registration:
     *
     *   - registration empty              -> move vin into registration, null vin
     *   - registration equals vin          -> just null vin (duplicate cleanup)
     *   - registration set to something else -> LEAVE as-is, print for manual review
     *
     * @return array{scanned:int,moved:int,cleared:int,skipped_conflict:int}
     */
    private function processJobs(bool $dry, ?int $limit): array
    {
        $this->line('<info>transport_jobs</info>');
        $q = Job::query()->whereNotNull('vin');
        if ($limit) {
            $q->orderBy('id')->limit($limit);
        }
        return $this->processQuery($q, $dry, 'jobs');
    }

    private function processMovementRequests(bool $dry, ?int $limit): array
    {
        $this->line('<info>movement_requests</info>');
        $q = MovementRequest::query()->whereNotNull('vin');
        if ($limit) {
            $q->orderBy('id')->limit($limit);
        }
        return $this->processQuery($q, $dry, 'movement_requests');
    }

    /**
     * Common walker.  Streams the query with `each()` so we don't
     * pull the entire table into memory on a big customer.  Wraps
     * every write in the caller's DB transaction (invoked once,
     * outside the loop) so we can commit or rollback wholesale.
     */
    private function processQuery($query, bool $dry, string $label): array
    {
        $scanned = 0;
        $moved = 0;
        $cleared = 0;
        $skipped = 0;

        $work = function () use ($query, $dry, $label, &$scanned, &$moved, &$cleared, &$skipped) {
            $query->each(function ($row) use ($dry, $label, &$scanned, &$moved, &$cleared, &$skipped) {
                $scanned++;
                $vin = $row->vin;
                if (!$vin) {
                    return;
                }

                if (VehicleIdentifier::classify($vin) !== VehicleIdentifier::TYPE_REGISTRATION) {
                    return;
                }

                $normalisedVin = VehicleIdentifier::normalise($vin);
                $normalisedReg = VehicleIdentifier::normalise($row->registration);

                if ($normalisedReg === '' || $normalisedReg === $normalisedVin) {
                    // Safe to move.
                    $moved++;
                    if ($normalisedReg === $normalisedVin) {
                        $cleared++;
                    }
                    $this->line(sprintf(
                        '  [%s id=%d] vin=%s reg=%s -> reg=%s vin=null',
                        $label,
                        $row->id,
                        $vin,
                        $row->registration ?? '(null)',
                        $normalisedVin,
                    ));
                    if (!$dry) {
                        $row->registration = $normalisedVin;
                        $row->vin = null;
                        $row->saveQuietly();
                    }
                    return;
                }

                // Reg is set to something different — refuse to
                // clobber it, flag for manual review.  Ops needs to
                // decide which of the two values is real.
                $skipped++;
                $this->warn(sprintf(
                    '  [%s id=%d] SKIP: vin=%s classifies as reg but registration already holds %s',
                    $label,
                    $row->id,
                    $vin,
                    $row->registration,
                ));
            });
        };

        if ($dry) {
            $work();
        } else {
            DB::transaction($work);
        }

        return [
            'scanned' => $scanned,
            'moved' => $moved,
            'cleared' => $cleared,
            'skipped_conflict' => $skipped,
        ];
    }

    /**
     * dealer_stock is read-only from this command -- the vin column
     * there is NOT NULL and part of a unique index, so nulling it
     * would break both.  Report anything that looks suspicious so
     * the DMS admin can chase it up manually.
     */
    private function reportDealerStock(): void
    {
        $this->newLine();
        $this->line('<info>dealer_stock (report only)</info>');

        $suspicious = 0;
        DealerStock::query()->whereNotNull('vin')->each(function (DealerStock $stock) use (&$suspicious) {
            if (VehicleIdentifier::classify($stock->vin) === VehicleIdentifier::TYPE_REGISTRATION) {
                $suspicious++;
                $this->warn(sprintf(
                    '  [dealer_stock id=%d dealer=%s] vin=%s looks like a registration; reg=%s',
                    $stock->id,
                    $stock->dealer_company_id ?? '(unassigned)',
                    $stock->vin,
                    $stock->registration ?? '(null)',
                ));
            }
        });

        if ($suspicious === 0) {
            $this->line('  <fg=green>no suspicious rows found</>');
        } else {
            $this->line("  <fg=yellow>{$suspicious} suspicious row(s) -- schema change required to fix (see plan).</>");
        }
    }
}
