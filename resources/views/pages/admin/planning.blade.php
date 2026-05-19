<?php

use App\Models\Job;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    // 'queue' = orders waiting to be planned, 'drivers' = who is where.
    // URL-bound so dispatch can bookmark the drivers view directly.
    #[Url(as: 'tab', except: 'queue')] public string $tab = 'queue';

    public string $search = '';
    public string $driverSearch = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedDriverSearch(): void
    {
        // driver workload tab is not paginated but keep method for symmetry
        // with future pagination if the fleet grows.
    }

    public function switchTab(string $tab): void
    {
        $this->tab = in_array($tab, ['queue', 'drivers'], true) ? $tab : 'queue';
    }

    public function planJob(int $jobId): void
    {
        $job = Job::findOrFail($jobId);

        if (!$job->canTransitionTo(Job::STATUS_PLANNED)) {
            session()->flash('error', 'This job cannot be moved to planned status.');
            return;
        }

        $job->transitionTo(Job::STATUS_PLANNED);

        session()->flash('success', "Order {$job->job_number} has been moved to planned.");
    }

    /**
     * Pre-collection statuses that the dispatcher expects to see a
     * driver attached to.  CONFIRMED is deliberately excluded — those
     * orders haven't been planned yet and live in the "Ready to Plan"
     * section above.  Anything in here without a driver_user_id is a
     * planning slip and surfaces in the "Awaiting Driver" section.
     */
    private const AWAITING_DRIVER_STATUSES = [
        Job::STATUS_PLANNED,
        Job::STATUS_DRIVER_ASSIGNED,
        Job::STATUS_READY_FOR_COLLECTION,
        Job::STATUS_ASSIGNED, // legacy pre-Phase-1 alias
    ];

    /**
     * Shared search clause for both queue tables.  Uses ilike so the
     * search actually works on Postgres (the production DB) — plain
     * LIKE is case-sensitive there and would silently miss matches.
     */
    private function applyQueueSearch($query): void
    {
        if ($this->search === '') {
            return;
        }
        $s = trim($this->search);
        $query->where(function ($q) use ($s) {
            $q->where('job_number', 'ilike', "%{$s}%")
                ->orWhere('vin', 'ilike', "%{$s}%")
                ->orWhere('model_name', 'ilike', "%{$s}%")
                ->orWhereHas('brand', fn ($c) => $c->where('name', 'ilike', "%{$s}%"))
                ->orWhereHas('company', fn ($c) => $c->where('name', 'ilike', "%{$s}%"))
                ->orWhereHas('pickupLocation', fn ($c) => $c->where('company_name', 'ilike', "%{$s}%"))
                ->orWhereHas('deliveryLocation', fn ($c) => $c->where('company_name', 'ilike', "%{$s}%"));
        });
    }

    /**
     * Buckets for the driver-workload tab. A driver can technically have
     * more than one active job (multi-leg) but we pick the most advanced
     * status to decide which bucket they fall into; that matches how a
     * dispatcher thinks about the phone call ("where's Joe right now?").
     */
    private function driverWorkload(): array
    {
        // Statuses that mean the driver is actively carrying the keys.
        $onRoadStatuses = [
            Job::STATUS_COLLECTED, Job::STATUS_IN_TRANSIT, Job::STATUS_IN_PROGRESS,
        ];
        $collectingStatuses = [
            Job::STATUS_DRIVER_ASSIGNED, Job::STATUS_READY_FOR_COLLECTION, Job::STATUS_ASSIGNED,
        ];

        $activeStatuses = array_merge($onRoadStatuses, $collectingStatuses);

        // Pull every driver with role 'driver' plus their active jobs in
        // one go. We eager-load the two location names because the card
        // needs both ends of the leg; driverProfile supplies base_location
        // and tracker_id for the green pill.
        $drivers = User::query()
            ->whereHas('roles', fn ($r) => $r->where('slug', 'driver'))
            ->where('is_active', true)
            ->with([
                'driverProfile:user_id,base_location,tracker_id,cellphone,trade_plate',
                'assignedJobs' => function ($q) use ($activeStatuses) {
                    $q->whereIn('status', $activeStatuses)
                        ->with([
                            'brand:id,name',
                            'pickupLocation:id,company_name,city',
                            'deliveryLocation:id,company_name,city',
                            'company:id,name',
                        ])
                        ->orderByRaw("CASE status
                            WHEN 'in_transit' THEN 1
                            WHEN 'collected' THEN 2
                            WHEN 'in_progress' THEN 3
                            WHEN 'ready_for_collection' THEN 4
                            WHEN 'driver_assigned' THEN 5
                            WHEN 'assigned' THEN 6
                            ELSE 99 END")
                        ->orderBy('scheduled_date');
                },
            ])
            ->when($this->driverSearch !== '', function ($q) {
                $s = trim($this->driverSearch);
                $q->where(function ($qq) use ($s) {
                    $qq->where('name', 'ilike', "%{$s}%")
                        ->orWhere('phone', 'ilike', "%{$s}%")
                        ->orWhereHas('driverProfile', fn($p) =>
                            $p->where('base_location', 'ilike', "%{$s}%")
                              ->orWhere('tracker_id', 'ilike', "%{$s}%")
                              ->orWhere('trade_plate', 'ilike', "%{$s}%"));
                });
            })
            ->orderBy('name')
            ->get(['id', 'name', 'phone']);

        $onRoad     = collect();
        $collecting = collect();
        $idle       = collect();

        foreach ($drivers as $d) {
            // Split the driver's active jobs into primary (what we pin to
            // the card) and "others" (a small list underneath).
            $onRoadJob     = $d->assignedJobs->firstWhere(fn($j) => in_array($j->status, $onRoadStatuses, true));
            $collectingJob = $d->assignedJobs->firstWhere(fn($j) => in_array($j->status, $collectingStatuses, true));

            if ($onRoadJob) {
                $d->primary_job = $onRoadJob;
                $d->bucket_status = 'on_road';
                $onRoad->push($d);
            } elseif ($collectingJob) {
                $d->primary_job = $collectingJob;
                $d->bucket_status = 'collecting';
                $collecting->push($d);
            } else {
                $d->primary_job = null;
                $d->bucket_status = 'idle';
                $idle->push($d);
            }
        }

        return [
            'onRoadDrivers'     => $onRoad,
            'collectingDrivers' => $collecting,
            'idleDrivers'       => $idle,
            'activeCount'       => $onRoad->count() + $collecting->count(),
            'idleCount'         => $idle->count(),
            'totalDrivers'      => $drivers->count(),
        ];
    }

    public function with(): array
    {
        $payload = [];

        if ($this->tab === 'queue') {
            $eagerLoads = [
                'company:id,name',
                'pickupLocation:id,company_name',
                'deliveryLocation:id,company_name',
                'brand:id,name',
            ];

            // Section 1: orders that have been confirmed but not yet
            // planned.  These are the original "Planning Queue" rows.
            $jobs = Job::with($eagerLoads)
                ->where('status', Job::STATUS_CONFIRMED)
                ->tap(fn ($q) => $this->applyQueueSearch($q))
                ->orderBy('scheduled_date')
                ->orderBy('created_at')
                ->paginate(25, ['*'], 'planPage');

            // Section 2: orders that *have* been planned (or beyond)
            // but never had a driver attached.  Without this list a
            // dispatcher who clicks Plan and forgets to assign a
            // driver has no UI surface that flags it — the order
            // just goes silent until somebody spots it on the wall
            // display.  Independent paginator so paging one list
            // doesn't drop the other off-screen.
            $awaitingDriver = Job::with($eagerLoads)
                ->whereIn('status', self::AWAITING_DRIVER_STATUSES)
                ->whereNull('driver_user_id')
                ->tap(fn ($q) => $this->applyQueueSearch($q))
                ->orderBy('scheduled_date')
                ->orderBy('created_at')
                ->paginate(25, ['*'], 'driverPage');

            $payload['jobs']           = $jobs;
            $payload['awaitingDriver'] = $awaitingDriver;
        } else {
            $payload = array_merge($payload, $this->driverWorkload());
        }

        // Always supply the headline counters so the tab badges are
        // accurate regardless of which tab is currently rendered.
        // queueCount is the sum of both queue sections so dispatch
        // sees a single "needs my attention" number.
        $payload['toPlanCount']        = Job::where('status', Job::STATUS_CONFIRMED)->count();
        $payload['awaitingDriverCount'] = Job::whereIn('status', self::AWAITING_DRIVER_STATUSES)
            ->whereNull('driver_user_id')
            ->count();
        $payload['queueCount']         = $payload['toPlanCount'] + $payload['awaitingDriverCount'];
        $payload['driverActive']       = Job::whereIn('status', [
            Job::STATUS_DRIVER_ASSIGNED, Job::STATUS_READY_FOR_COLLECTION, Job::STATUS_ASSIGNED,
            Job::STATUS_COLLECTED, Job::STATUS_IN_TRANSIT, Job::STATUS_IN_PROGRESS,
        ])->whereNotNull('driver_user_id')->distinct('driver_user_id')->count('driver_user_id');

        return $payload;
    }
};

?>

<div>
    <x-slot:header>Planning</x-slot:header>

    @if(session('success'))
    <div class="mb-4 rounded-lg bg-green-50 border border-green-200 p-4 text-sm text-green-800">
        {{ session('success') }}
    </div>
    @endif

    @if(session('error'))
    <div class="mb-4 rounded-lg bg-red-50 border border-red-200 p-4 text-sm text-red-800">
        {{ session('error') }}
    </div>
    @endif

    {{-- ============================================================ --}}
    {{-- Tabs                                                          --}}
    {{-- ============================================================ --}}
    <div class="mb-5 flex items-center gap-1 rounded-xl bg-slate-100 p-1 w-full sm:w-fit">
        <button wire:click="switchTab('queue')" type="button"
            class="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold transition
            {{ $tab === 'queue' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900' }}">
            <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/></svg>
            Planning Queue
            <span class="rounded-full px-1.5 py-0.5 text-[10px] font-bold tabular-nums
                {{ $tab === 'queue' ? 'bg-blue-100 text-blue-700' : 'bg-slate-200 text-slate-600' }}">{{ $queueCount }}</span>
        </button>
        <button wire:click="switchTab('drivers')" type="button"
            class="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold transition
            {{ $tab === 'drivers' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900' }}">
            <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            Driver Workload
            <span class="rounded-full px-1.5 py-0.5 text-[10px] font-bold tabular-nums
                {{ $tab === 'drivers' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600' }}">{{ $driverActive }}</span>
        </button>
    </div>

    {{-- ============================================================ --}}
    {{-- TAB: Planning Queue (existing behaviour)                      --}}
    {{-- ============================================================ --}}
    @if($tab === 'queue')
        <div class="mb-6">
            <div class="relative max-w-md">
                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                    <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                </div>
                <input
                    wire:model.live.debounce.300ms="search"
                    type="text"
                    placeholder="Search by order #, VIN, make/model, company, or route..."
                    class="block w-full rounded-lg border border-gray-300 bg-white py-2.5 pl-10 pr-4 text-sm text-gray-900 placeholder-gray-400 focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                />
            </div>
        </div>

        {{-- ===================== Section 1: Ready to plan ===================== --}}
        <div class="bg-white rounded-xl shadow-sm border border-gray-200">
            <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <h2 class="text-lg font-semibold text-gray-900">Confirmed Orders — Ready to Plan</h2>
                    <span class="rounded-full bg-blue-100 text-blue-700 px-2 py-0.5 text-[11px] font-bold tabular-nums">{{ $toPlanCount }}</span>
                </div>
                <span class="text-sm text-gray-500">{{ $jobs->total() }} {{ Str::plural('order', $jobs->total()) }} matching</span>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Order #</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Make / Model</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">VIN</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Pickup</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Delivery</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Requested Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Created</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @forelse($jobs as $job)
                        <tr class="hover:bg-gray-50" wire:key="plan-{{ $job->id }}">
                            <td class="px-6 py-4 text-sm font-medium text-blue-600">
                                <a href="{{ route('admin.orders.show', $job) }}" class="hover:underline">{{ $job->job_number ?? '—' }}</a>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">{{ $job->company?->name ?? '—' }}</td>
                            <td class="px-6 py-4 text-sm text-gray-900">{{ $job->brand?->name }} {{ $job->model_name }}</td>
                            <td class="px-6 py-4 text-sm font-mono text-gray-600">{{ $job->vin ?: '—' }}</td>
                            <td class="px-6 py-4 text-sm text-gray-500">{{ $job->pickupLocation?->company_name ?? '—' }}</td>
                            <td class="px-6 py-4 text-sm text-gray-500">{{ $job->deliveryLocation?->company_name ?? '—' }}</td>
                            <td class="px-6 py-4 text-sm text-gray-500">{{ $job->scheduled_date?->format('d M Y') ?? '—' }}</td>
                            <td class="px-6 py-4 text-sm text-gray-500">{{ $job->created_at->format('d M Y') }}</td>
                            <td class="px-6 py-4 text-right">
                                <button
                                    wire:click="planJob({{ $job->id }})"
                                    wire:confirm="Move order {{ $job->job_number }} to planned?"
                                    class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3.5 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-blue-700 transition-colors"
                                >
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/></svg>
                                    Plan
                                </button>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="9" class="px-6 py-12 text-center text-sm text-gray-500">No confirmed orders waiting to be planned.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($jobs->hasPages())
            <div class="px-6 py-4 border-t border-gray-200">
                {{ $jobs->links() }}
            </div>
            @endif
        </div>

        {{-- ===================== Section 2: Awaiting driver ===================== --}}
        <div class="mt-6 bg-white rounded-xl shadow-sm border border-amber-200">
            <div class="px-6 py-4 border-b border-amber-200 bg-amber-50/40 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <span class="inline-flex h-7 w-7 items-center justify-center rounded-lg bg-amber-100 text-amber-700">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/><path d="m22 12-3 3-1.5-1.5"/></svg>
                    </span>
                    <h2 class="text-lg font-semibold text-gray-900">Planned Orders — Awaiting Driver</h2>
                    <span class="rounded-full bg-amber-100 text-amber-800 px-2 py-0.5 text-[11px] font-bold tabular-nums">{{ $awaitingDriverCount }}</span>
                </div>
                <span class="text-sm text-gray-500">{{ $awaitingDriver->total() }} {{ Str::plural('order', $awaitingDriver->total()) }} matching</span>
            </div>
            <div class="px-6 pt-3 pb-2 text-xs text-amber-800/80">
                These orders have been planned (or beyond) but no driver has been attached yet.
                Click the order number to assign one on the order detail page.
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Order #</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Make / Model</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">VIN</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Pickup</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Delivery</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Scheduled</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @forelse($awaitingDriver as $job)
                        <tr class="hover:bg-amber-50/50" wire:key="awaiting-{{ $job->id }}">
                            <td class="px-6 py-4 text-sm font-medium text-blue-600">
                                <a href="{{ route('admin.orders.show', $job) }}" class="hover:underline">{{ $job->job_number ?? '—' }}</a>
                            </td>
                            <td class="px-6 py-4 text-xs">
                                <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 font-semibold uppercase tracking-wide text-amber-800">
                                    {{ \App\Models\Job::PHASE1_STATUS_LABELS[$job->status] ?? Str::title(str_replace('_', ' ', $job->status)) }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">{{ $job->company?->name ?? '—' }}</td>
                            <td class="px-6 py-4 text-sm text-gray-900">{{ $job->brand?->name }} {{ $job->model_name }}</td>
                            <td class="px-6 py-4 text-sm font-mono text-gray-600">{{ $job->vin ?: '—' }}</td>
                            <td class="px-6 py-4 text-sm text-gray-500">{{ $job->pickupLocation?->company_name ?? '—' }}</td>
                            <td class="px-6 py-4 text-sm text-gray-500">{{ $job->deliveryLocation?->company_name ?? '—' }}</td>
                            <td class="px-6 py-4 text-sm text-gray-500">{{ $job->scheduled_date?->format('d M Y') ?? '—' }}</td>
                            <td class="px-6 py-4 text-right">
                                <a href="{{ route('admin.orders.show', $job) }}"
                                   class="inline-flex items-center gap-1.5 rounded-lg bg-amber-600 px-3.5 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-amber-700 transition-colors">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                    Assign Driver
                                </a>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="9" class="px-6 py-12 text-center text-sm text-gray-500">Every planned order has a driver attached. Nice.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($awaitingDriver->hasPages())
            <div class="px-6 py-4 border-t border-gray-200">
                {{ $awaitingDriver->links() }}
            </div>
            @endif
        </div>

    @else

    {{-- ============================================================ --}}
    {{-- TAB: Driver Workload                                          --}}
    {{-- ============================================================ --}}

        {{-- Summary strip --}}
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-5">
            <div class="rounded-xl border border-blue-200 bg-blue-50 p-4">
                <p class="text-[10px] font-semibold uppercase tracking-wide text-blue-700">On the road</p>
                <p class="mt-0.5 text-2xl font-bold text-blue-900 tabular-nums">{{ $onRoadDrivers->count() }}</p>
                <p class="text-[11px] text-blue-700/70">carrying keys right now</p>
            </div>
            <div class="rounded-xl border border-indigo-200 bg-indigo-50 p-4">
                <p class="text-[10px] font-semibold uppercase tracking-wide text-indigo-700">Collecting</p>
                <p class="mt-0.5 text-2xl font-bold text-indigo-900 tabular-nums">{{ $collectingDrivers->count() }}</p>
                <p class="text-[11px] text-indigo-700/70">en route to pickup</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                <p class="text-[10px] font-semibold uppercase tracking-wide text-slate-600">Idle</p>
                <p class="mt-0.5 text-2xl font-bold text-slate-900 tabular-nums">{{ $idleDrivers->count() }}</p>
                <p class="text-[11px] text-slate-500">available for assignment</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4">
                <p class="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Total fleet</p>
                <p class="mt-0.5 text-2xl font-bold text-slate-900 tabular-nums">{{ $totalDrivers }}</p>
                <p class="text-[11px] text-slate-500">with driver role</p>
            </div>
        </div>

        {{-- Search --}}
        <div class="mb-5 relative max-w-md">
            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
            </div>
            <input
                wire:model.live.debounce.300ms="driverSearch"
                type="text"
                placeholder="Search driver name, phone, base, tracker or trade plate..."
                class="block w-full rounded-lg border border-gray-300 bg-white py-2.5 pl-10 pr-4 text-sm text-gray-900 placeholder-gray-400 focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
            />
        </div>

        @php
            // Shared card renderer — rendered inline via a local closure is
            // not available in Blade, so we define the markup three times
            // via @include of a partial would be ideal but keeping it in
            // this file means one less moving part. We use an inline
            // component in-place below via repeated markup.

            $renderDriverCard = null; // placeholder so the variable exists
        @endphp

        {{-- ============================== On the road ============================== --}}
        @if($onRoadDrivers->isNotEmpty())
            <div class="mb-6">
                <h3 class="mb-2 flex items-center gap-2 text-sm font-semibold text-blue-900">
                    <span class="flex h-2.5 w-2.5 rounded-full bg-blue-500 animate-pulse"></span>
                    On the road
                    <span class="text-xs font-medium text-blue-700/70">· {{ $onRoadDrivers->count() }}</span>
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
                    @foreach($onRoadDrivers as $driver)
                        @include('pages.admin._partials.driver-workload-card', ['driver' => $driver, 'bucket' => 'on_road'])
                    @endforeach
                </div>
            </div>
        @endif

        {{-- ============================== Collecting ============================== --}}
        @if($collectingDrivers->isNotEmpty())
            <div class="mb-6">
                <h3 class="mb-2 flex items-center gap-2 text-sm font-semibold text-indigo-900">
                    <span class="flex h-2.5 w-2.5 rounded-full bg-indigo-500"></span>
                    Collecting
                    <span class="text-xs font-medium text-indigo-700/70">· {{ $collectingDrivers->count() }}</span>
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
                    @foreach($collectingDrivers as $driver)
                        @include('pages.admin._partials.driver-workload-card', ['driver' => $driver, 'bucket' => 'collecting'])
                    @endforeach
                </div>
            </div>
        @endif

        {{-- ============================== Idle ============================== --}}
        <div class="mb-6">
            <h3 class="mb-2 flex items-center gap-2 text-sm font-semibold text-slate-700">
                <span class="flex h-2.5 w-2.5 rounded-full bg-slate-300"></span>
                Idle
                <span class="text-xs font-medium text-slate-500">· {{ $idleDrivers->count() }}</span>
            </h3>
            @if($idleDrivers->isEmpty())
                <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50/60 p-6 text-center text-sm text-slate-500">
                    Every driver is active. No one is idle right now.
                </div>
            @else
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
                    @foreach($idleDrivers as $driver)
                        @include('pages.admin._partials.driver-workload-card', ['driver' => $driver, 'bucket' => 'idle'])
                    @endforeach
                </div>
            @endif
        </div>
    @endif
</div>
