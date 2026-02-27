<?php

use App\Models\Brand;
use App\Models\Hub;
use App\Models\VehicleClass;
use App\Services\BookingService;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.app')] class extends Component {
    use WithFileUploads;

    public string $jobType = 'transport';

    // Transport fields
    public ?int $fromHubId = null;
    public ?int $toHubId = null;
    public ?int $vehicleClassId = null;
    public ?int $brandId = null;
    public string $modelName = '';
    public string $vin = '';
    public string $scheduledDate = '';
    public string $scheduledReadyTime = '';

    // Yard fields
    public ?int $yardHubId = null;
    public int $driversRequired = 1;
    public float $hoursRequired = 8;

    // Common
    public string $poNumber = '';
    public string $poAmount = '';
    public $poFile;
    public bool $isEmergency = false;
    public string $emergencyReason = '';

    public function submit(BookingService $bookingService): void
    {
        $company = auth()->user()->company();
        if (!$company) {
            session()->flash('error', 'No company linked to your account.');
            return;
        }

        $rules = [
            'poNumber' => 'required|string|max:50',
            'poAmount' => 'required|numeric|min:0.01',
            'poFile' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'scheduledDate' => 'required|date|after:today',
        ];

        if ($this->jobType === 'transport') {
            $rules += [
                'fromHubId' => 'required|exists:hubs,id',
                'toHubId' => 'required|exists:hubs,id|different:fromHubId',
                'vehicleClassId' => 'required|exists:vehicle_classes,id',
            ];
        } else {
            $rules += [
                'yardHubId' => 'required|exists:hubs,id',
                'driversRequired' => 'required|integer|min:1',
                'hoursRequired' => 'required|numeric|min:0.5',
            ];
        }

        $this->validate($rules);

        $data = [
            'company_id' => $company->id,
            'created_by_user_id' => auth()->id(),
            'po_number' => $this->poNumber,
            'po_amount' => $this->poAmount,
            'scheduled_date' => $this->scheduledDate,
        ];

        if ($this->jobType === 'transport') {
            $data += [
                'from_hub_id' => $this->fromHubId,
                'to_hub_id' => $this->toHubId,
                'vehicle_class_id' => $this->vehicleClassId,
                'brand_id' => $this->brandId,
                'model_name' => $this->modelName,
                'vin' => $this->vin,
                'scheduled_ready_time' => $this->scheduledDate . ' ' . ($this->scheduledReadyTime ?: '08:00'),
                'is_emergency' => $this->isEmergency,
                'emergency_reason' => $this->emergencyReason,
            ];
            $job = $bookingService->createTransportBooking($data);
        } else {
            $data += [
                'yard_hub_id' => $this->yardHubId,
                'drivers_required' => $this->driversRequired,
                'hours_required' => $this->hoursRequired,
            ];
            $job = $bookingService->createYardBooking($data);
        }

        // Upload PO document
        if ($this->poFile) {
            $disk = config('filesystems.default') === 'local' ? 'local' : 'r2';
            $path = $this->poFile->store('jobs/' . $job->uuid . '/documents', $disk);

            \App\Models\JobDocument::create([
                'job_id' => $job->id,
                'uploaded_by_user_id' => auth()->id(),
                'category' => 'purchase_order',
                'disk' => $disk,
                'path' => $path,
                'original_filename' => $this->poFile->getClientOriginalName(),
                'mime_type' => $this->poFile->getMimeType(),
                'size_bytes' => $this->poFile->getSize(),
                'file_hash' => hash_file('sha256', $this->poFile->getRealPath()),
            ]);
        }

        session()->flash('success', "Booking {$job->job_number} created successfully.");
        $this->redirect(route('dealer.bookings.show', $job));
    }

    public function with(): array
    {
        return [
            'hubs' => Hub::where('is_active', true)->orderBy('name')->get(['id', 'name', 'city']),
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
                    <label class="block text-sm font-medium text-gray-700 mb-1">From Hub *</label>
                    <select wire:model="fromHubId" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm">
                        <option value="">Select origin...</option>
                        @foreach($hubs as $hub)<option value="{{ $hub->id }}">{{ $hub->name }}{{ $hub->city ? " ({$hub->city})" : '' }}</option>@endforeach
                    </select>
                    @error('fromHubId')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">To Hub *</label>
                    <select wire:model="toHubId" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm">
                        <option value="">Select destination...</option>
                        @foreach($hubs as $hub)<option value="{{ $hub->id }}">{{ $hub->name }}{{ $hub->city ? " ({$hub->city})" : '' }}</option>@endforeach
                    </select>
                    @error('toHubId')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
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
                    <input wire:model="modelName" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm" placeholder="e.g. NQR 500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">VIN / Chassis</label>
                    <input wire:model="vin" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm font-mono" placeholder="Optional">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Scheduled Ready Time</label>
                    <input wire:model="scheduledReadyTime" type="time" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm">
                </div>
            </div>
            <div class="mt-4">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input wire:model.live="isEmergency" type="checkbox" class="h-4 w-4 rounded border-gray-300 text-red-600">
                    <span class="text-sm font-medium text-red-700">Emergency booking (bypasses cut-off rules)</span>
                </label>
                @if($isEmergency)
                <div class="mt-2">
                    <textarea wire:model="emergencyReason" rows="2" placeholder="Emergency reason (required)..." class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"></textarea>
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
                    <select wire:model="yardHubId" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm">
                        <option value="">Select yard...</option>
                        @foreach($hubs as $hub)<option value="{{ $hub->id }}">{{ $hub->name }}</option>@endforeach
                    </select>
                    @error('yardHubId')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
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

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Purchase Order</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Scheduled Date *</label>
                    <input wire:model="scheduledDate" type="date" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm">
                    @error('scheduledDate')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">PO Number *</label>
                    <input wire:model="poNumber" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm">
                    @error('poNumber')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">PO Amount (ZAR) *</label>
                    <input wire:model="poAmount" type="number" step="0.01" min="0.01" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm">
                    @error('poAmount')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">PO Document *</label>
                    <input wire:model="poFile" type="file" accept=".pdf,.jpg,.jpeg,.png" class="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                    @error('poFile')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
            </div>
        </div>

        <div class="flex justify-end gap-3">
            <a href="{{ route('dealer.bookings.index') }}" class="rounded-lg border border-gray-300 bg-white px-6 py-3 text-sm font-semibold text-gray-700 hover:bg-gray-50">Cancel</a>
            <button type="submit" class="rounded-lg bg-blue-600 px-6 py-3 text-sm font-semibold text-white hover:bg-blue-500" wire:loading.attr="disabled">
                <span wire:loading.remove>Submit Booking</span>
                <span wire:loading>Submitting...</span>
            </button>
        </div>
    </form>
</div>
