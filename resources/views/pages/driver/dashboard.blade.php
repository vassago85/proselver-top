<?php
use App\Models\Job;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;

new #[Layout('components.layouts.app')] class extends Component {
    public function with(): array
    {
        $assignedJobs = Job::where('driver_user_id', auth()->id())
            ->whereIn('status', [Job::STATUS_ASSIGNED, Job::STATUS_IN_PROGRESS])
            ->with(['company:id,name', 'fromHub:id,name,address', 'toHub:id,name,address', 'yardHub:id,name'])
            ->orderBy('scheduled_date')
            ->get();

        return ['jobs' => $assignedJobs];
    }
};
?>
<div>
    <x-slot:header>My Jobs</x-slot:header>

    <div class="space-y-4">
        @forelse($jobs as $job)
        <a href="{{ route('driver.job', $job) }}" class="block bg-white rounded-xl shadow-sm border border-gray-200 p-6 hover:shadow-md transition-shadow">
            <div class="flex items-center justify-between mb-3">
                <span class="text-lg font-bold text-gray-900">{{ $job->job_number }}</span>
                <x-status-badge :status="$job->status" />
            </div>
            <p class="text-sm text-gray-600 mb-1">{{ $job->company?->name }}</p>
            @if($job->isTransport())
            <p class="text-base font-medium text-gray-900">{{ $job->fromHub?->name }} → {{ $job->toHub?->name }}</p>
            <p class="text-sm text-gray-500">{{ $job->fromHub?->address }}</p>
            @else
            <p class="text-base font-medium text-gray-900">Yard Work — {{ $job->yardHub?->name }}</p>
            @endif
            <p class="mt-2 text-sm text-gray-500">{{ $job->scheduled_date?->format('D, d M Y') }} {{ $job->scheduled_ready_time?->format('H:i') ?? '' }}</p>
        </a>
        @empty
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-12 text-center text-gray-500">
            No assigned jobs at the moment.
        </div>
        @endforelse
    </div>
</div>
