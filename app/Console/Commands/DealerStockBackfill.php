<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\DealerStock;
use App\Models\Job;
use App\Observers\DealerStockMovementLinker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Seed the dealer_stock ledger from historical transport_jobs.
 *
 * For each dealer company (or one specified by --dealer=), this
 * command walks every VIN it has movements for, takes the latest
 * job per VIN, and upserts a dealer_stock row with the inferred
 * current bucket.  Subsequent imports backfill the missing
 * attributes (suffix, variant, colour, engine number).
 *
 * Idempotent.  Safe to re-run -- every upsert key is (dealer_company_id, vin)
 * and we never overwrite the commercial fields (status, sale, demo)
 * if a row already exists.
 */
class DealerStockBackfill extends Command
{
    protected $signature = 'dealer-stock:backfill
        {--dealer= : Limit to a single dealer company id}
        {--dry-run : Show what would be written without persisting}';

    protected $description = 'Seed dealer_stock from historical transport_jobs (idempotent).';

    public function handle(DealerStockMovementLinker $linker): int
    {
        $dealerId = $this->option('dealer');
        $dryRun = (bool) $this->option('dry-run');

        $companies = Company::query()
            ->when($dealerId, fn ($q) => $q->where('id', $dealerId))
            ->when(!$dealerId, fn ($q) => $q->where('type', Company::TYPE_DEALER))
            ->orderBy('id')
            ->get();

        if ($companies->isEmpty()) {
            $this->warn('No dealer companies to back-fill.');
            return self::SUCCESS;
        }

        $totalCreated = 0;
        $totalSkipped = 0;
        $totalUpdated = 0;

        foreach ($companies as $company) {
            $this->info("→ {$company->name} (#{$company->id})");

            $latestPerVin = $this->latestJobsPerVin($company->id);

            foreach ($latestPerVin as $job) {
                $vin = strtoupper(trim((string) $job->vin));
                if ($vin === '') {
                    continue;
                }

                $existing = DealerStock::where('dealer_company_id', $company->id)
                    ->where('vin', $vin)
                    ->first();

                $bucket = $this->bucketForJob($job);

                if ($dryRun) {
                    $this->line("   [dry] {$vin} → {$bucket} (job #{$job->id} status {$job->status})");
                    if ($existing) {
                        $totalUpdated++;
                    } else {
                        $totalCreated++;
                    }
                    continue;
                }

                if ($existing) {
                    // Only refresh the location bucket -- never the
                    // commercial fields, never the import attributes.
                    $existing->current_location_type = $bucket;
                    $existing->current_location_id = $job->delivery_location_id;
                    $existing->current_job_id = $job->id;
                    if ($bucket === DealerStock::LOCATION_DELIVERED) {
                        $existing->delivered_at = $job->delivered_at ?? $existing->delivered_at;
                    }
                    if ($existing->isDirty()) {
                        $existing->save();
                        $totalUpdated++;
                    } else {
                        $totalSkipped++;
                    }
                } else {
                    DealerStock::create([
                        'dealer_company_id'     => $company->id,
                        'vin'                   => $vin,
                        'brand_id'              => $job->brand_id,
                        'model_name'            => $job->model_name,
                        'current_location_type' => $bucket,
                        'current_location_id'   => $job->delivery_location_id,
                        'current_job_id'        => $job->id,
                        'delivered_at'          => $bucket === DealerStock::LOCATION_DELIVERED
                            ? $job->delivered_at
                            : null,
                        'status'                => DealerStock::STATUS_AVAILABLE,
                    ]);
                    $totalCreated++;
                }
            }
        }

        $this->info(sprintf(
            'Done. created=%d updated=%d skipped=%d%s',
            $totalCreated,
            $totalUpdated,
            $totalSkipped,
            $dryRun ? ' (dry-run)' : ''
        ));

        return self::SUCCESS;
    }

    /**
     * Per dealer, return the latest job row per VIN.  Uses a
     * single grouped query to avoid an N+1 walk.
     */
    protected function latestJobsPerVin(int $companyId): \Illuminate\Support\Collection
    {
        $latestIds = Job::where('company_id', $companyId)
            ->whereNotNull('vin')
            ->select(DB::raw('MAX(id) as id'))
            ->groupBy(DB::raw('UPPER(TRIM(vin))'))
            ->pluck('id');

        return Job::whereIn('id', $latestIds)
            ->orderBy('id')
            ->get([
                'id', 'company_id', 'vin', 'status', 'destination_type',
                'delivery_location_id', 'delivered_at', 'archived_at',
                'brand_id', 'model_name',
            ]);
    }

    /**
     * Same rules as DealerStockMovementLinker uses internally --
     * duplicated here so the command can run without booting the
     * full observer pipeline (which would re-issue updates and
     * potentially fight with the backfill's own writes).
     */
    protected function bucketForJob(Job $job): string
    {
        if ($job->archived_at || $job->status === Job::STATUS_CANCELLED) {
            return DealerStock::LOCATION_PREMISES;
        }

        if (in_array($job->status, [Job::STATUS_DELIVERED, Job::STATUS_COMPLETED], true)) {
            return match ($job->destination_type) {
                Job::DESTINATION_BODY_BUILDER => DealerStock::LOCATION_BODY_BUILDER,
                Job::DESTINATION_YARD,
                Job::DESTINATION_OTHER        => DealerStock::LOCATION_STORAGE,
                Job::DESTINATION_DEALER       => DealerStock::LOCATION_DELIVERED,
                default                        => DealerStock::LOCATION_PREMISES,
            };
        }

        if (in_array($job->status, [
            Job::STATUS_COLLECTED,
            Job::STATUS_IN_TRANSIT,
            Job::STATUS_IN_PROGRESS,
        ], true)) {
            return DealerStock::LOCATION_IN_TRANSIT;
        }

        return DealerStock::LOCATION_PREMISES;
    }
}
