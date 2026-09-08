<?php

namespace App\Domain\Operations\Queries;

use App\Domain\Operations\OperationsFilters;
use App\Enums\StageGroup;
use App\Models\Job;

/**
 * Per-stage-group p50 / p90 dwell time, in hours.
 *
 * Dwell = now() - status_entered_at for every active job. Percentiles
 * are computed in PHP so the same query works on Postgres and SQLite
 * (Postgres' percentile_cont would be more efficient but only in
 * production).
 *
 * Snapshot is state-right-now, not window-restricted, because ops
 * cares about how long the CURRENT stalls have lasted, not what
 * happened last month.
 */
class DwellDistributionQuery
{
    /**
     * @return array<string, array{group:StageGroup, label:string, count:int, p50_hours:int, p90_hours:int, max_hours:int}>
     */
    public function get(OperationsFilters $filters): array
    {
        $now = now();

        $result = [];
        foreach (StageGroup::pipelineGroups() as $group) {
            $rows = $filters->applyEntityScope(Job::query())
                ->whereNull('deleted_at')
                ->whereIn('status', $group->statusValues())
                ->whereNotNull('status_entered_at')
                ->orderBy('status_entered_at', 'asc')
                ->pluck('status_entered_at');

            $hours = $rows
                ->map(fn ($ts) => (int) $ts?->diffInHours($now))
                ->sort()
                ->values()
                ->all();

            $count = count($hours);

            $result[$group->value] = [
                'group'     => $group,
                'label'     => $group->label(),
                'count'     => $count,
                'p50_hours' => $count > 0 ? $this->percentile($hours, 0.50) : 0,
                'p90_hours' => $count > 0 ? $this->percentile($hours, 0.90) : 0,
                'max_hours' => $count > 0 ? end($hours) : 0,
            ];
        }

        return $result;
    }

    /**
     * @param list<int> $sortedHours pre-sorted ascending
     */
    private function percentile(array $sortedHours, float $p): int
    {
        $n = count($sortedHours);
        if ($n === 0) {
            return 0;
        }
        $rank = ($n - 1) * $p;
        $lo = (int) floor($rank);
        $hi = (int) ceil($rank);
        if ($lo === $hi) {
            return (int) $sortedHours[$lo];
        }
        // Linear interpolation
        return (int) round($sortedHours[$lo] + ($sortedHours[$hi] - $sortedHours[$lo]) * ($rank - $lo));
    }
}
