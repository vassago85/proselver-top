<?php

use App\Models\Job;
use App\Models\Company;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;

new #[Layout('components.layouts.app')] class extends Component {
    public ?Company $company = null;
    public bool $requiresConfirmation = false;

    public function mount(): void
    {
        $this->company = auth()->user()->company();
        abort_unless($this->company, 403, 'No company associated with your account.');
        $this->requiresConfirmation = $this->company->requiresExternalConfirmation();
    }

    public function with(): array
    {
        $baseQuery = Job::where('company_id', $this->company->id);

        $activeStatuses = [
            Job::STATUS_RECEIVED,
            Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION,
            Job::STATUS_CONFIRMED,
            Job::STATUS_PLANNED,
            Job::STATUS_DRIVER_ASSIGNED,
            Job::STATUS_READY_FOR_COLLECTION,
            Job::STATUS_COLLECTED,
            Job::STATUS_IN_TRANSIT,
        ];

        $activeCount = (clone $baseQuery)->whereIn('status', $activeStatuses)->count();

        $awaitingConfirmationCount = $this->requiresConfirmation
            ? (clone $baseQuery)->where('status', Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION)->count()
            : 0;

        $inTransitCount = (clone $baseQuery)->whereIn('status', [
            Job::STATUS_COLLECTED,
            Job::STATUS_IN_TRANSIT,
        ])->count();

        $deliveredThisMonth = (clone $baseQuery)
            ->whereIn('status', [Job::STATUS_DELIVERED, Job::STATUS_COMPLETED])
            ->where('delivered_at', '>=', now()->startOfMonth())
            ->count();

        $recentOrders = Job::where('company_id', $this->company->id)
            ->with([
                'pickupLocation:id,company_name,city',
                'deliveryLocation:id,company_name,city',
                'brand:id,name',
                'driver:id,name',
            ])
            ->latest('created_at')
            ->limit(15)
            ->get();

        $completedDeliveries = Job::where('company_id', $this->company->id)
            ->where('status', Job::STATUS_COMPLETED)
            ->with([
                'deliveryLocation:id,company_name,city',
                'brand:id,name',
                'documents',
            ])
            ->latest('completed_at')
            ->limit(10)
            ->get();

        return [
            'activeCount' => $activeCount,
            'awaitingConfirmationCount' => $awaitingConfirmationCount,
            'inTransitCount' => $inTransitCount,
            'deliveredThisMonth' => $deliveredThisMonth,
            'recentOrders' => $recentOrders,
            'completedDeliveries' => $completedDeliveries,
            'canCreateOrder' => auth()->user()->hasPermission('submit_booking'),
        ];
    }
};

?>

<div>
    <x-slot:header>Dashboard</x-slot:header>

    @if(session('success'))
        <div class="mb-4 rounded-lg bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800">{{ session('success') }}</div>
    @endif

    {{-- Stat Cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <x-stat-card label="Active Orders" :value="$activeCount" color="blue" :href="route('customer.orders.index')" />

        @if($requiresConfirmation)
            <x-stat-card label="Awaiting My Confirmation" :value="$awaitingConfirmationCount" color="yellow" :href="route('customer.orders.index', ['statusFilter' => 'awaiting_customer_confirmation'])" />
        @endif

        <x-stat-card label="In Transit" :value="$inTransitCount" color="green" />
        <x-stat-card label="Delivered This Month" :value="$deliveredThisMonth" color="green" />
    </div>

    {{-- Header with New Order button --}}
    <div class="flex items-center justify-between mb-4">
        <h2 class="text-lg font-semibold text-gray-900">Recent Orders</h2>
        @if($canCreateOrder)
            <a href="{{ route('customer.orders.create') }}" class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-500 transition-colors">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                New Order
            </a>
        @endif
    </div>

    {{-- Recent Orders Table --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-x-auto mb-8">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Order #</th>
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
                @forelse($recentOrders as $order)
                <tr class="hover:bg-gray-50 cursor-pointer" onclick="window.location='{{ route('customer.orders.show', $order) }}'">
                    <td class="px-4 py-3 text-sm font-medium text-blue-600">{{ $order->job_number ?? '—' }}</td>
                    <td class="px-4 py-3 text-sm text-gray-900">{{ $order->brand?->name }} {{ $order->model_name }}</td>
                    <td class="px-4 py-3 text-sm font-mono text-gray-600 uppercase">{{ $order->vin ? strtoupper($order->vin) : '—' }}</td>
                    <td class="px-4 py-3 text-sm text-gray-600">{{ $order->pickupLocation?->shortDisplay() ?? '—' }}</td>
                    <td class="px-4 py-3 text-sm text-gray-600">{{ $order->deliveryLocation?->shortDisplay() ?? '—' }}</td>
                    <td class="px-4 py-3"><x-status-badge :status="$order->status" /></td>
                    <td class="px-4 py-3 text-sm text-gray-500">{{ $order->scheduled_date?->format('d M Y') ?? $order->created_at->format('d M Y') }}</td>
                    <td class="px-4 py-3 text-sm text-gray-600">{{ $order->driver?->name ?? '—' }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" class="px-6 py-12 text-center text-sm text-gray-500">No orders yet.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Completed Deliveries --}}
    <h2 class="text-lg font-semibold text-gray-900 mb-4">Completed Deliveries</h2>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Order #</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Make / Model</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">VIN</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Delivery Location</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Completed</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">POD</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($completedDeliveries as $delivery)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3">
                        <a href="{{ route('customer.orders.show', $delivery) }}" class="text-sm font-medium text-blue-600 hover:text-blue-800">{{ $delivery->job_number ?? '—' }}</a>
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-900">{{ $delivery->brand?->name }} {{ $delivery->model_name }}</td>
                    <td class="px-4 py-3 text-sm font-mono text-gray-600 uppercase">{{ $delivery->vin ? strtoupper($delivery->vin) : '—' }}</td>
                    <td class="px-4 py-3 text-sm text-gray-600">{{ $delivery->deliveryLocation?->shortDisplay() ?? '—' }}</td>
                    <td class="px-4 py-3 text-sm text-gray-500">{{ $delivery->completed_at?->format('d M Y') ?? '—' }}</td>
                    <td class="px-4 py-3">
                        @if($delivery->documents->where('category', 'proof_of_delivery')->isNotEmpty())
                            <span class="inline-flex items-center gap-1 rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700">
                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/></svg>
                                Received
                            </span>
                        @else
                            <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-500">Pending</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="px-6 py-12 text-center text-sm text-gray-500">No completed deliveries yet.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
