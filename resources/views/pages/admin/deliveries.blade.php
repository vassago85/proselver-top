<?php

use App\Models\Job;
use Carbon\Carbon;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    #[Url]
    public string $range = 'today';

    #[Url]
    public string $search = '';

    public const ALLOWED_RANGES = ['today', 'yesterday', 'last_7_days', 'this_month', 'all'];

    public const DELIVERED_STATUSES = [
        Job::STATUS_DELIVERED,
        Job::STATUS_COMPLETED,
    ];

    public function mount(): void
    {
        if (!in_array($this->range, self::ALLOWED_RANGES, true)) {
            $this->range = 'today';
        }
    }

    public function setRange(string $range): void
    {
        if (!in_array($range, self::ALLOWED_RANGES, true)) {
            return;
        }
        $this->range = $range;
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function clearSearch(): void
    {
        $this->search = '';
        $this->resetPage();
    }

    /**
     * Convert the selected range into a [start, end] Carbon pair, or null when
     * "all" is chosen. Dates are resolved against the app timezone so a
     * "Today" filter agrees with the dashboard's "Delivered Today" card.
     */
    protected function resolveRange(): ?array
    {
        return match ($this->range) {
            'today'       => [today()->startOfDay(), today()->endOfDay()],
            'yesterday'   => [today()->subDay()->startOfDay(), today()->subDay()->endOfDay()],
            'last_7_days' => [today()->subDays(6)->startOfDay(), today()->endOfDay()],
            'this_month'  => [now()->startOfMonth(), now()->endOfMonth()],
            default       => null,
        };
    }

    public function with(): array
    {
        $rangeDates = $this->resolveRange();

        $base = Job::query()
            ->whereIn('status', self::DELIVERED_STATUSES)
            ->whereNotNull('delivered_at');

        if ($rangeDates !== null) {
            [$start, $end] = $rangeDates;
            $base->whereBetween('delivered_at', [$start, $end]);
        }

        // Counts for the range toggle pills. Run independently of the list
        // so a driver/VIN search narrows the table without rewriting counts.
        $counts = [];
        foreach (self::ALLOWED_RANGES as $r) {
            $counts[$r] = $this->countForRange($r);
        }

        $query = (clone $base)
            ->with([
                'company:id,name',
                'pickupLocation:id,company_name,city',
                'deliveryLocation:id,company_name,city',
                'driver:id,name,phone',
                'brand:id,name',
            ])
            ->orderByDesc('delivered_at');

        if ($this->search !== '') {
            $term = $this->search;
            $query->where(function ($q) use ($term) {
                $q->where('job_number', 'ilike', "%{$term}%")
                    ->orWhere('vin', 'ilike', "%{$term}%")
                    ->orWhere('registration', 'ilike', "%{$term}%")
                    ->orWhere('model_name', 'ilike', "%{$term}%")
                    ->orWhereHas('brand', fn($q) => $q->where('name', 'ilike', "%{$term}%"))
                    ->orWhereHas('company', fn($q) => $q->where('name', 'ilike', "%{$term}%"))
                    ->orWhereHas('pickupLocation', fn($q) => $q->where('company_name', 'ilike', "%{$term}%"))
                    ->orWhereHas('deliveryLocation', fn($q) => $q->where('company_name', 'ilike', "%{$term}%"))
                    ->orWhereHas('driver', fn($q) => $q->where('name', 'ilike', "%{$term}%"));
            });
        }

        return [
            'jobs' => $query->paginate(25),
            'counts' => $counts,
            'rangeLabel' => $this->rangeLabel($this->range),
        ];
    }

    protected function countForRange(string $range): int
    {
        $q = Job::query()
            ->whereIn('status', self::DELIVERED_STATUSES)
            ->whereNotNull('delivered_at');

        $dates = match ($range) {
            'today'       => [today()->startOfDay(), today()->endOfDay()],
            'yesterday'   => [today()->subDay()->startOfDay(), today()->subDay()->endOfDay()],
            'last_7_days' => [today()->subDays(6)->startOfDay(), today()->endOfDay()],
            'this_month'  => [now()->startOfMonth(), now()->endOfMonth()],
            default       => null,
        };

        if ($dates !== null) {
            $q->whereBetween('delivered_at', $dates);
        }

        return $q->count();
    }

    protected function rangeLabel(string $range): string
    {
        return match ($range) {
            'today'       => 'Today',
            'yesterday'   => 'Yesterday',
            'last_7_days' => 'Last 7 days',
            'this_month'  => 'This month',
            'all'         => 'All time',
            default       => 'Today',
        };
    }
};

?>

<div>
    <x-slot:header>Deliveries</x-slot:header>

    <div class="mb-5">
        <h1 class="text-2xl font-semibold text-slate-900">Delivered Vehicles</h1>
        <p class="mt-1 text-sm text-slate-500">
            Vehicles the driver has marked as delivered, including those already completed by ops. Showing
            <span class="font-semibold text-slate-900">{{ $rangeLabel }}</span>.
        </p>
    </div>

    {{-- Range toggle pills --}}
    <div class="mb-5 flex flex-wrap items-center gap-2">
        @php
            $rangePills = [
                'today'       => 'Today',
                'yesterday'   => 'Yesterday',
                'last_7_days' => 'Last 7 days',
                'this_month'  => 'This month',
                'all'         => 'All time',
            ];
        @endphp
        @foreach($rangePills as $value => $label)
            <button type="button" wire:click="setRange('{{ $value }}')"
                class="inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-semibold transition-colors
                    {{ $range === $value
                        ? 'border-emerald-600 bg-emerald-50 text-emerald-700'
                        : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300 hover:text-slate-900' }}">
                <span>{{ $label }}</span>
                <span class="tabular-nums text-[11px] {{ $range === $value ? 'text-emerald-600' : 'text-slate-400' }}">{{ $counts[$value] ?? 0 }}</span>
            </button>
        @endforeach
    </div>

    {{-- Search --}}
    <div class="mb-4">
        <div class="relative">
            <input wire:model.live.debounce.300ms="search" type="text"
                placeholder="Search by order #, VIN, registration, make/model, customer, route, or driver..."
                class="w-full rounded-lg border border-slate-300 px-4 py-2.5 pr-10 text-sm focus:border-emerald-500 focus:ring-emerald-500">
            @if($search !== '')
                <button type="button" wire:click="clearSearch"
                    class="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </button>
            @endif
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase">Order #</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase">Customer</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase">Make / Model</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase">VIN</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase">Route</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase">Driver</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase">Delivered</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    @forelse($jobs as $job)
                    <tr class="hover:bg-slate-50 cursor-pointer" onclick="window.location='{{ route('admin.orders.show', $job) }}'">
                        <td class="px-4 py-3 text-sm font-medium text-blue-600">{{ $job->job_number ?? '—' }}</td>
                        <td class="px-4 py-3 text-sm text-slate-900">{{ $job->company?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-sm text-slate-900">{{ $job->brand?->name }} {{ $job->model_name }}</td>
                        <td class="px-4 py-3 text-sm font-mono text-slate-600 uppercase">{{ $job->vin ? strtoupper($job->vin) : '—' }}</td>
                        <td class="px-4 py-3 text-sm text-slate-500">
                            {{ $job->pickupLocation?->company_name ?? '—' }}
                            <span class="text-slate-300">→</span>
                            {{ $job->deliveryLocation?->company_name ?? '—' }}
                        </td>
                        <td class="px-4 py-3 text-sm text-slate-900">{{ $job->driver?->name ?? '—' }}</td>
                        <td class="px-4 py-3"><x-status-badge :status="$job->status" /></td>
                        <td class="px-4 py-3 text-sm text-slate-500" title="{{ $job->delivered_at?->format('d M Y H:i') }}">
                            @if($job->delivered_at)
                                <span class="text-slate-900">{{ $job->delivered_at->format('d M Y') }}</span>
                                <span class="text-slate-400 ml-1">{{ $job->delivered_at->format('H:i') }}</span>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="8" class="px-4 py-12 text-center text-sm text-slate-500">
                            @if($search !== '')
                                No deliveries match <span class="font-semibold text-slate-700">"{{ $search }}"</span> for {{ strtolower($rangeLabel) }}.
                            @else
                                No vehicles delivered {{ strtolower($rangeLabel) }}.
                            @endif
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($jobs->hasPages())
        <div class="border-t border-slate-200 px-4 py-3">
            {{ $jobs->links() }}
        </div>
        @endif
    </div>
</div>
