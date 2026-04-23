<?php

use App\Models\Brand;
use App\Models\Company;
use App\Models\Job;
use App\Models\Location;
use App\Models\SystemSetting;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    /**
     * ╔══════════════════════════════════════════════════════════════════╗
     * ║  CUSTOMER / OEM DASHBOARD                                        ║
     * ╠══════════════════════════════════════════════════════════════════╣
     * ║  Same command-centre shell as /admin/dashboard (the executive    ║
     * ║  overview), but:                                                 ║
     * ║    • scoped to the logged-in customer's company_id only         ║
     * ║    • no invoice / financial metrics                              ║
     * ║    • no driver / transporter columns or panels                   ║
     * ║    • "New Order" button pinned to the header                     ║
     * ║                                                                  ║
     * ║  Same pipeline focus:                                            ║
     * ║     Received → Confirmed → Dispatched → In transit → Delivered   ║
     * ╚══════════════════════════════════════════════════════════════════╝
     */

    public ?Company $company = null;
    public bool $requiresConfirmation = false;

    // ─── Filter state (URL-persistent) ──────────────────────────────
    #[Url] public ?string $dateFrom = null;
    #[Url] public ?string $dateTo = null;
    #[Url] public ?int $brandId = null;
    #[Url] public ?string $region = null;   // pickup or delivery province
    #[Url] public ?string $status = null;   // pipeline stage

    // ─── Phase 1 logical groupings (mirrors admin dashboard) ────────
    protected const G_INTAKE = [
        Job::STATUS_PENDING_VERIFICATION,
        Job::STATUS_RECEIVED,
        Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION,
        Job::STATUS_CONFIRMATION_ISSUE,
    ];
    protected const G_TO_DISPATCH = [
        Job::STATUS_CONFIRMED,
    ];
    protected const G_DISPATCHED = [
        Job::STATUS_PLANNED,
        Job::STATUS_DRIVER_ASSIGNED,
        Job::STATUS_READY_FOR_COLLECTION,
    ];
    protected const G_IN_TRANSIT = [
        Job::STATUS_COLLECTED,
        Job::STATUS_IN_TRANSIT,
    ];
    protected const G_DELIVERED = [
        Job::STATUS_DELIVERED,
        Job::STATUS_COMPLETED,
    ];
    protected const ACTIVE_PHASE1 = [
        Job::STATUS_PENDING_VERIFICATION,
        Job::STATUS_RECEIVED,
        Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION,
        Job::STATUS_CONFIRMATION_ISSUE,
        Job::STATUS_CONFIRMED,
        Job::STATUS_PLANNED,
        Job::STATUS_DRIVER_ASSIGNED,
        Job::STATUS_READY_FOR_COLLECTION,
        Job::STATUS_COLLECTED,
        Job::STATUS_IN_TRANSIT,
    ];

    public function mount(): void
    {
        $this->company = auth()->user()->company();
        abort_unless($this->company, 403, 'No company associated with your account.');
        $this->requiresConfirmation = $this->company->requiresExternalConfirmation();

        if (!$this->dateFrom) { $this->dateFrom = now()->subDays(29)->toDateString(); }
        if (!$this->dateTo)   { $this->dateTo   = now()->toDateString(); }
    }

    public function resetFilters(): void
    {
        $this->reset(['brandId', 'region', 'status']);
        $this->dateFrom = now()->subDays(29)->toDateString();
        $this->dateTo   = now()->toDateString();
    }

    // Company-scoped base query — every panel starts from here so we
    // can never leak another customer's data into this dashboard.
    protected function baseJobsQuery(bool $applyStatusFilter = false)
    {
        // Fully-qualify every column we touch here. Some callers of this
        // helper join `locations` (which also has company_id / brand_id /
        // status / updated_at columns), so an unqualified WHERE triggers
        // SQLSTATE 42702 "column reference is ambiguous" in Postgres.
        $q = Job::where('transport_jobs.company_id', $this->company->id);

        if ($this->brandId) { $q->where('transport_jobs.brand_id', $this->brandId); }
        if ($this->region) {
            $q->where(function ($w) {
                $w->whereHas('pickupLocation',    fn ($l) => $l->where('province', $this->region))
                  ->orWhereHas('deliveryLocation', fn ($l) => $l->where('province', $this->region));
            });
        }
        if ($applyStatusFilter && $this->status) {
            $q->where('transport_jobs.status', $this->status);
        }
        return $q;
    }

    protected function thresholds(): array
    {
        return [
            'awaiting_confirm_days' => (int) SystemSetting::get('ops.alert.awaiting_confirm_days', 2),
            'to_dispatch_hours'     => (int) SystemSetting::get('ops.alert.to_dispatch_hours', 24),
            'dispatched_days'       => (int) SystemSetting::get('ops.alert.dispatched_days', 2),
            'in_transit_days'       => (int) SystemSetting::get('ops.alert.in_transit_days', 3),
        ];
    }

    public function with(): array
    {
        $from = Carbon::parse($this->dateFrom)->startOfDay();
        $to   = Carbon::parse($this->dateTo)->endOfDay();
        $span = max(1, $from->diffInDays($to) + 1);
        $prevTo   = (clone $from)->subDay()->endOfDay();
        $prevFrom = (clone $prevTo)->subDays($span - 1)->startOfDay();

        $th = $this->thresholds();

        // ─── KPIs ────────────────────────────────────────────────────
        $intake       = (clone $this->baseJobsQuery())->whereIn('status', self::G_INTAKE)->count();
        $awaitingMine = $this->requiresConfirmation
            ? (clone $this->baseJobsQuery())->where('status', Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION)->count()
            : 0;
        $toDispatch   = (clone $this->baseJobsQuery())->whereIn('status', self::G_TO_DISPATCH)->count();
        $onRoad       = (clone $this->baseJobsQuery())->whereIn('status', self::G_IN_TRANSIT)->count();

        $deliveredRange = (clone $this->baseJobsQuery())
            ->whereNotNull('delivered_at')
            ->whereBetween('delivered_at', [$from, $to])
            ->count();
        $deliveredPrev = (clone $this->baseJobsQuery())
            ->whereNotNull('delivered_at')
            ->whereBetween('delivered_at', [$prevFrom, $prevTo])
            ->count();

        // "Needs attention" — your orders sitting in their stage too long.
        $atRisk = (clone $this->baseJobsQuery())
            ->where(function ($w) use ($th) {
                $w->where(function ($a) use ($th) {
                    $a->whereIn('status', [Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION, Job::STATUS_CONFIRMATION_ISSUE])
                      ->where('updated_at', '<=', now()->subDays($th['awaiting_confirm_days']));
                })->orWhere(function ($a) use ($th) {
                    $a->where('status', Job::STATUS_CONFIRMED)
                      ->where('updated_at', '<=', now()->subHours($th['to_dispatch_hours']));
                })->orWhere(function ($a) use ($th) {
                    $a->whereIn('status', self::G_DISPATCHED)
                      ->where('updated_at', '<=', now()->subDays($th['dispatched_days']));
                })->orWhere(function ($a) use ($th) {
                    $a->whereIn('status', self::G_IN_TRANSIT)
                      ->where('updated_at', '<=', now()->subDays($th['in_transit_days']));
                });
            })
            ->count();

        // Prev-period in-status proxies for trend arrows.
        $prevIntake     = (clone $this->baseJobsQuery())->whereIn('status', self::G_INTAKE)->whereBetween('updated_at', [$prevFrom, $prevTo])->count();
        $prevToDispatch = (clone $this->baseJobsQuery())->whereIn('status', self::G_TO_DISPATCH)->whereBetween('updated_at', [$prevFrom, $prevTo])->count();
        $prevOnRoad     = (clone $this->baseJobsQuery())->whereIn('status', self::G_IN_TRANSIT)->whereBetween('updated_at', [$prevFrom, $prevTo])->count();

        $kpis = [];

        $kpis[] = [
            'key' => 'intake',
            'label' => 'New orders',
            'value' => $intake,
            'color' => 'amber',
            'href' => route('customer.orders.index'),
            'trend' => $this->trend($intake, $prevIntake),
            'helper' => 'Received · awaiting review / confirmation',
            'iconPath' => '<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.29 1.51 4.04 3 5.5l7 7Z"/>',
        ];

        if ($this->requiresConfirmation) {
            $kpis[] = [
                'key' => 'awaiting_mine',
                'label' => 'Awaiting my confirmation',
                'value' => $awaitingMine,
                'color' => $awaitingMine > 0 ? 'amber' : 'slate',
                'href' => route('customer.orders.index', ['statusFilter' => Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION]),
                'trend' => null,
                'helper' => 'Confirm so dispatch can proceed',
                'iconPath' => '<path d="M12 22s-8-4.5-8-11.8A8 8 0 0 1 12 2a8 8 0 0 1 8 8.2c0 7.3-8 11.8-8 11.8Z"/><path d="m9 11 2 2 4-4"/>',
            ];
        }

        $kpis[] = [
            'key' => 'to_dispatch',
            'label' => 'Ready to dispatch',
            'value' => $toDispatch,
            'color' => 'indigo',
            'href' => route('customer.orders.index', ['statusFilter' => Job::STATUS_CONFIRMED]),
            'trend' => $this->trend($toDispatch, $prevToDispatch),
            'helper' => 'Confirmed · scheduling in progress',
            'iconPath' => '<path d="M8 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-3"/><path d="M16 3h5v5"/><path d="M21 3 9 15"/>',
        ];

        $kpis[] = [
            'key' => 'on_road',
            'label' => 'On the road',
            'value' => $onRoad,
            'color' => 'blue',
            'href' => route('customer.orders.index', ['statusFilter' => Job::STATUS_IN_TRANSIT]),
            'trend' => $this->trend($onRoad, $prevOnRoad),
            'helper' => 'Collected · in transit',
            'iconPath' => '<path d="M14 16H9m10 0h3v-3.15a1 1 0 0 0-.84-.99L16 11l-2.7-3.6a1 1 0 0 0-.8-.4H5.24a2 2 0 0 0-1.8 1.1l-.8 1.63A6 6 0 0 0 2 12.42V16h2"/><circle cx="6.5" cy="16.5" r="2.5"/><circle cx="16.5" cy="16.5" r="2.5"/>',
        ];

        $kpis[] = [
            'key' => 'delivered',
            'label' => 'Delivered (range)',
            'value' => $deliveredRange,
            'color' => 'green',
            'href' => null,
            'trend' => $this->trend($deliveredRange, $deliveredPrev),
            'helper' => $from->format('d M') . ' – ' . $to->format('d M'),
            'iconPath' => '<path d="M20 6 9 17l-5-5"/>',
        ];

        $kpis[] = [
            'key' => 'at_risk',
            'label' => 'Needs attention',
            'value' => $atRisk,
            'color' => $atRisk > 0 ? 'red' : 'slate',
            'href' => null,
            'trend' => null,
            'helper' => "Confirm >{$th['awaiting_confirm_days']}d · Transit >{$th['in_transit_days']}d",
            'iconPath' => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
        ];

        // ─── Pipeline activity chart (dispatched / in_transit / delivered daily) ─
        $days = [];
        $cursor = $from->copy();
        while ($cursor->lte($to)) {
            $days[$cursor->toDateString()] = [
                'date'       => $cursor->copy(),
                'dispatched' => 0,
                'in_transit' => 0,
                'delivered'  => 0,
            ];
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
                    if (isset($days[$k])) { $days[$k][$bucket]++; }
                }
            }
        }
        $activitySeries = array_values($days);
        $activityPeak   = max(array_merge([1], array_map(fn ($d) => max($d['dispatched'], $d['in_transit'], $d['delivered']), $activitySeries)));

        // ─── Pipeline distribution ───────────────────────────────────
        $stageGroups = [
            'intake'      => ['label' => 'Intake',            'statuses' => self::G_INTAKE,      'hex' => '#f59e0b'],
            'to_dispatch' => ['label' => 'Ready to dispatch', 'statuses' => self::G_TO_DISPATCH, 'hex' => '#6366f1'],
            'dispatched'  => ['label' => 'Dispatched',        'statuses' => self::G_DISPATCHED,  'hex' => '#a855f7'],
            'in_transit'  => ['label' => 'On the road',       'statuses' => self::G_IN_TRANSIT,  'hex' => '#3b82f6'],
        ];
        $stageCounts = (clone $this->baseJobsQuery())
            ->whereIn('status', self::ACTIVE_PHASE1)
            ->selectRaw('status, count(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status')
            ->toArray();
        foreach ($stageGroups as $key => &$grp) {
            $grp['count'] = (int) collect($grp['statuses'])->sum(fn ($s) => (int) ($stageCounts[$s] ?? 0));
        }
        unset($grp);
        $pipelineTotal = array_sum(array_column($stageGroups, 'count'));

        // ─── Throughput (scheduled vs delivered in range) ───────────
        $scheduled = (clone $this->baseJobsQuery())
            ->whereBetween('scheduled_date', [$from->toDateString(), $to->toDateString()])
            ->count();
        $delivered = (clone $this->baseJobsQuery())
            ->whereNotNull('delivered_at')
            ->whereBetween('delivered_at', [$from, $to])
            ->count();
        $throughputPct = $scheduled > 0 ? (int) round(($delivered / $scheduled) * 100) : 0;

        // ─── Exceptions ──────────────────────────────────────────────
        // Customer version: pipeline-level only. We still flag "Awaiting
        // my confirmation" so the customer knows *they* are the blocker.
        $exceptions = [];
        if ($this->requiresConfirmation) {
            $exceptions[] = [
                'key'      => 'awaiting_mine',
                'label'    => 'Awaiting your confirmation',
                'sublabel' => 'Confirm to let us start',
                'count'    => (clone $this->baseJobsQuery())
                    ->where('status', Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION)
                    ->count(),
                'severity' => 'amber',
            ];
        }
        $exceptions = array_merge($exceptions, [
            [
                'key' => 'confirmation_issue',
                'label' => 'Confirmation issue',
                'sublabel' => 'Awaiting resolution',
                'count' => (clone $this->baseJobsQuery())
                    ->where('status', Job::STATUS_CONFIRMATION_ISSUE)
                    ->count(),
                'severity' => 'red',
            ],
            [
                'key' => 'dispatched_stale',
                'label' => 'Dispatched · not collected',
                'sublabel' => "> {$th['dispatched_days']}d",
                'count' => (clone $this->baseJobsQuery())
                    ->whereIn('status', self::G_DISPATCHED)
                    ->where('updated_at', '<=', now()->subDays($th['dispatched_days']))
                    ->count(),
                'severity' => 'amber',
            ],
            [
                'key' => 'long_transit',
                'label' => 'Long in transit',
                'sublabel' => "> {$th['in_transit_days']}d",
                'count' => (clone $this->baseJobsQuery())
                    ->whereIn('status', self::G_IN_TRANSIT)
                    ->where('updated_at', '<=', now()->subDays($th['in_transit_days']))
                    ->count(),
                'severity' => 'red',
            ],
            [
                'key' => 'delivered_open',
                'label' => 'Delivered · not signed off',
                'sublabel' => 'POD / paperwork pending',
                'count' => (clone $this->baseJobsQuery())
                    ->where('status', Job::STATUS_DELIVERED)
                    ->whereNull('completed_at')
                    ->count(),
                'severity' => 'amber',
            ],
        ]);

        // ─── Priority orders (longest in current stage, top 12) ──────
        // No driver column — customers don't need to see or care about
        // the executing driver. Only the stage + dwell time matter.
        $priority = (clone $this->baseJobsQuery(applyStatusFilter: true))
            ->whereIn('status', self::ACTIVE_PHASE1)
            ->with([
                'pickupLocation:id,city,province',
                'deliveryLocation:id,city,province',
                'brand:id,name',
                'inventory:id,chassis_number,vin',
            ])
            ->orderBy('updated_at', 'asc')
            ->limit(12)
            ->get();

        foreach ($priority as $row) {
            $daysInStage = $row->updated_at ? (int) $row->updated_at->diffInDays(now()) : null;
            $hrsInStage  = $row->updated_at ? (int) $row->updated_at->diffInHours(now()) : null;
            $row->setAttribute('days_in_stage', $daysInStage);
            $row->setAttribute('hours_in_stage', $hrsInStage);
            $row->setAttribute('risk_level', $this->riskFor($row->status, $daysInStage, $hrsInStage, $th));
        }

        // ─── Insight row: Brands + Destinations (replaces customer/OEM + transporter panels) ──
        // Top brands this customer is moving, by active + delivered count.
        $brandRows = (clone $this->baseJobsQuery())
            ->selectRaw('brand_id, '
                . 'count(*) filter (where status in (' . $this->pgStatusList(self::ACTIVE_PHASE1) . ')) as active_count, '
                . 'count(*) filter (where delivered_at between ? and ?) as delivered_count', [$from, $to])
            ->whereNotNull('brand_id')
            ->groupBy('brand_id')
            ->orderByDesc('active_count')
            ->orderByDesc('delivered_count')
            ->limit(8)
            ->get();
        $brandIds = $brandRows->pluck('brand_id')->all();
        $brandMap = !empty($brandIds)
            ? Brand::whereIn('id', $brandIds)->pluck('name', 'id')
            : collect();
        $brandRows->each(fn ($r) => $r->setAttribute('brand_name', $brandMap->get($r->brand_id) ?? 'Unknown'));

        // Top delivery destinations (city / province pairs).
        $destinationRows = (clone $this->baseJobsQuery())
            ->whereNotNull('delivered_at')
            ->whereBetween('delivered_at', [$from, $to])
            ->join('locations', 'transport_jobs.delivery_location_id', '=', 'locations.id')
            ->selectRaw('locations.city as city, locations.province as province, count(*) as n')
            ->groupBy('locations.city', 'locations.province')
            ->orderByDesc('n')
            ->limit(8)
            ->get();

        // Recent deliveries with POD — gives customer a signed-off feel.
        $recentDeliveries = (clone $this->baseJobsQuery())
            ->whereIn('status', [Job::STATUS_DELIVERED, Job::STATUS_COMPLETED])
            ->with([
                'deliveryLocation:id,city,province,company_name',
                'brand:id,name',
                'documents',
            ])
            ->orderByDesc(\Illuminate\Support\Facades\DB::raw('coalesce(completed_at, delivered_at)'))
            ->limit(8)
            ->get();

        // ─── Filter option lists ─────────────────────────────────────
        $brandOptions = Brand::where('is_active', true)->orderBy('name')->get(['id', 'name']);
        // Regions — only the ones touched by this customer's jobs.
        $regionOptions = Location::query()
            ->whereNotNull('province')
            ->where('province', '!=', '')
            ->where(function ($q) {
                $q->whereIn('id', Job::where('company_id', $this->company->id)->pluck('pickup_location_id'))
                  ->orWhereIn('id', Job::where('company_id', $this->company->id)->pluck('delivery_location_id'));
            })
            ->distinct()->orderBy('province')->pluck('province');
        $statusOptions = self::ACTIVE_PHASE1;

        return compact(
            'from', 'to', 'span', 'kpis',
            'activitySeries', 'activityPeak',
            'stageGroups', 'pipelineTotal',
            'scheduled', 'delivered', 'throughputPct',
            'exceptions', 'priority',
            'brandRows', 'destinationRows', 'recentDeliveries',
            'brandOptions', 'regionOptions', 'statusOptions',
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

    protected function riskFor(string $status, ?int $days, ?int $hours, array $th): string
    {
        if ($days === null) { return 'low'; }

        if (in_array($status, [Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION, Job::STATUS_CONFIRMATION_ISSUE], true)) {
            $hi = $th['awaiting_confirm_days'];
            return $days >= $hi ? 'high' : ($days >= max(1, (int) round($hi / 2)) ? 'med' : 'low');
        }
        if (in_array($status, [Job::STATUS_PENDING_VERIFICATION, Job::STATUS_RECEIVED], true)) {
            return $days >= 1 ? 'med' : 'low';
        }
        if ($status === Job::STATUS_CONFIRMED) {
            $hi = $th['to_dispatch_hours'];
            $h = $hours ?? 0;
            return $h >= $hi ? 'high' : ($h >= (int) round($hi / 2) ? 'med' : 'low');
        }
        if (in_array($status, self::G_DISPATCHED, true)) {
            $hi = $th['dispatched_days'];
            return $days >= $hi ? 'high' : ($days >= max(1, (int) round($hi / 2)) ? 'med' : 'low');
        }
        if (in_array($status, self::G_IN_TRANSIT, true)) {
            $hi = $th['in_transit_days'];
            return $days >= $hi ? 'high' : ($days >= max(1, (int) round($hi / 2)) ? 'med' : 'low');
        }
        return 'low';
    }

    /** Safe in-operator list for Postgres FILTER clauses. */
    protected function pgStatusList(array $statuses): string
    {
        return collect($statuses)->map(fn ($s) => "'" . addslashes($s) . "'")->implode(',');
    }
};
?>

@php
    $num      = fn ($v) => number_format((int) $v);
    $canOrder = auth()->user()->hasPermission('submit_booking');
@endphp

<div class="space-y-6">

    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- HEADER                                                         --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    <x-page-header
        eyebrow="{{ $company->name }}"
        title="Overview"
        subtitle="Every order in flight · received → confirmed → dispatched → in transit → delivered.">
        <x-slot:actions>
            <x-button variant="secondary" size="sm" :href="route('customer.orders.index')">
                <x-slot:icon>
                    <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3h18v18H3z"/><path d="M3 9h18"/><path d="M9 21V9"/></svg>
                </x-slot:icon>
                All orders
            </x-button>
            <x-button variant="secondary" size="sm" :href="route('customer.documents')">
                <x-slot:icon>
                    <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/></svg>
                </x-slot:icon>
                Documents
            </x-button>
            @if($canOrder)
                <x-button variant="primary" size="sm" :href="route('customer.orders.create')">
                    <x-slot:icon>
                        <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                    </x-slot:icon>
                    New Order
                </x-button>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if(session('success'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('success') }}</div>
    @endif

    {{-- Filter strip --}}
    <x-dash.filter-bar>
        <x-dash.filter-date label="From" wire:model.live="dateFrom" minWidth="160px" />
        <x-dash.filter-date label="To"   wire:model.live="dateTo"   minWidth="160px" />
        <x-dash.filter-select label="Brand" wire:model.live="brandId" minWidth="180px">
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
        <x-dash.filter-select label="Stage" wire:model.live="status" minWidth="200px">
            <option value="">Any</option>
            @foreach($statusOptions as $s)
                <option value="{{ $s }}">{{ \App\Models\Job::PHASE1_STATUS_LABELS[$s] ?? ucwords(str_replace('_', ' ', $s)) }}</option>
            @endforeach
        </x-dash.filter-select>
        <x-dash.filter-reset wire:click="resetFilters" />
    </x-dash.filter-bar>

    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- ROW 1 — KPI CARDS                                              --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    @php
        // Keep Tailwind JIT happy — dynamic class names aren't scanned.
        // 5 KPIs (no external confirmation) or 6 KPIs (with confirmation).
        $kpiGridClass = count($kpis) >= 6
            ? 'grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4'
            : 'grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-4';
    @endphp
    <div class="{{ $kpiGridClass }}">
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
    {{-- ROW 2 — FLOW & PIPELINE                                        --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

        {{-- Pipeline activity chart --}}
        <x-dash.panel
            class="lg:col-span-2"
            title="Order activity"
            :subtitle="'Daily dispatch, transit start and delivery · ' . $from->format('d M') . ' – ' . $to->format('d M')">
            <x-slot:actions>
                <div class="flex items-center gap-3 text-[11px] font-semibold text-slate-600">
                    <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-purple-500"></span>Dispatched</span>
                    <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-orange-500"></span>In transit</span>
                    <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-emerald-500"></span>Delivered</span>
                </div>
            </x-slot:actions>

            @if(count($activitySeries) === 0 || $activityPeak === 0)
                <div class="h-56 flex items-center justify-center text-sm text-slate-400">
                    No activity in this range
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
                        @for($i = 1; $i <= 4; $i++)
                            <line x1="0" x2="{{ $chartW }}" y1="{{ $chartH - ($chartH / 4) * $i }}" y2="{{ $chartH - ($chartH / 4) * $i }}" stroke="#f1f5f9" stroke-width="1"/>
                        @endfor
                        @foreach($activitySeries as $i => $d)
                            @php
                                $gx = $i * $groupW + 3;
                                $dh = $activityPeak > 0 ? ($d['dispatched'] / $activityPeak) * ($chartH - 6) : 0;
                                $th_ = $activityPeak > 0 ? ($d['in_transit'] / $activityPeak) * ($chartH - 6) : 0;
                                $vh = $activityPeak > 0 ? ($d['delivered']  / $activityPeak) * ($chartH - 6) : 0;
                            @endphp
                            <g>
                                <rect x="{{ $gx }}"                     y="{{ $chartH - $dh }}"  width="{{ $barW }}" height="{{ $dh }}"  fill="#a855f7" rx="1.5"/>
                                <rect x="{{ $gx + $barW + $barGap }}"   y="{{ $chartH - $th_ }}" width="{{ $barW }}" height="{{ $th_ }}" fill="#f97316" rx="1.5"/>
                                <rect x="{{ $gx + 2*($barW + $barGap) }}" y="{{ $chartH - $vh }}"  width="{{ $barW }}" height="{{ $vh }}"  fill="#10b981" rx="1.5"/>
                            </g>
                            @if($count <= 30 || $i % (int) ceil($count / 15) === 0)
                                <text x="{{ $gx + ($innerW / 2) }}" y="{{ $chartH + 16 }}" text-anchor="middle" font-size="9" fill="#64748b" font-family="ui-sans-serif,system-ui">{{ $d['date']->format('d/m') }}</text>
                            @endif
                        @endforeach
                    </svg>
                </div>
            @endif
        </x-dash.panel>

        {{-- Pipeline distribution --}}
        <x-dash.panel title="Pipeline distribution" subtitle="Active orders by stage">
            @if($pipelineTotal === 0)
                <p class="text-sm text-slate-400 text-center py-8">No active orders</p>
            @else
                <ul class="space-y-3">
                    @foreach($stageGroups as $grp)
                        @php $pct = $pipelineTotal > 0 ? ($grp['count'] / $pipelineTotal) * 100 : 0; @endphp
                        <li>
                            <div class="flex items-center justify-between text-xs mb-1">
                                <span class="font-medium text-slate-700">{{ $grp['label'] }}</span>
                                <span class="tabular-nums text-slate-500"><strong class="text-slate-900">{{ $num($grp['count']) }}</strong> · {{ number_format($pct, 0) }}%</span>
                            </div>
                            <div class="h-2 bg-slate-100 rounded-full overflow-hidden">
                                <div class="h-full rounded-full" style="width: {{ max(2, $pct) }}%; background-color: {{ $grp['hex'] }};"></div>
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
        subtitle="Deliveries completed vs orders scheduled for the same window">
        <x-slot:actions>
            <x-dash.pill size="md" :variant="$throughputPct >= 90 ? 'green' : ($throughputPct >= 70 ? 'amber' : 'red')">
                {{ $throughputPct }}% throughput
            </x-dash.pill>
        </x-slot:actions>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div>
                <p class="text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-500">Scheduled</p>
                <p class="mt-2 text-3xl font-semibold text-slate-900 tabular-nums">{{ $num($scheduled) }}</p>
                <p class="mt-1 text-xs text-slate-500">Orders with a scheduled date in the window</p>
            </div>
            <div>
                <p class="text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-500">Delivered</p>
                <p class="mt-2 text-3xl font-semibold text-emerald-700 tabular-nums">{{ $num($delivered) }}</p>
                <p class="mt-1 text-xs text-slate-500">Vehicles delivered in the window</p>
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
    {{-- ROW 3 — EXCEPTIONS & PRIORITY ORDERS                           --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

        {{-- Exceptions --}}
        <x-dash.panel title="Exceptions" subtitle="Orders blocking the pipeline" :tight="true">
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

        {{-- Priority orders --}}
        <x-dash.panel
            class="lg:col-span-2"
            title="Orders needing attention"
            subtitle="Longest dwell in current stage · top 12"
            :tight="true">
            <x-slot:actions>
                <a href="{{ route('customer.orders.index') }}" class="text-xs font-semibold text-slate-600 hover:text-slate-900 inline-flex items-center gap-1">
                    View all orders
                    <svg viewBox="0 0 24 24" class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
                </a>
            </x-slot:actions>

            @if($priority->isEmpty())
                <p class="px-5 py-10 text-sm text-slate-400 text-center">No active orders match the current filters</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="bg-slate-50/70 text-[10px] uppercase tracking-[0.15em] text-slate-500">
                                <th class="px-4 py-2 text-left font-semibold">Order #</th>
                                <th class="px-4 py-2 text-left font-semibold">Stage</th>
                                <th class="px-4 py-2 text-left font-semibold">Vehicle</th>
                                <th class="px-4 py-2 text-left font-semibold">Origin → Destination</th>
                                <th class="px-4 py-2 text-right font-semibold">In stage</th>
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
                                    $daysIn = $r->getAttribute('days_in_stage');
                                    $hrsIn  = $r->getAttribute('hours_in_stage');
                                    $dwellLabel = $daysIn >= 1 ? ($daysIn . 'd') : (($hrsIn ?? 0) . 'h');
                                    $chassis = $r->inventory?->chassis_number;
                                    $vin = $r->inventory?->vin;
                                @endphp
                                <tr class="hover:bg-slate-50/60 transition-colors cursor-pointer"
                                    onclick="window.location='{{ route('customer.orders.show', $r) }}'">
                                    <td class="px-4 py-2.5">
                                        <div class="font-mono text-[12px] text-slate-900">{{ $r->job_number ?? ('JOB-' . $r->id) }}</div>
                                        @if($chassis)
                                            <div class="font-mono text-[10px] text-slate-400">{{ $chassis }}{{ $vin && $vin !== $chassis ? ' · ' . $vin : '' }}</div>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5"><x-status-badge :status="$r->status" size="sm"/></td>
                                    <td class="px-4 py-2.5">
                                        <div class="text-[12px] text-slate-700 truncate max-w-[160px]">
                                            {{ $r->brand?->name }} {{ $r->model_name }}
                                        </div>
                                    </td>
                                    <td class="px-4 py-2.5">
                                        <div class="text-[12px] text-slate-700 flex items-center gap-1">
                                            <span>{{ $r->pickupLocation?->city ?? '—' }}</span>
                                            <svg viewBox="0 0 24 24" class="h-3 w-3 text-slate-300" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                                            <span>{{ $r->deliveryLocation?->city ?? '—' }}</span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-2.5 text-right text-[12px] tabular-nums text-slate-700">{{ $dwellLabel }}</td>
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

    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- ROW 4 — CUSTOMER INSIGHT                                       --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

        {{-- Brand mix --}}
        <x-dash.panel title="Vehicles by brand" subtitle="Top 8 active + delivered in range" :tight="true">
            @if($brandRows->isEmpty())
                <p class="px-5 py-10 text-sm text-slate-400 text-center">No orders in scope</p>
            @else
                @php $topActive = $brandRows->max('active_count') ?: 1; @endphp
                <ul class="divide-y divide-slate-100">
                    @foreach($brandRows as $r)
                        <li class="px-5 py-3">
                            <div class="flex items-center justify-between gap-3 mb-1">
                                <span class="text-sm font-medium text-slate-900 truncate">{{ $r->getAttribute('brand_name') }}</span>
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

        {{-- Top destinations --}}
        <x-dash.panel title="Top destinations" subtitle="Deliveries in range · by city" :tight="true">
            @if($destinationRows->isEmpty())
                <p class="px-5 py-10 text-sm text-slate-400 text-center">No deliveries in this range</p>
            @else
                @php $topN = $destinationRows->max('n') ?: 1; @endphp
                <ul class="divide-y divide-slate-100">
                    @foreach($destinationRows as $r)
                        <li class="px-5 py-3">
                            <div class="flex items-center justify-between gap-3 mb-1">
                                <span class="text-sm font-medium text-slate-900 truncate">
                                    {{ $r->city ?: '—' }}<span class="text-slate-400">{{ $r->province ? ', ' . $r->province : '' }}</span>
                                </span>
                                <span class="text-[11px] tabular-nums shrink-0">
                                    <span class="font-semibold text-emerald-700">{{ $num($r->n) }}</span>
                                    <span class="text-slate-400">{{ \Illuminate\Support\Str::plural('delivery', $r->n) }}</span>
                                </span>
                            </div>
                            <div class="h-1.5 bg-slate-100 rounded-full overflow-hidden">
                                <div class="h-full bg-emerald-500 rounded-full" style="width: {{ ($r->n / $topN) * 100 }}%"></div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-dash.panel>

        {{-- Recent deliveries --}}
        <x-dash.panel title="Recent deliveries" subtitle="Latest 8 · POD status" :tight="true">
            @if($recentDeliveries->isEmpty())
                <p class="px-5 py-10 text-sm text-slate-400 text-center">No recent deliveries</p>
            @else
                <ul class="divide-y divide-slate-100">
                    @foreach($recentDeliveries as $d)
                        @php
                            $hasPod = $d->documents->where('category', 'proof_of_delivery')->isNotEmpty();
                            $completedOn = $d->completed_at ?? $d->delivered_at;
                        @endphp
                        <li class="px-5 py-3">
                            <a href="{{ route('customer.orders.show', $d) }}" class="block group">
                                <div class="flex items-center justify-between gap-3">
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-medium text-slate-900 truncate group-hover:text-blue-700">
                                            {{ $d->job_number ?? ('JOB-' . $d->id) }}
                                            <span class="font-normal text-slate-500">· {{ $d->brand?->name }} {{ $d->model_name }}</span>
                                        </p>
                                        <p class="text-[11px] text-slate-500 truncate">
                                            {{ $d->deliveryLocation?->shortDisplay() ?? ($d->deliveryLocation?->city ?? '—') }}
                                            @if($completedOn)
                                                · {{ $completedOn->format('d M Y') }}
                                            @endif
                                        </p>
                                    </div>
                                    @if($hasPod)
                                        <x-dash.pill variant="green">POD</x-dash.pill>
                                    @else
                                        <x-dash.pill variant="slate">Pending</x-dash.pill>
                                    @endif
                                </div>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif

            <x-slot:footer>
                <div class="text-right">
                    <a href="{{ route('customer.documents') }}" class="text-[11px] font-semibold text-slate-600 hover:text-slate-900 inline-flex items-center gap-1">
                        View all documents
                        <svg viewBox="0 0 24 24" class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
                    </a>
                </div>
            </x-slot:footer>
        </x-dash.panel>
    </div>

    <p class="text-center text-[10px] text-slate-400 tracking-[0.2em] uppercase pt-2">
        Trident · {{ $company->name }} · Received → Confirmed → Dispatched → In transit → Delivered
    </p>
</div>
