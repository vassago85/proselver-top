<?php

use App\Models\Brand;
use App\Models\Company;
use App\Models\Job;
use App\Models\Location;
use App\Models\VehicleClass;
use App\Services\BookingService;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;

new #[Layout('components.layouts.app')] class extends Component {
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
    public string $poNumber = '';
    public ?string $poAmount = null;
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
            'scheduledDate' => 'required|date|after:today',
            'poNumber' => 'nullable|string|max:100',
            'poAmount' => 'nullable|numeric|min:0',
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
            'po_number' => $this->poNumber ?: null,
            'po_amount' => $this->poAmount,
            'company_id' => $this->company->id,
            'created_by_user_id' => auth()->id(),
        ]);

        $job->status = Job::STATUS_RECEIVED;
        $job->save();

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

        return [
            'companyLocations' => $companyLocations,
            'sharedLocations' => $sharedLocations,
            'brands' => $brands,
            'vehicleClasses' => VehicleClass::where('is_active', true)->orderBy('name')->get(['id', 'name']),
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
                        <select wire:model.live="pickupLocationId" required
                            class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="">Select pickup location</option>
                            @if($companyLocations->isNotEmpty())
                                <optgroup label="My Locations">
                                    @foreach($companyLocations as $loc)
                                        <option value="{{ $loc->id }}">{{ $loc->company_name }}{{ $loc->city ? " — {$loc->city}" : '' }}</option>
                                    @endforeach
                                </optgroup>
                            @endif
                            @if($sharedLocations->isNotEmpty())
                                <optgroup label="Shared Depots">
                                    @foreach($sharedLocations as $loc)
                                        <option value="{{ $loc->id }}">{{ $loc->company_name }}{{ $loc->city ? " — {$loc->city}" : '' }}</option>
                                    @endforeach
                                </optgroup>
                            @endif
                        </select>
                        @error('pickupLocationId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Delivery Location <span class="text-red-500">*</span></label>
                        <select wire:model.live="deliveryLocationId" required
                            class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="">Select delivery location</option>
                            @if($companyLocations->isNotEmpty())
                                <optgroup label="My Locations">
                                    @foreach($companyLocations as $loc)
                                        <option value="{{ $loc->id }}">{{ $loc->company_name }}{{ $loc->city ? " — {$loc->city}" : '' }}</option>
                                    @endforeach
                                </optgroup>
                            @endif
                            @if($sharedLocations->isNotEmpty())
                                <optgroup label="Shared Depots">
                                    @foreach($sharedLocations as $loc)
                                        <option value="{{ $loc->id }}">{{ $loc->company_name }}{{ $loc->city ? " — {$loc->city}" : '' }}</option>
                                    @endforeach
                                </optgroup>
                            @endif
                        </select>
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
                        <select wire:model="brandId"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="">Select brand</option>
                            @foreach($brands as $brand)
                                <option value="{{ $brand->id }}">{{ $brand->name }}</option>
                            @endforeach
                        </select>
                        @error('brandId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Model Name</label>
                        <input wire:model="modelName" type="text"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500"
                            placeholder="e.g. Ranger, Hilux">
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
                        <select wire:model="vehicleClassId" required
                            class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="">Select vehicle class</option>
                            @foreach($vehicleClasses as $vc)
                                <option value="{{ $vc->id }}">{{ $vc->name }}</option>
                            @endforeach
                        </select>
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
                        <input wire:model="scheduledDate" type="date" required min="{{ now()->addDay()->format('Y-m-d') }}"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                        @error('scheduledDate') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
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
