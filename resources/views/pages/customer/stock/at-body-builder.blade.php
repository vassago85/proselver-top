<?php

use App\Models\Company;
use App\Models\Job;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/*
 * "At body builder" stock view — vehicles the dealer has delivered to
 * a body builder but hasn't booked a return movement for. Stitching is
 * deliberately one-VIN-one-trip: a vehicle is "still at body builder X"
 * if its most recent delivered job for that VIN had destination_type =
 * body_builder AND there is no newer movement (delivered or in-flight)
 * that picks it up from there. Archived rows are excluded — once a
 * vehicle has come back and the return is archived, the stock entry
 * disappears.
 *
 * One-click "Book return / next move" populates the create-order form
 * with pickup_location_id = previous delivery_location_id + VIN /
 * brand / model carried over (handled by orders/create.mount).
 */
new #[Layout('components.layouts.app')]
class extends Component
{
    public ?Company $company = null;

    public string $search = '';
    public ?int $bodyBuilderId = null;

    public function mount(): void
    {
        $this->company = auth()->user()->companies()->first();
        abort_unless($this->company, 403);
    }

    public function with(): array
    {
        // Step 1: for the dealer's company, find every VIN whose most
        // recent COMPLETED-or-DELIVERED transport job is a body-builder
        // delivery AND is still active (not archived).
        $candidates = Job::query()
            ->where('company_id', $this->company->id)
            ->where('destination_type', Job::DESTINATION_BODY_BUILDER)
            ->whereIn('status', [Job::STATUS_DELIVERED, Job::STATUS_COMPLETED])
            ->whereNull('archived_at')
            ->whereNotNull('vin')
            ->with(['deliveryLocation', 'brand:id,name', 'pickupLocation'])
            ->orderByDesc('delivered_at')
            ->get();

        // Step 2: for each VIN keep ONLY the latest job, then drop any
        // VIN that has a more recent active job that uses this body-
        // builder location as pickup (i.e. the return has already been
        // booked or is in flight).
        $latestPerVin = $candidates->unique('vin');

        $returnedVins = Job::query()
            ->where('company_id', $this->company->id)
            ->whereIn('vin', $latestPerVin->pluck('vin')->all())
            ->whereNull('archived_at')
            ->whereNotIn('status', [Job::STATUS_CANCELLED, Job::STATUS_DELIVERED, Job::STATUS_COMPLETED])
            ->pluck('vin')
            ->unique()
            ->values()
            ->all();

        $rows = $latestPerVin
            ->reject(fn ($j) => in_array($j->vin, $returnedVins, true))
            ->filter(function ($j) {
                if ($this->bodyBuilderId && $j->delivery_location_id !== $this->bodyBuilderId) {
                    return false;
                }
                if ($this->search === '') {
                    return true;
                }
                $needle = strtolower($this->search);
                return str_contains(strtolower((string) $j->vin), $needle)
                    || str_contains(strtolower((string) ($j->model_name ?? '')), $needle)
                    || str_contains(strtolower((string) ($j->brand?->name ?? '')), $needle)
                    || str_contains(strtolower((string) ($j->deliveryLocation?->company_name ?? '')), $needle);
            })
            ->values();

        // Distinct body-builder locations to power the filter dropdown.
        $bodyBuilderOptions = $latestPerVin
            ->pluck('deliveryLocation')
            ->filter()
            ->unique('id')
            ->map(fn ($loc) => [
                'value' => (string) $loc->id,
                'label' => $loc->company_name . ($loc->city ? " — {$loc->city}" : ''),
            ])
            ->values()
            ->all();

        return [
            'rows' => $rows,
            'totalAtBodyBuilder' => $rows->count(),
            'bodyBuilderOptions' => $bodyBuilderOptions,
        ];
    }
};

?>

<div>
    <x-slot:header>Stock at Body Builders</x-slot:header>

    <div class="mb-4 rounded-xl border border-gray-200 bg-white p-4 grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div>
            <p class="text-[10px] font-semibold uppercase tracking-wide text-gray-500">At body builder</p>
            <p class="mt-1 text-2xl font-semibold text-gray-900 tabular-nums">{{ $totalAtBodyBuilder }}</p>
            <p class="mt-0.5 text-xs text-gray-500">Vehicles delivered to a body builder, awaiting return.</p>
        </div>
        <div class="sm:col-span-2 flex flex-col sm:flex-row items-stretch sm:items-end gap-2">
            <div class="flex-1">
                <label class="block text-xs font-medium text-gray-700 mb-1">Body builder</label>
                <x-searchable-select
                    wire:model.live="bodyBuilderId"
                    :options="$bodyBuilderOptions"
                    placeholder="All body builders"
                    search-placeholder="Search body builders…"
                />
            </div>
            <div class="flex-1">
                <label class="block text-xs font-medium text-gray-700 mb-1">Search</label>
                <input wire:model.live.debounce.300ms="search" type="text"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500"
                    placeholder="VIN, model, brand, location…">
            </div>
        </div>
    </div>

    <div class="rounded-xl bg-white shadow-sm border border-gray-200 overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Vehicle</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">VIN</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Body Builder</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Delivered</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Origin</th>
                    <th class="px-4 py-3 text-right text-xs font-medium uppercase text-gray-500">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 bg-white">
                @forelse($rows as $row)
                    <tr>
                        <td class="px-4 py-3 text-sm">
                            <div class="font-medium text-gray-900">{{ $row->brand?->name }} {{ $row->model_name }}</div>
                            @if($row->registration)
                                <div class="text-xs text-gray-500">{{ $row->registration }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm font-mono text-gray-700">{{ $row->vin }}</td>
                        <td class="px-4 py-3 text-sm text-gray-700">
                            <div>{{ $row->deliveryLocation?->company_name ?? '—' }}</div>
                            @if($row->deliveryLocation?->city)
                                <div class="text-xs text-gray-500">{{ $row->deliveryLocation->city }}{{ $row->deliveryLocation->province ? ', ' . $row->deliveryLocation->province : '' }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-700">
                            {{ $row->delivered_at?->format('d M Y') ?? $row->updated_at->format('d M Y') }}
                            <div class="text-xs text-gray-400">
                                {{ ($row->delivered_at ?? $row->updated_at)->diffForHumans() }}
                            </div>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-700">
                            {{ $row->pickupLocation?->company_name ?? '—' }}
                        </td>
                        <td class="px-4 py-3 text-sm text-right">
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
                            <a href="{{ route('customer.orders.show', $row) }}" class="ml-2 text-xs font-medium text-gray-600 hover:text-gray-900">View order</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-12 text-center text-sm text-gray-500">
                            No vehicles at body builders right now.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
