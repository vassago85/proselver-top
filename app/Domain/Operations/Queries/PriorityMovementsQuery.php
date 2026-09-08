<?php

namespace App\Domain\Operations\Queries;

use App\Domain\Operations\OperationsFilters;
use App\Domain\Operations\ProvinceLabel;
use App\Enums\JobStatus;
use App\Enums\StageGroup;
use App\Models\Job;
use Illuminate\Support\Collection;

/**
 * The 8-ish stuck jobs on top of the priority list, plus their lane
 * grouping. Ordered by "oldest in stage" descending, so what's been
 * bleeding the longest floats to the top.
 *
 * WHY LANE-GROUPED
 * ----------------
 * Twelve independent rows all going Eastern Cape -> Gauteng is one
 * dispatch decision shown twelve times. The old dashboard did that.
 * Grouping by (origin_province, destination_province) turns the same
 * data into one row that says "Eastern Cape → Gauteng: 5 waiting,
 * oldest 1d — consolidate."
 */
class PriorityMovementsQuery
{
    /**
     * @return array{lanes: Collection, jobs: Collection}
     */
    public function get(OperationsFilters $filters, int $limit = 12): array
    {
        // Only active, pre-delivery statuses count as "priority". Once
        // a job is delivered/completed/cancelled it isn't a dispatch
        // decision anymore.
        $activeStatuses = array_values(array_unique(array_merge(
            StageGroup::Intake->statusValues(),
            StageGroup::Ready->statusValues(),
            StageGroup::Dispatched->statusValues(),
            StageGroup::OnRoad->statusValues(),
        )));

        $jobs = $filters->applyEntityScope(Job::query())
            ->whereNull('deleted_at')
            ->whereIn('status', $activeStatuses)
            ->with([
                'company:id,name,workflow_type',
                'pickupLocation:id,company_name,address,city,province',
                'deliveryLocation:id,company_name,address,city,province',
                'driver:id,name',
                'inventory:id,chassis_number,vin',
                'brand:id,name',
            ])
            ->orderByRaw('COALESCE(status_entered_at, updated_at) asc')
            ->limit($limit)
            ->get()
            ->map(function (Job $j) {
                $enteredAt = $j->status_entered_at ?? $j->updated_at;
                $hoursIn = $enteredAt ? $enteredAt->diffInHours(now()) : 0;
                $daysIn  = $enteredAt ? (int) $enteredAt->diffInDays(now()) : 0;
                $j->setAttribute('hours_in_stage', $hoursIn);
                $j->setAttribute('days_in_stage', $daysIn);
                // Canonicalise province casing before building the
                // lane key, so "Gauteng" and "GAUTENG" collapse to
                // the same corridor heading. Matches LaneSummaryQuery.
                $origin      = ProvinceLabel::canonicalise($j->pickupLocation?->province)   ?? '—';
                $destination = ProvinceLabel::canonicalise($j->deliveryLocation?->province) ?? '—';
                $j->setAttribute('lane_key', $origin . ' → ' . $destination);
                return $j;
            });

        // Group by lane so the UI can render one heading per corridor.
        $lanes = $jobs->groupBy('lane_key')->map(function ($group, $key) {
            $oldest = $group->first();
            return [
                'lane'       => $key,
                'count'      => $group->count(),
                'customers'  => $group->pluck('company.id')->unique()->filter()->count(),
                'oldest_hours' => $oldest->hours_in_stage,
                'jobs'       => $group->values(),
            ];
        })->values();

        return [
            'lanes' => $lanes,
            'jobs'  => $jobs,
        ];
    }
}
