<?php

namespace App\Domain\Operations\Queries;

use App\Domain\Operations\OperationsFilters;
use App\Enums\StageGroup;
use App\Models\Job;
use Illuminate\Support\Collection;

/**
 * Top-8 waiting lanes: pickup province -> delivery province.
 *
 * A lane is a dispatch decision — same origin, same destination. The
 * point is to spot consolidation opportunities: five separate rows
 * going Eastern Cape -> Gauteng is one truck, if you notice it.
 */
class LaneSummaryQuery
{
    public function get(OperationsFilters $filters, int $limit = 8): Collection
    {
        $waitingStatuses = array_merge(
            StageGroup::Intake->statusValues(),
            StageGroup::Ready->statusValues(),
        );

        // Join in locations twice for origin + destination provinces.
        // Fully-qualify every column reference — an unqualified
        // "deleted_at" throws "ambiguous column" because locations
        // carries its own soft-deletes column.
        return $filters->applyEntityScope(Job::query())
            ->whereNull('transport_jobs.deleted_at')
            ->whereIn('transport_jobs.status', $waitingStatuses)
            ->join('locations as pl', 'pl.id', '=', 'transport_jobs.pickup_location_id')
            ->join('locations as dl', 'dl.id', '=', 'transport_jobs.delivery_location_id')
            ->whereNotNull('pl.province')
            ->whereNotNull('dl.province')
            ->selectRaw(
                'pl.province as origin_province, dl.province as destination_province, '
                . 'count(*) as jobs, count(distinct transport_jobs.company_id) as customers'
            )
            ->groupBy('pl.province', 'dl.province')
            ->orderByDesc('jobs')
            ->limit($limit)
            ->get();
    }
}
