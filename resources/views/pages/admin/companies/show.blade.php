<?php

use App\Models\Brand;
use App\Models\Company;
use App\Models\Job;
use App\Models\Location;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;

new #[Layout('components.layouts.app')] class extends Component {
    public Company $company;

    public string $name = '';
    public string $type = '';
    public string $workflowType = 'standard';
    public string $address = '';
    public string $vatNumber = '';
    public string $billingEmail = '';
    public string $phone = '';
    public bool $isActive = true;
    public array $selectedBrandIds = [];

    public bool $editing = false;

    public function mount(Company $company): void
    {
        $this->company = $company;
        $this->fillForm();
    }

    protected function fillForm(): void
    {
        $this->name = $this->company->name;
        $this->type = $this->company->type ?? Company::TYPE_DEALER;
        $this->workflowType = $this->company->workflow_type ?? 'standard';
        $this->address = $this->company->address ?? '';
        $this->vatNumber = $this->company->vat_number ?? '';
        $this->billingEmail = $this->company->billing_email ?? '';
        $this->phone = $this->company->phone ?? '';
        $this->isActive = $this->company->is_active;
        $this->selectedBrandIds = $this->company->brands()->pluck('brands.id')->map(fn($id) => (string) $id)->toArray();
    }

    public function toggleEdit(): void
    {
        $this->editing = !$this->editing;
        if (!$this->editing) {
            $this->fillForm();
        }
    }

    public function save(): void
    {
        // Type whitelist is the full Company::TYPES list now (dealer,
        // oem, body_builder, transporter, yard, internal, customer)
        // rather than the old 3-value subset, so admin can promote /
        // change a company without surgery on the DB.
        $this->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:' . implode(',', Company::TYPES),
            'workflowType' => 'required|in:standard,faw',
            'address' => 'nullable|string|max:500',
            'vatNumber' => 'nullable|string|max:20',
            'billingEmail' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:30',
            'selectedBrandIds' => 'array',
            'selectedBrandIds.*' => 'exists:brands,id',
        ]);

        $this->company->update([
            'name' => $this->name,
            'type' => $this->type,
            'workflow_type' => $this->workflowType,
            'address' => $this->address,
            'vat_number' => $this->vatNumber,
            'billing_email' => $this->billingEmail,
            'phone' => $this->phone,
            'is_active' => $this->isActive,
        ]);

        $this->company->brands()->sync(array_map('intval', $this->selectedBrandIds));

        $this->editing = false;
        session()->flash('success', 'Company updated.');
    }

    public function with(): array
    {
        $users = $this->company->users()
            ->with(['roles', 'companies' => fn($q) => $q->where('companies.id', $this->company->id)])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        $orderStats = [
            'total' => Job::where('company_id', $this->company->id)->count(),
            'active' => Job::where('company_id', $this->company->id)
                ->whereIn('status', [
                    Job::STATUS_RECEIVED, Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION,
                    Job::STATUS_CONFIRMATION_ISSUE,
                    Job::STATUS_CONFIRMED, Job::STATUS_PLANNED, Job::STATUS_DRIVER_ASSIGNED,
                    Job::STATUS_READY_FOR_COLLECTION, Job::STATUS_COLLECTED, Job::STATUS_IN_TRANSIT,
                ])->count(),
            'completed' => Job::where('company_id', $this->company->id)
                ->where('status', Job::STATUS_COMPLETED)->count(),
        ];

        $locations = $this->company->locations()->orderBy('company_name')->get();
        $allBrands = Brand::where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $companyBrands = $this->company->brands()->pluck('brands.id')->toArray();

        $typeLabels = [
            Company::TYPE_DEALER       => 'Dealer',
            Company::TYPE_OEM          => 'OEM',
            Company::TYPE_BODY_BUILDER => 'Body Builder',
            Company::TYPE_TRANSPORTER  => 'Transporter',
            Company::TYPE_YARD         => 'Yard / Storage',
            Company::TYPE_INTERNAL     => 'Internal (ProSelver)',
            Company::TYPE_CUSTOMER     => 'Customer',
        ];

        return compact('users', 'orderStats', 'locations', 'allBrands', 'companyBrands', 'typeLabels');
    }
};
?>

<div>
    <x-slot:header>
        <div class="flex items-center gap-3">
            <a href="{{ route('admin.companies.index') }}" class="text-gray-400 hover:text-gray-600">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
            </a>
            {{ $company->name }}
        </div>
    </x-slot:header>

    <div class="space-y-6">

        @if(session('success'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('success') }}</div>
        @endif

        {{-- Order Stats --}}
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="rounded-xl border border-gray-200 bg-white p-5">
                <p class="text-sm text-gray-500">Total Orders</p>
                <p class="mt-1 text-2xl font-bold text-gray-900">{{ $orderStats['total'] }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-5">
                <p class="text-sm text-gray-500">Active Orders</p>
                <p class="mt-1 text-2xl font-bold text-blue-600">{{ $orderStats['active'] }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-5">
                <p class="text-sm text-gray-500">Completed</p>
                <p class="mt-1 text-2xl font-bold text-green-600">{{ $orderStats['completed'] }}</p>
            </div>
        </div>

        {{-- Company Details --}}
        <div class="rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4">
                <h3 class="text-lg font-semibold text-gray-900">Company Details</h3>
                <button wire:click="toggleEdit" class="text-sm font-medium {{ $editing ? 'text-gray-500 hover:text-gray-700' : 'text-blue-600 hover:text-blue-800' }}">
                    {{ $editing ? 'Cancel' : 'Edit' }}
                </button>
            </div>
            <div class="p-6">
                @if($editing)
                <form wire:submit="save" class="space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Name *</label>
                            <input wire:model="name" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                            @error('name') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                            <select wire:model="type" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                                @foreach($typeLabels as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('type') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Workflow Type</label>
                            <select wire:model="workflowType" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="standard">Standard</option>
                                <option value="faw">FAW (requires customer confirmation)</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                            <input wire:model="phone" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Billing Email</label>
                            <input wire:model="billingEmail" type="email" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                            @error('billingEmail') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">VAT Number</label>
                            <input wire:model="vatNumber" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                            <input wire:model="address" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="flex items-center gap-2">
                                <input wire:model="isActive" type="checkbox" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                <span class="text-sm font-medium text-gray-700">Active</span>
                            </label>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Assigned Brands</label>
                            <p class="text-xs text-gray-500 mb-2">Select which brands this company can use when creating orders. Leave empty to allow all brands.</p>
                            <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                                @foreach($allBrands as $brand)
                                <label class="flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 cursor-pointer hover:bg-gray-50">
                                    <input type="checkbox" wire:model="selectedBrandIds" value="{{ $brand->id }}" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                    <span class="text-sm text-gray-700">{{ $brand->name }}</span>
                                </label>
                                @endforeach
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-end pt-2">
                        <button type="submit" class="rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-500 transition-colors">
                            Save Changes
                        </button>
                    </div>
                </form>
                @else
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-4">
                    <div>
                        <dt class="text-sm text-gray-500">Name</dt>
                        <dd class="mt-0.5 text-sm font-medium text-gray-900">{{ $company->name }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500">Type</dt>
                        <dd class="mt-0.5">
                            <span @class([
                                'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium',
                                'bg-purple-100 text-purple-700'   => $company->type === Company::TYPE_OEM,
                                'bg-blue-100 text-blue-700'       => $company->type === Company::TYPE_DEALER,
                                'bg-amber-100 text-amber-800'     => $company->type === Company::TYPE_BODY_BUILDER,
                                'bg-slate-100 text-slate-700'     => $company->type === Company::TYPE_INTERNAL,
                                'bg-cyan-100 text-cyan-700'       => $company->type === Company::TYPE_TRANSPORTER,
                                'bg-orange-100 text-orange-700'   => $company->type === Company::TYPE_YARD,
                                'bg-green-100 text-green-700'     => $company->type === Company::TYPE_CUSTOMER,
                                'bg-gray-100 text-gray-600'       => ! in_array($company->type, Company::TYPES, true),
                            ])>{{ $typeLabels[$company->type] ?? ucfirst($company->type ?? 'unknown') }}</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500">Workflow</dt>
                        <dd class="mt-0.5">
                            @if($company->workflow_type === 'faw')
                                <span class="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-700">FAW (confirmation required)</span>
                            @else
                                <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-600">Standard</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500">Phone</dt>
                        <dd class="mt-0.5 text-sm text-gray-900">{{ $company->phone ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500">Billing Email</dt>
                        <dd class="mt-0.5 text-sm text-gray-900">{{ $company->billing_email ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500">VAT Number</dt>
                        <dd class="mt-0.5 text-sm text-gray-900">{{ $company->vat_number ?? '—' }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-sm text-gray-500">Address</dt>
                        <dd class="mt-0.5 text-sm text-gray-900">{{ $company->address ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500">Status</dt>
                        <dd class="mt-0.5">
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $company->is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                                {{ $company->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </dd>
                    </div>
                    @if(count($companyBrands) > 0)
                    <div class="sm:col-span-2">
                        <dt class="text-sm text-gray-500">Assigned Brands</dt>
                        <dd class="mt-1 flex flex-wrap gap-1.5">
                            @foreach($allBrands->whereIn('id', $companyBrands) as $brand)
                                <span class="inline-flex items-center rounded-full bg-purple-50 px-2.5 py-0.5 text-xs font-medium text-purple-700">{{ $brand->name }}</span>
                            @endforeach
                        </dd>
                    </div>
                    @endif
                </dl>
                @endif
            </div>
        </div>

        {{-- Users --}}
        <div class="rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-200 px-6 py-4">
                <h3 class="text-lg font-semibold text-gray-900">Users ({{ $users->count() }})</h3>
            </div>
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">Name</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">Username</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">Email</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">Phone</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">Role(s)</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">Location</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white">
                    @forelse($users as $user)
                    <tr class="{{ !$user->is_active ? 'opacity-50' : '' }}">
                        <td class="whitespace-nowrap px-6 py-3 text-sm font-medium text-gray-900">{{ $user->name }}</td>
                        <td class="whitespace-nowrap px-6 py-3 text-sm text-gray-500">{{ $user->username }}</td>
                        <td class="whitespace-nowrap px-6 py-3 text-sm text-gray-500">{{ $user->email ?? '—' }}</td>
                        <td class="whitespace-nowrap px-6 py-3 text-sm text-gray-500">{{ $user->phone ?? '—' }}</td>
                        <td class="whitespace-nowrap px-6 py-3 text-sm text-gray-500">
                            @foreach($user->roles as $role)
                                <span class="inline-flex items-center rounded-full bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-700 mr-1">{{ $role->name }}</span>
                            @endforeach
                        </td>
                        <td class="whitespace-nowrap px-6 py-3 text-sm text-gray-500">
                            @php
                                $userPivot = $user->companies->first()?->pivot;
                                $userLoc = $userPivot?->location_id ? $locations->firstWhere('id', $userPivot->location_id) : null;
                            @endphp
                            {{ $userLoc?->company_name ?? '—' }}
                        </td>
                        <td class="whitespace-nowrap px-6 py-3">
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $user->is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                                {{ $user->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                        <td class="whitespace-nowrap px-6 py-3 text-sm">
                            @if(auth()->user()->isDeveloper())
                                <form method="POST" action="{{ route('admin.impersonate', $user) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="text-amber-600 hover:text-amber-800 font-medium">Impersonate</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="8" class="px-6 py-8 text-center text-sm text-gray-500">No users linked to this company yet. Use the Admin → Users page to invite team members.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Locations --}}
        @if($locations->isNotEmpty())
        <div class="rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-200 px-6 py-4">
                <h3 class="text-lg font-semibold text-gray-900">Locations ({{ $locations->count() }})</h3>
            </div>
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">Name</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">Address</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">City</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">Contact</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white">
                    @foreach($locations as $location)
                    <tr>
                        <td class="whitespace-nowrap px-6 py-3 text-sm font-medium text-gray-900">{{ $location->company_name }}</td>
                        <td class="px-6 py-3 text-sm text-gray-500">{{ $location->address }}</td>
                        <td class="whitespace-nowrap px-6 py-3 text-sm text-gray-500">{{ $location->city ?? '—' }}</td>
                        <td class="whitespace-nowrap px-6 py-3 text-sm text-gray-500">{{ $location->customer_name ?? '—' }}</td>
                        <td class="whitespace-nowrap px-6 py-3">
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $location->is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                                {{ $location->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif

    </div>
</div>
