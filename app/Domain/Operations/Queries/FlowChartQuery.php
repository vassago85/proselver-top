<?php

namespace App\Domain\Operations\Queries;

use App\Domain\Operations\OperationsFilters;
use App\Enums\JobStatus;
use App\Enums\StageGroup;
use App\Models\Job;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Cumulative-flow-style throughput per stage group, per day in the
 * performance window.
 *
 * WHAT IT REALLY SHOWS
 * --------------------
 * For each day in the range, we count jobs whose current status
 * entered on that day, grouped by StageGroup. The result reads as
 * "how much work moved into each stage each day". A true CFD
 * reconstructs stage populations from the job_status_events history
 * — worth doing once the ledger has a few weeks of data, but until
 * then this daily inflow view is honest and cheap.
 *
 * Returns pre-computed SVG geometry so the Blade template can render
 * paths directly, no chart library required.
 */
class FlowChartQuery
{
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
        // still linear on SQLite; each query filters by status list +
        // date range with no joins. Total = 4 queries for the chart,
        // well under the panel budget.
        $daily = [];
        foreach ($groups as $group) {
            $daily[$group->value] = $filters->applyEntityScope(Job::query())
                ->whereNull('deleted_at')
                ->whereIn('status', $group->statusValues())
                ->whereBetween('status_entered_at', [$from, $to])
                ->selectRaw($this->dateSlot('status_entered_at') . ' as bucket, count(*) as c')
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
