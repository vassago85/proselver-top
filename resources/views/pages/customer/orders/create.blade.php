<?php

use App\Models\Brand;
use App\Models\Company;
use App\Models\Job;
use App\Models\Location;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\VehicleClass;
use App\Models\VehicleModel;
use App\Services\BookingService;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.app')] class extends Component {
    use WithFileUploads;

    public ?Company $company = null;
    public bool $hasLocations = false;

    public ?int $pickupLocationId = null;
    public ?int $deliveryLocationId = null;
    public ?string $destinationType = null;
    public ?int $brandId = null;
    public string $modelName = '';
    public string $vin = '';
    public string $registration = '';
    public ?int $vehicleClassId = null;
    public string $scheduledDate = '';
    // HH:MM, optional. Captures "the truck will be ready for collection
    // at this time on the requested date" — required for same-day bookings
    // so dispatch knows whether the driver can roll now or has to wait.
    public string $scheduledReadyTime = '';
    public string $poNumber = '';
    public ?string $poAmount = null;
    public $poFile = null;
    public string $notes = '';

    // Executor selection. Defaults to ProSelver so nothing changes
    // for dealers who don't care about the new options — they just see
    // the same form they've always used with a tiny chooser at the top.
    public string $executorType = Job::EXECUTOR_PROSELVER;
    public ?int $internalDriverId = null;
    public string $thirdPartyCourierName = '';
    public string $thirdPartyWaybill = '';
    public string $thirdPartyExpectedDate = '';
    public string $selfCollectName = '';
    public string $selfCollectPhone = '';
    public string $selfCollectIdNumber = '';

    public function mount(): void
    {
        $this->company = auth()->user()->company();
        abort_unless($this->company, 403, 'No company associated with your account.');

        $this->hasLocations = Location::where('company_id', $this->company->id)
            ->where('is_active', true)
            ->exists();

        // Pre-fill the requested delivery destination from query params
        // so the "Book return / next move" button on the body-builder
        // stock page can land the dealer in a form that already knows
        // the answer. Honoured silently — invalid IDs are dropped.
        if (request()->filled('pickup_location_id')) {
            $id = (int) request('pickup_location_id');
            if (Location::where('id', $id)->exists()) {
                $this->pickupLocationId = $id;
            }
        }
        if (request()->filled('vin')) {
            $this->vin = (string) request('vin');
        }
        if (request()->filled('brand_id')) {
            $this->brandId = (int) request('brand_id');
        }
        if (request()->filled('model_name')) {
            $this->modelName = (string) request('model_name');
        }
        if (request()->filled('vehicle_class_id')) {
            $this->vehicleClassId = (int) request('vehicle_class_id');
        }
    }

    public function submit(): void
    {
        $rules = [
            'pickupLocationId' => 'required|exists:locations,id',
            'deliveryLocationId' => 'required|exists:locations,id|different:pickupLocationId',
            'destinationType' => 'nullable|in:' . implode(',', Job::DESTINATION_TYPES),
            'brandId' => 'nullable|exists:brands,id',
            'modelName' => 'nullable|string|max:255',
            'vin' => 'required|string|max:50',
            'registration' => 'nullable|string|max:20',
            'vehicleClassId' => 'required|exists:vehicle_classes,id',
            // after_or_equal so dealers / OEM customers can book same-day
            // when a vehicle is ready right now (e.g. dealer collection
            // happening this afternoon). The next-day cutoff that used
            // to live in BookingService::canBookForDate is enforced by
            // ops, not by this form.
            'scheduledDate' => 'required|date|after_or_equal:today',
            'scheduledReadyTime' => 'nullable|date_format:H:i',
            'poNumber' => 'nullable|string|max:100',
            'poAmount' => 'nullable|numeric|min:0',
            'poFile' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'executorType' => 'required|in:' . implode(',', Job::EXECUTOR_TYPES),
        ];

        // Conditional rules per executor type. We intentionally keep
        // internal driver assignment OPTIONAL at booking time — the
        // dealer can pick a driver now or leave it for the planner.
        if ($this->executorType === Job::EXECUTOR_INTERNAL && $this->internalDriverId) {
            $rules['internalDriverId'] = [
                'integer',
                // Driver must be in the dealer's own driver pool —
                // can't accidentally pick a ProSelver driver or
                // another dealer's driver through tampering.
                function (string $attribute, $value, \Closure $fail) {
                    $exists = User::query()
                        ->driversForCompany($this->company->id)
                        ->whereKey((int) $value)
                        ->exists();
                    if (! $exists) {
                        $fail('Selected driver is not in your driver pool.');
                    }
                },
            ];
        }
        if ($this->executorType === Job::EXECUTOR_THIRD_PARTY) {
            $rules['thirdPartyCourierName'] = 'required|string|max:255';
            $rules['thirdPartyWaybill'] = 'nullable|string|max:100';
            $rules['thirdPartyExpectedDate'] = 'nullable|date';
        }
        if ($this->executorType === Job::EXECUTOR_SELF_COLLECT) {
            $rules['selfCollectName'] = 'required|string|max:255';
            $rules['selfCollectPhone'] = 'required|string|max:50';
            $rules['selfCollectIdNumber'] = 'nullable|string|max:50';
        }

        $this->validate($rules);

        $service = app(BookingService::class);

        $job = $service->createTransportBooking([
            'pickup_location_id' => $this->pickupLocationId,
            'delivery_location_id' => $this->deliveryLocationId,
            'destination_type' => $this->destinationType ?: null,
            'vehicle_class_id' => $this->vehicleClassId,
            'brand_id' => $this->brandId,
            'model_name' => $this->modelName ?: null,
            'vin' => $this->vin,
            'registration' => $this->registration ?: null,
            'scheduled_date' => $this->scheduledDate,
            'scheduled_ready_time' => $this->scheduledReadyTime
                ? $this->scheduledDate . ' ' . $this->scheduledReadyTime
                : null,
            'po_number' => $this->poNumber ?: null,
            'po_amount' => $this->poAmount,
            'company_id' => $this->company->id,
            'created_by_user_id' => auth()->id(),
            'customer_notes' => $this->notes ?: null,
            'executor_type' => $this->executorType,
            'driver_user_id' => $this->executorType === Job::EXECUTOR_INTERNAL
                ? ($this->internalDriverId ?: null)
                : null,
            'third_party_courier_name' => $this->thirdPartyCourierName ?: null,
            'third_party_waybill' => $this->thirdPartyWaybill ?: null,
            'third_party_expected_date' => $this->thirdPartyExpectedDate ?: null,
            'self_collect_name' => $this->selfCollectName ?: null,
            'self_collect_phone' => $this->selfCollectPhone ?: null,
            'self_collect_id_number' => $this->selfCollectIdNumber ?: null,
        ]);

        $job->status = Job::STATUS_RECEIVED;
        $job->save();

        if ($this->poFile) {
            $disk = \App\Support\StorageDisk::forUploads();
            $path = $this->poFile->store('jobs/' . $job->uuid . '/po', $disk);

            PurchaseOrder::create([
                'job_id' => $job->id,
                'po_number' => $this->poNumber ?: $job->job_number,
                'po_amount' => $this->poAmount,
                'document_disk' => $disk,
                'document_path' => $path,
                'original_filename' => $this->poFile->getClientOriginalName(),
                'uploaded_by_user_id' => auth()->id(),
            ]);
        }

        session()->flash('success', 'Order submitted successfully — reference ' . $job->job_number);
        $this->redirect(route('customer.orders.show', $job), navigate: true);
    }

    public function with(): array
    {
        $companyLocations = Location::where('company_id', $this->company->id)
            ->where('is_active', true)
            ->orderBy('company_name')
            ->get(['id', 'company_name', 'city']);

        $sharedLocations = Location::whereNull('company_id')
            ->where('is_active', true)
            ->orderBy('company_name')
            ->get(['id', 'company_name', 'city']);

        $companyBrands = $this->company->brands()->where('is_active', true)->orderBy('name')->get(['brands.id', 'brands.name']);
        $brands = $companyBrands->isNotEmpty()
            ? $companyBrands
            : Brand::where('is_active', true)->orderBy('name')->get(['id', 'name']);

        // Model suggestions: filter to the selected brand when one is picked,
        // otherwise fall back to models across the customer's allowed brands so
        // the datalist is never empty. Models are data-driven (admins can add
        // more via Brands & Models) and the input stays free-text for any
        // variant not yet catalogued.
        $modelsQuery = VehicleModel::where('is_active', true)->orderBy('name');
        if ($this->brandId) {
            $modelsQuery->where('brand_id', $this->brandId);
        } elseif ($brands->isNotEmpty()) {
            $modelsQuery->whereIn('brand_id', $brands->pluck('id'));
        }
        $vehicleModels = $modelsQuery->get(['id', 'brand_id', 'name']);

        $selectedBrand = $this->brandId ? $brands->firstWhere('id', (int) $this->brandId) : null;
        $modelPlaceholder = match (true) {
            $vehicleModels->isNotEmpty() && $selectedBrand !== null
                => 'e.g. ' . $vehicleModels->first()->name,
            $vehicleModels->isNotEmpty()
                => 'Type or pick a model…',
            $selectedBrand !== null
                => 'Enter model for ' . $selectedBrand->name,
            default
                => 'Enter model',
        };

        $vehicleClasses = VehicleClass::where('is_active', true)->ordered()->get(['id', 'name']);

        // Shape the location options for <x-searchable-select>: a grouped
        // structure so "My Locations" stays separate from "Shared Depots"
        // even with typeahead filtering. Same shape powers pickup and
        // delivery — they pull from the same pool.
        $locationFormatter = fn ($loc) => [
            'value' => (string) $loc->id,
            'label' => $loc->company_name . ($loc->city ? " — {$loc->city}" : ''),
        ];
        $locationGroups = [];
        if ($companyLocations->isNotEmpty()) {
            $locationGroups[] = [
                'label' => 'My Locations',
                'options' => $companyLocations->map($locationFormatter)->values()->all(),
            ];
        }
        if ($sharedLocations->isNotEmpty()) {
            $locationGroups[] = [
                'label' => 'Shared Depots',
                'options' => $sharedLocations->map($locationFormatter)->values()->all(),
            ];
        }

        $brandOptions = $brands->map(fn ($b) => [
            'value' => (string) $b->id,
            'label' => $b->name,
        ])->values()->all();

        $vehicleClassOptions = $vehicleClasses->map(fn ($vc) => [
            'value' => (string) $vc->id,
            'label' => $vc->name,
        ])->values()->all();

        // Dealer's own driver pool — only populated for the conditional
        // "Internal Driver" panel. Empty pool just means the dealer
        // hasn't added any drivers yet; the form still lets them book
        // and assign later from the order detail page.
        $internalDrivers = User::query()
            ->driversForCompany($this->company->id)
            ->orderBy('name')
            ->get(['id', 'name']);

        $internalDriverOptions = $internalDrivers->map(fn ($d) => [
            'value' => (string) $d->id,
            'label' => $d->name,
        ])->values()->all();

        return [
            'companyLocations' => $companyLocations,
            'sharedLocations' => $sharedLocations,
            'brands' => $brands,
            'vehicleClasses' => $vehicleClasses,
            'vehicleModels' => $vehicleModels,
            'modelPlaceholder' => $modelPlaceholder,
            'locationGroups' => $locationGroups,
            'brandOptions' => $brandOptions,
            'vehicleClassOptions' => $vehicleClassOptions,
            'internalDrivers' => $internalDrivers,
            'internalDriverOptions' => $internalDriverOptions,
            'executorChoices' => [
                Job::EXECUTOR_PROSELVER => [
                    'label' => 'ProSelver',
                    'description' => 'A ProSelver-supplied driver and truck.',
                    'icon' => 'truck',
                ],
                Job::EXECUTOR_INTERNAL => [
                    'label' => 'Internal Driver',
                    'description' => 'One of your own drivers will move this vehicle.',
                    'icon' => 'user',
                ],
                Job::EXECUTOR_THIRD_PARTY => [
                    'label' => '3rd-Party Courier',
                    'description' => 'An outside courier company is handling the move.',
                    'icon' => 'building',
                ],
                Job::EXECUTOR_SELF_COLLECT => [
                    'label' => 'Self-Collect',
                    'description' => 'The end customer is collecting the vehicle themselves.',
                    'icon' => 'hand',
                ],
            ],
        ];
    }
};

?>

<div>
    <x-slot:header>New Order</x-slot:header>

    <div class="mb-4">
        <a href="{{ route('customer.orders.index') }}" class="inline-flex items-center gap-1 text-sm text-gray-500 hover:text-gray-700">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
            Back to Orders
        </a>
    </div>

    @if(!$hasLocations)
        <div class="rounded-xl border border-yellow-300 bg-yellow-50 p-6 text-center">
            <svg class="mx-auto h-10 w-10 text-yellow-500 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
            <h3 class="text-lg font-semibold text-yellow-800 mb-1">No Locations Yet</h3>
            <p class="text-sm text-yellow-700 mb-4">You need to add at least one active location before creating an order.</p>
            <a href="{{ route('customer.locations.index') }}" class="inline-flex items-center gap-1.5 rounded-lg bg-yellow-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-yellow-500 transition-colors">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                Add Locations
            </a>
        </div>
    @else
        <form wire:submit="submit" class="space-y-6">
            {{-- Executor: who is moving this vehicle? --}}
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-1">Who is moving this vehicle?</h3>
                <p class="text-sm text-gray-500 mb-4">Pick how this movement will be done. You can change this later if plans shift.</p>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    @foreach($executorChoices as $value => $choice)
                        <label class="relative flex flex-col gap-1 cursor-pointer rounded-lg border-2 px-4 py-3 transition-colors hover:border-blue-300
                            {{ $executorType === $value ? 'border-blue-500 bg-blue-50' : 'border-gray-200 bg-white' }}">
                            <input type="radio" wire:model.live="executorType" value="{{ $value }}" class="sr-only">
                            <span class="text-sm font-semibold text-gray-900">{{ $choice['label'] }}</span>
                            <span class="text-xs text-gray-500">{{ $choice['description'] }}</span>
                            @if($executorType === $value)
                                <svg class="absolute top-2 right-2 h-4 w-4 text-blue-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                            @endif
                        </label>
                    @endforeach
                </div>

                @error('executorType') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror

                {{-- Internal driver --}}
                @if($executorType === \App\Models\Job::EXECUTOR_INTERNAL)
                    <div class="mt-4 rounded-lg bg-gray-50 border border-gray-200 p-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Driver (optional)</label>
                        @if(empty($internalDriverOptions))
                            <p class="text-sm text-gray-600">You haven't added any internal drivers yet. You can still submit the order and assign a driver later, or go to <a class="font-medium text-blue-600 hover:underline" href="{{ route('customer.drivers.index') }}">Drivers</a> to add one now.</p>
                        @else
                            <x-searchable-select
                                wire:model="internalDriverId"
                                :options="$internalDriverOptions"
                                placeholder="Choose a driver (or assign later)"
                                search-placeholder="Search your drivers…"
                            />
                            <p class="mt-1 text-xs text-gray-500">Leave blank to assign later from the order page.</p>
                            @error('internalDriverId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        @endif
                    </div>
                @endif

                {{-- 3rd-party courier --}}
                @if($executorType === \App\Models\Job::EXECUTOR_THIRD_PARTY)
                    <div class="mt-4 rounded-lg bg-gray-50 border border-gray-200 p-4 grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div class="sm:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Courier Company <span class="text-red-500">*</span></label>
                            <input wire:model="thirdPartyCourierName" type="text"
                                class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500"
                                placeholder="e.g. DHL, Aramex, Local Carrier">
                            @error('thirdPartyCourierName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Expected Delivery</label>
                            <input wire:model="thirdPartyExpectedDate" type="date"
                                class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                            @error('thirdPartyExpectedDate') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div class="sm:col-span-3">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Waybill / Tracking Number</label>
                            <input wire:model="thirdPartyWaybill" type="text"
                                class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500"
                                placeholder="Optional — fill in when you have it">
                            @error('thirdPartyWaybill') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                @endif

                {{-- Self-collect --}}
                @if($executorType === \App\Models\Job::EXECUTOR_SELF_COLLECT)
                    <div class="mt-4 rounded-lg bg-gray-50 border border-gray-200 p-4 grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Collector Name <span class="text-red-500">*</span></label>
                            <input wire:model="selfCollectName" type="text"
                                class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500"
                                placeholder="Person collecting the vehicle">
                            @error('selfCollectName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Phone <span class="text-red-500">*</span></label>
                            <input wire:model="selfCollectPhone" type="tel"
                                class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500"
                                placeholder="082 xxx xxxx">
                            @error('selfCollectPhone') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">ID / Licence Number</label>
                            <input wire:model="selfCollectIdNumber" type="text"
                                class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500"
                                placeholder="Optional — for record only">
                            @error('selfCollectIdNumber') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                @endif
            </div>

            {{-- Locations --}}
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Pickup & Delivery</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Pickup Location <span class="text-red-500">*</span></label>
                        <x-searchable-select
                            wire:model.live="pickupLocationId"
                            :options="$locationGroups"
                            placeholder="Select pickup location"
                            search-placeholder="Search locations…"
                        />
                        @error('pickupLocationId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Delivery Location <span class="text-red-500">*</span></label>
                        <x-searchable-select
                            wire:model.live="deliveryLocationId"
                            :options="$locationGroups"
                            placeholder="Select delivery location"
                            search-placeholder="Search locations…"
                        />
                        @error('deliveryLocationId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                {{-- Destination type — drives the body-builder stock view
                     and the archive button on the order page. Leave blank
                     when it's an ordinary dealer-to-dealer move. --}}
                <div class="mt-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">What kind of destination is this?</label>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                        @foreach([
                            null => ['label' => 'Standard', 'sub' => 'Dealer-to-dealer / handover'],
                            \App\Models\Job::DESTINATION_BODY_BUILDER => ['label' => 'Body Builder', 'sub' => 'Vehicle goes for fitment'],
                            \App\Models\Job::DESTINATION_YARD => ['label' => 'Yard', 'sub' => 'Transit / holding'],
                            \App\Models\Job::DESTINATION_OTHER => ['label' => 'Other', 'sub' => 'One-off destination'],
                        ] as $value => $opts)
                            <label class="cursor-pointer rounded-lg border-2 px-3 py-2 transition-colors hover:border-blue-300
                                {{ (string) $destinationType === (string) $value ? 'border-blue-500 bg-blue-50' : 'border-gray-200 bg-white' }}">
                                <input type="radio" wire:model.live="destinationType" value="{{ $value }}" class="sr-only">
                                <span class="block text-sm font-semibold text-gray-900">{{ $opts['label'] }}</span>
                                <span class="block text-[11px] text-gray-500">{{ $opts['sub'] }}</span>
                            </label>
                        @endforeach
                    </div>
                    @if($destinationType === \App\Models\Job::DESTINATION_BODY_BUILDER)
                        <p class="mt-2 text-xs text-amber-700">Body-builder deliveries stay visible in your stock view until a return movement is booked.</p>
                    @endif
                    @error('destinationType') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>

            {{-- Vehicle Details --}}
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Vehicle Details</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Brand</label>
                        <x-searchable-select
                            wire:model.live="brandId"
                            :options="$brandOptions"
                            placeholder="Select brand"
                            search-placeholder="Search brands…"
                        />
                        @error('brandId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Model Name
                            @if($vehicleModels->isNotEmpty())
                                <span class="ml-1 text-xs font-normal text-gray-500">
                                    · {{ $vehicleModels->count() }} suggestion{{ $vehicleModels->count() === 1 ? '' : 's' }}
                                </span>
                            @endif
                        </label>
                        <input wire:model="modelName" type="text"
                            list="customer-order-model-suggestions"
                            autocomplete="off"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500"
                            placeholder="{{ $modelPlaceholder }}">
                        @if($vehicleModels->isNotEmpty())
                            <datalist id="customer-order-model-suggestions">
                                @foreach($vehicleModels as $vm)<option value="{{ $vm->name }}"></option>@endforeach
                            </datalist>
                            <p class="mt-1 text-xs text-gray-500">Start typing to filter suggestions — you can also enter any model not in the list.</p>
                        @endif
                        @error('modelName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">VIN / Chassis Number <span class="text-red-500">*</span></label>
                        <input wire:model="vin" type="text" required
                            class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm font-mono uppercase focus:border-blue-500 focus:ring-blue-500"
                            placeholder="Enter VIN or chassis number">
                        @error('vin') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Registration</label>
                        <input wire:model="registration" type="text"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm uppercase focus:border-blue-500 focus:ring-blue-500"
                            placeholder="Optional">
                        @error('registration') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Vehicle Class <span class="text-red-500">*</span></label>
                        <x-searchable-select
                            wire:model="vehicleClassId"
                            :options="$vehicleClassOptions"
                            placeholder="Select vehicle class"
                            search-placeholder="Search classes…"
                        />
                        @error('vehicleClassId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            {{-- Scheduling & PO --}}
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Scheduling & Reference</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Requested Date <span class="text-red-500">*</span></label>
                        <input wire:model="scheduledDate" type="date" required min="{{ now()->format('Y-m-d') }}"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                        <p class="mt-1 text-xs text-gray-500">Today is allowed if the vehicle is ready for collection now.</p>
                        @error('scheduledDate') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Vehicle Ready Time</label>
                        <input wire:model="scheduledReadyTime" type="time"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                        <p class="mt-1 text-xs text-gray-500">Optional — when the vehicle will be ready for the driver to collect.</p>
                        @error('scheduledReadyTime') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">PO Number</label>
                        <input wire:model="poNumber" type="text"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500"
                            placeholder="Optional">
                        @error('poNumber') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">PO Amount</label>
                        <input wire:model="poAmount" type="number" step="0.01" min="0"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500"
                            placeholder="0.00">
                        @error('poAmount') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                {{-- PO Document Upload --}}
                <div class="mt-4" x-data="{ uploading: false, progress: 0 }"
                    x-on:livewire-upload-start="uploading = true"
                    x-on:livewire-upload-finish="uploading = false"
                    x-on:livewire-upload-cancel="uploading = false"
                    x-on:livewire-upload-error="uploading = false"
                    x-on:livewire-upload-progress="progress = $event.detail.progress">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Purchase Order Document</label>
                    <p class="text-xs text-gray-500 mb-2">PDF, JPG or PNG &middot; max 10&nbsp;MB &middot; optional</p>

                    @if($poFile)
                        <div class="flex items-center justify-between rounded-lg border border-green-200 bg-green-50 px-3 py-2.5">
                            <div class="flex items-center gap-2 min-w-0">
                                <svg class="h-4 w-4 shrink-0 text-green-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                <span class="text-sm text-gray-800 truncate">{{ $poFile->getClientOriginalName() }}</span>
                                <span class="text-xs text-gray-500">({{ number_format($poFile->getSize() / 1024, 0) }} KB)</span>
                            </div>
                            <button type="button" wire:click="$set('poFile', null)"
                                class="text-xs font-medium text-red-600 hover:text-red-700 shrink-0 ml-3">
                                Remove
                            </button>
                        </div>
                    @else
                        <label class="flex items-center justify-center gap-2 w-full rounded-lg border-2 border-dashed border-gray-300 bg-gray-50 px-4 py-5 text-sm text-gray-600 hover:border-blue-400 hover:bg-blue-50 cursor-pointer transition-colors">
                            <svg class="h-5 w-5 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                            <span>Click to upload PO document</span>
                            <input wire:model="poFile" type="file" accept="application/pdf,image/jpeg,image/png" class="hidden">
                        </label>
                    @endif

                    <div x-show="uploading" x-cloak class="mt-2">
                        <div class="h-1.5 w-full rounded-full bg-gray-200 overflow-hidden">
                            <div class="h-full bg-blue-500 transition-all" :style="`width: ${progress}%`"></div>
                        </div>
                        <p class="mt-1 text-xs text-gray-500">Uploading… <span x-text="progress"></span>%</p>
                    </div>

                    @error('poFile') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="mt-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                    <textarea wire:model="notes" rows="3"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500"
                        placeholder="Special instructions, access details, etc."></textarea>
                    @error('notes') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>

            {{-- Submit --}}
            <div class="flex items-center justify-end gap-3">
                <a href="{{ route('customer.orders.index') }}" class="rounded-lg bg-white px-5 py-2.5 text-sm font-semibold text-gray-700 border border-gray-300 hover:bg-gray-50 transition-colors">
                    Cancel
                </a>
                <button type="submit" class="rounded-lg bg-blue-600 px-6 py-2.5 text-sm font-semibold text-white hover:bg-blue-500 transition-colors">
                    Submit Order
                </button>
            </div>
        </form>
    @endif
</div>
