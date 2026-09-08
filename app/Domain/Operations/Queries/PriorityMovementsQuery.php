<?php

namespace App\Domain\Operations\Queries;

use App\Domain\Operations\Lane;
use App\Domain\Operations\OperationsFilters;
use App\Enums\JobStatus;
use App\Enums\StageGroup;
use App\Models\Job;
use Illuminate\Support\Collection;

/**
 * The ops queue: every active job (Intake → Delivered/POD-pending),
 * scored by how many hours it is OVERDUE for its stage's threshold,
 * then grouped by dispatch lane (origin_province → destination_province).
 *
 * A row's badge is the Stage label (from `StageGroup::label()`) — not
 * the raw JobStatus. This is what makes the queue "speak Stage" while
 * the DB column stays a real workflow enum.
 *
 * WHY EVERY ROW (NO HARD 12-CAP)
 * ------------------------------
 * The old dashboard capped at 12 and hid a growing backlog. Ops
 * asked for the full working set — scroll if you must, but do not
 * lie by omission. A `showOverdueOnly` toggle can be added later
 * without changing this query's contract.
 *
 * WHY LANE-GROUPED
 * ----------------
 * Twelve independent rows all going Eastern Cape → Gauteng is one
 * dispatch decision shown twelve times. Grouping by (origin, dest)
 * turns the same data into one row that says "EC → GP: 5 waiting,
 * worst 6h over — consolidate." Incomplete lanes (missing province
 * at either end) collapse into a single "Lane not set" heading pinned
 * to the top so ops fixes the data instead of squinting at "—".
 */
class PriorityMovementsQuery
{
    /**
     * @param  bool  $overdueOnly  when true, only include rows past their stage threshold
     * @return array{lanes: Collection<int,array>, jobs: Collection<int,\App\Models\Job>}
     */
    public function get(OperationsFilters $filters, bool $overdueOnly = false): array
    {
        // Active set = every queue stage. POD pending (Delivered) is
        // now on the queue too — a delivered row waiting on POD is
        // still ops's problem until it flips to Completed.
        $activeStatuses = [];
        foreach (StageGroup::queueGroups() as $group) {
            foreach ($group->statusValues() as $s) {
                $activeStatuses[] = $s;
            }
        }
        $activeStatuses = array_values(array_unique($activeStatuses));

        $rows = $filters->applyEntityScope(Job::query())
            ->whereNull('transport_jobs.deleted_at')
            ->whereIn('transport_jobs.status', $activeStatuses)
            ->with([
                'company:id,name,workflow_type',
                'pickupLocation:id,company_name,address,city,province',
                'deliveryLocation:id,company_name,address,city,province',
                'driver:id,name',
                'inventory:id,chassis_number,vin',
                'brand:id,name',
            ])
            ->orderByRaw('COALESCE(status_entered_at, updated_at) asc')
            ->get();

        // Decorate each row: hours in stage, overdue_by, stage label,
        // lane key. All the presentation logic lives here so the
        // Blade template stays a dumb printer.
        $jobs = $rows->map(function (Job $j) {
            $enteredAt = $j->status_entered_at ?? $j->updated_at;

            // Carbon 3 returns floats from diffIn*; round to whole
            // hours + whole days so "in stage for 6.83h" can never
            // leak to the UI. `abs()` handles rare clock-skew where
            // the entry timestamp is a few seconds in the future.
            $hoursIn = $enteredAt ? (int) round(abs($enteredAt->diffInHours(now()))) : 0;
            $daysIn  = $enteredAt ? (int) floor(abs($enteredAt->diffInDays(now()))) : 0;

            $status = JobStatus::tryFrom($j->status);
            $group  = $status?->group();
            $threshold = $group?->thresholdHours();
            $overdueBy = ($threshold !== null) ? ($hoursIn - $threshold) : null;

            $j->setAttribute('hours_in_stage', $hoursIn);
            $j->setAttribute('days_in_stage', $daysIn);
            $j->setAttribute('stage_group', $group);
            $j->setAttribute('stage_label', $group?->label() ?? $status?->label() ?? $j->status);
            $j->setAttribute('threshold_hours', $threshold);
            $j->setAttribute('overdue_by', $overdueBy);
            $j->setAttribute('is_overdue', $overdueBy !== null && $overdueBy > 0);

            $lane = Lane::from(
                $j->pickupLocation?->province,
                $j->deliveryLocation?->province,
            );
            $j->setAttribute('lane_key', $lane->key());
            $j->setAttribute('lane_is_complete', $lane->isComplete());
            $j->setAttribute('lane_origin', $lane->origin);
            $j->setAttribute('lane_destination', $lane->destination);

            return $j;
        });

        if ($overdueOnly) {
            $jobs = $jobs->filter->is_overdue->values();
        }

        // Sort by overdue_by desc so the loudest breach floats to the
        // top. Null overdue (no threshold defined) is treated as zero
        // so rows still sit in a deterministic order.
        $jobs = $jobs->sortByDesc(fn (Job $j) => $j->overdue_by ?? 0)->values();

        // Group by lane; incomplete-lane rows collapse under one
        // "Lane not set" heading which the caller pins to the top.
        $groups = $jobs->groupBy('lane_key');

        $lanes = $groups->map(function (Collection $group, string $key) {
            $worstOverdue = $group->max('overdue_by') ?? 0;
            $oldestHours  = $group->max('hours_in_stage') ?? 0;
            $isComplete   = (bool) $group->first()?->lane_is_complete;

            return [
                'lane'          => $key,
                'lane_key'      => $key,
                'is_complete'   => $isComplete,
                'count'         => $group->count(),
                'customers'     => $group->pluck('company.id')->unique()->filter()->count(),
                'overdue_count' => $group->where('is_overdue', true)->count(),
                'worst_overdue' => (int) $worstOverdue,
                'oldest_hours'  => (int) $oldestHours,
                'jobs'          => $group->values(),
            ];
        })->values();

        // Sort lanes: incomplete first (data debt is priority zero),
        // then complete lanes ordered by worst overdue desc.
        $lanes = $lanes
            ->sortBy(fn (array $l) => [
                $l['is_complete'] ? 1 : 0,       // incomplete lanes first
                -1 * (int) $l['worst_overdue'],   // worst overdue floats up
            ])
            ->values();

        return [
            'lanes' => $lanes,
            'jobs'  => $jobs,
        ];
    }

    /**
     * Cheap count of rows past their stage threshold — used by the
     * At-risk hero tile so its number matches the badges on the
     * queue exactly. Same predicate, same numbers.
     */
    public function overdueCount(OperationsFilters $filters): int
    {
        return $this->get($filters, overdueOnly: true)['jobs']->count();
    }
}
