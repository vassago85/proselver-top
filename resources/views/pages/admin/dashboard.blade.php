<?php

use App\Models\Brand;
use App\Models\Company;
use App\Models\Inventory;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\Location;
use App\Models\SystemSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    /**
     * ╔══════════════════════════════════════════════════════════════════╗
     * ║  VEHICLE MOVEMENT — EXECUTIVE OVERVIEW                           ║
     * ╠══════════════════════════════════════════════════════════════════╣
     * ║  A high-level command centre on top of the inventory ledger.    ║
     * ║  Does not replace /admin/dashboard. Every tile/card is backed    ║
     * ║  by real rows in `inventory`, `transport_jobs`, `invoices`,      ║
     * ║  `locations`, `companies` — no mock or seeded figures.           ║
     * ║                                                                  ║
     * ║  Inventory has no `status_changed_at` column. We treat           ║
     * ║  `updated_at` as the "in-current-status since" timestamp. This   ║
     * ║  is accurate so long as nothing else touches inventory rows      ║
     * ║  between state changes; that is the current behaviour of         ║
     * ║  InventoryLifecycleService.                                      ║
     * ╚══════════════════════════════════════════════════════════════════╝
     */

    // ─── Filter state (URL-persistent so links/bookmarks reflect the view) ──
    #[Url] public ?string $dateFrom = null;
    #[Url] public ?string $dateTo = null;
    #[Url] public ?int $companyId = null;      // booking customer / OEM
    #[Url] public ?int $transporterId = null;  // executing company
    #[Url] public ?int $brandId = null;
    #[Url] public ?string $region = null;      // province on current_location
    #[Url] public ?string $status = null;      // inventory status

    public function mount(): void
    {
        // Default window: rolling 30 days ending today. Carbon string form
        // keeps wire:model happy on a plain <input type="date">.
        if (!$this->dateFrom) {
            $this->dateFrom = now()->subDays(29)->toDateString();
        }
        if (!$this->dateTo) {
            $this->dateTo = now()->toDateString();
        }
    }

    public function resetFilters(): void
    {
        $this->reset(['companyId', 'transporterId', 'brandId', 'region', 'status']);
        $this->dateFrom = now()->subDays(29)->toDateString();
        $this->dateTo = now()->toDateString();
    }

    // ───────────────────────────────────────────────────────────────────
    //  Reusable query builders
    // ───────────────────────────────────────────────────────────────────

    /**
     * Inventory scoped by the active filters EXCEPT status — we want to
     * apply status per-metric (e.g. "in transit" ignores a user-selected
     * status filter; the status filter only pre-limits the exploratory
     * surfaces like Status Distribution and Priority Movements).
     */
    protected function baseInventoryQuery(bool $applyStatusFilter = false)
    {
        $q = Inventory::query();

        if ($this->companyId) {
            $q->where('owner_company_id', $this->companyId);
        }
        if ($this->brandId) {
            $q->where('brand_id', $this->brandId);
        }
        if ($this->region) {
            $q->whereHas('currentLocation', fn ($l) => $l->where('province', $this->region));
        }
        if ($applyStatusFilter && $this->status) {
            $q->where('status', $this->status);
        }
        // Transporter filter on inventory: join through delivered_via_job
        // (only affects historical/delivered rows). For active rows the
        // transporter is implicit in the latest open job → we handle that
        // in the jobs-driven queries below.
        if ($this->transporterId) {
            $q->whereHas('jobs', fn ($j) => $j->where('executing_company_id', $this->transporterId));
        }

        return $q;
    }

    /**
     * Jobs query matching the same filters. Used for throughput, on-time
     * ratios, transporter leaderboard.
     */
    protected function baseJobsQuery()
    {
        $q = Job::query();
        if ($this->companyId)     { $q->where('company_id', $this->companyId); }
        if ($this->transporterId) { $q->where('executing_company_id', $this->transporterId); }
        if ($this->brandId)       { $q->where('brand_id', $this->brandId); }
        if ($this->region) {
            $q->where(function ($w) {
                $w->whereHas('pickupLocation',   fn ($l) => $l->where('province', $this->region))
                  ->orWhereHas('deliveryLocation', fn ($l) => $l->where('province', $this->region));
            });
        }
        return $q;
    }

    // ───────────────────────────────────────────────────────────────────
    //  Thresholds (config, tunable via SystemSetting)
    // ───────────────────────────────────────────────────────────────────

    protected function thresholds(): array
    {
        return [
            'in_transit_days' => (int) SystemSetting::get('exec.alert.in_transit_days', 7),
            'at_yard_days'    => (int) SystemSetting::get('exec.alert.at_yard_days', 14),
            'at_plant_days'   => (int) SystemSetting::get('exec.alert.at_plant_days', 21),
        ];
    }

    // ───────────────────────────────────────────────────────────────────
    //  Data for view
    // ───────────────────────────────────────────────────────────────────

    public function with(): array
    {
        $from = Carbon::parse($this->dateFrom)->startOfDay();
        $to   = Carbon::parse($this->dateTo)->endOfDay();
        $span = max(1, $from->diffInDays($to) + 1);
        $prevTo   = (clone $from)->subDay()->endOfDay();
        $prevFrom = (clone $prevTo)->subDays($span - 1)->startOfDay();

        $th = $this->thresholds();

        // ─────────────────────────────────────────────────────────────
        //  ROW 1 — KPI cards (all from Inventory, no fake figures)
        // ─────────────────────────────────────────────────────────────
        $inTransit = (clone $this->baseInventoryQuery())
            ->where('status', Inventory::STATUS_IN_TRANSIT)->count();

        $atYard = (clone $this->baseInventoryQuery())
            ->whereIn('status', [Inventory::STATUS_AT_YARD, Inventory::STATUS_AT_STORAGE])->count();

        $atPlant = (clone $this->baseInventoryQuery())
            ->whereIn('status', [Inventory::STATUS_PRODUCED, Inventory::STATUS_AT_PLANT])->count();

        $deliveredRange = (clone $this->baseInventoryQuery())
            ->where('status', Inventory::STATUS_DELIVERED)
            ->whereBetween('delivered_at', [$from, $to])->count();

        $deliveredPrev = (clone $this->baseInventoryQuery())
            ->where('status', Inventory::STATUS_DELIVERED)
            ->whereBetween('delivered_at', [$prevFrom, $prevTo])->count();

        // "At risk" — stuck in a non-terminal state past the status-specific
        // threshold. Uses `updated_at` as the in-status proxy.
        $atRiskQ = (clone $this->baseInventoryQuery())
            ->where(function ($w) use ($th) {
                $w->where(function ($a) use ($th) {
                    $a->where('status', Inventory::STATUS_IN_TRANSIT)
                      ->where('updated_at', '<=', now()->subDays($th['in_transit_days']));
                })->orWhere(function ($a) use ($th) {
                    $a->whereIn('status', [Inventory::STATUS_AT_YARD, Inventory::STATUS_AT_STORAGE])
                      ->where('updated_at', '<=', now()->subDays($th['at_yard_days']));
                })->orWhere(function ($a) use ($th) {
                    $a->whereIn('status', [Inventory::STATUS_PRODUCED, Inventory::STATUS_AT_PLANT])
                      ->where('updated_at', '<=', now()->subDays($th['at_plant_days']));
                });
            });
        $atRisk = (clone $atRiskQ)->count();

        $activeInventory = (clone $this->baseInventoryQuery())
            ->whereIn('status', Inventory::ACTIVE_STATUSES)->count();

        // Previous-period comparisons for the other KPI cards. We use
        // updated_at as a proxy for "was in this status during the
        // previous window" — imperfect but directionally correct and the
        // only signal available without a status-history table.
        $prevInTransit = (clone $this->baseInventoryQuery())
            ->where('status', Inventory::STATUS_IN_TRANSIT)
            ->whereBetween('updated_at', [$prevFrom, $prevTo])->count();
        $prevAtYard = (clone $this->baseInventoryQuery())
            ->whereIn('status', [Inventory::STATUS_AT_YARD, Inventory::STATUS_AT_STORAGE])
            ->whereBetween('updated_at', [$prevFrom, $prevTo])->count();
        $prevAtPlant = (clone $this->baseInventoryQuery())
            ->whereIn('status', [Inventory::STATUS_PRODUCED, Inventory::STATUS_AT_PLANT])
            ->whereBetween('updated_at', [$prevFrom, $prevTo])->count();
        $prevActive = (clone $this->baseInventoryQuery())
            ->whereIn('status', Inventory::ACTIVE_STATUSES)
            ->whereBetween('updated_at', [$prevFrom, $prevTo])->count();

        $kpis = [
            [
                'key'       => 'in_transit',
                'label'     => 'Vehicles in Transit',
                'value'     => $inTransit,
                'color'     => 'blue',
                'href'      => route('admin.vehicles.index', ['bucket' => 'live']),
                'trend'     => $this->trend($inTransit, $prevInTransit),
                'helper'    => 'Active road movements',
                'iconPath'  => '<path d="M14 16H9m10 0h3v-3.15a1 1 0 0 0-.84-.99L16 11l-2.7-3.6a1 1 0 0 0-.8-.4H5.24a2 2 0 0 0-1.8 1.1l-.8 1.63A6 6 0 0 0 2 12.42V16h2"/><circle cx="6.5" cy="16.5" r="2.5"/><circle cx="16.5" cy="16.5" r="2.5"/>',
            ],
            [
                'key'       => 'at_yard',
                'label'     => 'At Yard / Storage',
                'value'     => $atYard,
                'color'     => 'amber',
                'href'      => route('admin.vehicles.index'),
                'trend'     => $this->trend($atYard, $prevAtYard),
                'helper'    => 'Awaiting next hop',
                'iconPath'  => '<path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/><path d="M9 9v.01"/><path d="M9 12v.01"/><path d="M9 15v.01"/><path d="M9 18v.01"/>',
            ],
            [
                'key'       => 'at_plant',
                'label'     => 'At Plant / Produced',
                'value'     => $atPlant,
                'color'     => 'indigo',
                'href'      => route('admin.vehicles.index'),
                'trend'     => $this->trend($atPlant, $prevAtPlant),
                'helper'    => 'Upstream, ready to release',
                'iconPath'  => '<path d="M17 18a2 2 0 0 0-2-2H9a2 2 0 0 0-2 2"/><path d="M21 22H3"/><path d="M4 22V6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v16"/><path d="M10 8h4"/><path d="M10 12h4"/><path d="M10 16h4"/>',
            ],
            [
                'key'       => 'delivered',
                'label'     => 'Delivered (range)',
                'value'     => $deliveredRange,
                'color'     => 'green',
                'href'      => route('admin.deliveries'),
                'trend'     => $this->trend($deliveredRange, $deliveredPrev),
                'helper'    => $from->format('d M') . ' – ' . $to->format('d M'),
                'iconPath'  => '<path d="M20 6 9 17l-5-5"/>',
            ],
            [
                'key'       => 'at_risk',
                'label'     => 'At Risk / Delayed',
                'value'     => $atRisk,
                'color'     => $atRisk > 0 ? 'red' : 'slate',
                'href'      => null,
                'trend'     => null,
                'helper'    => "Transit >{$th['in_transit_days']}d · Yard >{$th['at_yard_days']}d · Plant >{$th['at_plant_days']}d",
                'iconPath'  => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
            ],
            [
                'key'       => 'active',
                'label'     => 'Active Inventory',
                'value'     => $activeInventory,
                'color'     => 'teal',
                'href'      => route('admin.vehicles.index'),
                'trend'     => $this->trend($activeInventory, $prevActive),
                'helper'    => 'Everything not yet delivered',
                'iconPath'  => '<circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/>',
            ],
        ];

        // ─────────────────────────────────────────────────────────────
        //  ROW 2 — Flow & Distribution
        // ─────────────────────────────────────────────────────────────

        // Daily activity series — 3 lines: dispatched (assigned_at),
        // departed (in_transit_at), delivered (delivered_at).
        $days = [];
        $cursor = $from->copy();
        while ($cursor->lte($to)) {
            $days[$cursor->toDateString()] = ['date' => $cursor->copy(), 'dispatched' => 0, 'in_transit' => 0, 'delivered' => 0];
            $cursor->addDay();
        }

        $jobActivity = (clone $this->baseJobsQuery())
            ->where(function ($w) use ($from, $to) {
                $w->whereBetween('assigned_at',   [$from, $to])
                  ->orWhereBetween('in_transit_at', [$from, $to])
                  ->orWhereBetween('delivered_at',  [$from, $to]);
            })
            ->get(['id', 'assigned_at', 'in_transit_at', 'delivered_at']);

        foreach ($jobActivity as $j) {
            foreach (['assigned_at' => 'dispatched', 'in_transit_at' => 'in_transit', 'delivered_at' => 'delivered'] as $col => $bucket) {
                if ($j->$col && $j->$col->between($from, $to)) {
                    $k = $j->$col->toDateString();
                    if (isset($days[$k])) {
                        $days[$k][$bucket]++;
                    }
                }
            }
        }
        $activitySeries = array_values($days);
        $activityPeak   = max(array_merge([1], array_map(fn ($d) => max($d['dispatched'], $d['in_transit'], $d['delivered']), $activitySeries)));

        // Status distribution — inventory grouped by status (active only).
        $statusDist = (clone $this->baseInventoryQuery())
            ->whereIn('status', Inventory::ACTIVE_STATUSES)
            ->selectRaw('status, count(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status')
            ->toArray();

        // Throughput vs target — jobs scheduled inside the range vs
        // delivered inside the range. Gap = overdue / undelivered.
        $scheduled = (clone $this->baseJobsQuery())
            ->whereBetween('scheduled_date', [$from->toDateString(), $to->toDateString()])
            ->count();
        $delivered = (clone $this->baseJobsQuery())
            ->whereNotNull('delivered_at')
            ->whereBetween('delivered_at', [$from, $to])
            ->count();
        $throughputPct = $scheduled > 0 ? round(($delivered / $scheduled) * 100) : 0;

        // ─────────────────────────────────────────────────────────────
        //  ROW 3 — Operations focus
        // ─────────────────────────────────────────────────────────────

        $exceptions = [
            [
                'key'      => 'stuck_plant',
                'label'    => 'Stuck at plant',
                'sublabel' => "> {$th['at_plant_days']}d",
                'count'    => (clone $this->baseInventoryQuery())
                    ->whereIn('status', [Inventory::STATUS_PRODUCED, Inventory::STATUS_AT_PLANT])
                    ->where('updated_at', '<=', now()->subDays($th['at_plant_days']))
                    ->count(),
                'severity' => 'amber',
            ],
            [
                'key'      => 'stuck_yard',
                'label'    => 'Stuck at yard / storage',
                'sublabel' => "> {$th['at_yard_days']}d",
                'count'    => (clone $this->baseInventoryQuery())
                    ->whereIn('status', [Inventory::STATUS_AT_YARD, Inventory::STATUS_AT_STORAGE])
                    ->where('updated_at', '<=', now()->subDays($th['at_yard_days']))
                    ->count(),
                'severity' => 'amber',
            ],
            [
                'key'      => 'long_transit',
                'label'    => 'Long in transit',
                'sublabel' => "> {$th['in_transit_days']}d",
                'count'    => (clone $this->baseInventoryQuery())
                    ->where('status', Inventory::STATUS_IN_TRANSIT)
                    ->where('updated_at', '<=', now()->subDays($th['in_transit_days']))
                    ->count(),
                'severity' => 'red',
            ],
            [
                'key'      => 'missing_driver',
                'label'    => 'Jobs missing driver',
                'sublabel' => 'planned / confirmed, no driver',
                'count'    => (clone $this->baseJobsQuery())
                    ->whereIn('status', [Job::STATUS_CONFIRMED, Job::STATUS_PLANNED])
                    ->whereNull('driver_user_id')
                    ->count(),
                'severity' => 'amber',
            ],
            [
                'key'      => 'delivered_open',
                'label'    => 'Delivered but workflow open',
                'sublabel' => 'inventory delivered, job not completed',
                'count'    => Inventory::query()
                    ->where('status', Inventory::STATUS_DELIVERED)
                    ->whereNotNull('delivered_via_job_id')
                    ->whereHas('deliveredViaJob', fn ($j) => $j->whereNotIn('status', [Job::STATUS_COMPLETED, Job::STATUS_INVOICED]))
                    ->count(),
                'severity' => 'amber',
            ],
        ];

        // Priority movements — active inventory ordered by longest time
        // in current status (= `updated_at` ascending among active rows).
        $priority = (clone $this->baseInventoryQuery(applyStatusFilter: true))
            ->whereIn('status', Inventory::ACTIVE_STATUSES)
            ->with([
                'currentLocation:id,company_name,city,province,type',
                'brand:id,name',
                'owner:id,name',
            ])
            ->orderBy('updated_at', 'asc')
            ->limit(12)
            ->get();

        // Enrich priority rows with the latest open job (for origin →
        // destination + driver). Batch-load to avoid N+1.
        $invIds = $priority->pluck('id')->all();
        $latestJobs = !empty($invIds)
            ? Job::whereIn('inventory_id', $invIds)
                ->whereNotIn('status', [Job::STATUS_COMPLETED, Job::STATUS_INVOICED, Job::STATUS_CANCELLED, Job::STATUS_REJECTED])
                ->with(['pickupLocation:id,city', 'deliveryLocation:id,city', 'driver:id,name'])
                ->orderByDesc('updated_at')
                ->get()
                ->groupBy('inventory_id')
            : collect();

        foreach ($priority as $row) {
            $row->setAttribute('latest_job', $latestJobs->get($row->id)?->first());
            $row->setAttribute('days_in_status', $row->updated_at ? (int) $row->updated_at->diffInDays(now()) : null);
            $row->setAttribute('risk_level', $this->riskFor($row->status, $row->getAttribute('days_in_status'), $th));
        }

        // Location pressure — inventory grouped by location.type.
        $locationPressure = (clone $this->baseInventoryQuery())
            ->whereIn('status', Inventory::ACTIVE_STATUSES)
            ->join('locations', 'inventory.current_location_id', '=', 'locations.id')
            ->selectRaw('locations.type as type, count(*) as n')
            ->groupBy('locations.type')
            ->pluck('n', 'type')
            ->toArray();

        // ─────────────────────────────────────────────────────────────
        //  ROW 4 — Executive insight
        // ─────────────────────────────────────────────────────────────

        // Top customer / OEM breakdown — active inventory + delivered
        // count in the selected window.
        $companyRows = (clone $this->baseInventoryQuery())
            ->selectRaw('owner_company_id, '
                . 'count(*) filter (where status in (' . $this->pgStatusList(Inventory::ACTIVE_STATUSES) . ')) as active_count, '
                . 'count(*) filter (where status = ? and delivered_at between ? and ?) as delivered_count', [
                    Inventory::STATUS_DELIVERED, $from, $to,
                ])
            ->whereNotNull('owner_company_id')
            ->groupBy('owner_company_id')
            ->orderByDesc('active_count')
            ->limit(8)
            ->get();

        $companyIds = $companyRows->pluck('owner_company_id')->all();
        $companies  = !empty($companyIds)
            ? Company::whereIn('id', $companyIds)->get(['id', 'name', 'type'])->keyBy('id')
            : collect();
        $companyRows->each(fn ($r) => $r->setAttribute('company', $companies->get($r->owner_company_id)));

        // Transporter performance — jobs per transporter in the range,
        // plus on-time %. NULL executing_company_id = platform-owner.
        $transporterRows = (clone $this->baseJobsQuery())
            ->whereBetween('completed_at', [$from, $to])
            ->selectRaw('executing_company_id, '
                . 'count(*) as job_count, '
                . 'count(*) filter (where delivered_at::date <= scheduled_date and coalesce(delay_minutes,0)=0) as on_time_count')
            ->groupBy('executing_company_id')
            ->orderByDesc('job_count')
            ->limit(8)
            ->get();

        $tpIds = $transporterRows->pluck('executing_company_id')->filter()->all();
        $tpMap = !empty($tpIds) ? Company::whereIn('id', $tpIds)->pluck('name', 'id') : collect();
        $transporterRows->each(function ($r) use ($tpMap) {
            $r->setAttribute('transporter_name', $r->executing_company_id
                ? ($tpMap->get($r->executing_company_id) ?? 'Unknown')
                : 'Platform / Internal');
            $r->setAttribute('on_time_pct', $r->job_count > 0
                ? (int) round(($r->on_time_count / $r->job_count) * 100)
                : null);
        });

        // Revenue snapshot — invoice surface, not financial forecasts.
        // "Outstanding" = issued invoices not yet paid.
        $invoiceIssued = Invoice::where('status', Invoice::STATUS_ISSUED)
            ->when($this->companyId, fn ($q) => $q->where('company_id', $this->companyId))
            ->selectRaw('count(*) as c, coalesce(sum(total),0) as v')
            ->first();
        $invoicePaid = Invoice::where('status', Invoice::STATUS_PAID)
            ->when($this->companyId, fn ($q) => $q->where('company_id', $this->companyId))
            ->whereBetween('generated_at', [$from, $to])
            ->selectRaw('count(*) as c, coalesce(sum(total),0) as v')
            ->first();
        $invoiceDraft = Invoice::where('status', Invoice::STATUS_DRAFT)
            ->when($this->companyId, fn ($q) => $q->where('company_id', $this->companyId))
            ->selectRaw('count(*) as c, coalesce(sum(total),0) as v')
            ->first();
        $awaitingInv = (clone $this->baseJobsQuery())
            ->whereIn('status', [Job::STATUS_DELIVERED, Job::STATUS_COMPLETED, Job::STATUS_READY_FOR_INVOICING])
            ->whereNull('invoiced_at')
            ->selectRaw('count(*) as c, coalesce(sum(total_sell_price),0) as v')
            ->first();

        // ─── Filter option lists ─────────────────────────────────────
        $companyOptions = Company::whereIn('type', [Company::TYPE_OEM, Company::TYPE_DEALER, Company::TYPE_CUSTOMER])
            ->where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $transporterOptions = Company::where('type', Company::TYPE_TRANSPORTER)
            ->where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $brandOptions = Brand::where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $regionOptions = Location::query()
            ->whereNotNull('province')->where('province', '!=', '')
            ->distinct()->orderBy('province')->pluck('province');
        $statusOptions = Inventory::STATUSES;

        return compact(
            'from', 'to', 'span', 'kpis',
            'activitySeries', 'activityPeak', 'statusDist',
            'scheduled', 'delivered', 'throughputPct',
            'exceptions', 'priority', 'locationPressure',
            'companyRows', 'transporterRows',
            'invoiceIssued', 'invoicePaid', 'invoiceDraft', 'awaitingInv',
            'companyOptions', 'transporterOptions', 'brandOptions', 'regionOptions', 'statusOptions',
            'th',
        );
    }

    // ───────── Helpers ─────────

    protected function trend(int $current, int $previous): ?array
    {
        if ($previous === 0 && $current === 0) { return null; }
        if ($previous === 0)                   { return ['dir' => 'up', 'label' => 'new']; }
        $delta = (int) round((($current - $previous) / $previous) * 100);
        return [
            'dir'   => $delta >= 0 ? 'up' : 'down',
            'label' => ($delta >= 0 ? '+' : '') . $delta . '%',
        ];
    }

    protected function riskFor(string $status, ?int $days, array $th): string
    {
        if ($days === null) { return 'low'; }
        return match ($status) {
            Inventory::STATUS_IN_TRANSIT =>
                $days >= $th['in_transit_days'] ? 'high' : ($days >= (int) round($th['in_transit_days'] / 2) ? 'med' : 'low'),
            Inventory::STATUS_AT_YARD, Inventory::STATUS_AT_STORAGE =>
                $days >= $th['at_yard_days'] ? 'high' : ($days >= (int) round($th['at_yard_days'] / 2) ? 'med' : 'low'),
            Inventory::STATUS_PRODUCED, Inventory::STATUS_AT_PLANT =>
                $days >= $th['at_plant_days'] ? 'high' : ($days >= (int) round($th['at_plant_days'] / 2) ? 'med' : 'low'),
            default => 'low',
        };
    }

    /** Safe in-operator list for Postgres FILTER clauses. */
    protected function pgStatusList(array $statuses): string
    {
        return collect($statuses)->map(fn ($s) => "'" . addslashes($s) . "'")->implode(',');
    }
};
?>

@php
    $money = fn ($v) => 'R ' . number_format((float) $v, 0);
    $num   = fn ($v) => number_format((int) $v);
@endphp

<div class="space-y-6">

    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- HEADER                                                         --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    <x-page-header
        eyebrow="Command centre"
        title="Vehicle Movement Overview"
        subtitle="Executive view of the inventory ledger — where every vehicle sits, how it's moving, and who's moving it.">
        <x-slot:actions>
            <x-button variant="secondary" size="sm" :href="route('admin.planning')">
                <x-slot:icon>
                    <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                </x-slot:icon>
                New Dispatch
            </x-button>
            <x-button variant="secondary" size="sm" :href="route('admin.deliveries')">
                <x-slot:icon>
                    <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                </x-slot:icon>
                Log Delivery
            </x-button>
            <x-button variant="primary" size="sm" :href="route('admin.reports.index')">
                <x-slot:icon>
                    <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m7 14 4-4 4 4 5-6"/></svg>
                </x-slot:icon>
                View Reports
            </x-button>
        </x-slot:actions>
    </x-page-header>

    {{-- Filter strip — the same <x-dash.*> filter system every other
         operations dashboard uses. --}}
    <x-dash.filter-bar>
        <x-dash.filter-date label="From" wire:model.live="dateFrom" minWidth="160px" />
        <x-dash.filter-date label="To"   wire:model.live="dateTo"   minWidth="160px" />
        <x-dash.filter-select label="Customer / OEM" wire:model.live="companyId" minWidth="200px">
            <option value="">All</option>
            @foreach($companyOptions as $c)
                <option value="{{ $c->id }}">{{ $c->name }}</option>
            @endforeach
        </x-dash.filter-select>
        <x-dash.filter-select label="Transporter" wire:model.live="transporterId" minWidth="200px">
            <option value="">All</option>
            @foreach($transporterOptions as $c)
                <option value="{{ $c->id }}">{{ $c->name }}</option>
            @endforeach
        </x-dash.filter-select>
        <x-dash.filter-select label="Brand" wire:model.live="brandId" minWidth="160px">
            <option value="">All</option>
            @foreach($brandOptions as $b)
                <option value="{{ $b->id }}">{{ $b->name }}</option>
            @endforeach
        </x-dash.filter-select>
        <x-dash.filter-select label="Region" wire:model.live="region" minWidth="160px">
            <option value="">All</option>
            @foreach($regionOptions as $r)
                <option value="{{ $r }}">{{ $r }}</option>
            @endforeach
        </x-dash.filter-select>
        <x-dash.filter-select label="Status" wire:model.live="status" minWidth="180px">
            <option value="">Any</option>
            @foreach($statusOptions as $s)
                <option value="{{ $s }}">{{ ucwords(str_replace('_', ' ', $s)) }}</option>
            @endforeach
        </x-dash.filter-select>
        <x-dash.filter-reset wire:click="resetFilters" />
    </x-dash.filter-bar>

    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- ROW 1 — KPI CARDS                                             --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4">
        @foreach($kpis as $k)
            <x-dash.kpi
                :label="$k['label']"
                :value="$num($k['value'])"
                :color="$k['color']"
                :href="$k['href']"
                :helper="$k['helper']"
                :trend="$k['trend']">
                <x-slot:icon>
                    <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">{!! $k['iconPath'] !!}</svg>
                </x-slot:icon>
            </x-dash.kpi>
        @endforeach
    </div>

    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- ROW 2 — FLOW & DISTRIBUTION                                   --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

        {{-- Movement activity chart --}}
        <x-dash.panel
            class="lg:col-span-2"
            title="Movement activity"
            :subtitle="'Daily dispatch, transit start, and delivery events · ' . $from->format('d M') . ' – ' . $to->format('d M')">
            <x-slot:actions>
                <div class="flex items-center gap-3 text-[11px] font-semibold text-slate-600">
                    <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-purple-500"></span>Dispatched</span>
                    <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-orange-500"></span>In transit</span>
                    <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-emerald-500"></span>Delivered</span>
                </div>
            </x-slot:actions>

            @if(count($activitySeries) === 0 || $activityPeak === 0)
                <div class="h-56 flex items-center justify-center text-sm text-slate-400">
                    No movement activity in this range
                </div>
            @else
                @php
                    $count = count($activitySeries);
                    $barGap = 2;
                    $chartH = 180;
                    $chartW = max(600, $count * 22);
                    $groupW = $chartW / max($count, 1);
                    $innerW = max(1, $groupW - 6);
                    $barW   = max(1, ($innerW / 3) - $barGap);
                @endphp
                <div class="overflow-x-auto">
                    <svg viewBox="0 0 {{ $chartW }} {{ $chartH + 30 }}" class="w-full h-60 min-w-[600px]" preserveAspectRatio="none">
                        {{-- Grid --}}
                        @for($i = 1; $i <= 4; $i++)
                            <line x1="0" x2="{{ $chartW }}" y1="{{ $chartH - ($chartH / 4) * $i }}" y2="{{ $chartH - ($chartH / 4) * $i }}" stroke="#f1f5f9" stroke-width="1"/>
                        @endfor
                        @foreach($activitySeries as $i => $d)
                            @php
                                $gx = $i * $groupW + 3;
                                $dh = $activityPeak > 0 ? ($d['dispatched'] / $activityPeak) * ($chartH - 6) : 0;
                                $th_ = $activityPeak > 0 ? ($d['in_transit'] / $activityPeak) * ($chartH - 6) : 0;
                                $vh = $activityPeak > 0 ? ($d['delivered'] / $activityPeak) * ($chartH - 6) : 0;
                            @endphp
                            <g>
                                <rect x="{{ $gx }}"                   y="{{ $chartH - $dh }}"  width="{{ $barW }}" height="{{ $dh }}" fill="#a855f7" rx="1.5"/>
                                <rect x="{{ $gx + $barW + $barGap }}" y="{{ $chartH - $th_ }}" width="{{ $barW }}" height="{{ $th_ }}" fill="#f97316" rx="1.5"/>
                                <rect x="{{ $gx + 2*($barW + $barGap) }}" y="{{ $chartH - $vh }}" width="{{ $barW }}" height="{{ $vh }}" fill="#10b981" rx="1.5"/>
                            </g>
                            @if($count <= 30 || $i % (int) ceil($count / 15) === 0)
                                <text x="{{ $gx + ($innerW / 2) }}" y="{{ $chartH + 16 }}" text-anchor="middle" font-size="9" fill="#64748b" font-family="ui-sans-serif,system-ui">{{ $d['date']->format('d/m') }}</text>
                            @endif
                        @endforeach
                    </svg>
                </div>
            @endif
        </x-dash.panel>

        {{-- Status distribution --}}
        <x-dash.panel title="Status distribution" subtitle="Active inventory by lifecycle state">
            @php
                $distTotal = array_sum($statusDist);
                // Hex colours used as inline style so we don't have to
                // rely on Tailwind content-scanning interpolated classes.
                $statusColorHex = [
                    'produced'   => '#6366f1',
                    'at_plant'   => '#818cf8',
                    'at_yard'    => '#f59e0b',
                    'at_storage' => '#fbbf24',
                    'in_transit' => '#3b82f6',
                    'delivered'  => '#10b981',
                ];
            @endphp
            @if($distTotal === 0)
                <p class="text-sm text-slate-400 text-center py-8">No active inventory</p>
            @else
                <ul class="space-y-3">
                    @foreach(\App\Models\Inventory::ACTIVE_STATUSES as $s)
                        @php
                            $n = $statusDist[$s] ?? 0;
                            $pct = $distTotal > 0 ? ($n / $distTotal) * 100 : 0;
                            $hex = $statusColorHex[$s] ?? '#94a3b8';
                        @endphp
                        <li>
                            <div class="flex items-center justify-between text-xs mb-1">
                                <span class="font-medium text-slate-700">{{ ucwords(str_replace('_', ' ', $s)) }}</span>
                                <span class="tabular-nums text-slate-500"><strong class="text-slate-900">{{ $num($n) }}</strong> · {{ number_format($pct, 0) }}%</span>
                            </div>
                            <div class="h-2 bg-slate-100 rounded-full overflow-hidden">
                                <div class="h-full rounded-full" style="width: {{ max(2, $pct) }}%; background-color: {{ $hex }};"></div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-dash.panel>
    </div>

    {{-- Throughput vs scheduled --}}
    <x-dash.panel
        title="Throughput vs scheduled"
        subtitle="Deliveries completed in range vs bookings scheduled for the same window">
        <x-slot:actions>
            <x-dash.pill size="md" :variant="$throughputPct >= 90 ? 'green' : ($throughputPct >= 70 ? 'amber' : 'red')">
                {{ $throughputPct }}% throughput
            </x-dash.pill>
        </x-slot:actions>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div>
                <p class="text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-500">Scheduled</p>
                <p class="mt-2 text-3xl font-semibold text-slate-900 tabular-nums">{{ $num($scheduled) }}</p>
                <p class="mt-1 text-xs text-slate-500">Bookings with a scheduled date in the window</p>
            </div>
            <div>
                <p class="text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-500">Delivered</p>
                <p class="mt-2 text-3xl font-semibold text-emerald-700 tabular-nums">{{ $num($delivered) }}</p>
                <p class="mt-1 text-xs text-slate-500">Jobs with a delivered_at timestamp in the window</p>
            </div>
            <div>
                <p class="text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-500">Gap</p>
                <p class="mt-2 text-3xl font-semibold {{ $scheduled - $delivered > 0 ? 'text-amber-700' : 'text-slate-900' }} tabular-nums">{{ $num(max(0, $scheduled - $delivered)) }}</p>
                <p class="mt-1 text-xs text-slate-500">Scheduled but not yet delivered</p>
            </div>
        </div>
        <div class="mt-5 h-3 bg-slate-100 rounded-full overflow-hidden">
            <div class="h-full {{ $throughputPct >= 90 ? 'bg-emerald-500' : ($throughputPct >= 70 ? 'bg-amber-500' : 'bg-rose-500') }} rounded-full transition-all"
                 style="width: {{ min(100, $throughputPct) }}%"></div>
        </div>
    </x-dash.panel>

    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- ROW 3 — OPERATIONS FOCUS                                      --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

        {{-- Exceptions --}}
        <x-dash.panel title="Exceptions" subtitle="Movements blocking the pipeline" :tight="true">
            <ul class="divide-y divide-slate-100">
                @foreach($exceptions as $e)
                    @php
                        $pillVariant = $e['count'] === 0
                            ? 'slate'
                            : ($e['severity'] === 'red' ? 'red' : 'amber');
                        $dotClass = match(true) {
                            $e['count'] === 0           => 'bg-slate-300',
                            $e['severity'] === 'red'    => 'bg-rose-500 node-pulse',
                            $e['severity'] === 'amber'  => 'bg-amber-500 node-pulse',
                            default                     => 'bg-slate-400',
                        };
                    @endphp
                    <li class="flex items-center gap-3 px-5 py-3">
                        <span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $dotClass }}"></span>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-slate-900 truncate">{{ $e['label'] }}</p>
                            <p class="text-[11px] text-slate-500 truncate">{{ $e['sublabel'] }}</p>
                        </div>
                        <x-dash.pill size="md" :variant="$pillVariant">{{ $num($e['count']) }}</x-dash.pill>
                    </li>
                @endforeach
            </ul>
        </x-dash.panel>

        {{-- Priority Movements --}}
        <x-dash.panel
            class="lg:col-span-2"
            title="Priority movements"
            subtitle="Longest dwell in current state · top 12"
            :tight="true">
            <x-slot:actions>
                <a href="{{ route('admin.vehicles.index') }}" class="text-xs font-semibold text-slate-600 hover:text-slate-900 inline-flex items-center gap-1">
                    View all vehicles
                    <svg viewBox="0 0 24 24" class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
                </a>
            </x-slot:actions>

            @if($priority->isEmpty())
                <p class="px-5 py-10 text-sm text-slate-400 text-center">No active inventory matches the current filters</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="bg-slate-50/70 text-[10px] uppercase tracking-[0.15em] text-slate-500">
                                <th class="px-4 py-2 text-left font-semibold">Chassis / VIN</th>
                                <th class="px-4 py-2 text-left font-semibold">Status</th>
                                <th class="px-4 py-2 text-left font-semibold">Location</th>
                                <th class="px-4 py-2 text-left font-semibold">Origin → Destination</th>
                                <th class="px-4 py-2 text-left font-semibold">Driver</th>
                                <th class="px-4 py-2 text-right font-semibold">Days</th>
                                <th class="px-4 py-2 text-center font-semibold">Risk</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($priority as $r)
                                @php
                                    $riskVariant = match($r->getAttribute('risk_level')) {
                                        'high' => 'red',
                                        'med'  => 'amber',
                                        default=> 'slate',
                                    };
                                    $riskLabel = match($r->getAttribute('risk_level')) {
                                        'high' => 'High',
                                        'med'  => 'Med',
                                        default=> 'Low',
                                    };
                                    $latest = $r->getAttribute('latest_job');
                                @endphp
                                <tr class="hover:bg-slate-50/60 transition-colors">
                                    <td class="px-4 py-2.5">
                                        <div class="font-mono text-[12px] text-slate-900">{{ $r->chassis_number }}</div>
                                        @if($r->vin && $r->vin !== $r->chassis_number)
                                            <div class="font-mono text-[10px] text-slate-400">{{ $r->vin }}</div>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5"><x-status-badge :status="$r->status" size="sm"/></td>
                                    <td class="px-4 py-2.5">
                                        <div class="text-[12px] text-slate-700">{{ $r->currentLocation?->company_name ?? '—' }}</div>
                                        <div class="text-[10px] text-slate-400">{{ $r->currentLocation?->city ?? '' }}</div>
                                    </td>
                                    <td class="px-4 py-2.5">
                                        @if($latest)
                                            <div class="text-[12px] text-slate-700 flex items-center gap-1">
                                                <span>{{ $latest->pickupLocation?->city ?? '—' }}</span>
                                                <svg viewBox="0 0 24 24" class="h-3 w-3 text-slate-300" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                                                <span>{{ $latest->deliveryLocation?->city ?? '—' }}</span>
                                            </div>
                                        @else
                                            <span class="text-[11px] text-slate-400">No open job</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5 text-[12px] text-slate-700">{{ $latest?->driver?->name ?? '—' }}</td>
                                    <td class="px-4 py-2.5 text-right text-[12px] tabular-nums text-slate-700">{{ $r->getAttribute('days_in_status') ?? '—' }}d</td>
                                    <td class="px-4 py-2.5 text-center">
                                        <x-dash.pill :variant="$riskVariant">{{ $riskLabel }}</x-dash.pill>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-dash.panel>
    </div>

    {{-- Location Pressure --}}
    <x-dash.panel title="Location pressure" subtitle="Active inventory by the type of place it's sitting in">
        <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
            @php
                $locTypes = [
                    'plant'        => ['Plant',        'indigo'],
                    'yard'         => ['Yard',         'amber'],
                    'storage'      => ['Storage',      'amber'],
                    'dealer'       => ['Dealer',       'emerald'],
                    'body_builder' => ['Body builder', 'blue'],
                ];
            @endphp
            @foreach($locTypes as $type => [$label, $color])
                @php
                    $n = $locationPressure[$type] ?? 0;
                    $tint = [
                        'indigo'  => ['border-indigo-200 bg-indigo-50',  'text-indigo-700'],
                        'amber'   => ['border-amber-200 bg-amber-50',    'text-amber-800'],
                        'emerald' => ['border-emerald-200 bg-emerald-50','text-emerald-700'],
                        'blue'    => ['border-blue-200 bg-blue-50',      'text-blue-700'],
                    ][$color];
                @endphp
                <div class="rounded-xl border {{ $tint[0] }} p-4">
                    <p class="text-[10px] font-semibold uppercase tracking-[0.2em] {{ $tint[1] }}">{{ $label }}</p>
                    <p class="mt-2 text-2xl font-semibold tabular-nums {{ $tint[1] }}">{{ $num($n) }}</p>
                </div>
            @endforeach
        </div>
    </x-dash.panel>

    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- ROW 4 — EXECUTIVE INSIGHT                                     --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

        {{-- Company / OEM Breakdown --}}
        <x-dash.panel title="Customer / OEM breakdown" subtitle="Top 8 by active inventory" :tight="true">
            @if($companyRows->isEmpty())
                <p class="px-5 py-10 text-sm text-slate-400 text-center">No inventory rows</p>
            @else
                @php $topActive = $companyRows->max('active_count') ?: 1; @endphp
                <ul class="divide-y divide-slate-100">
                    @foreach($companyRows as $r)
                        <li class="px-5 py-3">
                            <div class="flex items-center justify-between gap-3 mb-1">
                                <span class="text-sm font-medium text-slate-900 truncate">{{ $r->company?->name ?? '—' }}</span>
                                <span class="text-[11px] tabular-nums shrink-0">
                                    <span class="font-semibold text-slate-900">{{ $num($r->active_count) }}</span>
                                    <span class="text-slate-400">active</span>
                                    <span class="mx-1.5 text-slate-300">·</span>
                                    <span class="font-semibold text-emerald-700">{{ $num($r->delivered_count) }}</span>
                                    <span class="text-slate-400">delivered</span>
                                </span>
                            </div>
                            <div class="h-1.5 bg-slate-100 rounded-full overflow-hidden">
                                <div class="h-full bg-blue-500 rounded-full" style="width: {{ ($r->active_count / $topActive) * 100 }}%"></div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-dash.panel>

        {{-- Transporter Performance --}}
        <x-dash.panel title="Transporter performance" subtitle="Completed jobs in range · on-time ratio" :tight="true">
            @if($transporterRows->isEmpty())
                <p class="px-5 py-10 text-sm text-slate-400 text-center">No completed jobs in this range</p>
            @else
                <ul class="divide-y divide-slate-100">
                    @foreach($transporterRows as $r)
                        @php
                            $otp = $r->getAttribute('on_time_pct');
                            $variant = $otp === null ? 'slate'
                                : ($otp >= 90 ? 'green'
                                : ($otp >= 70 ? 'amber' : 'red'));
                        @endphp
                        <li class="flex items-center gap-3 px-5 py-3">
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-slate-900 truncate">{{ $r->getAttribute('transporter_name') }}</p>
                                <p class="text-[11px] text-slate-500 tabular-nums">{{ $num($r->job_count) }} {{ \Illuminate\Support\Str::plural('job', $r->job_count) }} · {{ $num($r->on_time_count) }} on-time</p>
                            </div>
                            <x-dash.pill :variant="$variant">{{ $otp === null ? 'n/a' : $otp . '%' }}</x-dash.pill>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-dash.panel>

        {{-- Invoice Snapshot (invoice-based, not forecast) --}}
        <x-dash.panel title="Invoice snapshot" subtitle="What's invoiced, paid and outstanding" :tight="true">
            <ul class="divide-y divide-slate-100">
                <li class="flex items-center gap-3 px-5 py-3">
                    <span class="h-1.5 w-1.5 rounded-full bg-slate-400"></span>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium text-slate-900">Draft</p>
                        <p class="text-[11px] text-slate-500 tabular-nums">{{ $num($invoiceDraft->c ?? 0) }} invoices</p>
                    </div>
                    <span class="text-sm font-semibold text-slate-900 tabular-nums shrink-0">{{ $money($invoiceDraft->v ?? 0) }}</span>
                </li>
                <li class="flex items-center gap-3 px-5 py-3">
                    <span class="h-1.5 w-1.5 rounded-full bg-blue-500"></span>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium text-slate-900">Issued (outstanding)</p>
                        <p class="text-[11px] text-slate-500 tabular-nums">{{ $num($invoiceIssued->c ?? 0) }} invoices</p>
                    </div>
                    <span class="text-sm font-semibold text-blue-700 tabular-nums shrink-0">{{ $money($invoiceIssued->v ?? 0) }}</span>
                </li>
                <li class="flex items-center gap-3 px-5 py-3">
                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium text-slate-900">Paid (in range)</p>
                        <p class="text-[11px] text-slate-500 tabular-nums">{{ $num($invoicePaid->c ?? 0) }} invoices</p>
                    </div>
                    <span class="text-sm font-semibold text-emerald-700 tabular-nums shrink-0">{{ $money($invoicePaid->v ?? 0) }}</span>
                </li>
                <li class="flex items-center gap-3 px-5 py-3 bg-amber-50/40">
                    <span class="h-1.5 w-1.5 rounded-full bg-amber-500 node-pulse"></span>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium text-slate-900">Awaiting invoicing</p>
                        <p class="text-[11px] text-slate-500 tabular-nums">{{ $num($awaitingInv->c ?? 0) }} jobs delivered / completed</p>
                    </div>
                    <span class="text-sm font-semibold text-amber-700 tabular-nums shrink-0">{{ $money($awaitingInv->v ?? 0) }}</span>
                </li>
            </ul>

            <x-slot:footer>
                <div class="text-right">
                    <a href="{{ route('admin.invoices.index') }}" class="text-[11px] font-semibold text-slate-600 hover:text-slate-900 inline-flex items-center gap-1">
                        Go to invoices
                        <svg viewBox="0 0 24 24" class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
                    </a>
                </div>
            </x-slot:footer>
        </x-dash.panel>
    </div>

    <p class="text-center text-[10px] text-slate-400 tracking-[0.2em] uppercase pt-2">
        Trident · Executive Overview · Sources: inventory · transport_jobs · invoices · locations
    </p>
</div>
