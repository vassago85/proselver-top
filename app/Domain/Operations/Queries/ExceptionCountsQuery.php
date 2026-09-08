<?php

namespace App\Domain\Operations\Queries;

use App\Domain\Operations\OperationsFilters;
use App\Domain\Operations\OperationsThresholds;
use App\Enums\JobStatus;
use App\Models\Job;
use Illuminate\Support\Carbon;

/**
 * Six exception buckets + at_risk, in a single aggregate SELECT.
 *
 * WHY THIS EXISTS
 * ---------------
 * The old dashboard fired one count() per bucket (six queries per
 * render) and aged jobs on `updated_at`, which resets every time
 * anyone touches the row. Both bugs are fixed here:
 *
 *   - One query, using SUM(CASE WHEN … THEN 1 ELSE 0 END) so it runs
 *     identically on Postgres AND on SQLite (tests).
 *   - Ages on `status_entered_at`, which is only bumped on status
 *     change (see the Job model's `updating` observer).
 *
 * Buckets are mutually exclusive by status, so `at_risk` = the plain
 * sum of the six. If a future bucket introduces overlap, promote
 * this to a COUNT(DISTINCT CASE …) — but do NOT add a seventh
 * independent count that can drift from the six on the page.
 */
class ExceptionCountsQuery
{
    /**
     * @return array{
     *   awaiting_confirmation:int,
     *   confirmation_issue:int,
     *   ready_no_driver:int,
     *   dispatched_not_collected:int,
     *   long_in_transit:int,
     *   pod_pending:int,
     *   at_risk:int
     * }
     */
    public function get(OperationsFilters $filters, ?array $thresholds = null): array
    {
        $t = $thresholds ?? OperationsThresholds::forExceptions();
        $now = Carbon::now();

        // Cutoff timestamps, computed in PHP so the query is portable.
        $cutoffs = [
            'awaiting_confirmation'    => $now->copy()->subHours((int) $t['awaiting_confirmation_hours']),
            'ready_no_driver'          => $now->copy()->subHours((int) $t['ready_no_driver_hours']),
            'dispatched_not_collected' => $now->copy()->subHours((int) $t['dispatched_not_collected_hours']),
            'long_in_transit'          => $now->copy()->subHours((int) $t['long_in_transit_hours']),
            'pod_pending'              => $now->copy()->subHours((int) $t['pod_pending_hours']),
        ];

        $base = $filters->applyEntityScope(Job::query())
            ->whereNull('deleted_at');

        $sql = <<<'SQL'
SUM(CASE WHEN status = ? AND (status_entered_at IS NULL OR status_entered_at <= ?) THEN 1 ELSE 0 END) AS awaiting_confirmation,
SUM(CASE WHEN status = ? THEN 1 ELSE 0 END)                                                             AS confirmation_issue,
SUM(CASE WHEN status IN (?, ?) AND (status_entered_at IS NULL OR status_entered_at <= ?) THEN 1 ELSE 0 END) AS ready_no_driver,
SUM(CASE WHEN status IN (?, ?) AND (status_entered_at IS NULL OR status_entered_at <= ?) THEN 1 ELSE 0 END) AS dispatched_not_collected,
SUM(CASE WHEN status = ? AND (status_entered_at IS NULL OR status_entered_at <= ?) THEN 1 ELSE 0 END) AS long_in_transit,
SUM(CASE WHEN status = ? AND completed_at IS NULL AND (delivered_at IS NULL OR delivered_at <= ?) THEN 1 ELSE 0 END) AS pod_pending
SQL;

        $bindings = [
            JobStatus::AwaitingCustomerConfirmation->value, $cutoffs['awaiting_confirmation'],
            JobStatus::ConfirmationIssue->value,
            JobStatus::Confirmed->value, JobStatus::Planned->value, $cutoffs['ready_no_driver'],
            JobStatus::DriverAssigned->value, JobStatus::ReadyForCollection->value, $cutoffs['dispatched_not_collected'],
            JobStatus::InTransit->value, $cutoffs['long_in_transit'],
            JobStatus::Delivered->value, $cutoffs['pod_pending'],
        ];

        // Drop to the underlying query builder before calling first() so
        // the driver returns an stdClass keyed by the aggregate aliases
        // rather than an Eloquent Model (which hides them behind the
        // model's attribute-array). Same reason we use ->getQuery()
        // everywhere aggregation is the point of the query.
        $row = (array) $base->selectRaw($sql, $bindings)->getQuery()->first();

        $result = [
            'awaiting_confirmation'    => (int) ($row['awaiting_confirmation']    ?? 0),
            'confirmation_issue'       => (int) ($row['confirmation_issue']       ?? 0),
            'ready_no_driver'          => (int) ($row['ready_no_driver']          ?? 0),
            'dispatched_not_collected' => (int) ($row['dispatched_not_collected'] ?? 0),
            'long_in_transit'          => (int) ($row['long_in_transit']          ?? 0),
            'pod_pending'              => (int) ($row['pod_pending']              ?? 0),
        ];

        // Buckets partition on status, so no job can be in two of
        // them. at_risk is therefore the plain sum — never a separate
        // query that could drift.
        $result['at_risk'] = array_sum($result);

        return $result;
    }

    /**
     * SQL predicates keyed by bucket, matched by the /admin/orders
     * `?exception=` filter so a click on any count lands on exactly
     * the same rows the dashboard summed.
     *
     * Returned as callables that take an Eloquent Builder and add
     * where-clauses to it, so the caller keeps its existing scope
     * (search, executor filter, driver, etc.) intact.
     *
     * @return array<string, callable>
     */
    public static function predicates(?array $thresholds = null): array
    {
        $t = $thresholds ?? OperationsThresholds::forExceptions();
        $now = Carbon::now();

        $awaiting = $now->copy()->subHours((int) $t['awaiting_confirmation_hours']);
        $ready    = $now->copy()->subHours((int) $t['ready_no_driver_hours']);
        $dispatch = $now->copy()->subHours((int) $t['dispatched_not_collected_hours']);
        $transit  = $now->copy()->subHours((int) $t['long_in_transit_hours']);
        $pod      = $now->copy()->subHours((int) $t['pod_pending_hours']);

        return [
            'awaiting_confirmation'    => fn ($q) => $q
                ->where('status', JobStatus::AwaitingCustomerConfirmation->value)
                ->where(function ($w) use ($awaiting) {
                    $w->whereNull('status_entered_at')
                      ->orWhere('status_entered_at', '<=', $awaiting);
                }),

            'confirmation_issue'       => fn ($q) => $q
                ->where('status', JobStatus::ConfirmationIssue->value),

            'ready_no_driver'          => fn ($q) => $q
                ->whereIn('status', [JobStatus::Confirmed->value, JobStatus::Planned->value])
                ->where(function ($w) use ($ready) {
                    $w->whereNull('status_entered_at')
                      ->orWhere('status_entered_at', '<=', $ready);
                }),

            'dispatched_not_collected' => fn ($q) => $q
                ->whereIn('status', [JobStatus::DriverAssigned->value, JobStatus::ReadyForCollection->value])
                ->where(function ($w) use ($dispatch) {
                    $w->whereNull('status_entered_at')
                      ->orWhere('status_entered_at', '<=', $dispatch);
                }),

            'long_in_transit'          => fn ($q) => $q
                ->where('status', JobStatus::InTransit->value)
                ->where(function ($w) use ($transit) {
                    $w->whereNull('status_entered_at')
                      ->orWhere('status_entered_at', '<=', $transit);
                }),

            'pod_pending'              => fn ($q) => $q
                ->where('status', JobStatus::Delivered->value)
                ->whereNull('completed_at')
                ->where(function ($w) use ($pod) {
                    $w->whereNull('delivered_at')
                      ->orWhere('delivered_at', '<=', $pod);
                }),
        ];
    }

}
