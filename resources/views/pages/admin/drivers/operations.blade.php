<?php

use App\Models\Company;
use App\Models\DriverProfile;
use App\Models\Job;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    /**
     * ╔═══════════════════════════════════════════════════════════════╗
     * ║  DRIVER OPERATIONS — fleet control dashboard                  ║
     * ╠═══════════════════════════════════════════════════════════════╣
     * ║  Answers: who's moving, who's idle, who's late, who is        ║
     * ║  overloaded, whose docs expire soon. NOT an HR surface        ║
     * ║  (that lives on /admin/drivers).                              ║
     * ║                                                                ║
     * ║  Reuses models only — no new tables, no migrations.           ║
     * ║  Backed entirely by User · DriverProfile · Job relationships. ║
     * ╚═══════════════════════════════════════════════════════════════╝
     */

    #[Url] public ?string $dateFrom = null;
    #[Url] public ?string $dateTo = null;
    #[Url] public ?int $companyId = null;       // transporter filter
    #[Url] public string $activeFilter = 'active'; // active | inactive | all
    #[Url] public ?string $baseLocation = null;

    /**
     * "Overloaded" = driver with more open (non-terminal) assignments than
     * this many. Tunable per-org via SystemSetting without touching code.
     */
    public int $overloadThreshold = 3;
    public int $expirySoonDays    = 30;

    public function mount(): void
    {
        if (!$this->dateFrom) {
            $this->dateFrom = now()->subDays(29)->toDateString();
        }
        if (!$this->dateTo) {
            $this->dateTo = now()->toDateString();
        }
        $this->overloadThreshold = (int) SystemSetting::get('drivers.overload_threshold', 3);
        $this->expirySoonDays    = (int) SystemSetting::get('drivers.expiry_soon_days', 30);
    }

    public function resetFilters(): void
    {
        $this->companyId    = null;
        $this->baseLocation = null;
        $this->activeFilter = 'active';
        $this->dateFrom     = now()->subDays(29)->toDateString();
        $this->dateTo       = now()->toDateString();
    }

    // ───── Base driver query, honours filters ────────────────────────
    protected function baseDriverQuery()
    {
        $q = User::query()
            ->whereHas('roles', fn ($r) => $r->where('slug', 'driver'))
            // Restrict to drivers attached to the platform-owner company
            // (ProSelver). Dealer-only drivers belong to a dealer's own
            // pool, get assigned through the customer portal, and have no
            // bearing on ProSelver fleet operations -- including them here
            // would make every dealer's drivers show up on ops's "who's
            // idle / who's overloaded" board, drowning the real signal.
            // Same scope as User::scopePlatformDrivers().
            ->whereHas('companies', fn ($c) => $c->where('is_platform_owner', true))
            ->with('driverProfile:id,user_id,base_location,license_expiry,prdp_expiry,trade_plate_expiry');

        if ($this->activeFilter === 'active')   { $q->where('is_active', true); }
        if ($this->activeFilter === 'inactive') { $q->where('is_active', false); }

        if ($this->companyId) {
            $q->whereHas('companies', fn ($c) => $c->where('companies.id', $this->companyId));
        }
        if ($this->baseLocation) {
            $q->whereHas('driverProfile', fn ($p) => $p->where('base_location', $this->baseLocation));
        }

        return $q;
    }

    // ───── Live-state helpers ────────────────────────────────────────
    protected function inFlightStatuses(): array
    {
        return [
            Job::STATUS_DRIVER_ASSIGNED,
            Job::STATUS_READY_FOR_COLLECTION,
            Job::STATUS_COLLECTED,
            Job::STATUS_IN_TRANSIT,
        ];
    }

    protected function openStatuses(): array
    {
        // Includes DELIVERED because the vehicle is physically back but
        // the POD / invoicing may still be pending — for driver-load
        // purposes treat it as "off the truck".
        return [
            Job::STATUS_DRIVER_ASSIGNED,
            Job::STATUS_READY_FOR_COLLECTION,
            Job::STATUS_COLLECTED,
            Job::STATUS_IN_TRANSIT,
        ];
    }

    public function with(): array
    {
        $from = Carbon::parse($this->dateFrom)->startOfDay();
        $to   = Carbon::parse($this->dateTo)->endOfDay();
        $inFlight = $this->inFlightStatuses();
        $open     = $this->openStatuses();

        // ─────────────────────────────────────────────────────────────
        // Eager-load every driver we'll need with pre-computed counts.
        // Two round-trips:  1) drivers, 2) one per-driver aggregate.
        // ─────────────────────────────────────────────────────────────
        $drivers = (clone $this->baseDriverQuery())->get(['id', 'name', 'is_active']);

        $driverIds = $drivers->pluck('id')->all();

        // Jobs touched in range (assigned or completed), per driver.
        $perDriverWindow = empty($driverIds) ? collect() : Job::query()
            ->whereIn('driver_user_id', $driverIds)
            ->where(function ($w) use ($from, $to) {
                $w->whereBetween('assigned_at', [$from, $to])
                  ->orWhereBetween('completed_at', [$from, $to])
                  ->orWhereBetween('delivered_at', [$from, $to]);
            })
            ->selectRaw('
                driver_user_id,
                count(*) as touched,
                count(*) filter (where completed_at is not null and completed_at between ? and ?) as completed,
                count(*) filter (where delivered_at is not null and delivered_at between ? and ?) as delivered,
                count(*) filter (where coalesce(delay_minutes,0) > 0 and completed_at between ? and ?) as delays,
                count(*) filter (where coalesce(delay_minutes,0) = 0 and delivered_at is not null and delivered_at::date <= scheduled_date and delivered_at between ? and ?) as on_time,
                avg(extract(epoch from (delivered_at - coalesce(in_transit_at, assigned_at)))) filter (where delivered_at is not null and delivered_at between ? and ?) as avg_delivery_seconds
            ', [
                $from, $to, $from, $to, $from, $to, $from, $to, $from, $to,
            ])
            ->groupBy('driver_user_id')
            ->get()
            ->keyBy('driver_user_id');

        // Open / in-flight jobs per driver — single row per driver, most
        // recent open job attached for "current job" column.
        $openJobs = empty($driverIds) ? collect() : Job::query()
            ->whereIn('driver_user_id', $driverIds)
            ->whereIn('status', $open)
            ->with([
                'pickupLocation:id,city',
                'deliveryLocation:id,city',
                'brand:id,name',
            ])
            ->orderByDesc('updated_at')
            ->get();

        $openCounts = $openJobs->groupBy('driver_user_id')->map->count();
        $currentJob = $openJobs->groupBy('driver_user_id')->map->first();

        // Last job activity touched ─ for "idle since" reporting. We use
        // updated_at on the latest job as the proxy.
        $lastActivity = empty($driverIds) ? collect() : Job::query()
            ->whereIn('driver_user_id', $driverIds)
            ->selectRaw('driver_user_id, max(updated_at) as last_activity')
            ->groupBy('driver_user_id')
            ->pluck('last_activity', 'driver_user_id');

        // ─────────────────────────────────────────────────────────────
        // Enrich each driver row with derived fields for the view.
        // ─────────────────────────────────────────────────────────────
        $drivers->each(function ($d) use ($perDriverWindow, $openCounts, $currentJob, $lastActivity, $inFlight) {
            $win = $perDriverWindow->get($d->id);
            $openN = (int) ($openCounts[$d->id] ?? 0);
            $cur = $currentJob->get($d->id);

            $d->setAttribute('open_count', $openN);
            $d->setAttribute('current_job', $cur);
            $d->setAttribute('touched',       (int) ($win->touched ?? 0));
            $d->setAttribute('completed',     (int) ($win->completed ?? 0));
            $d->setAttribute('delivered',     (int) ($win->delivered ?? 0));
            $d->setAttribute('delays',        (int) ($win->delays ?? 0));
            $d->setAttribute('on_time',       (int) ($win->on_time ?? 0));
            $d->setAttribute('avg_seconds',   $win?->avg_delivery_seconds !== null ? (float) $win->avg_delivery_seconds : null);
            // selectRaw('max(...) as last_activity') returns a raw string
            // from Postgres (it bypasses Eloquent casts). Parse explicitly
            // so the view can call ->diffForHumans() on a Carbon instance.
            $raw = $lastActivity[$d->id] ?? null;
            $d->setAttribute('last_activity', $raw ? Carbon::parse($raw) : null);

            // Live status bucket
            if (!$cur) {
                $d->setAttribute('live_status', 'idle');
            } elseif (in_array($cur->status, [Job::STATUS_IN_TRANSIT, Job::STATUS_COLLECTED], true)) {
                $d->setAttribute('live_status', 'in_transit');
            } else {
                $d->setAttribute('live_status', 'assigned');
            }

            $base = in_array($d->completed, [0, null], true) ? 0 : $d->completed;
            $d->setAttribute('on_time_pct', $base > 0 ? (int) round(($d->on_time / $base) * 100) : null);
        });

        // Sort for the Active Drivers table — busy drivers first.
        $driversSorted = $drivers->sortBy([
            ['live_status', 'asc'],   // assigned/in_transit before idle
            ['open_count', 'desc'],
        ])->values();

        // ─────────────────────────────────────────────────────────────
        // ROW 1 — KPIs
        // ─────────────────────────────────────────────────────────────
        $totalActive   = (clone $this->baseDriverQuery())->where('is_active', true)->count();
        $assignedToday = Job::query()
            ->whereBetween('assigned_at', [$from, $to])
            ->whereNotNull('driver_user_id')
            ->when($this->companyId, fn ($q) => $q->where('executing_company_id', $this->companyId))
            ->distinct('driver_user_id')
            ->count('driver_user_id');

        $unassignedJobs = Job::query()
            ->whereIn('status', [Job::STATUS_CONFIRMED, Job::STATUS_PLANNED])
            ->whereNull('driver_user_id')
            ->when($this->companyId, fn ($q) => $q->where('executing_company_id', $this->companyId))
            ->count();

        $withActiveJob = $drivers->filter(fn ($d) => $d->open_count > 0)->count();
        $utilisation   = $totalActive > 0 ? (int) round(($withActiveJob / $totalActive) * 100) : 0;

        $soonDate = now()->addDays($this->expirySoonDays)->toDateString();
        $expiringDocs = DriverProfile::query()
            ->whereHas('user', fn ($u) => $u->where('is_active', true))
            ->where(function ($w) use ($soonDate) {
                $w->whereBetween('license_expiry', [now()->toDateString(), $soonDate])
                  ->orWhereBetween('prdp_expiry', [now()->toDateString(), $soonDate])
                  ->orWhereBetween('trade_plate_expiry', [now()->toDateString(), $soonDate]);
            })
            ->count();

        $kpis = [
            [
                'key'   => 'active',
                'label' => 'Active drivers',
                'value' => $totalActive,
                'color' => 'blue',
                'icon'  => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
                'helper'=> 'Driver role, is_active = true',
            ],
            [
                'key'   => 'assigned',
                'label' => 'Distinct drivers assigned',
                'value' => $assignedToday,
                'color' => 'teal',
                'icon'  => '<path d="M9 12l2 2 4-4"/><path d="M21 12c0 4.97-4.03 9-9 9s-9-4.03-9-9 4.03-9 9-9 9 4.03 9 9z"/>',
                'helper'=> $from->format('d M') . ' – ' . $to->format('d M'),
            ],
            [
                'key'   => 'unassigned',
                'label' => 'Unassigned jobs',
                'value' => $unassignedJobs,
                'color' => $unassignedJobs > 0 ? 'amber' : 'slate',
                'icon'  => '<circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/>',
                'helper'=> 'Planned / confirmed, no driver',
            ],
            [
                'key'   => 'utilisation',
                'label' => 'Utilisation',
                'value' => $utilisation . '%',
                'color' => $utilisation >= 70 ? 'green' : ($utilisation >= 40 ? 'amber' : 'red'),
                'icon'  => '<path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/>',
                'helper'=> "{$withActiveJob} of {$totalActive} with an open job",
            ],
            [
                'key'   => 'expiring',
                'label' => 'Expiring docs',
                'value' => $expiringDocs,
                'color' => $expiringDocs > 0 ? 'red' : 'slate',
                'icon'  => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
                'helper'=> "License / PDP / trade plate within {$this->expirySoonDays}d",
            ],
        ];

        // ─────────────────────────────────────────────────────────────
        // ROW 2 — Activity (table + load chart)
        // ─────────────────────────────────────────────────────────────
        $topLoad = $drivers
            ->filter(fn ($d) => $d->open_count > 0)
            ->sortByDesc('open_count')
            ->take(10)
            ->values();

        $maxLoad = $topLoad->max('open_count') ?: 1;

        // ─────────────────────────────────────────────────────────────
        // ROW 3 — Performance
        // ─────────────────────────────────────────────────────────────
        $performers = $drivers
            ->filter(fn ($d) => $d->completed > 0 || $d->delivered > 0 || $d->delays > 0)
            ->sortByDesc('completed')
            ->take(15)
            ->values();

        // ─────────────────────────────────────────────────────────────
        // ROW 4 — Problems / risks
        // ─────────────────────────────────────────────────────────────
        $issues = [
            'no_assignments' => $drivers->filter(fn ($d) => $d->open_count === 0 && $d->touched === 0)->values(),
            'overloaded'     => $drivers->filter(fn ($d) => $d->open_count > $this->overloadThreshold)->sortByDesc('open_count')->values(),
            'with_delays'    => $drivers->filter(fn ($d) => $d->delays > 0)->sortByDesc('delays')->values(),
        ];

        // Compliance — current list of expired + expiring soon, with the
        // user attached for name + activity. Only for drivers that pass
        // the current filters.
        $complianceDrivers = DriverProfile::query()
            ->whereHas('user', function ($u) {
                $u->whereHas('roles', fn ($r) => $r->where('slug', 'driver'))
                  // Match baseDriverQuery -- platform-owner drivers only.
                  ->whereHas('companies', fn ($c) => $c->where('is_platform_owner', true));
                if ($this->activeFilter === 'active')   { $u->where('is_active', true); }
                if ($this->activeFilter === 'inactive') { $u->where('is_active', false); }
                if ($this->companyId) {
                    $u->whereHas('companies', fn ($c) => $c->where('companies.id', $this->companyId));
                }
            })
            ->when($this->baseLocation, fn ($q) => $q->where('base_location', $this->baseLocation))
            ->with('user:id,name,is_active')
            ->where(function ($w) use ($soonDate) {
                // Expired OR expiring-soon on any of the three dates
                $today = now()->toDateString();
                $w->where(function ($q) use ($today, $soonDate) {
                    $q->where('license_expiry', '<', $today)
                      ->orWhereBetween('license_expiry', [$today, $soonDate]);
                })->orWhere(function ($q) use ($today, $soonDate) {
                    $q->where('prdp_expiry', '<', $today)
                      ->orWhereBetween('prdp_expiry', [$today, $soonDate]);
                })->orWhere(function ($q) use ($today, $soonDate) {
                    $q->where('trade_plate_expiry', '<', $today)
                      ->orWhereBetween('trade_plate_expiry', [$today, $soonDate]);
                });
            })
            ->orderByRaw('least(coalesce(license_expiry, \'9999-12-31\'), coalesce(prdp_expiry, \'9999-12-31\'), coalesce(trade_plate_expiry, \'9999-12-31\')) asc')
            ->limit(20)
            ->get();

        // Filter option lists
        $companyOptions = Company::where('type', Company::TYPE_TRANSPORTER)
            ->where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $baseOptions = DriverProfile::query()
            ->whereNotNull('base_location')
            ->where('base_location', '!=', '')
            ->distinct()->orderBy('base_location')->pluck('base_location');

        return compact(
            'from', 'to',
            'kpis',
            'driversSorted', 'topLoad', 'maxLoad',
            'performers',
            'issues', 'complianceDrivers',
            'companyOptions', 'baseOptions',
        );
    }
};
?>

@php
    $num = fn ($v) => number_format((int) $v);

    /**
     * "2h 15m" style duration from a float seconds. Null-safe.
     */
    $human = function (?float $seconds) {
        if ($seconds === null || $seconds <= 0) { return '—'; }
        $h = (int) floor($seconds / 3600);
        $m = (int) floor(($seconds % 3600) / 60);
        if ($h >= 24) {
            $d = (int) floor($h / 24);
            return $d . 'd ' . ($h % 24) . 'h';
        }
        if ($h === 0) { return $m . 'm'; }
        return $h . 'h ' . $m . 'm';
    };

    /** Expiry badge: red=expired, amber=soon, green=ok, slate=missing. */
    $expiryBadge = function (?\Illuminate\Support\Carbon $date, int $soonDays) {
        if (!$date) { return ['slate', 'Missing']; }
        if ($date->isPast())                       { return ['red',   'Expired · ' . $date->format('d M Y')]; }
        if ($date->diffInDays(now()) <= $soonDays) { return ['amber', 'Due ' . $date->format('d M Y')]; }
        return ['green', $date->format('d M Y')];
    };
@endphp

<div class="space-y-6">

    {{-- ══════════════════════════════════════════════════════════ --}}
    {{-- HEADER                                                     --}}
    {{-- ══════════════════════════════════════════════════════════ --}}
    <x-page-header
        eyebrow="Fleet control"
        title="Driver Operations"
        subtitle="Who's moving, who's idle, who's late, and who is at risk — today's fleet in one page.">
        <x-slot:actions>
            <x-button variant="secondary" size="sm" :href="route('admin.drivers.index')">
                <x-slot:icon>
                    <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                </x-slot:icon>
                Roster &amp; Compliance
            </x-button>
            <x-button variant="primary" size="sm" :href="route('admin.dispatch')">
                <x-slot:icon>
                    <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/></svg>
                </x-slot:icon>
                Dispatch Board
            </x-button>
        </x-slot:actions>
    </x-page-header>

    {{-- Unified filter bar — same component system as every other dashboard. --}}
    <x-dash.filter-bar>
        <x-dash.filter-date label="From" wire:model.live="dateFrom" minWidth="160px" />
        <x-dash.filter-date label="To"   wire:model.live="dateTo"   minWidth="160px" />
        <x-dash.filter-select label="Transporter" wire:model.live="companyId" minWidth="200px">
            <option value="">All</option>
            @foreach($companyOptions as $c)
                <option value="{{ $c->id }}">{{ $c->name }}</option>
            @endforeach
        </x-dash.filter-select>
        <x-dash.filter-select label="Status" wire:model.live="activeFilter" minWidth="160px">
            <option value="active">Active only</option>
            <option value="inactive">Inactive only</option>
            <option value="all">All</option>
        </x-dash.filter-select>
        <x-dash.filter-select label="Base location" wire:model.live="baseLocation" minWidth="180px">
            <option value="">All</option>
            @foreach($baseOptions as $b)
                <option value="{{ $b }}">{{ $b }}</option>
            @endforeach
        </x-dash.filter-select>
        <x-dash.filter-reset wire:click="resetFilters" />
    </x-dash.filter-bar>

    {{-- ══════════════════════════════════════════════════════════ --}}
    {{-- ROW 1 — KPI                                                --}}
    {{-- ══════════════════════════════════════════════════════════ --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
        @foreach($kpis as $k)
            <x-dash.kpi
                :label="$k['label']"
                :value="is_string($k['value']) ? $k['value'] : $num($k['value'])"
                :color="$k['color']"
                :helper="$k['helper']">
                <x-slot:icon>
                    <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">{!! $k['icon'] !!}</svg>
                </x-slot:icon>
            </x-dash.kpi>
        @endforeach
    </div>

    {{-- ══════════════════════════════════════════════════════════ --}}
    {{-- ROW 2 — DRIVER ACTIVITY                                    --}}
    {{-- ══════════════════════════════════════════════════════════ --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

        {{-- Active Drivers Table --}}
        <x-dash.panel
            class="lg:col-span-2"
            title="Active drivers"
            subtitle="Live status ordered by open workload"
            :tight="true">
            <x-slot:actions>
                <x-dash.pill variant="slate">
                    {{ $num($driversSorted->count()) }} driver{{ $driversSorted->count() === 1 ? '' : 's' }}
                </x-dash.pill>
            </x-slot:actions>

            @if($driversSorted->isEmpty())
                <p class="px-5 py-10 text-sm text-slate-400 text-center">No drivers match the current filters</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="bg-slate-50/70 text-[10px] uppercase tracking-[0.15em] text-slate-500">
                                <th class="px-4 py-2 text-left font-semibold">Driver</th>
                                <th class="px-4 py-2 text-left font-semibold">Base</th>
                                <th class="px-4 py-2 text-left font-semibold">Current job</th>
                                <th class="px-4 py-2 text-center font-semibold">Status</th>
                                <th class="px-4 py-2 text-right font-semibold">Load</th>
                                <th class="px-4 py-2 text-right font-semibold">Last activity</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($driversSorted as $d)
                                @php
                                    // Semantic-pill mapping — blue = assigned (active),
                                    // teal = in_transit (on the road), slate = idle.
                                    $statusVariant = match($d->live_status) {
                                        'in_transit' => ['teal',  'In transit'],
                                        'assigned'   => ['blue',  'Assigned'],
                                        default      => ['slate', 'Idle'],
                                    };
                                    $cur = $d->current_job;
                                @endphp
                                <tr class="hover:bg-slate-50/60 transition-colors">
                                    <td class="px-4 py-2.5">
                                        <a href="{{ route('admin.drivers.edit', $d) }}" class="font-medium text-slate-900 hover:text-blue-700">{{ $d->name }}</a>
                                        @if(!$d->is_active)
                                            <span class="ml-1 inline-flex items-center rounded-sm bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-500">Inactive</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5 text-[12px] text-slate-600">{{ $d->driverProfile?->base_location ?? '—' }}</td>
                                    <td class="px-4 py-2.5">
                                        @if($cur)
                                            <a href="{{ route('admin.orders.show', $cur->id) }}" class="text-[12px] text-slate-700 hover:text-blue-700 inline-flex items-center gap-1">
                                                <span class="font-mono text-[11px] text-slate-500">#{{ $cur->job_number ?? $cur->id }}</span>
                                                <span>{{ $cur->pickupLocation?->city ?? '—' }}</span>
                                                <svg viewBox="0 0 24 24" class="h-3 w-3 text-slate-300" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                                                <span>{{ $cur->deliveryLocation?->city ?? '—' }}</span>
                                            </a>
                                        @else
                                            <span class="text-[11px] text-slate-400">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5 text-center">
                                        <x-dash.pill :variant="$statusVariant[0]">{{ $statusVariant[1] }}</x-dash.pill>
                                    </td>
                                    <td class="px-4 py-2.5 text-right">
                                        @if($d->open_count > 0)
                                            <x-dash.pill variant="blue">{{ $d->open_count }}</x-dash.pill>
                                        @else
                                            <span class="text-[11px] text-slate-400">0</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5 text-right text-[11px] text-slate-500 tabular-nums">
                                        {{ $d->last_activity ? $d->last_activity->diffForHumans(['short' => true]) : 'never' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-dash.panel>

        {{-- Assignment load --}}
        <x-dash.panel title="Assignment load" subtitle="Open jobs per driver · top 10">
            @if($topLoad->isEmpty())
                <p class="py-10 text-sm text-slate-400 text-center">No drivers carrying open jobs</p>
            @else
                <ul class="space-y-3">
                    @foreach($topLoad as $d)
                        <li>
                            <div class="flex items-center justify-between text-xs mb-1">
                                <span class="font-medium text-slate-700 truncate">{{ $d->name }}</span>
                                <span class="tabular-nums text-slate-500"><strong class="text-slate-900">{{ $d->open_count }}</strong></span>
                            </div>
                            <div class="h-2 bg-slate-100 rounded-full overflow-hidden">
                                @php $widthPct = max(4, ($d->open_count / $maxLoad) * 100); @endphp
                                <div class="h-full rounded-full {{ $d->open_count > $overloadThreshold ? 'bg-rose-500' : 'bg-blue-500' }}"
                                     style="width: {{ $widthPct }}%;"></div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif

            <x-slot:footer>
                Overload threshold: {{ $overloadThreshold }} open jobs
            </x-slot:footer>
        </x-dash.panel>
    </div>

    {{-- ══════════════════════════════════════════════════════════ --}}
    {{-- ROW 3 — PERFORMANCE                                       --}}
    {{-- ══════════════════════════════════════════════════════════ --}}
    <x-dash.panel
        title="Driver performance"
        subtitle="Completed in range · on-time ratio · avg transit · delays"
        :tight="true">
        <x-slot:actions>
            <span class="text-[11px] text-slate-500">Range: {{ $from->format('d M') }} – {{ $to->format('d M') }}</span>
        </x-slot:actions>

        @if($performers->isEmpty())
            <p class="px-5 py-10 text-sm text-slate-400 text-center">No completed or delayed jobs in this range</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="bg-slate-50/70 text-[10px] uppercase tracking-[0.15em] text-slate-500">
                            <th class="px-4 py-2 text-left font-semibold">Driver</th>
                            <th class="px-4 py-2 text-right font-semibold">Completed</th>
                            <th class="px-4 py-2 text-right font-semibold">Delivered</th>
                            <th class="px-4 py-2 text-right font-semibold">Avg transit</th>
                            <th class="px-4 py-2 text-right font-semibold">Delays</th>
                            <th class="px-4 py-2 text-right font-semibold">On-time</th>
                            <th class="px-4 py-2 text-left font-semibold">Performance</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($performers as $d)
                            @php
                                $otp = $d->on_time_pct;
                                $otpVariant = $otp === null ? 'slate'
                                    : ($otp >= 90 ? 'green'
                                    : ($otp >= 70 ? 'amber' : 'red'));
                                $barColor = $otp === null ? '#cbd5e1' : ($otp >= 90 ? '#10b981' : ($otp >= 70 ? '#f59e0b' : '#ef4444'));
                            @endphp
                            <tr class="hover:bg-slate-50/60 transition-colors">
                                <td class="px-4 py-2.5 font-medium text-slate-900">{{ $d->name }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-slate-700">{{ $num($d->completed) }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-emerald-700">{{ $num($d->delivered) }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-slate-700">{{ $human($d->avg_seconds) }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums {{ $d->delays > 0 ? 'text-rose-700 font-semibold' : 'text-slate-500' }}">{{ $num($d->delays) }}</td>
                                <td class="px-4 py-2.5 text-right">
                                    <x-dash.pill :variant="$otpVariant">{{ $otp === null ? 'n/a' : $otp . '%' }}</x-dash.pill>
                                </td>
                                <td class="px-4 py-2.5">
                                    <div class="h-1.5 w-32 bg-slate-100 rounded-full overflow-hidden">
                                        <div class="h-full rounded-full" style="width: {{ $otp === null ? 0 : max(3, $otp) }}%; background-color: {{ $barColor }};"></div>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-dash.panel>

    {{-- ══════════════════════════════════════════════════════════ --}}
    {{-- ROW 4 — PROBLEMS / RISKS                                  --}}
    {{-- ══════════════════════════════════════════════════════════ --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

        {{-- Driver issues panel --}}
        <x-dash.panel title="Driver issues" subtitle="Assignment / workload anomalies right now" :tight="true">
            <div class="divide-y divide-slate-100">
                {{-- Overloaded --}}
                <div class="px-5 py-4">
                    <div class="flex items-center justify-between mb-2">
                        <div>
                            <p class="text-sm font-semibold text-slate-900">Overloaded</p>
                            <p class="text-[11px] text-slate-500">More than {{ $overloadThreshold }} open jobs</p>
                        </div>
                        <x-dash.pill size="md" :variant="$issues['overloaded']->count() > 0 ? 'red' : 'slate'">
                            {{ $num($issues['overloaded']->count()) }}
                        </x-dash.pill>
                    </div>
                    @if($issues['overloaded']->isNotEmpty())
                        <ul class="flex flex-wrap gap-1.5">
                            @foreach($issues['overloaded']->take(8) as $d)
                                <li class="inline-flex items-center gap-1.5 rounded-full bg-rose-50 border border-rose-200 text-rose-700 px-2 py-0.5 text-[11px] font-medium">
                                    {{ $d->name }}
                                    <span class="tabular-nums text-rose-600/80">{{ $d->open_count }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                {{-- With delays --}}
                <div class="px-5 py-4">
                    <div class="flex items-center justify-between mb-2">
                        <div>
                            <p class="text-sm font-semibold text-slate-900">With delays</p>
                            <p class="text-[11px] text-slate-500">Completed jobs with delay_minutes &gt; 0 in range</p>
                        </div>
                        <x-dash.pill size="md" :variant="$issues['with_delays']->count() > 0 ? 'amber' : 'slate'">
                            {{ $num($issues['with_delays']->count()) }}
                        </x-dash.pill>
                    </div>
                    @if($issues['with_delays']->isNotEmpty())
                        <ul class="flex flex-wrap gap-1.5">
                            @foreach($issues['with_delays']->take(8) as $d)
                                <li class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 border border-amber-200 text-amber-800 px-2 py-0.5 text-[11px] font-medium">
                                    {{ $d->name }}
                                    <span class="tabular-nums text-amber-700/80">{{ $d->delays }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                {{-- No assignments --}}
                <div class="px-5 py-4">
                    <div class="flex items-center justify-between mb-2">
                        <div>
                            <p class="text-sm font-semibold text-slate-900">No assignments in range</p>
                            <p class="text-[11px] text-slate-500">Active drivers with zero jobs touched</p>
                        </div>
                        <x-dash.pill size="md" :variant="$issues['no_assignments']->count() > 0 ? 'amber' : 'slate'">
                            {{ $num($issues['no_assignments']->count()) }}
                        </x-dash.pill>
                    </div>
                    @if($issues['no_assignments']->isNotEmpty())
                        <ul class="flex flex-wrap gap-1.5">
                            @foreach($issues['no_assignments']->take(10) as $d)
                                <li class="inline-flex items-center rounded-full bg-slate-50 border border-slate-200 text-slate-600 px-2 py-0.5 text-[11px] font-medium">
                                    {{ $d->name }}
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </x-dash.panel>

        {{-- Compliance panel --}}
        <x-dash.panel
            title="Compliance risks"
            :subtitle="'Expired & expiring within ' . $expirySoonDays . ' days'"
            :tight="true">

            @if($complianceDrivers->isEmpty())
                <p class="px-5 py-10 text-sm text-slate-400 text-center">All documentation is current</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="bg-slate-50/70 text-[10px] uppercase tracking-[0.15em] text-slate-500">
                                <th class="px-4 py-2 text-left font-semibold">Driver</th>
                                <th class="px-4 py-2 text-left font-semibold">Licence</th>
                                <th class="px-4 py-2 text-left font-semibold">PDP</th>
                                <th class="px-4 py-2 text-left font-semibold">Trade plate</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($complianceDrivers as $p)
                                @php
                                    [$licCol, $licTxt] = $expiryBadge($p->license_expiry, $expirySoonDays);
                                    [$pdpCol, $pdpTxt] = $expiryBadge($p->prdp_expiry, $expirySoonDays);
                                    [$tpCol,  $tpTxt]  = $expiryBadge($p->trade_plate_expiry, $expirySoonDays);
                                @endphp
                                <tr class="hover:bg-slate-50/60 transition-colors">
                                    <td class="px-4 py-2.5">
                                        <a href="{{ route('admin.drivers.edit', $p->user) }}" class="font-medium text-slate-900 hover:text-blue-700">{{ $p->user?->name ?? '—' }}</a>
                                    </td>
                                    <td class="px-4 py-2.5"><x-dash.pill :variant="$licCol">{{ $licTxt }}</x-dash.pill></td>
                                    <td class="px-4 py-2.5"><x-dash.pill :variant="$pdpCol">{{ $pdpTxt }}</x-dash.pill></td>
                                    <td class="px-4 py-2.5"><x-dash.pill :variant="$tpCol">{{ $tpTxt }}</x-dash.pill></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if($complianceDrivers->isNotEmpty())
                <x-slot:footer>
                    <div class="text-right">
                        <a href="{{ route('admin.drivers.index', ['trade_plate' => 'expiring']) }}" class="text-[11px] font-semibold text-slate-600 hover:text-slate-900 inline-flex items-center gap-1">
                            Manage roster
                            <svg viewBox="0 0 24 24" class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
                        </a>
                    </div>
                </x-slot:footer>
            @endif
        </x-dash.panel>
    </div>

    <p class="text-center text-[10px] text-slate-400 tracking-[0.2em] uppercase pt-2">
        Trident · Driver Operations · Sources: users · driver_profiles · transport_jobs
    </p>
</div>
