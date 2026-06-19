<?php

use App\Models\Job;
use App\Models\Company;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public ?Company $company = null;

    #[Url]
    public string $search = '';

    #[Url]
    public string $statusFilter = '';

    // Dealership filter for franchise CEOs whose visibleCompanyIds spans
    // multiple sibling dealerships.  Empty string = "all my dealerships".
    // Single-company users never see this control.
    #[Url(as: 'dealership')]
    public string $dealershipFilter = '';

    // Default: hide archived (out-of-stock) movements from the active
    // orders list. The dealer flips this on if they want to bring back
    // archived rows — reports always show them either way.
    #[Url(as: 'archived')]
    public bool $showArchived = false;

    public function mount(): void
    {
        $this->company = auth()->user()->company();
        abort_unless($this->company, 403, 'No company associated with your account.');
    }

    public function updatedDealershipFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedShowArchived(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        $user = auth()->user();
        $visibleCompanyIds = $user->visibleCompanyIds();

        // Franchise-CEO scoping: visibleCompanyIds returns every
        // dealership the user is pivoted into.  Single-dealership users
        // get [$this->company->id] and the page behaves exactly as
        // before; group principals see every sibling unless they
        // narrow via the dealership filter chip-strip below.
        $effectiveCompanyIds = $this->dealershipFilter !== ''
            ? array_values(array_intersect($visibleCompanyIds, [(int) $this->dealershipFilter]))
            : $visibleCompanyIds;

        $query = Job::whereIn('company_id', $effectiveCompanyIds)
            ->with([
                'pickupLocation:id,company_name,city',
                'deliveryLocation:id,company_name,city',
                'brand:id,name',
                'driver:id,name',
                'company:id,name',
            ])
            ->latest('created_at');

        $locationId = $user->assignedLocationId();
        $isRestricted = $locationId && !$user->canManageCompanyData();
        if ($isRestricted) {
            $query->where(function ($q) use ($locationId) {
                $q->where('pickup_location_id', $locationId)
                    ->orWhere('delivery_location_id', $locationId);
            });
        }

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('job_number', 'like', "%{$this->search}%")
                    ->orWhere('vin', 'like', "%{$this->search}%")
                    ->orWhere('model_name', 'like', "%{$this->search}%")
                    ->orWhereHas('brand', fn($q) => $q->where('name', 'like', "%{$this->search}%"))
                    ->orWhereHas('pickupLocation', fn($q) => $q->where('company_name', 'like', "%{$this->search}%"))
                    ->orWhereHas('deliveryLocation', fn($q) => $q->where('company_name', 'like', "%{$this->search}%"))
                    ->orWhereHas('driver', fn($q) => $q->where('name', 'like', "%{$this->search}%"));
            });
        }

        if ($this->statusFilter) {
            $query->where('status', $this->statusFilter);
        }

        if (! $this->showArchived) {
            $query->whereNull('archived_at');
        }

        // Dealership chip-strip options when multi-tenant.  Pulled in
        // one go from companies so a CEO sees the actual dealership
        // names ("Williams Hunt Sandton" / "Bryanston" / "Fourways")
        // rather than ID numbers.
        $visibleCompanies = count($visibleCompanyIds) > 1
            ? Company::whereIn('id', $visibleCompanyIds)->orderBy('name')->get(['id', 'name'])
            : collect();

        return [
            'jobs' => $query->paginate(15),
            'archivedCount' => Job::whereIn('company_id', $visibleCompanyIds)->whereNotNull('archived_at')->count(),
            'visibleCompanies' => $visibleCompanies,
            'isMultiCompany' => $visibleCompanies->isNotEmpty(),
        ];
    }
};

?>

<div>
    <x-slot:header>Orders</x-slot:header>

    {{-- Dealership chip strip (multi-tenant CEO only) --}}
    <x-dealership-chip-strip :companies="$visibleCompanies" :selected="$dealershipFilter" wire-model="dealershipFilter" />

    {{-- Filters --}}
    <div class="mb-6 flex flex-col sm:flex-row gap-4 items-start sm:items-center">
        <div class="flex-1 w-full">
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search by order #, VIN, make/model, route, or driver..."
                class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
        </div>
        <select wire:model.live="statusFilter" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
            <option value="">All Statuses</option>
            @foreach(\App\Models\Job::PHASE1_STATUS_LABELS as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
        <label class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 cursor-pointer hover:bg-gray-50">
            <input type="checkbox" wire:model.live="showArchived" class="h-4 w-4 rounded border-gray-300 text-blue-600">
            <span>Show archived</span>
            @if($archivedCount > 0)
                <span class="text-xs text-gray-500">({{ $archivedCount }})</span>
            @endif
        </label>
    </div>

    {{-- Orders Table --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Order #</th>
                    @if($isMultiCompany)
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Dealership</th>
                    @endif
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Make / Model</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">VIN</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Pickup</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Delivery</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Driver</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($jobs as $job)
                <tr class="hover:bg-gray-50 cursor-pointer" onclick="window.location='{{ route('customer.orders.show', $job) }}'">
                    <td class="px-4 py-3 text-sm font-medium text-blue-600">{{ $job->job_number ?? '—' }}</td>
                    @if($isMultiCompany)
                        <td class="px-4 py-3 text-sm text-gray-700">{{ $job->company?->name ?? '—' }}</td>
                    @endif
                    <td class="px-4 py-3 text-sm text-gray-900">{{ $job->brand?->name }} {{ $job->model_name }}</td>
                    <td class="px-4 py-3 text-sm font-mono text-gray-600 uppercase">{{ $job->vin ? strtoupper($job->vin) : '—' }}</td>
                    <td class="px-4 py-3 text-sm text-gray-600">{{ $job->pickupLocation?->shortDisplay() ?? '—' }}</td>
                    <td class="px-4 py-3 text-sm text-gray-600">{{ $job->deliveryLocation?->shortDisplay() ?? '—' }}</td>
                    <td class="px-4 py-3"><x-status-badge :status="$job->status" /></td>
                    <td class="px-4 py-3 text-sm text-gray-500">{{ $job->scheduled_date?->format('d M Y') ?? $job->created_at->format('d M Y') }}</td>
                    <td class="px-4 py-3 text-sm text-gray-600">{{ $job->driver?->name ?? '—' }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="{{ $isMultiCompany ? 9 : 8 }}" class="px-6 py-12 text-center text-sm text-gray-500">No orders found.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $jobs->links() }}
    </div>
</div>
