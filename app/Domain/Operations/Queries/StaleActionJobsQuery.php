<?php

namespace App\Domain\Operations\Queries;

use App\Enums\JobStatus;
use App\Enums\StageGroup;
use App\Models\Job;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Jobs stuck in the same stage for `trident.stale_action_days` (default
 * 7) with no active "Wait longer" snooze in play — the working set for
 * the ops login gate.
 *
 * WHY THIS IS NOT PriorityMovementsQuery
 * --------------------------------------
 * `PriorityMovementsQuery` is the ops queue: every active row, sorted
 * by "hours over stage threshold", grouped by dispatch lane. Its job
 * is to make TODAY's dispatch decisions loud.
 *
 * This query answers a different question — "which jobs have been
 * quietly forgotten for a week?" — and drives a login-time gate that
 * splits results by creator so people only get blocked by rows they
 * personally own. Same statuses, same executor scope, but a very
 * different UX contract, and its own decorations (`days_in_stage`,
 * `is_owned_by_actor`), so it lives in its own file.
 *
 * WHY NO OperationsFilters
 * ------------------------
 * The modal is a per-user prompt, not a per-filter drill-down. If ops
 * were free to filter the gate away — "hide all Brand X" — the whole
 * point (force attention on rot) would collapse. We always scope to
 * ProSelver-executed work (matching the ops dashboard default) and
 * ignore the dashboard's entity filters.
 */
class StaleActionJobsQuery
{
    /**
     * @return array{
     *     owned: Collection<int, Job>,
     *     others: Collection<int, Job>,
     *     total: int,
     *     worst_days: int,
     * }
     */
    public function forUser(User $actor): array
    {
        $days = (int) config('trident.stale_action_days', 7);
        $threshold = now()->subDays(max(1, $days));

        // Every queue stage — same set the ops queue considers active.
        // Closed / Cancelled rows never light up the gate.
        $activeStatuses = [];
        foreach (StageGroup::queueGroups() as $group) {
            foreach ($group->statusValues() as $s) {
                $activeStatuses[] = $s;
            }
        }
        $activeStatuses = array_values(array_unique($activeStatuses));

        $rows = Job::query()
            ->whereNull('deleted_at')
            // Match the ops dashboard default: our dispatch operation
            // only. 3PL / self-collect work stays out of the gate.
            ->where('executor_type', Job::EXECUTOR_PROSELVER)
            ->whereIn('status', $activeStatuses)
            // Age against the dwell clock. Fall back to updated_at for
            // legacy rows that predate the phase-1 dwell migration and
            // haven't transitioned since; same fallback the ops queue
            // uses.
            ->whereRaw('COALESCE(status_entered_at, updated_at) <= ?', [$threshold])
            // Active snooze suppresses; null or past snoozes don't.
            ->where(function ($q) {
                $q->whereNull('stale_action_snoozed_until')
                  ->orWhere('stale_action_snoozed_until', '<=', now());
            })
            ->with([
                'company:id,name',
                'brand:id,name',
                'createdBy:id,name',
                'staleActionSnoozedBy:id,name',
            ])
            ->orderByRaw('COALESCE(status_entered_at, updated_at) asc')
            ->get();

        $decorated = $rows->map(function (Job $j) use ($actor) {
            $enteredAt = $j->status_entered_at ?? $j->updated_at;
            $daysIn = $enteredAt ? (int) floor(abs($enteredAt->diffInDays(now()))) : 0;

            $status = JobStatus::tryFrom($j->status);
            $group  = $status?->group();

            $j->setAttribute('days_in_stage', $daysIn);
            $j->setAttribute('stage_label', $group?->label() ?? $status?->label() ?? $j->status);
            $j->setAttribute('is_owned_by_actor', (int) $j->created_by_user_id === (int) $actor->id);

            return $j;
        });

        $owned  = $decorated->filter->is_owned_by_actor->values();
        $others = $decorated->reject->is_owned_by_actor->values();

        return [
            'owned'      => $owned,
            'others'     => $others,
            'total'      => $decorated->count(),
            'worst_days' => (int) $decorated->max('days_in_stage'),
        ];
    }
}
