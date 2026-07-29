<?php

use App\Models\Job;
use App\Models\User;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $statusFilter = '';

    /**
     * Executor scope for the listing. Defaults to ProSelver so ops's
     * default view shows only the movements they actually dispatch --
     * dealer-internal / 3rd-party / self-collect bookings stay hidden
     * unless explicitly asked for, because they'd otherwise clutter
     * the queue with work that's not ops's responsibility.
     *
     * Values: 'proselver' (default) | 'external' | 'all'.
     *
     * IMPORTANT: a non-empty $search bypasses this filter entirely --
     * if a customer phones in with an order number / VIN, the search
     * must find it regardless of which executor is in the dropdown.
     */
    #[Url(except: 'proselver')]
    public string $executorFilter = 'proselver';

    /**
     * "Movement per driver" filter (CRM request 23-Jul).  Ops can pick
     * a driver from the dropdown to see everything assigned to them,
     * without having to type the name into search.  Ignored when the
     * search box is populated (same rule as executor scope).
     */
    #[Url(as: 'driver')]
    public ?int $driverId = null;

    public function with(): array
    {
        $query = Job::with([
                'company:id,name,workflow_type',
                'pickupLocation:id,company_name,address,city,province',
                'deliveryLocation:id,company_name,address,city,province',
                'driver:id,name',
                'brand:id,name',
            ])
            ->whereIn('status', Job::PHASE1_STATUSES)
            ->orderByDesc('created_at');

        if ($this->statusFilter) {
            $query->where('status', $this->statusFilter);
        }

        // Apply the executor filter only when the operator is browsing
        // (no active search). Searching always spans every executor so
        // ops can find a phoned-in order without first switching the
        // dropdown to "All".
        if (! $this->search) {
            $this->applyExecutorScope($query);
        }

        // Driver filter (bypassed by search for the same reason as
        // executor -- a phoned-in job # lookup must always find its
        // row).
        if (! $this->search && $this->driverId) {
            $query->where('driver_user_id', (int) $this->driverId);
        }

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('job_number', 'ilike', "%{$this->search}%")
                    ->orWhere('vin', 'ilike', "%{$this->search}%")
                    ->orWhere('registration', 'ilike', "%{$this->search}%")
                    ->orWhere('model_name', 'ilike', "%{$this->search}%")
                    ->orWhereHas('brand', fn($q) => $q->where('name', 'ilike', "%{$this->search}%"))
                    ->orWhereHas('company', fn($q) => $q->where('name', 'ilike', "%{$this->search}%"))
                    ->orWhereHas('pickupLocation', fn($q) => $q->where('company_name', 'ilike', "%{$this->search}%"))
                    ->orWhereHas('deliveryLocation', fn($q) => $q->where('company_name', 'ilike', "%{$this->search}%"))
                    ->orWhereHas('driver', fn($q) => $q->where('name', 'ilike', "%{$this->search}%"));
            });
        }

        // Status counts pills must respect the active executor + driver
        // filters -- otherwise the pill says "23 In Transit" but the
        // table shows 6 because the other 17 are dealer-internal or
        // assigned to a different driver.
        $countsQuery = Job::whereIn('status', Job::PHASE1_STATUSES);
        if (! $this->search) {
            $this->applyExecutorScope($countsQuery);
            if ($this->driverId) {
                $countsQuery->where('driver_user_id', (int) $this->driverId);
            }
        }
        $statusCounts = $countsQuery
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->toArray();

        // Always-on counter of hidden dealer-internal jobs so ops knows
        // there's a parallel pile even when the filter is on.
        $hiddenExternalCount = $this->executorFilter === 'proselver' && ! $this->search
            ? Job::whereIn('status', Job::PHASE1_STATUSES)
                ->whereIn('executor_type', [Job::EXECUTOR_INTERNAL, Job::EXECUTOR_THIRD_PARTY, Job::EXECUTOR_SELF_COLLECT])
                ->count()
            : 0;

        // Driver dropdown options -- all active platform drivers,
        // plus dealer-internal drivers so ops can filter regardless of
        // which pool the person belongs to.  Query is cheap (single
        // table with roles join) and cached-friendly across renders.
        $driverOptions = User::query()
            ->whereHas('roles', fn ($q) => $q->where('slug', 'driver'))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($d) => ['value' => (string) $d->id, 'label' => $d->name])
            ->prepend(['value' => '', 'label' => 'All drivers'])
            ->all();

        // Server-resolved label so ?driver=42 deep-links show the right
        // name before Livewire's JS boots.
        $driverLabel = $this->driverId
            ? User::whereKey($this->driverId)->value('name')
            : null;

        return [
            'jobs' => $query->paginate(20),
            'statuses' => Job::PHASE1_STATUS_LABELS,
            'statusCounts' => $statusCounts,
            'totalCount' => array_sum($statusCounts),
            'hiddenExternalCount' => $hiddenExternalCount,
            'driverOptions' => $driverOptions,
            'driverLabel' => $driverLabel,
        ];
    }

    /**
     * Narrow a Job query to the active executor filter. Pulled out so the
     * listing query and the counts query stay in lockstep.
     */
    protected function applyExecutorScope($query): void
    {
        match ($this->executorFilter) {
            'external' => $query->whereIn('executor_type', [
                Job::EXECUTOR_INTERNAL,
                Job::EXECUTOR_THIRD_PARTY,
                Job::EXECUTOR_SELF_COLLECT,
            ]),
            'all'      => null,
            default    => $query->where('executor_type', Job::EXECUTOR_PROSELVER),
        };
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedExecutorFilter(): void
    {
        $this->resetPage();
    }

    public function updatedDriverId(): void
    {
        $this->resetPage();
    }

    public function setFilter(string $status): void
    {
        $this->statusFilter = $this->statusFilter === $status ? '' : $status;
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->statusFilter = '';
        $this->executorFilter = 'proselver';
        $this->driverId = null;
        $this->resetPage();
    }
};

?>

<div>
    <x-slot:header>Orders</x-slot:header>

    <x-page-header
        eyebrow="Booking"
        title="Orders"
        :subtitle="number_format($totalCount) . ' active bookings in the Phase 1 lifecycle'">
        <x-slot:actions>
            @if($search || $statusFilter || $driverId)
                <x-button variant="ghost" size="sm" wire:click="clearFilters">
                    <x-slot:icon>
                        <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                    </x-slot:icon>
                    Clear filters
                </x-button>
            @endif
        </x-slot:actions>
    </x-page-header>

    {{-- Quick status filter pills --}}
    <div class="mb-4 flex flex-wrap items-center gap-2">
        <button type="button" wire:click="$set('statusFilter', '')"
            class="inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-semibold transition-colors {{ $statusFilter === '' ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300 hover:text-slate-900' }}">
            All
            <span class="tabular-nums {{ $statusFilter === '' ? 'text-slate-300' : 'text-slate-400' }}">{{ $totalCount }}</span>
        </button>
        @foreach(['received', 'awaiting_customer_confirmation', 'confirmed', 'planned', 'driver_assigned', 'in_transit', 'delivered'] as $s)
            @if(isset($statusCounts[$s]))
                <button type="button" wire:click="setFilter('{{ $s }}')"
                    class="inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-semibold transition-colors {{ $statusFilter === $s ? 'border-blue-600 bg-blue-50 text-blue-700' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300 hover:text-slate-900' }}">
                    <x-status-badge :status="$s" size="sm" dot="true" class="!ring-0 !bg-transparent !px-0 !py-0" />
                    <span class="tabular-nums">{{ $statusCounts[$s] }}</span>
                </button>
            @endif
        @endforeach
    </div>

    {{-- Search + extended filter --}}
    <div class="mb-4 flex flex-col sm:flex-row gap-3">
        <div class="relative flex-1">
            <svg viewBox="0 0 24 24" class="absolute left-3.5 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400 pointer-events-none" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search order #, VIN, make/model, company, route, or driver…"
                class="w-full rounded-lg border border-slate-300 bg-white pl-10 pr-10 py-2.5 text-sm placeholder:text-slate-400 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none transition">
            <div wire:loading wire:target="search" class="absolute right-3 top-1/2 -translate-y-1/2">
                <svg class="h-4 w-4 animate-spin text-slate-400" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z"/></svg>
            </div>
        </div>
        <select wire:model.live="statusFilter" class="rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none transition">
            <option value="">All statuses</option>
            @foreach($statuses as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
        {{-- Executor filter. ProSelver-only is the default so ops's
             browse view stays clean; the dealer-internal pile is one
             click away when ops actually wants to look at it. Search
             ignores this filter so phoned-in lookups always work. --}}
        <select wire:model.live="executorFilter" class="rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none transition">
            <option value="proselver">ProSelver only</option>
            <option value="external">Dealer / 3rd-party / Self-collect</option>
            <option value="all">All executors</option>
        </select>
        {{-- Movement per driver (CRM request 23-Jul).  Bypassed while
             search is active. --}}
        <div class="w-full sm:w-56">
            <x-searchable-select
                wire:model.live="driverId"
                :options="$driverOptions"
                :selected-label="$driverLabel"
                placeholder="All drivers"/>
        </div>
    </div>

    @if($hiddenExternalCount > 0)
        <div class="mb-3 flex items-center gap-2 text-xs text-slate-500">
            <svg class="h-3.5 w-3.5 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
            <span>
                {{ number_format($hiddenExternalCount) }} dealer-internal / 3rd-party / self-collect
                {{ \Illuminate\Support\Str::plural('movement', $hiddenExternalCount) }} hidden.
            </span>
            <button type="button" wire:click="$set('executorFilter', 'external')"
                class="font-semibold text-blue-600 hover:text-blue-800">Show them</button>
        </div>
    @endif

    {{-- Table --}}
    <div class="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead class="bg-slate-50/60 border-b border-slate-100">
                    <tr>
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-500">Job number</th>
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-500">Customer</th>
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-500">Make / Model</th>
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-500">VIN / Reg</th>
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-500">Route</th>
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-500">Status</th>
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-500" title="Petty cash workflow state">Petty Cash</th>
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-500">Driver</th>
                        <th class="px-4 py-3 text-right text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-500">Date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($jobs as $job)
                    <tr class="group hover:bg-slate-50/60 cursor-pointer transition-colors"
                        onclick="window.location='{{ route('admin.orders.show', $job) }}'">
                        <td class="px-4 py-3.5">
                            <span class="text-sm font-semibold text-slate-900 group-hover:text-blue-700 transition-colors">{{ $job->job_number ?? '—' }}</span>
                        </td>
                        <td class="px-4 py-3.5">
                            <div class="flex items-center gap-2">
                                <span class="text-sm text-slate-700 truncate max-w-[160px]">{{ $job->company?->name ?? '—' }}</span>
                                @if($job->company?->workflow_type === 'faw')
                                    <x-badge color="amber" size="sm">FAW</x-badge>
                                @endif
                            </div>
                        </td>
                        <td class="px-4 py-3.5 text-sm text-slate-700">{{ trim(($job->brand?->name ?? '') . ' ' . ($job->model_name ?? '')) ?: '—' }}</td>
                        <td class="px-4 py-3.5 text-xs text-slate-500">
                            <x-vehicle-identifier :model="$job" layout="stacked" />
                        </td>
                        <td class="px-4 py-3.5 text-xs text-slate-600 align-top">
                            <div class="space-y-2 max-w-[260px]">
                                <div class="flex gap-1.5">
                                    <span class="inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-blue-100 text-blue-700 text-[9px] font-bold" title="Pickup">P</span>
                                    <div class="min-w-0 leading-tight" title="{{ $job->pickupLocation?->fullDisplay(' — ') }}">
                                        <p class="font-semibold text-slate-800 truncate">{{ $job->pickupLocation?->company_name ?? '—' }}</p>
                                        @if($job->pickupLocation?->address)
                                            <p class="text-slate-500 truncate">{{ $job->pickupLocation->address }}</p>
                                        @endif
                                        @if($job->pickupLocation?->city || $job->pickupLocation?->province)
                                            <p class="text-slate-400 text-[10px]">{{ trim(implode(', ', array_filter([$job->pickupLocation->city, $job->pickupLocation->province]))) }}</p>
                                        @endif
                                    </div>
                                </div>
                                <div class="flex gap-1.5">
                                    <span class="inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 text-[9px] font-bold" title="Delivery">D</span>
                                    <div class="min-w-0 leading-tight" title="{{ $job->deliveryLocation?->fullDisplay(' — ') }}">
                                        <p class="font-semibold text-slate-800 truncate">{{ $job->deliveryLocation?->company_name ?? '—' }}</p>
                                        @if($job->deliveryLocation?->address)
                                            <p class="text-slate-500 truncate">{{ $job->deliveryLocation->address }}</p>
                                        @endif
                                        @if($job->deliveryLocation?->city || $job->deliveryLocation?->province)
                                            <p class="text-slate-400 text-[10px]">{{ trim(implode(', ', array_filter([$job->deliveryLocation->city, $job->deliveryLocation->province]))) }}</p>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3.5"><x-status-badge :status="$job->status" /></td>
                        <td class="px-4 py-3.5">
                            @php
                                // Compute the petty-cash workflow state in priority
                                // order so we surface the most-recent / most-load-
                                // bearing stamp first.  Removal-pending wins because
                                // it's the actionable owner gate; issued wins next
                                // because cash is already out; etc.  All four pieces
                                // come straight off transport_jobs -- no extra
                                // queries needed.
                                $pcState = null;
                                if ($job->advance_total !== null) {
                                    if ($job->advance_removal_pending) {
                                        $pcState = ['label' => 'Removal pending', 'cls' => 'bg-amber-100 text-amber-800 border-amber-200', 'icon' => 'M12 9v2m0 4h.01'];
                                    } elseif ($job->advance_issued_at) {
                                        $pcState = ['label' => 'Issued', 'cls' => 'bg-blue-100 text-blue-800 border-blue-200', 'icon' => 'M5 13l4 4L19 7'];
                                    } elseif ($job->advance_approved_at) {
                                        $pcState = ['label' => 'Approved', 'cls' => 'bg-emerald-100 text-emerald-800 border-emerald-200', 'icon' => 'M5 13l4 4L19 7'];
                                    } elseif ($job->advance_plan_id) {
                                        $pcState = ['label' => 'On plan', 'cls' => 'bg-indigo-100 text-indigo-800 border-indigo-200', 'icon' => 'M9 12h6M12 9v6'];
                                    } else {
                                        $pcState = ['label' => 'Saved', 'cls' => 'bg-slate-100 text-slate-700 border-slate-200', 'icon' => 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4'];
                                    }
                                }
                            @endphp
                            @if($pcState)
                                <div class="flex flex-col gap-0.5">
                                    <span class="inline-flex items-center gap-1 self-start rounded-full border px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider {{ $pcState['cls'] }}">
                                        <svg class="h-2.5 w-2.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $pcState['icon'] }}"/></svg>
                                        {{ $pcState['label'] }}
                                    </span>
                                    <span class="text-[11px] font-mono tabular-nums text-slate-600">R {{ number_format((float) $job->advance_total, 2) }}</span>
                                </div>
                            @else
                                <span class="text-xs text-slate-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3.5">
                            @if($job->driver)
                                <div class="flex items-center gap-2">
                                    <span class="h-6 w-6 rounded-full bg-gradient-to-br from-slate-700 to-slate-900 text-white text-[10px] font-semibold flex items-center justify-center">{{ strtoupper(substr($job->driver->name, 0, 1)) }}</span>
                                    <span class="text-xs text-slate-600 truncate max-w-[120px]">{{ $job->driver->name }}</span>
                                </div>
                            @else
                                <span class="text-xs text-slate-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3.5 text-right">
                            <span class="text-xs text-slate-500">{{ $job->created_at->format('d M Y') }}</span>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="9">
                            <x-empty-state
                                :title="$search || $statusFilter ? 'No matches' : 'No orders yet'"
                                :description="$search || $statusFilter ? 'Try adjusting your search or filters.' : 'Orders will appear here as soon as customers submit bookings.'">
                                <x-slot:icon>
                                    <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg>
                                </x-slot:icon>
                                @if($search || $statusFilter)
                                    <x-slot:actions>
                                        <x-button variant="secondary" size="sm" wire:click="clearFilters">Clear filters</x-button>
                                    </x-slot:actions>
                                @endif
                            </x-empty-state>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if($jobs->hasPages())
        <div class="mt-4">
            {{ $jobs->links() }}
        </div>
    @endif
</div>
