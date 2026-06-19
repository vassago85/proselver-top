<?php

use App\Models\Brand;
use App\Models\Company;
use App\Models\Job;
use App\Models\VehicleClass;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/*
 * Dealer-scoped deliveries report. Mirrors the admin Executive
 * Reports page but anchored on the booking company so the dealer
 * only ever sees their own vehicles. Adds the executor + destination
 * + archived filters introduced for the dealer-executor feature so
 * the dealer can answer "where are my vehicles?" / "what's still at
 * a body builder?" / "what has the courier finished?" without
 * leaving this page.
 */

new #[Layout('components.layouts.app')] class extends Component {
    public ?Company $company = null;

    #[Url] public string $dateFrom = '';
    #[Url] public string $dateTo = '';
    #[Url] public ?int $brandId = null;
    #[Url] public ?int $vehicleClassId = null;
    #[Url] public ?string $executorType = null;
    #[Url] public ?string $destinationType = null;
    #[Url] public ?int $tripId = null;
    #[Url] public string $archivedFilter = 'all'; // all | only | exclude
    #[Url] public int $perPage = 50;

    public function mount(): void
    {
        $this->company = auth()->user()->company();
        abort_unless($this->company, 403);

        if (!$this->dateFrom) { $this->dateFrom = now()->startOfMonth()->toDateString(); }
        if (!$this->dateTo)   { $this->dateTo   = now()->toDateString(); }
    }

    public function resetFilters(): void
    {
        $this->reset(['brandId', 'vehicleClassId', 'executorType', 'destinationType', 'tripId']);
        $this->archivedFilter = 'all';
        $this->dateFrom = now()->startOfMonth()->toDateString();
        $this->dateTo   = now()->toDateString();
    }

    public function applyRange(string $range): void
    {
        $now = now();
        [$from, $to] = match ($range) {
            'today'        => [$now->copy()->startOfDay(),     $now->copy()->endOfDay()],
            'this_week'    => [$now->copy()->startOfWeek(),    $now->copy()->endOfWeek()],
            'last_7'       => [$now->copy()->subDays(6),       $now->copy()],
            'this_month'   => [$now->copy()->startOfMonth(),   $now->copy()->endOfMonth()],
            'last_month'   => [$now->copy()->subMonthNoOverflow()->startOfMonth(),
                               $now->copy()->subMonthNoOverflow()->endOfMonth()],
            'last_30'      => [$now->copy()->subDays(29),      $now->copy()],
            'this_quarter' => [$now->copy()->startOfQuarter(), $now->copy()->endOfQuarter()],
            'this_year'    => [$now->copy()->startOfYear(),    $now->copy()->endOfYear()],
            'ytd'          => [$now->copy()->startOfYear(),    $now->copy()],
            default        => [$now->copy()->startOfMonth(),   $now->copy()],
        };
        $this->dateFrom = $from->toDateString();
        $this->dateTo   = $to->toDateString();
    }

    public function exportCsv(): StreamedResponse
    {
        $from = Carbon::parse($this->dateFrom)->startOfDay();
        $to   = Carbon::parse($this->dateTo)->endOfDay();

        $filename = sprintf('deliveries_%s_%s_to_%s.csv',
            \Illuminate\Support\Str::slug($this->company->name),
            $from->toDateString(), $to->toDateString());

        $query = $this->deliveredJobsQuery($from, $to);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'Delivered Date', 'Job Number', 'Executor', 'Brand', 'Model',
                'VIN', 'Vehicle Class', 'Pickup', 'Pickup City', 'Pickup Province',
                'Drop Off', 'Drop Off City', 'Drop Off Province', 'Destination Type',
                'Driver / Carrier', 'Archived',
            ]);

            $query->chunk(500, function ($jobs) use ($out) {
                foreach ($jobs as $j) {
                    $carrier = match ($j->executor_type) {
                        Job::EXECUTOR_THIRD_PARTY => $j->third_party_courier_name,
                        Job::EXECUTOR_SELF_COLLECT => $j->self_collect_name,
                        default => $j->driver?->name,
                    };
                    fputcsv($out, [
                        optional($j->delivered_at)->format('Y-m-d H:i'),
                        $j->job_number ?? ('JOB-' . $j->id),
                        Job::EXECUTOR_LABELS[$j->executor_type] ?? $j->executor_type,
                        $j->brand?->name ?? '',
                        $j->model_name ?? '',
                        $j->vin ?? '',
                        $j->vehicleClass?->name ?? '',
                        $j->pickupLocation?->company_name ?? '',
                        $j->pickupLocation?->city ?? '',
                        $j->pickupLocation?->province ?? '',
                        $j->deliveryLocation?->company_name ?? '',
                        $j->deliveryLocation?->city ?? '',
                        $j->deliveryLocation?->province ?? '',
                        $j->destination_type ?? '',
                        $carrier ?? '',
                        $j->archived_at ? 'Y' : 'N',
                    ]);
                }
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    protected function deliveredJobsQuery(Carbon $from, Carbon $to)
    {
        $q = Job::query()
            ->where('company_id', $this->company->id)
            ->whereIn('status', [Job::STATUS_DELIVERED, Job::STATUS_COMPLETED])
            ->whereNotNull('delivered_at')
            ->whereBetween('delivered_at', [$from, $to])
            ->with([
                'brand:id,name',
                'vehicleClass:id,name',
                'pickupLocation:id,company_name,city,province',
                'deliveryLocation:id,company_name,city,province',
                'driver:id,name',
            ])
            ->orderByDesc('delivered_at');

        if ($this->brandId)          { $q->where('brand_id', $this->brandId); }
        if ($this->vehicleClassId)   { $q->where('vehicle_class_id', $this->vehicleClassId); }
        if ($this->executorType)     { $q->where('executor_type', $this->executorType); }
        if ($this->destinationType)  { $q->where('destination_type', $this->destinationType); }
        if ($this->tripId)           { $q->where('trip_id', $this->tripId); }
        if ($this->archivedFilter === 'only')    { $q->whereNotNull('archived_at'); }
        if ($this->archivedFilter === 'exclude') { $q->whereNull('archived_at'); }

        return $q;
    }

    public function archiveJob(int $id): void
    {
        $job = Job::where('company_id', $this->company->id)->findOrFail($id);
        $this->authorize('archive', $job);
        $job->archive();
        session()->flash('success', 'Order archived.');
    }

    public function unarchiveJob(int $id): void
    {
        $job = Job::where('company_id', $this->company->id)->findOrFail($id);
        $this->authorize('unarchive', $job);
        $job->unarchive();
        session()->flash('success', 'Order restored.');
    }

    public function with(): array
    {
        $from = Carbon::parse($this->dateFrom)->startOfDay();
        $to   = Carbon::parse($this->dateTo)->endOfDay();

        $base = $this->deliveredJobsQuery($from, $to);

        $total = (clone $base)->count();
        $atBodyBuilder = Job::query()
            ->where('company_id', $this->company->id)
            ->whereIn('status', [Job::STATUS_DELIVERED, Job::STATUS_COMPLETED])
            ->where('destination_type', Job::DESTINATION_BODY_BUILDER)
            ->whereNull('archived_at')
            ->count();
        // "Final" deliveries == destination is Delivery (DEALER) or
        // legacy null. Body builder / round trip / yard / other are
        // non-final and still in stock — they don't count here.
        $finalDelivered = (clone $base)
            ->where(function ($w) {
                $w->whereNull('destination_type')
                  ->orWhereNotIn('destination_type', Job::NON_FINAL_DESTINATION_TYPES);
            })
            ->count();
        $archivedInWindow = (clone $base)->whereNotNull('archived_at')->count();

        // Per-destination breakdown ordered by volume.
        //
        // MUST mirror the same filter set as $base / $total — otherwise the
        // breakdown shows rows that aren't counted in the denominator and
        // every percentage in the Share column reads 0.0% (or, worse, > 100%
        // when the denominator happens to be smaller). The previous version
        // forgot destinationType + tripId, which is why a FAW dealer
        // filtering by destinationType=round_trip saw 0 KPI deliveries but
        // a full table of depot destinations underneath with 0% shares.
        $destinationBreakdown = Job::query()
            ->where('company_id', $this->company->id)
            ->whereIn('status', [Job::STATUS_DELIVERED, Job::STATUS_COMPLETED])
            ->whereNotNull('delivered_at')
            ->whereBetween('delivered_at', [$from, $to])
            ->when($this->brandId,         fn ($q) => $q->where('brand_id', $this->brandId))
            ->when($this->vehicleClassId,  fn ($q) => $q->where('vehicle_class_id', $this->vehicleClassId))
            ->when($this->executorType,    fn ($q) => $q->where('executor_type', $this->executorType))
            ->when($this->destinationType, fn ($q) => $q->where('destination_type', $this->destinationType))
            ->when($this->tripId,          fn ($q) => $q->where('trip_id', $this->tripId))
            ->when($this->archivedFilter === 'only',    fn ($q) => $q->whereNotNull('archived_at'))
            ->when($this->archivedFilter === 'exclude', fn ($q) => $q->whereNull('archived_at'))
            ->select('delivery_location_id', DB::raw('count(*) as deliveries'))
            ->groupBy('delivery_location_id')
            ->orderByDesc('deliveries')
            ->with('deliveryLocation:id,company_name,city,province')
            ->limit(10)
            ->get();

        $jobs = $base->paginate($this->perPage);

        return [
            'jobs' => $jobs,
            'total' => $total,
            'atBodyBuilder' => $atBodyBuilder,
            'finalDelivered' => $finalDelivered,
            'archivedInWindow' => $archivedInWindow,
            'destinationBreakdown' => $destinationBreakdown,
            'brandOptions' => Brand::query()
                ->orderBy('name')->get(['id', 'name'])
                ->map(fn ($b) => ['value' => $b->id, 'label' => $b->name])->all(),
            'classOptions' => VehicleClass::query()
                ->ordered()->get(['id', 'name'])
                ->map(fn ($v) => ['value' => $v->id, 'label' => $v->name])->all(),
            'executorOptions' => collect(Job::EXECUTOR_LABELS)
                ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
                ->values()->all(),
            'destinationOptions' => collect([
                Job::DESTINATION_DEALER       => 'Delivery',
                Job::DESTINATION_BODY_BUILDER => 'Body Builder or Fitment',
                Job::DESTINATION_ROUND_TRIP   => 'Round Trip',
                Job::DESTINATION_YARD         => 'Other Storage Facility',
                // DESTINATION_OTHER is the legacy bucket — kept as a
                // filter option so old rows can still be drilled into,
                // but new bookings can't be set to it.
                Job::DESTINATION_OTHER        => 'Other (legacy)',
            ])->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values()->all(),
        ];
    }
};

?>
<div class="space-y-6">
    <x-slot:header>Deliveries Report</x-slot:header>

    @if(session('success'))
        <div class="rounded-lg bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800">{{ session('success') }}</div>
    @endif

    {{-- Filters --}}
    <div class="rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-5 py-3">
            <div>
                <h2 class="text-sm font-semibold text-gray-900">Vehicles delivered — {{ $company->name }}</h2>
                <p class="text-xs text-gray-500">Every vehicle that left your yard in the selected window, regardless of executor.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <button wire:click="applyRange('today')"        class="rounded-md border border-gray-200 bg-white px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">Today</button>
                <button wire:click="applyRange('last_7')"       class="rounded-md border border-gray-200 bg-white px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">7d</button>
                <button wire:click="applyRange('this_month')"   class="rounded-md border border-gray-200 bg-white px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">This month</button>
                <button wire:click="applyRange('last_month')"   class="rounded-md border border-gray-200 bg-white px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">Last month</button>
                <button wire:click="applyRange('ytd')"          class="rounded-md border border-gray-200 bg-white px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">YTD</button>
                <button wire:click="exportCsv"
                    class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-500">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                    Export CSV
                </button>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-3 px-5 py-4 sm:grid-cols-2 lg:grid-cols-6">
            <div>
                <label class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">From</label>
                <input type="date" wire:model.live="dateFrom" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"/>
            </div>
            <div>
                <label class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">To</label>
                <input type="date" wire:model.live="dateTo" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"/>
            </div>
            <div>
                <label class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">Executor</label>
                <x-searchable-select
                    wire:model.live="executorType"
                    :options="$executorOptions"
                    placeholder="All executors"
                />
            </div>
            <div>
                <label class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">Destination</label>
                <x-searchable-select
                    wire:model.live="destinationType"
                    :options="$destinationOptions"
                    placeholder="All destinations"
                />
            </div>
            <div>
                <label class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">Brand</label>
                <x-searchable-select
                    wire:model.live="brandId"
                    :options="$brandOptions"
                    placeholder="All brands"
                />
            </div>
            <div>
                <label class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">Class</label>
                <x-searchable-select
                    wire:model.live="vehicleClassId"
                    :options="$classOptions"
                    placeholder="All classes"
                />
            </div>
            <div class="lg:col-span-6">
                <label class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">Archived</label>
                <div class="mt-1 inline-flex rounded-md border border-gray-300 bg-white text-xs">
                    @foreach(['all' => 'All', 'exclude' => 'Active only', 'only' => 'Archived only'] as $value => $label)
                        <button type="button"
                            wire:click="$set('archivedFilter', '{{ $value }}')"
                            class="px-3 py-1.5 font-medium {{ $archivedFilter === $value ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-50' }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            </div>
        </div>

        @php
            $hasActiveFilters = $brandId || $vehicleClassId || $executorType || $destinationType || $archivedFilter !== 'all';
        @endphp
        <div class="flex items-center justify-between border-t border-gray-100 px-5 py-2">
            <p class="text-xs text-gray-500">
                @if($hasActiveFilters)
                    Filters applied — click below to reset everything.
                @else
                    Tip: each dropdown has an &times; to clear it, or reset the whole panel below.
                @endif
            </p>
            <button
                wire:click="resetFilters"
                @class([
                    'inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1 text-xs font-semibold transition',
                    'border-rose-300 bg-rose-50 text-rose-700 hover:bg-rose-100' => $hasActiveFilters,
                    'border-gray-200 bg-white text-gray-400 cursor-default' => ! $hasActiveFilters,
                ])
                @disabled(! $hasActiveFilters)
            >
                <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                Clear filters
            </button>
        </div>
    </div>

    {{-- KPI cards --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-4">
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4">
            <p class="text-[10px] font-semibold uppercase tracking-wider text-emerald-700">Vehicles Delivered</p>
            <p class="mt-1 text-3xl font-semibold text-emerald-900 tabular-nums">{{ number_format($total) }}</p>
            <p class="mt-0.5 text-xs text-emerald-700/80">{{ \Carbon\Carbon::parse($dateFrom)->format('d M') }} → {{ \Carbon\Carbon::parse($dateTo)->format('d M Y') }}</p>
        </div>
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4">
            <p class="text-[10px] font-semibold uppercase tracking-wider text-amber-700">At Body Builder</p>
            <p class="mt-1 text-3xl font-semibold text-amber-900 tabular-nums">{{ number_format($atBodyBuilder) }}</p>
            <p class="mt-0.5 text-xs text-amber-700/80">Awaiting return (live, not filtered by window)</p>
        </div>
        <div class="rounded-xl border border-blue-200 bg-blue-50 p-4">
            <p class="text-[10px] font-semibold uppercase tracking-wider text-blue-700">Final Deliveries</p>
            <p class="mt-1 text-3xl font-semibold text-blue-900 tabular-nums">{{ number_format($finalDelivered) }}</p>
            <p class="mt-0.5 text-xs text-blue-700/80">Excluding body-builder & yard rows</p>
        </div>
        <div class="rounded-xl border border-gray-300 bg-gray-50 p-4">
            <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-700">Archived</p>
            <p class="mt-1 text-3xl font-semibold text-gray-900 tabular-nums">{{ number_format($archivedInWindow) }}</p>
            <p class="mt-0.5 text-xs text-gray-600">Filed out of the active list in this window</p>
        </div>
    </div>

    {{-- Top destinations --}}
    @if($destinationBreakdown->isNotEmpty())
        <div class="rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-100 px-5 py-3">
                <h2 class="text-sm font-semibold text-gray-900">Top delivery destinations</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="bg-gray-50/70 text-[10px] uppercase tracking-[0.15em] text-gray-500">
                            <th class="px-5 py-2 text-left font-semibold">Destination</th>
                            <th class="px-5 py-2 text-right font-semibold">Deliveries</th>
                            <th class="px-5 py-2 text-right font-semibold">Share</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($destinationBreakdown as $row)
                            @php $share = $total > 0 ? ($row->deliveries / $total) * 100 : 0; @endphp
                            <tr>
                                <td class="px-5 py-2.5 text-sm text-gray-900">
                                    <div class="font-medium">{{ $row->deliveryLocation?->company_name ?? '—' }}</div>
                                    @if($row->deliveryLocation?->city)
                                        <div class="text-xs text-gray-500">{{ $row->deliveryLocation->city }}{{ $row->deliveryLocation->province ? ', ' . $row->deliveryLocation->province : '' }}</div>
                                    @endif
                                </td>
                                <td class="px-5 py-2.5 text-right text-sm font-semibold tabular-nums text-gray-900">{{ number_format($row->deliveries) }}</td>
                                <td class="px-5 py-2.5 text-right">
                                    <div class="inline-flex items-center gap-2">
                                        <div class="h-1.5 w-16 overflow-hidden rounded-full bg-gray-100">
                                            <div class="h-full rounded-full bg-emerald-500" style="width: {{ min(100, $share) }}%"></div>
                                        </div>
                                        <span class="w-10 text-xs tabular-nums text-gray-500">{{ number_format($share, 1) }}%</span>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Detail table --}}
    <div class="rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="flex items-center justify-between border-b border-gray-100 px-5 py-3">
            <div>
                <h2 class="text-sm font-semibold text-gray-900">Vehicles delivered</h2>
                <p class="text-xs text-gray-500">Click a row to open the order. Archived rows show a restore action.</p>
            </div>
            <div class="flex items-center gap-2 text-xs text-gray-500">
                <label class="font-medium">Rows per page</label>
                <select wire:model.live="perPage" class="rounded-md border-gray-300 text-xs">
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                    <option value="250">250</option>
                </select>
            </div>
        </div>

        @if($jobs->isEmpty())
            <div class="px-5 py-12 text-center text-sm text-gray-500">No deliveries match the current filters.</div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="bg-gray-50/70 text-[10px] uppercase tracking-[0.15em] text-gray-500">
                            <th class="px-4 py-2 text-left font-semibold">Delivered</th>
                            <th class="px-4 py-2 text-left font-semibold">Vehicle</th>
                            <th class="px-4 py-2 text-left font-semibold">VIN</th>
                            <th class="px-4 py-2 text-left font-semibold">Executor</th>
                            <th class="px-4 py-2 text-left font-semibold">Pickup</th>
                            <th class="px-4 py-2 text-left font-semibold">Drop Off</th>
                            <th class="px-4 py-2 text-left font-semibold">Carrier</th>
                            <th class="px-4 py-2 text-right font-semibold">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($jobs as $j)
                            @php
                                $execBadge = match ($j->executor_type) {
                                    Job::EXECUTOR_INTERNAL => 'bg-emerald-100 text-emerald-800',
                                    Job::EXECUTOR_THIRD_PARTY => 'bg-purple-100 text-purple-800',
                                    Job::EXECUTOR_SELF_COLLECT => 'bg-amber-100 text-amber-800',
                                    default => 'bg-blue-100 text-blue-800',
                                };
                                $carrier = match ($j->executor_type) {
                                    Job::EXECUTOR_THIRD_PARTY => $j->third_party_courier_name,
                                    Job::EXECUTOR_SELF_COLLECT => $j->self_collect_name,
                                    default => $j->driver?->name,
                                };
                            @endphp
                            <tr class="hover:bg-gray-50/60 {{ $j->archived_at ? 'opacity-70' : '' }}">
                                <td class="px-4 py-2.5 text-[12px] text-gray-700 tabular-nums">
                                    <a href="{{ route('customer.orders.show', $j) }}" class="font-medium text-gray-900 hover:text-blue-600">
                                        {{ optional($j->delivered_at)->format('d M Y') ?? '—' }}
                                    </a>
                                    <div class="text-[10px] text-gray-400">{{ $j->job_number ?? ('JOB-' . $j->id) }}</div>
                                </td>
                                <td class="px-4 py-2.5 text-[12px] text-gray-700">
                                    <div class="font-medium text-gray-900">{{ trim(($j->brand?->name ?? '') . ' ' . ($j->model_name ?? '')) ?: '—' }}</div>
                                    <div class="text-[10px] text-gray-400">{{ $j->vehicleClass?->name ?? '' }}</div>
                                </td>
                                <td class="px-4 py-2.5 text-[11px] font-mono text-gray-600">{{ $j->vin ?? '—' }}</td>
                                <td class="px-4 py-2.5">
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold {{ $execBadge }}">
                                        {{ Job::EXECUTOR_LABELS[$j->executor_type] ?? '—' }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 text-[12px] text-gray-700">
                                    <div>{{ $j->pickupLocation?->company_name ?? '—' }}</div>
                                    <div class="text-[10px] text-gray-400">
                                        {{ trim(($j->pickupLocation?->city ?? '') . ($j->pickupLocation?->province ? ', ' . $j->pickupLocation->province : '')) ?: '—' }}
                                    </div>
                                </td>
                                <td class="px-4 py-2.5 text-[12px] text-gray-700">
                                    <div>{{ $j->deliveryLocation?->company_name ?? '—' }}</div>
                                    <div class="text-[10px] text-gray-400">
                                        {{ trim(($j->deliveryLocation?->city ?? '') . ($j->deliveryLocation?->province ? ', ' . $j->deliveryLocation->province : '')) ?: '—' }}
                                        @if($j->destination_type)
                                            · <span class="text-amber-600 uppercase">{{ str_replace('_', ' ', $j->destination_type) }}</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-4 py-2.5 text-[12px] text-gray-700">{{ $carrier ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-right text-[12px]">
                                    @if($j->archived_at)
                                        <span class="inline-flex items-center gap-1 rounded-full bg-gray-200 px-2 py-0.5 text-[10px] font-semibold text-gray-700">Archived</span>
                                        <button wire:click="unarchiveJob({{ $j->id }})" class="ml-2 text-[10px] font-medium text-blue-600 hover:underline">Restore</button>
                                    @else
                                        <button wire:click="archiveJob({{ $j->id }})" wire:confirm="Archive this order?" class="text-[10px] font-medium text-gray-500 hover:text-gray-900">Archive</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-gray-100 px-5 py-3">{{ $jobs->links() }}</div>
        @endif
    </div>
</div>
