<?php

use App\Models\Company;
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
     * ║  CUSTOMER (OEM) DASHBOARD                                        ║
     * ╠══════════════════════════════════════════════════════════════════╣
     * ║  Proselver moves vehicles on behalf of one OEM customer at a     ║
     * ║  time (currently FAW). The hero is a live South-Africa Google    ║
     * ║  Map of destination dealers, coloured by delivery state, with a  ║
     * ║  side-list that pan/zooms its marker on click.                   ║
     * ║                                                                  ║
     * ║  KPI strip below:                                                ║
     * ║     Inbound  ·  Delivered (range)  ·  On-time %  ·  Damage-free %║
     * ║                                                                  ║
     * ║  Quiet detail panels: dealer scoreboard, recent POD deliveries,  ║
     * ║  exceptions (long-transit + damage reports released in range).   ║
     * ║                                                                  ║
     * ║  No brand filter (single-brand OEM), no driver metrics,          ║
     * ║  no invoice metrics.                                             ║
     * ╚══════════════════════════════════════════════════════════════════╝
     */

    public ?Company $company = null;
    public bool $requiresConfirmation = false;

    // ─── Filter state (URL-persistent) ──────────────────────────────
    #[Url] public ?string $dateFrom = null;
    #[Url] public ?string $dateTo = null;
    #[Url] public ?string $region = null;         // delivery province
    #[Url] public ?int $destinationId = null;     // a specific dealer
    #[Url] public ?string $status = null;         // pipeline stage

    // ─── Status groups ────────────────────────────────────────────
    protected const G_DISPATCHED = [
        Job::STATUS_PLANNED,
        Job::STATUS_DRIVER_ASSIGNED,
        Job::STATUS_READY_FOR_COLLECTION,
    ];
    protected const G_IN_TRANSIT = [
        Job::STATUS_COLLECTED,
        Job::STATUS_IN_TRANSIT,
    ];
    // "Inbound to a dealer" for OEM map/KPI view — anything booked,
    // planned, collected or rolling.
    protected const G_INBOUND = [
        Job::STATUS_PLANNED,
        Job::STATUS_DRIVER_ASSIGNED,
        Job::STATUS_READY_FOR_COLLECTION,
        Job::STATUS_COLLECTED,
        Job::STATUS_IN_TRANSIT,
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
        $this->reset(['region', 'destinationId', 'status']);
        $this->dateFrom = now()->subDays(29)->toDateString();
        $this->dateTo   = now()->toDateString();
    }

    // Company-scoped base query. Every derived query goes through here so
    // a stray WHERE can never leak another customer's data. Columns are
    // fully qualified because some callers join `locations` (which has
    // its own company_id / status / updated_at) and Postgres rejects
    // ambiguous refs with SQLSTATE 42702.
    protected function baseJobsQuery(bool $applyStatusFilter = false)
    {
        $q = Job::where('transport_jobs.company_id', $this->company->id);

        if ($this->region) {
            $q->where(function ($w) {
                $w->whereHas('pickupLocation',    fn ($l) => $l->where('province', $this->region))
                  ->orWhereHas('deliveryLocation', fn ($l) => $l->where('province', $this->region));
            });
        }
        if ($this->destinationId) {
            $q->where('transport_jobs.delivery_location_id', $this->destinationId);
        }
        if ($applyStatusFilter && $this->status) {
            $q->where('transport_jobs.status', $this->status);
        }
        return $q;
    }

    protected function thresholds(): array
    {
        return [
            'dispatched_days' => (int) SystemSetting::get('ops.alert.dispatched_days', 2),
            'in_transit_days' => (int) SystemSetting::get('ops.alert.in_transit_days', 3),
        ];
    }

    protected function onTimeDays(): int
    {
        return (int) SystemSetting::get('ops.oem.on_time_days', 3);
    }

    public function with(): array
    {
        $from = Carbon::parse($this->dateFrom)->startOfDay();
        $to   = Carbon::parse($this->dateTo)->endOfDay();
        $span = max(1, $from->diffInDays($to) + 1);
        $prevTo   = (clone $from)->subDay()->endOfDay();
        $prevFrom = (clone $prevTo)->subDays($span - 1)->startOfDay();

        $th      = $this->thresholds();
        $onTime  = $this->onTimeDays();
        $inboundList    = $this->pgStatusList(self::G_INBOUND);
        $inTransitList  = $this->pgStatusList(self::G_IN_TRANSIT);
        $inTransitStale = now()->subDays($th['in_transit_days'])->toDateTimeString();

        // ── Per-destination aggregate (jobs → locations) ────────────────
        // Aggregates job activity grouped by delivery destination.
        $perDestination = (clone $this->baseJobsQuery())
            ->whereNotNull('transport_jobs.delivery_location_id')
            ->join('locations', 'transport_jobs.delivery_location_id', '=', 'locations.id')
            ->selectRaw("
                locations.id as location_id,
                locations.company_name as company_name,
                locations.city as city,
                locations.province as province,
                locations.latitude as latitude,
                locations.longitude as longitude,
                count(*) filter (where transport_jobs.status in ($inboundList)) as inbound_count,
                count(*) filter (where transport_jobs.delivered_at between ? and ?) as delivered_count,
                count(*) filter (
                    where transport_jobs.delivered_at between ? and ?
                      and transport_jobs.collected_at is not null
                      and transport_jobs.delivered_at - transport_jobs.collected_at <= (? || ' days')::interval
                ) as on_time_count,
                count(*) filter (
                    where transport_jobs.delivered_at between ? and ?
                      and transport_jobs.damage_report_released_at is null
                ) as damage_free_count,
                count(*) filter (
                    where transport_jobs.delivered_at between ? and ?
                      and transport_jobs.damage_report_released_at is not null
                ) as damaged_count,
                count(*) filter (
                    where transport_jobs.status in ($inTransitList)
                      and transport_jobs.updated_at <= ?
                ) as at_risk_count,
                avg(extract(epoch from (transport_jobs.delivered_at - transport_jobs.collected_at)) / 86400)
                    filter (
                        where transport_jobs.delivered_at between ? and ?
                          and transport_jobs.collected_at is not null
                    ) as avg_transit_days
            ", [
                $from, $to,
                $from, $to, (string) $onTime,
                $from, $to,
                $from, $to,
                $inTransitStale,
                $from, $to,
            ])
            ->groupBy(
                'locations.id', 'locations.company_name', 'locations.city',
                'locations.province', 'locations.latitude', 'locations.longitude'
            )
            ->get()
            ->keyBy('location_id');

        // ── Full address book + all delivery destinations ───────────────
        // Every location in the customer's address book appears, plus
        // every delivery destination from jobs (even if it belongs to
        // the transporter). Locations without lat/lng still show in
        // the side-list but won't get a map marker.
        $addressBook = Location::where('company_id', $this->company->id)
            ->where('is_active', true)
            ->get(['id', 'company_name', 'city', 'province', 'latitude', 'longitude', 'type'])
            ->keyBy('id');

        $points  = collect();
        $seenIds = [];

        // Helper to build a point entry
        $makePoint = function ($id, $name, $city, $province, $lat, $lng, $type, $act) {
            $inbound   = $act ? (int) $act->inbound_count   : 0;
            $delivered  = $act ? (int) $act->delivered_count : 0;
            $damaged    = $act ? (int) $act->damaged_count   : 0;
            $atRisk     = $act ? (int) $act->at_risk_count   : 0;
            $state = match (true) {
                $atRisk   > 0 => 'at_risk',
                $damaged  > 0 => 'damaged',
                $inbound  > 0 => 'inbound',
                $delivered > 0 => 'delivered',
                default       => 'idle',
            };
            $hasCoords = $lat !== null && $lng !== null && (float) $lat != 0;
            return [
                'id'        => (int) $id,
                'name'      => $name ?: ($city ?: 'Unknown'),
                'city'      => $city,
                'province'  => $province,
                'lat'       => $hasCoords ? (float) $lat : null,
                'lng'       => $hasCoords ? (float) $lng : null,
                'inbound'   => $inbound,
                'delivered' => $delivered,
                'damaged'   => $damaged,
                'at_risk'   => $atRisk,
                'state'     => $state,
                'type'      => $type,
            ];
        };

        // 1. Address-book locations (always present in the side-list)
        foreach ($addressBook as $loc) {
            $points->push($makePoint(
                $loc->id, $loc->company_name, $loc->city, $loc->province,
                $loc->latitude, $loc->longitude, $loc->type,
                $perDestination->get($loc->id),
            ));
            $seenIds[$loc->id] = true;
        }

        // 2. Every delivery destination from jobs that isn't already
        //    in the address book (e.g. FAW Johannesburg Depot owned
        //    by a different company).
        foreach ($perDestination as $r) {
            $lid = (int) $r->location_id;
            if (isset($seenIds[$lid])) continue;
            $points->push($makePoint(
                $lid, $r->company_name, $r->city, $r->province,
                $r->latitude, $r->longitude, null, $r,
            ));
            $seenIds[$lid] = true;
        }

        $points = $points
            ->sortByDesc(fn ($p) => $p['inbound'] + $p['delivered'])
            ->values()
            ->all();

        // ── In-transit routes (live movement arrows on the map) ───────
        // Each route = a job currently in transit. We no longer require
        // BOTH ends to have lat/lng — if only the destination does,
        // we'll still draw an arrow arriving at it.
        $routes = (clone $this->baseJobsQuery())
            ->whereIn('transport_jobs.status', self::G_IN_TRANSIT)
            ->whereNotNull('transport_jobs.delivery_location_id')
            ->join('locations as drop', 'transport_jobs.delivery_location_id', '=', 'drop.id')
            ->leftJoin('locations as pick', 'transport_jobs.pickup_location_id', '=', 'pick.id')
            ->select([
                'transport_jobs.id as job_id',
                'transport_jobs.job_number',
                'transport_jobs.status',
                'pick.latitude  as from_lat',
                'pick.longitude as from_lng',
                'pick.city      as from_city',
                'pick.province  as from_province',
                'drop.latitude  as to_lat',
                'drop.longitude as to_lng',
                'drop.city      as to_city',
                'drop.province  as to_province',
                'drop.company_name as to_name',
            ])
            ->get()
            ->map(function ($r) {
                $fromLat = $r->from_lat !== null ? (float) $r->from_lat : null;
                $fromLng = $r->from_lng !== null ? (float) $r->from_lng : null;
                $toLat   = $r->to_lat   !== null ? (float) $r->to_lat   : null;
                $toLng   = $r->to_lng   !== null ? (float) $r->to_lng   : null;

                // At least one end must be plottable
                if ($toLat === null && $fromLat === null) return null;

                return [
                    'id'            => (int) $r->job_id,
                    'number'        => $r->job_number ?: ('JOB-' . $r->job_id),
                    'from_lat'      => $fromLat,
                    'from_lng'      => $fromLng,
                    'from_city'     => $r->from_city,
                    'from_province' => $r->from_province,
                    'to_lat'        => $toLat,
                    'to_lng'        => $toLng,
                    'to_city'       => $r->to_city,
                    'to_province'   => $r->to_province,
                    'to_name'       => $r->to_name,
                ];
            })
            ->filter()
            ->values()
            ->all();

        // ── KPI totals ────────────────────────────────────────────────
        $totalInbound   = (int) $perDestination->sum('inbound_count');
        $totalDelivered = (int) $perDestination->sum('delivered_count');
        $totalOnTime    = (int) $perDestination->sum('on_time_count');
        $totalDmgFree   = (int) $perDestination->sum('damage_free_count');
        $totalDamaged   = (int) $perDestination->sum('damaged_count');

        $prevDelivered = (clone $this->baseJobsQuery())
            ->whereNotNull('delivered_at')
            ->whereBetween('delivered_at', [$prevFrom, $prevTo])
            ->count();

        $onTimePct  = $totalDelivered > 0 ? (int) round(($totalOnTime  / $totalDelivered) * 100) : null;
        $dmgFreePct = $totalDelivered > 0 ? (int) round(($totalDmgFree / $totalDelivered) * 100) : null;

        $kpis = [
            [
                'key'   => 'inbound',
                'label' => 'Inbound',
                'value' => $totalInbound,
                'color' => 'blue',
                'helper'=> 'Assigned · collected · in transit',
                'iconPath' => '<path d="M14 16H9m10 0h3v-3.15a1 1 0 0 0-.84-.99L16 11l-2.7-3.6a1 1 0 0 0-.8-.4H5.24a2 2 0 0 0-1.8 1.1l-.8 1.63A6 6 0 0 0 2 12.42V16h2"/><circle cx="6.5" cy="16.5" r="2.5"/><circle cx="16.5" cy="16.5" r="2.5"/>',
                'href'  => route('customer.orders.index', ['statusFilter' => Job::STATUS_IN_TRANSIT]),
                'trend' => null,
            ],
            [
                'key'   => 'delivered',
                'label' => 'Delivered (range)',
                'value' => $totalDelivered,
                'color' => 'green',
                'helper'=> $from->format('d M') . ' – ' . $to->format('d M'),
                'iconPath' => '<path d="M20 6 9 17l-5-5"/>',
                'href'  => null,
                'trend' => $this->trend($totalDelivered, (int) $prevDelivered),
            ],
            [
                'key'   => 'on_time',
                'label' => 'On-time %',
                'value' => $onTimePct !== null ? ($onTimePct . '%') : '—',
                'color' => $onTimePct === null
                    ? 'slate'
                    : ($onTimePct >= 90 ? 'green' : ($onTimePct >= 70 ? 'amber' : 'red')),
                'helper'=> $totalDelivered > 0
                    ? "{$totalOnTime} of {$totalDelivered} within {$onTime}d of collection"
                    : 'No deliveries in range',
                'iconPath' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
                'href'  => null,
                'trend' => null,
            ],
            [
                'key'   => 'damage_free',
                'label' => 'Damage-free %',
                'value' => $dmgFreePct !== null ? ($dmgFreePct . '%') : '—',
                'color' => $dmgFreePct === null
                    ? 'slate'
                    : ($dmgFreePct >= 98 ? 'green' : ($dmgFreePct >= 90 ? 'amber' : 'red')),
                'helper'=> $totalDamaged > 0
                    ? "{$totalDamaged} delivered with damage reported"
                    : ($totalDelivered > 0 ? 'No damage reports in range' : 'No deliveries in range'),
                'iconPath' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
                'href'  => null,
                'trend' => null,
            ],
        ];

        // ── Scoreboard (top 10 destinations by combined activity) ─────
        $scoreboard = $perDestination
            ->sortByDesc(fn ($r) => (int) $r->inbound_count + (int) $r->delivered_count)
            ->take(10)
            ->map(function ($r) {
                $delivered = (int) $r->delivered_count;
                return (object) [
                    'id'               => (int) $r->location_id,
                    'name'             => $r->company_name ?: ($r->city ?: '—'),
                    'city'             => $r->city,
                    'province'         => $r->province,
                    'inbound'          => (int) $r->inbound_count,
                    'delivered'        => $delivered,
                    'on_time'          => (int) $r->on_time_count,
                    'damaged'          => (int) $r->damaged_count,
                    'on_time_pct'      => $delivered > 0
                        ? (int) round(((int) $r->on_time_count / $delivered) * 100)
                        : null,
                    'damage_free_pct'  => $delivered > 0
                        ? (int) round(((($delivered - (int) $r->damaged_count)) / $delivered) * 100)
                        : null,
                    'avg_transit_days' => $r->avg_transit_days !== null
                        ? round((float) $r->avg_transit_days, 1)
                        : null,
                    'at_risk'          => (int) $r->at_risk_count,
                ];
            })
            ->values();

        // ── Awaiting-confirmation banner count (only if the customer
        //    workflow demands it) ───────────────────────────────────────
        $awaitingMine = $this->requiresConfirmation
            ? (clone $this->baseJobsQuery())
                ->where('transport_jobs.status', Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION)
                ->count()
            : 0;

        // ── Recent deliveries with POD (top 5) ────────────────────────
        $recentDeliveries = (clone $this->baseJobsQuery())
            ->whereIn('transport_jobs.status', [Job::STATUS_DELIVERED, Job::STATUS_COMPLETED])
            ->with([
                'deliveryLocation:id,city,province,company_name',
                'brand:id,name',
                'documents',
            ])
            ->orderByDesc(DB::raw('coalesce(completed_at, delivered_at)'))
            ->limit(5)
            ->get();

        // ── Exceptions (long transit + damage in range) ───────────────
        $exceptions = (clone $this->baseJobsQuery())
            ->where(function ($w) use ($th, $from, $to) {
                $w->where(function ($a) use ($th) {
                    $a->whereIn('transport_jobs.status', self::G_IN_TRANSIT)
                      ->where('transport_jobs.updated_at', '<=', now()->subDays($th['in_transit_days']));
                })->orWhere(function ($a) use ($th) {
                    $a->whereIn('transport_jobs.status', self::G_DISPATCHED)
                      ->where('transport_jobs.updated_at', '<=', now()->subDays($th['dispatched_days']));
                })->orWhere(function ($a) use ($from, $to) {
                    $a->whereNotNull('transport_jobs.damage_report_released_at')
                      ->whereBetween('transport_jobs.damage_report_released_at', [$from, $to]);
                });
            })
            ->with([
                'pickupLocation:id,city,province',
                'deliveryLocation:id,city,province,company_name',
            ])
            ->orderBy('transport_jobs.updated_at')
            ->limit(12)
            ->get()
            ->each(function ($j) use ($th) {
                $j->setAttribute('is_damaged', !is_null($j->damage_report_released_at));
                $j->setAttribute('days_in_stage', $j->updated_at ? (int) $j->updated_at->diffInDays(now()) : 0);
                $j->setAttribute('is_long_transit',
                    in_array($j->status, self::G_IN_TRANSIT, true)
                    && $j->updated_at
                    && $j->updated_at->lte(now()->subDays($th['in_transit_days']))
                );
            });

        // ── Filter option lists (scoped to this customer) ─────────────
        $regionOptions = Location::query()
            ->whereNotNull('province')->where('province', '!=', '')
            ->where(function ($q) {
                $q->whereIn('id', Job::where('company_id', $this->company->id)->pluck('pickup_location_id'))
                  ->orWhereIn('id', Job::where('company_id', $this->company->id)->pluck('delivery_location_id'));
            })
            ->distinct()->orderBy('province')->pluck('province');

        $destinationOptions = Location::query()
            ->whereIn('id', Job::where('company_id', $this->company->id)
                ->whereNotNull('delivery_location_id')
                ->pluck('delivery_location_id')->unique())
            ->orderBy('company_name')->orderBy('city')
            ->get(['id', 'company_name', 'city', 'province']);

        $statusOptions = self::ACTIVE_PHASE1;

        return compact(
            'from', 'to', 'span',
            'kpis', 'points', 'routes', 'scoreboard',
            'awaitingMine',
            'recentDeliveries', 'exceptions',
            'regionOptions', 'destinationOptions', 'statusOptions',
            'onTime', 'th',
        );
    }

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

    /** Comma-separated quoted status list for Postgres FILTER clauses. */
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
        title="Vehicle movements"
        subtitle="Where your vehicles are, which dealers are receiving them, and how they're arriving.">
        <x-slot:actions>
            <x-button variant="secondary" size="sm" :href="route('customer.orders.index')">
                <x-slot:icon>
                    <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3h18v18H3z"/><path d="M3 9h18"/><path d="M9 21V9"/></svg>
                </x-slot:icon>
                All orders
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

    @if($requiresConfirmation && $awaitingMine > 0)
        <div class="flex items-center gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <svg viewBox="0 0 24 24" class="h-5 w-5 shrink-0 text-amber-600" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/>
            </svg>
            <div class="flex-1 min-w-0">
                <p class="font-semibold">{{ $awaitingMine }} {{ \Illuminate\Support\Str::plural('order', $awaitingMine) }} waiting for your confirmation</p>
                <p class="text-xs text-amber-800">Confirm so Proselver can dispatch.</p>
            </div>
            <a href="{{ route('customer.orders.index', ['statusFilter' => \App\Models\Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION]) }}"
               class="shrink-0 rounded-md bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-700">
                Review
            </a>
        </div>
    @endif

    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- FILTER BAR                                                     --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    <x-dash.filter-bar>
        <x-dash.filter-date label="From" wire:model.live="dateFrom" minWidth="160px" />
        <x-dash.filter-date label="To"   wire:model.live="dateTo"   minWidth="160px" />
        <x-dash.filter-select label="Region" wire:model.live="region" minWidth="170px">
            <option value="">All</option>
            @foreach($regionOptions as $r)
                <option value="{{ $r }}">{{ $r }}</option>
            @endforeach
        </x-dash.filter-select>
        <x-dash.filter-select label="Destination" wire:model.live="destinationId" minWidth="220px">
            <option value="">All destinations</option>
            @foreach($destinationOptions as $d)
                <option value="{{ $d->id }}">{{ $d->company_name }}{{ $d->city ? ' · ' . $d->city : '' }}</option>
            @endforeach
        </x-dash.filter-select>
        <x-dash.filter-select label="Stage" wire:model.live="status" minWidth="190px">
            <option value="">Any</option>
            @foreach($statusOptions as $s)
                <option value="{{ $s }}">{{ \App\Models\Job::PHASE1_STATUS_LABELS[$s] ?? ucwords(str_replace('_', ' ', $s)) }}</option>
            @endforeach
        </x-dash.filter-select>
        <x-dash.filter-reset wire:click="resetFilters" />
    </x-dash.filter-bar>

    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- HERO — MAP + DESTINATION LIST                                  --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    <div
        x-data="oemMap(@js($points), @js($routes))"
        x-init="init()"
        wire:key="oem-map-{{ md5(json_encode($points) . json_encode($routes)) }}"
        class="grid grid-cols-1 xl:grid-cols-3 gap-4"
    >
        <x-dash.panel
            class="xl:col-span-2"
            title="Live destination map"
            :subtitle="count($points) . ' ' . \Illuminate\Support\Str::plural('location', count($points)) . ' in your network' . (count($routes) > 0 ? ' · ' . count($routes) . ' in transit' : '')"
            :tight="true">
            <x-slot:actions>
                <div class="hidden md:flex items-center gap-3 text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-500">
                    <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-blue-500"></span>Inbound</span>
                    <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-emerald-500"></span>Delivered</span>
                    <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-amber-500"></span>Overdue</span>
                    <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-rose-500"></span>Damaged</span>
                    <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-slate-300"></span>Idle</span>
                    <span class="inline-flex items-center gap-1.5">
                        <svg class="h-3 w-4" viewBox="0 0 16 8"><line x1="0" y1="4" x2="12" y2="4" stroke="#2563eb" stroke-width="2" stroke-dasharray="3 2"/><polygon points="12,1 16,4 12,7" fill="#2563eb"/></svg>
                        In transit
                    </span>
                </div>
            </x-slot:actions>

            <div class="relative">
                {{-- Map host. wire:ignore keeps Livewire from touching the
                     Google-built DOM inside it; we drive marker refreshes
                     from the Alpine component when the wire:key bumps. --}}
                <div wire:ignore x-ref="mapEl" class="h-[520px] w-full bg-slate-50"></div>

                {{-- Loading state shown until Maps JS is ready --}}
                <div x-show="!ready" x-transition.opacity
                     class="absolute inset-0 flex items-center justify-center bg-white/80 text-sm text-slate-500">
                    <div class="flex items-center gap-2">
                        <svg class="h-4 w-4 animate-spin text-slate-400" viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" stroke-opacity="0.25"/>
                            <path d="M22 12a10 10 0 0 1-10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                        </svg>
                        Loading map…
                    </div>
                </div>

                {{-- Empty state overlay --}}
                <template x-if="ready && points.length === 0">
                    <div class="absolute inset-0 flex items-center justify-center pointer-events-none">
                        <div class="rounded-lg border border-slate-200 bg-white/95 px-4 py-3 text-sm text-slate-500 shadow-sm">
                            No locations with coordinates in your address book
                        </div>
                    </div>
                </template>
            </div>
        </x-dash.panel>

        <x-dash.panel
            title="Destinations"
            subtitle="Click to focus · sorted by activity"
            :tight="true">
            <template x-if="points.length === 0">
                <p class="px-5 py-10 text-center text-sm text-slate-400">No locations in your address book</p>
            </template>
            <ul class="divide-y divide-slate-100 max-h-[520px] overflow-y-auto">
                <template x-for="p in points" :key="p.id">
                    <li
                        class="px-5 py-3 cursor-pointer transition-colors"
                        :class="selectedId === p.id ? 'bg-blue-50/80' : 'hover:bg-slate-50'"
                        @click="focus(p)">
                        <div class="flex items-center gap-3">
                            <span class="h-2 w-2 shrink-0 rounded-full" :class="dotClass(p.state)"></span>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-slate-900 truncate" x-text="p.name"></p>
                                <p class="text-[11px] text-slate-500 truncate">
                                    <span x-text="[p.city, p.province].filter(Boolean).join(', ') || '—'"></span>
                                    <span x-show="p.lat === null" class="text-[10px] text-amber-500 ml-1">· no pin</span>
                                </p>
                            </div>
                            <div class="text-right shrink-0 space-y-0.5">
                                <p class="text-[11px] tabular-nums" x-show="p.inbound > 0">
                                    <span class="font-semibold text-blue-700" x-text="p.inbound"></span>
                                    <span class="text-slate-400">en route</span>
                                </p>
                                <p class="text-[11px] tabular-nums" x-show="p.delivered > 0">
                                    <span class="font-semibold text-emerald-700" x-text="p.delivered"></span>
                                    <span class="text-slate-400">delivered</span>
                                </p>
                                <p class="text-[10px] text-rose-600 tabular-nums" x-show="p.damaged > 0">
                                    <span x-text="p.damaged"></span>
                                    <span>damaged</span>
                                </p>
                                <p class="text-[10px] text-slate-400" x-show="p.inbound === 0 && p.delivered === 0 && p.damaged === 0">
                                    No activity
                                </p>
                            </div>
                        </div>
                    </li>
                </template>
            </ul>
        </x-dash.panel>
    </div>

    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- KPI STRIP                                                      --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        @foreach($kpis as $k)
            <x-dash.kpi
                :label="$k['label']"
                :value="is_string($k['value']) ? $k['value'] : $num($k['value'])"
                :color="$k['color']"
                :href="$k['href'] ?? null"
                :helper="$k['helper']"
                :trend="$k['trend'] ?? null">
                <x-slot:icon>
                    <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">{!! $k['iconPath'] !!}</svg>
                </x-slot:icon>
            </x-dash.kpi>
        @endforeach
    </div>

    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- DEALER SCOREBOARD (collapsed)                                  --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    <x-dash.panel
        title="Destination scoreboard"
        subtitle="Top 10 locations in this window"
        :tight="true">
        @if($scoreboard->isEmpty())
            <p class="px-5 py-10 text-center text-sm text-slate-400">No destinations in range</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="bg-slate-50/70 text-[10px] uppercase tracking-[0.15em] text-slate-500">
                            <th class="px-4 py-2 text-left font-semibold">Destination</th>
                            <th class="px-4 py-2 text-left font-semibold">Location</th>
                            <th class="px-4 py-2 text-right font-semibold">Inbound</th>
                            <th class="px-4 py-2 text-right font-semibold">Delivered</th>
                            <th class="px-4 py-2 text-right font-semibold">On-time</th>
                            <th class="px-4 py-2 text-right font-semibold">Damage-free</th>
                            <th class="px-4 py-2 text-right font-semibold">Avg transit</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($scoreboard as $s)
                            <tr class="hover:bg-slate-50/60 transition-colors cursor-pointer"
                                wire:click="$set('destinationId', {{ $s->id }})">
                                <td class="px-4 py-2.5">
                                    <div class="text-[13px] font-medium text-slate-900 truncate max-w-[260px]">{{ $s->name }}</div>
                                    @if($s->at_risk > 0)
                                        <div class="mt-0.5"><x-dash.pill variant="amber" size="sm">{{ $s->at_risk }} overdue</x-dash.pill></div>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-[12px] text-slate-600 truncate max-w-[180px]">
                                    {{ $s->city ?: '—' }}{{ $s->province ? ', ' . $s->province : '' }}
                                </td>
                                <td class="px-4 py-2.5 text-right tabular-nums">
                                    <span class="text-[13px] font-semibold text-blue-700">{{ $num($s->inbound) }}</span>
                                </td>
                                <td class="px-4 py-2.5 text-right tabular-nums">
                                    <span class="text-[13px] font-semibold text-emerald-700">{{ $num($s->delivered) }}</span>
                                </td>
                                <td class="px-4 py-2.5 text-right tabular-nums">
                                    @if($s->on_time_pct === null)
                                        <span class="text-[11px] text-slate-400">—</span>
                                    @else
                                        <x-dash.pill size="sm" :variant="$s->on_time_pct >= 90 ? 'green' : ($s->on_time_pct >= 70 ? 'amber' : 'red')">
                                            {{ $s->on_time_pct }}%
                                        </x-dash.pill>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-right tabular-nums">
                                    @if($s->damage_free_pct === null)
                                        <span class="text-[11px] text-slate-400">—</span>
                                    @else
                                        <x-dash.pill size="sm" :variant="$s->damage_free_pct >= 98 ? 'green' : ($s->damage_free_pct >= 90 ? 'amber' : 'red')">
                                            {{ $s->damage_free_pct }}%
                                        </x-dash.pill>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-right text-[12px] tabular-nums text-slate-700">
                                    {{ $s->avg_transit_days !== null ? $s->avg_transit_days . 'd' : '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-dash.panel>

    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- RECENT DELIVERIES + EXCEPTIONS                                 --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

        {{-- Recent deliveries --}}
        <x-dash.panel title="Recent deliveries" subtitle="Latest 5 · POD status" :tight="true">
            @if($recentDeliveries->isEmpty())
                <p class="px-5 py-10 text-sm text-slate-400 text-center">No recent deliveries</p>
            @else
                <ul class="divide-y divide-slate-100">
                    @foreach($recentDeliveries as $d)
                        @php
                            $hasPod       = $d->documents->where('category', 'proof_of_delivery')->isNotEmpty();
                            $completedOn  = $d->completed_at ?? $d->delivered_at;
                            $hasDamage    = !is_null($d->damage_report_released_at);
                        @endphp
                        <li class="px-5 py-3">
                            <a href="{{ route('customer.orders.show', $d) }}" class="block group">
                                <div class="flex items-center justify-between gap-3">
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-medium text-slate-900 truncate group-hover:text-blue-700">
                                            {{ $d->job_number ?? ('JOB-' . $d->id) }}
                                            <span class="font-normal text-slate-500">· {{ $d->model_name ?: ($d->brand?->name ?: 'Vehicle') }}</span>
                                        </p>
                                        <p class="text-[11px] text-slate-500 truncate">
                                            {{ $d->deliveryLocation?->shortDisplay() ?? ($d->deliveryLocation?->city ?? '—') }}
                                            @if($completedOn)
                                                · {{ $completedOn->format('d M Y') }}
                                            @endif
                                        </p>
                                    </div>
                                    <div class="flex items-center gap-1.5 shrink-0">
                                        @if($hasDamage)
                                            <x-dash.pill variant="red" size="sm">Damage</x-dash.pill>
                                        @endif
                                        @if($hasPod)
                                            <x-dash.pill variant="green" size="sm">POD</x-dash.pill>
                                        @else
                                            <x-dash.pill variant="slate" size="sm">Pending</x-dash.pill>
                                        @endif
                                    </div>
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

        {{-- Exceptions --}}
        <x-dash.panel
            :title="'Exceptions' . ($exceptions->isEmpty() ? '' : ' · ' . $exceptions->count())"
            :subtitle="'Overdue: in a stage longer than ' . $th['in_transit_days'] . ' days, or damage reported'"
            :tight="true">
            @if($exceptions->isEmpty())
                <div class="px-5 py-10 text-center">
                    <div class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-emerald-50 text-emerald-600 mb-2">
                        <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                    </div>
                    <p class="text-sm text-slate-500">Nothing needs attention right now</p>
                </div>
            @else
                <ul class="divide-y divide-slate-100 max-h-[400px] overflow-y-auto">
                    @foreach($exceptions as $e)
                        @php
                            $isDamaged = (bool) $e->getAttribute('is_damaged');
                            $isLong    = (bool) $e->getAttribute('is_long_transit');
                            $label     = $isDamaged ? 'Damage report released' : ($isLong ? 'Long in transit' : 'Stuck in dispatch');
                            $variant   = $isDamaged ? 'red' : ($isLong ? 'amber' : 'amber');
                        @endphp
                        <li class="px-5 py-3">
                            <a href="{{ route('customer.orders.show', $e) }}" class="block group">
                                <div class="flex items-center justify-between gap-3">
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-medium text-slate-900 truncate group-hover:text-blue-700">
                                            {{ $e->job_number ?? ('JOB-' . $e->id) }}
                                            <span class="font-normal text-slate-500">·
                                                {{ $e->pickupLocation?->city ?? '—' }} → {{ $e->deliveryLocation?->city ?? '—' }}
                                            </span>
                                        </p>
                                        <p class="text-[11px] text-slate-500">
                                            {{ $label }} · in stage {{ $e->getAttribute('days_in_stage') }}d
                                        </p>
                                    </div>
                                    <x-dash.pill size="sm" :variant="$variant">{{ $isDamaged ? 'Damage' : 'Overdue' }}</x-dash.pill>
                                </div>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-dash.panel>
    </div>

    <p class="text-center text-[10px] text-slate-400 tracking-[0.2em] uppercase pt-2">
        Trident · {{ $company->name }} · Proselver vehicle movements
    </p>
</div>

{{-- ══════════════════════════════════════════════════════════════════ --}}
{{-- ALPINE COMPONENT — Google Maps binding                             --}}
{{-- ══════════════════════════════════════════════════════════════════ --}}
@once
<script>
(() => {
    const register = () => {
        if (window.Alpine && window.Alpine._oemMapRegistered) return;

        const STATE_COLORS = {
            at_risk:   { fill: '#d97706', dot: 'bg-amber-500' },
            damaged:   { fill: '#e11d48', dot: 'bg-rose-500'  },
            inbound:   { fill: '#2563eb', dot: 'bg-blue-500'  },
            delivered: { fill: '#16a34a', dot: 'bg-emerald-500' },
            idle:      { fill: '#94a3b8', dot: 'bg-slate-400' },
        };

        window.Alpine.data('oemMap', (initialPoints, initialRoutes) => ({
            points: Array.isArray(initialPoints) ? initialPoints : [],
            routes: Array.isArray(initialRoutes) ? initialRoutes : [],
            map: null,
            markers: [],
            routeLines: [],
            infoWindow: null,
            ready: false,
            selectedId: null,

            init() {
                this.waitForGoogle().then(() => this.render()).catch(() => { this.ready = true; });
            },

            // Google Maps is loaded with callback=initGooglePlaces in the
            // app layout. By the time the dashboard renders it's often
            // already in window.google; if not, poll briefly.
            waitForGoogle() {
                return new Promise((resolve, reject) => {
                    if (window.google && window.google.maps) return resolve();
                    let tries = 0;
                    const iv = setInterval(() => {
                        if (window.google && window.google.maps) {
                            clearInterval(iv); resolve();
                        } else if (++tries > 80) {  // ~8s
                            clearInterval(iv); reject(new Error('google.maps never loaded'));
                        }
                    }, 100);
                });
            },

            render() {
                const el = this.$refs.mapEl;
                if (!el) { this.ready = true; return; }

                this.map = new google.maps.Map(el, {
                    center: { lat: -28.8, lng: 24.7 },  // rough centre of SA
                    zoom: 6,
                    mapTypeControl: false,
                    streetViewControl: false,
                    fullscreenControl: true,
                    styles: [
                        { featureType: 'poi',             stylers: [{ visibility: 'off' }] },
                        { featureType: 'transit',         stylers: [{ visibility: 'off' }] },
                        { featureType: 'road.local',      elementType: 'labels', stylers: [{ visibility: 'off' }] },
                        { featureType: 'administrative.land_parcel', stylers: [{ visibility: 'off' }] },
                    ],
                });
                this.infoWindow = new google.maps.InfoWindow();
                this.dropMarkers();
                this.drawRoutes();
                this.ready = true;
            },

            dropMarkers() {
                if (!this.map) return;
                this.markers.forEach(m => m.setMap(null));
                this.markers = [];
                if (this.points.length === 0) return;

                const bounds = new google.maps.LatLngBounds();

                this.points.forEach(p => {
                    if (p.lat === null || p.lng === null) {
                        this.markers.push(null);
                        return;
                    }
                    const isIdle = p.state === 'idle';
                    const color = (STATE_COLORS[p.state] || STATE_COLORS.idle).fill;
                    const scale = isIdle
                        ? 5
                        : Math.min(16, 8 + Math.sqrt(Math.max(0, p.inbound + p.delivered)) * 1.6);

                    const marker = new google.maps.Marker({
                        position: { lat: p.lat, lng: p.lng },
                        map: this.map,
                        title: p.name,
                        icon: {
                            path: google.maps.SymbolPath.CIRCLE,
                            scale,
                            fillColor: color,
                            fillOpacity: isIdle ? 0.45 : 0.9,
                            strokeColor: '#ffffff',
                            strokeWeight: isIdle ? 1 : 2,
                        },
                        zIndex: isIdle ? 1 : 10,
                    });

                    marker.addListener('click', () => this.openInfo(p, marker));
                    this.markers.push(marker);
                    bounds.extend(marker.getPosition());
                });

                const realMarkers = this.markers.filter(m => m !== null);
                if (realMarkers.length === 1) {
                    this.map.setCenter(realMarkers[0].getPosition());
                    this.map.setZoom(8);
                } else if (realMarkers.length > 1) {
                    this.map.fitBounds(bounds, 48);
                }
            },

            drawRoutes() {
                if (!this.map) return;
                this.routeLines.forEach(l => l.setMap(null));
                this.routeLines = [];
                if (this.routes.length === 0) return;

                // SA province centroids as fallback when a location
                // has no coordinates. Better than not drawing at all.
                const provinceFallback = {
                    'Gauteng':        { lat: -26.27, lng: 28.11 },
                    'Western Cape':   { lat: -33.93, lng: 18.42 },
                    'Eastern Cape':   { lat: -33.72, lng: 25.53 },
                    'KwaZulu-Natal':  { lat: -29.60, lng: 30.38 },
                    'Free State':     { lat: -29.09, lng: 26.16 },
                    'Limpopo':        { lat: -23.40, lng: 29.42 },
                    'Mpumalanga':     { lat: -25.47, lng: 30.97 },
                    'North West':     { lat: -26.66, lng: 25.29 },
                    'Northern Cape':  { lat: -29.05, lng: 21.86 },
                };
                const fallback = (prov) => provinceFallback[prov] || { lat: -33.80, lng: 25.80 };

                this.routes.forEach(r => {
                    const from = (r.from_lat != null && r.from_lng != null)
                        ? { lat: r.from_lat, lng: r.from_lng }
                        : fallback(r.from_province);
                    const to = (r.to_lat != null && r.to_lng != null)
                        ? { lat: r.to_lat, lng: r.to_lng }
                        : fallback(r.to_province);

                    // Don't draw if both ends resolved to the same fallback
                    if (from.lat === to.lat && from.lng === to.lng) return;

                    const path = [from, to];

                    const line = new google.maps.Polyline({
                        path,
                        map: this.map,
                        geodesic: true,
                        strokeColor: '#2563eb',
                        strokeOpacity: 0,
                        strokeWeight: 2,
                        icons: [
                            {
                                icon: { path: 'M 0,-1 0,1', strokeOpacity: 0.6, strokeWeight: 2, scale: 2 },
                                offset: '0',
                                repeat: '12px',
                            },
                            {
                                icon: {
                                    path: google.maps.SymbolPath.FORWARD_CLOSED_ARROW,
                                    scale: 3,
                                    fillColor: '#2563eb',
                                    fillOpacity: 0.9,
                                    strokeColor: '#ffffff',
                                    strokeWeight: 1,
                                },
                                offset: '100%',
                            },
                        ],
                        zIndex: 5,
                    });

                    line.addListener('click', () => {
                        const mid = {
                            lat: (r.from_lat + r.to_lat) / 2,
                            lng: (r.from_lng + r.to_lng) / 2,
                        };
                        const html = `
                            <div style="min-width:180px;font:500 13px/1.4 ui-sans-serif,system-ui;color:#0f172a">
                                <div style="font-weight:700;margin-bottom:4px;color:#2563eb">${escapeHtml(r.number)}</div>
                                <div style="font-size:12px">
                                    <span style="color:#64748b">From</span> ${escapeHtml(r.from_city || '—')}<br>
                                    <span style="color:#64748b">To</span> ${escapeHtml(r.to_name || r.to_city || '—')}
                                </div>
                                <a href="/customer/orders/${r.id}" style="display:inline-block;margin-top:8px;font-size:11px;font-weight:600;color:#1d4ed8;text-decoration:none">View order →</a>
                            </div>`;
                        this.infoWindow.setContent(html);
                        this.infoWindow.setPosition(mid);
                        this.infoWindow.open(this.map);
                    });

                    this.routeLines.push(line);
                });
            },

            openInfo(p, marker) {
                if (!this.infoWindow) return;
                this.selectedId = p.id;
                const prov = [p.city, p.province].filter(Boolean).join(', ') || '—';
                const orderLink = '/customer/orders?destination=' + encodeURIComponent(p.id);
                const html = `
                    <div style="min-width:220px;font:500 13px/1.4 ui-sans-serif,system-ui;color:#0f172a">
                        <div style="font-weight:700;margin-bottom:2px">${escapeHtml(p.name)}</div>
                        <div style="color:#64748b;font-size:11px;margin-bottom:8px">${escapeHtml(prov)}</div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px 12px;font-size:12px">
                            <div><span style="color:#64748b">En route</span> <strong style="color:#1d4ed8">${p.inbound}</strong></div>
                            <div><span style="color:#64748b">Delivered</span> <strong style="color:#15803d">${p.delivered}</strong></div>
                            ${p.at_risk > 0 ? `<div><span style="color:#64748b">Overdue</span> <strong style="color:#b45309">${p.at_risk}</strong></div>` : ''}
                            ${p.damaged > 0 ? `<div><span style="color:#64748b">Damaged</span> <strong style="color:#be123c">${p.damaged}</strong></div>` : ''}
                        </div>
                        <a href="${orderLink}" style="display:inline-block;margin-top:10px;font-size:11px;font-weight:600;color:#1d4ed8;text-decoration:none">View orders →</a>
                    </div>`;
                this.infoWindow.setContent(html);
                this.infoWindow.open({ anchor: marker, map: this.map });
            },

            focus(p) {
                this.selectedId = p.id;
                if (!this.map || p.lat === null || p.lng === null) return;
                const idx = this.points.findIndex(x => x.id === p.id);
                if (idx < 0) return;
                const marker = this.markers[idx];
                this.map.panTo({ lat: p.lat, lng: p.lng });
                if (this.map.getZoom() < 9) this.map.setZoom(9);
                if (marker) this.openInfo(p, marker);
            },

            dotClass(state) {
                return (STATE_COLORS[state] || STATE_COLORS.idle).dot;
            },
        }));

        window.Alpine._oemMapRegistered = true;
    };

    function escapeHtml(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        })[c]);
    }

    if (window.Alpine) { register(); }
    else { document.addEventListener('alpine:init', register); }
})();
</script>
@endonce
