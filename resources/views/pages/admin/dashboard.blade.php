<?php

use App\Models\Job;
use App\Models\Company;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;

new #[Layout('components.layouts.app')] class extends Component {
    public function with(): array
    {
        $pendingVerification = Job::where('status', Job::STATUS_PENDING_VERIFICATION)->count();
        $activeJobs = Job::whereNotIn('status', [Job::STATUS_INVOICED, Job::STATUS_CANCELLED])->count();
        $readyForInvoicing = Job::where('status', Job::STATUS_READY_FOR_INVOICING)->count();
        $unassigned = Job::where('status', Job::STATUS_APPROVED)->whereNull('driver_user_id')->count();
        $inProgress = Job::where('status', Job::STATUS_IN_PROGRESS)->count();

        $recentJobs = Job::with(['company:id,name', 'driver:id,name', 'fromHub:id,name', 'toHub:id,name'])
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return [
            'pendingVerification' => $pendingVerification,
            'activeJobs' => $activeJobs,
            'readyForInvoicing' => $readyForInvoicing,
            'unassigned' => $unassigned,
            'inProgress' => $inProgress,
            'recentJobs' => $recentJobs,
        ];
    }
};

?>

<div>
    <x-slot:header>Admin Dashboard</x-slot:header>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5 mb-8">
        <x-stat-card label="Pending Verification" :value="$pendingVerification" color="yellow" :href="route('admin.bookings.index', ['status' => 'pending_verification'])" />
        <x-stat-card label="Active Jobs" :value="$activeJobs" color="blue" />
        <x-stat-card label="Unassigned" :value="$unassigned" color="red" :href="route('admin.jobs.index', ['status' => 'approved'])" />
        <x-stat-card label="In Progress" :value="$inProgress" color="orange" />
        <x-stat-card label="Ready for Invoicing" :value="$readyForInvoicing" color="green" :href="route('admin.invoices.index')" />
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-200">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-900">Recent Jobs</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Job #</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Company</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Route</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Driver</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @forelse($recentJobs as $job)
                    <tr class="hover:bg-gray-50 cursor-pointer" onclick="window.location='{{ route('admin.jobs.show', $job) }}'">
                        <td class="px-6 py-4 text-sm font-medium text-blue-600">{{ $job->job_number ?? '—' }}</td>
                        <td class="px-6 py-4 text-sm text-gray-500">{{ $job->isTransport() ? 'Transport' : 'Yard' }}</td>
                        <td class="px-6 py-4 text-sm text-gray-900">{{ $job->company?->name }}</td>
                        <td class="px-6 py-4 text-sm text-gray-500">
                            @if($job->isTransport())
                                {{ $job->fromHub?->name }} → {{ $job->toHub?->name }}
                            @else
                                {{ $job->yardHub?->name ?? 'Yard Work' }}
                            @endif
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-500">{{ $job->driver?->name ?? '—' }}</td>
                        <td class="px-6 py-4"><x-status-badge :status="$job->status" /></td>
                        <td class="px-6 py-4 text-sm text-gray-500">{{ $job->scheduled_date?->format('d M Y') }}</td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="px-6 py-12 text-center text-sm text-gray-500">No jobs yet. Bookings will appear here once dealers submit them.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
