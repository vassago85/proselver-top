<?php
use App\Models\Job;
use App\Models\JobEvent;
use App\Services\AuditService;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;

new #[Layout('components.layouts.app')] class extends Component {
    public Job $job;

    public function mount(Job $job): void
    {
        if ($job->driver_user_id !== auth()->id()) {
            abort(403);
        }
        $this->job = $job->load(['company:id,name', 'fromHub:id,name,address', 'toHub:id,name,address', 'events']);
    }

    public function logEvent(string $eventType): void
    {
        if (!in_array($eventType, JobEvent::TYPES)) return;

        JobEvent::create([
            'job_id' => $this->job->id,
            'user_id' => auth()->id(),
            'event_type' => $eventType,
            'event_at' => now(),
            'synced_at' => now(),
            'client_uuid' => \Illuminate\Support\Str::uuid(),
        ]);

        if ($eventType === JobEvent::TYPE_ARRIVED_PICKUP && $this->job->status === Job::STATUS_ASSIGNED) {
            $this->job->transitionTo(Job::STATUS_IN_PROGRESS);
        }

        if ($eventType === JobEvent::TYPE_VEHICLE_READY) {
            $this->job->actual_ready_time = now();
            $this->job->save();
        }

        if ($eventType === JobEvent::TYPE_JOB_COMPLETED) {
            $this->job->transitionTo(Job::STATUS_COMPLETED);
        }

        $this->job->refresh()->load('events');
        session()->flash('success', ucfirst(str_replace('_', ' ', $eventType)) . ' logged.');
    }

    public function with(): array
    {
        $loggedTypes = $this->job->events->pluck('event_type')->toArray();
        return ['loggedTypes' => $loggedTypes];
    }
};
?>
<div>
    <x-slot:header>Job {{ $job->job_number }}</x-slot:header>

    <div class="max-w-2xl mx-auto space-y-6">
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-xl font-bold text-gray-900">{{ $job->company?->name }}</h3>
                <x-status-badge :status="$job->status" />
            </div>
            @if($job->isTransport())
            <div class="text-center py-4">
                <p class="text-lg font-semibold text-gray-900">{{ $job->fromHub?->name }}</p>
                <p class="text-sm text-gray-500">{{ $job->fromHub?->address }}</p>
                <div class="my-3 text-2xl text-gray-400">↓</div>
                <p class="text-lg font-semibold text-gray-900">{{ $job->toHub?->name }}</p>
                <p class="text-sm text-gray-500">{{ $job->toHub?->address }}</p>
            </div>
            @endif
            <p class="text-sm text-gray-500 text-center">{{ $job->scheduled_date?->format('D, d M Y') }}</p>
        </div>

        {{-- Action Buttons --}}
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 space-y-3">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Actions</h3>

            @php $steps = [
                ['type' => 'arrived_pickup', 'label' => 'Arrived at Pickup', 'color' => 'blue'],
                ['type' => 'vehicle_ready_confirmed', 'label' => 'Vehicle Ready', 'color' => 'blue'],
                ['type' => 'departed_pickup', 'label' => 'Departed Pickup', 'color' => 'blue'],
                ['type' => 'arrived_delivery', 'label' => 'Arrived at Delivery', 'color' => 'blue'],
                ['type' => 'pod_scanned', 'label' => 'POD Scanned', 'color' => 'green'],
                ['type' => 'job_completed', 'label' => 'Complete Job', 'color' => 'green'],
            ]; @endphp

            @foreach($steps as $step)
                @if(in_array($step['type'], $loggedTypes))
                    <button disabled class="w-full rounded-lg bg-gray-100 px-4 py-4 text-sm font-semibold text-gray-400 flex items-center justify-between">
                        {{ $step['label'] }}
                        <svg class="h-5 w-5 text-green-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
                    </button>
                @else
                    <button wire:click="logEvent('{{ $step['type'] }}')" wire:confirm="Log '{{ $step['label'] }}'?"
                        class="w-full rounded-lg bg-{{ $step['color'] }}-600 px-4 py-4 text-sm font-semibold text-white hover:bg-{{ $step['color'] }}-500 transition-colors text-left">
                        {{ $step['label'] }}
                    </button>
                @endif
            @endforeach
        </div>

        {{-- Event Timeline --}}
        @if($job->events->isNotEmpty())
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Timeline</h3>
            <ol class="space-y-3">
                @foreach($job->events->sortByDesc('event_at') as $event)
                <li class="flex justify-between items-center py-2 border-b border-gray-100 last:border-0">
                    <span class="text-sm font-medium text-gray-900">{{ ucfirst(str_replace('_', ' ', $event->event_type)) }}</span>
                    <time class="text-xs text-gray-500">{{ $event->event_at->format('H:i') }}</time>
                </li>
                @endforeach
            </ol>
        </div>
        @endif
    </div>
</div>
