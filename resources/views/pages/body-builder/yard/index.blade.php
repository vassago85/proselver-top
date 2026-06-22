<?php

use App\Models\DealerStock;
use App\Models\Job;
use App\Models\Location;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/*
 * BB yard ledger -- the tablet view a workshop foreman uses to see
 * what's on the premises right now.
 *
 * Data sources:
 *
 *   - Jobs delivered to one of our locations (status=delivered) -- the
 *     existing "on site" bucket from /body-builder/jobs.
 *   - Dealer stock units whose current_location_id is one of our
 *     locations -- catches vehicles that arrived without a transport
 *     job (drove themselves in).
 *
 * We merge both into a single "what's on the yard" list keyed by VIN,
 * with the BB internal job number front and centre.
 */
new #[Layout('components.layouts.body-builder')] class extends Component
{
    #[Url]
    public string $q = '';

    #[Url]
    public ?int $dealerId = null;

    public function with(): array
    {
        $user    = auth()->user();
        $company = $user?->company();

        if (!$company || !$company->isBodyBuilder()) {
            return ['vehicles' => collect(), 'dealerOptions' => []];
        }

        $myLocationIds = Location::where('company_id', $company->id)->pluck('id');

        // 1. Jobs delivered to our locations and not yet collected.
        $jobs = Job::query()
            ->whereIn('delivery_location_id', $myLocationIds)
            ->where('status', Job::STATUS_DELIVERED)
            ->with(['company:id,name', 'brand:id,name', 'deliveryLocation:id,company_name,city'])
            ->orderByDesc('delivered_at')
            ->get();

        // 2. Dealer stock currently parked at our locations.  Includes
        // OEM-direct arrivals (dealer_company_id IS NULL) -- those are
        // surfaced with the "Unassigned" badge below.
        $stocks = DealerStock::query()
            ->whereIn('current_location_id', $myLocationIds)
            ->where('current_location_type', DealerStock::LOCATION_BODY_BUILDER)
            ->whereNotIn('status', [DealerStock::STATUS_ARCHIVED])
            ->with(['dealerCompany:id,name', 'oemCompany:id,name', 'brand:id,name', 'currentLocation:id,company_name'])
            ->get();

        // Merge by VIN, preferring stock-level data (richer -- it has
        // the dealer-shared metadata + the BB internal job number).
        $merged = [];
        foreach ($jobs as $j) {
            $vin = strtoupper(trim((string) $j->vin));
            if ($vin === '') continue;
            $merged[$vin] = [
                'vin'          => $vin,
                'job_id'       => $j->id,
                'job_uuid'     => $j->uuid,
                'dealer'       => $j->company?->name ?? '—',
                'dealer_id'    => $j->company_id,
                'brand'        => $j->brand?->name ?? '—',
                'model'        => $j->model_name ?? '',
                'location'     => $j->deliveryLocation?->company_name ?? '',
                'arrived_at'   => $j->delivered_at,
                'bb_job_no'    => null,
                'share'        => null,
                'stock'        => null,
            ];
        }
        foreach ($stocks as $s) {
            $vin = strtoupper(trim((string) $s->vin));
            if ($vin === '') continue;
            $merged[$vin] = array_merge($merged[$vin] ?? [
                'vin'        => $vin,
                'job_id'     => $s->current_job_id,
                'job_uuid'   => optional($s->currentJob)->uuid,
                'arrived_at' => $s->created_at,
            ], [
                'dealer'      => $s->dealerCompany?->name,
                'dealer_id'   => $s->dealer_company_id,
                'unassigned'  => $s->isUnassigned(),
                'oem'         => $s->oemCompany?->name,
                'brand'       => $s->brand?->name ?? '—',
                'model'       => $s->model_name ?? '',
                'location'    => $s->currentLocation?->company_name ?? '',
                'bb_job_no'   => $s->bb_internal_job_number,
                'stock_id'    => $s->id,
                'share'       => $s->bb_share_with_body_builder ? [
                    'salesperson'  => $s->bb_share_salesperson,
                    'end_customer' => $s->bb_share_end_customer,
                    'notes'        => $s->bb_build_notes,
                ] : null,
                'stock'       => $s,
            ]);
        }

        // Filters.
        $needle = trim($this->q);
        $vehicles = collect($merged)
            ->when($needle !== '', function ($c) use ($needle) {
                $n = strtolower($needle);
                return $c->filter(function ($v) use ($n) {
                    return str_contains(strtolower($v['vin']),       $n)
                        || str_contains(strtolower($v['dealer']),    $n)
                        || str_contains(strtolower($v['brand']),     $n)
                        || str_contains(strtolower($v['model']),     $n)
                        || str_contains(strtolower((string) $v['bb_job_no']), $n);
                });
            })
            ->when($this->dealerId, fn ($c) => $c->filter(fn ($v) => $v['dealer_id'] === $this->dealerId))
            ->sortByDesc(fn ($v) => $v['arrived_at']?->timestamp ?? 0)
            ->values();

        $dealerOptions = collect($merged)
            ->map(fn ($v) => ['id' => $v['dealer_id'], 'name' => $v['dealer']])
            ->unique('id')
            ->filter(fn ($d) => $d['id'])
            ->sortBy('name')
            ->values();

        return [
            'vehicles'      => $vehicles,
            'dealerOptions' => $dealerOptions,
        ];
    }
}; ?>

<div class="space-y-3">
    <div class="flex items-baseline justify-between">
        <h1 class="text-lg font-semibold text-slate-900">On the yard</h1>
        <span class="text-xs text-slate-500">{{ $vehicles->count() }} vehicle{{ $vehicles->count() === 1 ? '' : 's' }}</span>
    </div>

    {{-- Search + filter row. The bar stays sticky on tall yard lists
         so the foreman can re-filter without scrolling back up. --}}
    <div class="sticky top-14 -mx-4 px-4 pb-3 pt-1 bg-slate-100/80 backdrop-blur z-10 border-b border-slate-200">
        <div class="flex gap-2">
            <input type="search" wire:model.live.debounce.300ms="q"
                placeholder="VIN, dealer, BB job #..."
                class="flex-1 rounded-lg border-slate-300 text-sm h-11">
            @if($dealerOptions->isNotEmpty())
                <select wire:model.live="dealerId" class="rounded-lg border-slate-300 text-sm h-11 max-w-[10rem]">
                    <option value="">All dealers</option>
                    @foreach($dealerOptions as $d)
                        <option value="{{ $d['id'] }}">{{ $d['name'] }}</option>
                    @endforeach
                </select>
            @endif
        </div>
    </div>

    @if($vehicles->isEmpty())
        <div class="rounded-xl border-2 border-dashed border-slate-300 bg-white px-6 py-12 text-center">
            <p class="text-sm text-slate-600">No vehicles on the yard right now.</p>
            <a href="{{ route('body-builder.yard.checkin') }}" class="mt-3 inline-flex items-center gap-1 text-sm font-semibold text-blue-600">
                Check one in →
            </a>
        </div>
    @else
        <div class="space-y-2">
            @foreach($vehicles as $v)
                @php
                    $unassigned = $v['unassigned'] ?? false;
                    if ($unassigned && !empty($v['stock_id'])) {
                        $href = route('body-builder.yard.stock', ['stock' => $v['stock_id']]);
                    } elseif (!empty($v['job_id'])) {
                        $href = route('body-builder.yard.show', ['job' => $v['job_id']]);
                    } else {
                        $href = '#';
                    }
                @endphp
                <a href="{{ $href }}"
                   class="block rounded-xl border {{ $unassigned ? 'border-amber-300 bg-amber-50' : 'border-slate-200 bg-white' }} p-4 hover:border-blue-300 active:bg-slate-50">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2 text-xs text-slate-500">
                                <span class="font-mono">{{ $v['vin'] }}</span>
                                @if($v['bb_job_no'])
                                    <span class="inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-[10px] font-semibold text-blue-800">
                                        BB# {{ $v['bb_job_no'] }}
                                    </span>
                                @endif
                                @if($unassigned)
                                    <span class="inline-flex items-center rounded-full bg-amber-200 px-2 py-0.5 text-[10px] font-semibold text-amber-900">
                                        ⚠ Unassigned
                                    </span>
                                @endif
                            </div>
                            <div class="mt-1 text-base font-semibold text-slate-900 truncate">
                                {{ $v['brand'] }} {{ $v['model'] }}
                            </div>
                            <div class="mt-0.5 text-sm text-slate-600 truncate">
                                @if($unassigned)
                                    @if(!empty($v['oem']))From {{ $v['oem'] }} -- no dealer yet @else No dealer assigned yet @endif
                                @else
                                    {{ $v['dealer'] ?: '—' }}
                                @endif
                            </div>
                            @if($v['share'])
                                <div class="mt-1 text-xs text-emerald-700">
                                    @if($v['share']['end_customer']) → {{ $v['share']['end_customer'] }} @endif
                                </div>
                            @endif
                        </div>
                        <div class="text-right text-[11px] text-slate-500 whitespace-nowrap">
                            @if($v['arrived_at'])
                                {{ $v['arrived_at']->diffForHumans() }}
                            @endif
                        </div>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</div>
