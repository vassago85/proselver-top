<?php

use App\Models\Brand;
use App\Models\Company;
use App\Models\Job;
use App\Models\Location;
use App\Models\PurchaseOrder;
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

    public function mount(): void
    {
        $this->company = auth()->user()->company();
        abort_unless($this->company, 403, 'No company associated with your account.');

        $this->hasLocations = Location::where('company_id', $this->company->id)
            ->where('is_active', true)
            ->exists();
    }

    public function submit(): void
    {
        $this->validate([
            'pickupLocationId' => 'required|exists:locations,id',
            'deliveryLocationId' => 'required|exists:locations,id|different:pickupLocationId',
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
        ]);

        $service = app(BookingService::class);

        $job = $service->createTransportBooking([
            'pickup_location_id' => $this->pickupLocationId,
            'delivery_location_id' => $this->deliveryLocationId,
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

        $vehicleClasses = VehicleClass::where('is_active', true)->orderBy('name')->get(['id', 'name']);

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
