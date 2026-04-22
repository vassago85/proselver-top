<?php

namespace App\Observers;

use App\Models\Job;
use App\Services\InventoryLifecycleService;

/**
 * Bridges Job lifecycle events into the inventory model.
 *
 * Registered conditionally in AppServiceProvider::boot() based on
 * config('features.inventory_link'). When the flag is off this class is
 * never attached, so the dispatch flow is byte-for-byte identical to
 * how it behaved before the inventory foundation landed.
 */
class JobObserver
{
    private const TRANSIT_STATUSES = [
        Job::STATUS_COLLECTED,
        Job::STATUS_IN_TRANSIT,
        Job::STATUS_IN_PROGRESS,
    ];

    private const COMPLETED_STATUSES = [
        Job::STATUS_DELIVERED,
        Job::STATUS_COMPLETED,
    ];

    public function __construct(private InventoryLifecycleService $lifecycle)
    {
    }

    public function updated(Job $job): void
    {
        if (!$job->wasChanged('status')) {
            return;
        }

        $status = $job->status;

        if (in_array($status, self::TRANSIT_STATUSES, true)) {
            $this->lifecycle->onJobStarted($job);
            return;
        }

        if (in_array($status, self::COMPLETED_STATUSES, true)) {
            $this->lifecycle->onJobCompleted($job);
        }
    }
}
