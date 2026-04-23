<?php

use App\Models\Job;
use App\Models\JobDocument;
use App\Models\DriverProfile;
use App\Models\User;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;

new #[Layout('components.layouts.app')] class extends Component {
    /**
     * One-click dismiss for a damage incident on the dashboard strip.
     * Stamps damage_acknowledged_at so the incident drops off both the
     * "Damage Reports" stat tile and the "Recent damage incidents" list
     * without forcing the operator through the full release-to-customer
     * flow. Release implies ack elsewhere; this is the lighter-weight
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
        // "New" from ops' point of view = anything that just landed and hasn't
        // been verified/confirmed yet. Dealer + OEM bookings arrive in
        // PENDING_VERIFICATION; customer-portal bookings land in RECEIVED.
        // Either way ops needs eyes on them, so we count both here.
        $newOrders = Job::whereIn('status', [
            Job::STATUS_PENDING_VERIFICATION,
            Job::STATUS_RECEIVED,
        ])->count();
        $pendingVerification = Job::where('status', Job::STATUS_PENDING_VERIFICATION)->count();
        $awaitingConfirmation = Job::whereIn('status', [Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION, Job::STATUS_CONFIRMATION_ISSUE])->count();
        $confirmationIssues = Job::where('status', Job::STATUS_CONFIRMATION_ISSUE)->count();
        $readyToPlan = Job::where('status', Job::STATUS_CONFIRMED)->count();

        $inFlight = Job::whereIn('status', [
            Job::STATUS_DRIVER_ASSIGNED,
            Job::STATUS_READY_FOR_COLLECTION,
            Job::STATUS_COLLECTED,
            Job::STATUS_IN_TRANSIT,
        ])->count();

        $deliveredToday = Job::whereIn('status', [Job::STATUS_DELIVERED, Job::STATUS_COMPLETED])
            ->whereDate('delivered_at', today())
            ->count();

        // Live pulse row: counts per live status. driver_assigned absorbs legacy
        // ready_for_collection rows so the board never shows both side by side.
        $liveCounts = [
            'driver_assigned'      => Job::whereIn('status', [Job::STATUS_DRIVER_ASSIGNED, Job::STATUS_READY_FOR_COLLECTION])->count(),
            'collected'            => Job::where('status', Job::STATUS_COLLECTED)->count(),
            'in_transit'           => Job::where('status', Job::STATUS_IN_TRANSIT)->count(),
        ];

        // Driver compliance (licence + PDP, 60-day horizon)
        $driverThreshold = now()->addDays(60);
        $driversExpiringSoon = DriverProfile::where(function ($q) use ($driverThreshold) {
                $q->whereBetween('license_expiry', [now(), $driverThreshold])
                  ->orWhereBetween('prdp_expiry', [now(), $driverThreshold]);
            })->count();
        $driversExpired = DriverProfile::where(function ($q) {
                $q->where('license_expiry', '<', now())
                  ->orWhere('prdp_expiry', '<', now());
            })->count();

        // Attention list — a single unified feed of every driver credential
        // that is EXPIRED or expiring inside the 60-day window. Licence,
        // PrDP, Trade Plate all land in the same list, sorted by urgency
        // (expired first, then soonest expiry). Replaces the old three-
        // column Fleet Health grid — ops wanted one focused list, not a
        // chip showroom. Equipment / identity flags live on /admin/drivers
        // where they belong.
        $today = now()->startOfDay();
        $attentionWindowEnd = $today->copy()->addDays(60)->endOfDay();

        $attentionProfiles = DriverProfile::with('user:id,name,is_active')
            ->whereHas('user', fn($q) => $q->where('is_active', true))
            ->where(function ($q) use ($attentionWindowEnd) {
                $q->where('license_expiry', '<=', $attentionWindowEnd)
                  ->orWhere('prdp_expiry', '<=', $attentionWindowEnd)
                  ->orWhere('trade_plate_expiry', '<=', $attentionWindowEnd);
            })
            ->get();

        $attentionItems = [];
        foreach ($attentionProfiles as $p) {
            $fields = [
                ['date' => $p->license_expiry,     'label' => 'Licence',     'filter' => 'license'],
                ['date' => $p->prdp_expiry,        'label' => 'PrDP',        'filter' => 'prdp'],
                ['date' => $p->trade_plate_expiry, 'label' => 'Trade plate', 'filter' => 'trade_plate'],
            ];
            foreach ($fields as $f) {
                $d = $f['date'];
                if (!$d || $d->gt($attentionWindowEnd)) {
                    continue;
                }
                $daysLeft = (int) floor($today->diffInDays($d, false));
                $attentionItems[] = [
                    'user_id'     => $p->user_id,
                    'driver_name' => $p->user?->name ?? 'Unknown',
                    'label'       => $f['label'],
                    'filter'      => $f['filter'],
                    'date'        => $d,
                    'days_left'   => $daysLeft,
                    'expired'     => $daysLeft < 0,
                ];
            }
        }
        // Sort: expired first (by how long ago, worst at top), then
        // upcoming (soonest first). Keeps the loudest alarm at the top.
        usort($attentionItems, fn($a, $b) => $a['date'] <=> $b['date']);

        $attentionExpiredCount  = count(array_filter($attentionItems, fn($i) => $i['expired']));
        $attentionExpiringCount = count($attentionItems) - $attentionExpiredCount;
        $attentionVisible = array_slice($attentionItems, 0, 10);
        $attentionOverflow = max(0, count($attentionItems) - count($attentionVisible));

        $recentOrders = Job::with(['company:id,name,workflow_type', 'pickupLocation:id,company_name,city', 'deliveryLocation:id,company_name,city', 'brand:id,name'])
            ->whereIn('status', Job::PHASE1_STATUSES)
            ->orderByDesc('created_at')
            ->limit(8)
            ->get();

        // Damage pulse — only shows jobs with damage that ops has NOT
        // acknowledged yet. Ack happens:
        //   1. automatically on admin order page view (they've seen it),
        //   2. via the inline Dismiss button on this strip, or
        //   3. when ops explicitly releases the report to the customer.
        // Once acked, the incident disappears from the dashboard. It
        // stays in /admin/damage for audit / release workflow.
        $damageJobIds = JobDocument::where('category', JobDocument::CATEGORY_DAMAGE_PHOTO)
            ->distinct()
            ->pluck('job_id');

        $unackedBase = Job::whereIn('id', $damageJobIds)
            ->whereNull('damage_acknowledged_at');

        // Headline is unreleased-to-customer incidents — this is still the
        // number ops should drive to zero for customer sign-off.
        $pendingReleaseCount = (clone $unackedBase)
            ->whereNull('damage_report_released_at')
            ->count();
        // "Open" = still operationally live (not completed / cancelled) AND
        // not yet acknowledged by ops.
        $openDamageCount = (clone $unackedBase)
            ->whereNotIn('status', [Job::STATUS_COMPLETED, Job::STATUS_CANCELLED])
            ->count();
        $damageLast7d = JobDocument::where('category', JobDocument::CATEGORY_DAMAGE_PHOTO)
            ->where('created_at', '>=', now()->subDays(7))
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

        return compact(
            'newOrders',
            'pendingVerification',
            'awaitingConfirmation',
            'confirmationIssues',
            'readyToPlan',
            'inFlight',
            'deliveredToday',
            'liveCounts',
            'driversExpiringSoon',
            'driversExpired',
            'recentOrders',
            'attentionItems',
            'attentionVisible',
            'attentionOverflow',
            'attentionExpiredCount',
            'attentionExpiringCount',
            'openDamageCount',
            'pendingReleaseCount',
            'damageLast7d',
            'recentDamageJobs',
        );
    }
};

?>

<div wire:poll.60s>
    <x-slot:header>Dashboard</x-slot:header>

    {{-- Hero strip --}}
    <x-page-header
        eyebrow="Control · Dispatch · Deliver"
        title="Operations overview"
        subtitle="Live snapshot of bookings, dispatch readiness, and active movements.">
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

    {{-- Stat cards
         4-up on laptops, 7-up on wide monitors. Avoids the "too many
         tiny tiles in one row" look that hid the numbers before. --}}
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 2xl:grid-cols-7 mb-6">
        <x-stat-card
            label="New Bookings"
            :value="$newOrders"
            color="blue"
            :helper="$pendingVerification > 0 ? $pendingVerification . ' to verify' : null"
            helperColor="amber"
            :href="route('admin.vehicles.index', ['bucket' => 'open'])">
            <x-slot:icon>
                <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
            </x-slot:icon>
        </x-stat-card>

        <x-stat-card
            label="Awaiting Confirmation"
            :value="$awaitingConfirmation"
            color="amber"
            :helper="$confirmationIssues > 0 ? $confirmationIssues . ' with issues' : null"
            helperColor="red"
            :href="route('admin.orders.index', ['status' => 'awaiting_customer_confirmation'])" />

        <x-stat-card
            label="Ready to Plan"
            :value="$readyToPlan"
            color="indigo"
            :href="route('admin.planning')" />

        <x-stat-card
            label="In Transit"
            :value="$inFlight"
            color="orange"
            :href="route('admin.vehicles.index', ['bucket' => 'live'])" />

        <x-stat-card
            label="Delivered Today"
            :value="$deliveredToday"
            color="emerald"
            :href="route('admin.deliveries', ['range' => 'today'])" />

        @php
            // Driver Compliance tile surfaces everything that needs action:
            //   expired licences/PDPs (urgent) + those expiring inside the
            //   60-day window. Headline count is the *total* action list so
            //   a big "0" never hides an expired driver sitting underneath.
            $driversNeedingAction = $driversExpired + $driversExpiringSoon;
            $complianceHelper = match (true) {
                $driversExpired > 0 && $driversExpiringSoon > 0
                    => $driversExpired . ' expired · ' . $driversExpiringSoon . ' expiring soon',
                $driversExpired > 0
                    => $driversExpired . ' expired · action required',
                $driversExpiringSoon > 0
                    => $driversExpiringSoon . ' expiring in 60 days',
                default
                    => 'All licences valid',
            };
            $complianceColor = match (true) {
                $driversExpired > 0 => 'red',
                $driversExpiringSoon > 0 => 'amber',
                default => 'slate',
            };
        @endphp

        <x-stat-card
            label="Driver Compliance"
            :value="$driversNeedingAction"
            :color="$complianceColor"
            :helper="$complianceHelper"
            :helperColor="$complianceColor"
            :href="route('admin.drivers.index')" />

        @php
            // Damage Reports tile. Counts unacknowledged incidents —
            // everything ops has either opened, dismissed, or formally
            // released has already dropped off this number.
            $damageHelper = match (true) {
                $openDamageCount > 0 && $pendingReleaseCount > 0 && $openDamageCount !== $pendingReleaseCount
                    => $openDamageCount . ' new · ' . $pendingReleaseCount . ' still to release',
                $openDamageCount > 0
                    => $openDamageCount . ' awaiting review',
                default
                    => 'No new damage',
            };
            $damageColor = $openDamageCount > 0 ? 'red' : 'slate';
        @endphp
        <x-stat-card
            label="Damage Reports"
            :value="$pendingReleaseCount"
            :color="$damageColor"
            :helper="$damageHelper"
            :helperColor="$damageColor"
            :href="route('admin.damage', ['bucket' => 'pending_release'])">
            <x-slot:icon>
                <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
            </x-slot:icon>
        </x-stat-card>
    </div>

    {{-- Live movements strip (matches landing page operational tile feel) --}}
    <div class="mb-6 rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="flex items-center justify-between gap-3 border-b border-slate-100 px-6 py-4">
            <div class="flex items-center gap-3">
                <span class="inline-flex items-center gap-2 text-[10px] font-semibold uppercase tracking-[0.25em] text-blue-600">
                    <span class="h-1.5 w-1.5 rounded-full bg-blue-500 node-pulse"></span>
                    Live pipeline
                </span>
            </div>
            <a href="{{ route('admin.vehicles.index', ['bucket' => 'live']) }}" class="text-xs font-semibold text-slate-600 hover:text-slate-900 inline-flex items-center gap-1 transition-colors">
                Open live fleet
                <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 7h10v10"/><path d="M7 17 17 7"/></svg>
            </a>
        </div>
        <div class="grid grid-cols-3 divide-y md:divide-y-0 md:divide-x divide-slate-100">
            @foreach([
                ['label' => 'Driver Assigned', 'key' => 'driver_assigned', 'dot' => 'bg-purple-500'],
                ['label' => 'Driver Arrived at Pickup', 'key' => 'collected', 'dot' => 'bg-teal-500'],
                ['label' => 'In Transit', 'key' => 'in_transit', 'dot' => 'bg-orange-500'],
            ] as $node)
                <div class="px-6 py-4 flex items-center justify-between gap-3">
                    <div class="flex items-center gap-2.5 min-w-0">
                        <span class="h-2 w-2 rounded-full {{ $node['dot'] }} {{ ($liveCounts[$node['key']] ?? 0) > 0 ? 'node-pulse' : 'opacity-30' }}"></span>
                        <span class="text-xs font-medium text-slate-600 truncate">{{ $node['label'] }}</span>
                    </div>
                    <span class="text-lg font-semibold tabular-nums text-slate-900">{{ $liveCounts[$node['key']] ?? 0 }}</span>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Recent damage incidents strip
         Only renders when there is damage to show — no point eating
         vertical space on a clean week. Each row links directly to
         the order page's #damage-section so ops can review photos +
         download the PDF in one click.
    --}}
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

    {{-- Action required — unified expiry list
         Single focused feed: every driver credential (Licence, PrDP,
         Trade Plate) that is expired or expiring inside 60 days,
         sorted by urgency. No chips, no equipment noise — just the
         things ops has to do something about, grouped by driver.
         Renders nothing when there's nothing to action. --}}
    @if(!empty($attentionItems))
    @php
        $driversRoute = route('admin.drivers.index');
        $headlineColor = $attentionExpiredCount > 0 ? 'amber' : 'amber';
        $ring = $attentionExpiredCount > 0 ? 'border-amber-200' : 'border-slate-200';
        $headBg = $attentionExpiredCount > 0 ? 'bg-amber-50/60' : 'bg-slate-50/60';
    @endphp
    <div class="mb-6 rounded-2xl border {{ $ring }} bg-white shadow-sm overflow-hidden">
        <div class="flex items-center justify-between gap-3 border-b border-slate-100 {{ $headBg }} px-6 py-4">
            <div class="flex items-center gap-3">
                <span class="inline-flex items-center gap-2 text-[10px] font-semibold uppercase tracking-[0.25em] {{ $attentionExpiredCount > 0 ? 'text-amber-700' : 'text-slate-600' }}">
                    <span class="h-1.5 w-1.5 rounded-full {{ $attentionExpiredCount > 0 ? 'bg-amber-500 node-pulse' : 'bg-slate-400' }}"></span>
                    Action required
                </span>
                <span class="text-[11px] text-slate-500 tabular-nums">
                    @if($attentionExpiredCount > 0){{ $attentionExpiredCount }} expired@endif
                    @if($attentionExpiredCount > 0 && $attentionExpiringCount > 0) · @endif
                    @if($attentionExpiringCount > 0){{ $attentionExpiringCount }} expiring &lt;60d@endif
                </span>
            </div>
            <a href="{{ $driversRoute }}" class="text-xs font-semibold text-slate-600 hover:text-slate-900 inline-flex items-center gap-1 transition-colors">
                All drivers
                <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 7h10v10"/><path d="M7 17 17 7"/></svg>
            </a>
        </div>
        <ul class="divide-y divide-slate-100">
            @foreach($attentionVisible as $item)
                @php
                    $expired = $item['expired'];
                    $days = $item['days_left'];
                    // Urgency pill: expired in red, <=14d in amber, else slate.
                    if ($expired) {
                        $pillClass = 'bg-red-100 text-red-700 border-red-200';
                        $pillText  = $days === -1 ? 'Expired yesterday' : 'Expired ' . abs($days) . 'd ago';
                        $dotClass  = 'bg-red-500';
                    } elseif ($days <= 14) {
                        $pillClass = 'bg-amber-100 text-amber-800 border-amber-200';
                        $pillText  = $days === 0 ? 'Expires today' : ($days === 1 ? '1 day left' : $days . ' days left');
                        $dotClass  = 'bg-amber-500';
                    } else {
                        $pillClass = 'bg-slate-100 text-slate-700 border-slate-200';
                        $pillText  = $days . ' days left';
                        $dotClass  = 'bg-slate-400';
                    }
                @endphp
                <li>
                    <a href="{{ route('admin.drivers.edit', $item['user_id']) }}"
                       class="flex items-center gap-4 px-6 py-2.5 hover:bg-slate-50/70 transition-colors">
                        <span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $dotClass }}"></span>
                        <div class="min-w-0 flex-1 flex items-center gap-3">
                            <span class="text-sm font-medium text-slate-900 truncate">{{ $item['driver_name'] }}</span>
                            <span class="inline-flex items-center rounded-md bg-slate-50 border border-slate-200 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-600">
                                {{ $item['label'] }}
                            </span>
                        </div>
                        <span class="hidden sm:inline text-[11px] text-slate-400 tabular-nums shrink-0">
                            {{ $item['date']->format('d M Y') }}
                        </span>
                        <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold tabular-nums shrink-0 {{ $pillClass }}">
                            {{ $pillText }}
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
        @if($attentionOverflow > 0)
            <div class="border-t border-slate-100 bg-slate-50/60 px-6 py-2.5 text-center">
                <a href="{{ $driversRoute }}?expiring=1" class="text-[11px] font-semibold text-slate-600 hover:text-slate-900 inline-flex items-center gap-1">
                    View {{ $attentionOverflow }} more
                    <svg viewBox="0 0 24 24" class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
                </a>
            </div>
        @endif
    </div>
    @endif

    {{-- Recent orders — full width.
         The old Active Movements side panel was redundant with the Live
         pipeline strip at the top; keeping the dashboard to one focused
         column makes the whole page read top-to-bottom without the eye
         having to juggle two stacks of information. --}}
    <div>
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
</div>
