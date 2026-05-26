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
        $this->tab = in_array($tab, ['queue', 'drivers', 'calendar'], true) ? $tab : 'queue';
    }

    /**
     * SLA aging buckets used by the queue badges and the headline counter.
     * "Stale" = a confirmed order that's been sitting for 24+ hours
     * without being planned.  "Critical" tips it into 48+.  Tuned to ops
     * shift cadence -- 24h means "today's batch missed", 48h means "we
     * need an explanation".
     */
    private function ageBadge(\Carbon\CarbonImmutable|\Carbon\Carbon|string|null $confirmedAt): array
    {
        if (!$confirmedAt) {
            return ['label' => null, 'class' => ''];
        }
        $confirmed = \Carbon\Carbon::parse($confirmedAt);
        $hours = $confirmed->diffInHours(now());

        if ($hours >= 48) {
            return ['label' => $confirmed->diffForHumans(now(), \Carbon\CarbonInterface::DIFF_ABSOLUTE) . ' unplanned', 'class' => 'bg-rose-100 text-rose-800 border-rose-300'];
        }
        if ($hours >= 24) {
            return ['label' => $confirmed->diffForHumans(now(), \Carbon\CarbonInterface::DIFF_ABSOLUTE) . ' unplanned', 'class' => 'bg-amber-100 text-amber-800 border-amber-300'];
        }
        return ['label' => null, 'class' => ''];
    }

    /**
     * Calendar data: the next 14 days, with each ProSelver-executed job
     * grouped under its scheduled_date.  Includes both unplanned and
     * planned orders so ops can see the day's full shape at a glance
     * (the queue tab only ever shows actionable items).
     */
    private function calendarData(): array
    {
        $start = now()->startOfDay();
        $end = $start->copy()->addDays(13);

        $jobs = Job::query()
            ->with(['company:id,name', 'pickupLocation:id,company_name', 'deliveryLocation:id,company_name', 'driver:id,name'])
            ->whereBetween('scheduled_date', [$start->toDateString(), $end->toDateString()])
            ->where('executor_type', Job::EXECUTOR_PROSELVER)
            ->whereNotIn('status', [Job::STATUS_CANCELLED, Job::STATUS_COMPLETED])
            ->orderBy('scheduled_date')
            ->get();

        $byDay = $jobs->groupBy(fn ($j) => $j->scheduled_date?->toDateString());

        $days = [];
        for ($i = 0; $i < 14; $i++) {
            $d = $start->copy()->addDays($i);
            $key = $d->toDateString();
            $list = $byDay->get($key, collect());
            $days[] = [
                'date' => $d,
                'is_today' => $d->isToday(),
                'is_weekend' => $d->isWeekend(),
                'jobs' => $list,
                'count' => $list->count(),
                'unplanned' => $list->where('status', Job::STATUS_CONFIRMED)->count(),
                'unassigned' => $list->whereIn('status', self::AWAITING_DRIVER_STATUSES)->whereNull('driver_user_id')->count(),
            ];
        }
        return $days;
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
            //
            // Scoped to ProSelver-executed orders because the planning
            // queue is "what ops needs to dispatch a ProSelver driver
            // for". Dealer-internal / 3rd-party / self-collect bookings
            // are moved by the dealer's own driver / a courier / the
            // customer themselves, so they never need an ops planning
            // touch and would just clutter the queue.
            //
            // Sort by the older-first / oldest-confirmed-first so SLA
            // breaches surface at the top of the list -- you read down,
            // you act on the loudest-shouting badge first.
            $jobs = Job::with($eagerLoads)
                ->where('status', Job::STATUS_CONFIRMED)
                ->where('executor_type', Job::EXECUTOR_PROSELVER)
                ->tap(fn ($q) => $this->applyQueueSearch($q))
                ->orderByRaw('COALESCE(customer_confirmed_at, created_at) asc')
                ->orderBy('scheduled_date')
                ->paginate(25, ['*'], 'planPage');

            // Section 2: orders that *have* been planned (or beyond)
            // but never had a driver attached.  Without this list a
            // dispatcher who clicks Plan and forgets to assign a
            // driver has no UI surface that flags it — the order
            // just goes silent until somebody spots it on the wall
            // display.  Independent paginator so paging one list
            // doesn't drop the other off-screen.
            // Same ProSelver-only scope as Section 1 -- a dealer-internal
            // booking sitting at PLANNED with no driver_user_id is not
            // an ops planning slip, it's the dealer not having picked
            // their own driver yet, and surfaces on their portal not ours.
            $awaitingDriver = Job::with($eagerLoads)
                ->whereIn('status', self::AWAITING_DRIVER_STATUSES)
                ->where('executor_type', Job::EXECUTOR_PROSELVER)
                ->whereNull('driver_user_id')
                ->tap(fn ($q) => $this->applyQueueSearch($q))
                ->orderBy('scheduled_date')
                ->orderBy('created_at')
                ->paginate(25, ['*'], 'driverPage');

            $payload['jobs']           = $jobs;
            $payload['awaitingDriver'] = $awaitingDriver;
        } elseif ($this->tab === 'calendar') {
            $payload['calendarDays'] = $this->calendarData();
        } else {
            $payload = array_merge($payload, $this->driverWorkload());
        }

        // Always supply the headline counters so the tab badges are
        // accurate regardless of which tab is currently rendered.
        // queueCount is the sum of both queue sections so dispatch
        // sees a single "needs my attention" number.
        // Tab badge counts must match the filtered lists above, otherwise
        // the dispatcher sees "5 to plan" but only 3 rows -- the missing
        // 2 are dealer-internal which we deliberately hide on this page.
        $payload['toPlanCount']        = Job::where('status', Job::STATUS_CONFIRMED)
            ->where('executor_type', Job::EXECUTOR_PROSELVER)
            ->count();
        $payload['awaitingDriverCount'] = Job::whereIn('status', self::AWAITING_DRIVER_STATUSES)
            ->where('executor_type', Job::EXECUTOR_PROSELVER)
            ->whereNull('driver_user_id')
            ->count();
        $payload['queueCount']         = $payload['toPlanCount'] + $payload['awaitingDriverCount'];

        // SLA aging: how many confirmed-but-unplanned orders have been
        // sitting for >24h.  Drives the "Stale" headline so a dispatcher
        // can't pretend they don't see the backlog.  Postgres age()
        // would work but a Carbon-side calculation keeps this portable.
        $staleCutoff = now()->subHours(24);
        $payload['staleCount'] = Job::where('status', Job::STATUS_CONFIRMED)
            ->where('executor_type', Job::EXECUTOR_PROSELVER)
            ->where(function ($q) use ($staleCutoff) {
                $q->where('customer_confirmed_at', '<', $staleCutoff)
                  ->orWhere(function ($q2) use ($staleCutoff) {
                      $q2->whereNull('customer_confirmed_at')->where('created_at', '<', $staleCutoff);
                  });
            })
            ->count();
        // driverActive counts how many distinct ProSelver drivers are
        // currently engaged. Dealer-driver jobs aren't part of ProSelver
        // dispatch utilisation, so they're excluded here too.
        $payload['driverActive']       = Job::whereIn('status', [
            Job::STATUS_DRIVER_ASSIGNED, Job::STATUS_READY_FOR_COLLECTION, Job::STATUS_ASSIGNED,
            Job::STATUS_COLLECTED, Job::STATUS_IN_TRANSIT, Job::STATUS_IN_PROGRESS,
        ])->where('executor_type', Job::EXECUTOR_PROSELVER)
          ->whereNotNull('driver_user_id')
          ->distinct('driver_user_id')
          ->count('driver_user_id');

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
        <button wire:click="switchTab('calendar')" type="button"
            class="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold transition
            {{ $tab === 'calendar' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900' }}">
            <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/></svg>
            Calendar
        </button>
    </div>

    {{-- SLA aging headline: only visible when there's something to act on.
         The cutoff is 24h confirmed-without-being-planned -- past that,
         the order has missed its daily planning window. --}}
    @if($staleCount > 0)
        <div class="mb-4 flex items-center gap-3 rounded-lg border-2 border-amber-300 bg-amber-50 px-4 py-2.5 text-sm">
            <svg class="h-5 w-5 shrink-0 text-amber-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
            <div class="min-w-0">
                <strong class="text-amber-900">{{ $staleCount }} order{{ $staleCount === 1 ? '' : 's' }}</strong>
                <span class="text-amber-800">confirmed more than 24 hours ago and still unplanned. They're sorted to the top of the queue below.</span>
            </div>
        </div>
    @endif

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
                        @php
                            // Surface freshly-recalled orders so ops sees these are returns
                            // rather than first-time confirmations.  24h window matches how
                            // long ops typically takes to re-plan after a recall.
                            $recentlyRecalled = $job->recalled_at && $job->recalled_at->gt(now()->subDay());
                            $aging = $this->ageBadge($job->customer_confirmed_at ?? $job->created_at);
                        @endphp
                        <tr class="hover:bg-gray-50 {{ $aging['label'] ? ($aging['class'] === 'bg-rose-100 text-rose-800 border-rose-300' ? 'bg-rose-50/40' : 'bg-amber-50/40') : '' }}" wire:key="plan-{{ $job->id }}">
                            <td class="px-6 py-4 text-sm font-medium text-blue-600">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <a href="{{ route('admin.orders.show', $job) }}" class="hover:underline">{{ $job->job_number ?? '—' }}</a>
                                    @if($aging['label'])
                                        <span class="inline-flex items-center gap-1 rounded-md border px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider {{ $aging['class'] }}"
                                            title="Confirmed {{ ($job->customer_confirmed_at ?? $job->created_at)?->format('D d M H:i') }}">
                                            {{ $aging['label'] }}
                                        </span>
                                    @endif
                                    @if($recentlyRecalled)
                                        <span class="inline-flex items-center gap-1 rounded-md bg-amber-100 text-amber-800 ring-1 ring-amber-200 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider"
                                            title="Recalled {{ $job->recalled_at->diffForHumans() }}@if($job->recall_reason) — {{ $job->recall_reason }}@endif">
                                            <svg class="h-2.5 w-2.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/></svg>
                                            Recalled
                                        </span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">{{ $job->company?->name ?? '—' }}</td>
                            <td class="px-6 py-4 text-sm text-gray-900">{{ $job->brand?->name }} {{ $job->model_name }}</td>
                            <td class="px-6 py-4 text-sm font-mono text-gray-600">{{ $job->vin ?: '—' }}</td>
                            <td class="px-6 py-4 text-sm text-gray-500">{{ $job->pickupLocation?->company_name ?? '—' }}</td>
                            <td class="px-6 py-4 text-sm text-gray-500">{{ $job->deliveryLocation?->company_name ?? '—' }}</td>
                            <td class="px-6 py-4 text-sm text-gray-500">{{ $job->scheduled_date?->format('d M Y') ?? '—' }}</td>
                            <td class="px-6 py-4 text-sm text-gray-500">{{ $job->created_at->format('d M Y') }}</td>
                            <td class="px-6 py-4 text-right">
                                <div class="inline-flex items-center gap-1.5 flex-wrap justify-end">
                                    <a href="{{ route('admin.petty-cash.plans', ['tab' => 'create', 'preselect' => $job->id]) }}"
                                        target="_blank"
                                        title="Open the petty-cash plan with this trip pre-selected; submit for owner sign-off."
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-emerald-300 bg-white px-3 py-1.5 text-xs font-semibold text-emerald-800 hover:bg-emerald-50 transition-colors">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="20" height="12" rx="2"/><path d="M6 12h.01M18 12h.01"/><circle cx="12" cy="12" r="2.5"/></svg>
                                        Petty Cash Plan
                                    </a>
                                    <button
                                        wire:click="planJob({{ $job->id }})"
                                        wire:confirm="Move order {{ $job->job_number }} to planned?"
                                        class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3.5 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-blue-700 transition-colors"
                                    >
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/></svg>
                                        Plan
                                    </button>
                                </div>
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
                                <div class="inline-flex items-center gap-1.5 flex-wrap justify-end">
                                    @if($job->advance_plan_id)
                                        <span class="inline-flex items-center gap-1 rounded-md bg-emerald-50 text-emerald-700 border border-emerald-200 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider" title="Already on plan #{{ $job->advance_plan_id }}">
                                            <svg class="h-2.5 w-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                            on plan
                                        </span>
                                    @else
                                        <a href="{{ route('admin.petty-cash.plans', ['tab' => 'create', 'preselect' => $job->id]) }}"
                                            target="_blank"
                                            title="Add this trip to a petty-cash plan for owner sign-off."
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-emerald-300 bg-white px-3 py-1.5 text-xs font-semibold text-emerald-800 hover:bg-emerald-50 transition-colors">
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="20" height="12" rx="2"/><path d="M6 12h.01M18 12h.01"/><circle cx="12" cy="12" r="2.5"/></svg>
                                            Petty Cash Plan
                                        </a>
                                    @endif
                                    <a href="{{ route('admin.orders.show', $job) }}"
                                       class="inline-flex items-center gap-1.5 rounded-lg bg-amber-600 px-3.5 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-amber-700 transition-colors">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                        Assign Driver
                                    </a>
                                </div>
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

    @elseif($tab === 'calendar')

    {{-- ============================================================ --}}
    {{-- TAB: Calendar — next 14 days at a glance.                     --}}
    {{-- Each day card shows total jobs, with a sub-counter for the    --}}
    {{-- ones that still need attention (unplanned + unassigned).      --}}
    {{-- Click a job pill to jump to its detail page.                  --}}
    {{-- ============================================================ --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-7 gap-3">
            @foreach($calendarDays as $day)
                @php
                    $needsAttention = $day['unplanned'] + $day['unassigned'];
                @endphp
                <div class="rounded-xl border bg-white p-3 min-h-[180px] flex flex-col
                    {{ $day['is_today'] ? 'border-blue-400 ring-2 ring-blue-100' : ($day['is_weekend'] ? 'border-slate-200 bg-slate-50/60' : 'border-slate-200') }}">
                    <div class="flex items-center justify-between mb-2">
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-wide {{ $day['is_today'] ? 'text-blue-700' : 'text-slate-500' }}">
                                {{ $day['date']->format('D') }}
                            </p>
                            <p class="text-base font-bold {{ $day['is_today'] ? 'text-blue-900' : 'text-slate-900' }}">
                                {{ $day['date']->format('d M') }}
                            </p>
                        </div>
                        @if($day['count'] > 0)
                            <div class="text-right">
                                <p class="text-lg font-bold tabular-nums text-slate-900">{{ $day['count'] }}</p>
                                @if($needsAttention > 0)
                                    <p class="text-[10px] font-semibold uppercase tracking-wide text-amber-700">{{ $needsAttention }} todo</p>
                                @endif
                            </div>
                        @endif
                    </div>

                    <div class="flex-1 space-y-1 overflow-hidden">
                        @forelse($day['jobs']->take(6) as $job)
                            @php
                                $pillClass = match (true) {
                                    $job->status === \App\Models\Job::STATUS_CONFIRMED => 'bg-blue-50 text-blue-800 border-blue-200',
                                    in_array($job->status, [\App\Models\Job::STATUS_PLANNED, \App\Models\Job::STATUS_DRIVER_ASSIGNED, \App\Models\Job::STATUS_READY_FOR_COLLECTION], true) && !$job->driver_user_id => 'bg-amber-50 text-amber-800 border-amber-200',
                                    in_array($job->status, [\App\Models\Job::STATUS_COLLECTED, \App\Models\Job::STATUS_IN_TRANSIT, \App\Models\Job::STATUS_IN_PROGRESS], true) => 'bg-emerald-50 text-emerald-800 border-emerald-200',
                                    $job->status === \App\Models\Job::STATUS_DELIVERED => 'bg-slate-50 text-slate-600 border-slate-200',
                                    default => 'bg-slate-50 text-slate-700 border-slate-200',
                                };
                            @endphp
                            <a href="{{ route('admin.orders.show', $job) }}"
                               class="block rounded-md border px-2 py-1 text-[11px] {{ $pillClass }} hover:opacity-80"
                               title="{{ $job->pickupLocation?->company_name }} → {{ $job->deliveryLocation?->company_name }}">
                                <span class="font-semibold">{{ $job->job_number }}</span>
                                @if($job->driver) · <span class="opacity-75">{{ Str::limit($job->driver->name, 14, '…') }}</span> @endif
                            </a>
                        @empty
                            <p class="text-[11px] text-slate-400 italic">no jobs</p>
                        @endforelse
                        @if($day['count'] > 6)
                            <p class="text-[10px] text-slate-500 mt-1">+{{ $day['count'] - 6 }} more</p>
                        @endif
                    </div>
                </div>
            @endforeach
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
