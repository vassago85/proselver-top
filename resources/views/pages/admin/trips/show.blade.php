<?php

use App\Models\Job;
use App\Models\Location;
use App\Models\Trip;
use App\Models\TripStop;
use App\Services\TripPlanner;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/*
 * Ops-side trip planner. Same affordances as the dealer planner but
 * unscoped — ops can edit trips for any company. Used when a dealer
 * rings up and needs a quick change, or when planning ProSelver's own
 * platform-driver trips.
 */
new #[Layout('components.layouts.app')]
class extends Component
{
    public Trip $trip;
    public bool $canEdit = true;
    public ?string $errorMessage = null;

    public ?string $waypointType = null;
    public ?int $waypointLocationId = null;
    public string $waypointNotes = '';
    public ?int $insertAfterSequence = null;

    public function mount(Trip $trip): void
    {
        $user = auth()->user();
        abort_unless($user && $user->belongsToPlatformOwner(), 403);

        $this->trip = $trip->load([
            'driver', 'company',
            'stops.job.brand', 'stops.job.pickupLocation', 'stops.job.deliveryLocation',
            'stops.location', 'startLocation', 'endLocation',
        ]);

        $this->canEdit = !in_array($trip->status, [Trip::STATUS_COMPLETED, Trip::STATUS_CANCELLED], true);
    }

    public function refreshTrip(): void
    {
        $this->trip->refresh()->load([
            'driver', 'company',
            'stops.job.brand', 'stops.job.pickupLocation', 'stops.job.deliveryLocation',
            'stops.location', 'startLocation', 'endLocation',
        ]);
    }

    public function attachJob(int $jobId, TripPlanner $planner): void
    {
        $this->errorMessage = null;
        if (!$this->canEdit) { return; }
        $job = Job::find($jobId);
        if (!$job) { return; }
        try { $planner->attachJob($this->trip, $job); }
        catch (\Throwable $e) { $this->errorMessage = $e->getMessage(); return; }
        $this->refreshTrip();
    }

    public function detachJob(int $jobId, TripPlanner $planner): void
    {
        $this->errorMessage = null;
        if (!$this->canEdit) { return; }
        $job = Job::find($jobId);
        if (!$job) { return; }
        try { $planner->detachJob($this->trip, $job); }
        catch (\Throwable $e) { $this->errorMessage = $e->getMessage(); return; }
        $this->refreshTrip();
    }

    public function openWaypoint(?int $afterSequence, string $type): void
    {
        $this->errorMessage = null;
        $this->insertAfterSequence = $afterSequence;
        $this->waypointType = $type;
        $this->waypointLocationId = null;
        $this->waypointNotes = '';
    }

    public function cancelWaypoint(): void
    {
        $this->waypointType = null;
        $this->insertAfterSequence = null;
        $this->waypointLocationId = null;
        $this->waypointNotes = '';
    }

    public function saveWaypoint(TripPlanner $planner): void
    {
        $this->errorMessage = null;
        if (!$this->canEdit || !$this->waypointType) { return; }

        $this->validate([
            'waypointType'       => 'required|string',
            'waypointLocationId' => 'nullable|integer|exists:locations,id',
            'waypointNotes'      => 'nullable|string|max:500',
        ]);

        try {
            $planner->insertWaypoint(
                $this->trip,
                $this->waypointType,
                $this->waypointLocationId,
                $this->insertAfterSequence,
                $this->waypointNotes ?: null,
            );
        } catch (\Throwable $e) { $this->errorMessage = $e->getMessage(); return; }

        $this->cancelWaypoint();
        $this->refreshTrip();
    }

    public function moveStop(int $stopId, string $direction, TripPlanner $planner): void
    {
        $this->errorMessage = null;
        if (!$this->canEdit) { return; }

        $stops = $this->trip->stops()->orderBy('sequence')->orderBy('id')->get()->values();
        $idx = $stops->search(fn ($s) => (int) $s->id === $stopId);
        if ($idx === false) { return; }
        $swap = $direction === 'up' ? $idx - 1 : $idx + 1;
        if ($swap < 0 || $swap >= $stops->count()) { return; }

        $map = [];
        $tmp = $stops[$idx]->sequence;
        $map[$stops[$idx]->id] = $stops[$swap]->sequence;
        $map[$stops[$swap]->id] = $tmp;

        try { $planner->reorderStops($this->trip, $map); }
        catch (\Throwable $e) { $this->errorMessage = $e->getMessage(); return; }
        $this->refreshTrip();
    }

    public function removeStop(int $stopId, TripPlanner $planner): void
    {
        $this->errorMessage = null;
        if (!$this->canEdit) { return; }
        $stop = TripStop::find($stopId);
        if (!$stop || $stop->trip_id !== $this->trip->id) { return; }
        try { $planner->removeStop($stop); }
        catch (\Throwable $e) { $this->errorMessage = $e->getMessage(); return; }
        $this->refreshTrip();
    }

    public function markArrived(int $stopId): void
    {
        $stop = TripStop::find($stopId);
        if (!$stop || $stop->trip_id !== $this->trip->id) { return; }
        $stop->markArrived();
        $this->refreshTrip();
    }

    public function markDeparted(int $stopId): void
    {
        $stop = TripStop::find($stopId);
        if (!$stop || $stop->trip_id !== $this->trip->id) { return; }
        $stop->markDeparted();
        $this->refreshTrip();
    }

    public function startTrip(): void
    {
        if (!$this->canEdit) { return; }
        $this->trip->start();
        $this->refreshTrip();
    }

    public function completeTrip(): void
    {
        $this->trip->complete();
        $this->refreshTrip();
    }

    public function cancelTrip(): void
    {
        if (!$this->canEdit) { return; }
        $this->trip->cancel();
        $this->refreshTrip();
    }

    public function with(): array
    {
        $tripDate = $this->trip->trip_date->copy();

        $attachable = Job::query()
            ->where('company_id', $this->trip->company_id)
            ->whereNull('trip_id')
            ->whereNotIn('status', [Job::STATUS_DELIVERED, Job::STATUS_COMPLETED, Job::STATUS_CANCELLED])
            ->whereDate('scheduled_date', '>=', $tripDate->copy()->subDays(3)->toDateString())
            ->whereDate('scheduled_date', '<=', $tripDate->copy()->addDays(3)->toDateString())
            ->with(['brand:id,name', 'pickupLocation:id,company_name,city', 'deliveryLocation:id,company_name,city', 'vehicleClass:id,name'])
            ->orderBy('scheduled_date')
            ->limit(50)
            ->get();

        $locationOptions = Location::query()
            ->where(function ($q) {
                $q->where('company_id', $this->trip->company_id)
                  ->orWhereNull('company_id');
            })
            ->where('is_active', true)
            ->orderBy('company_name')
            ->limit(500)
            ->get(['id', 'company_name', 'city'])
            ->map(fn ($l) => [
                'value' => (string) $l->id,
                'label' => $l->company_name . ($l->city ? ' — ' . $l->city : ''),
            ])
            ->all();

        return [
            'attachableJobs'  => $attachable,
            'locationOptions' => $locationOptions,
            'waypointTypes'   => [
                TripStop::TYPE_POSITIONING          => ['label' => 'Positioning leg'],
                TripStop::TYPE_WAYPOINT_COF         => ['label' => 'COF check'],
                TripStop::TYPE_WAYPOINT_WEIGHBRIDGE => ['label' => 'Weighbridge'],
                TripStop::TYPE_WAYPOINT_FUEL        => ['label' => 'Fuel stop'],
                TripStop::TYPE_WAYPOINT_OTHER       => ['label' => 'Waypoint'],
            ],
        ];
    }
};

?>
<div class="space-y-6">
    <x-slot:header>Trip planner — ops view</x-slot:header>

    @if($errorMessage)
        <div class="rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
            {{ $errorMessage }}
        </div>
    @endif

    @php
        $statusPill = match ($trip->status) {
            Trip::STATUS_PLANNED     => 'bg-blue-100 text-blue-800',
            Trip::STATUS_IN_PROGRESS => 'bg-emerald-100 text-emerald-800',
            Trip::STATUS_COMPLETED   => 'bg-slate-100 text-slate-700',
            Trip::STATUS_CANCELLED   => 'bg-rose-100 text-rose-700',
            default                  => 'bg-slate-100 text-slate-700',
        };
    @endphp

    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="text-lg font-semibold text-slate-900">{{ $trip->driver?->name ?? 'Unassigned driver' }}</h1>
                    <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wider {{ $statusPill }}">
                        {{ $trip->statusLabel() }}
                    </span>
                    <span class="rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-700">{{ $trip->company?->name }}</span>
                </div>
                <p class="mt-1 text-sm text-slate-500">
                    {{ $trip->trip_date->format('l, j M Y') }}
                    · {{ $trip->startLocation?->company_name ?? 'No start' }}
                    →
                    {{ $trip->endLocation?->company_name ?? 'No end' }}
                </p>
                @if($trip->notes)
                    <p class="mt-2 text-xs italic text-slate-500">"{{ $trip->notes }}"</p>
                @endif
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('admin.trips.index') }}" class="text-sm font-medium text-slate-500 hover:text-slate-800">← All trips</a>
                <a href="#" onclick="window.print(); return false;"
                   class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                    Print sheet
                </a>
                @if($canEdit && $trip->status === Trip::STATUS_PLANNED)
                    <button wire:click="startTrip" class="rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-500">
                        Start trip
                    </button>
                    <button wire:click="cancelTrip" wire:confirm="Cancel this trip?" class="rounded-md border border-rose-300 bg-white px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-50">
                        Cancel
                    </button>
                @endif
                @if($trip->status === Trip::STATUS_IN_PROGRESS)
                    <button wire:click="completeTrip" wire:confirm="Mark this trip as completed?" class="rounded-md bg-slate-800 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-700">
                        Complete trip
                    </button>
                @endif
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <div class="rounded-xl border border-slate-200 bg-white shadow-sm">
                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3">
                    <h2 class="text-sm font-semibold text-slate-900">Stops ({{ $trip->stops->count() }})</h2>
                    @if($canEdit)
                        <div class="flex flex-wrap items-center gap-1">
                            @foreach($waypointTypes as $code => $meta)
                                <button wire:click="openWaypoint(null, '{{ $code }}')"
                                    class="rounded-md border border-slate-200 bg-white px-2 py-1 text-[11px] font-medium text-slate-700 hover:bg-slate-50">
                                    + {{ $meta['label'] }}
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>

                <ol class="divide-y divide-slate-100">
                    @forelse($trip->stops as $stop)
                        @php
                            $isJob = in_array($stop->stop_type, TripStop::JOB_LINKED_TYPES, true);
                            $rowBadge = match (true) {
                                $stop->stop_type === TripStop::TYPE_JOB_PICKUP   => 'bg-blue-100 text-blue-800',
                                $stop->stop_type === TripStop::TYPE_JOB_DROPOFF  => 'bg-emerald-100 text-emerald-800',
                                $stop->stop_type === TripStop::TYPE_POSITIONING  => 'bg-indigo-100 text-indigo-800',
                                str_starts_with($stop->stop_type, 'waypoint_')  => 'bg-amber-100 text-amber-800',
                                default => 'bg-slate-100 text-slate-700',
                            };
                            $locationName = $stop->location?->company_name
                                ?? ($stop->isPickup() ? $stop->job?->pickupLocation?->company_name : null)
                                ?? ($stop->isDropoff() ? $stop->job?->deliveryLocation?->company_name : null)
                                ?? '—';
                        @endphp
                        <li class="px-5 py-4">
                            <div class="flex flex-wrap items-start gap-3">
                                <div class="flex h-7 w-7 flex-none items-center justify-center rounded-full bg-slate-100 text-xs font-bold text-slate-700">
                                    {{ $stop->sequence }}
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider {{ $rowBadge }}">
                                            {{ $stop->typeLabel() }}
                                        </span>
                                        @if($isJob && $stop->job)
                                            <a href="{{ route('admin.orders.show', $stop->job) }}"
                                               class="text-sm font-semibold text-slate-900 hover:text-blue-600">
                                                {{ $stop->job->vin ?? ('JOB-' . $stop->job->id) }}
                                            </a>
                                            <span class="text-xs text-slate-500">· {{ $stop->job->brand?->name }} {{ $stop->job->model_name }}</span>
                                        @else
                                            <span class="text-sm font-semibold text-slate-900">{{ $locationName }}</span>
                                        @endif
                                    </div>
                                    <div class="mt-1 text-xs text-slate-500">
                                        {{ $locationName }}
                                        @if($stop->expected_at) · expected {{ $stop->expected_at->format('H:i') }} @endif
                                    </div>
                                    @if($stop->notes)<p class="mt-1 text-xs italic text-slate-500">{{ $stop->notes }}</p>@endif
                                    <div class="mt-2 flex flex-wrap items-center gap-3 text-[11px] text-slate-500">
                                        @if($stop->arrived_at)<span>arrived <span class="font-medium text-slate-700">{{ $stop->arrived_at->format('H:i') }}</span></span>@endif
                                        @if($stop->departed_at)<span>departed <span class="font-medium text-slate-700">{{ $stop->departed_at->format('H:i') }}</span></span>@endif
                                    </div>
                                </div>

                                <div class="flex flex-none flex-wrap items-center gap-1">
                                    @if(!$stop->arrived_at && !in_array($trip->status, [Trip::STATUS_COMPLETED, Trip::STATUS_CANCELLED], true))
                                        <button wire:click="markArrived({{ $stop->id }})"
                                            class="rounded-md bg-emerald-50 px-2 py-1 text-[11px] font-semibold text-emerald-700 ring-1 ring-emerald-200 hover:bg-emerald-100">Arrived</button>
                                    @endif
                                    @if($stop->arrived_at && !$stop->departed_at && !in_array($trip->status, [Trip::STATUS_COMPLETED, Trip::STATUS_CANCELLED], true))
                                        <button wire:click="markDeparted({{ $stop->id }})"
                                            class="rounded-md bg-blue-50 px-2 py-1 text-[11px] font-semibold text-blue-700 ring-1 ring-blue-200 hover:bg-blue-100">Departed</button>
                                    @endif
                                    @if($canEdit)
                                        <button wire:click="moveStop({{ $stop->id }}, 'up')" title="Move up" class="rounded-md border border-slate-200 bg-white px-1.5 py-1 text-xs text-slate-600 hover:bg-slate-50">↑</button>
                                        <button wire:click="moveStop({{ $stop->id }}, 'down')" title="Move down" class="rounded-md border border-slate-200 bg-white px-1.5 py-1 text-xs text-slate-600 hover:bg-slate-50">↓</button>
                                        <button wire:click="openWaypoint({{ $stop->sequence }}, 'waypoint_other')" title="Insert below" class="rounded-md border border-slate-200 bg-white px-1.5 py-1 text-xs text-slate-600 hover:bg-slate-50">+</button>
                                        <button wire:click="removeStop({{ $stop->id }})" wire:confirm="Remove this stop?" class="rounded-md border border-rose-200 bg-white px-1.5 py-1 text-xs text-rose-600 hover:bg-rose-50">✕</button>
                                    @endif
                                </div>
                            </div>

                            @if($canEdit && $insertAfterSequence === $stop->sequence && $waypointType)
                                @include('pages.admin.trips._waypoint-form')
                            @endif
                        </li>
                    @empty
                        <li class="px-5 py-12 text-center text-sm text-slate-500">No stops yet. Add a job from the right panel.</li>
                    @endforelse

                    @if($canEdit && $insertAfterSequence === null && $waypointType)
                        <li class="px-5 py-4">@include('pages.admin.trips._waypoint-form')</li>
                    @endif
                </ol>
            </div>
        </div>

        <div>
            <div class="rounded-xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 px-5 py-3">
                    <h2 class="text-sm font-semibold text-slate-900">Unassigned jobs for {{ $trip->company?->name }}</h2>
                    <p class="mt-0.5 text-xs text-slate-500">±3 days of {{ $trip->trip_date->format('j M') }}.</p>
                </div>
                <ul class="max-h-[640px] divide-y divide-slate-100 overflow-y-auto">
                    @forelse($attachableJobs as $job)
                        <li class="px-5 py-3 hover:bg-slate-50/70">
                            <div class="text-sm font-semibold text-slate-900">{{ $job->vin ?? ('JOB-' . $job->id) }}</div>
                            <div class="text-xs text-slate-500">{{ $job->brand?->name }} {{ $job->model_name }} · {{ $job->vehicleClass?->name }}</div>
                            <div class="mt-1 text-[11px] text-slate-500">
                                <span class="font-medium text-slate-700">{{ $job->pickupLocation?->company_name }}</span>
                                <svg class="inline h-3 w-3 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
                                <span class="font-medium text-slate-700">{{ $job->deliveryLocation?->company_name }}</span>
                            </div>
                            <div class="mt-1 flex items-center justify-between text-[11px] text-slate-500">
                                <span>{{ optional($job->scheduled_date)->format('j M') }}</span>
                                @if($canEdit)
                                    <button wire:click="attachJob({{ $job->id }})"
                                        class="rounded-md bg-blue-600 px-2 py-1 text-[11px] font-semibold text-white hover:bg-blue-500">+ Add</button>
                                @endif
                            </div>
                        </li>
                    @empty
                        <li class="px-5 py-8 text-center text-xs text-slate-500">No unassigned jobs in this window.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</div>
