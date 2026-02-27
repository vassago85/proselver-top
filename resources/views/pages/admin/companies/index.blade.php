<?php
use App\Models\Company;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;
    public string $search = '';

    public function with(): array
    {
        $query = Company::orderBy('name');
        if ($this->search) {
            $query->where('name', 'ilike', "%{$this->search}%");
        }
        return ['companies' => $query->paginate(25)];
    }

    public function updatedSearch(): void { $this->resetPage(); }
};
?>
<div>
    <x-slot:header>Companies</x-slot:header>
    <div class="mb-6">
        <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search companies..." class="w-full max-w-md rounded-lg border border-gray-300 px-4 py-2.5 text-sm">
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">VAT</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Billing Email</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($companies as $company)
                <tr>
                    <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $company->name }}</td>
                    <td class="px-6 py-4 text-sm text-gray-500">{{ $company->vat_number ?? '—' }}</td>
                    <td class="px-6 py-4 text-sm text-gray-500">{{ $company->billing_email ?? '—' }}</td>
                    <td class="px-6 py-4"><x-badge :color="$company->is_active ? 'green' : 'red'">{{ $company->is_active ? 'Active' : 'Inactive' }}</x-badge></td>
                </tr>
                @empty
                <tr><td colspan="4" class="px-6 py-12 text-center text-sm text-gray-500">No companies found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $companies->links() }}</div>
</div>
