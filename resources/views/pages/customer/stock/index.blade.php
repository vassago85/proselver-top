<?php

use App\Models\Company;
use App\Models\DealerStock;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/*
 * Dealer stock ledger -- the canonical list of vehicles a dealer
 * has on the books, scoped by the user's visibleCompanyIds() and
 * filterable by location bucket and commercial status.  Sale /
 * demo / archive actions live on the per-vehicle show page; this
 * is just the table.
 */
new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url(as: 'bucket')]
    public string $bucketFilter = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    #[Url(as: 'dealership')]
    public string $dealershipFilter = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->hasPermission('view_dealer_stock'), 403);
    }

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedBucketFilter(): void { $this->resetPage(); }
    public function updatedStatusFilter(): void { $this->resetPage(); }
    public function updatedDealershipFilter(): void { $this->resetPage(); }

    public function with(): array
    {
        $user = auth()->user();
        $visibleCompanyIds = $user->visibleCompanyIds();

        $effectiveCompanyIds = $this->dealershipFilter !== ''
            ? array_values(array_intersect($visibleCompanyIds, [(int) $this->dealershipFilter]))
            : $visibleCompanyIds;

        $query = DealerStock::query()
            ->whereIn('dealer_company_id', $effectiveCompanyIds)
            ->with(['brand:id,name', 'currentLocation:id,company_name,city', 'dealerCompany:id,name', 'salesperson:id,name'])
            ->whereNull('archived_at')
            ->orderByDesc('updated_at');

        if ($this->bucketFilter !== '') {
            $query->where('current_location_type', $this->bucketFilter);
        }

        if ($this->statusFilter !== '') {
            $query->where('status', $this->statusFilter);
        }

        if ($this->search !== '') {
            $needle = trim($this->search);
            $query->where(function ($q) use ($needle) {
                $q->where('vin', 'like', "%{$needle}%")
                    ->orWhere('registration', 'like', "%{$needle}%")
                    ->orWhere('engine_number', 'like', "%{$needle}%")
                    ->orWhere('model_name', 'like', "%{$needle}%")
                    ->orWhere('variant', 'like', "%{$needle}%")
                    ->orWhere('suffix', 'like', "%{$needle}%")
                    ->orWhere('colour', 'like', "%{$needle}%")
                    ->orWhereHas('brand', fn ($b) => $b->where('name', 'like', "%{$needle}%"));
            });
        }

        // Counts per bucket / status -- shown in the filter strip
        // to telegraph how many rows the filters will surface.
        $baseCounts = DealerStock::query()
            ->whereIn('dealer_company_id', $effectiveCompanyIds)
            ->whereNull('archived_at');

        $bucketCounts = [
            DealerStock::LOCATION_PREMISES     => (clone $baseCounts)->where('current_location_type', DealerStock::LOCATION_PREMISES)->count(),
            DealerStock::LOCATION_BODY_BUILDER => (clone $baseCounts)->where('current_location_type', DealerStock::LOCATION_BODY_BUILDER)->count(),
            DealerStock::LOCATION_STORAGE      => (clone $baseCounts)->where('current_location_type', DealerStock::LOCATION_STORAGE)->count(),
            DealerStock::LOCATION_IN_TRANSIT   => (clone $baseCounts)->where('current_location_type', DealerStock::LOCATION_IN_TRANSIT)->count(),
            DealerStock::LOCATION_ON_DEMO      => (clone $baseCounts)->where('current_location_type', DealerStock::LOCATION_ON_DEMO)->count(),
            DealerStock::LOCATION_DELIVERED    => (clone $baseCounts)->where('current_location_type', DealerStock::LOCATION_DELIVERED)->count(),
        ];

        $visibleCompanies = count($visibleCompanyIds) > 1
            ? Company::whereIn('id', $visibleCompanyIds)->orderBy('name')->get(['id', 'name'])
            : collect();

        return [
            'rows' => $query->paginate(25),
            'bucketCounts' => $bucketCounts,
            'bucketLabels' => [
                DealerStock::LOCATION_PREMISES     => 'At premises',
                DealerStock::LOCATION_BODY_BUILDER => 'Body builder',
                DealerStock::LOCATION_STORAGE      => 'Other storage',
                DealerStock::LOCATION_IN_TRANSIT   => 'In transit',
                DealerStock::LOCATION_ON_DEMO      => 'On demo',
                DealerStock::LOCATION_DELIVERED    => 'Delivered',
            ],
            'statusLabels' => [
                DealerStock::STATUS_AVAILABLE => 'Available',
                DealerStock::STATUS_RESERVED  => 'Reserved',
                DealerStock::STATUS_SOLD      => 'Sold',
                DealerStock::STATUS_DEMO      => 'On demo',
                DealerStock::STATUS_ARCHIVED  => 'Archived',
            ],
            'visibleCompanies' => $visibleCompanies,
            'isMultiCompany' => $visibleCompanies->isNotEmpty(),
            'canManageStock' => $user->hasPermission('manage_dealer_stock'),
        ];
    }
};
?>

<div>
    <x-slot:header>Stock</x-slot:header>

    @if(session('success'))
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('success') }}</div>
    @endif

    <div class="mb-4 flex flex-wrap items-center gap-2">
        @if($canManageStock)
            <a href="{{ route('customer.stock.import') }}"
               class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-blue-500 transition-colors">
                Import stock
            </a>
        @endif
    </div>

    <x-dealership-chip-strip :companies="$visibleCompanies" :selected="$dealershipFilter" wire-model="dealershipFilter" />

    {{-- Bucket chips --}}
    <div class="mb-3 flex flex-wrap gap-2">
        @php
            $colours = [
                'premises'     => ['bg-slate-100 text-slate-800 border-slate-300',  'bg-slate-900 text-white border-slate-900'],
                'body_builder' => ['bg-amber-50 text-amber-800 border-amber-200',   'bg-amber-600 text-white border-amber-600'],
                'storage'      => ['bg-indigo-50 text-indigo-800 border-indigo-200','bg-indigo-600 text-white border-indigo-600'],
                'in_transit'   => ['bg-blue-50 text-blue-800 border-blue-200',      'bg-blue-600 text-white border-blue-600'],
                'on_demo'      => ['bg-teal-50 text-teal-800 border-teal-200',      'bg-teal-600 text-white border-teal-600'],
                'delivered'    => ['bg-emerald-50 text-emerald-800 border-emerald-200', 'bg-emerald-600 text-white border-emerald-600'],
            ];
        @endphp

        <button wire:click="$set('bucketFilter', '')" @class([
            'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold transition-colors',
            'bg-slate-900 text-white border-slate-900' => $bucketFilter === '',
            'bg-white text-slate-700 border-slate-300 hover:bg-slate-50' => $bucketFilter !== '',
        ])>
            All buckets <span class="rounded-full bg-white/20 px-1.5 text-[10px]">{{ array_sum($bucketCounts) }}</span>
        </button>
        @foreach($bucketLabels as $key => $label)
            @php $active = $bucketFilter === $key; @endphp
            <button wire:click="$set('bucketFilter', '{{ $key }}')" @class([
                'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold transition-colors',
                $colours[$key][1] => $active,
                $colours[$key][0] . ' hover:opacity-80' => !$active,
            ])>
                {{ $label }} <span class="rounded-full bg-white/30 px-1.5 text-[10px] tabular-nums">{{ $bucketCounts[$key] }}</span>
            </button>
        @endforeach
    </div>

    {{-- Search + status filter --}}
    <div class="mb-4 flex flex-col sm:flex-row gap-3">
        <input wire:model.live.debounce.300ms="search" type="text"
               placeholder="VIN, registration, model, variant, colour…"
               class="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
        <select wire:model.live="statusFilter"
                class="rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
            <option value="">Any status</option>
            @foreach($statusLabels as $key => $label)
                <option value="{{ $key }}">{{ $label }}</option>
            @endforeach
        </select>
    </div>

    {{-- Table --}}
    <div class="rounded-xl border border-slate-200 bg-white overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200">
            <thead class="bg-slate-50">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-medium uppercase text-slate-500">VIN / NIV</th>
                    @if($isMultiCompany)
                        <th class="px-3 py-2 text-left text-xs font-medium uppercase text-slate-500">Dealership</th>
                    @endif
                    <th class="px-3 py-2 text-left text-xs font-medium uppercase text-slate-500">Vehicle</th>
                    <th class="px-3 py-2 text-left text-xs font-medium uppercase text-slate-500">Colour</th>
                    <th class="px-3 py-2 text-left text-xs font-medium uppercase text-slate-500">Reg</th>
                    <th class="px-3 py-2 text-left text-xs font-medium uppercase text-slate-500">Bucket</th>
                    <th class="px-3 py-2 text-left text-xs font-medium uppercase text-slate-500">Status</th>
                    <th class="px-3 py-2 text-left text-xs font-medium uppercase text-slate-500">Last update</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 bg-white">
                @forelse($rows as $row)
                    <tr class="text-sm hover:bg-slate-50 cursor-pointer" onclick="window.location='{{ route('customer.stock.show', $row) }}'">
                        <td class="px-3 py-2 font-mono text-slate-700">{{ $row->vin }}</td>
                        @if($isMultiCompany)
                            <td class="px-3 py-2 text-slate-700">{{ $row->dealerCompany?->name }}</td>
                        @endif
                        <td class="px-3 py-2 text-slate-700">
                            <div class="font-medium text-slate-900">{{ $row->brand?->name }} {{ $row->model_name }}</div>
                            @if($row->variant || $row->suffix)
                                <div class="text-xs text-slate-500">{{ trim(($row->variant ?? '') . ' ' . ($row->suffix ?? '')) }}</div>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-slate-700">{{ $row->colour ?: '—' }}</td>
                        <td class="px-3 py-2 font-mono text-slate-700">{{ $row->registration ?: '—' }}</td>
                        <td class="px-3 py-2">
                            <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-semibold {{ ($colours[$row->current_location_type][0] ?? 'bg-slate-100 text-slate-700 border-slate-300') }}">
                                {{ $bucketLabels[$row->current_location_type] ?? $row->current_location_type }}
                            </span>
                        </td>
                        <td class="px-3 py-2 text-slate-700">
                            <span>{{ $statusLabels[$row->status] ?? $row->status }}</span>
                            @if($row->status !== \App\Models\DealerStock::STATUS_ARCHIVED)
                                <a href="{{ route('dealer-stock.sale-delivery-note.download', $row) }}"
                                   target="_blank" rel="noopener"
                                   onclick="event.stopPropagation()"
                                   title="Print delivery note"
                                   class="ml-1 inline-flex text-slate-400 hover:text-blue-600 align-middle">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/></svg>
                                </a>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-slate-500">{{ $row->updated_at->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $isMultiCompany ? 8 : 7 }}" class="px-3 py-12 text-center text-sm text-slate-500">
                            No stock matches these filters.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $rows->links() }}
    </div>
</div>
