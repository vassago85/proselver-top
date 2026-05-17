<?php

use App\Models\Brand;
use App\Models\Company;
use App\Models\Inventory;
use App\Models\Invoice;
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
     * ║  OPERATIONS OVERVIEW — EXECUTIVE                                 ║
     * ╠══════════════════════════════════════════════════════════════════╣
     * ║  Focused on the current live operational pipeline only:         ║
     * ║      Received  →  Confirmed  →  Dispatched  →  In transit       ║
     * ║      →  Delivered                                               ║
     * ║                                                                  ║
     * ║  Yard / storage / plant lifecycle surfaces are intentionally     ║
     * ║  absent — not in scope for current operations. If the yard       ║
     * ║  module comes online, add a separate Yard Overview dashboard    ║
     * ║  using the same component system, do not bloat this page.       ║
     * ║                                                                  ║
     * ║  Every metric is backed by real rows in `transport_jobs`,        ║
     * ║  `invoices`, `locations`, `companies`. No mock figures.          ║
     * ╚══════════════════════════════════════════════════════════════════╝
     */

    // ─── Filter state (URL-persistent) ──────────────────────────────
    #[Url] public ?string $dateFrom = null;
    #[Url] public ?string $dateTo = null;
    #[Url] public ?int $companyId = null;      // booking customer / OEM
    #[Url] public ?int $transporterId = null;  // executing company
    #[Url] public ?int $brandId = null;
    #[Url] public ?string $region = null;      // pickup or delivery province
    #[Url] public ?string $status = null;      // phase 1 job status

    // ─── Phase 1 logical groupings ──────────────────────────────────
    // These are the ONLY statuses this dashboard understands. Legacy
    // statuses (assigned, in_progress, completed, invoiced, etc.) are
    // mapped onto the pipeline where they overlap, otherwise ignored.
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
        // Everything not yet delivered / completed / cancelled.
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
        if (!$this->dateFrom) { $this->dateFrom = now()->subDays(29)->toDateString(); }
        if (!$this->dateTo)   { $this->dateTo   = now()->toDateString(); }
    }

    public function resetFilters(): void
    {
        $this->reset(['companyId', 'transporterId', 'brandId', 'region', 'status']);
        $this->dateFrom = now()->subDays(29)->toDateString();
        $this->dateTo   = now()->toDateString();
    }

    // ───────────────────────────────────────────────────────────────────
    //  Jobs query scoped by the active filters (except status — we apply
    //  per-metric). Status filter only pre-narrows the Priority list.
    // ───────────────────────────────────────────────────────────────────
    protected function baseJobsQuery(bool $applyStatusFilter = false)
    {
        $q = Job::query();

        if ($this->companyId)     { $q->where('company_id', $this->companyId); }
        if ($this->transporterId) { $q->where('executing_company_id', $this->transporterId); }
        if ($this->brandId)       { $q->where('brand_id', $this->brandId); }
        if ($this->region) {
            $q->where(function ($w) {
                $w->whereHas('pickupLocation',    fn ($l) => $l->where('province', $this->region))
                  ->orWhereHas('deliveryLocation', fn ($l) => $l->where('province', $this->region));
            });
        }
        if ($applyStatusFilter && $this->status) {
            $q->where('status', $this->status);
        }
        return $q;
    }

    // ───────────────────────────────────────────────────────────────────
    //  Thresholds (config, tunable via SystemSetting)
    // ───────────────────────────────────────────────────────────────────
    protected function thresholds(): array
    {
        return [
            // How long an intake job can sit awaiting the customer before
            // ops needs to chase it.
            'awaiting_confirm_days' => (int) SystemSetting::get('ops.alert.awaiting_confirm_days', 2),
            // Confirmed jobs that still have no driver assigned.
            'to_dispatch_hours'     => (int) SystemSetting::get('ops.alert.to_dispatch_hours', 24),
            // Driver assigned but vehicle not yet collected.
            'dispatched_days'       => (int) SystemSetting::get('ops.alert.dispatched_days', 2),
            // In transit too long.
            'in_transit_days'       => (int) SystemSetting::get('ops.alert.in_transit_days', 3),
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
        //  ROW 1 — KPI cards, one per pipeline stage + risk.
        //  All driven by transport_jobs, not inventory.
        // ─────────────────────────────────────────────────────────────
        $intake = (clone $this->baseJobsQuery())->whereIn('status', self::G_INTAKE)->count();
        $toDispatch = (clone $this->baseJobsQuery())->whereIn('status', self::G_TO_DISPATCH)->count();
        $dispatched = (clone $this->baseJobsQuery())->whereIn('status', self::G_DISPATCHED)->count();
        $onRoad = (clone $this->baseJobsQuery())->whereIn('status', self::G_IN_TRANSIT)->count();

        $deliveredRange = (clone $this->baseJobsQuery())
            ->whereNotNull('delivered_at')
            ->whereBetween('delivered_at', [$from, $to])
            ->count();
        $deliveredPrev = (clone $this->baseJobsQuery())
            ->whereNotNull('delivered_at')
            ->whereBetween('delivered_at', [$prevFrom, $prevTo])
            ->count();

        // "At risk" — anything lingering past its stage threshold.
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

        // Prev-period baselines (using updated_at as an in-status proxy).
        $prevIntake    = (clone $this->baseJobsQuery())->whereIn('status', self::G_INTAKE)->whereBetween('updated_at', [$prevFrom, $prevTo])->count();
        $prevDispatch  = (clone $this->baseJobsQuery())->whereIn('status', self::G_TO_DISPATCH)->whereBetween('updated_at', [$prevFrom, $prevTo])->count();
        $prevDispatchd = (clone $this->baseJobsQuery())->whereIn('status', self::G_DISPATCHED)->whereBetween('updated_at', [$prevFrom, $prevTo])->count();
        $prevOnRoad    = (clone $this->baseJobsQuery())->whereIn('status', self::G_IN_TRANSIT)->whereBetween('updated_at', [$prevFrom, $prevTo])->count();

        $kpis = [
            [
                'key'       => 'intake',
                'label'     => 'New orders',
                'value'     => $intake,
                'color'     => 'amber',
                'href'      => route('admin.orders.index', ['phase' => 'intake']),
                'trend'     => $this->trend($intake, $prevIntake),
                'helper'    => 'Received · awaiting review / confirmation',
                'iconPath'  => '<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.29 1.51 4.04 3 5.5l7 7Z"/>',
            ],
            [
                'key'       => 'to_dispatch',
                'label'     => 'Ready to dispatch',
                'value'     => $toDispatch,
                'color'     => 'indigo',
                'href'      => route('admin.planning'),
                'trend'     => $this->trend($toDispatch, $prevDispatch),
                'helper'    => 'Confirmed · no driver yet',
                'iconPath'  => '<path d="M8 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-3"/><path d="M16 3h5v5"/><path d="M21 3 9 15"/>',
            ],
            [
                'key'       => 'dispatched',
                'label'     => 'Dispatched',
                'value'     => $dispatched,
                'color'     => 'purple',
                'href'      => route('admin.planning'),
                'trend'     => $this->trend($dispatched, $prevDispatchd),
                'helper'    => 'Driver assigned · vehicle not yet collected',
                'iconPath'  => '<circle cx="12" cy="8" r="5"/><path d="M20 21a8 8 0 0 0-16 0"/>',
            ],
            [
                'key'       => 'on_road',
                'label'     => 'On the road',
                'value'     => $onRoad,
                'color'     => 'blue',
                'href'      => route('admin.vehicles.index', ['bucket' => 'live']),
                'trend'     => $this->trend($onRoad, $prevOnRoad),
                'helper'    => 'Collected · in transit',
                'iconPath'  => '<path d="M14 16H9m10 0h3v-3.15a1 1 0 0 0-.84-.99L16 11l-2.7-3.6a1 1 0 0 0-.8-.4H5.24a2 2 0 0 0-1.8 1.1l-.8 1.63A6 6 0 0 0 2 12.42V16h2"/><circle cx="6.5" cy="16.5" r="2.5"/><circle cx="16.5" cy="16.5" r="2.5"/>',
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
                'label'     => 'At risk / delayed',
                'value'     => $atRisk,
                'color'     => $atRisk > 0 ? 'red' : 'slate',
                'href'      => null,
                'trend'     => null,
                'helper'    => "Awaiting confirm >{$th['awaiting_confirm_days']}d · To dispatch >{$th['to_dispatch_hours']}h · Transit >{$th['in_transit_days']}d",
                'iconPath'  => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
            ],
        ];

        // ─────────────────────────────────────────────────────────────
        //  ROW 2 — Pipeline activity & distribution
        // ─────────────────────────────────────────────────────────────

        // Daily activity series — 3 stage-transition timestamps.
        $days = [];
        $cursor = $from->copy();
        while ($cursor->lte($to)) {
            $days[$cursor->toDateString()] = [
                'date'       => $cursor->copy(),
                'dispatched' => 0, // assigned_at — driver set
                'in_transit' => 0, // in_transit_at — rolled out
                'delivered'  => 0, // delivered_at — completed at destination
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
                    if (isset($days[$k])) {
                        $days[$k][$bucket]++;
                    }
                }
            }
        }
        $activitySeries = array_values($days);
        $activityPeak   = max(array_merge([1], array_map(fn ($d) => max($d['dispatched'], $d['in_transit'], $d['delivered']), $activitySeries)));

        // Pipeline distribution — active jobs grouped by their stage.
        // Gives ops an at-a-glance read on where the bottleneck is.
        $stageGroups = [
            'intake'      => ['label' => 'Intake',           'statuses' => self::G_INTAKE,      'hex' => '#f59e0b'],
            'to_dispatch' => ['label' => 'Ready to dispatch','statuses' => self::G_TO_DISPATCH, 'hex' => '#6366f1'],
            'dispatched'  => ['label' => 'Dispatched',       'statuses' => self::G_DISPATCHED,  'hex' => '#a855f7'],
            'in_transit'  => ['label' => 'On the road',      'statuses' => self::G_IN_TRANSIT,  'hex' => '#3b82f6'],
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

        // Throughput vs scheduled (job-level).
        $scheduled = (clone $this->baseJobsQuery())
            ->whereBetween('scheduled_date', [$from->toDateString(), $to->toDateString()])
            ->count();
        $delivered = (clone $this->baseJobsQuery())
            ->whereNotNull('delivered_at')
            ->whereBetween('delivered_at', [$from, $to])
            ->count();
        $throughputPct = $scheduled > 0 ? (int) round(($delivered / $scheduled) * 100) : 0;

        // ─────────────────────────────────────────────────────────────
        //  ROW 3 — Exceptions & priority
        // ─────────────────────────────────────────────────────────────
        $exceptions = [
            [
                'key'      => 'awaiting_confirm',
                'label'    => 'Awaiting customer confirmation',
                'sublabel' => "> {$th['awaiting_confirm_days']}d",
                'count'    => (clone $this->baseJobsQuery())
                    ->whereIn('status', [Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION])
                    ->where('updated_at', '<=', now()->subDays($th['awaiting_confirm_days']))
                    ->count(),
                'severity' => 'amber',
            ],
            [
                'key'      => 'confirmation_issue',
                'label'    => 'Confirmation issue unresolved',
                'sublabel' => 'customer raised a problem',
                'count'    => (clone $this->baseJobsQuery())
                    ->where('status', Job::STATUS_CONFIRMATION_ISSUE)
                    ->count(),
                'severity' => 'red',
            ],
            [
                'key'      => 'no_driver',
                'label'    => 'Confirmed · no driver',
                'sublabel' => "> {$th['to_dispatch_hours']}h",
                'count'    => (clone $this->baseJobsQuery())
                    ->where('status', Job::STATUS_CONFIRMED)
                    ->where('updated_at', '<=', now()->subHours($th['to_dispatch_hours']))
                    ->count(),
                'severity' => 'amber',
            ],
            [
                'key'      => 'dispatched_stale',
                'label'    => 'Dispatched · not collected',
                'sublabel' => "> {$th['dispatched_days']}d",
                'count'    => (clone $this->baseJobsQuery())
                    ->whereIn('status', self::G_DISPATCHED)
                    ->where('updated_at', '<=', now()->subDays($th['dispatched_days']))
                    ->count(),
                'severity' => 'amber',
            ],
            [
                'key'      => 'long_transit',
                'label'    => 'Long in transit',
                'sublabel' => "> {$th['in_transit_days']}d",
                'count'    => (clone $this->baseJobsQuery())
                    ->whereIn('status', self::G_IN_TRANSIT)
                    ->where('updated_at', '<=', now()->subDays($th['in_transit_days']))
                    ->count(),
                'severity' => 'red',
            ],
            [
                'key'      => 'delivered_open',
                'label'    => 'Delivered but not completed',
                'sublabel' => 'paperwork / POD pending',
                'count'    => (clone $this->baseJobsQuery())
                    ->where('status', Job::STATUS_DELIVERED)
                    ->whereNull('completed_at')
                    ->count(),
                'severity' => 'amber',
            ],
        ];

        // Priority movements — active Phase 1 jobs ordered by longest
        // dwell in their current stage. Table shows VIN from inventory,
        // origin → destination, driver, days in stage, risk.
        $priority = (clone $this->baseJobsQuery(applyStatusFilter: true))
            ->whereIn('status', self::ACTIVE_PHASE1)
            ->with([
                'pickupLocation:id,city,province',
                'deliveryLocation:id,city,province',
                'driver:id,name',
                'inventory:id,chassis_number,vin',
                'company:id,name',
            ])
            ->orderBy('updated_at', 'asc')
            ->limit(12)
            ->get();

        foreach ($priority as $row) {
            $days = $row->updated_at ? (int) $row->updated_at->diffInDays(now()) : null;
            $hrs  = $row->updated_at ? (int) $row->updated_at->diffInHours(now()) : null;
            $row->setAttribute('days_in_stage', $days);
            $row->setAttribute('hours_in_stage', $hrs);
            $row->setAttribute('risk_level', $this->riskFor($row->status, $days, $hrs, $th));
        }

        // ─────────────────────────────────────────────────────────────
        //  ROW 4 — Executive insight
        // ─────────────────────────────────────────────────────────────

        // Customer / OEM breakdown — active jobs + delivered-in-range.
        $companyRows = (clone $this->baseJobsQuery())
            ->selectRaw('company_id, '
                . 'count(*) filter (where status in (' . $this->pgStatusList(self::ACTIVE_PHASE1) . ')) as active_count, '
                . 'count(*) filter (where delivered_at between ? and ?) as delivered_count', [$from, $to])
            ->whereNotNull('company_id')
            ->groupBy('company_id')
            ->orderByDesc('active_count')
            ->limit(8)
            ->get();

        $companyIds = $companyRows->pluck('company_id')->all();
        $companies  = !empty($companyIds)
            ? Company::whereIn('id', $companyIds)->get(['id', 'name', 'type'])->keyBy('id')
            : collect();
        $companyRows->each(fn ($r) => $r->setAttribute('company', $companies->get($r->company_id)));

        // Transporter performance — completed jobs per transporter,
        // on-time = delivered on/before scheduled_date with no delay.
        $transporterRows = (clone $this->baseJobsQuery())
            ->whereNotNull('delivered_at')
            ->whereBetween('delivered_at', [$from, $to])
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

        // Invoice snapshot — what's invoiced, paid, outstanding.
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
        // Status filter — only Phase 1 statuses that are still in play.
        $statusOptions = self::ACTIVE_PHASE1;

        return compact(
            'from', 'to', 'span', 'kpis',
            'activitySeries', 'activityPeak',
            'stageGroups', 'pipelineTotal',
            'scheduled', 'delivered', 'throughputPct',
            'exceptions', 'priority',
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

    protected function riskFor(string $status, ?int $days, ?int $hours, array $th): string
    {
        if ($days === null) { return 'low'; }

        // Intake
        if (in_array($status, [Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION, Job::STATUS_CONFIRMATION_ISSUE], true)) {
            $hi = $th['awaiting_confirm_days'];
            return $days >= $hi ? 'high' : ($days >= max(1, (int) round($hi / 2)) ? 'med' : 'low');
        }
        if (in_array($status, [Job::STATUS_PENDING_VERIFICATION, Job::STATUS_RECEIVED], true)) {
            return $days >= 1 ? 'med' : 'low';
        }

        // Ready to dispatch — measured in hours.
        if ($status === Job::STATUS_CONFIRMED) {
            $hi = $th['to_dispatch_hours'];
            $h = $hours ?? 0;
            return $h >= $hi ? 'high' : ($h >= (int) round($hi / 2) ? 'med' : 'low');
        }

        // Dispatched — driver set, not yet collected.
        if (in_array($status, self::G_DISPATCHED, true)) {
            $hi = $th['dispatched_days'];
            return $days >= $hi ? 'high' : ($days >= max(1, (int) round($hi / 2)) ? 'med' : 'low');
        }

        // On the road.
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
    $money = fn ($v) => 'R ' . number_format((float) $v, 0);
    $num   = fn ($v) => number_format((int) $v);
@endphp

<div class="space-y-6">

    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- HEADER                                                         --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    <x-page-header
        eyebrow="Command centre"
        title="Operations Overview"
        subtitle="From order received to vehicle delivered — every job currently in flight.">
        <x-slot:actions>
            <x-button variant="secondary" size="sm" :href="route('admin.planning')">
                <x-slot:icon>
                    <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                </x-slot:icon>
                Dispatch queue
            </x-button>
            <x-button variant="secondary" size="sm" :href="route('admin.deliveries')">
                <x-slot:icon>
                    <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                </x-slot:icon>
                Deliveries
            </x-button>
            <x-button variant="primary" size="sm" :href="route('admin.reports.index')">
                <x-slot:icon>
                    <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m7 14 4-4 4 4 5-6"/></svg>
                </x-slot:icon>
                Reports
            </x-button>
        </x-slot:actions>
    </x-page-header>

    {{-- Filter strip --}}
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
        <x-dash.filter-select label="Stage" wire:model.live="status" minWidth="180px">
            <option value="">Any</option>
            @foreach($statusOptions as $s)
                <option value="{{ $s }}">{{ \App\Models\Job::PHASE1_STATUS_LABELS[$s] ?? ucwords(str_replace('_', ' ', $s)) }}</option>
            @endforeach
        </x-dash.filter-select>
        <x-dash.filter-reset wire:click="resetFilters" />
    </x-dash.filter-bar>

    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- ROW 1 — KPI CARDS (one per pipeline stage + risk)              --}}
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
    {{-- ROW 2 — FLOW & PIPELINE                                        --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

        {{-- Pipeline activity chart --}}
        <x-dash.panel
            class="lg:col-span-2"
            title="Pipeline activity"
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
                    No pipeline activity in this range
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
        <x-dash.panel title="Pipeline distribution" subtitle="Active jobs by stage">
            @if($pipelineTotal === 0)
                <p class="text-sm text-slate-400 text-center py-8">No active jobs</p>
            @else
                <ul class="space-y-3">
                    @foreach($stageGroups as $grp)
                        @php
                            $pct = $pipelineTotal > 0 ? ($grp['count'] / $pipelineTotal) * 100 : 0;
                        @endphp
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
        subtitle="Deliveries completed vs bookings scheduled for the same window">
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
                <p class="mt-1 text-xs text-slate-500">Jobs with a delivered_at in the window</p>
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
    {{-- ROW 3 — EXCEPTIONS & PRIORITY                                  --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

        {{-- Exceptions --}}
        <x-dash.panel title="Exceptions" subtitle="Jobs blocking the pipeline" :tight="true">
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

        {{-- Priority movements --}}
        <x-dash.panel
            class="lg:col-span-2"
            title="Priority movements"
            subtitle="Longest dwell in current stage · top 12"
            :tight="true">
            <x-slot:actions>
                <a href="{{ route('admin.orders.index') }}" class="text-xs font-semibold text-slate-600 hover:text-slate-900 inline-flex items-center gap-1">
                    View all orders
                    <svg viewBox="0 0 24 24" class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
                </a>
            </x-slot:actions>

            @if($priority->isEmpty())
                <p class="px-5 py-10 text-sm text-slate-400 text-center">No active jobs match the current filters</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="bg-slate-50/70 text-[10px] uppercase tracking-[0.15em] text-slate-500">
                                <th class="px-4 py-2 text-left font-semibold">Reference</th>
                                <th class="px-4 py-2 text-left font-semibold">Stage</th>
                                <th class="px-4 py-2 text-left font-semibold">Customer</th>
                                <th class="px-4 py-2 text-left font-semibold">Origin → Destination</th>
                                <th class="px-4 py-2 text-left font-semibold">Driver</th>
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
                                    $days = $r->getAttribute('days_in_stage');
                                    $hrs  = $r->getAttribute('hours_in_stage');
                                    $dwellLabel = $days >= 1 ? ($days . 'd') : (($hrs ?? 0) . 'h');
                                    $chassis = $r->inventory?->chassis_number;
                                    $vin = $r->inventory?->vin;
                                @endphp
                                <tr class="hover:bg-slate-50/60 transition-colors">
                                    <td class="px-4 py-2.5">
                                        <div class="font-mono text-[12px] text-slate-900">{{ $r->job_number ?? ('JOB-' . $r->id) }}</div>
                                        @if($chassis)
                                            <div class="font-mono text-[10px] text-slate-400">{{ $chassis }}{{ $vin && $vin !== $chassis ? ' · ' . $vin : '' }}</div>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5"><x-status-badge :status="$r->status" size="sm"/></td>
                                    <td class="px-4 py-2.5">
                                        <div class="text-[12px] text-slate-700 truncate max-w-[160px]">{{ $r->company?->name ?? '—' }}</div>
                                    </td>
                                    <td class="px-4 py-2.5">
                                        <div class="flex items-center gap-2">
                                            <div class="min-w-0">
                                                <div class="text-[12px] text-slate-700 truncate">{{ $r->pickupLocation?->city ?? '—' }}</div>
                                                @if($r->pickupLocation?->province)
                                                    <div class="text-[10px] text-slate-400 truncate">{{ $r->pickupLocation->province }}</div>
                                                @endif
                                            </div>
                                            <svg viewBox="0 0 24 24" class="h-3 w-3 shrink-0 text-slate-300" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                                            <div class="min-w-0">
                                                <div class="text-[12px] text-slate-700 truncate">{{ $r->deliveryLocation?->city ?? '—' }}</div>
                                                @if($r->deliveryLocation?->province)
                                                    <div class="text-[10px] text-slate-400 truncate">{{ $r->deliveryLocation->province }}</div>
                                                @endif
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-2.5 text-[12px] text-slate-700">{{ $r->driver?->name ?? '—' }}</td>
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
    {{-- ROW 4 — EXECUTIVE INSIGHT                                      --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

        {{-- Customer / OEM breakdown --}}
        <x-dash.panel title="Customer / OEM breakdown" subtitle="Top 8 by active jobs" :tight="true">
            @if($companyRows->isEmpty())
                <p class="px-5 py-10 text-sm text-slate-400 text-center">No jobs in scope</p>
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

        {{-- Transporter performance --}}
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

        {{-- Invoice snapshot --}}
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
        Trident · Operations Overview · Received → Confirmed → Dispatched → In transit → Delivered
    </p>
</div>
