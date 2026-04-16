<?php

use App\Models\Company;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $typeFilter = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        $query = Company::withCount('users')
            ->orderBy('name');

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('name', 'like', "%{$this->search}%")
                    ->orWhere('type', 'like', "%{$this->search}%");
            });
        }

        if ($this->typeFilter) {
            $query->where('type', $this->typeFilter);
        }

        return [
            'companies' => $query->paginate(20),
            'canManage' => auth()->user()->canManageUsers(),
        ];
    }
};

?>

<div>
    <x-slot:header>Customers</x-slot:header>

    {{-- Filters --}}
    <div class="mb-6 flex flex-col sm:flex-row gap-4 items-start sm:items-center">
        <div class="flex-1">
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search by name or type..."
                class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
        </div>
        <select wire:model.live="typeFilter" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
            <option value="">All Types</option>
            <option value="dealer">Dealer</option>
            <option value="oem">OEM</option>
            <option value="customer">Customer</option>
        </select>
        @if($canManage)
            <a href="{{ route('admin.companies.index') }}" class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-500 transition-colors">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                Add Customer
            </a>
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
                        <a href="{{ route('admin.customers.show', $company) }}" class="text-gray-900 hover:text-blue-600">{{ $company->name }}</a>
                    </td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                            {{ $company->type === 'oem' ? 'bg-purple-100 text-purple-700' : '' }}
                            {{ $company->type === 'dealer' ? 'bg-blue-100 text-blue-700' : '' }}
                            {{ $company->type === 'customer' ? 'bg-green-100 text-green-700' : '' }}
                        ">{{ ucfirst($company->type ?? 'unknown') }}</span>
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
                        <a href="{{ route('admin.customers.show', $company) }}" class="text-sm font-medium text-blue-600 hover:text-blue-800">View / Edit</a>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="px-6 py-12 text-center text-sm text-gray-500">No customers found.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $companies->links() }}
    </div>
</div>
