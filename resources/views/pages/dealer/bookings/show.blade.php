<?php
use App\Models\Brand;
use App\Models\Job;
use App\Models\PurchaseOrder;
use App\Services\AuditService;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\WithFileUploads;
use App\Services\CutoffService;
use App\Models\BookingChangeRequest;
use Illuminate\Support\Facades\Storage;

new #[Layout('components.layouts.app')] class extends Component {
    use WithFileUploads;

    public Job $job;

    // PO upload
    public bool $showPoForm = false;
    public string $poNumber = '';
    public string $poAmount = '';
    public string $poLabel = '';
    public $poFile;

    // PO preview & replace
    public ?int $previewPoId = null;
    public ?int $replacePoId = null;
    public $replacePoFile;

    // Vehicle reassignment
    public bool $showReassignForm = false;
    public ?int $reassignBrandId = null;
    public string $reassignModelName = '';
    public string $reassignVin = '';
    public string $reassignRegistration = '';
    public string $reassignPoNumber = '';
    public string $reassignPoAmount = '';
    public $reassignPoFile;

    // Collection date editing
    public bool $showEditCollection = false;
    public string $editCollectionDate = '';
    public string $editCollectionTime = '';

    // Change request (past cutoff)
    public bool $showChangeRequestForm = false;
    public string $requestedDate = '';
    public string $requestedTime = '';
    public string $changeReason = '';

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

    public function previewPo(int $poId): void
    {
        $this->previewPoId = $poId;
    }

    public function closePreview(): void
    {
        $this->previewPoId = null;
    }

    public function startReplacePo(int $poId): void
    {
        $po = PurchaseOrder::where('job_id', $this->job->id)->findOrFail($poId);
        if ($po->is_verified) {
            session()->flash('error', 'Cannot replace a verified PO.');
            return;
        }
        $this->replacePoId = $poId;
        $this->replacePoFile = null;
    }

    public function cancelReplace(): void
    {
        $this->replacePoId = null;
        $this->replacePoFile = null;
    }

    public function replacePo(): void
    {
        $this->validate(['replacePoFile' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240']);

        $po = PurchaseOrder::where('job_id', $this->job->id)->findOrFail($this->replacePoId);

        if ($po->is_verified) {
            session()->flash('error', 'Cannot replace a verified PO.');
            return;
        }

        if ($po->document_path && Storage::disk($po->document_disk)->exists($po->document_path)) {
            Storage::disk($po->document_disk)->delete($po->document_path);
        }

        $disk = config('filesystems.default') === 'local' ? 'local' : 'r2';
        $path = $this->replacePoFile->store('jobs/' . $this->job->uuid . '/po', $disk);

        $po->update([
            'document_disk' => $disk,
            'document_path' => $path,
            'original_filename' => $this->replacePoFile->getClientOriginalName(),
            'uploaded_by_user_id' => auth()->id(),
        ]);

        $this->replacePoId = null;
        $this->replacePoFile = null;
        $this->refreshJob();
        session()->flash('success', 'PO document replaced.');
    }

    public function deletePo(int $poId): void
    {
        $po = PurchaseOrder::where('job_id', $this->job->id)->findOrFail($poId);

        if ($po->is_verified) {
            session()->flash('error', 'Cannot delete a verified PO.');
            return;
        }

        if ($po->document_path && Storage::disk($po->document_disk)->exists($po->document_path)) {
            Storage::disk($po->document_disk)->delete($po->document_path);
        }

        $po->delete();
        $this->refreshJob();
        session()->flash('success', 'Purchase order removed.');
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

    public function updateCollectionDate(): void
    {
        $this->validate([
            'editCollectionDate' => 'required|date',
            'editCollectionTime' => 'required|date_format:H:i',
        ]);

        $this->job->update([
            'scheduled_date' => $this->editCollectionDate,
            'scheduled_ready_time' => $this->editCollectionDate . ' ' . $this->editCollectionTime,
        ]);

        $this->showEditCollection = false;
        $this->refreshJob();
        session()->flash('success', 'Collection date updated.');
    }

    public function submitChangeRequest(): void
    {
        $this->validate([
            'requestedDate' => 'required|date',
            'requestedTime' => 'required|date_format:H:i',
            'changeReason' => 'required|string|min:10',
        ]);

        BookingChangeRequest::create([
            'job_id' => $this->job->id,
            'requested_by_user_id' => auth()->id(),
            'request_type' => 'collection_date_change',
            'current_value' => [
                'date' => $this->job->scheduled_date?->format('Y-m-d'),
                'time' => $this->job->scheduled_ready_time?->format('H:i'),
            ],
            'requested_value' => [
                'date' => $this->requestedDate,
                'time' => $this->requestedTime,
            ],
            'reason' => $this->changeReason,
        ]);

        $this->showChangeRequestForm = false;
        $this->changeReason = '';
        session()->flash('success', 'Change request submitted for review.');
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
            'isPastCutoff' => CutoffService::isPastCutoff($this->job),
            'changeRequests' => $this->job->changeRequests()->latest()->get(),
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
            {{-- Job Details --}}
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-900">Job Details</h3>
                    <x-status-badge :status="$job->status" />
                </div>
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                    <div><dt class="text-gray-500">Type</dt><dd class="font-medium">{{ $job->isTransport() ? 'Transport' : 'Yard Work' }}</dd></div>
                    <div><dt class="text-gray-500">Created</dt><dd class="font-medium">{{ $job->created_at->format('d M Y H:i') }}</dd></div>
                    <div><dt class="text-gray-500">Booked By</dt><dd class="font-medium">{{ $job->createdBy?->name ?? '—' }}</dd></div>
                    <div>
                        <dt class="text-gray-500">Collection Date</dt>
                        <dd class="font-medium">{{ $job->scheduled_date?->format('d M Y') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Collection Time</dt>
                        <dd class="font-medium">{{ $job->scheduled_ready_time?->format('H:i') ?? '—' }}</dd>
                    </div>
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
                    @if($job->distance_km)
                    <div>
                        <dt class="text-gray-500">Distance</dt>
                        <dd class="font-medium">{{ number_format($job->distance_km, 1) }} km @if($job->is_round_trip)<span class="inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700 ml-1">Round Trip</span>@endif</dd>
                    </div>
                    @endif
                    @else
                    <div>
                        <dt class="text-gray-500">Yard</dt>
                        <dd class="font-medium">{{ $job->yardLocation?->company_name ?? '—' }}</dd>
                    </div>
                    @endif
                </dl>
            </div>

            {{-- Collection Date Management --}}
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-900">Collection Schedule</h3>
                    @if(!$isPastCutoff && !$showEditCollection)
                        <button wire:click="$set('showEditCollection', true)" class="text-sm font-medium text-blue-600 hover:text-blue-700">Edit</button>
                    @elseif($isPastCutoff && !$showChangeRequestForm)
                        <button wire:click="$set('showChangeRequestForm', true)" class="text-sm font-medium text-amber-600 hover:text-amber-700">Request Change</button>
                    @endif
                </div>

                <p class="text-sm text-gray-600">
                    <strong>{{ $job->scheduled_date?->format('d M Y') }}</strong> at <strong>{{ $job->scheduled_ready_time?->format('H:i') ?? '—' }}</strong>
                    @if($isPastCutoff)
                        <span class="ml-2 inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700">Past cutoff</span>
                    @endif
                </p>

                @if($showEditCollection)
                <div class="mt-4 rounded-lg border border-blue-200 bg-blue-50 p-4 space-y-3">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">New Date *</label>
                            <input wire:model="editCollectionDate" type="date" class="w-full rounded-md border border-gray-300 px-2.5 py-2 text-sm">
                            @error('editCollectionDate')<p class="mt-0.5 text-xs text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">New Time *</label>
                            <input wire:model="editCollectionTime" type="time" class="w-full rounded-md border border-gray-300 px-2.5 py-2 text-sm">
                            @error('editCollectionTime')<p class="mt-0.5 text-xs text-red-600">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <div class="flex gap-2 justify-end">
                        <button type="button" wire:click="$set('showEditCollection', false)" class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50">Cancel</button>
                        <button type="button" wire:click="updateCollectionDate" class="rounded-md bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-500">Save</button>
                    </div>
                </div>
                @endif

                @if($showChangeRequestForm)
                <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-4 space-y-3">
                    <p class="text-sm font-semibold text-gray-900">Request Collection Date Change</p>
                    <p class="text-xs text-gray-500">The cutoff has passed. Your request will be reviewed by the operations team.</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Requested Date *</label>
                            <input wire:model="requestedDate" type="date" class="w-full rounded-md border border-gray-300 px-2.5 py-2 text-sm">
                            @error('requestedDate')<p class="mt-0.5 text-xs text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Requested Time *</label>
                            <input wire:model="requestedTime" type="time" class="w-full rounded-md border border-gray-300 px-2.5 py-2 text-sm">
                            @error('requestedTime')<p class="mt-0.5 text-xs text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-medium text-gray-600 mb-1">Reason for Change *</label>
                            <textarea wire:model="changeReason" rows="2" class="w-full rounded-md border border-gray-300 px-2.5 py-2 text-sm" placeholder="Please explain why the date needs to change..."></textarea>
                            @error('changeReason')<p class="mt-0.5 text-xs text-red-600">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <div class="flex gap-2 justify-end">
                        <button type="button" wire:click="$set('showChangeRequestForm', false)" class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50">Cancel</button>
                        <button type="button" wire:click="submitChangeRequest" class="rounded-md bg-amber-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-amber-500">Submit Request</button>
                    </div>
                </div>
                @endif

                @if($changeRequests->isNotEmpty())
                <div class="mt-4 space-y-2">
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Change Requests</p>
                    @foreach($changeRequests as $cr)
                    <div class="rounded-lg border border-gray-100 bg-gray-50 px-3 py-2 text-sm">
                        <div class="flex items-center justify-between">
                            <span>{{ $cr->requested_value['date'] ?? '' }} {{ $cr->requested_value['time'] ?? '' }}</span>
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $cr->status === 'approved' ? 'bg-green-100 text-green-700' : ($cr->status === 'rejected' ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-700') }}">{{ ucfirst($cr->status) }}</span>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">{{ $cr->reason }}</p>
                        @if($cr->review_notes)<p class="text-xs text-gray-400 mt-0.5">Admin: {{ $cr->review_notes }}</p>@endif
                    </div>
                    @endforeach
                </div>
                @endif
            </div>

            {{-- Vehicle Info --}}
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
                    <div><dt class="text-gray-500">VIN</dt><dd class="font-medium font-mono uppercase">{{ strtoupper($job->vin ?? '') ?: '—' }}</dd></div>
                    <div><dt class="text-gray-500">Registration</dt><dd class="font-medium">{{ $job->registration ?? '—' }}</dd></div>
                </dl>
                @if($job->original_vin && $job->original_vin !== $job->vin)
                    <p class="mt-3 text-xs text-amber-600 bg-amber-50 rounded-lg px-3 py-2">
                        Vehicle reassigned on {{ $job->vehicle_reassigned_at->format('d M Y H:i') }}. Original VIN: <span class="font-mono uppercase">{{ strtoupper($job->original_vin) }}</span>
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
                            <input wire:model="reassignModelName" type="text" class="w-full rounded-md border border-gray-300 px-2.5 py-2 text-sm" placeholder="e.g. NQR 500">
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

            {{-- Purchase Orders --}}
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
                        <div class="rounded-lg border border-gray-100 bg-gray-50 px-4 py-3">
                            <div class="flex items-start justify-between">
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
                                <div class="text-right text-xs">
                                    <p class="text-gray-400">{{ $po->created_at->format('d M Y') }}</p>
                                    <p class="text-gray-400">{{ $po->uploadedBy?->name }}</p>
                                    <div class="mt-1 flex items-center gap-2 justify-end">
                                        @if($po->document_path)
                                            <button wire:click="previewPo({{ $po->id }})" class="text-blue-600 hover:text-blue-800 font-medium">View</button>
                                        @endif
                                        @if(!$po->is_verified)
                                            <button wire:click="startReplacePo({{ $po->id }})" class="text-amber-600 hover:text-amber-800 font-medium">Replace</button>
                                            <button wire:click="deletePo({{ $po->id }})" wire:confirm="Remove this PO? This cannot be undone." class="text-red-600 hover:text-red-800 font-medium">Remove</button>
                                        @endif
                                    </div>
                                </div>
                            </div>

                            {{-- Inline replace form --}}
                            @if($replacePoId === $po->id)
                            <div class="mt-3 pt-3 border-t border-gray-200">
                                <p class="text-xs font-medium text-gray-700 mb-2">Upload replacement document</p>
                                <div class="flex items-center gap-3">
                                    <input wire:model="replacePoFile" type="file" accept=".pdf,.jpg,.jpeg,.png" class="flex-1 text-sm text-gray-500 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-amber-50 file:text-amber-700 hover:file:bg-amber-100">
                                    <button wire:click="replacePo" class="rounded-md bg-amber-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-amber-500" wire:loading.attr="disabled">
                                        <span wire:loading.remove wire:target="replacePo">Upload</span>
                                        <span wire:loading wire:target="replacePo">Uploading...</span>
                                    </button>
                                    <button wire:click="cancelReplace" class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50">Cancel</button>
                                </div>
                                @error('replacePoFile')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                            </div>
                            @endif
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

    {{-- PO Document Preview Modal --}}
    @if($previewPoId)
    @php $previewPo = $job->purchaseOrders->firstWhere('id', $previewPoId); @endphp
    @if($previewPo)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60" wire:click.self="closePreview">
        <div class="relative w-full max-w-4xl mx-4 bg-white rounded-2xl shadow-2xl overflow-hidden" style="max-height: 90vh;">
            <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">{{ $previewPo->po_number }}</h3>
                    <p class="text-sm text-gray-500">{{ $previewPo->original_filename }} &middot; R{{ number_format($previewPo->po_amount, 2) }}</p>
                </div>
                <div class="flex items-center gap-3">
                    <a href="{{ route('po.preview', $previewPo->id) }}" target="_blank" class="rounded-md bg-gray-100 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-200">Open in New Tab</a>
                    <button wire:click="closePreview" class="rounded-full p-1 text-gray-400 hover:text-gray-600 hover:bg-gray-100">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                </div>
            </div>
            <div class="p-0" style="height: 75vh;">
                @php
                    $ext = pathinfo($previewPo->original_filename ?? '', PATHINFO_EXTENSION);
                    $isImage = in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'gif', 'webp']);
                @endphp
                @if($isImage)
                    <div class="flex items-center justify-center h-full bg-gray-50 p-4">
                        <img src="{{ route('po.preview', $previewPo->id) }}" alt="{{ $previewPo->original_filename }}" class="max-h-full max-w-full object-contain rounded">
                    </div>
                @else
                    <iframe src="{{ route('po.preview', $previewPo->id) }}" class="w-full h-full border-0"></iframe>
                @endif
            </div>
        </div>
    </div>
    @endif
    @endif
</div>
