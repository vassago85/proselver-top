<?php

use App\Models\Job;
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

    public function with(): array
    {
        $query = Job::with([
                'company:id,name,workflow_type',
                'pickupLocation:id,company_name,city',
                'deliveryLocation:id,company_name,city',
                'driver:id,name',
                'brand:id,name',
            ])
            ->whereIn('status', Job::PHASE1_STATUSES)
            ->orderByDesc('created_at');

        if ($this->statusFilter) {
            $query->where('status', $this->statusFilter);
        }

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('job_number', 'ilike', "%{$this->search}%")
                    ->orWhere('vin', 'ilike', "%{$this->search}%")
                    ->orWhere('model_name', 'ilike', "%{$this->search}%")
                    ->orWhereHas('brand', fn($q) => $q->where('name', 'ilike', "%{$this->search}%"))
                    ->orWhereHas('company', fn($q) => $q->where('name', 'ilike', "%{$this->search}%"))
                    ->orWhereHas('pickupLocation', fn($q) => $q->where('company_name', 'ilike', "%{$this->search}%"))
                    ->orWhereHas('deliveryLocation', fn($q) => $q->where('company_name', 'ilike', "%{$this->search}%"))
                    ->orWhereHas('driver', fn($q) => $q->where('name', 'ilike', "%{$this->search}%"));
            });
        }

        // Lightweight status counts for quick-filter pills
        $statusCounts = Job::whereIn('status', Job::PHASE1_STATUSES)
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->toArray();

        return [
            'jobs' => $query->paginate(20),
            'statuses' => Job::PHASE1_STATUS_LABELS,
            'statusCounts' => $statusCounts,
            'totalCount' => array_sum($statusCounts),
        ];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
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
            @if($search || $statusFilter)
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
    </div>

    {{-- Table --}}
    <div class="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead class="bg-slate-50/60 border-b border-slate-100">
                    <tr>
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-500">Order</th>
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-500">Customer</th>
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-500">Make / Model</th>
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-500">VIN</th>
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-500">Route</th>
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-500">Status</th>
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
                        <td class="px-4 py-3.5 text-xs font-mono uppercase text-slate-500 tracking-tight">{{ $job->vin ? strtoupper($job->vin) : '—' }}</td>
                        <td class="px-4 py-3.5">
                            <div class="flex items-center gap-1.5 text-xs text-slate-600">
                                <span class="truncate max-w-[100px]">{{ $job->pickupLocation?->shortDisplay() ?? '—' }}</span>
                                <svg viewBox="0 0 24 24" class="h-3 w-3 text-slate-300 shrink-0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                                <span class="truncate max-w-[100px]">{{ $job->deliveryLocation?->shortDisplay() ?? '—' }}</span>
                            </div>
                        </td>
                        <td class="px-4 py-3.5"><x-status-badge :status="$job->status" /></td>
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
                        <td colspan="8">
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
