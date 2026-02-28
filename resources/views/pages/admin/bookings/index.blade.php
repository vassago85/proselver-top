<?php

use App\Models\Job;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    #[Url]
    public string $status = '';

    #[Url]
    public string $search = '';

    public function with(): array
    {
        $query = Job::with(['company:id,name', 'pickupLocation:id,company_name', 'deliveryLocation:id,company_name'])
            ->orderByDesc('created_at');

        if ($this->status) {
            $query->where('status', $this->status);
        }

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('job_number', 'ilike', "%{$this->search}%")
                    ->orWhere('vin', 'ilike', "%{$this->search}%")
                    ->orWhere('po_number', 'ilike', "%{$this->search}%")
                    ->orWhereHas('company', fn($q) => $q->where('name', 'ilike', "%{$this->search}%"));
            });
        }

        return ['jobs' => $query->paginate(25)];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }
};

?>

<div>
    <x-slot:header>Bookings</x-slot:header>

    <div class="mb-6 flex flex-col sm:flex-row gap-4">
        <div class="flex-1">
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search by job #, VIN, PO, or company..."
                class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
        </div>
        <select wire:model.live="status" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
            <option value="">All Statuses</option>
            <option value="pending_verification">Pending Verification</option>
            <option value="verified">Verified</option>
            <option value="approved">Approved</option>
            <option value="rejected">Rejected</option>
            <option value="assigned">Assigned</option>
            <option value="in_progress">In Progress</option>
            <option value="completed">Completed</option>
            <option value="ready_for_invoicing">Ready for Invoicing</option>
            <option value="invoiced">Invoiced</option>
            <option value="cancelled">Cancelled</option>
        </select>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Job #</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Company</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Route</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">PO</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($jobs as $job)
                <tr class="hover:bg-gray-50 cursor-pointer" onclick="window.location='{{ route('admin.bookings.show', $job) }}'">
                    <td class="px-6 py-4 text-sm font-medium text-blue-600">{{ $job->job_number ?? '—' }}</td>
                    <td class="px-6 py-4 text-sm text-gray-500">{{ $job->isTransport() ? 'Transport' : 'Yard' }}</td>
                    <td class="px-6 py-4 text-sm text-gray-900">{{ $job->company?->name }}</td>
                    <td class="px-6 py-4 text-sm text-gray-500">
                        @if($job->isTransport())
                            {{ $job->pickupLocation?->company_name }} → {{ $job->deliveryLocation?->company_name }}
                        @else
                            Yard Work
                        @endif
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-500">{{ $job->po_number ?? '—' }}</td>
                    <td class="px-6 py-4"><x-status-badge :status="$job->status" /></td>
                    <td class="px-6 py-4 text-sm text-gray-500">{{ $job->scheduled_date?->format('d M Y') }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="px-6 py-12 text-center text-sm text-gray-500">No bookings found.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $jobs->links() }}
    </div>
</div>
