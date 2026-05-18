<?php

use App\Models\Company;
use App\Models\Trip;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/*
 * Cross-company trip overview for ProSelver ops. Same shape as the
 * dealer index but with an extra company filter so ops can either focus
 * on a single dealer (when assisting them) or see everything at once
 * (typical morning briefing).
 */
new #[Layout('components.layouts.app')]
class extends Component
{
    use WithPagination;

    #[Url(as: 'company')] public ?int $companyFilter = null;
    #[Url(as: 'driver')]  public ?int $driverFilter = null;
    #[Url(as: 'status')]  public string $statusFilter = 'active';
    #[Url(as: 'from')]    public string $dateFrom = '';
    #[Url(as: 'to')]      public string $dateTo = '';

    public function mount(): void
    {
        if (!$this->dateFrom) { $this->dateFrom = now()->subDays(7)->toDateString(); }
        if (!$this->dateTo)   { $this->dateTo   = now()->addDays(14)->toDateString(); }
    }

    public function updated($field): void
    {
        if (in_array($field, ['companyFilter', 'driverFilter', 'statusFilter', 'dateFrom', 'dateTo'], true)) {
            $this->resetPage();
        }
    }

    public function with(): array
    {
        $q = Trip::query()
            ->with([
                'driver:id,name',
                'company:id,name',
                'startLocation:id,company_name',
                'endLocation:id,company_name',
            ])
            ->withCount('stops')
            ->orderByDesc('trip_date')
            ->orderBy('id');

        if ($this->companyFilter) { $q->where('company_id', $this->companyFilter); }
        if ($this->driverFilter)  { $q->where('driver_user_id', $this->driverFilter); }

        if ($this->statusFilter === 'active') {
            $q->whereIn('status', [Trip::STATUS_PLANNED, Trip::STATUS_IN_PROGRESS]);
        } elseif ($this->statusFilter && $this->statusFilter !== 'all') {
            $q->where('status', $this->statusFilter);
        }

        if ($this->dateFrom) { $q->whereDate('trip_date', '>=', $this->dateFrom); }
        if ($this->dateTo)   { $q->whereDate('trip_date', '<=', $this->dateTo); }

        $trips = $q->paginate(30);

        $today = now()->toDateString();

        $kpis = [
            'today_count'  => Trip::whereDate('trip_date', $today)->active()->count(),
            'in_progress'  => Trip::where('status', Trip::STATUS_IN_PROGRESS)->count(),
            'planned_7d'   => Trip::whereBetween('trip_date', [$today, now()->addDays(7)->toDateString()])
                                  ->where('status', Trip::STATUS_PLANNED)->count(),
            'completed_7d' => Trip::where('status', Trip::STATUS_COMPLETED)
                                  ->whereBetween('trip_date', [now()->subDays(7)->toDateString(), $today])
                                  ->count(),
        ];

        return [
            'trips' => $trips,
            'kpis'  => $kpis,
            'companyOptions' => Company::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($c) => ['value' => (string) $c->id, 'label' => $c->name])
                ->prepend(['value' => '', 'label' => 'All companies'])
                ->all(),
            'driverOptions' => User::query()
                ->whereHas('roles', fn ($r) => $r->where('slug', 'driver'))
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($u) => ['value' => (string) $u->id, 'label' => $u->name])
                ->prepend(['value' => '', 'label' => 'All drivers'])
                ->all(),
            'statusLabels' => Trip::STATUS_LABELS,
        ];
    }
};

?>
<div class="space-y-6">
    <x-slot:header>Trips (ops)</x-slot:header>

    {{-- KPI strip --}}
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div class="rounded-xl border border-blue-200 bg-blue-50 p-4">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-blue-700">Today (all)</p>
            <p class="mt-1 text-2xl font-bold text-blue-900 tabular-nums">{{ $kpis['today_count'] }}</p>
        </div>
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-emerald-700">In progress</p>
            <p class="mt-1 text-2xl font-bold text-emerald-900 tabular-nums">{{ $kpis['in_progress'] }}</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Planned 7d</p>
            <p class="mt-1 text-2xl font-bold text-slate-900 tabular-nums">{{ $kpis['planned_7d'] }}</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Completed 7d</p>
            <p class="mt-1 text-2xl font-bold text-slate-900 tabular-nums">{{ $kpis['completed_7d'] }}</p>
        </div>
    </div>

    {{-- Filters --}}
    <div class="rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="flex flex-wrap items-end gap-3 border-b border-slate-100 px-5 py-4">
            <div>
                <label class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Company</label>
                <div class="mt-1 w-60">
                    <x-searchable-select wire:model.live="companyFilter" :options="$companyOptions" placeholder="All companies"/>
                </div>
            </div>
            <div>
                <label class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Driver</label>
                <div class="mt-1 w-56">
                    <x-searchable-select wire:model.live="driverFilter" :options="$driverOptions" placeholder="All drivers"/>
                </div>
            </div>
            <div>
                <label class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Status</label>
                <select wire:model.live="statusFilter"
                    class="mt-1 rounded-md border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    <option value="active">Active</option>
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
                @endphp
                <a href="{{ route('admin.trips.show', $trip) }}"
                   class="block px-5 py-4 transition-colors hover:bg-slate-50/70">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <div class="flex h-12 w-14 flex-col items-center justify-center rounded-md bg-slate-100 text-slate-700">
                                <span class="text-[10px] font-semibold uppercase">{{ $trip->trip_date->format('M') }}</span>
                                <span class="text-lg font-bold leading-none">{{ $trip->trip_date->format('d') }}</span>
                            </div>
                            <div>
                                <div class="text-sm font-semibold text-slate-900">
                                    {{ $trip->driver?->name ?? 'Unassigned' }}
                                    <span class="ml-2 text-xs font-normal text-slate-500">· {{ $trip->company?->name }}</span>
                                </div>
                                <div class="mt-0.5 text-xs text-slate-500">
                                    {{ $trip->startLocation?->company_name ?? '—' }}
                                    <svg class="inline h-3 w-3 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
                                    {{ $trip->endLocation?->company_name ?? '—' }}
                                </div>
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center gap-3">
                            <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider {{ $pill }}">
                                {{ $trip->statusLabel() }}
                            </span>
                            <span class="text-[11px] text-slate-500 tabular-nums">{{ $trip->stops_count }} stops</span>
                            <svg class="h-4 w-4 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
                        </div>
                    </div>
                </a>
            @empty
                <div class="px-5 py-12 text-center text-sm text-slate-500">No trips match those filters.</div>
            @endforelse
        </div>

        @if($trips->hasPages())
            <div class="border-t border-slate-100 px-5 py-3">{{ $trips->links() }}</div>
        @endif
    </div>
</div>
