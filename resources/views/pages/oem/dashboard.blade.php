<?php

use App\Models\Job;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;

new #[Layout('components.layouts.app')] class extends Component {
    public function with(): array
    {
        $company = auth()->user()->company();
        if (!$company) {
            return ['recentJobs' => collect(), 'stats' => []];
        }

        $pendingJobs = Job::where('company_id', $company->id)->where('status', Job::STATUS_PENDING_VERIFICATION)->count();
        $activeJobs = Job::where('company_id', $company->id)->whereNotIn('status', [Job::STATUS_INVOICED, Job::STATUS_CANCELLED])->count();
        $completedJobs = Job::where('company_id', $company->id)->where('status', Job::STATUS_INVOICED)->count();

        $recentJobs = Job::where('company_id', $company->id)
            ->with(['driver:id,name', 'pickupLocation:id,company_name', 'deliveryLocation:id,company_name', 'brand:id,name'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        return [
            'stats' => [
                'pending' => $pendingJobs,
                'active' => $activeJobs,
                'completed' => $completedJobs,
            ],
            'recentJobs' => $recentJobs,
        ];
    }
};
?>

<div>
    <x-slot:header>OEM Dashboard</x-slot:header>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3 mb-8">
        <x-stat-card label="Pending Bookings" :value="$stats['pending'] ?? 0" color="yellow" />
        <x-stat-card label="Active Jobs" :value="$stats['active'] ?? 0" color="blue" />
        <x-stat-card label="Completed" :value="$stats['completed'] ?? 0" color="green" />
    </div>

    <div class="flex justify-end mb-4 gap-2 flex-wrap">
        @if(auth()->user()->hasPermission('submit_booking'))
        {{-- Primary CTA: the unified order-create flow with executor
             selector (your driver / 3rd-party / self-collect / ProSelver).
             The legacy ProSelver-only booking is kept one click away
             for OEMs who only ever raise pure-ProSelver jobs. --}}
        <a href="{{ route('customer.orders.create') }}" class="inline-flex items-center rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-500 transition-colors">
            + New Movement
        </a>
        <a href="{{ route('oem.bookings.create') }}" class="inline-flex items-center rounded-lg bg-white ring-1 ring-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50 transition-colors">
            ProSelver Booking
        </a>
        @endif
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-x-auto">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-900">Recent Jobs</h2>
        </div>
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Job #</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Make / Model</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">VIN</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Route</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Driver</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($recentJobs as $job)
                <tr class="hover:bg-gray-50 cursor-pointer" onclick="window.location='{{ route('oem.jobs.show', $job) }}'">
                    <td class="px-6 py-4 text-sm font-medium text-blue-600">{{ $job->job_number ?? '—' }}</td>
                    <td class="px-6 py-4 text-sm text-gray-900">{{ $job->brand?->name }} {{ $job->model_name }}</td>
                    <td class="px-6 py-4 text-sm font-mono text-gray-600 uppercase">{{ $job->vin ? strtoupper($job->vin) : '—' }}</td>
                    <td class="px-6 py-4 text-sm text-gray-500">
                        @if($job->isTransport()){{ $job->pickupLocation?->company_name }} &rarr; {{ $job->deliveryLocation?->company_name }}@else Yard Work @endif
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-500">{{ $job->driver?->name ?? '—' }}</td>
                    <td class="px-6 py-4"><x-status-badge :status="$job->status" /></td>
                    <td class="px-6 py-4 text-sm text-gray-500">{{ $job->scheduled_date?->format('d M Y') }}</td>
                </tr>
                @empty
                <tr><td colspan="7" class="px-6 py-12 text-center text-sm text-gray-500">No jobs yet. @if(auth()->user()->hasPermission('submit_booking'))<a href="{{ route('oem.bookings.create') }}" class="text-blue-600 hover:underline">Create your first booking</a>@endif</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
