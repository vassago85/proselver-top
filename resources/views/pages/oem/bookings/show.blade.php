<?php
use App\Models\Brand;
use App\Models\Job;
use App\Models\PurchaseOrder;
use App\Services\AuditService;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.app')] class extends Component {
    use WithFileUploads;

    public Job $job;

    public bool $showPoForm = false;
    public string $poNumber = '';
    public string $poAmount = '';
    public string $poLabel = '';
    public $poFile;

    public bool $showReassignForm = false;
    public ?int $reassignBrandId = null;
    public string $reassignModelName = '';
    public string $reassignVin = '';
    public string $reassignRegistration = '';
    public string $reassignPoNumber = '';
    public string $reassignPoAmount = '';
    public $reassignPoFile;

    public function mount(Job $job): void
    {
        $company = auth()->user()->company();
        if (!$company || $job->company_id !== $company->id) {
            abort(403);
        }
        $this->job = $job->load(['driver:id,name,phone', 'pickupLocation', 'deliveryLocation', 'yardLocation', 'events', 'documents', 'createdBy:id,name', 'brand:id,name', 'purchaseOrders.uploadedBy:id,name']);
    }

    public function uploadPo(): void
    {
        $this->validate([
            'poNumber' => 'required|string|max:50',
            'poAmount' => 'required|numeric|min:0.01',
            'poFile' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        $disk = config('filesystems.default') === 'local' ? 'local' : 'r2';
        $path = $this->poFile->store('jobs/' . $this->job->uuid . '/po', $disk);

        PurchaseOrder::create([
            'job_id' => $this->job->id,
            'po_number' => $this->poNumber,
            'po_amount' => $this->poAmount,
            'label' => $this->poLabel ?: null,
            'document_disk' => $disk,
            'document_path' => $path,
            'original_filename' => $this->poFile->getClientOriginalName(),
            'uploaded_by_user_id' => auth()->id(),
        ]);

        $this->showPoForm = false;
        $this->poNumber = '';
        $this->poAmount = '';
        $this->poLabel = '';
        $this->poFile = null;
        $this->refreshJob();
        session()->flash('success', 'Purchase order uploaded successfully.');
    }

    public function reassignVehicle(): void
    {
        $this->validate([
            'reassignVin' => 'required|string|min:7|max:17',
            'reassignPoNumber' => 'required|string|max:50',
            'reassignPoAmount' => 'required|numeric|min:0.01',
            'reassignPoFile' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        $canReassign = in_array($this->job->status, [
            Job::STATUS_PENDING_VERIFICATION,
            Job::STATUS_VERIFIED,
            Job::STATUS_APPROVED,
            Job::STATUS_ASSIGNED,
        ]);

        if (!$canReassign) {
            session()->flash('error', 'Vehicle cannot be reassigned at this stage.');
            return;
        }

        $originalVin = $this->job->vin;

        $this->job->update([
            'brand_id' => $this->reassignBrandId,
            'model_name' => $this->reassignModelName ?: null,
            'vin' => strtoupper($this->reassignVin),
            'registration' => $this->reassignRegistration ? strtoupper($this->reassignRegistration) : null,
            'original_vin' => $this->job->original_vin ?? $originalVin,
            'vehicle_reassigned_at' => now(),
            'vehicle_reassigned_by' => auth()->id(),
        ]);

        $disk = config('filesystems.default') === 'local' ? 'local' : 'r2';
        $path = $this->reassignPoFile->store('jobs/' . $this->job->uuid . '/po', $disk);

        PurchaseOrder::create([
            'job_id' => $this->job->id,
            'po_number' => $this->reassignPoNumber,
            'po_amount' => $this->reassignPoAmount,
            'label' => 'Vehicle Reassignment',
            'document_disk' => $disk,
            'document_path' => $path,
            'original_filename' => $this->reassignPoFile->getClientOriginalName(),
            'uploaded_by_user_id' => auth()->id(),
        ]);

        AuditService::log('vehicle_reassigned', 'job', $this->job->id, null, [
            'original_vin' => $originalVin,
            'new_vin' => strtoupper($this->reassignVin),
        ]);

        $this->showReassignForm = false;
        $this->refreshJob();
        session()->flash('success', 'Vehicle reassigned successfully. New PO uploaded.');
    }

    private function refreshJob(): void
    {
        $this->job->refresh();
        $this->job->load(['documents', 'brand:id,name', 'purchaseOrders.uploadedBy:id,name']);
    }

    public function with(): array
    {
        return [
            'brands' => Brand::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'canReassign' => $this->job->isTransport() && in_array($this->job->status, [
                Job::STATUS_PENDING_VERIFICATION,
                Job::STATUS_VERIFIED,
                Job::STATUS_APPROVED,
                Job::STATUS_ASSIGNED,
            ]),
        ];
    }
};
?>
<div>
    <x-slot:header>Job {{ $job->job_number ?? $job->uuid }}</x-slot:header>

    @if(session('success'))
        <div class="mb-4 rounded-lg bg-green-50 border border-green-200 p-4 text-sm text-green-700">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-4 rounded-lg bg-red-50 border border-red-200 p-4 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-900">Job Details</h3>
                    <x-status-badge :status="$job->status" />
                </div>
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                    <div><dt class="text-gray-500">Type</dt><dd class="font-medium">{{ $job->isTransport() ? 'Transport' : 'Yard Work' }}</dd></div>
                    <div><dt class="text-gray-500">Created</dt><dd class="font-medium">{{ $job->created_at->format('d M Y H:i') }}</dd></div>
                    <div><dt class="text-gray-500">Booked By</dt><dd class="font-medium">{{ $job->createdBy?->name ?? '—' }}</dd></div>
                    @if($job->isTransport())
                    <div>
                        <dt class="text-gray-500">Pickup</dt>
                        <dd class="font-medium">{{ $job->pickupLocation?->company_name ?? '—' }}</dd>
                        @if($job->pickupLocation?->address)<dd class="text-xs text-gray-400">{{ $job->pickupLocation->address }}</dd>@endif
                        <dd class="text-xs text-gray-500 mt-1">Contact: {{ $job->pickup_contact_name ?? $job->pickupLocation?->customer_name ?? '—' }} {{ $job->pickup_contact_phone ?? $job->pickupLocation?->customer_phone ?? '' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Delivery</dt>
                        <dd class="font-medium">{{ $job->deliveryLocation?->company_name ?? '—' }}</dd>
                        @if($job->deliveryLocation?->address)<dd class="text-xs text-gray-400">{{ $job->deliveryLocation->address }}</dd>@endif
                        <dd class="text-xs text-gray-500 mt-1">Contact: {{ $job->delivery_contact_name ?? $job->deliveryLocation?->customer_name ?? '—' }} {{ $job->delivery_contact_phone ?? $job->deliveryLocation?->customer_phone ?? '' }}</dd>
                    </div>
                    @else
                    <div>
                        <dt class="text-gray-500">Yard</dt>
                        <dd class="font-medium">{{ $job->yardLocation?->company_name ?? '—' }}</dd>
                    </div>
                    @endif
                </dl>
            </div>

            @if($job->isTransport())
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-900">Vehicle</h3>
                    @if($canReassign)
                        <button wire:click="$toggle('showReassignForm')" class="text-sm font-medium text-amber-600 hover:text-amber-700">
                            {{ $showReassignForm ? 'Cancel' : 'Reassign Vehicle' }}
                        </button>
                    @endif
                </div>
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                    <div><dt class="text-gray-500">Brand</dt><dd class="font-medium">{{ $job->brand?->name ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">Model</dt><dd class="font-medium">{{ $job->model_name ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">VIN</dt><dd class="font-medium font-mono">{{ $job->vin ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">Registration</dt><dd class="font-medium">{{ $job->registration ?? '—' }}</dd></div>
                </dl>
                @if($job->original_vin && $job->original_vin !== $job->vin)
                    <p class="mt-3 text-xs text-amber-600 bg-amber-50 rounded-lg px-3 py-2">
                        Vehicle reassigned on {{ $job->vehicle_reassigned_at->format('d M Y H:i') }}. Original VIN: <span class="font-mono">{{ $job->original_vin }}</span>
                    </p>
                @endif

                @if($showReassignForm)
                <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-4 space-y-3">
                    <p class="text-sm font-semibold text-gray-900">Reassign to a different vehicle</p>
                    <p class="text-xs text-gray-500">A new PO is required for the replacement vehicle. No penalty will apply.</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Brand</label>
                            <select wire:model="reassignBrandId" class="w-full rounded-md border border-gray-300 px-2.5 py-2 text-sm">
                                <option value="">Select brand...</option>
                                @foreach($brands as $brand)<option value="{{ $brand->id }}">{{ $brand->name }}</option>@endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Model</label>
                            <input wire:model="reassignModelName" type="text" class="w-full rounded-md border border-gray-300 px-2.5 py-2 text-sm" placeholder="e.g. Actros 2645">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">VIN / Chassis *</label>
                            <input wire:model="reassignVin" type="text" class="w-full rounded-md border border-gray-300 px-2.5 py-2 text-sm font-mono uppercase" maxlength="17">
                            @error('reassignVin')<p class="mt-0.5 text-xs text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Registration</label>
                            <input wire:model="reassignRegistration" type="text" class="w-full rounded-md border border-gray-300 px-2.5 py-2 text-sm uppercase">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">New PO Number *</label>
                            <input wire:model="reassignPoNumber" type="text" class="w-full rounded-md border border-gray-300 px-2.5 py-2 text-sm">
                            @error('reassignPoNumber')<p class="mt-0.5 text-xs text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">New PO Amount (ZAR) *</label>
                            <input wire:model="reassignPoAmount" type="number" step="0.01" min="0.01" class="w-full rounded-md border border-gray-300 px-2.5 py-2 text-sm">
                            @error('reassignPoAmount')<p class="mt-0.5 text-xs text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-medium text-gray-600 mb-1">New PO Document *</label>
                            <input wire:model="reassignPoFile" type="file" accept=".pdf,.jpg,.jpeg,.png" class="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-amber-50 file:text-amber-700 hover:file:bg-amber-100">
                            @error('reassignPoFile')<p class="mt-0.5 text-xs text-red-600">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <div class="flex gap-2 justify-end">
                        <button type="button" wire:click="$set('showReassignForm', false)" class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50">Cancel</button>
                        <button type="button" wire:click="reassignVehicle" class="rounded-md bg-amber-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-amber-500" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="reassignVehicle">Reassign Vehicle</span>
                            <span wire:loading wire:target="reassignVehicle">Saving...</span>
                        </button>
                    </div>
                </div>
                @endif
            </div>
            @endif

            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-900">Purchase Orders</h3>
                    @if(!$showPoForm)
                        <button wire:click="$set('showPoForm', true)" class="text-sm font-medium text-blue-600 hover:text-blue-700">+ Add PO</button>
                    @endif
                </div>

                @if($job->purchaseOrders->isNotEmpty())
                    <div class="space-y-3">
                        @foreach($job->purchaseOrders as $po)
                        <div class="flex items-start justify-between rounded-lg border border-gray-100 bg-gray-50 px-4 py-3">
                            <div class="text-sm">
                                <div class="flex items-center gap-2">
                                    <span class="font-semibold text-gray-900">{{ $po->po_number }}</span>
                                    @if($po->label)
                                        <span class="inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700">{{ $po->label }}</span>
                                    @endif
                                    @if($po->is_verified)
                                        <span class="inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700">Verified</span>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-yellow-100 px-2 py-0.5 text-xs font-medium text-yellow-700">Pending</span>
                                    @endif
                                </div>
                                <p class="text-gray-600 mt-0.5">R{{ number_format($po->po_amount, 2) }}</p>
                                @if($po->original_filename)
                                    <p class="text-xs text-gray-400 mt-0.5">{{ $po->original_filename }}</p>
                                @endif
                            </div>
                            <div class="text-right text-xs text-gray-400">
                                <p>{{ $po->created_at->format('d M Y') }}</p>
                                <p>{{ $po->uploadedBy?->name }}</p>
                            </div>
                        </div>
                        @endforeach
                    </div>
                    @php $poTotal = $job->purchaseOrders->sum('po_amount'); @endphp
                    <div class="mt-3 flex justify-end text-sm">
                        <span class="text-gray-500">Total PO Value:</span>
                        <span class="ml-2 font-semibold text-gray-900">R{{ number_format($poTotal, 2) }}</span>
                    </div>
                @else
                    <p class="text-sm text-gray-500">No purchase orders attached yet.</p>
                @endif

                @if($showPoForm)
                <div class="mt-4 rounded-lg border border-blue-200 bg-blue-50 p-4 space-y-3">
                    <p class="text-sm font-semibold text-gray-900">Add Purchase Order</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">PO Number *</label>
                            <input wire:model="poNumber" type="text" class="w-full rounded-md border border-gray-300 px-2.5 py-2 text-sm">
                            @error('poNumber')<p class="mt-0.5 text-xs text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">PO Amount (ZAR) *</label>
                            <input wire:model="poAmount" type="number" step="0.01" min="0.01" class="w-full rounded-md border border-gray-300 px-2.5 py-2 text-sm">
                            @error('poAmount')<p class="mt-0.5 text-xs text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Label</label>
                            <input wire:model="poLabel" type="text" class="w-full rounded-md border border-gray-300 px-2.5 py-2 text-sm" placeholder="e.g. Transport, Fuel, Extras">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">PO Document *</label>
                            <input wire:model="poFile" type="file" accept=".pdf,.jpg,.jpeg,.png" class="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                            @error('poFile')<p class="mt-0.5 text-xs text-red-600">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <div class="flex gap-2 justify-end">
                        <button type="button" wire:click="$set('showPoForm', false)" class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50">Cancel</button>
                        <button type="button" wire:click="uploadPo" class="rounded-md bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-500" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="uploadPo">Upload</span>
                            <span wire:loading wire:target="uploadPo">Uploading...</span>
                        </button>
                    </div>
                </div>
                @endif
            </div>

            @if($job->driver)
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Driver</h3>
                <p class="text-sm"><strong>{{ $job->driver->name }}</strong></p>
                @if($job->driver->phone)<p class="text-sm text-gray-500">{{ $job->driver->phone }}</p>@endif
            </div>
            @endif

            @if($job->events->isNotEmpty())
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Timeline</h3>
                <ol class="relative border-l border-gray-200 ml-3 space-y-4">
                    @foreach($job->events as $event)
                    <li class="ml-6">
                        <span class="absolute -left-2 flex h-4 w-4 items-center justify-center rounded-full bg-blue-100 ring-4 ring-white"><span class="h-2 w-2 rounded-full bg-blue-600"></span></span>
                        <h4 class="text-sm font-medium text-gray-900">{{ ucfirst(str_replace('_', ' ', $event->event_type)) }}</h4>
                        <time class="text-xs text-gray-500">{{ $event->event_at->format('d M Y H:i') }}</time>
                    </li>
                    @endforeach
                </ol>
            </div>
            @endif
        </div>

        <div>
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Documents</h3>
                @if($job->documents->isNotEmpty())
                    <ul class="space-y-2">
                        @foreach($job->documents as $doc)
                        <li class="text-sm">
                            <span class="font-medium">{{ $doc->original_filename }}</span>
                            <br><span class="text-xs text-gray-500">{{ ucfirst(str_replace('_', ' ', $doc->category)) }}</span>
                        </li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-sm text-gray-500">No documents uploaded yet.</p>
                @endif
            </div>
        </div>
    </div>
</div>
