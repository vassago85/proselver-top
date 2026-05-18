<?php

use App\Models\Company;
use App\Models\Trip;
use App\Models\User;
use Carbon\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/*
 * Dealer-side trip planner index. Groups trips by date (newest at the
 * top) so a dispatcher can scan a week at a glance, with quick
 * driver-status filters. Drivers themselves get a different surface
 * (customer/trips/my-day) — this page is the planner / dispatcher
 * view.
 */
new #[Layout('components.layouts.app')]
class extends Component
{
    use WithPagination;

    public ?Company $company = null;

    #[Url(as: 'driver')]      public ?int $driverFilter = null;
    #[Url(as: 'status')]      public string $statusFilter = 'active';
    #[Url(as: 'from')]        public string $dateFrom = '';
    #[Url(as: 'to')]          public string $dateTo = '';

    public function mount(): void
    {
        $user = auth()->user();
        $this->company = $user?->company();
        abort_unless($this->company, 403);

        if (!$this->dateFrom) { $this->dateFrom = now()->subDays(7)->toDateString(); }
        if (!$this->dateTo)   { $this->dateTo   = now()->addDays(14)->toDateString(); }
    }

    public function updated($field): void
    {
        if (in_array($field, ['driverFilter', 'statusFilter', 'dateFrom', 'dateTo'], true)) {
            $this->resetPage();
        }
    }

    public function with(): array
    {
        $q = Trip::query()
            ->forCompany($this->company->id)
            ->with(['driver:id,name', 'stops', 'startLocation:id,company_name', 'endLocation:id,company_name'])
            ->withCount('stops')
            ->orderByDesc('trip_date')
            ->orderBy('id');

        if ($this->driverFilter) {
            $q->where('driver_user_id', $this->driverFilter);
        }

        if ($this->statusFilter === 'active') {
            $q->whereIn('status', [Trip::STATUS_PLANNED, Trip::STATUS_IN_PROGRESS]);
        } elseif ($this->statusFilter && $this->statusFilter !== 'all') {
            $q->where('status', $this->statusFilter);
        }

        if ($this->dateFrom) { $q->whereDate('trip_date', '>=', $this->dateFrom); }
        if ($this->dateTo)   { $q->whereDate('trip_date', '<=', $this->dateTo); }

        $trips = $q->paginate(25);

        $today = now()->toDateString();
        $tomorrow = now()->addDay()->toDateString();

        $kpis = [
            'today_count'    => Trip::forCompany($this->company->id)->whereDate('trip_date', $today)->active()->count(),
            'tomorrow_count' => Trip::forCompany($this->company->id)->whereDate('trip_date', $tomorrow)->active()->count(),
            'in_progress'    => Trip::forCompany($this->company->id)->where('status', Trip::STATUS_IN_PROGRESS)->count(),
            'completed_7d'   => Trip::forCompany($this->company->id)
                ->where('status', Trip::STATUS_COMPLETED)
                ->whereBetween('trip_date', [now()->subDays(7)->toDateString(), $today])
                ->count(),
        ];

        // "Drivers without a trip today" — quick prompt for the planner.
        $allDrivers = User::driversForCompany($this->company->id)->get(['users.id', 'users.name']);
        $driversWithTodayTrip = Trip::forCompany($this->company->id)
            ->whereDate('trip_date', $today)
            ->active()
            ->pluck('driver_user_id')
            ->all();
        $idleDrivers = $allDrivers->reject(fn ($d) => in_array($d->id, $driversWithTodayTrip, true))->values();

        return [
            'trips'        => $trips,
            'kpis'         => $kpis,
            'idleDrivers'  => $idleDrivers,
            'driverOptions' => $allDrivers
                ->map(fn ($d) => ['value' => (string) $d->id, 'label' => $d->name])
                ->prepend(['value' => '', 'label' => 'All drivers'])
                ->all(),
            'statusLabels' => Trip::STATUS_LABELS,
        ];
    }
};

?>
<div class="space-y-6">
    <x-slot:header>Trips</x-slot:header>

    {{-- KPI strip --}}
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div class="rounded-xl border border-blue-200 bg-blue-50 p-4">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-blue-700">Today</p>
            <p class="mt-1 text-2xl font-bold text-blue-900 tabular-nums">{{ $kpis['today_count'] }}</p>
            <p class="mt-1 text-[11px] text-blue-700">active trips</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Tomorrow</p>
            <p class="mt-1 text-2xl font-bold text-slate-900 tabular-nums">{{ $kpis['tomorrow_count'] }}</p>
            <p class="mt-1 text-[11px] text-slate-500">planned</p>
        </div>
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-emerald-700">In progress</p>
            <p class="mt-1 text-2xl font-bold text-emerald-900 tabular-nums">{{ $kpis['in_progress'] }}</p>
            <p class="mt-1 text-[11px] text-emerald-700">on the road now</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Completed 7d</p>
            <p class="mt-1 text-2xl font-bold text-slate-900 tabular-nums">{{ $kpis['completed_7d'] }}</p>
        </div>
    </div>

    {{-- Idle drivers prompt --}}
    @if($idleDrivers->isNotEmpty())
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-sm font-semibold text-amber-900">Drivers without a trip today</p>
                    <p class="mt-1 text-xs text-amber-700">
                        {{ $idleDrivers->count() }} driver(s) idle — assign them a trip to keep the schedule tight.
                    </p>
                </div>
                <a href="{{ route('customer.trips.create') }}"
                   class="inline-flex items-center gap-1.5 rounded-md bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-500">
                    Plan a trip
                </a>
            </div>
            <ul class="mt-3 flex flex-wrap gap-2">
                @foreach($idleDrivers as $d)
                    <li class="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 text-xs font-medium text-amber-800 ring-1 ring-amber-200">
                        {{ $d->name }}
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Filters + new trip button --}}
    <div class="rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="flex flex-wrap items-end justify-between gap-3 border-b border-slate-100 px-5 py-4">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Driver</label>
                    <div class="mt-1 w-56">
                        <x-searchable-select
                            wire:model.live="driverFilter"
                            :options="$driverOptions"
                            placeholder="All drivers"
                        />
                    </div>
                </div>
                <div>
                    <label class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Status</label>
                    <select wire:model.live="statusFilter"
                        class="mt-1 rounded-md border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="active">Active (planned + in-progress)</option>
                        <option value="all">All</option>
                        @foreach($statusLabels as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">From</label>
                    <input type="date" wire:model.live="dateFrom"
                        class="mt-1 rounded-md border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"/>
                </div>
                <div>
                    <label class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">To</label>
                    <input type="date" wire:model.live="dateTo"
                        class="mt-1 rounded-md border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"/>
                </div>
            </div>
            <a href="{{ route('customer.trips.create') }}"
                class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-500">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14" stroke-linecap="round"/></svg>
                New trip
            </a>
        </div>

        {{-- Trip list --}}
        <div class="divide-y divide-slate-100">
            @forelse($trips as $trip)
                @php
                    $pill = match ($trip->status) {
                        Trip::STATUS_PLANNED     => 'bg-blue-100 text-blue-800',
                        Trip::STATUS_IN_PROGRESS => 'bg-emerald-100 text-emerald-800',
                        Trip::STATUS_COMPLETED   => 'bg-slate-100 text-slate-700',
                        Trip::STATUS_CANCELLED   => 'bg-rose-100 text-rose-700',
                        default                  => 'bg-slate-100 text-slate-700',
                    };
                    $jobStops = $trip->stops->filter(fn ($s) => in_array($s->stop_type, \App\Models\TripStop::JOB_LINKED_TYPES, true));
                    $vehicleCount = $jobStops->where('stop_type', \App\Models\TripStop::TYPE_JOB_PICKUP)->count();
                @endphp
                <a href="{{ route('customer.trips.show', $trip) }}"
                   class="block px-5 py-4 transition-colors hover:bg-slate-50/70">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <div class="flex h-12 w-14 flex-col items-center justify-center rounded-md bg-slate-100 text-slate-700">
                                <span class="text-[10px] font-semibold uppercase">{{ $trip->trip_date->format('M') }}</span>
                                <span class="text-lg font-bold leading-none">{{ $trip->trip_date->format('d') }}</span>
                            </div>
                            <div>
                                <div class="text-sm font-semibold text-slate-900">
                                    {{ $trip->driver?->name ?? 'Unassigned driver' }}
                                </div>
                                <div class="mt-0.5 text-xs text-slate-500">
                                    {{ $trip->startLocation?->company_name ?? 'No start depot' }}
                                    <svg class="inline h-3 w-3 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
                                    {{ $trip->endLocation?->company_name ?? 'No end depot' }}
                                </div>
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center gap-3">
                            <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider {{ $pill }}">
                                {{ $trip->statusLabel() }}
                            </span>
                            <span class="text-[11px] text-slate-500 tabular-nums">{{ $trip->stops_count }} stops · {{ $vehicleCount }} vehicle{{ $vehicleCount === 1 ? '' : 's' }}</span>
                            <svg class="h-4 w-4 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
                        </div>
                    </div>
                </a>
            @empty
                <div class="px-5 py-12 text-center">
                    <p class="text-sm text-slate-500">No trips in this window.</p>
                    <a href="{{ route('customer.trips.create') }}"
                        class="mt-3 inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-500">
                        Plan the first trip
                    </a>
                </div>
            @endforelse
        </div>

        @if($trips->hasPages())
            <div class="border-t border-slate-100 px-5 py-3">{{ $trips->links() }}</div>
        @endif
    </div>
</div>
