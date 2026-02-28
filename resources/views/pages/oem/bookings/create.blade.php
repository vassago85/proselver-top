<?php

use App\Models\Brand;
use App\Models\Location;
use App\Models\VehicleClass;
use App\Services\BookingService;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;

new #[Layout('components.layouts.app')] class extends Component {
    public string $jobType = 'transport';

    public ?int $pickupLocationId = null;
    public ?int $deliveryLocationId = null;
    public ?int $vehicleClassId = null;
    public ?int $brandId = null;
    public string $modelName = '';
    public string $vin = '';
    public string $registration = '';
    public string $scheduledReadyTime = '';

    public ?int $yardLocationId = null;
    public int $driversRequired = 1;
    public float $hoursRequired = 8;

    public string $pickupContactName = '';
    public string $pickupContactPhone = '';
    public string $deliveryContactName = '';
    public string $deliveryContactPhone = '';

    public bool $isEmergency = false;
    public string $emergencyReason = '';

    public bool $showNewPickup = false;
    public bool $showNewDelivery = false;
    public bool $showNewYard = false;

    public string $newLocCompanyName = '';
    public string $newLocAddress = '';
    public string $newLocCity = '';
    public string $newLocProvince = '';
    public string $newLocCustomerName = '';
    public string $newLocCustomerContact = '';
    public string $newLocCustomerPhone = '';
    public string $newLocCustomerEmail = '';
    public bool $newLocIsPrivate = false;

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
            'is_private' => $this->newLocIsPrivate,
            'address' => $this->newLocAddress,
            'city' => $this->newLocCity ?: null,
            'province' => $this->newLocProvince ?: null,
            'customer_name' => $this->newLocCustomerName ?: null,
            'customer_contact' => $this->newLocCustomerContact ?: null,
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

        $this->newLocCompanyName = '';
        $this->newLocAddress = '';
        $this->newLocCity = '';
        $this->newLocProvince = '';
        $this->newLocCustomerName = '';
        $this->newLocCustomerContact = '';
        $this->newLocCustomerPhone = '';
        $this->newLocCustomerEmail = '';
        $this->newLocIsPrivate = false;
    }

    public function submit(BookingService $bookingService): void
    {
        $company = auth()->user()->company();
        if (!$company) {
            session()->flash('error', 'No company linked to your account.');
            return;
        }

        $rules = [];

        if ($this->jobType === 'transport') {
            $rules += [
                'pickupLocationId' => 'required|exists:locations,id',
                'deliveryLocationId' => 'required|exists:locations,id|different:pickupLocationId',
                'vehicleClassId' => 'required|exists:vehicle_classes,id',
                'vin' => 'required|string|min:7|max:17',
            ];
        } else {
            $rules += [
                'yardLocationId' => 'required|exists:locations,id',
                'driversRequired' => 'required|integer|min:1',
                'hoursRequired' => 'required|numeric|min:0.5',
            ];
        }

        $this->validate($rules);

        $data = [
            'company_id' => $company->id,
            'created_by_user_id' => auth()->id(),
        ];

        if ($this->jobType === 'transport') {
            $data += [
                'pickup_location_id' => $this->pickupLocationId,
                'pickup_contact_name' => $this->pickupContactName ?: null,
                'pickup_contact_phone' => $this->pickupContactPhone ?: null,
                'delivery_location_id' => $this->deliveryLocationId,
                'delivery_contact_name' => $this->deliveryContactName ?: null,
                'delivery_contact_phone' => $this->deliveryContactPhone ?: null,
                'vehicle_class_id' => $this->vehicleClassId,
                'brand_id' => $this->brandId,
                'model_name' => $this->modelName,
                'vin' => $this->vin,
                'registration' => $this->registration ?: null,
                'scheduled_ready_time' => $this->scheduledReadyTime ? now()->toDateString() . ' ' . $this->scheduledReadyTime : null,
                'is_emergency' => $this->isEmergency,
                'emergency_reason' => $this->emergencyReason,
            ];
            $job = $bookingService->createTransportBooking($data);
        } else {
            $data += [
                'yard_location_id' => $this->yardLocationId,
                'drivers_required' => $this->driversRequired,
                'hours_required' => $this->hoursRequired,
            ];
            $job = $bookingService->createYardBooking($data);
        }

        session()->flash('success', "Booking {$job->job_number} created successfully.");
        $this->redirect(route('oem.bookings.show', $job));
    }

    public function with(): array
    {
        $company = auth()->user()->company();
        return [
            'locations' => $company ? Location::visibleTo($company)->active()->orderBy('company_name')->get(['id', 'company_name', 'city', 'address']) : collect(),
            'vehicleClasses' => VehicleClass::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'brands' => Brand::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ];
    }
};
?>

<div>
    <x-slot:header>New Booking</x-slot:header>

    <form wire:submit="submit" class="max-w-3xl">
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Booking Type</h3>
            <div class="flex gap-4">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="radio" wire:model.live="jobType" value="transport" class="h-4 w-4 text-blue-600">
                    <span class="text-sm font-medium">Transport</span>
                </label>
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="radio" wire:model.live="jobType" value="yard_work" class="h-4 w-4 text-blue-600">
                    <span class="text-sm font-medium">Yard Work</span>
                </label>
            </div>
        </div>

        @if($jobType === 'transport')
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Transport Details</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Pickup Location *</label>
                    <select wire:model="pickupLocationId" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm">
                        <option value="">Select pickup...</option>
                        @foreach($locations as $loc)<option value="{{ $loc->id }}">{{ $loc->company_name }}{{ $loc->city ? " ({$loc->city})" : '' }}</option>@endforeach
                    </select>
                    @error('pickupLocationId')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    <button type="button" wire:click="$toggle('showNewPickup')" class="mt-1 text-xs text-blue-600 hover:underline">+ Add New Location</button>
                    @if($showNewPickup)
                        @include('partials.new-location-form', ['target' => 'pickup'])
                    @endif
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Delivery Location *</label>
                    <select wire:model="deliveryLocationId" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm">
                        <option value="">Select delivery...</option>
                        @foreach($locations as $loc)<option value="{{ $loc->id }}">{{ $loc->company_name }}{{ $loc->city ? " ({$loc->city})" : '' }}</option>@endforeach
                    </select>
                    @error('deliveryLocationId')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    <button type="button" wire:click="$toggle('showNewDelivery')" class="mt-1 text-xs text-blue-600 hover:underline">+ Add New Location</button>
                    @if($showNewDelivery)
                        @include('partials.new-location-form', ['target' => 'delivery'])
                    @endif
                </div>
            </div>

            <details class="mt-4 group">
                <summary class="text-sm font-medium text-gray-600 cursor-pointer hover:text-gray-900">Alternate contact person (optional)</summary>
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

            <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Vehicle Class *</label>
                    <select wire:model="vehicleClassId" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm">
                        <option value="">Select class...</option>
                        @foreach($vehicleClasses as $vc)<option value="{{ $vc->id }}">{{ $vc->name }}</option>@endforeach
                    </select>
                    @error('vehicleClassId')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Brand</label>
                    <select wire:model="brandId" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm">
                        <option value="">Select brand...</option>
                        @foreach($brands as $brand)<option value="{{ $brand->id }}">{{ $brand->name }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Model</label>
                    <input wire:model="modelName" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm" placeholder="e.g. Actros 2645">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">VIN / Chassis *</label>
                    <input wire:model="vin" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm font-mono uppercase" placeholder="Full VIN number" maxlength="17">
                    @error('vin')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Registration</label>
                    <input wire:model="registration" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm uppercase" placeholder="Optional">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Ready Time</label>
                    <input wire:model="scheduledReadyTime" type="time" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm">
                </div>
            </div>
            <div class="mt-4">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input wire:model.live="isEmergency" type="checkbox" class="h-4 w-4 rounded border-gray-300 text-red-600">
                    <span class="text-sm font-medium text-red-700">Emergency booking</span>
                </label>
                @if($isEmergency)
                <div class="mt-2">
                    <textarea wire:model="emergencyReason" rows="2" placeholder="Emergency reason..." class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"></textarea>
                </div>
                @endif
            </div>
        </div>
        @else
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
                    @if($showNewYard)
                        @include('partials.new-location-form', ['target' => 'yard'])
                    @endif
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
                <span wire:loading.remove>Submit Booking</span>
                <span wire:loading>Submitting...</span>
            </button>
        </div>
    </form>
</div>
