<?php

use App\Models\Job;
use App\Models\PurchaseOrder;
use App\Services\AuditService;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    #[Url]
    public string $status = '';

    #[Url]
    public string $search = '';

    public ?int $quickViewJobId = null;
    public ?int $previewPoId = null;

    public function with(): array
    {
        $query = Job::with([
                'company:id,name',
                'pickupLocation:id,company_name',
                'deliveryLocation:id,company_name',
                'purchaseOrders:id,job_id,po_number,po_amount,is_verified,original_filename,document_path',
                'brand:id,name',
            ])
            ->orderByDesc('created_at');

        if ($this->status) {
            $query->where('status', $this->status);
        }

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('job_number', 'ilike', "%{$this->search}%")
                    ->orWhere('vin', 'ilike', "%{$this->search}%")
                    ->orWhereHas('purchaseOrders', fn($q) => $q->where('po_number', 'ilike', "%{$this->search}%"))
                    ->orWhereHas('company', fn($q) => $q->where('name', 'ilike', "%{$this->search}%"));
            });
        }

        return ['jobs' => $query->paginate(25)];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function quickView(int $jobId): void
    {
        $this->quickViewJobId = $jobId;
        $this->previewPoId = null;
    }

    public function closeQuickView(): void
    {
        $this->quickViewJobId = null;
        $this->previewPoId = null;
    }

    public function previewPo(int $poId): void
    {
        $this->previewPoId = $poId;
    }

    public function closePreview(): void
    {
        $this->previewPoId = null;
    }

    public function quickVerify(int $jobId): void
    {
        $job = Job::findOrFail($jobId);
        if ($job->status !== Job::STATUS_PENDING_VERIFICATION || !auth()->user()->canApproveBookings()) {
            session()->flash('error', 'Cannot verify this booking.');
            return;
        }
        $job->transitionTo(Job::STATUS_VERIFIED);
        $job->po_verified = true;
        $job->po_verified_at = now();
        $job->po_verified_by = auth()->id();
        $job->save();
        AuditService::log('po_verified', 'job', $job->id, null, ['status' => 'verified']);
        $this->quickViewJobId = null;
        session()->flash('success', "Job {$job->job_number} verified.");
    }

    public function quickApprove(int $jobId): void
    {
        $job = Job::findOrFail($jobId);
        if ($job->status !== Job::STATUS_VERIFIED || !auth()->user()->canApproveBookings()) {
            session()->flash('error', 'Cannot approve this booking.');
            return;
        }
        $job->transitionTo(Job::STATUS_APPROVED);
        AuditService::log('approved', 'job', $job->id);
        $this->quickViewJobId = null;
        session()->flash('success', "Job {$job->job_number} approved.");
    }

    public function quickReject(int $jobId): void
    {
        $job = Job::findOrFail($jobId);
        if (!in_array($job->status, [Job::STATUS_PENDING_VERIFICATION, Job::STATUS_VERIFIED]) || !auth()->user()->canApproveBookings()) {
            session()->flash('error', 'Cannot reject this booking.');
            return;
        }
        $before = ['status' => $job->status];
        $job->transitionTo(Job::STATUS_REJECTED);
        AuditService::log('rejected', 'job', $job->id, $before, ['status' => 'rejected']);
        $this->quickViewJobId = null;
        session()->flash('success', "Job {$job->job_number} rejected.");
    }
};

?>

<div>
    <x-slot:header>Bookings</x-slot:header>

    @if(session('success'))
        <div class="mb-4 rounded-lg bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-4 rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800">{{ session('error') }}</div>
    @endif

    <div class="mb-6 flex flex-col sm:flex-row gap-4">
        <div class="flex-1">
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search by job #, VIN, PO, or company..."
                class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
        </div>
        <select wire:model.live="status" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
            <option value="">All Statuses</option>
            <option value="pending_verification">Pending Verification</option>
            <option value="verified">Verified</option>
            <option value="approved">Approved</option>
            <option value="rejected">Rejected</option>
            <option value="assigned">Assigned</option>
            <option value="in_progress">In Progress</option>
            <option value="completed">Completed</option>
            <option value="ready_for_invoicing">Ready for Invoicing</option>
            <option value="invoiced">Invoiced</option>
            <option value="cancelled">Cancelled</option>
        </select>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Job #</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Company</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Route</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">VIN</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">PO</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($jobs as $job)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.bookings.show', $job) }}" class="text-sm font-medium text-blue-600 hover:text-blue-800">{{ $job->job_number ?? '—' }}</a>
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-900">{{ $job->company?->name }}</td>
                    <td class="px-4 py-3 text-sm text-gray-500">
                        @if($job->isTransport())
                            {{ $job->pickupLocation?->company_name }} &rarr; {{ $job->deliveryLocation?->company_name }}
                        @else
                            Yard Work
                        @endif
                    </td>
                    <td class="px-4 py-3 text-sm font-mono text-gray-600 uppercase">{{ $job->vin ? strtoupper($job->vin) : '—' }}</td>
                    <td class="px-4 py-3 text-sm">
                        @if($job->purchaseOrders->isNotEmpty())
                            <span class="text-gray-900">{{ $job->purchaseOrders->first()->po_number }}</span>
                            <span class="text-gray-400 text-xs ml-1">R{{ number_format($job->purchaseOrders->sum('po_amount'), 0) }}</span>
                            @if($job->purchaseOrders->count() > 1)
                                <span class="text-xs text-gray-400">(+{{ $job->purchaseOrders->count() - 1 }})</span>
                            @endif
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3"><x-status-badge :status="$job->status" /></td>
                    <td class="px-4 py-3 text-sm text-gray-500">{{ $job->scheduled_date?->format('d M') }}</td>
                    <td class="px-4 py-3 text-center" onclick="event.stopPropagation()">
                        <div class="flex items-center justify-center gap-1">
                            @if($job->purchaseOrders->isNotEmpty() && $job->purchaseOrders->first()->document_path)
                                <button wire:click="quickView({{ $job->id }})" class="rounded-md bg-blue-50 px-2 py-1 text-xs font-medium text-blue-700 hover:bg-blue-100" title="Quick View PO & Actions">
                                    <svg class="h-4 w-4 inline" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                                    PO
                                </button>
                            @endif
                            @if($job->status === 'pending_verification' && auth()->user()->canApproveBookings())
                                <button wire:click="quickVerify({{ $job->id }})" wire:confirm="Verify this booking?" class="rounded-md bg-green-50 px-2 py-1 text-xs font-medium text-green-700 hover:bg-green-100" title="Verify">
                                    <svg class="h-4 w-4 inline" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                </button>
                            @endif
                            @if($job->status === 'verified' && auth()->user()->canApproveBookings())
                                <button wire:click="quickApprove({{ $job->id }})" wire:confirm="Approve this booking?" class="rounded-md bg-emerald-50 px-2 py-1 text-xs font-medium text-emerald-700 hover:bg-emerald-100" title="Approve">
                                    <svg class="h-4 w-4 inline" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                                </button>
                            @endif
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" class="px-6 py-12 text-center text-sm text-gray-500">No bookings found.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $jobs->links() }}
    </div>

    {{-- Quick View Slide-Over Panel --}}
    @if($quickViewJobId)
    @php
        $qvJob = $jobs->firstWhere('id', $quickViewJobId);
    @endphp
    @if($qvJob)
    <div class="fixed inset-0 z-40 flex justify-end" x-data x-transition>
        <div class="fixed inset-0 bg-black/40" wire:click="closeQuickView"></div>
        <div class="relative w-full max-w-lg bg-white shadow-2xl overflow-y-auto z-50">
            <div class="sticky top-0 bg-white border-b border-gray-200 px-6 py-4 flex items-center justify-between z-10">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">{{ $qvJob->job_number ?? 'Job' }}</h3>
                    <p class="text-sm text-gray-500">{{ $qvJob->company?->name }}</p>
                </div>
                <div class="flex items-center gap-2">
                    <a href="{{ route('admin.bookings.show', $qvJob) }}" class="rounded-md bg-gray-100 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-200">Full Details</a>
                    <button wire:click="closeQuickView" class="rounded-full p-1 text-gray-400 hover:text-gray-600 hover:bg-gray-100">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                </div>
            </div>

            <div class="p-6 space-y-5">
                {{-- Booking Summary --}}
                <div>
                    <h4 class="text-sm font-semibold text-gray-700 mb-2">Booking Summary</h4>
                    <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                        <div><dt class="text-gray-500">Status</dt><dd><x-status-badge :status="$qvJob->status" /></dd></div>
                        <div><dt class="text-gray-500">Date</dt><dd class="font-medium">{{ $qvJob->scheduled_date?->format('d M Y') }}</dd></div>
                        @if($qvJob->isTransport())
                            <div><dt class="text-gray-500">Pickup</dt><dd class="font-medium">{{ $qvJob->pickupLocation?->company_name }}</dd></div>
                            <div><dt class="text-gray-500">Delivery</dt><dd class="font-medium">{{ $qvJob->deliveryLocation?->company_name }}</dd></div>
                            <div><dt class="text-gray-500">Brand</dt><dd class="font-medium">{{ $qvJob->brand?->name ?? '—' }}</dd></div>
                            <div><dt class="text-gray-500">VIN</dt><dd class="font-mono text-xs uppercase">{{ strtoupper($qvJob->vin ?? '') ?: '—' }}</dd></div>
                        @endif
                    </dl>
                </div>

                {{-- Purchase Orders --}}
                <div>
                    <h4 class="text-sm font-semibold text-gray-700 mb-2">Purchase Orders</h4>
                    @if($qvJob->purchaseOrders->isNotEmpty())
                        <div class="space-y-2">
                            @foreach($qvJob->purchaseOrders as $po)
                            <div class="rounded-lg border border-gray-100 bg-gray-50 p-3">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <span class="font-semibold text-sm text-gray-900">{{ $po->po_number }}</span>
                                        @if($po->is_verified)
                                            <span class="ml-1 inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700">Verified</span>
                                        @else
                                            <span class="ml-1 inline-flex items-center rounded-full bg-yellow-100 px-2 py-0.5 text-xs font-medium text-yellow-700">Pending</span>
                                        @endif
                                        <p class="text-sm text-gray-600">R{{ number_format($po->po_amount, 2) }}</p>
                                        @if($po->original_filename)
                                            <p class="text-xs text-gray-400">{{ $po->original_filename }}</p>
                                        @endif
                                    </div>
                                    @if($po->document_path)
                                        <button wire:click="previewPo({{ $po->id }})" class="rounded-md bg-blue-50 px-3 py-1.5 text-xs font-medium text-blue-700 hover:bg-blue-100">
                                            Preview
                                        </button>
                                    @endif
                                </div>
                            </div>
                            @endforeach
                        </div>
                        <div class="mt-2 flex justify-end text-sm">
                            <span class="text-gray-500">Total:</span>
                            <span class="ml-1 font-semibold text-gray-900">R{{ number_format($qvJob->purchaseOrders->sum('po_amount'), 2) }}</span>
                        </div>
                    @else
                        <p class="text-sm text-gray-400">No POs attached.</p>
                    @endif
                </div>

                {{-- Quick Actions --}}
                @if(auth()->user()->canApproveBookings())
                <div>
                    <h4 class="text-sm font-semibold text-gray-700 mb-2">Quick Actions</h4>
                    <div class="space-y-2">
                        @if($qvJob->status === 'pending_verification')
                            <button wire:click="quickVerify({{ $qvJob->id }})" wire:confirm="Verify this booking and PO?" class="w-full rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-500 transition-colors">
                                Verify & Approve PO
                            </button>
                            <button wire:click="quickReject({{ $qvJob->id }})" wire:confirm="Reject this booking?" class="w-full rounded-lg bg-red-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-red-500 transition-colors">
                                Reject
                            </button>
                        @elseif($qvJob->status === 'verified')
                            <button wire:click="quickApprove({{ $qvJob->id }})" wire:confirm="Approve this booking?" class="w-full rounded-lg bg-green-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-green-500 transition-colors">
                                Approve Booking
                            </button>
                            <button wire:click="quickReject({{ $qvJob->id }})" wire:confirm="Reject this booking?" class="w-full rounded-lg bg-red-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-red-500 transition-colors">
                                Reject
                            </button>
                        @else
                            <p class="text-sm text-gray-400 text-center py-2">No actions available for this status.</p>
                        @endif
                    </div>
                </div>
                @endif
            </div>
        </div>
    </div>
    @endif
    @endif

    {{-- PO Document Preview Modal (overlays on top of slide-over) --}}
    @if($previewPoId)
    @php
        $previewPo = null;
        if ($quickViewJobId) {
            $qvj = $jobs->firstWhere('id', $quickViewJobId);
            $previewPo = $qvj?->purchaseOrders->firstWhere('id', $previewPoId);
        }
    @endphp
    @if($previewPo)
    <div class="fixed inset-0 z-[60] flex items-center justify-center bg-black/60" wire:click.self="closePreview">
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
