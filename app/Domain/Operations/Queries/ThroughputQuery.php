<?php

namespace App\Domain\Operations\Queries;

use App\Domain\Operations\OperationsFilters;
use App\Enums\JobStatus;
use App\Models\Job;
use Illuminate\Support\Carbon;

/**
 * Delivered / Scheduled inside the performance window, plus an
 * ageing split of the gap.
 *
 * The old dashboard shipped a single "throughput %" bar that let a
 * quiet week hide a scheduling backlog three months deep. The gap
 * ageing split (1-7d / 8-30d / 30d+) makes that visible so you can
 * tell "we're slow this week" from "we've been carrying dead
 * schedules since May".
 */
class ThroughputQuery
{
    /**
     * @return array{
     *   scheduled: int,
     *   delivered: int,
     *   gap: int,
     *   gap_1_7d: int,
     *   gap_8_30d: int,
     *   gap_30d_plus: int,
     *   throughput_pct: int,
     * }
     */
    public function get(OperationsFilters $filters): array
    {
        [$from, $to] = $this->window($filters);

        $base = $filters->applyEntityScope(Job::query())->whereNull('deleted_at');

        // Scheduled in window (as originally planned).
        $scheduled = (clone $base)
            ->whereBetween('scheduled_date', [$from->toDateString(), $to->toDateString()])
            ->count();

        // Delivered in window.
        $delivered = (clone $base)
            ->whereBetween('delivered_at', [$from, $to])
            ->count();

        // Ageing gap = things scheduled that never got delivered,
        // segmented by how old the miss is.
        $now = now();
        $gapBase = (clone $base)
            ->whereNotNull('scheduled_date')
            ->whereNull('delivered_at')
            ->whereNotIn('status', [
                JobStatus::Cancelled->value,
                JobStatus::Completed->value,
            ]);

        $gap_1_7d = (clone $gapBase)
            ->whereBetween('scheduled_date', [$now->copy()->subDays(7)->toDateString(), $now->copy()->subDay()->toDateString()])
            ->count();
        $gap_8_30d = (clone $gapBase)
            ->whereBetween('scheduled_date', [$now->copy()->subDays(30)->toDateString(), $now->copy()->subDays(8)->toDateString()])
            ->count();
        $gap_30d_plus = (clone $gapBase)
            ->where('scheduled_date', '<', $now->copy()->subDays(30)->toDateString())
            ->count();

        $gap = $gap_1_7d + $gap_8_30d + $gap_30d_plus;
        $throughputPct = $scheduled > 0 ? (int) round(($delivered / $scheduled) * 100) : 0;

        return [
            'scheduled'      => $scheduled,
            'delivered'      => $delivered,
            'gap'            => $gap,
            'gap_1_7d'       => $gap_1_7d,
            'gap_8_30d'      => $gap_8_30d,
            'gap_30d_plus'   => $gap_30d_plus,
            'throughput_pct' => $throughputPct,
        ];
    }

    /**
     * @return array{0:Carbon, 1:Carbon}
     */
    private function window(OperationsFilters $filters): array
    {
        $days = (int) config('operations.default_range_days', 7);
        $from = $filters->from ?? now()->subDays($days - 1)->startOfDay();
        $to   = $filters->to   ?? now()->endOfDay();
        return [$from, $to];
    }
}
