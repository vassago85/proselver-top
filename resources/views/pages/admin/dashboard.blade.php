<?php

use App\Models\Job;
use App\Models\DriverProfile;
use App\Models\User;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;

new #[Layout('components.layouts.app')] class extends Component {
    public function with(): array
    {
        $newOrders = Job::where('status', Job::STATUS_RECEIVED)->count();
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

        // Driver compliance
        $driverThreshold = now()->addDays(60);
        $driversExpiringSoon = DriverProfile::where(function ($q) use ($driverThreshold) {
                $q->whereBetween('license_expiry', [now(), $driverThreshold])
                  ->orWhereBetween('prdp_expiry', [now(), $driverThreshold]);
            })->count();
        $driversExpired = DriverProfile::where(function ($q) {
                $q->where('license_expiry', '<', now())
                  ->orWhere('prdp_expiry', '<', now());
            })->count();

        $recentOrders = Job::with(['company:id,name,workflow_type', 'pickupLocation:id,company_name,city', 'deliveryLocation:id,company_name,city', 'brand:id,name'])
            ->whereIn('status', Job::PHASE1_STATUSES)
            ->orderByDesc('created_at')
            ->limit(8)
            ->get();

        $activeMovements = Job::with(['company:id,name', 'pickupLocation:id,company_name,city', 'deliveryLocation:id,company_name,city', 'brand:id,name', 'driver:id,name'])
            ->whereIn('status', [Job::STATUS_DRIVER_ASSIGNED, Job::STATUS_READY_FOR_COLLECTION, Job::STATUS_COLLECTED, Job::STATUS_IN_TRANSIT])
            ->orderByDesc('updated_at')
            ->limit(6)
            ->get();

        return compact(
            'newOrders',
            'awaitingConfirmation',
            'confirmationIssues',
            'readyToPlan',
            'inFlight',
            'deliveredToday',
            'liveCounts',
            'driversExpiringSoon',
            'driversExpired',
            'recentOrders',
            'activeMovements',
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

    {{-- Stat cards --}}
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 mb-6">
        <x-stat-card
            label="New Orders"
            :value="$newOrders"
            color="blue"
            :href="route('admin.orders.index', ['status' => 'received'])">
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
            :href="route('admin.tracking')" />

        <x-stat-card
            label="Delivered Today"
            :value="$deliveredToday"
            color="emerald" />

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
            <a href="{{ route('admin.tracking') }}" class="text-xs font-semibold text-slate-600 hover:text-slate-900 inline-flex items-center gap-1 transition-colors">
                Open tracking
                <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 7h10v10"/><path d="M7 17 17 7"/></svg>
            </a>
        </div>
        <div class="grid grid-cols-3 divide-y md:divide-y-0 md:divide-x divide-slate-100">
            @foreach([
                ['label' => 'Driver Assigned', 'key' => 'driver_assigned', 'dot' => 'bg-purple-500'],
                ['label' => 'Collected', 'key' => 'collected', 'dot' => 'bg-teal-500'],
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

    {{-- Content grid: Recent orders + Active movements --}}
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">

        {{-- Recent orders (main) --}}
        <div class="xl:col-span-2">
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

        {{-- Active movements panel --}}
        <div class="xl:col-span-1">
            <x-card title="Active Movements" subtitle="Currently in the field" :padding="false">
                <x-slot:actions>
                    <span class="inline-flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-500">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 node-pulse"></span>
                        Live
                    </span>
                </x-slot:actions>

                @if($activeMovements->isEmpty())
                    <x-empty-state
                        title="No active movements"
                        description="Dispatched drivers and in-transit vehicles appear here in real time.">
                        <x-slot:icon>
                            <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/></svg>
                        </x-slot:icon>
                    </x-empty-state>
                @else
                    <ul class="divide-y divide-slate-100">
                        @foreach($activeMovements as $job)
                        <li class="px-5 py-3.5 hover:bg-slate-50/60 transition-colors">
                            <a href="{{ route('admin.orders.show', $job) }}" class="flex items-start gap-3">
                                <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full {{ $job->status === 'in_transit' ? 'bg-orange-500 node-pulse' : ($job->status === 'collected' ? 'bg-teal-500' : ($job->status === 'ready_for_collection' ? 'bg-cyan-500' : 'bg-purple-500')) }}"></span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="text-sm font-semibold text-slate-900 truncate">{{ $job->job_number ?? '—' }}</span>
                                        <x-status-badge :status="$job->status" size="sm" />
                                    </div>
                                    <p class="mt-0.5 text-xs text-slate-500 truncate">
                                        {{ $job->brand?->name }} {{ $job->model_name }}
                                        @if($job->driver) · {{ $job->driver->name }} @endif
                                    </p>
                                    <p class="mt-0.5 text-[11px] text-slate-400 truncate">
                                        {{ $job->pickupLocation?->city ?? '—' }} → {{ $job->deliveryLocation?->city ?? '—' }}
                                    </p>
                                </div>
                            </a>
                        </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>
        </div>
    </div>
</div>
