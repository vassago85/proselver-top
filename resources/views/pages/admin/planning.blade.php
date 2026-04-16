<?php

use App\Models\Job;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Layout;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function planJob(int $jobId): void
    {
        $job = Job::findOrFail($jobId);

        if (!$job->canTransitionTo(Job::STATUS_PLANNED)) {
            session()->flash('error', 'This job cannot be moved to planned status.');
            return;
        }

        $job->transitionTo(Job::STATUS_PLANNED);

        session()->flash('success', "Order {$job->job_number} has been moved to planned.");
    }

    public function with(): array
    {
        $jobs = Job::with(['company:id,name', 'pickupLocation:id,company_name', 'deliveryLocation:id,company_name', 'brand:id,name'])
            ->where('status', Job::STATUS_CONFIRMED)
            ->when($this->search, function ($query) {
                $query->where(function ($q) {
                    $q->where('job_number', 'like', "%{$this->search}%")
                      ->orWhere('vin', 'like', "%{$this->search}%")
                      ->orWhere('model_name', 'like', "%{$this->search}%")
                      ->orWhereHas('brand', fn($c) => $c->where('name', 'like', "%{$this->search}%"))
                      ->orWhereHas('company', fn($c) => $c->where('name', 'like', "%{$this->search}%"))
                      ->orWhereHas('pickupLocation', fn($c) => $c->where('company_name', 'like', "%{$this->search}%"))
                      ->orWhereHas('deliveryLocation', fn($c) => $c->where('company_name', 'like', "%{$this->search}%"));
                });
            })
            ->orderBy('scheduled_date')
            ->orderBy('created_at')
            ->paginate(25);

        return [
            'jobs' => $jobs,
        ];
    }
};

?>

<div>
    <x-slot:header>Planning Queue</x-slot:header>

    @if(session('success'))
    <div class="mb-4 rounded-lg bg-green-50 border border-green-200 p-4 text-sm text-green-800">
        {{ session('success') }}
    </div>
    @endif

    @if(session('error'))
    <div class="mb-4 rounded-lg bg-red-50 border border-red-200 p-4 text-sm text-red-800">
        {{ session('error') }}
    </div>
    @endif

    <div class="mb-6">
        <div class="relative max-w-md">
            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
            </div>
            <input
                wire:model.live.debounce.300ms="search"
                type="text"
                placeholder="Search by order #, VIN, make/model, company, or route..."
                class="block w-full rounded-lg border border-gray-300 bg-white py-2.5 pl-10 pr-4 text-sm text-gray-900 placeholder-gray-400 focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
            />
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-200">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h2 class="text-lg font-semibold text-gray-900">Confirmed Orders — Ready to Plan</h2>
            <span class="text-sm text-gray-500">{{ $jobs->total() }} {{ Str::plural('order', $jobs->total()) }}</span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Order #</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Make / Model</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">VIN</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Pickup</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Delivery</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Requested Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Created</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @forelse($jobs as $job)
                    <tr class="hover:bg-gray-50" wire:key="plan-{{ $job->id }}">
                        <td class="px-6 py-4 text-sm font-medium text-blue-600">
                            <a href="{{ route('admin.orders.show', $job) }}" class="hover:underline">{{ $job->job_number ?? '—' }}</a>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-900">{{ $job->company?->name ?? '—' }}</td>
                        <td class="px-6 py-4 text-sm text-gray-900">{{ $job->brand?->name }} {{ $job->model_name }}</td>
                        <td class="px-6 py-4 text-sm font-mono text-gray-600 uppercase">{{ $job->vin ? strtoupper($job->vin) : '—' }}</td>
                        <td class="px-6 py-4 text-sm text-gray-500">{{ $job->pickupLocation?->company_name ?? '—' }}</td>
                        <td class="px-6 py-4 text-sm text-gray-500">{{ $job->deliveryLocation?->company_name ?? '—' }}</td>
                        <td class="px-6 py-4 text-sm text-gray-500">{{ $job->scheduled_date?->format('d M Y') ?? '—' }}</td>
                        <td class="px-6 py-4 text-sm text-gray-500">{{ $job->created_at->format('d M Y') }}</td>
                        <td class="px-6 py-4 text-right">
                            <button
                                wire:click="planJob({{ $job->id }})"
                                wire:confirm="Move order {{ $job->job_number }} to planned?"
                                class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3.5 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-blue-700 transition-colors"
                            >
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/></svg>
                                Plan
                            </button>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="9" class="px-6 py-12 text-center text-sm text-gray-500">No confirmed orders waiting to be planned.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($jobs->hasPages())
        <div class="px-6 py-4 border-t border-gray-200">
            {{ $jobs->links() }}
        </div>
        @endif
    </div>
</div>
