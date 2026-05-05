<?php

use App\Models\Job;
use App\Models\User;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Layout;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $statusFilter = '';
    public array $driverSelections = [];

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function setStatusFilter(string $filter): void
    {
        $this->statusFilter = $filter;
        $this->resetPage();
    }

    public function assignDriver(int $jobId): void
    {
        $driverId = $this->driverSelections[$jobId] ?? null;

        if (!$driverId) {
            session()->flash('error', 'Please select a driver.');
            return;
        }

        $job = Job::findOrFail($jobId);

        if (!$job->canTransitionTo(Job::STATUS_DRIVER_ASSIGNED)) {
            session()->flash('error', 'This job cannot be assigned a driver in its current status.');
            return;
        }

        $driver = User::findOrFail($driverId);
        $job->driver_user_id = $driver->id;
        $job->transitionTo(Job::STATUS_DRIVER_ASSIGNED);

        unset($this->driverSelections[$jobId]);

        session()->flash('success', "Driver {$driver->name} assigned to order {$job->job_number}.");
    }

    public function with(): array
    {
        $statuses = match ($this->statusFilter) {
            'planned' => [Job::STATUS_PLANNED],
            'driver_assigned' => [Job::STATUS_DRIVER_ASSIGNED],
            default => [Job::STATUS_PLANNED, Job::STATUS_DRIVER_ASSIGNED],
        };

        $jobs = Job::with([
                'company:id,name',
                'driver:id,name',
                'pickupLocation:id,company_name',
                'deliveryLocation:id,company_name',
                'brand:id,name',
            ])
            ->whereIn('status', $statuses)
            ->when($this->search, function ($query) {
                $query->where(function ($q) {
                    $q->where('job_number', 'like', "%{$this->search}%")
                      ->orWhere('vin', 'like', "%{$this->search}%")
                      ->orWhere('model_name', 'like', "%{$this->search}%")
                      ->orWhereHas('brand', fn($c) => $c->where('name', 'like', "%{$this->search}%"))
                      ->orWhereHas('company', fn($c) => $c->where('name', 'like', "%{$this->search}%"))
                      ->orWhereHas('pickupLocation', fn($c) => $c->where('company_name', 'like', "%{$this->search}%"))
                      ->orWhereHas('deliveryLocation', fn($c) => $c->where('company_name', 'like', "%{$this->search}%"))
                      ->orWhereHas('driver', fn($c) => $c->where('name', 'like', "%{$this->search}%"));
                });
            })
            // Portable status ordering (MySQL's FIELD() doesn't exist on Postgres).
            ->orderByRaw("CASE status WHEN ? THEN 1 WHEN ? THEN 2 ELSE 3 END ASC", [Job::STATUS_PLANNED, Job::STATUS_DRIVER_ASSIGNED])
            ->orderBy('scheduled_date')
            ->paginate(25);

        $drivers = User::whereHas('roles', fn($q) => $q->where('slug', 'driver'))
            ->orderBy('name')
            ->get(['id', 'name']);

        $plannedCount = Job::where('status', Job::STATUS_PLANNED)->count();
        $assignedCount = Job::where('status', Job::STATUS_DRIVER_ASSIGNED)->count();

        $driverOptions = $drivers->map(fn ($d) => [
            'value' => (string) $d->id,
            'label' => $d->name,
        ])->values()->all();

        return [
            'jobs' => $jobs,
            'drivers' => $drivers,
            'driverOptions' => $driverOptions,
            'plannedCount' => $plannedCount,
            'assignedCount' => $assignedCount,
        ];
    }
};

?>

<div>
    <x-slot:header>Dispatch Board</x-slot:header>

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

    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="inline-flex rounded-lg border border-gray-200 bg-white p-1 shadow-sm">
            <button
                wire:click="setStatusFilter('')"
                class="rounded-md px-4 py-2 text-sm font-medium transition-colors {{ $statusFilter === '' ? 'bg-gray-900 text-white' : 'text-gray-600 hover:text-gray-900' }}"
            >
                All
                <span class="ml-1.5 inline-flex items-center justify-center rounded-full {{ $statusFilter === '' ? 'bg-gray-700' : 'bg-gray-100' }} px-2 py-0.5 text-xs">{{ $plannedCount + $assignedCount }}</span>
            </button>
            <button
                wire:click="setStatusFilter('planned')"
                class="rounded-md px-4 py-2 text-sm font-medium transition-colors {{ $statusFilter === 'planned' ? 'bg-blue-600 text-white' : 'text-gray-600 hover:text-gray-900' }}"
            >
                Planned
                <span class="ml-1.5 inline-flex items-center justify-center rounded-full {{ $statusFilter === 'planned' ? 'bg-blue-500' : 'bg-gray-100' }} px-2 py-0.5 text-xs">{{ $plannedCount }}</span>
            </button>
            <button
                wire:click="setStatusFilter('driver_assigned')"
                class="rounded-md px-4 py-2 text-sm font-medium transition-colors {{ $statusFilter === 'driver_assigned' ? 'bg-purple-600 text-white' : 'text-gray-600 hover:text-gray-900' }}"
            >
                Driver Assigned
                <span class="ml-1.5 inline-flex items-center justify-center rounded-full {{ $statusFilter === 'driver_assigned' ? 'bg-purple-500' : 'bg-gray-100' }} px-2 py-0.5 text-xs">{{ $assignedCount }}</span>
            </button>
        </div>

        <div class="relative max-w-md w-full sm:w-auto">
            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
            </div>
            <input
                wire:model.live.debounce.300ms="search"
                type="text"
                placeholder="Search by order #, VIN, make/model, company, route, or driver..."
                class="block w-full rounded-lg border border-gray-300 bg-white py-2.5 pl-10 pr-4 text-sm text-gray-900 placeholder-gray-400 focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
            />
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-200">
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
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Driver</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @forelse($jobs as $job)
                    <tr class="hover:bg-gray-50" wire:key="dispatch-{{ $job->id }}">
                        <td class="px-6 py-4 text-sm font-medium text-blue-600">
                            <a href="{{ route('admin.orders.show', $job) }}" class="hover:underline">{{ $job->job_number ?? '—' }}</a>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-900">{{ $job->company?->name ?? '—' }}</td>
                        <td class="px-6 py-4 text-sm text-gray-900">{{ $job->brand?->name }} {{ $job->model_name }}</td>
                        <td class="px-6 py-4 text-sm font-mono text-gray-600 uppercase">{{ $job->vin ? strtoupper($job->vin) : '—' }}</td>
                        <td class="px-6 py-4 text-sm text-gray-500">{{ $job->pickupLocation?->company_name ?? '—' }}</td>
                        <td class="px-6 py-4 text-sm text-gray-500">{{ $job->deliveryLocation?->company_name ?? '—' }}</td>
                        <td class="px-6 py-4"><x-status-badge :status="$job->status" /></td>
                        <td class="px-6 py-4 text-sm text-gray-900">{{ $job->driver?->name ?? '—' }}</td>
                        <td class="px-6 py-4">
                            @if($job->status === 'planned')
                            <div class="flex items-center justify-end gap-2">
                                <div class="w-52">
                                    <x-searchable-select
                                        wire:model="driverSelections.{{ $job->id }}"
                                        :options="$driverOptions"
                                        placeholder="Select driver..."
                                        search-placeholder="Search drivers…"
                                    />
                                </div>
                                <button
                                    wire:click="assignDriver({{ $job->id }})"
                                    class="inline-flex items-center gap-1 rounded-lg bg-purple-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-purple-700 transition-colors"
                                >
                                    Assign
                                </button>
                            </div>
                            @elseif($job->status === 'driver_assigned')
                            <div class="flex justify-end">
                                <span class="inline-flex items-center gap-1.5 rounded-lg bg-slate-100 px-3 py-1.5 text-xs font-medium text-slate-600">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                                    Awaiting driver pickup
                                </span>
                            </div>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="9" class="px-6 py-12 text-center text-sm text-gray-500">No jobs in the dispatch queue.</td>
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
