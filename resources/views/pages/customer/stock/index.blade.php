<?php

use App\Models\Company;
use App\Models\DealerStock;
use App\Models\Location;
use App\Models\User;
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

    /**
     * Multi-select body-builder filter -- IDs of Company rows (BB
     * tenants) whose Locations host any of the dealer's stock.  The
     * picker only ever surfaces the BBs the dealer is actually
     * dealing with, so we don't drown them in 200 random BB names.
     *
     * @var array<int>
     */
    #[Url(as: 'bb')]
    public array $bodyBuilderFilter = [];

    /**
     * Multi-select salesperson filter -- user IDs.
     *
     * @var array<int>
     */
    #[Url(as: 'sp')]
    public array $salespersonFilter = [];

    public function mount(): void
    {
        // Dealer-tenant only.  OEM-customer accounts also carry the
        // view_dealer_stock perm (via the shared customer_owner role)
        // but the stock ledger -- and its sold/reserved/archive
        // lifecycle -- is a dealer concept.  An OEM hitting this URL
        // gets a 404 rather than a 403 so the page is invisible
        // entirely from their portal.
        abort_unless(auth()->user()?->company()?->isDealer(), 404);
        abort_unless(auth()->user()?->hasPermission('view_dealer_stock'), 403);
    }

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedBucketFilter(): void { $this->resetPage(); }
    public function updatedStatusFilter(): void { $this->resetPage(); }
    public function updatedDealershipFilter(): void { $this->resetPage(); }
    public function updatedBodyBuilderFilter(): void { $this->resetPage(); }
    public function updatedSalespersonFilter(): void { $this->resetPage(); }

    /**
     * Pill-style toggle: clicking the same value removes it from the
     * filter array; clicking a new value adds it.  Cast to int so we
     * never store a string-vs-int mismatch (Livewire URL hydration
     * can pull either back).
     */
    public function toggleBodyBuilder(int $companyId): void
    {
        $existing = array_map('intval', $this->bodyBuilderFilter);
        $this->bodyBuilderFilter = in_array($companyId, $existing, true)
            ? array_values(array_diff($existing, [$companyId]))
            : array_values(array_merge($existing, [$companyId]));
        $this->resetPage();
    }

    public function toggleSalesperson(int $userId): void
    {
        $existing = array_map('intval', $this->salespersonFilter);
        $this->salespersonFilter = in_array($userId, $existing, true)
            ? array_values(array_diff($existing, [$userId]))
            : array_values(array_merge($existing, [$userId]));
        $this->resetPage();
    }

    public function clearBodyBuilderFilter(): void
    {
        $this->bodyBuilderFilter = [];
        $this->resetPage();
    }

    public function clearSalespersonFilter(): void
    {
        $this->salespersonFilter = [];
        $this->resetPage();
    }

    public function with(): array
    {
        $user = auth()->user();
        $visibleCompanyIds = $user->visibleCompanyIds();

        $effectiveCompanyIds = $this->dealershipFilter !== ''
            ? array_values(array_intersect($visibleCompanyIds, [(int) $this->dealershipFilter]))
            : $visibleCompanyIds;

        // The Delivered bucket is the only view that crosses the
        // archive boundary -- delivered rows have archived_at set by
        // design, so we drop the archived_at filter for that case
        // and let scopeRecentlyDelivered do the work.  Every other
        // bucket stays on the active ledger.
        $crossesArchive = $this->bucketFilter === 'recently_delivered';

        $query = DealerStock::query()
            ->whereIn('dealer_company_id', $effectiveCompanyIds)
            ->with([
                'brand:id,name',
                'currentLocation:id,company_name,city,company_id',
                'currentLocation.company:id,name',
                'dealerCompany:id,name',
                'salesperson:id,name',
                'currentJob:id,job_number,status,scheduled_date',
            ])
            ->when(!$crossesArchive, fn ($q) => $q->whereNull('archived_at'))
            ->orderByDesc('updated_at');

        if ($this->bucketFilter !== '') {
            match ($this->bucketFilter) {
                'scheduled'          => $query->scheduledForMovement(),
                'recently_sold'      => $query->recentlySold(),
                'recently_delivered' => $query->recentlyDelivered(),
                default              => $query->where('current_location_type', $this->bucketFilter),
            };
        }

        if ($this->statusFilter !== '') {
            $query->where('status', $this->statusFilter);
        }

        // Body-builder filter: vehicle's current_location must belong
        // to one of the picked BB companies.  Implies the bucket is
        // body_builder so we narrow that too (saves a join hit when
        // someone picks a BB without first clicking the bucket).
        if (!empty($this->bodyBuilderFilter)) {
            $query->where('current_location_type', DealerStock::LOCATION_BODY_BUILDER)
                ->whereHas('currentLocation', fn ($q) => $q->whereIn('company_id', array_map('intval', $this->bodyBuilderFilter)));
        }

        if (!empty($this->salespersonFilter)) {
            $query->whereIn('salesperson_user_id', array_map('intval', $this->salespersonFilter));
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
            'scheduled'          => (clone $baseCounts)->scheduledForMovement()->count(),
            'recently_sold'      => (clone $baseCounts)->recentlySold()->count(),
            // Delivered bucket crosses archived_at -- count it from
            // the unscoped per-company query instead of $baseCounts.
            'recently_delivered' => DealerStock::query()
                ->whereIn('dealer_company_id', $effectiveCompanyIds)
                ->recentlyDelivered()
                ->count(),
        ];

        $visibleCompanies = count($visibleCompanyIds) > 1
            ? Company::whereIn('id', $visibleCompanyIds)->orderBy('name')->get(['id', 'name'])
            : collect();

        // BB picker -- only the BBs actually holding the dealer's
        // stock right now (so the filter strip stays short and
        // relevant).  Built off the bucket-counted dataset so we
        // ignore the user's own bucket/search filters; otherwise
        // selecting "Pretoria BB" would hide every other BB.
        $bbOptions = Location::query()
            ->whereIn('id', (clone $baseCounts)
                ->where('current_location_type', DealerStock::LOCATION_BODY_BUILDER)
                ->whereNotNull('current_location_id')
                ->distinct()
                ->pluck('current_location_id')
            )
            ->with('company:id,name')
            ->get(['id', 'company_id', 'company_name'])
            ->groupBy('company_id')
            ->map(function ($locs, $companyId) {
                $first = $locs->first();
                return [
                    'id'    => (int) $companyId,
                    'name'  => $first->company?->name ?: $first->company_name,
                    'count' => $locs->count(),
                ];
            })
            ->sortBy('name')
            ->values();

        // Salesperson picker -- users who own at least one stock row
        // in scope.  Same "only show people that have stock" logic as
        // BBs so we don't enumerate every sales role on the company.
        $spOptions = User::query()
            ->whereIn('id', (clone $baseCounts)
                ->whereNotNull('salesperson_user_id')
                ->distinct()
                ->pluck('salesperson_user_id')
            )
            ->orderBy('name')
            ->get(['id', 'name']);

        // "First-run" detector -- did this dealer ever ledger ANY stock?
        // Used to swap the empty table for a big "import your stock"
        // welcome card.  Includes archived rows so re-archiving the
        // last vehicle doesn't trip the empty state back on.
        $hasAnyStockEver = DealerStock::query()
            ->whereIn('dealer_company_id', $effectiveCompanyIds)
            ->exists();

        return [
            'rows' => $query->paginate(25),
            'hasAnyStockEver' => $hasAnyStockEver,
            'bucketCounts' => $bucketCounts,
            'bbOptions' => $bbOptions,
            'spOptions' => $spOptions,
            'bucketLabels' => [
                DealerStock::LOCATION_PREMISES     => 'At premises',
                DealerStock::LOCATION_BODY_BUILDER => 'Body builder',
                DealerStock::LOCATION_STORAGE      => 'Other storage',
                DealerStock::LOCATION_IN_TRANSIT   => 'In transit',
                DealerStock::LOCATION_ON_DEMO      => 'On demo',
                DealerStock::LOCATION_DELIVERED    => 'Delivered to dealer',
                'scheduled'          => 'Scheduled for movement',
                'recently_sold'      => 'Recently sold',
                'recently_delivered' => 'Recently delivered',
            ],
            'bucketTooltips' => [
                DealerStock::LOCATION_PREMISES     => 'Physically at your dealership',
                DealerStock::LOCATION_BODY_BUILDER => 'Parked at a body builder or fitment centre',
                DealerStock::LOCATION_STORAGE      => 'Parked at another storage yard',
                DealerStock::LOCATION_IN_TRANSIT   => 'On the road with an active transport job',
                DealerStock::LOCATION_ON_DEMO      => 'Out on demo with a customer',
                DealerStock::LOCATION_DELIVERED    => 'A transport job ended at a dealer destination (vehicle arrived at the dealership)',
                'scheduled'          => 'A transport job is booked but collection has not started',
                'recently_sold'      => 'Sold but still on your books — Mark as delivered when the buyer takes the keys',
                'recently_delivered' => 'Marked delivered in the last 30 days — archived from the active board but kept here for your records',
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
            <a href="{{ route('customer.stock.create') }}"
               class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-blue-500 transition-colors">
                + Add vehicle
            </a>
            <a href="{{ route('customer.stock.import') }}"
               class="inline-flex items-center gap-2 rounded-lg border border-blue-600 bg-white px-3.5 py-2 text-sm font-semibold text-blue-700 hover:bg-blue-50 transition-colors">
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
                'scheduled'          => ['bg-sky-50 text-sky-800 border-sky-200',           'bg-sky-600 text-white border-sky-600'],
                'recently_sold'      => ['bg-green-50 text-green-800 border-green-200',     'bg-green-600 text-white border-green-600'],
                'recently_delivered' => ['bg-emerald-50 text-emerald-800 border-emerald-200', 'bg-emerald-600 text-white border-emerald-600'],
            ];
            $locationBucketKeys = [
                DealerStock::LOCATION_PREMISES,
                DealerStock::LOCATION_BODY_BUILDER,
                DealerStock::LOCATION_STORAGE,
                DealerStock::LOCATION_IN_TRANSIT,
                DealerStock::LOCATION_ON_DEMO,
                DealerStock::LOCATION_DELIVERED,
            ];
            $locationBucketTotal = collect($locationBucketKeys)->sum(fn ($k) => $bucketCounts[$k] ?? 0);
        @endphp

        <button wire:click="$set('bucketFilter', '')" @class([
            'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold transition-colors',
            'bg-slate-900 text-white border-slate-900' => $bucketFilter === '',
            'bg-white text-slate-700 border-slate-300 hover:bg-slate-50' => $bucketFilter !== '',
        ])>
            All buckets <span class="rounded-full bg-white/20 px-1.5 text-[10px]">{{ $locationBucketTotal }}</span>
        </button>
        @foreach($bucketLabels as $key => $label)
            @php $active = $bucketFilter === $key; @endphp
            <button wire:click="$set('bucketFilter', '{{ $key }}')" @class([
                'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold transition-colors',
                $colours[$key][1] => $active,
                $colours[$key][0] . ' hover:opacity-80' => !$active,
            ]) title="{{ $bucketTooltips[$key] ?? '' }}">
                {{ $label }} <span class="rounded-full bg-white/30 px-1.5 text-[10px] tabular-nums">{{ $bucketCounts[$key] }}</span>
            </button>
        @endforeach
    </div>

    {{-- Body-builder filter pills.  Only rendered when at least one
         BB hosts the dealer's stock -- no point showing an empty
         filter strip on dealers who never go to a body builder. --}}
    @if($bbOptions->isNotEmpty())
        <div class="mb-3">
            <div class="mb-1 flex items-center gap-2">
                <span class="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Body builders</span>
                @if(!empty($bodyBuilderFilter))
                    <button wire:click="clearBodyBuilderFilter" type="button"
                        class="text-[10px] font-semibold text-slate-500 hover:text-rose-600 underline">
                        Clear
                    </button>
                @endif
            </div>
            <div class="flex flex-wrap gap-2">
                @foreach($bbOptions as $bb)
                    @php $active = in_array((int) $bb['id'], array_map('intval', $bodyBuilderFilter), true); @endphp
                    <button type="button" wire:click="toggleBodyBuilder({{ $bb['id'] }})" @class([
                        'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-medium transition-colors',
                        'bg-amber-600 text-white border-amber-600' => $active,
                        'bg-amber-50 text-amber-900 border-amber-200 hover:bg-amber-100' => !$active,
                    ])>
                        {{ $bb['name'] }}
                        <span @class([
                            'rounded-full px-1.5 text-[10px] tabular-nums',
                            'bg-white/30' => $active,
                            'bg-amber-100 text-amber-700' => !$active,
                        ])>{{ $bb['count'] }}</span>
                    </button>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Salesperson filter pills.  Only rendered when at least one
         row has a salesperson attached. --}}
    @if($spOptions->isNotEmpty())
        <div class="mb-4">
            <div class="mb-1 flex items-center gap-2">
                <span class="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Salespeople</span>
                @if(!empty($salespersonFilter))
                    <button wire:click="clearSalespersonFilter" type="button"
                        class="text-[10px] font-semibold text-slate-500 hover:text-rose-600 underline">
                        Clear
                    </button>
                @endif
            </div>
            <div class="flex flex-wrap gap-2">
                @foreach($spOptions as $sp)
                    @php $active = in_array((int) $sp->id, array_map('intval', $salespersonFilter), true); @endphp
                    <button type="button" wire:click="toggleSalesperson({{ $sp->id }})" @class([
                        'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-medium transition-colors',
                        'bg-slate-900 text-white border-slate-900' => $active,
                        'bg-white text-slate-700 border-slate-300 hover:bg-slate-50' => !$active,
                    ])>
                        {{ $sp->name }}
                    </button>
                @endforeach
            </div>
        </div>
    @endif

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
        {{-- One-tap quick filter for Reserved -- the commercial-funnel
             status the dealer cares about most.  Independent of bucket
             so reserved-and-at-BB shows up here too. --}}
        <button type="button"
                wire:click="$set('statusFilter', '{{ $statusFilter === \App\Models\DealerStock::STATUS_RESERVED ? '' : \App\Models\DealerStock::STATUS_RESERVED }}')"
                @class([
                    'inline-flex items-center gap-1.5 rounded-lg border px-3 py-2 text-sm font-semibold transition-colors',
                    'bg-amber-600 text-white border-amber-600' => $statusFilter === \App\Models\DealerStock::STATUS_RESERVED,
                    'bg-white text-amber-700 border-amber-300 hover:bg-amber-50' => $statusFilter !== \App\Models\DealerStock::STATUS_RESERVED,
                ])>
            Reserved only
        </button>
    </div>

    {{-- Table --}}
    <div class="rounded-xl border border-slate-200 bg-white overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200">
            <thead class="bg-slate-50">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-medium uppercase text-slate-500">VIN</th>
                    @if($isMultiCompany)
                        <th class="px-3 py-2 text-left text-xs font-medium uppercase text-slate-500">Dealership</th>
                    @endif
                    <th class="px-3 py-2 text-left text-xs font-medium uppercase text-slate-500">Vehicle</th>
                    <th class="px-3 py-2 text-left text-xs font-medium uppercase text-slate-500">Colour</th>
                    <th class="px-3 py-2 text-left text-xs font-medium uppercase text-slate-500">Reg</th>
                    <th class="px-3 py-2 text-left text-xs font-medium uppercase text-slate-500">Where</th>
                    <th class="px-3 py-2 text-left text-xs font-medium uppercase text-slate-500">Status</th>
                    <th class="px-3 py-2 text-left text-xs font-medium uppercase text-slate-500">Salesperson</th>
                    <th class="px-3 py-2 text-left text-xs font-medium uppercase text-slate-500">Customer</th>
                    <th class="px-3 py-2 text-left text-xs font-medium uppercase text-slate-500">Last movement</th>
                    <th class="px-3 py-2"></th>
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
                            {{-- Concrete location surfaces underneath the bucket
                                 pill -- mainly so "Body builder" rows show
                                 WHICH builder (the bucket alone is useless when
                                 you're juggling 5 workshops). --}}
                            @if($row->currentLocation)
                                @php
                                    $locLabel = $row->currentLocation->company?->name
                                        ?: $row->currentLocation->company_name;
                                    if ($row->currentLocation->city && $locLabel) {
                                        $locLabel .= ' · ' . $row->currentLocation->city;
                                    }
                                @endphp
                                <div class="mt-0.5 text-[11px] text-slate-500 truncate max-w-[14rem]" title="{{ $locLabel }}">
                                    {{ $locLabel }}
                                </div>
                            @endif
                        </td>
                        <td class="px-3 py-2">
                            @php
                                $statusClass = match($row->status) {
                                    \App\Models\DealerStock::STATUS_RESERVED => 'bg-amber-50 text-amber-700 border-amber-200',
                                    \App\Models\DealerStock::STATUS_SOLD     => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                                    \App\Models\DealerStock::STATUS_DEMO     => 'bg-teal-50 text-teal-700 border-teal-200',
                                    \App\Models\DealerStock::STATUS_ARCHIVED => 'bg-slate-100 text-slate-600 border-slate-300',
                                    default => 'bg-slate-50 text-slate-700 border-slate-200',
                                };
                            @endphp
                            <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-semibold {{ $statusClass }}">
                                {{ $statusLabels[$row->status] ?? $row->status }}
                            </span>
                        </td>
                        <td class="px-3 py-2 text-slate-700">{{ $row->salesperson?->name ?: '—' }}</td>
                        <td class="px-3 py-2 text-slate-700">
                            @if($row->sale_customer_name)
                                <div class="font-medium text-slate-900 truncate max-w-[12rem]" title="{{ $row->sale_customer_name }}">{{ $row->sale_customer_name }}</div>
                                @if($row->sale_customer_phone)
                                    <div class="text-[11px] text-slate-500">{{ $row->sale_customer_phone }}</div>
                                @endif
                            @else
                                <span class="text-slate-400">—</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-slate-500">
                            @if($row->currentJob)
                                <div class="text-[11px] font-semibold text-blue-700">{{ $row->currentJob->job_number }}</div>
                                <div class="text-[11px] text-slate-500">{{ ucfirst(str_replace('_', ' ', $row->currentJob->status)) }}</div>
                            @else
                                <div class="text-[11px]">No active movement</div>
                            @endif
                            <div class="text-[10px] text-slate-400 mt-0.5">Updated {{ $row->updated_at->diffForHumans() }}</div>
                        </td>
                        <td class="px-3 py-2 text-right">
                            @php
                                $canBook = $row->status !== \App\Models\DealerStock::STATUS_ARCHIVED
                                    && $row->current_location_type !== \App\Models\DealerStock::LOCATION_IN_TRANSIT
                                    && $row->current_location_type !== \App\Models\DealerStock::LOCATION_DELIVERED;
                                $bookParams = array_filter([
                                    'vin'                => $row->vin,
                                    'pickup_location_id' => $row->current_location_id,
                                    'brand_id'           => $row->brand_id,
                                    'model_name'         => $row->model_name,
                                ]);
                            @endphp
                            <div class="inline-flex items-center gap-1.5">
                                @if($canBook)
                                    <a href="{{ route('customer.orders.create', $bookParams) }}"
                                       onclick="event.stopPropagation()"
                                       title="Book delivery for this vehicle"
                                       class="inline-flex items-center gap-1 rounded-md border border-blue-200 bg-blue-50 px-2 py-1 text-[11px] font-semibold text-blue-700 hover:bg-blue-100">
                                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 18V6a1 1 0 0 0-1-1H3a1 1 0 0 0-1 1v11a1 1 0 0 0 1 1h1"/><path d="M14 9h4l4 4v4a1 1 0 0 1-1 1h-1"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/></svg>
                                        Book
                                    </a>
                                @endif
                                @if($row->status !== \App\Models\DealerStock::STATUS_ARCHIVED)
                                    <a href="{{ route('dealer-stock.sale-delivery-note.download', $row) }}"
                                       target="_blank" rel="noopener"
                                       onclick="event.stopPropagation()"
                                       title="Print delivery note"
                                       class="inline-flex text-slate-400 hover:text-blue-600 align-middle">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/></svg>
                                    </a>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $isMultiCompany ? 11 : 10 }}" class="px-3 py-12 text-center text-sm text-slate-500">
                            @if(!$hasAnyStockEver)
                                <div class="mx-auto max-w-md space-y-3">
                                    <p class="text-base font-semibold text-slate-900">You haven't loaded any stock yet.</p>
                                    <p>
                                        Add vehicles one at a time, or drop your DMS export to load them all in a single pass &mdash;
                                        VIN, registration, engine number, model and colour map automatically.
                                    </p>
                                    @if($canManageStock)
                                        <p class="flex flex-wrap items-center justify-center gap-2">
                                            <a href="{{ route('customer.stock.create') }}"
                                               class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-500 transition-colors">
                                                + Add a vehicle
                                            </a>
                                            <a href="{{ route('customer.stock.import') }}"
                                               class="inline-flex items-center gap-2 rounded-lg border border-blue-600 bg-white px-4 py-2 text-sm font-semibold text-blue-700 hover:bg-blue-50 transition-colors">
                                                Import from spreadsheet
                                            </a>
                                        </p>
                                    @else
                                        <p class="text-xs text-slate-400">Ask a manager with <em>Manage dealer stock</em> permission to import.</p>
                                    @endif
                                </div>
                            @else
                                No stock matches these filters.
                            @endif
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
