<?php

use App\Models\Trip;
use App\Models\TripStop;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/*
 * Driver "My Day" — the touch-friendly counterpart to the planner page.
 * Lists the authenticated driver's active or most-recently-completed
 * trip for today with one-tap Arrived / Departed buttons per stop.
 *
 * Available to any user with the `driver` role; also linkable from
 * sidebar by other dispatchers who happen to share the role.
 */
new #[Layout('components.layouts.app')]
class extends Component
{
    public ?Trip $trip = null;
    public ?string $errorMessage = null;

    public function mount(): void
    {
        $user = auth()->user();
        abort_unless($user, 403);
        abort_unless(
            $user->hasAnyRole(['driver', 'customer_dispatcher', 'customer_admin', 'customer_owner']),
            403,
            'My Day is for drivers and their dispatchers.'
        );

        $this->loadTrip();
    }

    private function loadTrip(): void
    {
        $today = now()->toDateString();
        $user = auth()->user();

        // Today's active trip wins; fall back to today's completed trip
        // so the driver can still see the day's history; otherwise null
        // (renders the empty state).
        $this->trip = Trip::query()
            ->where('driver_user_id', $user->id)
            ->whereDate('trip_date', $today)
            ->orderByRaw("CASE WHEN status = 'in_progress' THEN 0 WHEN status = 'planned' THEN 1 WHEN status = 'completed' THEN 2 ELSE 3 END")
            ->with([
                'stops.job.brand',
                'stops.job.pickupLocation',
                'stops.job.deliveryLocation',
                'stops.location',
                'startLocation',
                'endLocation',
            ])
            ->first();
    }

    public function startTrip(): void
    {
        if ($this->trip && $this->trip->isPlanned()) {
            $this->trip->start();
            $this->loadTrip();
        }
    }

    public function completeTrip(): void
    {
        if ($this->trip && $this->trip->isInProgress()) {
            $this->trip->complete();
            $this->loadTrip();
        }
    }

    public function markArrived(int $stopId): void
    {
        $this->errorMessage = null;
        $stop = TripStop::find($stopId);
        if (!$stop || (int) $stop->trip_id !== (int) $this->trip?->id) { return; }
        $stop->markArrived();
        $this->loadTrip();
    }

    public function markDeparted(int $stopId): void
    {
        $this->errorMessage = null;
        $stop = TripStop::find($stopId);
        if (!$stop || (int) $stop->trip_id !== (int) $this->trip?->id) { return; }
        $stop->markDeparted();
        $this->loadTrip();
    }

    public function markCompleted(int $stopId): void
    {
        $this->errorMessage = null;
        $stop = TripStop::find($stopId);
        if (!$stop || (int) $stop->trip_id !== (int) $this->trip?->id) { return; }
        $stop->markCompleted();
        $this->loadTrip();
    }

    public function appendNote(int $stopId, string $note): void
    {
        $note = trim($note);
        if ($note === '') { return; }
        $stop = TripStop::find($stopId);
        if (!$stop || (int) $stop->trip_id !== (int) $this->trip?->id) { return; }
        $stop->notes = trim(($stop->notes ? $stop->notes . "\n" : '') . $note);
        $stop->save();
        $this->loadTrip();
    }
};

?>
<div class="space-y-5">
    <x-slot:header>My Day</x-slot:header>

    @if($errorMessage)
        <div class="rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
            {{ $errorMessage }}
        </div>
    @endif

    @if(!$trip)
        <div class="rounded-xl border border-slate-200 bg-white p-8 text-center">
            <p class="text-base font-semibold text-slate-900">No trip planned for today</p>
            <p class="mt-1 text-sm text-slate-500">Check back later — your dispatcher will add jobs as they're booked.</p>
        </div>
    @else
        @php
            $statusPill = match ($trip->status) {
                Trip::STATUS_PLANNED     => 'bg-blue-100 text-blue-800',
                Trip::STATUS_IN_PROGRESS => 'bg-emerald-100 text-emerald-800',
                Trip::STATUS_COMPLETED   => 'bg-slate-100 text-slate-700',
                Trip::STATUS_CANCELLED   => 'bg-rose-100 text-rose-700',
                default                  => 'bg-slate-100 text-slate-700',
            };
            $totalStops   = $trip->stops->count();
            $arrivedCount = $trip->stops->whereNotNull('arrived_at')->count();
            $progress     = $totalStops > 0 ? (int) round(($arrivedCount / $totalStops) * 100) : 0;
        @endphp

        {{-- Trip header --}}
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-lg font-semibold text-slate-900">{{ now()->format('l, j M Y') }}</h1>
                        <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wider {{ $statusPill }}">
                            {{ $trip->statusLabel() }}
                        </span>
                    </div>
                    <p class="mt-1 text-sm text-slate-500">
                        {{ $trip->startLocation?->company_name ?? 'Start' }}
                        →
                        {{ $trip->endLocation?->company_name ?? 'End' }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    @if($trip->isPlanned())
                        <button wire:click="startTrip"
                            class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">
                            Start trip
                        </button>
                    @elseif($trip->isInProgress())
                        <button wire:click="completeTrip" wire:confirm="Mark today's trip as completed?"
                            class="rounded-md bg-slate-800 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">
                            Complete trip
                        </button>
                    @endif
                </div>
            </div>

            {{-- Progress bar --}}
            <div class="mt-4">
                <div class="flex items-center justify-between text-[11px] text-slate-500">
                    <span>{{ $arrivedCount }} of {{ $totalStops }} stops</span>
                    <span class="tabular-nums">{{ $progress }}%</span>
                </div>
                <div class="mt-1 h-2 overflow-hidden rounded-full bg-slate-100">
                    <div class="h-full rounded-full bg-emerald-500 transition-all" style="width: {{ $progress }}%"></div>
                </div>
            </div>
        </div>

        {{-- Stop list --}}
        <ol class="space-y-3">
            @foreach($trip->stops as $stop)
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
                    $address = $stop->location?->address
                        ?? ($stop->isPickup() ? $stop->job?->pickupLocation?->address : null)
                        ?? ($stop->isDropoff() ? $stop->job?->deliveryLocation?->address : null);
                    $contactName = $stop->isPickup() ? $stop->job?->pickup_contact_name
                                : ($stop->isDropoff() ? $stop->job?->delivery_contact_name : null);
                    $contactPhone = $stop->isPickup() ? $stop->job?->pickup_contact_phone
                                : ($stop->isDropoff() ? $stop->job?->delivery_contact_phone : null);
                    $isDone = $stop->arrived_at && $stop->departed_at;
                @endphp

                <li class="rounded-xl border {{ $isDone ? 'border-slate-200 bg-slate-50 opacity-80' : 'border-slate-200 bg-white' }} shadow-sm">
                    <div class="p-4">
                        <div class="flex items-start gap-3">
                            <div class="flex h-9 w-9 flex-none items-center justify-center rounded-full bg-slate-100 text-sm font-bold text-slate-700">
                                {{ $stop->sequence }}
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider {{ $rowBadge }}">
                                        {{ $stop->typeLabel() }}
                                    </span>
                                    @if($stop->expected_at)
                                        <span class="text-[11px] text-slate-500">expected {{ $stop->expected_at->format('H:i') }}</span>
                                    @endif
                                </div>
                                <p class="mt-1 text-base font-semibold text-slate-900">{{ $locationName }}</p>
                                @if($address)
                                    <p class="text-xs text-slate-500">{{ $address }}</p>
                                @endif

                                @if($isJob && $stop->job)
                                    <p class="mt-2 text-xs text-slate-600">
                                        <span class="font-mono">{{ $stop->job->vin }}</span>
                                        · {{ $stop->job->brand?->name }} {{ $stop->job->model_name }}
                                    </p>
                                @endif

                                @if($contactPhone)
                                    <a href="tel:{{ $contactPhone }}"
                                       class="mt-2 inline-flex items-center gap-1.5 text-xs font-semibold text-blue-600 hover:text-blue-800">
                                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.37 1.9.72 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.35 1.85.59 2.81.72A2 2 0 0 1 22 16.92z" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                        {{ $contactName ? $contactName . ' · ' : '' }}{{ $contactPhone }}
                                    </a>
                                @endif

                                @if($stop->notes)
                                    <p class="mt-2 rounded-md bg-amber-50 px-2 py-1 text-xs text-amber-800">
                                        {{ $stop->notes }}
                                    </p>
                                @endif

                                <div class="mt-2 flex flex-wrap items-center gap-3 text-[11px] text-slate-500">
                                    @if($stop->arrived_at)
                                        <span>arrived <span class="font-semibold text-slate-700">{{ $stop->arrived_at->format('H:i') }}</span></span>
                                    @endif
                                    @if($stop->departed_at)
                                        <span>departed <span class="font-semibold text-slate-700">{{ $stop->departed_at->format('H:i') }}</span></span>
                                    @endif
                                </div>
                            </div>
                        </div>

                        @if(!$isDone && $trip->status !== Trip::STATUS_COMPLETED && $trip->status !== Trip::STATUS_CANCELLED)
                            <div class="mt-4 flex flex-wrap gap-2">
                                @if(!$stop->arrived_at)
                                    <button wire:click="markArrived({{ $stop->id }})"
                                        class="flex-1 rounded-md bg-emerald-600 px-4 py-3 text-sm font-semibold text-white hover:bg-emerald-500">
                                        I've arrived
                                    </button>
                                @elseif(!$stop->departed_at)
                                    <button wire:click="markDeparted({{ $stop->id }})"
                                        class="flex-1 rounded-md bg-blue-600 px-4 py-3 text-sm font-semibold text-white hover:bg-blue-500">
                                        Leaving now
                                    </button>
                                @endif
                                @if($stop->isWaypoint())
                                    <button wire:click="markCompleted({{ $stop->id }})"
                                        class="rounded-md border border-slate-300 bg-white px-3 py-3 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                        Quick done
                                    </button>
                                @endif
                            </div>

                            @if($stop->isWaypoint())
                                <div x-data="{ note: '' }" class="mt-3 flex items-center gap-2">
                                    <input x-model="note" type="text"
                                        placeholder="Add a quick note (COF cert #, weighbridge ticket, etc.)"
                                        class="flex-1 rounded-md border-slate-300 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500"/>
                                    <button @click="$wire.appendNote({{ $stop->id }}, note); note = ''"
                                        class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                        Save note
                                    </button>
                                </div>
                            @endif
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    @endif
</div>
