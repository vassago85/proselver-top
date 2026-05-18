<?php

use App\Models\Company;
use App\Models\Location;
use App\Models\Trip;
use App\Models\User;
use App\Services\TripPlanner;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/*
 * Empty-trip creation. The planner page lives at /customer/trips/{trip}
 * and expects an existing row; this page is the bare minimum form to
 * stand one up.
 *
 * The flow is intentionally light: pick a driver, pick a date, pick
 * start/end depots (default = the dealer's first active location), and
 * we redirect to the planner where jobs/waypoints get dragged on.
 *
 * Permission: customer_owner / customer_admin / customer_dispatcher.
 */
new #[Layout('components.layouts.app')]
class extends Component
{
    public ?Company $company = null;

    public ?int $driverUserId = null;
    public string $tripDate = '';
    public ?int $startLocationId = null;
    public ?int $endLocationId = null;
    public string $notes = '';

    public function mount(): void
    {
        $user = auth()->user();
        $this->company = $user?->company();

        abort_unless($this->company, 403, 'No customer account is associated with your user.');
        abort_unless(
            $user->hasAnyRole(['customer_owner', 'customer_admin', 'customer_dispatcher']),
            403,
            'You don\'t have permission to plan trips.'
        );

        $this->tripDate = now()->addDay()->toDateString();

        $primary = $this->company->locations()
            ->where('is_active', true)
            ->orderByDesc('is_primary')
            ->first();

        if ($primary) {
            $this->startLocationId = (int) $primary->id;
            $this->endLocationId   = (int) $primary->id;
        }
    }

    public function save(TripPlanner $planner): void
    {
        $this->validate([
            'driverUserId'    => 'required|integer|exists:users,id',
            'tripDate'        => 'required|date|after_or_equal:today',
            'startLocationId' => 'nullable|integer|exists:locations,id',
            'endLocationId'   => 'nullable|integer|exists:locations,id',
            'notes'           => 'nullable|string|max:1000',
        ]);

        try {
            $planner->assertDriverHasNoConflictingTrip($this->driverUserId, $this->tripDate);
        } catch (\Throwable $e) {
            $this->addError('driverUserId', $e->getMessage());
            return;
        }

        $trip = Trip::create([
            'company_id'         => $this->company->id,
            'driver_user_id'     => $this->driverUserId,
            'trip_date'          => $this->tripDate,
            'status'             => Trip::STATUS_PLANNED,
            'start_location_id'  => $this->startLocationId,
            'end_location_id'    => $this->endLocationId,
            'notes'              => $this->notes ?: null,
            'created_by_user_id' => auth()->id(),
        ]);

        $this->redirect(route('customer.trips.show', $trip), navigate: true);
    }

    public function with(): array
    {
        $driverOptions = $this->company
            ? User::driversForCompany($this->company->id)
                ->orderBy('users.name')
                ->get(['users.id', 'users.name'])
                ->map(fn ($u) => ['value' => (string) $u->id, 'label' => $u->name])
                ->all()
            : [];

        $locationOptions = $this->company
            ? Location::query()
                ->where('company_id', $this->company->id)
                ->where('is_active', true)
                ->orderByDesc('is_primary')
                ->orderBy('company_name')
                ->get(['id', 'company_name', 'city'])
                ->map(fn ($l) => [
                    'value' => (string) $l->id,
                    'label' => $l->company_name . ($l->city ? ' — ' . $l->city : ''),
                ])
                ->all()
            : [];

        return [
            'driverOptions'   => $driverOptions,
            'locationOptions' => $locationOptions,
        ];
    }
};

?>
<div class="space-y-6">
    <x-slot:header>Plan a new trip</x-slot:header>

    <div class="rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-100 px-5 py-4">
            <h2 class="text-base font-semibold text-slate-900">New driver trip</h2>
            <p class="mt-1 text-xs text-slate-500">
                Pick the driver, the date, and the depots they'll start and end at. Once created you can drag jobs onto the trip,
                add COF / weighbridge / fuel waypoints, and insert positioning legs.
            </p>
        </div>

        <form wire:submit="save" class="space-y-5 px-5 py-5">
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <div>
                    <label class="text-xs font-semibold uppercase tracking-wider text-slate-500">Driver <span class="text-rose-500">*</span></label>
                    <div class="mt-1">
                        <x-searchable-select
                            wire:model="driverUserId"
                            :options="$driverOptions"
                            placeholder="— pick a driver —"
                            search-placeholder="Search drivers…"
                        />
                    </div>
                    @if(empty($driverOptions))
                        <p class="mt-2 text-xs text-amber-700">
                            No drivers on file yet. <a href="{{ route('customer.drivers.index') }}" class="font-medium underline">Add a driver</a> first.
                        </p>
                    @endif
                    @error('driverUserId')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="text-xs font-semibold uppercase tracking-wider text-slate-500">Date <span class="text-rose-500">*</span></label>
                    <input type="date" wire:model="tripDate"
                        min="{{ now()->toDateString() }}"
                        class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"/>
                    @error('tripDate')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="text-xs font-semibold uppercase tracking-wider text-slate-500">Start depot</label>
                    <div class="mt-1">
                        <x-searchable-select
                            wire:model="startLocationId"
                            :options="$locationOptions"
                            placeholder="— pick a depot —"
                            search-placeholder="Search depots…"
                        />
                    </div>
                    @error('startLocationId')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="text-xs font-semibold uppercase tracking-wider text-slate-500">End depot</label>
                    <div class="mt-1">
                        <x-searchable-select
                            wire:model="endLocationId"
                            :options="$locationOptions"
                            placeholder="— pick a depot —"
                            search-placeholder="Search depots…"
                        />
                    </div>
                    @error('endLocationId')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                </div>
            </div>

            <div>
                <label class="text-xs font-semibold uppercase tracking-wider text-slate-500">Planning notes (optional)</label>
                <textarea wire:model="notes" rows="3"
                    class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                    placeholder="e.g. driver to pick up COF certificate on the way back"></textarea>
                @error('notes')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
            </div>

            <div class="flex flex-wrap items-center gap-3 border-t border-slate-100 pt-4">
                <button type="submit"
                    class="inline-flex items-center gap-2 rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-500 disabled:opacity-60"
                    wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">Create trip &amp; plan stops</span>
                    <span wire:loading wire:target="save">Creating…</span>
                </button>
                <a href="{{ route('customer.trips.index') }}" class="text-sm font-medium text-slate-500 hover:text-slate-800">Cancel</a>
            </div>
        </form>
    </div>
</div>
