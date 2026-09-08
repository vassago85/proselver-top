<?php

namespace App\Domain\Operations\Queries;

use App\Domain\Operations\OperationsFilters;
use App\Enums\JobStatus;
use App\Models\Job;
use Illuminate\Support\Carbon;

/**
 * On-time coverage panel data.
 *
 * A raw on-time % is only reported when at least COVERAGE_THRESHOLD
 * of delivered jobs have a resolvable target (promised_delivery_at
 * OR sla_hours OR company.default_sla_hours). Below that, the panel
 * shows the coverage gap — never a made-up %.
 *
 * Resolution order (in SQL, single query):
 *   1. transport_jobs.promised_delivery_at
 *   2. collected_at + transport_jobs.sla_hours * 1 hour
 *   3. collected_at + companies.default_sla_hours * 1 hour
 *   4. NULL → not measurable
 */
class OnTimeQuery
{
    /**
     * Minimum share of delivered jobs with a resolvable target for
     * the tile to publish an on-time %. Anything below and the tile
     * asks ops to fill in the missing targets instead.
     */
    public const COVERAGE_THRESHOLD = 0.80;

    /**
     * @return array{
     *   delivered:int,
     *   measurable:int,
     *   on_time:int,
     *   late:int,
     *   coverage_pct:int,
     *   on_time_pct:?int,
     *   is_measurable:bool,
     *   missing_target:int
     * }
     */
    public function get(OperationsFilters $filters): array
    {
        [$from, $to] = $this->window($filters);

        // We trust `delivered_at` as the source of truth, NOT the
        // status column. On this workflow `delivered` is a transient
        // stage — rows auto-progress to `completed` as soon as POD
        // is verified, so filtering by `status = 'delivered'` misses
        // ~99% of actual deliveries. `delivered_at IS NOT NULL` +
        // NOT IN (cancelled) is the honest predicate.
        $rows = $filters->applyEntityScope(Job::query())
            ->whereNull('transport_jobs.deleted_at')
            ->whereNotNull('transport_jobs.delivered_at')
            ->whereBetween('transport_jobs.delivered_at', [$from, $to])
            ->whereNotIn('transport_jobs.status', [JobStatus::Cancelled->value])
            ->leftJoin('companies as cust', 'cust.id', '=', 'transport_jobs.company_id')
            ->get([
                'transport_jobs.id',
                'transport_jobs.collected_at',
                'transport_jobs.delivered_at',
                'transport_jobs.promised_delivery_at',
                'transport_jobs.sla_hours',
                'cust.default_sla_hours as company_sla_hours',
            ]);

        $delivered   = $rows->count();
        $measurable  = 0;
        $onTime      = 0;
        $late        = 0;
        $missing     = 0;

        foreach ($rows as $row) {
            $target = $this->resolveTarget($row);
            if ($target === null) {
                $missing++;
                continue;
            }
            $measurable++;
            if ($row->delivered_at !== null && Carbon::parse($row->delivered_at)->lessThanOrEqualTo($target)) {
                $onTime++;
            } else {
                $late++;
            }
        }

        $coveragePct = $delivered > 0 ? (int) round(($measurable / $delivered) * 100) : 0;
        $isMeasurable = $delivered > 0 && ($measurable / $delivered) >= self::COVERAGE_THRESHOLD;
        $onTimePct = ($isMeasurable && $measurable > 0)
            ? (int) round(($onTime / $measurable) * 100)
            : null;

        return [
            'delivered'      => $delivered,
            'measurable'     => $measurable,
            'on_time'        => $onTime,
            'late'           => $late,
            'coverage_pct'   => $coveragePct,
            'on_time_pct'    => $onTimePct,
            'is_measurable'  => $isMeasurable,
            'missing_target' => $missing,
        ];
    }

    /**
     * Apply the resolution order (promised > per-job SLA > per-company SLA)
     * for a single row. Falls back to null when no source resolves.
     */
    private function resolveTarget(object $row): ?Carbon
    {
        if (! empty($row->promised_delivery_at)) {
            return Carbon::parse($row->promised_delivery_at);
        }

        if (empty($row->collected_at)) {
            return null; // no anchor to add hours to
        }

        $anchor = Carbon::parse($row->collected_at);

        if (! empty($row->sla_hours)) {
            return $anchor->copy()->addHours((int) $row->sla_hours);
        }

        if (! empty($row->company_sla_hours)) {
            return $anchor->copy()->addHours((int) $row->company_sla_hours);
        }

        return null;
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
