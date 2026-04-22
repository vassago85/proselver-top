<?php

use App\Models\Brand;
use App\Models\Location;
use App\Models\VehicleClass;
use App\Models\VehicleModel;
use App\Services\BookingService;
use App\Services\GeocodingService;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;

new #[Layout('components.layouts.app')] class extends Component {
    public string $jobType = 'transport';

    // Route & date (shared across all vehicles in a batch)
    public ?int $pickupLocationId = null;
    public ?int $deliveryLocationId = null;
    public ?int $vehicleClassId = null;
    public ?int $brandId = null;
    public string $collectionDate = '';
    public string $collectionTime = '';
    public bool $isRoundTrip = false;
    public string $customerNotes = '';

    // Vehicles batch: one row per VIN
    public array $vehicles = [
        ['vin' => '', 'model_name' => '', 'registration' => ''],
    ];

    // Bulk-paste helper (Excel / CSV rows)
    public string $pasteArea = '';
    public bool $showPaste = false;

    // Route preview
    public ?float $previewDistance = null;
    public ?float $previewPrice = null;
    public ?string $previewOriginZone = null;
    public ?string $previewDestZone = null;

    // Yard-work mode
    public ?int $yardLocationId = null;
    public int $driversRequired = 1;
    public float $hoursRequired = 8;

    // Contact overrides
    public string $pickupContactName = '';
    public string $pickupContactPhone = '';
    public string $deliveryContactName = '';
    public string $deliveryContactPhone = '';

    // Emergency
    public bool $isEmergency = false;
    public string $emergencyReason = '';

    // New-location sub-forms
    public bool $showNewPickup = false;
    public bool $showNewDelivery = false;
    public bool $showNewYard = false;
    public string $newLocCompanyName = '';
    public string $newLocAddress = '';
    public string $newLocCity = '';
    public string $newLocProvince = '';
    public string $newLocLat = '';
    public string $newLocLng = '';
    public string $newLocCustomerName = '';
    public string $newLocCustomerPhone = '';
    public string $newLocCustomerEmail = '';

    public function addVehicle(): void
    {
        $this->vehicles[] = ['vin' => '', 'model_name' => '', 'registration' => ''];
    }

    public function removeVehicle(int $index): void
    {
        unset($this->vehicles[$index]);
        $this->vehicles = array_values($this->vehicles);
        if (empty($this->vehicles)) {
            $this->addVehicle();
        }
    }

    /**
     * Parse pasted rows. Accepts tab, comma, or multi-space separated
     * columns. Expected order: VIN, Model, Registration (optional).
     * If a row has only VIN, that's fine.
     */
    public function importPaste(): void
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($this->pasteArea));
        $imported = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $parts = preg_split('/\t|,|\s{2,}/', $line);
            $vin = strtoupper(trim($parts[0] ?? ''));
            if (!$vin || strlen($vin) < 7) continue;

            $row = [
                'vin' => $vin,
                'model_name' => trim($parts[1] ?? ''),
                'registration' => strtoupper(trim($parts[2] ?? '')),
            ];

            // Replace the first empty row if it's still the default, else append
            if ($imported === 0 && count($this->vehicles) === 1 && empty($this->vehicles[0]['vin'])) {
                $this->vehicles[0] = $row;
            } else {
                $this->vehicles[] = $row;
            }
            $imported++;
        }

        $this->pasteArea = '';
        $this->showPaste = false;

        if ($imported) {
            session()->flash('success', "Added {$imported} vehicle(s) from paste.");
        } else {
            session()->flash('error', 'No valid VINs detected in paste.');
        }
    }

    public function saveNewLocation(string $target): void
    {
        $this->validate([
            'newLocCompanyName' => 'required|string|max:255',
            'newLocAddress' => 'required|string|max:500',
        ]);

        $company = auth()->user()->company();
        $location = Location::create([
            'company_id' => $company?->id,
            'company_name' => $this->newLocCompanyName,
            'address' => $this->newLocAddress,
            'city' => $this->newLocCity ?: null,
            'province' => $this->newLocProvince ?: null,
            'latitude' => $this->newLocLat ?: null,
            'longitude' => $this->newLocLng ?: null,
            'customer_name' => $this->newLocCustomerName ?: null,
            'customer_phone' => $this->newLocCustomerPhone ?: null,
            'customer_email' => $this->newLocCustomerEmail ?: null,
        ]);

        if ($target === 'pickup') {
            $this->pickupLocationId = $location->id;
            $this->showNewPickup = false;
        } elseif ($target === 'delivery') {
            $this->deliveryLocationId = $location->id;
            $this->showNewDelivery = false;
        } else {
            $this->yardLocationId = $location->id;
            $this->showNewYard = false;
        }

        $this->resetNewLocFields();
    }

    private function resetNewLocFields(): void
    {
        $this->newLocCompanyName = '';
        $this->newLocAddress = '';
        $this->newLocCity = '';
        $this->newLocProvince = '';
        $this->newLocLat = '';
        $this->newLocLng = '';
        $this->newLocCustomerName = '';
        $this->newLocCustomerPhone = '';
        $this->newLocCustomerEmail = '';
    }

    public function lookupNewLocAddress(): void
    {
        if (!$this->newLocAddress) return;
        $result = GeocodingService::geocodeDetailed($this->newLocAddress);
        if ($result) {
            $this->newLocCity = $result['city'] ?? $this->newLocCity;
            $this->newLocProvince = $result['province'] ?? $this->newLocProvince;
            $this->newLocLat = (string) ($result['lat'] ?? '');
            $this->newLocLng = (string) ($result['lng'] ?? '');
        }
    }

    public function updatedPickupLocationId(): void { $this->calculateRoutePreview(); }
    public function updatedDeliveryLocationId(): void { $this->calculateRoutePreview(); }
    public function updatedVehicleClassId(): void { $this->calculateRoutePreview(); }
    public function updatedIsRoundTrip(): void { $this->calculateRoutePreview(); }

    public function calculateRoutePreview(): void
    {
        $this->previewDistance = null;
        $this->previewPrice = null;
        $this->previewOriginZone = null;
        $this->previewDestZone = null;

        if (!$this->pickupLocationId || !$this->deliveryLocationId || !$this->vehicleClassId) return;
        if ($this->pickupLocationId == $this->deliveryLocationId) return;

        $result = BookingService::previewRoute(
            $this->pickupLocationId,
            $this->deliveryLocationId,
            $this->vehicleClassId,
            $this->isRoundTrip,
        );

        if ($result) {
            $this->previewDistance = $result['distance_km'];
            $this->previewPrice = $result['price'];
            $this->previewOriginZone = $result['origin_zone'];
            $this->previewDestZone = $result['destination_zone'];
        }
    }

    public function submit(BookingService $bookingService): void
    {
        $company = auth()->user()->company();
        if (!$company) {
            session()->flash('error', 'No company linked to your account.');
            return;
        }

        if ($this->jobType === 'yard_work') {
            $this->validate([
                'yardLocationId' => 'required|exists:locations,id',
                'driversRequired' => 'required|integer|min:1',
                'hoursRequired' => 'required|numeric|min:0.5',
            ]);

            $job = $bookingService->createYardBooking([
                'company_id' => $company->id,
                'created_by_user_id' => auth()->id(),
                'yard_location_id' => $this->yardLocationId,
                'drivers_required' => $this->driversRequired,
                'hours_required' => $this->hoursRequired,
            ]);

            session()->flash('success', "Yard booking {$job->job_number} created.");
            $this->redirect(route('oem.bookings.show', $job));
            return;
        }

        // Clean out completely-blank rows before validating
        $this->vehicles = array_values(array_filter(
            $this->vehicles,
            fn($v) => trim($v['vin'] ?? '') !== '' || trim($v['model_name'] ?? '') !== ''
        ));
        if (empty($this->vehicles)) {
            $this->addVehicle();
        }

        $this->validate([
            'pickupLocationId' => 'required|exists:locations,id',
            'deliveryLocationId' => 'required|exists:locations,id|different:pickupLocationId',
            'vehicleClassId' => 'required|exists:vehicle_classes,id',
            'collectionDate' => 'required|date|after_or_equal:today',
            'collectionTime' => 'required|date_format:H:i',
            'vehicles' => 'required|array|min:1',
            'vehicles.*.vin' => 'required|string|min:7|max:17',
            'vehicles.*.model_name' => 'nullable|string|max:255',
        ], [
            'vehicles.*.vin.required' => 'VIN is required on every row.',
            'vehicles.*.vin.min' => 'VIN must be at least 7 characters.',
        ]);

        $common = [
            'company_id' => $company->id,
            'created_by_user_id' => auth()->id(),
            'pickup_location_id' => $this->pickupLocationId,
            'pickup_contact_name' => $this->pickupContactName ?: null,
            'pickup_contact_phone' => $this->pickupContactPhone ?: null,
            'delivery_location_id' => $this->deliveryLocationId,
            'delivery_contact_name' => $this->deliveryContactName ?: null,
            'delivery_contact_phone' => $this->deliveryContactPhone ?: null,
            'vehicle_class_id' => $this->vehicleClassId,
            'brand_id' => $this->brandId,
            'scheduled_date' => $this->collectionDate,
            'scheduled_ready_time' => $this->collectionDate . ' ' . $this->collectionTime,
            'is_emergency' => $this->isEmergency,
            'emergency_reason' => $this->emergencyReason ?: null,
            'is_round_trip' => $this->isRoundTrip,
            'customer_notes' => $this->customerNotes ?: null,
        ];

        $vehicles = array_map(fn($v) => [
            'vin' => strtoupper(trim($v['vin'])),
            'model_name' => $v['model_name'] ? trim($v['model_name']) : null,
            'registration' => !empty($v['registration']) ? strtoupper(trim($v['registration'])) : null,
        ], $this->vehicles);

        $jobs = $bookingService->createTransportBookingBatch($common, $vehicles);

        if ($jobs->count() === 1) {
            session()->flash('success', "Booking {$jobs->first()->job_number} created.");
            $this->redirect(route('oem.bookings.show', $jobs->first()));
        } else {
            session()->flash('success', "Created {$jobs->count()} bookings for this movement order.");
            $this->redirect(route('oem.bookings.index'));
        }
    }

    public function with(): array
    {
        $company = auth()->user()->company();
        $linkedBrandIds = $company?->brands->pluck('id');

        $brandsQuery = Brand::where('is_active', true)->orderBy('name');
        if ($linkedBrandIds && $linkedBrandIds->isNotEmpty()) {
            $brandsQuery->whereIn('id', $linkedBrandIds);
        }
        $brands = $brandsQuery->get(['id', 'name']);

        // If company has exactly one allowed brand, lock it in automatically
        if ($brands->count() === 1 && !$this->brandId) {
            $this->brandId = $brands->first()->id;
        }

        $modelsQuery = VehicleModel::where('is_active', true)->orderBy('name');
        if ($this->brandId) {
            $modelsQuery->where('brand_id', $this->brandId);
        } elseif ($linkedBrandIds && $linkedBrandIds->isNotEmpty()) {
            $modelsQuery->whereIn('brand_id', $linkedBrandIds);
        }

        return [
            'locations' => $company
                ? Location::visibleTo($company)->active()->orderBy('company_name')->get(['id', 'company_name', 'city', 'address'])
                : collect(),
            'vehicleClasses' => VehicleClass::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'brands' => $brands,
            'vehicleModels' => $modelsQuery->get(['id', 'brand_id', 'name']),
        ];
    }
};
?>

<div>
    <x-slot:header>New Movement Order</x-slot:header>

    <form wire:submit="submit" class="max-w-4xl">
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Booking Type</h3>
            <div class="flex gap-4">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="radio" wire:model.live="jobType" value="transport" class="h-4 w-4 text-blue-600">
                    <span class="text-sm font-medium">Movement (vehicle dispatch)</span>
                </label>
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="radio" wire:model.live="jobType" value="yard_work" class="h-4 w-4 text-blue-600">
                    <span class="text-sm font-medium">Yard Work</span>
                </label>
            </div>
        </div>

        @if($jobType === 'transport')
        {{-- ============ ROUTE & DATE ============ --}}
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Route &amp; Movement Date</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">From *</label>
                    <select wire:model.live="pickupLocationId" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm">
                        <option value="">Select pickup...</option>
                        @foreach($locations as $loc)<option value="{{ $loc->id }}">{{ $loc->company_name }}{{ $loc->city ? " ({$loc->city})" : '' }}</option>@endforeach
                    </select>
                    @error('pickupLocationId')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    <button type="button" wire:click="$toggle('showNewPickup')" class="mt-1 text-xs text-blue-600 hover:underline">+ Add new location</button>
                    @if($showNewPickup)@include('partials.new-location-form', ['target' => 'pickup'])@endif
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">To *</label>
                    <select wire:model.live="deliveryLocationId" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm">
                        <option value="">Select delivery...</option>
                        @foreach($locations as $loc)<option value="{{ $loc->id }}">{{ $loc->company_name }}{{ $loc->city ? " ({$loc->city})" : '' }}</option>@endforeach
                    </select>
                    @error('deliveryLocationId')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    <button type="button" wire:click="$toggle('showNewDelivery')" class="mt-1 text-xs text-blue-600 hover:underline">+ Add new location</button>
                    @if($showNewDelivery)@include('partials.new-location-form', ['target' => 'delivery'])@endif
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Movement Order Date *</label>
                    <input wire:model="collectionDate" type="date" min="{{ date('Y-m-d') }}" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm">
                    @error('collectionDate')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Ready Time *</label>
                    <input wire:model="collectionTime" type="time" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm">
                    @error('collectionTime')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Vehicle Class *</label>
                    <select wire:model.live="vehicleClassId" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm">
                        <option value="">Select class...</option>
                        @foreach($vehicleClasses as $vc)<option value="{{ $vc->id }}">{{ $vc->name }}</option>@endforeach
                    </select>
                    @error('vehicleClassId')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div class="flex items-end">
                    <label class="flex items-center gap-2 cursor-pointer pb-2">
                        <input wire:model.live="isRoundTrip" type="checkbox" class="h-4 w-4 rounded border-gray-300 text-blue-600">
                        <span class="text-sm font-medium text-gray-700">Round trip</span>
                        <span class="text-xs text-gray-400">(doubles distance)</span>
                    </label>
                </div>
            </div>

            @if($previewDistance)
            <div class="mt-4 rounded-lg bg-blue-50 border border-blue-200 px-4 py-3">
                <div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                    <div class="flex items-center gap-2">
                        <svg class="h-5 w-5 text-blue-500 shrink-0" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" fill="none"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                        <span class="font-semibold text-gray-900">{{ number_format($previewDistance, 1) }} km</span>
                    </div>
                    @if($previewPrice)
                    <div><span class="font-semibold text-blue-900">Price:</span><span class="text-blue-700 ml-1">R{{ number_format($previewPrice, 2) }}</span></div>
                    @endif
                    @if($previewOriginZone && $previewDestZone)
                    <div class="text-xs text-blue-600">{{ $previewOriginZone }} &rarr; {{ $previewDestZone }}</div>
                    @endif
                </div>
            </div>
            @endif

            <details class="mt-4 group">
                <summary class="text-sm font-medium text-gray-600 cursor-pointer hover:text-gray-900">Contact overrides (optional)</summary>
                <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-4 rounded-lg border border-gray-200 bg-gray-50 p-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Pickup Contact Name</label>
                        <input wire:model="pickupContactName" type="text" class="w-full rounded-md border border-gray-300 px-2.5 py-2 text-sm" placeholder="Leave blank to use location default">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Pickup Contact Phone</label>
                        <input wire:model="pickupContactPhone" type="text" class="w-full rounded-md border border-gray-300 px-2.5 py-2 text-sm" placeholder="Leave blank to use location default">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Delivery Contact Name</label>
                        <input wire:model="deliveryContactName" type="text" class="w-full rounded-md border border-gray-300 px-2.5 py-2 text-sm" placeholder="Leave blank to use location default">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Delivery Contact Phone</label>
                        <input wire:model="deliveryContactPhone" type="text" class="w-full rounded-md border border-gray-300 px-2.5 py-2 text-sm" placeholder="Leave blank to use location default">
                    </div>
                </div>
            </details>
        </div>

        {{-- ============ VEHICLES ============ --}}
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
            <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">Vehicles</h3>
                    <p class="text-xs text-gray-500 mt-0.5">Add one row per VIN. All vehicles in this order share the same From, To, and Movement Date.</p>
                </div>
                <button type="button" wire:click="$toggle('showPaste')" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="8" height="4" x="8" y="2" rx="1"/><path d="M8 4H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-2"/></svg>
                    {{ $showPaste ? 'Hide paste' : 'Paste from spreadsheet' }}
                </button>
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Brand{{ $brands->count() > 1 ? ' *' : '' }}</label>
                @if($brands->count() <= 1)
                    <input type="text" value="{{ $brands->first()?->name ?? 'No brand assigned to your account' }}" disabled class="w-full max-w-xs rounded-lg border border-gray-200 bg-gray-50 px-3 py-2.5 text-sm text-gray-600">
                @else
                    <select wire:model.live="brandId" class="w-full max-w-xs rounded-lg border border-gray-300 px-3 py-2.5 text-sm">
                        <option value="">Select brand...</option>
                        @foreach($brands as $brand)<option value="{{ $brand->id }}">{{ $brand->name }}</option>@endforeach
                    </select>
                @endif
                @if($vehicleModels->isEmpty() && $brandId)
                    <p class="mt-1 text-xs text-amber-600">No models defined for this brand — ask your TCDC admin to add them.</p>
                @endif
            </div>

            @if($showPaste)
            <div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 p-4">
                <label class="block text-sm font-medium text-blue-900 mb-1">Paste rows</label>
                <p class="text-xs text-blue-700 mb-2">One vehicle per line. Columns: <strong>VIN</strong>, Model, Registration. Tab, comma or 2-space separated.</p>
                <textarea wire:model="pasteArea" rows="5" class="w-full rounded-md border border-blue-300 px-3 py-2 text-sm font-mono" placeholder="LFNA4HB52SAE42058&#9;4.110FL-MT&#10;LFWSSXSJ6S1H13183&#9;J7 28.550FTP"></textarea>
                <div class="mt-2 flex justify-end gap-2">
                    <button type="button" wire:click="$set('showPaste', false)" class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">Cancel</button>
                    <button type="button" wire:click="importPaste" class="rounded-md bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-500">Import</button>
                </div>
            </div>
            @endif

            @if($vehicleModels->isNotEmpty())
                <datalist id="vehicle-models-datalist">
                    @foreach($vehicleModels as $vm)<option value="{{ $vm->name }}"></option>@endforeach
                </datalist>
                <p class="mb-2 text-xs text-gray-500">Model field has {{ $vehicleModels->count() }} suggestion{{ $vehicleModels->count() === 1 ? '' : 's' }} — start typing or pick from the list. You can enter any value.</p>
            @endif

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase text-gray-500">
                            <th class="px-2 py-2 w-10">#</th>
                            <th class="px-2 py-2">VIN / Chassis *</th>
                            <th class="px-2 py-2">Model</th>
                            <th class="px-2 py-2">Registration</th>
                            <th class="px-2 py-2 w-10"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($vehicles as $i => $v)
                        <tr wire:key="veh-{{ $i }}">
                            <td class="px-2 py-2 text-xs text-gray-400 tabular-nums">{{ $i + 1 }}</td>
                            <td class="px-2 py-2">
                                <input wire:model="vehicles.{{ $i }}.vin" type="text" maxlength="17" class="w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm font-mono uppercase" placeholder="Full VIN">
                                @error("vehicles.$i.vin")<p class="mt-0.5 text-xs text-red-600">{{ $message }}</p>@enderror
                            </td>
                            <td class="px-2 py-2">
                                <input wire:model="vehicles.{{ $i }}.model_name"
                                    list="vehicle-models-datalist"
                                    type="text"
                                    autocomplete="off"
                                    class="w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm"
                                    placeholder="{{ $vehicleModels->isNotEmpty() ? 'Type or pick a model...' : 'e.g. Actros 2645' }}">
                            </td>
                            <td class="px-2 py-2">
                                <input wire:model="vehicles.{{ $i }}.registration" type="text" class="w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm uppercase" placeholder="Optional">
                            </td>
                            <td class="px-2 py-2 text-right">
                                @if(count($vehicles) > 1)
                                <button type="button" wire:click="removeVehicle({{ $i }})" class="rounded-md p-1 text-gray-400 hover:text-red-600 hover:bg-red-50" title="Remove row">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                </button>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @error('vehicles')<p class="mt-2 text-xs text-red-600">{{ $message }}</p>@enderror

            <div class="mt-3">
                <button type="button" wire:click="addVehicle" class="inline-flex items-center gap-1 rounded-md bg-gray-100 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-200">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                    Add another vehicle
                </button>
                <span class="ml-3 text-xs text-gray-500">{{ count($vehicles) }} vehicle{{ count($vehicles) === 1 ? '' : 's' }} in this order</span>
            </div>
        </div>

        {{-- ============ COMMENTS ============ --}}
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
            <label class="block text-sm font-medium text-gray-700 mb-1">Comments <span class="text-gray-400 font-normal">(optional)</span></label>
            <textarea wire:model="customerNotes" rows="2" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" placeholder='e.g. "collect Friday", "driver must bring hi-vis"...'></textarea>

            <div class="mt-4">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input wire:model.live="isEmergency" type="checkbox" class="h-4 w-4 rounded border-gray-300 text-red-600">
                    <span class="text-sm font-medium text-red-700">Emergency order</span>
                </label>
                @if($isEmergency)
                <div class="mt-2">
                    <textarea wire:model="emergencyReason" rows="2" placeholder="Emergency reason..." class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"></textarea>
                </div>
                @endif
            </div>
        </div>
        @else
        {{-- ============ YARD WORK ============ --}}
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Yard Work Details</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Yard Location *</label>
                    <select wire:model="yardLocationId" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm">
                        <option value="">Select yard...</option>
                        @foreach($locations as $loc)<option value="{{ $loc->id }}">{{ $loc->company_name }}{{ $loc->city ? " ({$loc->city})" : '' }}</option>@endforeach
                    </select>
                    @error('yardLocationId')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    <button type="button" wire:click="$toggle('showNewYard')" class="mt-1 text-xs text-blue-600 hover:underline">+ Add New Location</button>
                    @if($showNewYard)@include('partials.new-location-form', ['target' => 'yard'])@endif
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Drivers Required *</label>
                    <input wire:model="driversRequired" type="number" min="1" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Hours Required *</label>
                    <input wire:model="hoursRequired" type="number" min="0.5" step="0.5" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm">
                </div>
            </div>
        </div>
        @endif

        <div class="flex justify-end gap-3">
            <a href="{{ route('oem.bookings.index') }}" class="rounded-lg border border-gray-300 bg-white px-6 py-3 text-sm font-semibold text-gray-700 hover:bg-gray-50">Cancel</a>
            <button type="submit" class="rounded-lg bg-blue-600 px-6 py-3 text-sm font-semibold text-white hover:bg-blue-500" wire:loading.attr="disabled">
                <span wire:loading.remove>
                    @if($jobType === 'transport')
                        Submit {{ count($vehicles) > 1 ? count($vehicles) . ' Movement Orders' : 'Movement Order' }}
                    @else
                        Submit Booking
                    @endif
                </span>
                <span wire:loading>Submitting...</span>
            </button>
        </div>
    </form>
</div>
