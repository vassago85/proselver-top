<?php

use App\Models\Brand;
use App\Models\DealerStock;
use App\Models\Job;
use App\Models\Location;
use App\Models\VehicleClass;
use App\Models\VehicleModel;
use App\Services\BookingService;
use App\Support\VehicleIdentifier;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/*
 * BB-side direct order form.  Mirrors customer.orders.create but
 * trimmed for the BB context:
 *
 *   - Executor is always ProSelver -- the whole point of "direct
 *     order" is that the BB hires us to move the vehicle.
 *   - No PO panel.  Proselver invoices the BB on the back of the
 *     job; the BB doesn't raise a PO against themselves.
 *   - VIN live-lookup against ANY dealer's stock (the BB is using
 *     this to ship a finished build, so the VIN almost always
 *     matches a dealer's books).  A hit pre-fills the make / model /
 *     registration AND tells the BB that the dealer will get a
 *     pending-approval notice when they submit.
 *
 * Permission: bb_place_direct_order.
 */
new #[Layout('components.layouts.body-builder')] class extends Component
{
    public ?int $pickupLocationId = null;
    public ?int $deliveryLocationId = null;
    // Smart "VIN OR registration" primary input.  See
    // App\Support\VehicleIdentifier for the classifier + rationale.
    public string $vehicleId = '';
    public string $identifierType = VehicleIdentifier::TYPE_VIN;
    public string $secondaryIdentifier = '';
    public bool $identifierTypeManuallySet = false;
    public ?int $brandId = null;
    public string $modelName = '';
    public ?int $vehicleClassId = null;
    public string $scheduledDate = '';
    public string $scheduledReadyTime = '';
    public string $notes = '';

    public ?array $matchedStock = null;
    public bool $vehicleIdChecked = false;

    public function mount(): void
    {
        $u = auth()->user();
        abort_unless(
            $u && $u->companyIsBodyBuilder() && $u->hasPermission('bb_place_direct_order'),
            403,
            'You don\'t have permission to place direct orders.',
        );

        // Pre-fill from query string -- e.g. a deep link from the
        // yard show page ("Send for crane fitment") can drop the user
        // straight onto a half-filled form.  Accept either `vin=` or
        // `registration=` -- the input is agnostic now.
        $deepId = request()->input('vin') ?: request()->input('registration');
        if ($deepId) {
            $this->vehicleId = (string) $deepId;
        }
        if (request()->filled('pickup_location_id')) {
            $id = (int) request('pickup_location_id');
            if (Location::where('id', $id)->exists()) {
                $this->pickupLocationId = $id;
            }
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

        if ($this->vehicleId !== '') {
            $this->identifierType = VehicleIdentifier::classify($this->vehicleId);
            $this->updatedVehicleId();
        }
    }

    public function switchIdentifierType(): void
    {
        $this->identifierType = $this->identifierType === VehicleIdentifier::TYPE_VIN
            ? VehicleIdentifier::TYPE_REGISTRATION
            : VehicleIdentifier::TYPE_VIN;
        $this->identifierTypeManuallySet = true;
    }

    /**
     * Vehicle-identifier typeahead lookup against the GLOBAL stock
     * ledger.  Unlike the dealer-side form (which scopes to
     * visibleTo()), a BB needs to see ownership for ANY dealer
     * because they might be shipping a build that originated
     * outside their normal dealer list.  Matches on either VIN or
     * registration so a plate-only booking still finds the owner
     * and triggers the approval gate.
     *
     * The lookup is read-only -- it just tells the BB "this VIN /
     * plate belongs to X, dealer X will get an approval ping".
     */
    public function updatedVehicleId(): void
    {
        if (!$this->identifierTypeManuallySet) {
            $this->identifierType = VehicleIdentifier::classify($this->vehicleId);
        }

        $this->matchedStock = null;
        $this->vehicleIdChecked = false;

        $needle = VehicleIdentifier::normalise($this->vehicleId);
        if (strlen($needle) < 5) {
            return;
        }

        $this->vehicleIdChecked = true;

        $stock = DealerStock::query()
            ->where(function ($q) use ($needle) {
                $q->whereRaw('UPPER(vin) = ?', [$needle])
                  ->orWhereRaw('UPPER(COALESCE(registration, \'\')) = ?', [$needle]);
            })
            ->whereNotNull('dealer_company_id')
            ->where('status', '!=', DealerStock::STATUS_ARCHIVED)
            ->with(['brand:id,name', 'dealerCompany:id,name'])
            ->first();

        if (!$stock) {
            return;
        }

        $this->brandId = $stock->brand_id;
        $this->modelName = (string) ($stock->model_name ?? '');

        if ($this->identifierType === VehicleIdentifier::TYPE_REGISTRATION) {
            if ($stock->vin && $this->secondaryIdentifier === '') {
                $this->secondaryIdentifier = $stock->vin;
            }
        } else {
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
            'dealer_name' => $stock->dealerCompany?->name,
            'dealer_id' => $stock->dealer_company_id,
        ];
    }

    public function submit(): void
    {
        $this->validate([
            'pickupLocationId' => 'required|exists:locations,id',
            'deliveryLocationId' => 'required|exists:locations,id|different:pickupLocationId',
            'vehicleId' => 'required|string|max:50',
            'identifierType' => 'required|in:vin,registration',
            'secondaryIdentifier' => 'nullable|string|max:50',
            'brandId' => 'nullable|exists:brands,id',
            'modelName' => 'nullable|string|max:255',
            'vehicleClassId' => 'required|exists:vehicle_classes,id',
            'scheduledDate' => 'required|date|after_or_equal:today',
            'scheduledReadyTime' => 'nullable|date_format:H:i',
            'notes' => 'nullable|string|max:2000',
        ]);

        $primary = VehicleIdentifier::normalise($this->vehicleId);
        $secondary = VehicleIdentifier::normalise($this->secondaryIdentifier);
        if ($this->identifierType === VehicleIdentifier::TYPE_VIN) {
            $vinToSave = $primary ?: null;
            $regToSave = $secondary ?: null;
        } else {
            $vinToSave = $secondary ?: null;
            $regToSave = $primary ?: null;
        }
        if ($regToSave !== null) {
            $regToSave = substr($regToSave, 0, 20);
        }

        $company = auth()->user()->company();

        $job = app(BookingService::class)->createTransportBooking([
            'pickup_location_id'   => $this->pickupLocationId,
            'delivery_location_id' => $this->deliveryLocationId,
            'destination_type'     => Job::DESTINATION_DEALER, // generic "deliver to here"
            'vehicle_class_id'     => $this->vehicleClassId,
            'brand_id'             => $this->brandId,
            'model_name'           => $this->modelName ?: null,
            'vin'                  => $vinToSave,
            'registration'         => $regToSave,
            'scheduled_date'       => $this->scheduledDate,
            'scheduled_ready_time' => $this->scheduledReadyTime
                ? $this->scheduledDate . ' ' . $this->scheduledReadyTime
                : null,
            'company_id'           => $company->id,
            'created_by_user_id'   => auth()->id(),
            'customer_notes'       => $this->notes ?: null,
            'executor_type'        => Job::EXECUTOR_PROSELVER,
            'bypass_po_verification' => true,
        ]);

        session()->flash('success', $job->isPendingOwnerApproval()
            ? "Order placed -- {$this->matchedStock['dealer_name']} has been notified to approve the move."
            : "Order placed successfully (reference {$job->job_number}).");
        $this->redirect(route('body-builder.orders.show', $job), navigate: true);
    }

    public function with(): array
    {
        $company = auth()->user()->company();

        // Pickup options: BB's own workshops first, then shared depots,
        // then everything else (so the BB can ship FROM, say, a dealer
        // location too if the chassis was dropped at the dealer first).
        $ownLocations = Location::where('company_id', $company->id)
            ->where('is_active', true)
            ->orderBy('company_name')
            ->get(['id', 'company_name', 'city']);

        $allLocations = Location::where('is_active', true)
            ->whereNotIn('id', $ownLocations->pluck('id'))
            ->orderBy('company_name')
            ->get(['id', 'company_name', 'city']);

        $fmt = fn ($l) => [
            'value' => (string) $l->id,
            'label' => $l->company_name . ($l->city ? " — {$l->city}" : ''),
        ];

        $locationGroups = [];
        if ($ownLocations->isNotEmpty()) {
            $locationGroups[] = ['label' => 'My workshops', 'options' => $ownLocations->map($fmt)->values()->all()];
        }
        if ($allLocations->isNotEmpty()) {
            $locationGroups[] = ['label' => 'All other locations', 'options' => $allLocations->map($fmt)->values()->all()];
        }

        $brands = Brand::where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $brandOptions = $brands->map(fn ($b) => ['value' => (string) $b->id, 'label' => $b->name])->all();

        $modelsQuery = VehicleModel::where('is_active', true)->orderBy('name');
        if ($this->brandId) {
            $modelsQuery->where('brand_id', $this->brandId);
        }
        $vehicleModels = $modelsQuery->get(['id', 'brand_id', 'name']);

        $vehicleClasses = VehicleClass::where('is_active', true)->ordered()->get(['id', 'name']);
        $vehicleClassOptions = $vehicleClasses->map(fn ($vc) => ['value' => (string) $vc->id, 'label' => $vc->name])->all();

        return [
            'locationGroups'      => $locationGroups,
            'brandOptions'        => $brandOptions,
            'vehicleClassOptions' => $vehicleClassOptions,
            'vehicleModels'       => $vehicleModels,
            'brands'              => $brands,
        ];
    }
}; ?>

<x-slot:header>Place an order</x-slot:header>

<div>
    <div class="mb-4 flex items-center justify-between">
        <h1 class="text-lg font-semibold text-slate-900">Place a direct order with Proselver</h1>
        <a href="{{ route('body-builder.orders.index') }}" class="text-xs font-semibold text-slate-500 hover:text-slate-700">Cancel</a>
    </div>

    <form wire:submit.prevent="submit" class="space-y-5">

        {{-- VIN / Registration + live lookup --}}
        @php
            $isVin = $identifierType === \App\Support\VehicleIdentifier::TYPE_VIN;
            $ambiguous = \App\Support\VehicleIdentifier::isAmbiguous($vehicleId);
        @endphp
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <label class="block text-xs font-semibold uppercase tracking-wide text-slate-600">VIN / Registration</label>
            <input wire:model.live.debounce.400ms="vehicleId" type="text" maxlength="50"
                placeholder="VIN, chassis or registration"
                class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-3 text-base font-mono uppercase focus:border-blue-500 focus:ring-blue-500">
            @error('vehicleId') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror

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

            @if($vehicleIdChecked && $matchedStock)
                <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm">
                    <div class="font-semibold text-amber-900">
                        Vehicle belongs to {{ $matchedStock['dealer_name'] }}
                    </div>
                    <div class="text-xs text-amber-800 mt-0.5">
                        {{ $matchedStock['brand'] }} {{ $matchedStock['model'] }}{{ $matchedStock['colour'] ? ' · ' . $matchedStock['colour'] : '' }}
                        @if($matchedStock['vin']) · VIN {{ $matchedStock['vin'] }}@endif
                        @if($matchedStock['registration']) · Reg {{ $matchedStock['registration'] }}@endif
                    </div>
                    <p class="mt-2 text-xs text-amber-700">
                        When you submit, {{ $matchedStock['dealer_name'] }} will be notified and must approve the
                        movement before Proselver dispatches. They won't see the price.
                    </p>
                </div>
            @elseif($vehicleIdChecked && !$matchedStock && strlen($vehicleId) >= 5)
                <div class="mt-2 text-xs text-slate-500">
                    No dealer on the platform owns this vehicle -- the order goes straight through without an
                    owner-approval step.
                </div>
            @endif
        </div>

        {{-- Vehicle details --}}
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm space-y-3">
            <h2 class="text-xs font-semibold uppercase tracking-wide text-slate-600">Vehicle</h2>

            <div>
                <label class="block text-xs font-medium text-slate-600">Brand</label>
                <x-searchable-select
                    wire:model.live="brandId"
                    :options="$brandOptions"
                    placeholder="Pick a brand…"
                    class="mt-1"
                />
                @error('brandId') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-xs font-medium text-slate-600">Model</label>
                <input wire:model="modelName" list="bbOrderModelList" type="text"
                    class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500"
                    placeholder="Model name">
                <datalist id="bbOrderModelList">
                    @foreach($vehicleModels as $m)
                        <option value="{{ $m->name }}">
                    @endforeach
                </datalist>
            </div>

            <div>
                <label class="block text-xs font-medium text-slate-600">Vehicle class</label>
                <x-searchable-select
                    wire:model.live="vehicleClassId"
                    :options="$vehicleClassOptions"
                    placeholder="Pick a class…"
                    class="mt-1"
                />
                @error('vehicleClassId') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>

            <div>
                @php
                    $secondaryLabel = $isVin ? 'Registration' : 'VIN / Chassis';
                    $secondaryPlaceholder = $isVin ? 'ABC 123 GP' : 'Chassis / VIN';
                @endphp
                <label class="block text-xs font-medium text-slate-600">
                    {{ $secondaryLabel }} <span class="text-slate-400 font-normal">(optional)</span>
                </label>
                <input wire:model="secondaryIdentifier" type="text" maxlength="50"
                    class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm font-mono uppercase focus:border-blue-500 focus:ring-blue-500"
                    placeholder="{{ $secondaryPlaceholder }}">
                @error('secondaryIdentifier') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- Pickup + delivery --}}
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm space-y-3">
            <h2 class="text-xs font-semibold uppercase tracking-wide text-slate-600">Where</h2>

            <div>
                <label class="block text-xs font-medium text-slate-600">Pickup from</label>
                <x-searchable-select
                    wire:model.live="pickupLocationId"
                    :options="$locationGroups"
                    placeholder="Pickup location…"
                    class="mt-1"
                />
                @error('pickupLocationId') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-xs font-medium text-slate-600">Deliver to</label>
                <x-searchable-select
                    wire:model.live="deliveryLocationId"
                    :options="$locationGroups"
                    placeholder="Delivery location…"
                    class="mt-1"
                />
                @error('deliveryLocationId') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- When --}}
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm space-y-3">
            <h2 class="text-xs font-semibold uppercase tracking-wide text-slate-600">When</h2>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-medium text-slate-600">Date</label>
                    <input wire:model="scheduledDate" type="date" min="{{ now()->toDateString() }}"
                        class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                    @error('scheduledDate') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600">Ready by <span class="text-slate-400 font-normal">(optional)</span></label>
                    <input wire:model="scheduledReadyTime" type="time"
                        class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
            </div>
        </div>

        {{-- Notes --}}
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <label class="block text-xs font-semibold uppercase tracking-wide text-slate-600">Notes for Proselver</label>
            <textarea wire:model="notes" rows="3"
                class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500"
                placeholder="Anything Proselver should know -- crane access, gate code, contact on site…"></textarea>
        </div>

        <button type="submit"
            class="w-full rounded-xl bg-blue-600 px-4 py-3.5 text-base font-semibold text-white shadow-sm hover:bg-blue-500"
            wire:loading.attr="disabled">
            <span wire:loading.remove>Place order</span>
            <span wire:loading>Placing…</span>
        </button>

        <p class="text-xs text-slate-500 text-center">
            Proselver will invoice you for this movement.  If the vehicle is on a dealer's stock ledger, the
            dealer must approve before dispatch.
        </p>
    </form>
</div>
