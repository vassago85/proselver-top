<?php

namespace App\Domain\Operations\Queries;

use App\Domain\Operations\OperationsFilters;
use App\Domain\Operations\ProvinceLabel;
use App\Enums\StageGroup;
use App\Models\Job;
use Illuminate\Support\Collection;

/**
 * Top-8 waiting lanes: pickup province -> delivery province.
 *
 * A lane is a dispatch decision — same origin, same destination. The
 * point is to spot consolidation opportunities: five separate rows
 * going Eastern Cape -> Gauteng is one truck, if you notice it.
 *
 * Case-insensitive grouping: some rows carry `Gauteng`, some carry
 * `GAUTENG`. Grouping by raw value split the same lane into two
 * separate rows on the dashboard, so we group by LOWER(TRIM(...))
 * in SQL and canonicalise the labels back in PHP.
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
                'LOWER(TRIM(pl.province)) as origin_key, '
                . 'LOWER(TRIM(dl.province)) as destination_key, '
                . 'count(*) as jobs, '
                . 'count(distinct transport_jobs.company_id) as customers'
            )
            ->groupByRaw('LOWER(TRIM(pl.province)), LOWER(TRIM(dl.province))')
            ->orderByDesc('jobs')
            ->limit($limit)
            ->get()
            ->map(function ($row) {
                // Blade still reads $lane->origin_province /
                // ->destination_province — keep that contract, but
                // hand it a normalised label so the UI never shows
                // "Gauteng" and "GAUTENG" as two separate lanes.
                $row->origin_province      = ProvinceLabel::canonicalise($row->origin_key);
                $row->destination_province = ProvinceLabel::canonicalise($row->destination_key);
                return $row;
            });
    }
}
