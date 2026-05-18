<?php

use App\Models\Company;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Gate;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $typeFilter = '';

    // Create-form state. Kept on the component (rather than a child
    // Volt page) so the modal can read the same $typeFilter etc. and
    // we don't have to re-resolve the user permission twice.
    public bool $showCreate = false;
    public string $newName = '';
    public string $newType = Company::TYPE_DEALER;
    public string $newWorkflowType = 'standard';
    public string $newPhone = '';
    public string $newBillingEmail = '';
    public string $newVatNumber = '';
    public string $newAddress = '';
    public bool $newIsActive = true;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function openCreate(): void
    {
        // Server-side gate so a tampered Livewire payload can't open
        // the form for a viewer-only user.  The template hides the
        // button on the same condition.
        abort_unless(Gate::allows('create', Company::class), 403);
        $this->resetCreateForm();
        $this->showCreate = true;
    }

    public function closeCreate(): void
    {
        $this->showCreate = false;
        $this->resetCreateForm();
    }

    protected function resetCreateForm(): void
    {
        $this->newName = '';
        $this->newType = Company::TYPE_DEALER;
        $this->newWorkflowType = 'standard';
        $this->newPhone = '';
        $this->newBillingEmail = '';
        $this->newVatNumber = '';
        $this->newAddress = '';
        $this->newIsActive = true;
        $this->resetErrorBag();
    }

    public function createCompany()
    {
        abort_unless(Gate::allows('create', Company::class), 403);

        $data = $this->validate([
            'newName'         => 'required|string|max:255|unique:companies,name',
            'newType'         => 'required|in:' . implode(',', Company::TYPES),
            'newWorkflowType' => 'required|in:standard,faw',
            'newPhone'        => 'nullable|string|max:30',
            'newBillingEmail' => 'nullable|email|max:255',
            'newVatNumber'    => 'nullable|string|max:30',
            'newAddress'      => 'nullable|string|max:500',
            'newIsActive'     => 'boolean',
        ]);

        $company = Company::create([
            'name'          => $data['newName'],
            'type'          => $data['newType'],
            'workflow_type' => $data['newWorkflowType'],
            'phone'         => $data['newPhone'] ?: null,
            'billing_email' => $data['newBillingEmail'] ?: null,
            'vat_number'    => $data['newVatNumber'] ?: null,
            'address'       => $data['newAddress'] ?: null,
            'is_active'     => (bool) $data['newIsActive'],
        ]);

        session()->flash('success', 'Created ' . $company->name . '. Add users, locations and brand assignments next.');

        // Land the operator straight on the new company's detail page
        // so the next steps (users / locations / brands) are one click
        // away — closes the loop on "I added a company, now what?".
        return $this->redirectRoute('admin.companies.show', $company, navigate: true);
    }

    public function with(): array
    {
        $query = Company::withCount('users')->orderBy('name');

        if ($this->search) {
            $needle = '%' . $this->search . '%';
            $query->where(function ($q) use ($needle) {
                $q->where('name', 'like', $needle)
                    ->orWhere('billing_email', 'like', $needle)
                    ->orWhere('vat_number', 'like', $needle)
                    ->orWhere('phone', 'like', $needle);
            });
        }

        if ($this->typeFilter) {
            $query->where('type', $this->typeFilter);
        }

        // Pretty labels for each Company::TYPE_* constant.  Kept here
        // (not on the model) because they're presentation strings —
        // the model carries the slugs as the canonical source.
        $typeLabels = [
            Company::TYPE_DEALER       => 'Dealer',
            Company::TYPE_OEM          => 'OEM',
            Company::TYPE_BODY_BUILDER => 'Body Builder',
            Company::TYPE_TRANSPORTER  => 'Transporter',
            Company::TYPE_YARD         => 'Yard / Storage',
            Company::TYPE_INTERNAL     => 'Internal (ProSelver)',
            Company::TYPE_CUSTOMER     => 'Customer',
        ];

        return [
            'companies'  => $query->paginate(20),
            'canManage'  => Gate::allows('create', Company::class),
            'typeLabels' => $typeLabels,
        ];
    }
};

?>

<div>
    <x-slot:header>Companies</x-slot:header>

    @if(session('success'))
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('success') }}</div>
    @endif

    {{-- Filters --}}
    <div class="mb-6 flex flex-col sm:flex-row gap-4 items-start sm:items-center">
        <div class="flex-1">
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search by name, email, VAT or phone…"
                class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
        </div>
        <select wire:model.live="typeFilter" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
            <option value="">All Types</option>
            @foreach($typeLabels as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
        @if($canManage)
            <button type="button" wire:click="openCreate" class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-500 transition-colors">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                Add Company
            </button>
        @endif
    </div>

    {{-- Table --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Workflow</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Phone</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Active</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Users</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($companies as $company)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-sm font-medium">
                        <a href="{{ route('admin.companies.show', $company) }}" class="text-gray-900 hover:text-blue-600">{{ $company->name }}</a>
                    </td>
                    <td class="px-4 py-3">
                        <span @class([
                            'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                            'bg-purple-100 text-purple-700'   => $company->type === \App\Models\Company::TYPE_OEM,
                            'bg-blue-100 text-blue-700'       => $company->type === \App\Models\Company::TYPE_DEALER,
                            'bg-amber-100 text-amber-800'     => $company->type === \App\Models\Company::TYPE_BODY_BUILDER,
                            'bg-slate-100 text-slate-700'     => $company->type === \App\Models\Company::TYPE_INTERNAL,
                            'bg-cyan-100 text-cyan-700'       => $company->type === \App\Models\Company::TYPE_TRANSPORTER,
                            'bg-orange-100 text-orange-700'   => $company->type === \App\Models\Company::TYPE_YARD,
                            'bg-green-100 text-green-700'     => $company->type === \App\Models\Company::TYPE_CUSTOMER,
                            'bg-gray-100 text-gray-600'       => ! in_array($company->type, \App\Models\Company::TYPES, true),
                        ])>{{ $typeLabels[$company->type] ?? ucfirst($company->type ?? 'unknown') }}</span>
                    </td>
                    <td class="px-4 py-3">
                        @if($company->workflow_type === 'faw')
                            <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700">FAW</span>
                        @else
                            <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">Standard</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-600">{{ $company->phone ?? '—' }}</td>
                    <td class="px-4 py-3">
                        @if($company->is_active)
                            <span class="inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700">Yes</span>
                        @else
                            <span class="inline-flex items-center rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700">No</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-600">{{ $company->users_count }}</td>
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.companies.show', $company) }}" class="text-sm font-medium text-blue-600 hover:text-blue-800">View / Edit</a>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="px-6 py-12 text-center text-sm text-gray-500">No companies found.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $companies->links() }}
    </div>

    {{-- Create modal --}}
    @if($showCreate)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 px-4" wire:click.self="closeCreate">
            <form wire:submit.prevent="createCompany" class="w-full max-w-2xl rounded-xl bg-white shadow-xl">
                <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3">
                    <h3 class="text-base font-semibold text-slate-900">Add Company</h3>
                    <button type="button" wire:click="closeCreate" class="text-slate-400 hover:text-slate-600">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 px-5 py-4">
                    <div class="sm:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Company name *</label>
                        <input wire:model="newName" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500" autofocus>
                        @error('newName') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Type *</label>
                        <select wire:model="newType" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                            @foreach($typeLabels as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('newType') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                        <p class="mt-1 text-xs text-gray-500">
                            Picks the portal the company's users land on (dealer, OEM, body-builder, etc.) and what they can do.
                        </p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Workflow *</label>
                        <select wire:model="newWorkflowType" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="standard">Standard (auto-confirm)</option>
                            <option value="faw">FAW (requires customer confirmation)</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                        <input wire:model="newPhone" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Billing email</label>
                        <input wire:model="newBillingEmail" type="email" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                        @error('newBillingEmail') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">VAT number</label>
                        <input wire:model="newVatNumber" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>

                    <div class="sm:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                        <input wire:model="newAddress" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>

                    <div>
                        <label class="flex items-center gap-2">
                            <input wire:model="newIsActive" type="checkbox" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                            <span class="text-sm font-medium text-gray-700">Active</span>
                        </label>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 border-t border-slate-200 px-5 py-3">
                    <button type="button" wire:click="closeCreate" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Cancel</button>
                    <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-500">Create company</button>
                </div>
            </form>
        </div>
    @endif
</div>
