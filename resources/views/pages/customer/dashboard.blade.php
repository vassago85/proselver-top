<?php

use App\Models\Job;
use App\Models\Company;
use App\Models\SystemSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public ?Company $company = null;
    public bool $requiresConfirmation = false;

    protected const G_IN_TRANSIT = [
        Job::STATUS_COLLECTED,
        Job::STATUS_IN_TRANSIT,
    ];
    protected const G_INBOUND = [
        Job::STATUS_PLANNED,
        Job::STATUS_DRIVER_ASSIGNED,
        Job::STATUS_READY_FOR_COLLECTION,
        Job::STATUS_COLLECTED,
        Job::STATUS_IN_TRANSIT,
    ];
    protected const ACTIVE_STATUSES = [
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
    }

    protected function base()
    {
        return Job::where('transport_jobs.company_id', $this->company->id);
    }

    public function with(): array
    {
        $now  = now();
        $th   = (int) SystemSetting::get('ops.alert.in_transit_days', 3);

        // ── KPIs ──────────────────────────────────────────────────────
        $active     = (clone $this->base())->whereIn('transport_jobs.status', self::ACTIVE_STATUSES)->count();
        $inTransit  = (clone $this->base())->whereIn('transport_jobs.status', self::G_IN_TRANSIT)->count();
        $delivered30 = (clone $this->base())
            ->whereNotNull('delivered_at')
            ->where('delivered_at', '>=', $now->copy()->subDays(30))
            ->count();

        $awaitingMine = $this->requiresConfirmation
            ? (clone $this->base())->where('transport_jobs.status', Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION)->count()
            : 0;

        $overdue = (clone $this->base())
            ->whereIn('transport_jobs.status', self::G_IN_TRANSIT)
            ->where('transport_jobs.updated_at', '<=', $now->copy()->subDays($th))
            ->count();

        // ── Live orders (everything active, newest first, max 20) ─────
        $liveOrders = (clone $this->base())
            ->whereIn('transport_jobs.status', self::ACTIVE_STATUSES)
            ->with([
                'pickupLocation:id,company_name,city,province',
                'deliveryLocation:id,company_name,city,province',
                'brand:id,name',
                'inventory:id,chassis_number,vin',
            ])
            ->orderByDesc('transport_jobs.created_at')
            ->limit(20)
            ->get()
            ->each(function ($j) use ($th) {
                $days = $j->updated_at ? (int) $j->updated_at->diffInDays(now()) : 0;
                $j->setAttribute('days_in_stage', $days);
                $j->setAttribute('is_overdue',
                    in_array($j->status, self::G_IN_TRANSIT, true) && $days >= $th
                );
            });

        // ── Recent deliveries (last 5) ────────────────────────────────
        $recentDeliveries = (clone $this->base())
            ->whereIn('transport_jobs.status', [Job::STATUS_DELIVERED, Job::STATUS_COMPLETED])
            ->with([
                'deliveryLocation:id,company_name,city,province',
                'brand:id,name',
                'documents',
            ])
            ->orderByDesc(DB::raw('coalesce(completed_at, delivered_at)'))
            ->limit(5)
            ->get();

        return compact(
            'active', 'inTransit', 'delivered30', 'awaitingMine',
            'overdue', 'th',
            'liveOrders', 'recentDeliveries',
        );
    }
};
?>

@php
    $num      = fn ($v) => number_format((int) $v);
    $canOrder = auth()->user()->hasPermission('submit_booking');
@endphp

<div class="space-y-6">

    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- HEADER + QUICK ACTIONS                                         --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
        <div>
            <p class="text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-500">{{ $company->name }}</p>
            <h1 class="text-2xl font-bold tracking-tight text-slate-900">Dashboard</h1>
        </div>
        <div class="flex items-center gap-2">
            @if($canOrder)
                <a href="{{ route('customer.orders.create') }}"
                   class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 transition-colors">
                    <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                    New Order
                </a>
            @endif
            <a href="{{ route('customer.orders.index') }}"
               class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 transition-colors">
                <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg>
                All Orders
            </a>
        </div>
    </div>

    {{-- ── Awaiting confirmation banner ────────────────────────────── --}}
    @if($requiresConfirmation && $awaitingMine > 0)
        <div class="flex items-center gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3">
            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-amber-100">
                <svg viewBox="0 0 24 24" class="h-4 w-4 text-amber-600" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/>
                </svg>
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-semibold text-amber-900">{{ $awaitingMine }} {{ \Illuminate\Support\Str::plural('order', $awaitingMine) }} waiting for your confirmation</p>
                <p class="text-xs text-amber-800">Confirm so we can dispatch.</p>
            </div>
            <a href="{{ route('customer.orders.index', ['statusFilter' => \App\Models\Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION]) }}"
               class="shrink-0 rounded-md bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-700 transition-colors">
                Review now
            </a>
        </div>
    @endif

    @if(session('success'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('success') }}</div>
    @endif

    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- KPI STRIP                                                      --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <x-dash.kpi
            label="Active orders"
            :value="$num($active)"
            color="blue"
            :href="route('customer.orders.index')"
            helper="All orders currently in the pipeline">
            <x-slot:icon>
                <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg>
            </x-slot:icon>
        </x-dash.kpi>

        <x-dash.kpi
            label="In transit"
            :value="$num($inTransit)"
            color="{{ $inTransit > 0 ? 'blue' : 'slate' }}"
            :href="route('customer.orders.index', ['statusFilter' => \App\Models\Job::STATUS_IN_TRANSIT])"
            helper="Collected and on the road">
            <x-slot:icon>
                <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 16H9m10 0h3v-3.15a1 1 0 0 0-.84-.99L16 11l-2.7-3.6a1 1 0 0 0-.8-.4H5.24a2 2 0 0 0-1.8 1.1l-.8 1.63A6 6 0 0 0 2 12.42V16h2"/><circle cx="6.5" cy="16.5" r="2.5"/><circle cx="16.5" cy="16.5" r="2.5"/></svg>
            </x-slot:icon>
        </x-dash.kpi>

        <x-dash.kpi
            label="Delivered (30d)"
            :value="$num($delivered30)"
            color="green"
            helper="Completed in the last 30 days">
            <x-slot:icon>
                <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
            </x-slot:icon>
        </x-dash.kpi>

        <x-dash.kpi
            label="Overdue"
            :value="$num($overdue)"
            color="{{ $overdue > 0 ? 'red' : 'slate' }}"
            helper="In transit longer than {{ $th }} days">
            <x-slot:icon>
                <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </x-slot:icon>
        </x-dash.kpi>
    </div>

    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- LIVE ORDERS                                                    --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    <x-dash.panel title="Live orders" subtitle="Your active pipeline · newest first" :tight="true">
        <x-slot:actions>
            <a href="{{ route('customer.orders.index') }}" class="text-xs font-semibold text-slate-600 hover:text-slate-900 inline-flex items-center gap-1">
                View all
                <svg viewBox="0 0 24 24" class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
            </a>
        </x-slot:actions>

        @if($liveOrders->isEmpty())
            <div class="px-5 py-12 text-center">
                <div class="inline-flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400 mb-3">
                    <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg>
                </div>
                <p class="text-sm font-medium text-slate-900">No active orders</p>
                <p class="text-xs text-slate-500 mt-1">Create a new order to get started.</p>
                @if($canOrder)
                    <a href="{{ route('customer.orders.create') }}" class="mt-3 inline-flex items-center gap-1.5 text-sm font-semibold text-blue-600 hover:text-blue-700">
                        <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                        New Order
                    </a>
                @endif
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="bg-slate-50/70 text-[10px] uppercase tracking-[0.15em] text-slate-500">
                            <th class="px-4 py-2 text-left font-semibold">Order</th>
                            <th class="px-4 py-2 text-left font-semibold">Vehicle</th>
                            <th class="px-4 py-2 text-left font-semibold">From → To</th>
                            <th class="px-4 py-2 text-left font-semibold">Status</th>
                            <th class="px-4 py-2 text-right font-semibold">In stage</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($liveOrders as $j)
                            @php
                                $chassis = $j->inventory?->chassis_number;
                                $vin     = $j->inventory?->vin;
                                $daysIn  = $j->getAttribute('days_in_stage');
                                $isOv    = (bool) $j->getAttribute('is_overdue');
                            @endphp
                            <tr class="hover:bg-slate-50/60 transition-colors cursor-pointer"
                                onclick="window.location='{{ route('customer.orders.show', $j) }}'">
                                <td class="px-4 py-2.5">
                                    <div class="font-mono text-[12px] font-semibold text-slate-900">{{ $j->job_number ?? ('JOB-' . $j->id) }}</div>
                                    @if($chassis)
                                        <div class="font-mono text-[10px] text-slate-400">{{ $chassis }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5">
                                    <div class="text-[12px] text-slate-700 truncate max-w-[180px]">
                                        {{ $j->brand?->name }} {{ $j->model_name }}
                                    </div>
                                </td>
                                <td class="px-4 py-2.5">
                                    <div class="text-[12px] text-slate-700 flex items-center gap-1 truncate max-w-[260px]">
                                        <span class="truncate">{{ $j->pickupLocation?->city ?? '—' }}</span>
                                        <svg viewBox="0 0 24 24" class="h-3 w-3 shrink-0 text-slate-300" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                                        <span class="truncate">{{ $j->deliveryLocation?->company_name ?? ($j->deliveryLocation?->city ?? '—') }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-2.5">
                                    <x-status-badge :status="$j->status" size="sm"/>
                                </td>
                                <td class="px-4 py-2.5 text-right">
                                    @if($isOv)
                                        <x-dash.pill variant="red" size="sm">{{ $daysIn }}d</x-dash.pill>
                                    @else
                                        <span class="text-[12px] tabular-nums text-slate-500">{{ $daysIn > 0 ? $daysIn . 'd' : '<1d' }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-dash.panel>

    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- RECENT DELIVERIES                                              --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    <x-dash.panel title="Recent deliveries" subtitle="Last 5 completed" :tight="true">
        <x-slot:actions>
            <a href="{{ route('customer.documents') }}" class="text-xs font-semibold text-slate-600 hover:text-slate-900 inline-flex items-center gap-1">
                Documents
                <svg viewBox="0 0 24 24" class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
            </a>
        </x-slot:actions>

        @if($recentDeliveries->isEmpty())
            <p class="px-5 py-10 text-sm text-slate-400 text-center">No deliveries yet</p>
        @else
            <div class="divide-y divide-slate-100">
                @foreach($recentDeliveries as $d)
                    @php
                        $hasPod      = $d->documents->where('category', 'proof_of_delivery')->isNotEmpty();
                        $hasDamage   = !is_null($d->damage_report_released_at);
                        $completedOn = $d->completed_at ?? $d->delivered_at;
                    @endphp
                    <a href="{{ route('customer.orders.show', $d) }}" class="flex items-center gap-3 px-5 py-3 hover:bg-slate-50/60 transition-colors group">
                        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full {{ $hasPod ? 'bg-emerald-50 text-emerald-600' : 'bg-slate-100 text-slate-400' }}">
                            <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-slate-900 truncate group-hover:text-blue-700">
                                {{ $d->job_number ?? ('JOB-' . $d->id) }}
                                <span class="font-normal text-slate-500">· {{ $d->model_name ?: ($d->brand?->name ?: 'Vehicle') }}</span>
                            </p>
                            <p class="text-[11px] text-slate-500 truncate">
                                {{ $d->deliveryLocation?->company_name ?? ($d->deliveryLocation?->city ?? '—') }}
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
                    </a>
                @endforeach
            </div>
        @endif
    </x-dash.panel>

    <p class="text-center text-[10px] text-slate-400 tracking-[0.2em] uppercase pt-2">
        Trident · {{ $company->name }}
    </p>
</div>
