<?php

use App\Models\Brand;
use App\Models\Company;
use App\Models\DealerStock;
use App\Models\Job;
use App\Models\Location;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\VehicleClass;
use App\Models\VehicleModel;
use App\Services\BookingService;
use App\Support\VehicleIdentifier;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.app')] class extends Component {
    use WithFileUploads;

    public ?Company $company = null;
    public bool $hasLocations = false;

    public ?int $pickupLocationId = null;
    public ?int $deliveryLocationId = null;
    // Default to "Delivery" — the most common case. Sales staff opt
    // in to Body Builder / Round Trip / Other Storage Facility for
    // non-final movements that should stay on Stock In Transit.
    public ?string $destinationType = \App\Models\Job::DESTINATION_DEALER;
    public ?int $brandId = null;
    public string $modelName = '';

    // Single "smart" vehicle-identifier input.  The user types a VIN
    // OR a registration; the classifier picks which column it goes to
    // at submit time so we stop polluting `vin` with plates (see
    // App\Support\VehicleIdentifier).  `$secondaryIdentifier` is the
    // optional "the other one, if you know it" field that appears
    // once we've decided what the primary is.
    public string $vehicleId = '';
    public string $identifierType = VehicleIdentifier::TYPE_VIN;
    public string $secondaryIdentifier = '';
    public ?int $vehicleClassId = null;

    // Stock lookup. When a dealer types an identifier that matches a
    // unit on their (group-visible) books, we pre-fill the vehicle and
    // show a confirmation; no match just lets them capture a new vehicle.
    public ?array $matchedStock = null;
    public bool $vehicleIdChecked = false;
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

        // OEM tenants currently book ProSelver only — we don't surface the
        // internal-driver / third-party / self-collect choice for them.
        // Force-pin the executor on mount so even tampered Livewire payloads
        // can't slip another value through the submit handler. The feature
        // code below stays intact so we can flip a single flag to re-enable
        // it for OEMs later (e.g. when we expose third-party couriers).
        if ($this->company->isOem()) {
            $this->executorType = Job::EXECUTOR_PROSELVER;
        }

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
        // Deep-link identifier: the stock page "Book return" button
        // still sends `?vin=` (the stock ledger's VIN is authoritative),
        // but a friend page could just as easily hand us `?registration=`
        // for a plate-only vehicle -- classify whichever arrived so the
        // primary input renders with the right detected type.
        $deepId = request()->input('vin') ?: request()->input('registration');
        if ($deepId) {
            $this->vehicleId = (string) $deepId;
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

        // A deep-linked identifier (e.g. "Book return" from the stock
        // page) is an on-hand unit — classify and run the lookup so
        // the detected-type badge and stock confirmation both show.
        if ($this->vehicleId !== '') {
            $this->identifierType = VehicleIdentifier::classify($this->vehicleId);
            $this->updatedVehicleId();
        }
    }

    /**
     * Live re-classification as the user types.  Fires from the
     * `wire:model.live.debounce` binding so the "Detected: VIN /
     * Registration" badge updates without a full form round-trip.
     * If the operator has already overridden the type via the switch
     * link we DON'T stomp it -- their choice wins.
     */
    public bool $identifierTypeManuallySet = false;

    public function switchIdentifierType(): void
    {
        $this->identifierType = $this->identifierType === VehicleIdentifier::TYPE_VIN
            ? VehicleIdentifier::TYPE_REGISTRATION
            : VehicleIdentifier::TYPE_VIN;
        $this->identifierTypeManuallySet = true;
    }

    /**
     * As the vehicle identifier is typed, (1) auto-classify it into
     * VIN vs registration so the UI badge stays live, and (2) look
     * it up against the dealer's own (and any group-visible) stock
     * on EITHER column so a plate-only booking still hits the ledger.
     * A hit pre-fills the make/model + the other identifier and
     * confirms what's on hand; a miss just flags it so the user knows
     * they're capturing a new vehicle.  Only runs for dealers -- other
     * tenants have no stock ledger.
     */
    public function updatedVehicleId(): void
    {
        // Re-classify on each keystroke UNLESS the operator has
        // manually switched -- their choice must survive further
        // typing (e.g. they picked "Registration" for a 12-char plate
        // and we mustn't flip it back to VIN when they add a letter).
        if (!$this->identifierTypeManuallySet) {
            $this->identifierType = VehicleIdentifier::classify($this->vehicleId);
        }

        $this->matchedStock = null;
        $this->vehicleIdChecked = false;

        if (!$this->company?->isDealer()) {
            return;
        }

        $needle = VehicleIdentifier::normalise($this->vehicleId);
        // Wait until there's enough to be a real VIN/chassis or plate
        // fragment so we're not querying on the first keystroke.
        if (strlen($needle) < 5) {
            return;
        }

        $this->vehicleIdChecked = true;

        $stock = DealerStock::query()
            ->visibleTo(auth()->user())
            ->where(function ($q) use ($needle) {
                $q->whereRaw('UPPER(vin) = ?', [$needle])
                  ->orWhereRaw('UPPER(COALESCE(registration, \'\')) = ?', [$needle]);
            })
            ->where('status', '!=', DealerStock::STATUS_ARCHIVED)
            ->with('brand:id,name')
            ->first();

        if (!$stock) {
            return;
        }

        // Pre-fill from the stock record.  brandId drives the model
        // suggestion list too, so set it first.  The stock row's VIN
        // is authoritative, so if the user booked by registration we
        // silently pin the VIN into the secondary field.
        $this->brandId = $stock->brand_id;
        $this->modelName = (string) ($stock->model_name ?? '');

        if ($this->identifierType === VehicleIdentifier::TYPE_REGISTRATION) {
            // Primary field is a reg; put the ledger's VIN in the
            // secondary so the resulting job has both.
            if ($stock->vin) {
                $this->secondaryIdentifier = $stock->vin;
            }
        } else {
            // Primary field is a VIN; put the ledger's registration
            // in the secondary so we don't lose it.
            if ($stock->registration && $this->secondaryIdentifier === '') {
                $this->secondaryIdentifier = $stock->registration;
            }
        }

        $this->matchedStock = [
            'brand' => $stock->brand?->name,
            'model' => $stock->model_name,
            'colour' => $stock->colour,
            'registration' => $stock->registration,
            'vin' => $stock->vin,
            'where' => self::STOCK_LOCATION_LABELS[$stock->current_location_type] ?? $stock->current_location_type,
            'status' => ucfirst((string) $stock->status),
        ];
    }

    /** Human labels for the dealer_stock physical buckets. */
    private const STOCK_LOCATION_LABELS = [
        DealerStock::LOCATION_PREMISES     => 'At premises',
        DealerStock::LOCATION_BODY_BUILDER => 'At body builder / fitment',
        DealerStock::LOCATION_STORAGE      => 'At another storage location',
        DealerStock::LOCATION_IN_TRANSIT   => 'In transit',
        DealerStock::LOCATION_ON_DEMO      => 'On demo with customer',
        DealerStock::LOCATION_DELIVERED    => 'Delivered to dealer',
    ];

    public function submit(): void
    {
        // Server-side enforcement of the OEM-only-ProSelver rule. Mount
        // pre-fills the property and the UI hides the picker, but a
        // tampered Livewire payload could still arrive with a different
        // executor — pin it again here before validation so the value
        // can't slip through.
        if ($this->company?->isOem()) {
            $this->executorType = Job::EXECUTOR_PROSELVER;
            $this->internalDriverId = null;
            $this->thirdPartyCourierName = '';
            $this->thirdPartyWaybill = '';
            $this->thirdPartyExpectedDate = '';
            $this->selfCollectName = '';
            $this->selfCollectPhone = '';
            $this->selfCollectIdNumber = '';
        }

        $rules = [
            'pickupLocationId' => 'required|exists:locations,id',
            'deliveryLocationId' => 'required|exists:locations,id|different:pickupLocationId',
            // Only the four user-facing destination types are accepted
            // from the form. The legacy DESTINATION_OTHER value is
            // still valid in the DB for old rows but the picker no
            // longer offers it, so we don't accept it from this form.
            'destinationType' => 'nullable|in:' . implode(',', [
                Job::DESTINATION_DEALER,
                Job::DESTINATION_BODY_BUILDER,
                Job::DESTINATION_ROUND_TRIP,
                Job::DESTINATION_YARD,
            ]),
            'brandId' => 'nullable|exists:brands,id',
            'modelName' => 'nullable|string|max:255',
            // The primary "VIN or registration" field is required;
            // the secondary is optional.  Length caps match the DB
            // columns (`vin string(50)`, `registration string(20)`)
            // so we don't have to guess which column will receive
            // the value until after classification below.
            'vehicleId' => 'required|string|max:50',
            'identifierType' => 'required|in:vin,registration',
            'secondaryIdentifier' => 'nullable|string|max:50',
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

        // Route the "smart" input into the correct column.  If the
        // user detected/confirmed VIN, the secondary field is a plate;
        // if they detected registration, the secondary field is a VIN.
        // Either column may end up null -- BookingService accepts that
        // now, so a plate-only booking is allowed through.
        $primary = VehicleIdentifier::normalise($this->vehicleId);
        $secondary = VehicleIdentifier::normalise($this->secondaryIdentifier);
        if ($this->identifierType === VehicleIdentifier::TYPE_VIN) {
            $vinToSave = $primary ?: null;
            $regToSave = $secondary ?: null;
        } else {
            $vinToSave = $secondary ?: null;
            $regToSave = $primary ?: null;
        }
        // Registration column is only 20 chars; the smart input allows
        // up to 50 for VINs, so if the operator flipped a long value
        // into the "reg" side we cap it here rather than exploding.
        if ($regToSave !== null) {
            $regToSave = substr($regToSave, 0, 20);
        }

        $service = app(BookingService::class);

        $job = $service->createTransportBooking([
            'pickup_location_id' => $this->pickupLocationId,
            'delivery_location_id' => $this->deliveryLocationId,
            'destination_type' => $this->destinationType ?: null,
            'vehicle_class_id' => $this->vehicleClassId,
            'brand_id' => $this->brandId,
            'model_name' => $this->modelName ?: null,
            'vin' => $vinToSave,
            'registration' => $regToSave,
            'scheduled_date' => $this->scheduledDate,
            'scheduled_ready_time' => $this->scheduledReadyTime
                ? $this->scheduledDate . ' ' . $this->scheduledReadyTime
                : null,
            // PO fields only apply when we have a third-party to pay
            // (ProSelver or a courier). For My-Driver / Self-Collect
            // the dealer isn't raising a PO against anyone, so we drop
            // whatever stale value the form happened to carry.
            'po_number' => in_array($this->executorType, [Job::EXECUTOR_PROSELVER, Job::EXECUTOR_THIRD_PARTY], true)
                ? ($this->poNumber ?: null)
                : null,
            'po_amount' => in_array($this->executorType, [Job::EXECUTOR_PROSELVER, Job::EXECUTOR_THIRD_PARTY], true)
                ? $this->poAmount
                : null,
            // Round-trip behaviour is now inferred from the destination
            // type instead of a separate checkbox — picking "Round Trip"
            // doubles the route distance and tells reporting the vehicle
            // came back to pickup.
            'is_round_trip' => $this->destinationType === Job::DESTINATION_ROUND_TRIP,
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
            // Customer-portal bookings bypass the ops PO-verification
            // gate (only relevant for 'faw'-style spreadsheet imports).
            // The dealer typing it in themselves IS the verification.
            'bypass_po_verification' => true,
        ]);

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

        session()->flash('success', 'Movement submitted successfully — reference ' . $job->job_number);
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
                    'description' => 'A ProSelver driver collects and delivers the vehicle for you.',
                    'icon' => 'truck',
                ],
                Job::EXECUTOR_INTERNAL => [
                    'label' => 'My Driver',
                    'description' => "One of your own drivers will move the vehicle.",
                    'icon' => 'user',
                ],
                Job::EXECUTOR_THIRD_PARTY => [
                    'label' => '3rd-Party Transporter',
                    'description' => 'A competing transporter or owner-operator is moving the truck for you.',
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
    <x-slot:header>New Movement</x-slot:header>

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
            {{-- Executor: who is moving this vehicle?
                 OEM tenants are pinned to ProSelver — we skip the chooser
                 entirely for them so the page is a single-purpose booking
                 form.  Dealer / generic-customer tenants see the full
                 four-option picker. Everything below the chooser is
                 conditionally rendered, so hiding it on the OEM path is
                 enough — no other markup needs gating. --}}
            @if(!$company->isOem())
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
                    <div class="mt-4 rounded-lg bg-blue-50 border border-blue-200 p-4">
                        <p class="text-xs text-blue-800 mb-3">
                            <span class="font-semibold">No PO needed</span> &mdash; you're using your own driver,
                            so no purchase order or document upload is required. Just pick the driver (or assign later)
                            and the movement will land straight on their My&nbsp;Day list.
                        </p>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Driver (optional)</label>
                        @if(empty($internalDriverOptions))
                            <p class="text-sm text-gray-600">You haven't added any internal drivers yet. You can still submit the movement and assign a driver later, or go to <a class="font-medium text-blue-600 hover:underline" href="{{ route('customer.drivers.index') }}">Drivers</a> to add one now.</p>
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

                {{-- 3rd-party transporter --}}
                @if($executorType === \App\Models\Job::EXECUTOR_THIRD_PARTY)
                    <div class="mt-4 rounded-lg bg-gray-50 border border-gray-200 p-4 grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div class="sm:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Transporter / Carrier <span class="text-red-500">*</span></label>
                            <input wire:model="thirdPartyCourierName" type="text"
                                class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500"
                                placeholder="Name of the company moving the truck">
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
            @endif {{-- !$company->isOem() --}}

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

                {{-- Destination type — drives Stock In Transit + the
                     archive rule. ONLY "Delivery" (DESTINATION_DEALER
                     / null) can be archived once the job is complete;
                     every other type means the vehicle is still in the
                     dealer's stock somewhere off-site and the order
                     stays active until a follow-up movement delivers
                     it for real. "Round Trip" auto-sets is_round_trip
                     on submit so the route distance is doubled. --}}
                <div class="mt-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">What kind of destination is this?</label>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                        @foreach([
                            \App\Models\Job::DESTINATION_DEALER => ['label' => 'Delivery', 'sub' => 'To another dealer or customer (final)'],
                            \App\Models\Job::DESTINATION_BODY_BUILDER => ['label' => 'Body Builder or Fitment', 'sub' => 'Body builder, radio / canopy fitment, accessories'],
                            \App\Models\Job::DESTINATION_ROUND_TRIP => ['label' => 'Round Trip', 'sub' => 'COF / weighbridge / pre-delivery — driver waits'],
                            \App\Models\Job::DESTINATION_YARD => ['label' => 'Other Storage Facility', 'sub' => 'Off-site storage / holding'],
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
                        <p class="mt-2 text-xs text-amber-700">Body builder / fitment movements (radio, canopy, accessories, full body) keep the vehicle in your <strong>Stock In Transit</strong> view and can't be archived &mdash; book a return movement once the fitment is done.</p>
                    @elseif($destinationType === \App\Models\Job::DESTINATION_ROUND_TRIP)
                        <p class="mt-2 text-xs text-indigo-700">Round trips automatically double the route distance for reporting. The vehicle returns to pickup, so the order isn't archivable but also doesn't park anywhere.</p>
                    @elseif($destinationType === \App\Models\Job::DESTINATION_YARD)
                        <p class="mt-2 text-xs text-amber-700">Off-site storage keeps the vehicle in your <strong>Stock In Transit</strong> view until you book it out to a final Delivery destination.</p>
                    @elseif($destinationType === \App\Models\Job::DESTINATION_DEALER)
                        <p class="mt-2 text-xs text-gray-500">Final delivery to a dealer or customer &mdash; the order can be archived once completed, which hides it from the active list but keeps it in reports.</p>
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
                        @php
                            $isVin = $identifierType === \App\Support\VehicleIdentifier::TYPE_VIN;
                            $ambiguous = \App\Support\VehicleIdentifier::isAmbiguous($vehicleId);
                            $primaryLabel = 'VIN / Registration';
                            $secondaryLabel = $isVin ? 'Registration (if known)' : 'VIN / Chassis (if known)';
                            $secondaryPlaceholder = $isVin ? 'Optional — enter number plate' : 'Optional — enter chassis / VIN';
                        @endphp
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            {{ $primaryLabel }} <span class="text-red-500">*</span>
                        </label>
                        <input wire:model.live.debounce.500ms="vehicleId" type="text" required maxlength="50"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm font-mono uppercase focus:border-blue-500 focus:ring-blue-500"
                            placeholder="Enter VIN, chassis or registration">
                        @error('vehicleId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                        {{-- Detected-type badge with a one-click override.  We
                             deliberately show this even when the field is
                             empty so operators know the feature exists. --}}
                        @if($vehicleId !== '')
                            <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                                <span @class([
                                    'inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-medium',
                                    'bg-blue-100 text-blue-800' => $isVin && !$ambiguous,
                                    'bg-amber-100 text-amber-800' => $ambiguous,
                                    'bg-slate-200 text-slate-800' => !$isVin && !$ambiguous,
                                ])>
                                    @if($ambiguous)
                                        Looks like a VIN — confirm?
                                    @else
                                        Detected: {{ $isVin ? 'VIN / Chassis' : 'Registration' }}
                                    @endif
                                </span>
                                <button type="button" wire:click="switchIdentifierType"
                                    class="text-blue-600 hover:text-blue-800 underline underline-offset-2">
                                    Not right? Switch to {{ $isVin ? 'Registration' : 'VIN' }}
                                </button>
                            </div>
                        @endif

                        @if($matchedStock)
                            <div class="mt-2 flex items-start gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-800">
                                <svg class="h-4 w-4 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                                <span>
                                    <strong>Found in your stock.</strong>
                                    {{ trim(($matchedStock['brand'] ?? '') . ' ' . ($matchedStock['model'] ?? '')) ?: 'Vehicle' }}@if($matchedStock['colour']) · {{ $matchedStock['colour'] }}@endif@if($matchedStock['registration']) · Reg {{ $matchedStock['registration'] }}@endif@if($matchedStock['vin']) · VIN {{ $matchedStock['vin'] }}@endif
                                    <span class="text-emerald-700">— currently {{ $matchedStock['where'] }}.</span>
                                    Details filled in below.
                                </span>
                            </div>
                        @elseif($vehicleIdChecked && $company?->isDealer())
                            <p class="mt-2 text-xs text-slate-500">Not in your stock — capturing as a new vehicle.</p>
                        @endif
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ $secondaryLabel }}</label>
                        <input wire:model="secondaryIdentifier" type="text" maxlength="50"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm font-mono uppercase focus:border-blue-500 focus:ring-blue-500"
                            placeholder="{{ $secondaryPlaceholder }}">
                        @error('secondaryIdentifier') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
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

            {{-- Scheduling & PO. PO fields only render when there's a
                 third-party we're paying — ProSelver (we invoice the
                 dealer) or a 3rd-party transporter (the dealer pays them).
                 For My-Driver and Self-Collect there's no third party
                 to raise a PO against, so the entire PO block hides
                 and the section title simplifies to just "Scheduling". --}}
            @php($needsPo = in_array($executorType, [Job::EXECUTOR_PROSELVER, Job::EXECUTOR_THIRD_PARTY], true))
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">{{ $needsPo ? 'Scheduling & Reference' : 'Scheduling' }}</h3>
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
                    @if($needsPo)
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
                    @endif
                </div>

                {{-- PO Document Upload — same gate as the PO number /
                     amount fields above. Internal-driver / self-collect
                     bookings skip the whole block. --}}
                @if($needsPo)
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
                @endif

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
                    Submit Movement
                </button>
            </div>
        </form>
    @endif
</div>
