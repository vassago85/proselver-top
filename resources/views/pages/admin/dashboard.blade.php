<?php

use App\Models\Job;
use App\Models\JobDocument;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;

new #[Layout('components.layouts.app')] class extends Component {
    /**
     * One-click dismiss for a damage incident on the dashboard strip.
     * Stamps damage_acknowledged_at so the incident drops off both the
     * "Damage" stat tile and the "Recent damage incidents" list without
     * forcing the operator through the full release-to-customer flow.
     * Release implies ack elsewhere; this is the lighter-weight
     * "yes I've seen it" action ops needed to stop the dashboard
     * repeatedly nagging about incidents they're already working.
     */
    public function dismissDamage(int $jobId): void
    {
        $user = auth()->user();
        abort_unless($user && $user->isInternal(), 403);

        $job = Job::find($jobId);
        if (!$job) {
            return;
        }

        $job->forceFill([
            'damage_acknowledged_at' => now(),
            'damage_acknowledged_by' => $user->id,
        ])->save();

        session()->flash('dashboard_ack', "Damage incident for #{$job->job_number} dismissed.");
    }

    public function with(): array
    {
        // ─── Operations headline counters ─────────────────────────────
        // "New" from ops' point of view = anything that just landed
        // and still needs eyes. Dealer + OEM bookings arrive in
        // PENDING_VERIFICATION; portal bookings land in RECEIVED.
        $newOrders = Job::whereIn('status', [
            Job::STATUS_PENDING_VERIFICATION,
            Job::STATUS_RECEIVED,
        ])->count();
        $pendingVerification = Job::where('status', Job::STATUS_PENDING_VERIFICATION)->count();
        $readyToPlan         = Job::where('status', Job::STATUS_CONFIRMED)->count();

        // In-flight = anything with a driver actively moving (or about
        // to). This feeds both the tile AND the live-movements board.
        $inFlightStatuses = [
            Job::STATUS_DRIVER_ASSIGNED,
            Job::STATUS_READY_FOR_COLLECTION,
            Job::STATUS_COLLECTED,
            Job::STATUS_IN_TRANSIT,
        ];
        $inFlight = Job::whereIn('status', $inFlightStatuses)->count();

        $deliveredToday = Job::whereIn('status', [Job::STATUS_DELIVERED, Job::STATUS_COMPLETED])
            ->whereDate('delivered_at', today())
            ->count();

        // Live pulse row: counts per live status. driver_assigned
        // absorbs legacy ready_for_collection rows so the board never
        // shows both side by side.
        $liveCounts = [
            'driver_assigned' => Job::whereIn('status', [Job::STATUS_DRIVER_ASSIGNED, Job::STATUS_READY_FOR_COLLECTION])->count(),
            'collected'       => Job::where('status', Job::STATUS_COLLECTED)->count(),
            'in_transit'      => Job::where('status', Job::STATUS_IN_TRANSIT)->count(),
        ];

        // ─── Revenue / money tiles ────────────────────────────────────
        // Everything here uses invoiced_at (the moment the money
        // becomes real). Awaiting-invoicing = delivered but the ops
        // team hasn't invoiced yet = money sitting on the table.
        $invoicedTodayValue = (float) Job::whereDate('invoiced_at', today())->sum('total_sell_price');
        $invoicedTodayCount = Job::whereDate('invoiced_at', today())->count();

        $monthStart = now()->startOfMonth();
        $invoicedMtdValue = (float) Job::where('invoiced_at', '>=', $monthStart)->sum('total_sell_price');
        $invoicedMtdCount = Job::where('invoiced_at', '>=', $monthStart)->count();
        $costMtd          = (float) Job::where('invoiced_at', '>=', $monthStart)->sum('total_cost');
        $marginMtd        = max(0, $invoicedMtdValue - $costMtd);
        $marginPct        = $invoicedMtdValue > 0 ? round(($marginMtd / $invoicedMtdValue) * 100) : 0;

        $awaitingInvoicing = Job::whereIn('status', [Job::STATUS_DELIVERED, Job::STATUS_COMPLETED, Job::STATUS_READY_FOR_INVOICING])
            ->whereNull('invoiced_at');
        $awaitingCount = (clone $awaitingInvoicing)->count();
        $awaitingValue = (float) (clone $awaitingInvoicing)->sum('total_sell_price');

        // ─── Live movements board ─────────────────────────────────────
        // The cool shit: every vehicle currently on the road, with
        // driver, route, elapsed time since dispatch. Ordered so
        // the most "at-risk" (longest running) surface first.
        $liveMovements = Job::with([
                'company:id,name',
                'pickupLocation:id,company_name,city',
                'deliveryLocation:id,company_name,city',
                'brand:id,name',
                'driver:id,name',
            ])
            ->whereIn('status', $inFlightStatuses)
            ->orderByDesc(\DB::raw('COALESCE(collected_at, assigned_at, updated_at)'))
            ->limit(12)
            ->get();

        // ─── Damage pulse ─────────────────────────────────────────────
        // Only unacknowledged incidents. Ack happens when ops views
        // the order page, dismisses inline, or releases to customer.
        $damageJobIds = JobDocument::where('category', JobDocument::CATEGORY_DAMAGE_PHOTO)
            ->distinct()
            ->pluck('job_id');

        $unackedBase = Job::whereIn('id', $damageJobIds)
            ->whereNull('damage_acknowledged_at');

        $pendingReleaseCount = (clone $unackedBase)
            ->whereNull('damage_report_released_at')
            ->count();
        $openDamageCount = (clone $unackedBase)
            ->whereNotIn('status', [Job::STATUS_COMPLETED, Job::STATUS_CANCELLED])
            ->count();

        $recentDamageJobs = (clone $unackedBase)
            ->with([
                'company:id,name',
                'pickupLocation:id,company_name,city',
                'deliveryLocation:id,company_name,city',
                'brand:id,name',
            ])
            ->withCount(['documents as damage_photos_count' => function ($q) {
                $q->where('category', JobDocument::CATEGORY_DAMAGE_PHOTO);
            }])
            ->orderByDesc('updated_at')
            ->limit(6)
            ->get();

        $recentOrders = Job::with(['company:id,name,workflow_type', 'pickupLocation:id,company_name,city', 'deliveryLocation:id,company_name,city', 'brand:id,name'])
            ->whereIn('status', Job::PHASE1_STATUSES)
            ->orderByDesc('created_at')
            ->limit(8)
            ->get();

        return compact(
            'newOrders',
            'pendingVerification',
            'readyToPlan',
            'inFlight',
            'deliveredToday',
            'liveCounts',
            'liveMovements',
            'invoicedTodayValue',
            'invoicedTodayCount',
            'invoicedMtdValue',
            'invoicedMtdCount',
            'marginMtd',
            'marginPct',
            'awaitingCount',
            'awaitingValue',
            'openDamageCount',
            'pendingReleaseCount',
            'recentDamageJobs',
            'recentOrders',
        );
    }
};

?>

@php
    // Small money formatter — R prefix, thousands sep, no decimals for
    // stat tiles so big numbers stay scannable. Handled inline so we
    // don't hit the helper autoload cost 8 times per render.
    $money = fn($v) => 'R' . number_format((float) $v, 0);
@endphp

<div wire:poll.60s>
    <x-slot:header>Dashboard</x-slot:header>

    {{-- Hero --}}
    <x-page-header
        eyebrow="Control · Dispatch · Deliver"
        title="Operations overview"
        subtitle="Live money, live movements, and the things ops has to push next.">
        <x-slot:actions>
            <x-button :href="route('admin.planning')" variant="primary" size="md">
                <x-slot:icon>
                    <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/></svg>
                </x-slot:icon>
                Planning Queue
            </x-button>
            <x-button :href="route('admin.dispatch')" variant="dark" size="md">
                <x-slot:icon>
                    <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/></svg>
                </x-slot:icon>
                Dispatch Board
            </x-button>
        </x-slot:actions>
    </x-page-header>

    {{-- Revenue row
         This is the money line. Invoiced today / MTD / awaiting /
         margin — the four numbers the owner actually wants on the
         screen when they walk past. --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-3">
        <x-stat-card
            label="Invoiced Today"
            :value="$money($invoicedTodayValue)"
            color="emerald"
            :helper="$invoicedTodayCount . ' ' . \Illuminate\Support\Str::plural('invoice', $invoicedTodayCount)"
            helperColor="emerald">
            <x-slot:icon>
                <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
            </x-slot:icon>
        </x-stat-card>

        <x-stat-card
            label="Invoiced MTD"
            :value="$money($invoicedMtdValue)"
            color="emerald"
            :helper="$invoicedMtdCount . ' ' . \Illuminate\Support\Str::plural('invoice', $invoicedMtdCount) . ' · since ' . now()->startOfMonth()->format('d M')"
            helperColor="slate">
            <x-slot:icon>
                <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>
            </x-slot:icon>
        </x-stat-card>

        <x-stat-card
            label="Margin MTD"
            :value="$money($marginMtd)"
            :color="$marginPct >= 25 ? 'emerald' : ($marginPct >= 10 ? 'amber' : 'slate')"
            :helper="$marginPct . '% gross'"
            :helperColor="$marginPct >= 25 ? 'emerald' : ($marginPct >= 10 ? 'amber' : 'slate')">
            <x-slot:icon>
                <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20"/><path d="m5 12 7-7 7 7"/></svg>
            </x-slot:icon>
        </x-stat-card>

        <x-stat-card
            label="Awaiting Invoicing"
            :value="$money($awaitingValue)"
            :color="$awaitingCount > 0 ? 'amber' : 'slate'"
            :helper="$awaitingCount > 0 ? $awaitingCount . ' ' . \Illuminate\Support\Str::plural('delivery', $awaitingCount) . ' to invoice' : 'All invoiced'"
            :helperColor="$awaitingCount > 0 ? 'amber' : 'slate'"
            :href="route('admin.deliveries', ['range' => 'all'])">
            <x-slot:icon>
                <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </x-slot:icon>
        </x-stat-card>
    </div>

    {{-- Operations row --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
        <x-stat-card
            label="New Bookings"
            :value="$newOrders"
            color="blue"
            :helper="$pendingVerification > 0 ? $pendingVerification . ' to verify' : 'Queue clear'"
            :helperColor="$pendingVerification > 0 ? 'amber' : 'slate'"
            :href="route('admin.vehicles.index', ['bucket' => 'open'])">
            <x-slot:icon>
                <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
            </x-slot:icon>
        </x-stat-card>

        <x-stat-card
            label="Ready to Plan"
            :value="$readyToPlan"
            :color="$readyToPlan > 0 ? 'purple' : 'slate'"
            :helper="$readyToPlan > 0 ? 'Assign drivers' : 'Queue clear'"
            :helperColor="$readyToPlan > 0 ? 'purple' : 'slate'"
            :href="route('admin.planning')" />

        <x-stat-card
            label="In Flight"
            :value="$inFlight"
            :color="$inFlight > 0 ? 'blue' : 'slate'"
            helper="Drivers on the road"
            helperColor="slate"
            :href="route('admin.vehicles.index', ['bucket' => 'live'])" />

        <x-stat-card
            label="Delivered Today"
            :value="$deliveredToday"
            color="emerald"
            :helper="$deliveredToday > 0 ? 'Ready for invoicing' : null"
            helperColor="slate"
            :href="route('admin.deliveries', ['range' => 'today'])" />
    </div>

    {{-- Live movements board
         The hero piece. Every vehicle currently moving, with driver,
         route, status, and time-since-dispatch. Rows are clickable,
         going straight to the order detail. Empty state says so. --}}
    <div class="mb-6 rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="flex items-center justify-between gap-3 border-b border-slate-100 px-6 py-4">
            <div class="flex items-center gap-3">
                <span class="inline-flex items-center gap-2 text-[10px] font-semibold uppercase tracking-[0.25em] text-blue-600">
                    <span class="h-1.5 w-1.5 rounded-full bg-blue-500 {{ $liveMovements->isNotEmpty() ? 'node-pulse' : 'opacity-30' }}"></span>
                    Live movements
                </span>
                <span class="text-[11px] text-slate-500 tabular-nums">
                    {{ $liveMovements->count() }} {{ \Illuminate\Support\Str::plural('vehicle', $liveMovements->count()) }} on the road
                </span>
            </div>
            <a href="{{ route('admin.vehicles.index', ['bucket' => 'live']) }}" class="text-xs font-semibold text-slate-600 hover:text-slate-900 inline-flex items-center gap-1 transition-colors">
                Open live fleet
                <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 7h10v10"/><path d="M7 17 17 7"/></svg>
            </a>
        </div>

        {{-- Pipeline dots strip — gives the "3 active, 19 in transit"
             feel at a glance. Clickable deep links into the live bucket
             pre-filtered by status. --}}
        <div class="grid grid-cols-3 divide-x divide-slate-100 border-b border-slate-100">
            @php
                $nodes = [
                    ['label' => 'Driver Assigned',       'key' => 'driver_assigned', 'dot' => 'bg-purple-500'],
                    ['label' => 'Arrived at Pickup',     'key' => 'collected',       'dot' => 'bg-teal-500'],
                    ['label' => 'In Transit',            'key' => 'in_transit',      'dot' => 'bg-orange-500'],
                ];
            @endphp
            @foreach($nodes as $node)
                <div class="px-6 py-3 flex items-center justify-between gap-3">
                    <div class="flex items-center gap-2.5 min-w-0">
                        <span class="h-2 w-2 rounded-full {{ $node['dot'] }} {{ ($liveCounts[$node['key']] ?? 0) > 0 ? 'node-pulse' : 'opacity-30' }}"></span>
                        <span class="text-xs font-medium text-slate-600 truncate">{{ $node['label'] }}</span>
                    </div>
                    <span class="text-lg font-semibold tabular-nums text-slate-900">{{ $liveCounts[$node['key']] ?? 0 }}</span>
                </div>
            @endforeach
        </div>

        @if($liveMovements->isEmpty())
            <div class="px-6 py-10 text-center">
                <div class="mx-auto mb-3 flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-slate-400">
                    <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/></svg>
                </div>
                <p class="text-sm font-medium text-slate-900">No vehicles moving right now</p>
                <p class="text-xs text-slate-500 mt-0.5">The board will light up as soon as a driver is dispatched.</p>
            </div>
        @else
        <ul class="divide-y divide-slate-100">
            @foreach($liveMovements as $mov)
                @php
                    $statusColor = match ($mov->status) {
                        Job::STATUS_IN_TRANSIT                  => 'bg-orange-500',
                        Job::STATUS_COLLECTED                   => 'bg-teal-500',
                        Job::STATUS_DRIVER_ASSIGNED, Job::STATUS_READY_FOR_COLLECTION => 'bg-purple-500',
                        default                                 => 'bg-slate-400',
                    };
                    $anchor = $mov->collected_at ?? $mov->assigned_at ?? $mov->updated_at;
                    $pickupCity   = $mov->pickupLocation?->city ?? $mov->pickupLocation?->company_name ?? '—';
                    $deliveryCity = $mov->deliveryLocation?->city ?? $mov->deliveryLocation?->company_name ?? '—';
                @endphp
                <li>
                    <a href="{{ route('admin.orders.show', $mov) }}"
                       class="flex items-center gap-4 px-6 py-3 hover:bg-slate-50/60 transition-colors">
                        <span class="h-2 w-2 shrink-0 rounded-full {{ $statusColor }} node-pulse"></span>
                        <div class="min-w-0 flex-1 grid grid-cols-12 gap-3 items-center">
                            <div class="col-span-12 sm:col-span-3 min-w-0">
                                <div class="text-sm font-semibold text-slate-900 truncate">
                                    {{ $mov->job_number ?? ('#' . $mov->id) }}
                                </div>
                                <div class="text-[11px] text-slate-500 truncate">{{ $mov->company?->name ?? '—' }}</div>
                            </div>
                            <div class="col-span-6 sm:col-span-3 min-w-0">
                                <div class="text-sm text-slate-700 truncate">
                                    {{ $mov->driver?->name ?? '— Unassigned' }}
                                </div>
                                <div class="text-[11px] text-slate-400 truncate">
                                    {{ trim(($mov->brand?->name ?? '') . ' ' . ($mov->model_name ?? '')) ?: '—' }}
                                    @if($mov->registration) · <span class="font-mono uppercase">{{ $mov->registration }}</span>@endif
                                </div>
                            </div>
                            <div class="col-span-6 sm:col-span-4 min-w-0">
                                <div class="flex items-center gap-1.5 text-xs text-slate-600">
                                    <span class="truncate">{{ $pickupCity }}</span>
                                    <svg viewBox="0 0 24 24" class="h-3 w-3 text-slate-300 shrink-0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                                    <span class="truncate">{{ $deliveryCity }}</span>
                                </div>
                            </div>
                            <div class="col-span-12 sm:col-span-2 flex items-center justify-end gap-2">
                                <x-status-badge :status="$mov->status" />
                                @if($anchor)
                                    <span class="hidden sm:inline text-[11px] text-slate-400 tabular-nums shrink-0">
                                        {{ $anchor->diffForHumans(['short' => true]) }}
                                    </span>
                                @endif
                            </div>
                        </div>
                    </a>
                </li>
            @endforeach
        </ul>
        @endif
    </div>

    {{-- Damage incidents strip
         Only renders when there is damage to show — no point eating
         vertical space on a clean week. Each row links directly to
         the order page's #damage-section so ops can review photos +
         download the PDF in one click. --}}
    @if($recentDamageJobs->isNotEmpty())
    <div class="mb-6 rounded-2xl border border-rose-200 bg-white shadow-sm overflow-hidden">
        <div class="flex items-center justify-between gap-3 border-b border-rose-100 bg-rose-50/60 px-6 py-4">
            <div class="flex items-center gap-3">
                <span class="inline-flex items-center gap-2 text-[10px] font-semibold uppercase tracking-[0.25em] text-rose-700">
                    <span class="h-1.5 w-1.5 rounded-full bg-rose-500 node-pulse"></span>
                    Damage incidents
                </span>
                <span class="text-[11px] text-rose-700/70">{{ $recentDamageJobs->count() }} most recent</span>
            </div>
            <a href="{{ route('admin.damage') }}" class="text-xs font-semibold text-rose-700 hover:text-rose-900 inline-flex items-center gap-1 transition-colors">
                Open damage reports
                <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 7h10v10"/><path d="M7 17 17 7"/></svg>
            </a>
        </div>
        @if(session('dashboard_ack'))
            <div class="px-6 py-2 text-[11px] text-emerald-700 bg-emerald-50 border-b border-emerald-100">
                {{ session('dashboard_ack') }}
            </div>
        @endif
        <ul class="divide-y divide-rose-100">
            @foreach($recentDamageJobs as $dmgJob)
            <li class="flex items-center gap-4 px-6 py-3 hover:bg-rose-50/60 transition-colors">
                <a href="{{ route('admin.orders.show', $dmgJob) }}#damage-section"
                   class="flex items-center gap-4 min-w-0 flex-1">
                    <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-rose-100 text-rose-700 shrink-0">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="text-sm font-semibold text-slate-900">{{ $dmgJob->job_number ?? ('#' . $dmgJob->id) }}</span>
                            <span class="inline-flex items-center rounded-full bg-rose-100 border border-rose-200 px-1.5 py-0.5 text-[10px] font-semibold text-rose-800">
                                {{ $dmgJob->damage_photos_count }} {{ $dmgJob->damage_photos_count === 1 ? 'photo' : 'photos' }}
                            </span>
                            <x-status-badge :status="$dmgJob->status" />
                        </div>
                        <p class="text-xs text-slate-500 mt-0.5 truncate">
                            {{ $dmgJob->company?->name ?? '—' }}
                            @if($dmgJob->brand || $dmgJob->model_name)
                                &middot; {{ $dmgJob->brand?->name }} {{ $dmgJob->model_name }}
                            @endif
                            @if($dmgJob->registration)
                                &middot; {{ strtoupper($dmgJob->registration) }}
                            @endif
                        </p>
                    </div>
                    <div class="hidden sm:block text-right text-[11px] text-slate-400 shrink-0">
                        {{ $dmgJob->updated_at?->diffForHumans() }}
                    </div>
                </a>
                <button type="button"
                        wire:click="dismissDamage({{ $dmgJob->id }})"
                        wire:confirm="Dismiss this incident from the dashboard? It stays available in /admin/damage."
                        title="Dismiss from dashboard"
                        class="shrink-0 inline-flex items-center justify-center h-7 w-7 rounded-md text-slate-400 hover:text-rose-700 hover:bg-rose-100 transition-colors">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </button>
            </li>
            @endforeach
        </ul>
    </div>
    @endif

    {{-- Recent orders --}}
    <div>
        <x-card title="Recent Orders" subtitle="Latest bookings across all customers" :padding="false">
            <x-slot:actions>
                <a href="{{ route('admin.orders.index') }}" class="text-xs font-semibold text-slate-600 hover:text-slate-900 inline-flex items-center gap-1 transition-colors">
                    View all
                    <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 7h10v10"/><path d="M7 17 17 7"/></svg>
                </a>
            </x-slot:actions>

            @if($recentOrders->isEmpty())
                <x-empty-state
                    title="No orders yet"
                    description="Orders will appear here as soon as customers submit bookings.">
                    <x-slot:icon>
                        <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg>
                    </x-slot:icon>
                </x-empty-state>
            @else
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="bg-slate-50/60">
                        <tr>
                            <th class="px-6 py-3 text-left text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-500">Order</th>
                            <th class="px-6 py-3 text-left text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-500">Customer</th>
                            <th class="px-6 py-3 text-left text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-500">Vehicle · VIN</th>
                            <th class="px-6 py-3 text-left text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-500">Route</th>
                            <th class="px-6 py-3 text-left text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-500">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($recentOrders as $job)
                        <tr class="hover:bg-slate-50/60 cursor-pointer transition-colors group"
                            onclick="window.location='{{ route('admin.orders.show', $job) }}'">
                            <td class="px-6 py-3.5">
                                <div class="text-sm font-semibold text-slate-900 group-hover:text-blue-700 transition-colors">{{ $job->job_number ?? '—' }}</div>
                                <div class="text-[11px] text-slate-400">{{ $job->created_at->diffForHumans(['short' => true]) }}</div>
                            </td>
                            <td class="px-6 py-3.5">
                                <div class="flex items-center gap-2">
                                    <span class="text-sm text-slate-700 truncate max-w-[140px]">{{ $job->company?->name ?? '—' }}</span>
                                    @if($job->company?->workflow_type === 'faw')
                                        <x-badge color="amber" size="sm">FAW</x-badge>
                                    @endif
                                </div>
                            </td>
                            <td class="px-6 py-3.5">
                                <div class="text-sm text-slate-700 truncate max-w-[160px]">{{ trim(($job->brand?->name ?? '') . ' ' . ($job->model_name ?? '')) ?: '—' }}</div>
                                <div class="text-[11px] font-mono uppercase text-slate-400">{{ $job->vin ?: '—' }}</div>
                            </td>
                            <td class="px-6 py-3.5">
                                <div class="flex items-center gap-1.5 text-xs text-slate-600">
                                    <span class="truncate max-w-[80px]">{{ $job->pickupLocation?->city ?? $job->pickupLocation?->company_name ?? '—' }}</span>
                                    <svg viewBox="0 0 24 24" class="h-3 w-3 text-slate-300 shrink-0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                                    <span class="truncate max-w-[80px]">{{ $job->deliveryLocation?->city ?? $job->deliveryLocation?->company_name ?? '—' }}</span>
                                </div>
                            </td>
                            <td class="px-6 py-3.5">
                                <x-status-badge :status="$job->status" />
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif
        </x-card>
    </div>
</div>
