<?php

namespace App\Domain\Operations\Queries;

use App\Domain\Operations\OperationsFilters;
use App\Enums\JobStatus;
use App\Enums\StageGroup;
use App\Models\Job;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Daily inflow-per-stage chart across the performance window.
 *
 * WHAT IT SHOWS
 * -------------
 * One line per pipeline StageGroup. For each day, we count how many
 * jobs *entered that stage on that day*, using the per-stage entry
 * timestamp columns that already live on `transport_jobs`:
 *
 *   Intake     -> created_at
 *   Ready      -> customer_confirmed_at
 *   Dispatched -> assigned_at
 *   On the road-> collected_at
 *
 * Crucially we do NOT filter by current status. A job that flowed
 * Intake -> Ready -> Dispatched -> OnRoad -> Delivered inside one
 * week appears on ALL four lines, one entry per stage on its
 * transition day. That reads as real throughput.
 *
 * The previous implementation filtered by current status and used
 * `status_entered_at`; both were wrong: it dropped every already-
 * delivered row from the chart, and `status_entered_at` was
 * backfilled from `updated_at` for most historical rows, which
 * bunched all remaining points on the migration day.
 *
 * When `job_status_events` has a few weeks of ledger data we can
 * switch to that; for now the timestamp columns are the honest
 * source of truth.
 *
 * Returns pre-computed SVG geometry so the Blade template can render
 * paths directly, no chart library required.
 */
class FlowChartQuery
{
    /**
     * Column that records first entry into each pipeline group. Only
     * the four pipeline groups appear on the chart — Delivered lives
     * in the Delivered hero tile, Closed doesn't belong here.
     */
    private const STAGE_ENTRY_COLUMN = [
        'intake'     => 'created_at',
        'ready'      => 'customer_confirmed_at',
        'dispatched' => 'assigned_at',
        'on_road'    => 'collected_at',
    ];

    /**
     * @return array{
     *   window: array{from:string, to:string, days:int},
     *   series: array<string, array{group:StageGroup, label:string, hue:string, points:list<array{x:float,y:float,value:int}>, path:string, area:string}>,
     *   max: int,
     *   totals: array<string,int>
     * }
     */
    public function get(OperationsFilters $filters, int $width = 640, int $height = 160): array
    {
        [$from, $to] = $this->window($filters);
        $days = (int) $from->diffInDays($to) + 1;

        // Buckets we care about on the pipeline board — Delivered and
        // Closed are shown separately in the throughput tile, not here.
        $groups = StageGroup::pipelineGroups();

        // One SELECT per group is intentionally cheap on Postgres and
        // still linear on SQLite; each query filters on the entry
        // timestamp column for that stage with no joins. Total = 4
        // queries for the chart, well under the panel budget.
        //
        // No `whereIn('status', ...)` filter: we count *every* row
        // that entered the stage in the window, not just the rows
        // still sitting there today.
        $daily = [];
        foreach ($groups as $group) {
            $column = self::STAGE_ENTRY_COLUMN[$group->value] ?? null;
            if ($column === null) {
                $daily[$group->value] = [];
                continue;
            }

            $qualified = 'transport_jobs.' . $column;
            $daily[$group->value] = $filters->applyEntityScope(Job::query())
                ->whereNull('transport_jobs.deleted_at')
                ->whereNotNull($qualified)
                ->whereBetween($qualified, [$from, $to])
                ->selectRaw($this->dateSlot($qualified) . ' as bucket, count(*) as c')
                ->groupBy('bucket')
                ->orderBy('bucket')
                ->pluck('c', 'bucket')
                ->toArray();
        }

        // Fill in zeros for days with no activity so the SVG path is
        // continuous — an empty day would otherwise skip a segment.
        $daysList = collect();
        for ($i = 0; $i < $days; $i++) {
            $daysList->push($from->copy()->addDays($i)->toDateString());
        }

        // Find the max value across all series for the y-axis.
        $max = 1;
        foreach ($groups as $group) {
            foreach ($daysList as $day) {
                $max = max($max, (int) ($daily[$group->value][$day] ?? 0));
            }
        }

        // Build one series per group with SVG-ready coordinates and paths.
        $series = [];
        $totals = [];
        $step = $days > 1 ? ($width / ($days - 1)) : 0;
        foreach ($groups as $group) {
            $points = [];
            $total = 0;
            foreach ($daysList as $i => $day) {
                $value = (int) ($daily[$group->value][$day] ?? 0);
                $x = $i * $step;
                $y = $height - ($value / $max) * ($height - 8);
                $points[] = ['x' => $x, 'y' => $y, 'value' => $value];
                $total += $value;
            }
            $series[$group->value] = [
                'group'  => $group,
                'label'  => $group->label(),
                'hue'    => $this->hue($group),
                'points' => $points,
                'path'   => $this->linePath($points),
                'area'   => $this->areaPath($points, $height),
            ];
            $totals[$group->value] = $total;
        }

        return [
            'window' => [
                'from' => $from->toDateString(),
                'to'   => $to->toDateString(),
                'days' => $days,
            ],
            'series' => $series,
            'max'    => $max,
            'totals' => $totals,
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

    /**
     * Portable "cast timestamp to date" expression. Postgres uses ::date,
     * SQLite prefers date(...); the query builder picks the driver at
     * connect time so we cannot switch on Job::query()->getConnection()
     * here without dependency injection. Use the SQL-standard CAST
     * expression which every backend supports.
     */
    private function dateSlot(string $column): string
    {
        $driver = config('database.default') ? config('database.connections.' . config('database.default') . '.driver') : null;
        return $driver === 'pgsql'
            ? "({$column})::date"
            : "date({$column})";
    }

    private function hue(StageGroup $group): string
    {
        return match ($group) {
            StageGroup::Intake     => '#94a3b8', // slate
            StageGroup::Ready      => '#06b6d4', // cyan
            StageGroup::Dispatched => '#0284c7', // sky
            StageGroup::OnRoad     => '#10b981', // emerald
            default                => '#64748b',
        };
    }

    /**
     * Build a smoothed cubic-bezier path for a series line.
     */
    private function linePath(array $points): string
    {
        if (empty($points)) {
            return '';
        }
        $d = 'M ' . round($points[0]['x'], 2) . ' ' . round($points[0]['y'], 2);
        for ($i = 1; $i < count($points); $i++) {
            $prev = $points[$i - 1];
            $curr = $points[$i];
            $midX = ($prev['x'] + $curr['x']) / 2;
            $d .= ' C ' . round($midX, 2) . ' ' . round($prev['y'], 2)
                . ', '   . round($midX, 2) . ' ' . round($curr['y'], 2)
                . ', '   . round($curr['x'], 2) . ' ' . round($curr['y'], 2);
        }
        return $d;
    }

    private function areaPath(array $points, int $height): string
    {
        if (empty($points)) {
            return '';
        }
        $line = $this->linePath($points);
        $last = end($points);
        $first = reset($points);
        return $line
            . ' L ' . round($last['x'], 2) . ' ' . round($height, 2)
            . ' L ' . round($first['x'], 2) . ' ' . round($height, 2)
            . ' Z';
    }
}
