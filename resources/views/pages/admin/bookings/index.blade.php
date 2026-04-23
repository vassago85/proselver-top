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

    #[Url]
    public string $sortBy = 'scheduled_date';

    #[Url]
    public string $sortDir = 'desc';

    public ?int $quickViewJobId = null;
    public ?int $previewPoId = null;
    public ?int $approveJobId = null;
    public ?int $approveDriverId = null;

    public function with(): array
    {
        $allowedSorts = ['job_number', 'vin', 'scheduled_date', 'created_at'];
        $col = in_array($this->sortBy, $allowedSorts) ? $this->sortBy : 'scheduled_date';
        $dir = $this->sortDir === 'asc' ? 'asc' : 'desc';

        $query = Job::with([
                'company:id,name',
                'pickupLocation:id,company_name',
                'deliveryLocation:id,company_name',
                'purchaseOrders:id,job_id,po_number,po_amount,is_verified,original_filename,document_path',
                'brand:id,name',
                'driver:id,name,phone',
                'driver.driverProfile:id,user_id,cellphone',
            ])
            ->orderBy($col, $dir);

        if ($this->status) {
            $query->where('status', $this->status);
        }

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('job_number', 'ilike', "%{$this->search}%")
                    ->orWhere('vin', 'ilike', "%{$this->search}%")
                    ->orWhere('model_name', 'ilike', "%{$this->search}%")
                    ->orWhere('customer_notes', 'ilike', "%{$this->search}%")
                    ->orWhereHas('brand', fn($q) => $q->where('name', 'ilike', "%{$this->search}%"))
                    ->orWhereHas('driver', fn($q) => $q->where('name', 'ilike', "%{$this->search}%"))
                    ->orWhereHas('purchaseOrders', fn($q) => $q->where('po_number', 'ilike', "%{$this->search}%"))
                    ->orWhereHas('company', fn($q) => $q->where('name', 'ilike', "%{$this->search}%"))
                    ->orWhereHas('pickupLocation', fn($q) => $q->where('company_name', 'ilike', "%{$this->search}%"))
                    ->orWhereHas('deliveryLocation', fn($q) => $q->where('company_name', 'ilike', "%{$this->search}%"));
            });
        }

        $drivers = \App\Models\User::whereHas('roles', fn($q) => $q->where('slug', 'driver'))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return [
            'jobs' => $query->paginate(25),
            'drivers' => $drivers,
        ];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function sort(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'asc';
        }
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

    public function openApproveModal(int $jobId): void
    {
        $this->approveJobId = $jobId;
        $this->approveDriverId = null;
    }

    public function closeApproveModal(): void
    {
        $this->approveJobId = null;
        $this->approveDriverId = null;
    }

    public function approveAndAssign(): void
    {
        $job = Job::findOrFail($this->approveJobId);
        if ($job->status !== Job::STATUS_VERIFIED || !auth()->user()->canApproveBookings()) {
            session()->flash('error', 'Cannot approve this booking.');
            $this->closeApproveModal();
            return;
        }

        $job->transitionTo(Job::STATUS_APPROVED);
        AuditService::log('approved', 'job', $job->id);

        if ($this->approveDriverId) {
            $this->validate(['approveDriverId' => 'required|exists:users,id']);
            $driver = \App\Models\User::findOrFail($this->approveDriverId);
            $job->driver_user_id = $driver->id;
            $job->transitionTo(Job::STATUS_ASSIGNED);
            AuditService::log('driver_assigned', 'job', $job->id, null, ['driver_id' => $driver->id, 'driver_name' => $driver->name]);
            session()->flash('success', "Job {$job->job_number} approved and driver {$driver->name} assigned.");
        } else {
            session()->flash('success', "Job {$job->job_number} approved.");
        }

        $this->quickViewJobId = null;
        $this->closeApproveModal();
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
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search by job #, VIN, make/model, driver, comment, PO, company or route..."
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
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 whitespace-nowrap">
                <tr>
                    <th wire:click="sort('job_number')" class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase cursor-pointer hover:text-gray-700 select-none">
                        <span class="inline-flex items-center gap-1">Job #
                            @if($sortBy === 'job_number')
                                <svg class="h-3 w-3 {{ $sortDir === 'asc' ? '' : 'rotate-180' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m18 15-6-6-6 6"/></svg>
                            @endif
                        </span>
                    </th>
                    <th wire:click="sort('created_at')" class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase cursor-pointer hover:text-gray-700 select-none">
                        <span class="inline-flex items-center gap-1">Received
                            @if($sortBy === 'created_at')
                                <svg class="h-3 w-3 {{ $sortDir === 'asc' ? '' : 'rotate-180' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m18 15-6-6-6 6"/></svg>
                            @endif
                        </span>
                    </th>
                    <th wire:click="sort('scheduled_date')" class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase cursor-pointer hover:text-gray-700 select-none">
                        <span class="inline-flex items-center gap-1">Movement
                            @if($sortBy === 'scheduled_date')
                                <svg class="h-3 w-3 {{ $sortDir === 'asc' ? '' : 'rotate-180' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m18 15-6-6-6 6"/></svg>
                            @endif
                        </span>
                    </th>
                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Driver</th>
                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Cell</th>
                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Make / Model</th>
                    <th wire:click="sort('vin')" class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase cursor-pointer hover:text-gray-700 select-none">
                        <span class="inline-flex items-center gap-1">VIN
                            @if($sortBy === 'vin')
                                <svg class="h-3 w-3 {{ $sortDir === 'asc' ? '' : 'rotate-180' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m18 15-6-6-6 6"/></svg>
                            @endif
                        </span>
                    </th>
                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">From</th>
                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">To</th>
                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Arrived</th>
                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Delivered</th>
                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Comment</th>
                    <th class="px-3 py-3 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($jobs as $job)
                @php
                    $cell = $job->driver?->driverProfile?->cellphone ?? $job->driver?->phone;
                    $commentText = $job->confirmation_note
                        ?? ($job->confirmation_reason ? (\App\Models\Job::CONFIRMATION_ISSUE_REASONS[$job->confirmation_reason] ?? null) : null)
                        ?? $job->customer_notes
                        ?? $job->emergency_reason;
                @endphp
                <tr class="hover:bg-gray-50">
                    <td class="px-3 py-3 whitespace-nowrap">
                        <a href="{{ route('admin.bookings.show', $job) }}" class="font-medium text-blue-600 hover:text-blue-800">{{ $job->job_number ?? '—' }}</a>
                        <div class="text-xs text-gray-400">{{ $job->company?->name }}</div>
                    </td>
                    <td class="px-3 py-3 whitespace-nowrap text-gray-500 text-xs">{{ $job->created_at?->format('d M Y') }}</td>
                    <td class="px-3 py-3 whitespace-nowrap text-gray-700 text-xs font-medium">{{ $job->scheduled_date?->format('d M Y') ?? '—' }}</td>
                    <td class="px-3 py-3 whitespace-nowrap text-gray-900">{{ $job->driver?->name ?? '—' }}</td>
                    <td class="px-3 py-3 whitespace-nowrap text-gray-500 text-xs tabular-nums">{{ $cell ?? '—' }}</td>
                    <td class="px-3 py-3 whitespace-nowrap text-gray-900">{{ trim(($job->brand?->name ?? '') . ' ' . ($job->model_name ?? '')) ?: '—' }}</td>
                    <td class="px-3 py-3 whitespace-nowrap font-mono text-xs text-gray-600 uppercase">{{ $job->vin ? strtoupper($job->vin) : '—' }}</td>
                    <td class="px-3 py-3 text-gray-600 text-xs">{{ $job->pickupLocation?->company_name ?? ($job->isYardWork() ? 'Yard Work' : '—') }}</td>
                    <td class="px-3 py-3 text-gray-600 text-xs">{{ $job->deliveryLocation?->company_name ?? '—' }}</td>
                    <td class="px-3 py-3 whitespace-nowrap text-gray-500 text-xs">{{ $job->collected_at?->format('d M') ?? '—' }}</td>
                    <td class="px-3 py-3 whitespace-nowrap text-gray-500 text-xs">{{ $job->delivered_at?->format('d M') ?? '—' }}</td>
                    <td class="px-3 py-3 whitespace-nowrap"><x-status-badge :status="$job->status" /></td>
                    <td class="px-3 py-3 text-xs text-gray-500 max-w-[14rem] truncate" title="{{ $commentText }}">{{ $commentText ?: '—' }}</td>
                    <td class="px-3 py-3 text-center whitespace-nowrap" onclick="event.stopPropagation()">
                        <div class="flex items-center justify-center gap-1">
                            @if($job->purchaseOrders->isNotEmpty() && $job->purchaseOrders->first()->document_path)
                                <button wire:click="quickView({{ $job->id }})" class="rounded-md bg-blue-50 px-2 py-1 text-xs font-medium text-blue-700 hover:bg-blue-100" title="Quick View PO & Actions">
                                    <svg class="h-4 w-4 inline" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                                    PO
                                </button>
                            @endif
                            @if($job->status === 'pending_verification' && auth()->user()->canApproveBookings())
                                <button wire:click="quickVerify({{ $job->id }})" wire:confirm="Verify this booking?" class="rounded-md bg-green-50 px-2 py-1 text-xs font-medium text-green-700 hover:bg-green-100" title="Verify">
                                    <svg class="h-4 w-4 inline" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/></svg>
                                </button>
                            @endif
                            @if($job->status === 'verified' && auth()->user()->canApproveBookings())
                                <button wire:click="openApproveModal({{ $job->id }})" class="rounded-md bg-emerald-50 px-2 py-1 text-xs font-medium text-emerald-700 hover:bg-emerald-100" title="Approve & Assign Driver">
                                    <svg class="h-4 w-4 inline" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                                </button>
                            @endif
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="14" class="px-6 py-12 text-center text-sm text-gray-500">No bookings found.</td>
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
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
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
                            <button wire:click="openApproveModal({{ $qvJob->id }})" class="w-full rounded-lg bg-green-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-green-500 transition-colors">
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

    {{-- Approve & Assign Driver Modal --}}
    @if($approveJobId)
    @php $approveJob = $jobs->firstWhere('id', $approveJobId) ?? \App\Models\Job::with('company')->find($approveJobId); @endphp
    @if($approveJob)
    <div class="fixed inset-0 z-[60] flex items-center justify-center bg-black/60" wire:click.self="closeApproveModal">
        <div class="relative w-full max-w-md mx-4 bg-white rounded-2xl shadow-2xl overflow-hidden">
            <div class="border-b border-gray-200 px-6 py-4">
                <h3 class="text-lg font-semibold text-gray-900">Approve Booking</h3>
                <p class="text-sm text-gray-500 mt-0.5">{{ $approveJob->job_number }} &middot; {{ $approveJob->company?->name }}</p>
            </div>

            <div class="px-6 py-5 space-y-5">
                <div class="rounded-lg bg-green-50 border border-green-200 p-4">
                    <div class="flex items-start gap-3">
                        <svg class="h-5 w-5 text-green-600 mt-0.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/></svg>
                        <div class="text-sm">
                            <p class="font-medium text-green-800">PO Verified</p>
                            <p class="text-green-700 mt-0.5">This booking has been verified and is ready for approval.</p>
                        </div>
                    </div>
                </div>

                <div>
                    <label for="indexApproveDriverSelect" class="block text-sm font-medium text-gray-700 mb-1.5">Assign Driver <span class="text-gray-400 font-normal">(optional)</span></label>
                    <select wire:model="approveDriverId" id="indexApproveDriverSelect" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-purple-500 focus:ring-purple-500">
                        <option value="">Skip — assign later</option>
                        @foreach($drivers as $d)
                            <option value="{{ $d->id }}">{{ $d->name }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1.5 text-xs text-gray-500">Selecting a driver will also mark the booking as assigned.</p>
                </div>
            </div>

            <div class="border-t border-gray-200 px-6 py-4 flex items-center justify-end gap-3 bg-gray-50">
                <button wire:click="closeApproveModal" class="rounded-lg px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 transition-colors">
                    Cancel
                </button>
                <button wire:click="approveAndAssign" class="rounded-lg bg-green-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-green-500 transition-colors">
                    Approve{{ $approveDriverId ? ' & Assign' : '' }}
                </button>
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
                        <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
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
