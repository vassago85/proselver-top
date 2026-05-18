<?php

use App\Models\Company;
use App\Models\Job;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/*
 * Executive Reports — vehicles delivered in a date window, broken down
 * by customer with a full vehicle-level table for drill-down + CSV
 * export. Counts a job as "delivered" when its delivered_at falls in
 * the window AND its status is DELIVERED or COMPLETED (anything that
 * has effectively reached the customer).
 */

new #[Layout('components.layouts.app')] class extends Component {
    #[Url] public string $dateFrom = '';
    #[Url] public string $dateTo = '';
    #[Url] public ?int $companyId = null;
    #[Url] public ?int $brandId = null;
    #[Url] public ?int $vehicleClassId = null;
    #[Url] public int $perPage = 50;

    public function mount(): void
    {
        if (!$this->dateFrom) { $this->dateFrom = now()->startOfMonth()->toDateString(); }
        if (!$this->dateTo)   { $this->dateTo   = now()->toDateString(); }
    }

    public function resetFilters(): void
    {
        $this->reset(['companyId', 'brandId', 'vehicleClassId']);
        $this->dateFrom = now()->startOfMonth()->toDateString();
        $this->dateTo   = now()->toDateString();
    }

    /*
     * Quick date range presets. Pass any of: today, this_week, last_7,
     * this_month, last_month, last_30, this_quarter, this_year, ytd.
     */
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

    /*
     * Streamed CSV download of the same dataset shown in the table. Keeps
     * memory flat for very large exports because rows are written as the
     * query streams. Filename encodes the active filters so the download
     * is self-describing.
     */
    public function exportCsv(): StreamedResponse
    {
        $from = Carbon::parse($this->dateFrom)->startOfDay();
        $to   = Carbon::parse($this->dateTo)->endOfDay();

        $filename = sprintf(
            'deliveries_%s_to_%s.csv',
            $from->toDateString(),
            $to->toDateString(),
        );

        $query = $this->deliveredJobsQuery($from, $to);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'Delivered Date', 'Customer', 'Job Number', 'Brand', 'Model',
                'VIN', 'Vehicle Class', 'Pickup (Collection)', 'Pickup City',
                'Pickup Province', 'Delivery (Drop Off)', 'Delivery City',
                'Delivery Province', 'Driver',
            ]);

            $query->chunk(500, function ($jobs) use ($out) {
                foreach ($jobs as $j) {
                    fputcsv($out, [
                        optional($j->delivered_at)->format('Y-m-d H:i'),
                        $j->company?->name ?? '',
                        $j->job_number ?? ('JOB-' . $j->id),
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
                        $j->driver?->name ?? '',
                    ]);
                }
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    /*
     * Base query for "delivered in the window". Anchors on delivered_at
     * which is set by Job::transitionTo() the moment a delivery is
     * marked. We accept either DELIVERED or COMPLETED status so jobs
     * that have moved on to invoicing still count toward the period.
     */
    protected function deliveredJobsQuery(Carbon $from, Carbon $to)
    {
        $q = Job::query()
            ->whereIn('status', [Job::STATUS_DELIVERED, Job::STATUS_COMPLETED])
            ->whereNotNull('delivered_at')
            ->whereBetween('delivered_at', [$from, $to])
            ->with([
                'company:id,name',
                'brand:id,name',
                'vehicleClass:id,name',
                'pickupLocation:id,company_name,city,province',
                'deliveryLocation:id,company_name,city,province',
                'driver:id,name',
            ])
            ->orderByDesc('delivered_at');

        if ($this->companyId)        { $q->where('company_id', $this->companyId); }
        if ($this->brandId)          { $q->where('brand_id', $this->brandId); }
        if ($this->vehicleClassId)   { $q->where('vehicle_class_id', $this->vehicleClassId); }

        return $q;
    }

    public function with(): array
    {
        $from = Carbon::parse($this->dateFrom)->startOfDay();
        $to   = Carbon::parse($this->dateTo)->endOfDay();

        $base = $this->deliveredJobsQuery($from, $to);

        // KPI totals — cloned so pagination/orderBy doesn't trip them.
        $total = (clone $base)->count();
        $uniqueCustomers = (clone $base)->distinct('company_id')->count('company_id');
        $uniqueModels    = (clone $base)
            ->whereNotNull('model_name')
            ->distinct('model_name')
            ->count('model_name');

        // Per-customer breakdown ordered by volume. Built from a fresh
        // query (not a clone of $base) so we don't drag along the
        // base's orderByDesc('delivered_at') or eager-loaded relations,
        // either of which break a GROUP BY company_id on Postgres
        // (it rejects ORDER BY on a non-aggregated column). Capped to
        // the top 10 customers for glanceability.
        $customerBreakdown = Job::query()
            ->whereIn('status', [Job::STATUS_DELIVERED, Job::STATUS_COMPLETED])
            ->whereNotNull('delivered_at')
            ->whereBetween('delivered_at', [$from, $to])
            ->when($this->companyId,      fn ($q) => $q->where('company_id', $this->companyId))
            ->when($this->brandId,        fn ($q) => $q->where('brand_id', $this->brandId))
            ->when($this->vehicleClassId, fn ($q) => $q->where('vehicle_class_id', $this->vehicleClassId))
            ->select('company_id', DB::raw('count(*) as deliveries'))
            ->groupBy('company_id')
            ->orderByDesc('deliveries')
            ->with('company:id,name')
            ->limit(10)
            ->get();

        $jobs = $base->paginate($this->perPage);

        // Filter dropdown options — only show customers/brands/classes
        // that actually have deliveries in the (unfiltered) date range
        // so the dropdowns stay relevant for the window the operator is
        // looking at.
        $companyOptions = Company::query()
            ->whereIn('id', Job::query()
                ->whereIn('status', [Job::STATUS_DELIVERED, Job::STATUS_COMPLETED])
                ->whereBetween('delivered_at', [$from, $to])
                ->select('company_id'))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($c) => ['value' => $c->id, 'label' => $c->name])
            ->all();

        return [
            'jobs'              => $jobs,
            'total'             => $total,
            'uniqueCustomers'   => $uniqueCustomers,
            'uniqueModels'      => $uniqueModels,
            'customerBreakdown' => $customerBreakdown,
            'companyOptions'    => $companyOptions,
            'brandOptions'      => \App\Models\Brand::query()
                ->orderBy('name')->get(['id', 'name'])
                ->map(fn ($b) => ['value' => $b->id, 'label' => $b->name])->all(),
            'classOptions'      => \App\Models\VehicleClass::query()
                ->orderBy('name')->get(['id', 'name'])
                ->map(fn ($v) => ['value' => $v->id, 'label' => $v->name])->all(),
        ];
    }
};

?>
<div class="space-y-6">
    <x-slot:header>Executive Reports</x-slot:header>

    {{-- Filters --}}
    <div class="rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-5 py-3">
            <div>
                <h2 class="text-sm font-semibold text-gray-900">Delivered vehicles</h2>
                <p class="text-xs text-gray-500">All vehicles that reached their delivery destination in the selected window.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <button wire:click="applyRange('today')"        class="rounded-md border border-gray-200 bg-white px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">Today</button>
                <button wire:click="applyRange('last_7')"       class="rounded-md border border-gray-200 bg-white px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">7d</button>
                <button wire:click="applyRange('this_month')"   class="rounded-md border border-gray-200 bg-white px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">This month</button>
                <button wire:click="applyRange('last_month')"   class="rounded-md border border-gray-200 bg-white px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">Last month</button>
                <button wire:click="applyRange('this_quarter')" class="rounded-md border border-gray-200 bg-white px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">Quarter</button>
                <button wire:click="applyRange('ytd')"          class="rounded-md border border-gray-200 bg-white px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">YTD</button>
                <button wire:click="exportCsv"
                    class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-500">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                    Export CSV
                </button>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-3 px-5 py-4 sm:grid-cols-2 lg:grid-cols-5">
            <div>
                <label class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">From</label>
                <input type="date" wire:model.live="dateFrom" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"/>
            </div>
            <div>
                <label class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">To</label>
                <input type="date" wire:model.live="dateTo" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"/>
            </div>
            <div>
                <label class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">Customer</label>
                <x-searchable-select
                    wire:model.live="companyId"
                    :options="$companyOptions"
                    placeholder="All customers"
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
                <label class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">Vehicle Class</label>
                <x-searchable-select
                    wire:model.live="vehicleClassId"
                    :options="$classOptions"
                    placeholder="All classes"
                />
            </div>
        </div>

        @if($companyId || $brandId || $vehicleClassId)
            <div class="border-t border-gray-100 px-5 py-2 text-right">
                <button wire:click="resetFilters" class="text-xs font-medium text-gray-500 hover:text-gray-800">
                    Clear filters
                </button>
            </div>
        @endif
    </div>

    {{-- KPI cards --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4">
            <p class="text-[10px] font-semibold uppercase tracking-wider text-emerald-700">Vehicles Delivered</p>
            <p class="mt-1 text-3xl font-semibold text-emerald-900 tabular-nums">{{ number_format($total) }}</p>
            <p class="mt-0.5 text-xs text-emerald-700/80">{{ \Carbon\Carbon::parse($dateFrom)->format('d M Y') }} → {{ \Carbon\Carbon::parse($dateTo)->format('d M Y') }}</p>
        </div>
        <div class="rounded-xl border border-blue-200 bg-blue-50 p-4">
            <p class="text-[10px] font-semibold uppercase tracking-wider text-blue-700">Unique Customers</p>
            <p class="mt-1 text-3xl font-semibold text-blue-900 tabular-nums">{{ number_format($uniqueCustomers) }}</p>
            <p class="mt-0.5 text-xs text-blue-700/80">Distinct accounts billed against in this window</p>
        </div>
        <div class="rounded-xl border border-violet-200 bg-violet-50 p-4">
            <p class="text-[10px] font-semibold uppercase tracking-wider text-violet-700">Distinct Models</p>
            <p class="mt-1 text-3xl font-semibold text-violet-900 tabular-nums">{{ number_format($uniqueModels) }}</p>
            <p class="mt-0.5 text-xs text-violet-700/80">Different vehicle models moved</p>
        </div>
    </div>

    {{-- Top customers breakdown --}}
    @if($customerBreakdown->isNotEmpty())
        <div class="rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-100 px-5 py-3">
                <h2 class="text-sm font-semibold text-gray-900">Top customers by deliveries</h2>
                <p class="text-xs text-gray-500">Click a row to filter the table below.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="bg-gray-50/70 text-[10px] uppercase tracking-[0.15em] text-gray-500">
                            <th class="px-5 py-2 text-left font-semibold">Customer</th>
                            <th class="px-5 py-2 text-right font-semibold">Deliveries</th>
                            <th class="px-5 py-2 text-right font-semibold">Share</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($customerBreakdown as $row)
                            @php
                                $share = $total > 0 ? ($row->deliveries / $total) * 100 : 0;
                            @endphp
                            <tr wire:click="$set('companyId', {{ $row->company_id }})" class="cursor-pointer transition-colors hover:bg-gray-50/60">
                                <td class="px-5 py-2.5 text-sm text-gray-900">{{ $row->company?->name ?? '—' }}</td>
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

    {{-- Vehicle-level delivery table --}}
    <div class="rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="flex items-center justify-between border-b border-gray-100 px-5 py-3">
            <div>
                <h2 class="text-sm font-semibold text-gray-900">Vehicles delivered</h2>
                <p class="text-xs text-gray-500">Every vehicle handed over to the customer in the selected window.</p>
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
            <div class="px-5 py-12 text-center text-sm text-gray-500">
                No deliveries match the current filters.
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="bg-gray-50/70 text-[10px] uppercase tracking-[0.15em] text-gray-500">
                            <th class="px-5 py-2 text-left font-semibold">Delivered</th>
                            <th class="px-5 py-2 text-left font-semibold">Customer</th>
                            <th class="px-5 py-2 text-left font-semibold">Make</th>
                            <th class="px-5 py-2 text-left font-semibold">Model</th>
                            <th class="px-5 py-2 text-left font-semibold">VIN</th>
                            <th class="px-5 py-2 text-left font-semibold">Class</th>
                            <th class="px-5 py-2 text-left font-semibold">Collection</th>
                            <th class="px-5 py-2 text-left font-semibold">Drop Off</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($jobs as $j)
                            <tr class="transition-colors hover:bg-gray-50/60">
                                <td class="px-5 py-2.5 whitespace-nowrap text-[12px] text-gray-700 tabular-nums">
                                    <a href="{{ route('admin.orders.show', $j) }}" class="font-medium text-gray-900 hover:text-blue-600">
                                        {{ optional($j->delivered_at)->format('d M Y') ?? '—' }}
                                    </a>
                                    <div class="text-[10px] text-gray-400">{{ $j->job_number ?? ('JOB-' . $j->id) }}</div>
                                </td>
                                <td class="px-5 py-2.5 text-[12px] text-gray-700">{{ $j->company?->name ?? '—' }}</td>
                                <td class="px-5 py-2.5 text-[12px] text-gray-700">{{ $j->brand?->name ?? '—' }}</td>
                                <td class="px-5 py-2.5 text-[12px] text-gray-700">{{ $j->model_name ?? '—' }}</td>
                                <td class="px-5 py-2.5 text-[11px] font-mono text-gray-600">{{ $j->vin ?? '—' }}</td>
                                <td class="px-5 py-2.5 text-[12px] text-gray-700">{{ $j->vehicleClass?->name ?? '—' }}</td>
                                <td class="px-5 py-2.5 text-[12px] text-gray-700">
                                    <div class="font-medium text-gray-900">{{ $j->pickupLocation?->company_name ?? '—' }}</div>
                                    <div class="text-[10px] text-gray-400">
                                        {{ trim(($j->pickupLocation?->city ?? '') . ($j->pickupLocation?->province ? ', ' . $j->pickupLocation->province : '')) ?: '—' }}
                                    </div>
                                </td>
                                <td class="px-5 py-2.5 text-[12px] text-gray-700">
                                    <div class="font-medium text-gray-900">{{ $j->deliveryLocation?->company_name ?? '—' }}</div>
                                    <div class="text-[10px] text-gray-400">
                                        {{ trim(($j->deliveryLocation?->city ?? '') . ($j->deliveryLocation?->province ? ', ' . $j->deliveryLocation->province : '')) ?: '—' }}
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-gray-100 px-5 py-3">
                {{ $jobs->links() }}
            </div>
        @endif
    </div>
</div>
