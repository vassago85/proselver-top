<?php

namespace App\Observers;

use App\Models\DealerStock;
use App\Models\Job;

/**
 * One-way bridge from transport_jobs to dealer_stock.  Whenever a
 * job is created / updated / archived, this observer looks for a
 * matching dealer_stock row (same dealer_company_id + same VIN)
 * and adjusts its current_location_type / current_job_id /
 * delivered_at accordingly.
 *
 * IMPORTANT
 * - The observer NEVER writes back to the job.  It can only update
 *   dealer_stock.  This is the constraint the plan calls out:
 *   "Don't break proselver / transport side."
 * - If a job has no matching dealer_stock row (e.g. proselver is
 *   moving a non-dealer customer's vehicle), the observer is a
 *   no-op.  Proselver-side movements stay byte-for-byte identical
 *   to today.
 * - VINs are normalised (uppercase + trimmed) before matching so
 *   minor data-entry variation doesn't break the link.
 */
class DealerStockMovementLinker
{
    /** Pre-collection job statuses -- the vehicle is at the
     *  dealer's premises but a movement is planned / dispatched. */
    private const PRE_COLLECTION_STATUSES = [
        Job::STATUS_RECEIVED,
        Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION,
        Job::STATUS_CONFIRMATION_ISSUE,
        Job::STATUS_CONFIRMED,
        Job::STATUS_PLANNED,
        Job::STATUS_DRIVER_ASSIGNED,
        Job::STATUS_READY_FOR_COLLECTION,
    ];

    /** In-transit statuses -- the vehicle is on the road. */
    private const IN_TRANSIT_STATUSES = [
        Job::STATUS_COLLECTED,
        Job::STATUS_IN_TRANSIT,
        Job::STATUS_IN_PROGRESS,
    ];

    /** Delivered statuses -- the vehicle has landed at destination. */
    private const DELIVERED_STATUSES = [
        Job::STATUS_DELIVERED,
        Job::STATUS_COMPLETED,
    ];

    public function created(Job $job): void
    {
        $this->reconcile($job);
    }

    public function updated(Job $job): void
    {
        if (!$job->wasChanged(['status', 'destination_type', 'delivery_location_id', 'archived_at', 'vin', 'registration', 'company_id'])) {
            return;
        }
        $this->reconcile($job);
    }

    /**
     * Locate the dealer_stock row for this job (if any) and swing
     * its bucket to match the job's current state.  Idempotent.
     */
    protected function reconcile(Job $job): void
    {
        $stock = $this->resolveStock($job);
        if (!$stock) {
            return;
        }

        // Archived jobs revert the stock back to whatever bucket it
        // was in before the job started.  Best-effort -- we use
        // previous_location_type which is updated on every transition
        // away from a "stable" bucket below.
        if ($job->archived_at) {
            $this->restorePrevious($stock);
            return;
        }

        $status = $job->status;

        if (in_array($status, [Job::STATUS_CANCELLED], true)) {
            $this->restorePrevious($stock);
            return;
        }

        if (in_array($status, self::DELIVERED_STATUSES, true)) {
            $this->applyDelivered($stock, $job);
            return;
        }

        if (in_array($status, self::IN_TRANSIT_STATUSES, true)) {
            $this->applyInTransit($stock, $job);
            return;
        }

        if (in_array($status, self::PRE_COLLECTION_STATUSES, true)) {
            $this->applyScheduled($stock, $job);
            return;
        }
    }

    /**
     * Look up the dealer_stock row that owns this job's vehicle.
     * Match strategy: identical dealer_company_id AND identical VIN
     * OR registration (after uppercase + trim).  Returns null when
     * no row matches -- the linker is a no-op for non-dealer
     * movements.  VIN is preferred over registration because it's
     * the ledger's unique key and registrations can change over a
     * vehicle's life (temp trader plate → permanent plate); we only
     * fall back to reg when VIN wasn't captured or didn't hit.
     */
    protected function resolveStock(Job $job): ?DealerStock
    {
        $vin = $job->vin ? strtoupper(trim($job->vin)) : null;
        $reg = $job->registration ? strtoupper(trim($job->registration)) : null;
        if ((!$vin && !$reg) || !$job->company_id) {
            return null;
        }

        // Primary match: dealer's own row for this VIN (canonical).
        if ($vin) {
            $stock = DealerStock::where('dealer_company_id', $job->company_id)
                ->where('vin', $vin)
                ->first();
            if ($stock) {
                return $stock;
            }
        }

        // Reg-only match: booking captured no VIN, but the ledger
        // knows this plate.  This is the whole point of the VIN /
        // Registration rework -- a dealer booking against just the
        // plate should still hit their own stock ledger.
        if ($reg) {
            $stock = DealerStock::where('dealer_company_id', $job->company_id)
                ->whereRaw('UPPER(COALESCE(registration, \'\')) = ?', [$reg])
                ->first();
            if ($stock) {
                return $stock;
            }
        }

        // Fallback: an unassigned arrival (OEM-direct chassis sitting
        // at a body builder, recorded by the BB before the dealer
        // was known).  When the job that lifts it comes through with
        // company_id = a dealer, we claim the row by stamping the
        // dealer onto it.
        //
        // Refuse to claim if ANY dealer already holds this VIN -- the
        // data is ambiguous (could be the same chassis with a
        // duplicate record, could be a VIN typo).  Bailing avoids
        // accidentally re-assigning a vehicle that's already on a
        // different dealer's books.
        if ($vin) {
            $unclaimed = DealerStock::whereNull('dealer_company_id')
                ->where('vin', $vin)
                ->first();
            if ($unclaimed) {
                $anyOtherDealerOwnsVin = DealerStock::whereNotNull('dealer_company_id')
                    ->where('vin', $vin)
                    ->exists();
                if (!$anyOtherDealerOwnsVin) {
                    $unclaimed->dealer_company_id = $job->company_id;
                    $unclaimed->save();
                    return $unclaimed->fresh();
                }
            }
        }

        return null;
    }

    protected function applyScheduled(DealerStock $stock, Job $job): void
    {
        // "Scheduled for movement" doesn't get its own
        // current_location_type -- the vehicle is still physically
        // at premises (or wherever it was) until the truck collects
        // it.  We just stamp the job link so the dashboard's
        // scopeScheduledForMovement() query can find it.
        $stock->current_job_id = $job->id;
        $stock->save();
    }

    protected function applyInTransit(DealerStock $stock, Job $job): void
    {
        // Snapshot the prior bucket so a cancellation can restore
        // it.  We only update previous_location_type when leaving a
        // "stable" bucket -- premises / body_builder / storage /
        // on_demo / delivered -- not when bouncing between
        // in_transit states.
        if ($stock->current_location_type !== DealerStock::LOCATION_IN_TRANSIT) {
            $stock->previous_location_type = $stock->current_location_type;
        }
        $stock->current_location_type = DealerStock::LOCATION_IN_TRANSIT;
        $stock->current_job_id = $job->id;
        $stock->save();
    }

    protected function applyDelivered(DealerStock $stock, Job $job): void
    {
        $bucket = $this->bucketForDestinationType($job->destination_type);

        $stock->previous_location_type = $bucket;
        $stock->current_location_type = $bucket;
        $stock->current_location_id   = $job->delivery_location_id;
        $stock->current_job_id        = $job->id;
        if ($bucket === DealerStock::LOCATION_DELIVERED) {
            $stock->delivered_at = $job->delivered_at ?? now();
        }
        $stock->save();
    }

    protected function restorePrevious(DealerStock $stock): void
    {
        $stock->current_location_type = $stock->previous_location_type
            ?? DealerStock::LOCATION_PREMISES;
        $stock->current_job_id = null;
        $stock->save();
    }

    /**
     * Map Job::DESTINATION_* onto the dealer_stock physical bucket.
     * DESTINATION_DEALER means "back at a dealer for final
     * delivery" -- the vehicle is off the books from a movement
     * standpoint and tagged 'delivered' so it shows up under the
     * "Recently delivered" card (which uses sold_at, not
     * delivered_at, but a sale tied to a delivered movement is the
     * common flow).
     */
    protected function bucketForDestinationType(?string $destinationType): string
    {
        return match ($destinationType) {
            Job::DESTINATION_BODY_BUILDER => DealerStock::LOCATION_BODY_BUILDER,
            Job::DESTINATION_YARD,
            Job::DESTINATION_OTHER        => DealerStock::LOCATION_STORAGE,
            Job::DESTINATION_DEALER       => DealerStock::LOCATION_DELIVERED,
            Job::DESTINATION_ROUND_TRIP   => DealerStock::LOCATION_PREMISES,
            default                        => DealerStock::LOCATION_PREMISES,
        };
    }
}
