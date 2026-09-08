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
 * WHY THIS RETURNS TWO COUNTS
 * ---------------------------
 * Ops came back with "13 vs 3" — the ops queue below this widget
 * showed 13 jobs on the same lane the widget said had 3. The queue
 * counts every active row (Intake through POD-pending), the widget
 * used to count only Intake + Ready. Both are defensible: the queue
 * measures "everything still moving", the widget measures "still
 * dispatchable" (once a truck is on the road you can't consolidate
 * onto it). Rather than hide the difference we now return BOTH per
 * lane so the widget can render `3 awaiting dispatch · 13 in flight`
 * and the operator can see the whole picture at a glance.
 *
 * Case-insensitive grouping: some rows carry `Gauteng`, some carry
 * `GAUTENG`. Grouping by raw value split the same lane into two
 * separate rows on the dashboard, so we group by LOWER(TRIM(...))
 * in SQL and canonicalise the labels back in PHP.
 */
class LaneSummaryQuery
{
    /**
     * @return \Illuminate\Support\Collection<int, object{
     *   origin_key: string,
     *   destination_key: string,
     *   origin_province: string,
     *   destination_province: string,
     *   jobs: int,           // pre-dispatch (Intake + Ready) — the consolidation set
     *   in_flight: int,      // Dispatched + OnRoad + Delivered/POD-pending
     *   total: int,          // sum, matches the ops queue's lane count exactly
     *   customers: int,
     * }>
     */
    public function get(OperationsFilters $filters, int $limit = 8): Collection
    {
        $waitingStatuses = array_merge(
            StageGroup::Intake->statusValues(),
            StageGroup::Ready->statusValues(),
        );

        // In-flight = every queueGroup status that is NOT in the
        // waiting set. Kept as an array of strings so the SQL CASE
        // stays portable (Postgres + SQLite friendly).
        $queueStatuses = [];
        foreach (StageGroup::queueGroups() as $g) {
            foreach ($g->statusValues() as $s) {
                $queueStatuses[] = $s;
            }
        }
        $queueStatuses = array_values(array_unique($queueStatuses));
        $waitingSet    = array_values(array_unique($waitingStatuses));
        $inFlightSet   = array_values(array_diff($queueStatuses, $waitingSet));

        // We COUNT with CASE expressions so a single grouped query
        // returns both numbers per lane, no follow-up query required.
        // Ranking is by `waiting` so the widget's stated purpose
        // (consolidation) still drives the ordering.
        $waitingCase  = "CASE WHEN transport_jobs.status IN ('" . implode("','", $waitingSet)  . "') THEN 1 END";
        $inFlightCase = $inFlightSet
            ? "CASE WHEN transport_jobs.status IN ('" . implode("','", $inFlightSet) . "') THEN 1 END"
            : 'NULL';

        // Join in locations twice for origin + destination provinces.
        // Fully-qualify every column reference — an unqualified
        // "deleted_at" throws "ambiguous column" because locations
        // carries its own soft-deletes column.
        return $filters->applyEntityScope(Job::query())
            ->whereNull('transport_jobs.deleted_at')
            ->whereIn('transport_jobs.status', $queueStatuses)
            ->join('locations as pl', 'pl.id', '=', 'transport_jobs.pickup_location_id')
            ->join('locations as dl', 'dl.id', '=', 'transport_jobs.delivery_location_id')
            ->whereNotNull('pl.province')
            ->whereNotNull('dl.province')
            ->selectRaw(
                'LOWER(TRIM(pl.province)) as origin_key, '
                . 'LOWER(TRIM(dl.province)) as destination_key, '
                . "COUNT({$waitingCase})  as jobs, "
                . "COUNT({$inFlightCase}) as in_flight, "
                . 'COUNT(*)                as total, '
                . 'COUNT(DISTINCT transport_jobs.company_id) as customers'
            )
            ->groupByRaw('LOWER(TRIM(pl.province)), LOWER(TRIM(dl.province))')
            // Rank by "waiting" first so a lane with 12 in-flight and 0
            // waiting doesn't push a lane with 3 dispatch-ready jobs off
            // the top-8. The consolidation opportunity is what the
            // widget is for; the in-flight number is context, not the
            // ranking signal.
            ->orderByDesc('jobs')
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(function ($row) {
                // Blade still reads $lane->origin_province /
                // ->destination_province — keep that contract, but
                // hand it a normalised label so the UI never shows
                // "Gauteng" and "GAUTENG" as two separate lanes.
                $row->origin_province      = ProvinceLabel::canonicalise($row->origin_key);
                $row->destination_province = ProvinceLabel::canonicalise($row->destination_key);
                $row->jobs      = (int) $row->jobs;
                $row->in_flight = (int) $row->in_flight;
                $row->total     = (int) $row->total;
                $row->customers = (int) $row->customers;
                return $row;
            });
    }
}
