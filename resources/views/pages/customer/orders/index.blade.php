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

    #[Url(as: 'owner_pending')]
    public bool $ownerPendingOnly = false;

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

    public function updatedOwnerPendingOnly(): void
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

        // Dealer-visible orders: jobs the dealer placed themselves
        // (company_id) AND jobs placed by someone else against a
        // vehicle on their stock ledger (owner_company_id -- e.g. a
        // BB raising a direct order with Proselver against their VIN).
        // Owner-only rows are masked of commercial detail on the show
        // page, but they MUST appear in this list so the dealer can
        // see + approve them.
        $query = Job::query()
            ->where(function ($q) use ($effectiveCompanyIds) {
                $q->whereIn('company_id', $effectiveCompanyIds)
                    ->orWhereIn('owner_company_id', $effectiveCompanyIds);
            })
            ->with([
                'pickupLocation:id,company_name,city',
                'deliveryLocation:id,company_name,city',
                'brand:id,name',
                'driver:id,name',
                'company:id,name',
                'ownerCompany:id,name',
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
                    ->orWhere('registration', 'like', "%{$this->search}%")
                    ->orWhere('model_name', 'like', "%{$this->search}%")
                    ->orWhereHas('brand', fn($q) => $q->where('name', 'like', "%{$this->search}%"))
                    ->orWhereHas('pickupLocation', fn($q) => $q->where('company_name', 'like', "%{$this->search}%"))
                    ->orWhereHas('deliveryLocation', fn($q) => $q->where('company_name', 'like', "%{$this->search}%"))
                    ->orWhereHas('driver', fn($q) => $q->where('name', 'like', "%{$this->search}%"));
            });
        }

        if ($this->statusFilter) {
            $query->whereIn('status', \App\Models\Job::expandStatusFilter($this->statusFilter));
        }

        if ($this->ownerPendingOnly) {
            $query->whereIn('owner_company_id', $effectiveCompanyIds)
                ->where('requires_owner_approval', true)
                ->where('owner_approval_status', Job::OWNER_APPROVAL_PENDING);
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

        // Pending-owner-approval badge: how many jobs are waiting
        // for THIS dealer to OK a movement someone else raised on
        // their stock.  Surfaces on the page as a top-line callout
        // so dealers can't miss BB direct orders.
        $pendingOwnerApprovalCount = Job::query()
            ->whereIn('owner_company_id', $effectiveCompanyIds)
            ->where('requires_owner_approval', true)
            ->where('owner_approval_status', Job::OWNER_APPROVAL_PENDING)
            ->count();

        return [
            'jobs' => $query->paginate(15),
            'archivedCount' => Job::whereIn('company_id', $visibleCompanyIds)->whereNotNull('archived_at')->count(),
            'visibleCompanies' => $visibleCompanies,
            'isMultiCompany' => $visibleCompanies->isNotEmpty(),
            'pendingOwnerApprovalCount' => $pendingOwnerApprovalCount,
            'currentCompanyIds' => $effectiveCompanyIds,
        ];
    }
};

?>

<div>
    <x-slot:header>Orders</x-slot:header>

    {{-- Dealership chip strip (multi-tenant CEO only) --}}
    <x-dealership-chip-strip :companies="$visibleCompanies" :selected="$dealershipFilter" wire-model="dealershipFilter" />

    @if($pendingOwnerApprovalCount > 0)
        <div class="mb-4 rounded-xl border-2 border-amber-300 bg-amber-50 p-4 flex items-start gap-3">
            <svg class="h-6 w-6 mt-0.5 shrink-0 text-amber-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>
            <div class="flex-1">
                <div class="text-sm font-bold text-amber-900">
                    {{ $pendingOwnerApprovalCount }} movement{{ $pendingOwnerApprovalCount === 1 ? '' : 's' }} awaiting your approval
                </div>
                <p class="text-xs text-amber-800 mt-0.5">
                    A linked body builder booked ProSelver directly to move a vehicle on your stock ledger.
                    These are <strong>direct orders</strong> — not movement requests. Open each one below to approve or reject.
                </p>
            </div>
            @if(!$ownerPendingOnly)
                <button type="button" wire:click="$set('ownerPendingOnly', true)"
                        class="shrink-0 rounded-md bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-700 transition">
                    Show only these
                </button>
            @endif
        </div>
    @endif

    @if($ownerPendingOnly)
        <div class="mb-4 flex items-center gap-2 text-sm text-amber-900">
            <span class="font-semibold">Showing owner approvals only.</span>
            <button type="button" wire:click="$set('ownerPendingOnly', false)" class="text-xs font-semibold text-blue-600 hover:text-blue-800">Clear filter</button>
        </div>
    @endif

    {{-- Filters --}}
    <div class="mb-6 flex flex-col sm:flex-row gap-4 items-start sm:items-center">
        <div class="flex-1 w-full">
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search by order #, VIN, make/model, route, or driver..."
                class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
        </div>
        <select wire:model.live="statusFilter" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
            <option value="">All Statuses</option>
            @foreach(\App\Models\Job::phase1FilterOptions() as $value => $label)
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
                @php
                    $ownsButDidntPlace = $job->owner_company_id
                        && in_array($job->owner_company_id, $currentCompanyIds, true)
                        && !in_array($job->company_id, $currentCompanyIds, true);
                @endphp
                <tr class="hover:bg-gray-50 cursor-pointer" onclick="window.location='{{ route('customer.orders.show', $job) }}'">
                    <td class="px-4 py-3 text-sm font-medium text-blue-600">
                        {{ $job->job_number ?? '—' }}
                        @if($ownsButDidntPlace && $job->isPendingOwnerApproval())
                            <span class="ml-1 inline-flex items-center rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-800 align-middle">approve</span>
                        @elseif($ownsButDidntPlace)
                            <span class="ml-1 inline-flex items-center rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-600 align-middle">by {{ $job->company?->name }}</span>
                        @endif
                    </td>
                    @if($isMultiCompany)
                        <td class="px-4 py-3 text-sm text-gray-700">{{ ($ownsButDidntPlace ? $job->ownerCompany?->name : $job->company?->name) ?? '—' }}</td>
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
