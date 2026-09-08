<?php

namespace App\Domain\Operations\Queries;

use App\Domain\Operations\OperationsFilters;
use App\Enums\StageGroup;
use App\Models\Job;

/**
 * The four live pipeline tiles: Intake / Ready / Dispatched / On the road.
 *
 * IGNORES `from` and `to` on the filters — this is state RIGHT NOW,
 * not "how many arrived in a window". Everything else (customer /
 * transporter / brand / region / executor) is applied.
 *
 * One aggregate query, no per-tile count() calls.
 */
class LivePipelineQuery
{
    /**
     * @return array{intake:int, ready:int, dispatched:int, on_road:int}
     */
    public function get(OperationsFilters $filters): array
    {
        $base = $filters->applyEntityScope(Job::query())
            ->whereNull('deleted_at');

        $intake     = StageGroup::Intake->statusValues();
        $ready      = StageGroup::Ready->statusValues();
        $dispatched = StageGroup::Dispatched->statusValues();
        $onRoad     = StageGroup::OnRoad->statusValues();

        // Build the CASE fragments dynamically so the number of
        // placeholders always matches the group membership. Doing this
        // by hand caused a subtle 4-vs-5 status mismatch on the old
        // dashboard when the READY_FOR_COLLECTION alias was added.
        [$intakeSql, $intakeBind]         = $this->groupCase($intake);
        [$readySql, $readyBind]           = $this->groupCase($ready);
        [$dispatchedSql, $dispatchedBind] = $this->groupCase($dispatched);
        [$onRoadSql, $onRoadBind]         = $this->groupCase($onRoad);

        $sql =
            "SUM({$intakeSql}) AS intake, " .
            "SUM({$readySql}) AS ready, " .
            "SUM({$dispatchedSql}) AS dispatched, " .
            "SUM({$onRoadSql}) AS on_road";

        $bindings = array_merge($intakeBind, $readyBind, $dispatchedBind, $onRoadBind);

        // See ExceptionCountsQuery: dropping to ->getQuery() bypasses
        // Eloquent's model hydration so the aggregate row comes back
        // as a plain stdClass keyed by the alias, not a Model that
        // buries them behind attribute magic.
        $row = (array) $base->selectRaw($sql, $bindings)->getQuery()->first();

        return [
            'intake'     => (int) ($row['intake']     ?? 0),
            'ready'      => (int) ($row['ready']      ?? 0),
            'dispatched' => (int) ($row['dispatched'] ?? 0),
            'on_road'    => (int) ($row['on_road']    ?? 0),
        ];
    }

    /**
     * @return array{0:string,1:list<string>}  SQL fragment + bindings
     */
    private function groupCase(array $statuses): array
    {
        if (empty($statuses)) {
            return ['CASE WHEN 1 = 0 THEN 1 ELSE 0 END', []];
        }

        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $sql = "CASE WHEN status IN ({$placeholders}) THEN 1 ELSE 0 END";

        return [$sql, array_values($statuses)];
    }
}
