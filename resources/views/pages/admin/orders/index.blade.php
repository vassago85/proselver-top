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
                'company:id,name',
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

        return [
            'jobs' => $query->paginate(20),
            'statuses' => Job::PHASE1_STATUS_LABELS,
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
};

?>

<div>
    <x-slot:header>Orders</x-slot:header>

    <div class="mb-6 flex flex-col sm:flex-row gap-4">
        <div class="flex-1">
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search by order #, VIN, make/model, company, route, or driver..."
                class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
        </div>
        <select wire:model.live="statusFilter" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
            <option value="">All Statuses</option>
            @foreach($statuses as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Order #</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Make / Model</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">VIN</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Pickup</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Delivery</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Driver</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($jobs as $job)
                <tr wire:click="$dispatch('navigate', { url: '{{ route('admin.orders.show', $job) }}' })"
                    class="hover:bg-gray-50 cursor-pointer"
                    onclick="window.location='{{ route('admin.orders.show', $job) }}'">
                    <td class="px-4 py-3 text-sm font-medium text-blue-600">{{ $job->job_number ?? '—' }}</td>
                    <td class="px-4 py-3 text-sm text-gray-900">{{ $job->company?->name ?? '—' }}</td>
                    <td class="px-4 py-3 text-sm text-gray-900">{{ $job->brand?->name }} {{ $job->model_name }}</td>
                    <td class="px-4 py-3 text-sm font-mono text-gray-600 uppercase">{{ $job->vin ? strtoupper($job->vin) : '—' }}</td>
                    <td class="px-4 py-3 text-sm text-gray-500">{{ $job->pickupLocation?->shortDisplay() ?? '—' }}</td>
                    <td class="px-4 py-3 text-sm text-gray-500">{{ $job->deliveryLocation?->shortDisplay() ?? '—' }}</td>
                    <td class="px-4 py-3"><x-status-badge :status="$job->status" /></td>
                    <td class="px-4 py-3 text-sm text-gray-600">{{ $job->driver?->name ?? '—' }}</td>
                    <td class="px-4 py-3 text-sm text-gray-500">{{ $job->created_at->format('d M Y') }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="9" class="px-6 py-12 text-center text-sm text-gray-500">No orders found.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $jobs->links() }}
    </div>
</div>
