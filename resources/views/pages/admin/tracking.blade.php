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

    public const IN_FLIGHT_STATUSES = [
        Job::STATUS_DRIVER_ASSIGNED,
        Job::STATUS_READY_FOR_COLLECTION,
        Job::STATUS_COLLECTED,
        Job::STATUS_IN_TRANSIT,
        Job::STATUS_DELIVERED,
    ];

    public function with(): array
    {
        // driverProfile is eager-loaded so we can surface tracker_id
        // right next to the driver name on this screen. Ops use this page
        // to chase a vehicle in real time — having the tracker serial one
        // click away (instead of a round-trip into the driver record)
        // saves them opening Cartrack with the wrong id.
        $query = Job::with([
                'company:id,name',
                'pickupLocation:id,company_name,city',
                'deliveryLocation:id,company_name,city',
                'driver:id,name,phone',
                'driver.driverProfile:user_id,tracker_id',
                'brand:id,name',
            ])
            ->whereIn('status', self::IN_FLIGHT_STATUSES)
            ->orderByDesc('updated_at');

        if ($this->statusFilter && in_array($this->statusFilter, self::IN_FLIGHT_STATUSES)) {
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
                    ->orWhereHas('driver', fn($q) => $q->where('name', 'ilike', "%{$this->search}%"))
                    ->orWhereHas('driver.driverProfile', fn($q) => $q->where('tracker_id', 'ilike', "%{$this->search}%"));
            });
        }

        $counts = [];
        foreach (self::IN_FLIGHT_STATUSES as $s) {
            $counts[$s] = Job::where('status', $s)->count();
        }

        // Local tile labels. We override only the "delivered" tile so ops understand
        // it counts vehicles the driver has marked delivered but that ops has not
        // ticked "complete" on yet — once completed, they move to the Deliveries page.
        $tileLabels = array_intersect_key(Job::PHASE1_STATUS_LABELS, array_flip(self::IN_FLIGHT_STATUSES));
        $tileLabels[Job::STATUS_DELIVERED] = 'Delivered (awaiting completion)';

        return [
            'jobs' => $query->paginate(25),
            'counts' => $counts,
            'statuses' => $tileLabels,
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
    <x-slot:header>Tracking — Active Movements</x-slot:header>

    {{-- Status counts --}}
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3 mb-6">
        @foreach($statuses as $value => $label)
            <button wire:click="$set('statusFilter', '{{ $statusFilter === $value ? '' : $value }}')"
                class="text-left rounded-lg border px-4 py-3 transition-colors
                    {{ $statusFilter === $value ? 'border-blue-500 bg-blue-50 ring-2 ring-blue-100' : 'border-gray-200 bg-white hover:border-gray-300' }}">
                <p class="text-xs text-gray-500">{{ $label }}</p>
                <p class="mt-1 text-2xl font-bold text-gray-900">{{ $counts[$value] ?? 0 }}</p>
            </button>
        @endforeach
    </div>

    <div class="mb-4">
        <input wire:model.live.debounce.300ms="search" type="text"
            placeholder="Search by order #, VIN, make/model, company, route, driver or tracker ID..."
            class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Order #</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Make / Model</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">VIN</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Pickup</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Delivery</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Driver</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Updated</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @forelse($jobs as $job)
                    <tr class="hover:bg-gray-50 cursor-pointer" onclick="window.location='{{ route('admin.orders.show', $job) }}'">
                        <td class="px-4 py-3 text-sm font-medium text-blue-600">{{ $job->job_number ?? '—' }}</td>
                        <td class="px-4 py-3 text-sm text-gray-900">{{ $job->company?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-sm text-gray-900">{{ $job->brand?->name }} {{ $job->model_name }}</td>
                        <td class="px-4 py-3 text-sm font-mono text-gray-600 uppercase">{{ $job->vin ? strtoupper($job->vin) : '—' }}</td>
                        <td class="px-4 py-3 text-sm text-gray-500">{{ $job->pickupLocation?->company_name ?? '—' }}</td>
                        <td class="px-4 py-3 text-sm text-gray-500">{{ $job->deliveryLocation?->company_name ?? '—' }}</td>
                        <td class="px-4 py-3 text-sm text-gray-900">
                            <div class="flex flex-col leading-tight">
                                <span>{{ $job->driver?->name ?? '—' }}</span>
                                @if($job->driver?->driverProfile?->tracker_id)
                                    <span class="mt-0.5 inline-flex items-center gap-1 self-start rounded bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700 ring-1 ring-emerald-200"
                                          title="Tracker ID: {{ $job->driver->driverProfile->tracker_id }}">
                                        <svg viewBox="0 0 24 24" class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a8 8 0 0 1 8 8c0 4.5-6 12-8 12S4 14.5 4 10a8 8 0 0 1 8-8z"/><circle cx="12" cy="10" r="3"/></svg>
                                        <span class="font-mono tabular-nums">{{ $job->driver->driverProfile->tracker_id }}</span>
                                    </span>
                                @endif
                            </div>
                        </td>
                        <td class="px-4 py-3"><x-status-badge :status="$job->status" /></td>
                        <td class="px-4 py-3 text-sm text-gray-500">{{ $job->updated_at->diffForHumans() }}</td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="9" class="px-4 py-12 text-center text-sm text-gray-500">
                            No active movements right now. Orders appear here once a driver is assigned.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($jobs->hasPages())
        <div class="border-t border-gray-200 px-4 py-3">
            {{ $jobs->links() }}
        </div>
        @endif
    </div>
</div>
