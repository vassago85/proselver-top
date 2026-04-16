<?php

use App\Models\Job;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;

new #[Layout('components.layouts.app')] class extends Component {
    public function with(): array
    {
        $newOrders = Job::where('status', Job::STATUS_RECEIVED)->count();
        $awaitingConfirmation = Job::whereIn('status', [Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION, Job::STATUS_CONFIRMATION_ISSUE])->count();
        $confirmationIssues = Job::where('status', Job::STATUS_CONFIRMATION_ISSUE)->count();
        $readyToPlan = Job::where('status', Job::STATUS_CONFIRMED)->count();
        $driversOut = Job::whereIn('status', [Job::STATUS_IN_TRANSIT, Job::STATUS_COLLECTED])->count();
        $deliveredToday = Job::whereIn('status', [Job::STATUS_DELIVERED, Job::STATUS_COMPLETED])
            ->whereDate('delivered_at', today())
            ->count();

        $recentOrders = Job::with(['company:id,name', 'pickupLocation:id,company_name', 'deliveryLocation:id,company_name', 'brand:id,name'])
            ->whereIn('status', Job::PHASE1_STATUSES)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return [
            'newOrders' => $newOrders,
            'awaitingConfirmation' => $awaitingConfirmation,
            'confirmationIssues' => $confirmationIssues,
            'readyToPlan' => $readyToPlan,
            'driversOut' => $driversOut,
            'deliveredToday' => $deliveredToday,
            'recentOrders' => $recentOrders,
        ];
    }
};

?>

<div>
    <x-slot:header>Admin Dashboard</x-slot:header>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5 mb-8">
        <x-stat-card label="New Orders" :value="$newOrders" color="blue" :href="route('admin.orders.index', ['status' => 'received'])" />
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <p class="text-sm text-gray-500">Awaiting Confirmation</p>
            <p class="mt-1 text-2xl font-bold text-yellow-600">{{ $awaitingConfirmation }}</p>
            @if($confirmationIssues > 0)
                <p class="mt-1 text-xs font-medium text-red-600">{{ $confirmationIssues }} with issues</p>
            @endif
        </div>
        <x-stat-card label="Ready to Plan" :value="$readyToPlan" color="green" :href="route('admin.planning')" />
        <x-stat-card label="Drivers Out" :value="$driversOut" color="blue" />
        <x-stat-card label="Delivered Today" :value="$deliveredToday" color="green" />
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-200 mb-8">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-900">Recent Orders</h2>
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
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @forelse($recentOrders as $job)
                    <tr class="hover:bg-gray-50 cursor-pointer" onclick="window.location='{{ route('admin.orders.show', $job) }}'">
                        <td class="px-6 py-4 text-sm font-medium text-blue-600">{{ $job->job_number ?? '—' }}</td>
                        <td class="px-6 py-4 text-sm text-gray-900">{{ $job->company?->name ?? '—' }}</td>
                        <td class="px-6 py-4 text-sm text-gray-900">{{ $job->brand?->name }} {{ $job->model_name }}</td>
                        <td class="px-6 py-4 text-sm font-mono text-gray-600 uppercase">{{ $job->vin ? strtoupper($job->vin) : '—' }}</td>
                        <td class="px-6 py-4 text-sm text-gray-500">{{ $job->pickupLocation?->company_name ?? '—' }}</td>
                        <td class="px-6 py-4 text-sm text-gray-500">{{ $job->deliveryLocation?->company_name ?? '—' }}</td>
                        <td class="px-6 py-4"><x-status-badge :status="$job->status" /></td>
                        <td class="px-6 py-4 text-sm text-gray-500">{{ $job->created_at->format('d M Y') }}</td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="8" class="px-6 py-12 text-center text-sm text-gray-500">No orders yet. Orders will appear here once they are received.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="flex flex-wrap gap-4">
        <a href="{{ route('admin.planning') }}" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-blue-700 transition-colors">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/></svg>
            Planning Queue
        </a>
        <a href="{{ route('admin.dispatch') }}" class="inline-flex items-center gap-2 rounded-lg bg-gray-800 px-5 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-gray-900 transition-colors">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/></svg>
            Dispatch Board
        </a>
    </div>
</div>
