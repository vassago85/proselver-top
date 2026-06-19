<?php

use App\Models\Company;
use App\Models\Job;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/*
 * "Stock in transit" — every vehicle the dealer still has on their
 * books but isn't sitting at their own dealership right now. Three
 * buckets, all unified into one table:
 *
 *   1. At body builder   — delivered, destination_type = body_builder
 *                          (the original "at body builder" view).
 *   2. At yard / holding — delivered, destination_type = yard / other
 *                          (vehicle parked off-site, not finalised).
 *   3. In transit        — any active job not yet delivered (status
 *                          NEW / READY_FOR_COLLECTION / COLLECTED /
 *                          IN_TRANSIT / DRIVER_ASSIGNED etc.). The
 *                          driver hasn't dropped it yet.
 *
 * A vehicle is "no longer in stock" when its latest delivered job is
 * to a DEALER destination, or when the row is archived (the dealer
 * has explicitly hidden it after final delivery). Both cases drop
 * out of this view.
 *
 * One-click "Book return / next move" populates the create-order
 * form with pickup = previous delivery location + VIN/brand/model
 * carried over (orders/create.mount handles the prefill). It only
 * surfaces for parked-vehicle rows; in-transit rows show "Track" /
 * "View" actions instead since a return can't be planned yet.
 */
new #[Layout('components.layouts.app')]
class extends Component
{
    public ?Company $company = null;

    public string $search = '';
    public ?int $locationId = null;
    public string $bucket = 'all'; // all | body_builder | other_storage | in_transit

    // Dealership filter for franchise CEOs whose visibleCompanyIds
    // spans multiple sibling dealerships.  '' = "all my dealerships".
    public string $dealershipFilter = '';

    public function mount(): void
    {
        $this->company = auth()->user()->company();
        abort_unless($this->company, 403);
    }

    public function setBucket(string $bucket): void
    {
        $this->bucket = in_array($bucket, ['all', 'body_builder', 'other_storage', 'in_transit'], true)
            ? $bucket
            : 'all';
        $this->locationId = null;
    }

    public function with(): array
    {
        $user = auth()->user();
        $visibleCompanyIds = $user->visibleCompanyIds();

        // Group-aware scope: a CEO sees stock across every dealership
        // they're pivoted into via company_users.  When dealershipFilter
        // is set, narrow back down to that single dealership.
        $effectiveCompanyIds = $this->dealershipFilter !== ''
            ? array_values(array_intersect($visibleCompanyIds, [(int) $this->dealershipFilter]))
            : $visibleCompanyIds;

        // -----------------------------------------------------------
        // Step 1: delivered-but-parked rows (body builder + other
        // storage facility). DESTINATION_YARD + the legacy
        // DESTINATION_OTHER both back the "Other Storage Facility"
        // bucket in the UI. Round-trip rows are NEVER parked — once
        // delivered, the vehicle is back at pickup.
        // One row per VIN, take the latest delivered job and drop
        // any VIN that already has a newer movement booked (return is
        // in flight or completed).
        // -----------------------------------------------------------
        $parkedDestinations = [
            Job::DESTINATION_BODY_BUILDER,
            Job::DESTINATION_YARD,
            Job::DESTINATION_OTHER,
        ];

        $parkedCandidates = Job::query()
            ->whereIn('company_id', $effectiveCompanyIds)
            ->whereIn('destination_type', $parkedDestinations)
            ->whereIn('status', [Job::STATUS_DELIVERED, Job::STATUS_COMPLETED])
            ->whereNull('archived_at')
            ->whereNotNull('vin')
            ->with(['deliveryLocation', 'pickupLocation', 'brand:id,name', 'company:id,name'])
            ->orderByDesc('delivered_at')
            ->get();

        $latestParkedPerVin = $parkedCandidates->unique('vin');

        $vinsWithNewerMovement = Job::query()
            ->whereIn('company_id', $effectiveCompanyIds)
            ->whereIn('vin', $latestParkedPerVin->pluck('vin')->all())
            ->whereNull('archived_at')
            ->whereNotIn('status', [Job::STATUS_CANCELLED, Job::STATUS_DELIVERED, Job::STATUS_COMPLETED])
            ->pluck('vin')
            ->unique()
            ->values()
            ->all();

        $parkedRows = $latestParkedPerVin
            ->reject(fn ($j) => in_array($j->vin, $vinsWithNewerMovement, true))
            ->map(function (Job $j) {
                $j->bucket_key = $j->destination_type === Job::DESTINATION_BODY_BUILDER
                    ? 'body_builder'
                    : 'other_storage';
                $j->bucket_label = $j->destination_type === Job::DESTINATION_BODY_BUILDER
                    ? 'At body builder / fitment'
                    : 'At other storage facility';
                return $j;
            })
            ->values();

        // -----------------------------------------------------------
        // Step 2: in-transit rows — any active job that hasn't been
        // delivered yet. Latest job per VIN, ignore archived.
        // -----------------------------------------------------------
        $activeStatuses = [
            Job::STATUS_RECEIVED,
            Job::STATUS_READY_FOR_COLLECTION,
            Job::STATUS_DRIVER_ASSIGNED,
            Job::STATUS_COLLECTED,
            Job::STATUS_IN_TRANSIT,
        ];

        $inTransitCandidates = Job::query()
            ->whereIn('company_id', $effectiveCompanyIds)
            ->whereIn('status', $activeStatuses)
            ->whereNull('archived_at')
            ->whereNotNull('vin')
            ->with(['deliveryLocation', 'pickupLocation', 'brand:id,name', 'driver:id,name', 'company:id,name'])
            ->orderByDesc('scheduled_date')
            ->orderByDesc('id')
            ->get()
            ->unique('vin')
            ->map(function (Job $j) {
                $j->bucket_key = 'in_transit';
                $isRoundTrip = $j->destination_type === Job::DESTINATION_ROUND_TRIP
                    || $j->is_round_trip;
                $j->bucket_label = match (true) {
                    $isRoundTrip => 'Round trip in progress',
                    $j->status === Job::STATUS_IN_TRANSIT => 'In transit',
                    $j->status === Job::STATUS_COLLECTED => 'Collected',
                    $j->status === Job::STATUS_DRIVER_ASSIGNED => 'Driver assigned',
                    $j->status === Job::STATUS_READY_FOR_COLLECTION => 'Ready for collection',
                    default => 'Awaiting collection',
                };
                return $j;
            })
            ->values();

        // -----------------------------------------------------------
        // Step 3: merge + apply UI filters (bucket, location, search).
        // -----------------------------------------------------------
        $all = $parkedRows->concat($inTransitCandidates);

        $bucketCounts = [
            'all' => $all->count(),
            'body_builder' => $parkedRows->where('bucket_key', 'body_builder')->count(),
            'other_storage' => $parkedRows->where('bucket_key', 'other_storage')->count(),
            'in_transit' => $inTransitCandidates->count(),
        ];

        $rows = $all
            ->when($this->bucket !== 'all', fn ($c) => $c->where('bucket_key', $this->bucket)->values())
            ->filter(function ($j) {
                if ($this->locationId && $j->delivery_location_id !== $this->locationId) {
                    return false;
                }
                if ($this->search === '') {
                    return true;
                }
                $needle = strtolower($this->search);
                return str_contains(strtolower((string) $j->vin), $needle)
                    || str_contains(strtolower((string) ($j->model_name ?? '')), $needle)
                    || str_contains(strtolower((string) ($j->registration ?? '')), $needle)
                    || str_contains(strtolower((string) ($j->brand?->name ?? '')), $needle)
                    || str_contains(strtolower((string) ($j->deliveryLocation?->company_name ?? '')), $needle);
            })
            ->values();

        // Distinct delivery locations across the current view, for
        // the secondary filter dropdown.
        $locationOptions = $all
            ->pluck('deliveryLocation')
            ->filter()
            ->unique('id')
            ->map(fn ($loc) => [
                'value' => (string) $loc->id,
                'label' => $loc->company_name . ($loc->city ? " — {$loc->city}" : ''),
            ])
            ->values()
            ->all();

        $visibleCompanies = count($visibleCompanyIds) > 1
            ? Company::whereIn('id', $visibleCompanyIds)->orderBy('name')->get(['id', 'name'])
            : collect();

        return [
            'rows' => $rows,
            'bucketCounts' => $bucketCounts,
            'locationOptions' => $locationOptions,
            'visibleCompanies' => $visibleCompanies,
            'isMultiCompany' => $visibleCompanies->isNotEmpty(),
        ];
    }
};

?>

<div>
    <x-slot:header>Stock In Transit</x-slot:header>

    <div class="mb-2 text-sm text-gray-600">
        Vehicles you still own but aren't sitting at your dealership right now &mdash; at a body builder, parked in a yard, or actively on the road.
        Anything delivered to a final dealer destination drops out of this view automatically.
    </div>

    {{-- Dealership chip strip (group principals only) --}}
    <x-dealership-chip-strip :companies="$visibleCompanies" :selected="$dealershipFilter" wire-model="dealershipFilter" />

    {{-- Bucket tabs --}}
    <div class="mb-4 flex flex-wrap gap-2">
        @php
            $tabs = [
                'all' => ['label' => 'All', 'colour' => 'gray'],
                'body_builder' => ['label' => 'At body builder / fitment', 'colour' => 'amber'],
                'other_storage' => ['label' => 'At other storage facility', 'colour' => 'sky'],
                'in_transit' => ['label' => 'In transit', 'colour' => 'indigo'],
            ];
        @endphp
        @foreach($tabs as $key => $cfg)
            @php
                $active = $bucket === $key;
                $base = 'inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-sm font-medium transition-colors';
                $cls = $active
                    ? match ($cfg['colour']) {
                        'amber' => 'bg-amber-50 border-amber-300 text-amber-800',
                        'sky'   => 'bg-sky-50 border-sky-300 text-sky-800',
                        'indigo'=> 'bg-indigo-50 border-indigo-300 text-indigo-800',
                        default => 'bg-gray-100 border-gray-300 text-gray-900',
                    }
                    : 'bg-white border-gray-200 text-gray-700 hover:bg-gray-50';
            @endphp
            <button type="button" wire:click="setBucket('{{ $key }}')" class="{{ $base }} {{ $cls }}">
                {{ $cfg['label'] }}
                <span class="rounded-full bg-white/70 px-1.5 text-xs font-semibold text-gray-700">{{ $bucketCounts[$key] ?? 0 }}</span>
            </button>
        @endforeach
    </div>

    {{-- Filters --}}
    <div class="mb-4 rounded-xl border border-gray-200 bg-white p-4 grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">Search</label>
            <input wire:model.live.debounce.300ms="search" type="text"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500"
                placeholder="VIN, model, brand, location…">
        </div>
        <div class="sm:col-span-2">
            <label class="block text-xs font-medium text-gray-700 mb-1">Destination / current location</label>
            <x-searchable-select
                wire:model.live="locationId"
                :options="$locationOptions"
                placeholder="All locations"
                search-placeholder="Search locations…"
            />
        </div>
    </div>

    {{-- Table --}}
    <div class="rounded-xl bg-white shadow-sm border border-gray-200 overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Vehicle</th>
                    @if($isMultiCompany)
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Dealership</th>
                    @endif
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">VIN</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Status</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Where</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Last update</th>
                    <th class="px-4 py-3 text-right text-xs font-medium uppercase text-gray-500">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 bg-white">
                @forelse($rows as $row)
                    @php
                        $isParked = in_array($row->bucket_key, ['body_builder', 'other_storage'], true);
                        $badgeCls = match ($row->bucket_key) {
                            'body_builder'  => 'bg-amber-50 text-amber-800 border-amber-200',
                            'other_storage' => 'bg-sky-50 text-sky-800 border-sky-200',
                            default         => 'bg-indigo-50 text-indigo-800 border-indigo-200',
                        };
                    @endphp
                    <tr>
                        <td class="px-4 py-3 text-sm">
                            <div class="font-medium text-gray-900">{{ $row->brand?->name }} {{ $row->model_name }}</div>
                            @if($row->registration)
                                <div class="text-xs text-gray-500">{{ $row->registration }}</div>
                            @endif
                        </td>
                        @if($isMultiCompany)
                            <td class="px-4 py-3 text-sm text-gray-700">{{ $row->company?->name ?? '—' }}</td>
                        @endif
                        <td class="px-4 py-3 text-sm font-mono text-gray-700">{{ $row->vin }}</td>
                        <td class="px-4 py-3 text-sm">
                            <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-semibold {{ $badgeCls }}">
                                {{ $row->bucket_label }}
                            </span>
                            @if(! $isParked && $row->driver)
                                <div class="mt-1 text-[11px] text-gray-500">Driver: {{ $row->driver->name }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-700">
                            <div>{{ $row->deliveryLocation?->company_name ?? '—' }}</div>
                            @if($row->deliveryLocation?->city)
                                <div class="text-xs text-gray-500">{{ $row->deliveryLocation->city }}{{ $row->deliveryLocation->province ? ', ' . $row->deliveryLocation->province : '' }}</div>
                            @endif
                            @if(! $isParked && $row->pickupLocation)
                                <div class="mt-1 text-[11px] text-gray-500">From: {{ $row->pickupLocation->company_name }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-700">
                            @php($ts = $isParked ? ($row->delivered_at ?? $row->updated_at) : $row->updated_at)
                            {{ $ts->format('d M Y') }}
                            <div class="text-xs text-gray-400">{{ $ts->diffForHumans() }}</div>
                        </td>
                        <td class="px-4 py-3 text-sm text-right whitespace-nowrap">
                            @if($isParked)
                                <a href="{{ route('customer.orders.create', [
                                    'pickup_location_id' => $row->delivery_location_id,
                                    'vin' => $row->vin,
                                    'brand_id' => $row->brand_id,
                                    'model_name' => $row->model_name,
                                    'vehicle_class_id' => $row->vehicle_class_id,
                                ]) }}"
                                   class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-500">
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                                    Book Return / Next Move
                                </a>
                            @endif
                            <a href="{{ route('customer.orders.show', $row) }}" class="ml-2 text-xs font-medium text-gray-600 hover:text-gray-900">View order</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $isMultiCompany ? 7 : 6 }}" class="px-4 py-12 text-center text-sm text-gray-500">
                            Nothing in this view right now &mdash; all your vehicles are either at home or already delivered to their final destination.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
