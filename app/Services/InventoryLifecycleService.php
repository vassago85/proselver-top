<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\Job;

/**
 * Central authority for inventory state transitions.
 *
 * Every status change on an `inventory` row driven by a job's lifecycle goes
 * through here. Controllers, Livewire components, and the JobObserver call
 * these methods — they never mutate inventory.status directly.
 *
 * Safe to call with jobs that have no linked inventory; each method no-ops.
 */
class InventoryLifecycleService
{
    /**
     * Job moved into an in-transit state (status = collected / in_transit).
     * Flip the linked inventory row to in_transit.
     */
    public function onJobStarted(Job $job): void
    {
        if (!$job->inventory_id) {
            return;
        }

        $inventory = $job->inventory;
        if (!$inventory || $inventory->status === Inventory::STATUS_DELIVERED) {
            return;
        }

        $inventory->update(['status' => Inventory::STATUS_IN_TRANSIT]);
    }

    /**
     * Job reached delivered / completed. Decide the new inventory state based
     * on destination_type:
     *   - dealer / body_builder  → delivered (drops out of active stock)
     *   - yard                   → at_yard  (still active, sitting at a yard)
     *   - other / null           → at_storage (safe default, still active)
     *
     * Also snaps current_location_id to the job's delivery_location_id.
     */
    public function onJobCompleted(Job $job): void
    {
        if (!$job->inventory_id) {
            return;
        }

        $inventory = $job->inventory;
        if (!$inventory) {
            return;
        }

        $newStatus = match ($job->destination_type) {
            Job::DESTINATION_DEALER, Job::DESTINATION_BODY_BUILDER => Inventory::STATUS_DELIVERED,
            Job::DESTINATION_YARD => Inventory::STATUS_AT_YARD,
            default => Inventory::STATUS_AT_STORAGE,
        };

        $attributes = [
            'status' => $newStatus,
            'current_location_id' => $job->delivery_location_id ?? $inventory->current_location_id,
        ];

        if ($newStatus === Inventory::STATUS_DELIVERED) {
            $attributes['delivered_at'] = now();
            $attributes['delivered_via_job_id'] = $job->id;
        }

        $inventory->update($attributes);
    }
}
