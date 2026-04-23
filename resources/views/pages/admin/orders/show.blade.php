<?php

use App\Models\Job;
use App\Models\User;
use App\Services\AuditService;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;

new #[Layout('components.layouts.app')] class extends Component {
    public Job $job;
    public ?int $driverId = null;
    public string $cancelReason = '';
    public bool $showCancelModal = false;

    public function mount(Job $job): void
    {
        $this->job = $job->load([
            'company',
            'pickupLocation',
            'deliveryLocation',
            'driver',
            'driver.driverProfile',
            'documents',
            'purchaseOrders',
            'events',
            'brand',
            'createdBy.companies',
        ]);
    }

    public function confirmOrder(): void
    {
        if (!$this->job->transitionTo(Job::STATUS_CONFIRMED)) {
            session()->flash('error', 'Cannot confirm this order.');
            return;
        }
        AuditService::log('order_confirmed', 'job', $this->job->id);
        session()->flash('success', "Order {$this->job->job_number} confirmed.");
    }

    /**
     * One-click verification bridge out of the legacy PENDING_VERIFICATION
     * state into the Phase 1 chain at RECEIVED. Ops would previously have
     * had to walk the job through verified → approved → awaiting confirmation
     * on /admin/bookings; this collapses all of that into a single action
     * on the order detail page where they're already looking at the PO.
     */
    public function verifyBooking(): void
    {
        $this->authorize('verify', $this->job);

        $before = ['status' => $this->job->status];

        if (!$this->job->transitionTo(Job::STATUS_RECEIVED)) {
            session()->flash('error', 'Cannot verify this booking in its current state.');
            return;
        }

        $this->job->po_verified = true;
        $this->job->po_verified_at = now();
        $this->job->save();

        AuditService::log('verified', 'job', $this->job->id, $before, [
            'status' => $this->job->status,
            'po_verified' => true,
        ]);
        session()->flash('success', "Booking {$this->job->job_number} verified.");
    }

    public function sendToCustomer(): void
    {
        if (!$this->job->transitionTo(Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION)) {
            session()->flash('error', 'Cannot send to customer.');
            return;
        }
        AuditService::log('sent_to_customer', 'job', $this->job->id);
        session()->flash('success', "Order {$this->job->job_number} sent for customer confirmation.");
    }

    public function planOrder(): void
    {
        if (!$this->job->transitionTo(Job::STATUS_PLANNED)) {
            session()->flash('error', 'Cannot plan this order.');
            return;
        }
        AuditService::log('order_planned', 'job', $this->job->id);
        session()->flash('success', "Order {$this->job->job_number} marked as planned.");
    }

    public function assignDriver(): void
    {
        $this->validate(['driverId' => 'required|exists:users,id']);

        $driver = User::findOrFail($this->driverId);
        $this->job->driver_user_id = $driver->id;
        $this->job->save();

        if (!$this->job->transitionTo(Job::STATUS_DRIVER_ASSIGNED)) {
            session()->flash('error', 'Cannot assign driver at this stage.');
            return;
        }

        AuditService::log('driver_assigned', 'job', $this->job->id, null, [
            'driver_id' => $driver->id,
            'driver_name' => $driver->name,
        ]);
        $this->job->load(['driver', 'driver.driverProfile']);
        session()->flash('success', "Driver {$driver->name} assigned.");
    }

    public function markCollected(): void
    {
        if (!$this->job->transitionTo(Job::STATUS_COLLECTED)) {
            session()->flash('error', 'Cannot mark as collected.');
            return;
        }
        AuditService::log('collected', 'job', $this->job->id);
        session()->flash('success', "Order {$this->job->job_number} marked as collected.");
    }

    public function markInTransit(): void
    {
        if (!$this->job->transitionTo(Job::STATUS_IN_TRANSIT)) {
            session()->flash('error', 'Cannot mark as in transit.');
            return;
        }
        AuditService::log('in_transit', 'job', $this->job->id);
        session()->flash('success', "Order {$this->job->job_number} is now in transit.");
    }

    public function markDelivered(): void
    {
        if (!$this->job->transitionTo(Job::STATUS_DELIVERED)) {
            session()->flash('error', 'Cannot mark as delivered.');
            return;
        }
        AuditService::log('delivered', 'job', $this->job->id);
        session()->flash('success', "Order {$this->job->job_number} marked as delivered.");
    }

    public function completeOrder(): void
    {
        if (!$this->job->transitionTo(Job::STATUS_COMPLETED)) {
            session()->flash('error', 'Cannot complete this order.');
            return;
        }
        AuditService::log('order_completed', 'job', $this->job->id);
        session()->flash('success', "Order {$this->job->job_number} completed.");
    }

    public function openCancelModal(): void
    {
        $this->cancelReason = '';
        $this->showCancelModal = true;
    }

    public function closeCancelModal(): void
    {
        $this->showCancelModal = false;
    }

    public function cancelOrder(): void
    {
        $this->validate(['cancelReason' => 'required|min:5']);

        $this->job->cancellation_reason = $this->cancelReason;
        $this->job->save();

        if (!$this->job->transitionTo(Job::STATUS_CANCELLED)) {
            session()->flash('error', 'Cannot cancel this order.');
            return;
        }

        AuditService::log('order_cancelled', 'job', $this->job->id, null, [
            'reason' => $this->cancelReason,
        ]);
        $this->showCancelModal = false;
        session()->flash('success', "Order {$this->job->job_number} cancelled.");
    }

    public function with(): array
    {
        $drivers = User::whereHas('roles', fn($q) => $q->where('slug', 'driver'))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return ['drivers' => $drivers];
    }
};

?>

<div>
    <x-slot:header>
        <div class="flex items-center gap-3">
            <a href="{{ route('admin.orders.index') }}" class="text-gray-400 hover:text-gray-600">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
            </a>
            <span>Order {{ $job->job_number ?? $job->uuid }}</span>
        </div>
    </x-slot:header>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Left column --}}
        <div class="lg:col-span-2 space-y-6">

            {{-- Header card --}}
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">{{ $job->job_number }}</h3>
                        <p class="text-sm text-gray-500">{{ $job->company?->name }}</p>
                    </div>
                    <x-status-badge :status="$job->status" />
                </div>
                <p class="text-sm text-gray-600">{{ $job->phase1StatusLabel() }}</p>
            </div>

            {{-- Order details --}}
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Order Details</h3>
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                    <div><dt class="text-gray-500">Type</dt><dd class="font-medium">{{ $job->isTransport() ? 'Transport' : 'Yard Work' }}</dd></div>
                    <div><dt class="text-gray-500">Company</dt><dd class="font-medium">{{ $job->company?->name ?? '—' }}</dd></div>
                    @if($job->brand)
                    <div><dt class="text-gray-500">Brand</dt><dd class="font-medium">{{ $job->brand->name }}</dd></div>
                    @endif
                    @if($job->model_name)
                    <div><dt class="text-gray-500">Model</dt><dd class="font-medium">{{ $job->model_name }}</dd></div>
                    @endif
                    <div><dt class="text-gray-500">VIN</dt><dd class="font-medium">{{ $job->vin ?: '—' }}</dd></div>
                    @if($job->registration)
                    <div><dt class="text-gray-500">Registration</dt><dd class="font-medium">{{ $job->registration }}</dd></div>
                    @endif
                    <div><dt class="text-gray-500">Scheduled Date</dt><dd class="font-medium">{{ $job->scheduled_date?->format('d M Y') ?? '—' }}</dd></div>
                </dl>
            </div>

            {{-- Special Instructions --}}
            @if(trim($job->customer_notes ?? '') !== '')
            <div class="bg-amber-50 rounded-xl shadow-sm border border-amber-200 p-6">
                <div class="flex items-start gap-3">
                    <svg class="h-5 w-5 text-amber-600 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/><line x1="12" x2="12" y1="9" y2="13"/><line x1="12" x2="12.01" y1="17" y2="17"/></svg>
                    <div class="min-w-0 flex-1">
                        <h3 class="text-sm font-semibold text-amber-900">Special Instructions</h3>
                        <p class="mt-1 text-sm text-amber-900 whitespace-pre-line">{{ $job->customer_notes }}</p>
                    </div>
                </div>
            </div>
            @endif

            {{-- Pickup & Delivery --}}
            @if($job->isTransport())
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <h4 class="text-sm font-semibold text-gray-700 mb-3 flex items-center gap-2">
                        <svg class="h-4 w-4 text-green-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                        Pickup
                    </h4>
                    <p class="text-sm font-medium text-gray-900">{{ $job->pickupLocation?->shortDisplay() ?? '—' }}</p>
                    @if($job->pickup_contact_name)
                        <p class="text-sm text-gray-600 mt-2">{{ $job->pickup_contact_name }}</p>
                    @endif
                    @if($job->pickup_contact_phone)
                        <p class="text-sm text-gray-500">{{ $job->pickup_contact_phone }}</p>
                    @endif
                </div>
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <h4 class="text-sm font-semibold text-gray-700 mb-3 flex items-center gap-2">
                        <svg class="h-4 w-4 text-red-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                        Delivery
                    </h4>
                    <p class="text-sm font-medium text-gray-900">{{ $job->deliveryLocation?->shortDisplay() ?? '—' }}</p>
                    @if($job->delivery_contact_name)
                        <p class="text-sm text-gray-600 mt-2">{{ $job->delivery_contact_name }}</p>
                    @endif
                    @if($job->delivery_contact_phone)
                        <p class="text-sm text-gray-500">{{ $job->delivery_contact_phone }}</p>
                    @endif
                </div>
            </div>
            @endif

            {{-- Driver section --}}
            @if($job->driver)
            @php
                $dp = $job->driver->driverProfile;
                // When a vehicle is collected or in transit, the tracker ID is
                // the single most useful piece of info on this page — ops needs
                // it to pull a live location. Pin it to the top of the driver
                // card in that window so nobody has to hunt for it.
                $isInFlight = in_array($job->status, [
                    \App\Models\Job::STATUS_COLLECTED,
                    \App\Models\Job::STATUS_IN_TRANSIT,
                    \App\Models\Job::STATUS_IN_PROGRESS,
                ], true);
            @endphp
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Assigned Driver</h3>

                @if($isInFlight && $dp?->tracker_id)
                    <div class="mb-4 flex items-center gap-3 rounded-lg bg-emerald-50 border border-emerald-200 p-3">
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-emerald-500 text-white">
                            <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a8 8 0 0 1 8 8c0 4.5-6 12-8 12S4 14.5 4 10a8 8 0 0 1 8-8z"/><circle cx="12" cy="10" r="3"/></svg>
                        </span>
                        <div class="flex-1">
                            <p class="text-[10px] font-semibold uppercase tracking-[0.15em] text-emerald-700">Live Tracker</p>
                            <p class="font-mono text-sm font-semibold text-emerald-900">{{ $dp->tracker_id }}</p>
                        </div>
                    </div>
                @endif

                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                    <div><dt class="text-gray-500">Name</dt><dd class="font-medium">{{ $job->driver->name }}</dd></div>
                    @if($job->driver->phone)
                    <div><dt class="text-gray-500">Phone</dt><dd class="font-medium">{{ $job->driver->phone }}</dd></div>
                    @endif
                    @if($dp)
                        @if($dp->id_number)
                        <div><dt class="text-gray-500">ID Number</dt><dd class="font-medium">{{ $dp->id_number }}</dd></div>
                        @endif
                        @if($dp->cellphone)
                        <div><dt class="text-gray-500">Cellphone</dt><dd class="font-medium">{{ $dp->cellphone }}</dd></div>
                        @endif
                        @if($dp->tracker_id && !$isInFlight)
                        <div><dt class="text-gray-500">Tracker ID</dt><dd class="font-medium font-mono">{{ $dp->tracker_id }}</dd></div>
                        @endif
                        @if($dp->camera_id)
                        <div><dt class="text-gray-500">Camera ID</dt><dd class="font-medium font-mono">{{ $dp->camera_id }}</dd></div>
                        @endif
                        @if($dp->toll_card_number)
                        <div><dt class="text-gray-500">Toll Card</dt><dd class="font-medium font-mono">{{ $dp->toll_card_number }}</dd></div>
                        @endif
                    @endif
                </dl>
            </div>
            @endif

            {{-- Documents & Purchase Orders --}}
            @if($job->documents->isNotEmpty() || $job->purchaseOrders->isNotEmpty())
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Documents</h3>

                @if($job->purchaseOrders->isNotEmpty())
                <div class="mb-4">
                    <h4 class="text-sm font-semibold text-gray-700 mb-2">Purchase Orders</h4>
                    <ul class="divide-y divide-gray-100">
                        @foreach($job->purchaseOrders as $po)
                        <li class="py-2 flex items-center justify-between">
                            <div class="text-sm">
                                <span class="font-medium text-gray-900">{{ $po->po_number }}</span>
                                <span class="text-gray-500 ml-2">R{{ number_format($po->po_amount, 2) }}</span>
                            </div>
                            @if($po->document_path)
                            <a href="{{ route('po.preview', $po->id) }}" target="_blank" class="text-xs font-medium text-blue-600 hover:text-blue-800">Download</a>
                            @endif
                        </li>
                        @endforeach
                    </ul>
                </div>
                @endif

                @if($job->documents->isNotEmpty())
                <div>
                    <h4 class="text-sm font-semibold text-gray-700 mb-2">Uploaded Documents</h4>
                    <ul class="divide-y divide-gray-100">
                        @foreach($job->documents as $doc)
                        <li class="py-2 flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-900">{{ $doc->original_filename }}</p>
                                <p class="text-xs text-gray-500">{{ ucfirst(str_replace('_', ' ', $doc->category)) }} &middot; {{ number_format($doc->size_bytes / 1024, 1) }} KB</p>
                            </div>
                        </li>
                        @endforeach
                    </ul>
                </div>
                @endif
            </div>
            @endif

            {{-- Timeline --}}
            @if($job->events->isNotEmpty())
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Timeline</h3>
                <ol class="relative border-l border-gray-200 ml-3 space-y-6">
                    @foreach($job->events as $event)
                    <li class="ml-6">
                        <span class="absolute -left-2 flex h-4 w-4 items-center justify-center rounded-full bg-blue-100 ring-4 ring-white">
                            <span class="h-2 w-2 rounded-full bg-blue-600"></span>
                        </span>
                        <h4 class="text-sm font-medium text-gray-900">{{ ucfirst(str_replace('_', ' ', $event->event_type)) }}</h4>
                        <time class="text-xs text-gray-500">{{ $event->event_at->format('d M Y H:i') }}</time>
                        @if($event->notes)<p class="mt-1 text-sm text-gray-600">{{ $event->notes }}</p>@endif
                    </li>
                    @endforeach
                </ol>
            </div>
            @endif
        </div>

        {{-- Right column: Actions — priority is driven by status. When a driver has
             been assigned, Printing the Delivery Paperwork becomes the most important
             next step and sits above Mark Collected. Once the vehicle has been
             collected, the status-change button takes over as primary and the
             Collection Note demotes to a reprint-style secondary button below it.
             Cancel is only allowed while the vehicle is still on the depot. --}}
        @php
            $preReleaseStatuses = [
                Job::STATUS_DRIVER_ASSIGNED,
                Job::STATUS_READY_FOR_COLLECTION,
            ];
            $inFlightStatuses = [
                Job::STATUS_COLLECTED,
                Job::STATUS_IN_TRANSIT,
                Job::STATUS_DELIVERED,
            ];
            $cancellableStatuses = [
                Job::STATUS_RECEIVED,
                Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION,
                Job::STATUS_CONFIRMED,
                Job::STATUS_PLANNED,
                Job::STATUS_DRIVER_ASSIGNED,
                Job::STATUS_READY_FOR_COLLECTION,
            ];
            $isPreRelease = in_array($job->status, $preReleaseStatuses, true);
            $isInFlight = in_array($job->status, $inFlightStatuses, true);
            $isCancellable = in_array($job->status, $cancellableStatuses, true);
            $isTerminal = in_array($job->status, [Job::STATUS_COMPLETED, Job::STATUS_CANCELLED], true);
        @endphp
        <div class="space-y-6">
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Actions</h3>
                <div class="space-y-3">

                    {{-- =========================================================
                         1. PAPERWORK PROMPT (top priority when driver assigned)
                         ========================================================= --}}
                    @can('generateCollectionNote', $job)
                        @if($isPreRelease)
                            <div class="rounded-lg border border-green-300 bg-green-50 p-3.5">
                                <div class="flex items-start gap-2.5 mb-3">
                                    <svg class="h-5 w-5 text-green-600 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                                    <div class="min-w-0">
                                        <h4 class="text-sm font-semibold text-green-900">Print Delivery Paperwork</h4>
                                        <p class="mt-0.5 text-xs text-green-800 leading-snug">POD, Collection Note &amp; Manual Inspection Sheet &mdash; 4 pages. Print double-sided and hand to the driver before the vehicle leaves.</p>
                                    </div>
                                </div>
                                <a href="{{ route('collection-note.download', $job) }}" target="_blank"
                                    class="flex w-full items-center justify-center gap-2 rounded-lg bg-green-600 px-4 py-3 text-sm font-semibold text-white hover:bg-green-500 transition-colors shadow-sm">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                                    Print Paperwork
                                </a>
                            </div>
                        @endif
                    @endcan

                    {{-- =========================================================
                         2. STATUS TRANSITION BUTTON
                         ========================================================= --}}
                    @if($job->status === Job::STATUS_PENDING_VERIFICATION)
                        @can('verify', $job)
                            <div class="rounded-lg border border-amber-300 bg-amber-50 p-3.5">
                                <div class="flex items-start gap-2.5 mb-3">
                                    <svg class="h-5 w-5 text-amber-600 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>
                                    <div class="min-w-0">
                                        <h4 class="text-sm font-semibold text-amber-900">Verify this booking</h4>
                                        <p class="mt-0.5 text-xs text-amber-800 leading-snug">Confirm the PO and the vehicle details are correct. Once verified the booking moves into the active queue and the customer flow begins.</p>
                                    </div>
                                </div>
                                <button wire:click="verifyBooking" wire:confirm="Verify this booking?"
                                    class="flex w-full items-center justify-center gap-2 rounded-lg bg-amber-600 px-4 py-3 text-sm font-semibold text-white hover:bg-amber-500 transition-colors shadow-sm">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.25" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/></svg>
                                    Verify &amp; Release to Queue
                                </button>
                            </div>
                        @else
                            <div class="rounded-lg bg-amber-50 border border-amber-200 p-4 text-sm text-amber-800">
                                <div class="flex items-start gap-2">
                                    <svg class="h-5 w-5 text-amber-500 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                    <span>Waiting for operations to verify this booking.</span>
                                </div>
                            </div>
                        @endcan
                    @elseif($job->status === Job::STATUS_RECEIVED)
                        @if($job->company?->requiresExternalConfirmation())
                            <button wire:click="sendToCustomer" wire:confirm="Send to customer for confirmation?"
                                class="w-full rounded-lg bg-amber-600 px-4 py-3 text-sm font-semibold text-white hover:bg-amber-500 transition-colors">
                                Send to Customer for Confirmation
                            </button>
                        @else
                            <button wire:click="confirmOrder" wire:confirm="Confirm this order?"
                                class="w-full rounded-lg bg-blue-600 px-4 py-3 text-sm font-semibold text-white hover:bg-blue-500 transition-colors">
                                Confirm Order
                            </button>
                        @endif
                    @elseif($job->status === Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION)
                        <div class="rounded-lg bg-amber-50 border border-amber-200 p-4 text-sm text-amber-800">
                            <div class="flex items-start gap-2">
                                <svg class="h-5 w-5 text-amber-500 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                <span>Waiting for customer confirmation.</span>
                            </div>
                        </div>
                    @elseif($job->status === Job::STATUS_CONFIRMED)
                        <button wire:click="planOrder" wire:confirm="Mark as planned?"
                            class="w-full rounded-lg bg-indigo-600 px-4 py-3 text-sm font-semibold text-white hover:bg-indigo-500 transition-colors">
                            Plan Order
                        </button>
                    @elseif($job->status === Job::STATUS_PLANNED)
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1.5">Assign Driver</label>
                            <select wire:model="driverId" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-purple-500 focus:ring-purple-500 mb-2">
                                <option value="">Select driver...</option>
                                @foreach($drivers as $d)
                                    <option value="{{ $d->id }}">{{ $d->name }}</option>
                                @endforeach
                            </select>
                            <button wire:click="assignDriver"
                                class="w-full rounded-lg bg-purple-600 px-4 py-3 text-sm font-semibold text-white hover:bg-purple-500 transition-colors">
                                Assign Driver
                            </button>
                        </div>
                    @elseif($isPreRelease)
                        {{-- Secondary to the paperwork prompt above — outlined so ops
                             only presses it once the paperwork is out and signed. --}}
                        <button wire:click="markCollected" wire:confirm="Has the paperwork been printed and handed to the driver? Mark as collected?"
                            class="w-full rounded-lg border border-teal-600 bg-white px-4 py-3 text-sm font-semibold text-teal-700 hover:bg-teal-50 transition-colors">
                            Mark Collected
                        </button>
                    @elseif($job->status === Job::STATUS_COLLECTED)
                        <button wire:click="markInTransit" wire:confirm="Mark as in transit?"
                            class="w-full rounded-lg bg-orange-600 px-4 py-3 text-sm font-semibold text-white hover:bg-orange-500 transition-colors">
                            Mark In Transit
                        </button>
                    @elseif($job->status === Job::STATUS_IN_TRANSIT)
                        <button wire:click="markDelivered" wire:confirm="Mark as delivered?"
                            class="w-full rounded-lg bg-emerald-600 px-4 py-3 text-sm font-semibold text-white hover:bg-emerald-500 transition-colors">
                            Mark Delivered
                        </button>
                    @elseif($job->status === Job::STATUS_DELIVERED)
                        <button wire:click="completeOrder" wire:confirm="Complete this order?"
                            class="w-full rounded-lg bg-green-600 px-4 py-3 text-sm font-semibold text-white hover:bg-green-500 transition-colors">
                            Complete Order
                        </button>
                    @endif

                    {{-- =========================================================
                         3. COLLECTION NOTE REPRINT (only after vehicle has left)
                         ========================================================= --}}
                    @can('generateCollectionNote', $job)
                        @if(!$isPreRelease)
                            <a href="{{ route('collection-note.download', $job) }}" target="_blank"
                                class="flex w-full items-center justify-center gap-2 rounded-lg border border-blue-600 bg-white px-4 py-3 text-sm font-semibold text-blue-700 hover:bg-blue-50 transition-colors">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                                Collection Note (reprint)
                            </a>
                        @endif
                    @endcan

                    {{-- =========================================================
                         4. CANCEL / TERMINAL STATE
                         ========================================================= --}}
                    @if($isCancellable)
                        <button wire:click="openCancelModal"
                            class="w-full rounded-lg bg-red-600 px-4 py-3 text-sm font-semibold text-white hover:bg-red-500 transition-colors">
                            Cancel Order
                        </button>
                    @elseif($isInFlight)
                        <div class="rounded-lg bg-gray-50 border border-gray-200 px-3 py-2.5 text-xs text-gray-600 leading-relaxed">
                            <strong class="text-gray-800">Cannot cancel</strong> &mdash; the vehicle has left the depot. If the order needs to be reversed, record a return movement against the destination.
                        </div>
                    @elseif($isTerminal)
                        <p class="text-sm text-gray-400 text-center py-2">No further actions.</p>
                    @endif
                </div>
            </div>

            {{-- Info card --}}
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Info</h3>
                <dl class="space-y-3 text-sm">
                    <div><dt class="text-gray-500">Order Number</dt><dd class="font-mono">{{ $job->job_number ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">UUID</dt><dd class="font-mono text-xs break-all">{{ $job->uuid }}</dd></div>
                    <div><dt class="text-gray-500">Created</dt><dd>{{ $job->created_at->format('d M Y H:i') }}</dd></div>
                    @if($job->createdBy)
                        @php $bookerCompany = $job->createdBy->companies->first(); @endphp
                        <div>
                            <dt class="text-gray-500">Booked by</dt>
                            <dd>
                                <span class="font-medium text-gray-900">{{ $job->createdBy->name }}</span>
                                @if($bookerCompany)
                                    <span class="text-gray-500"> &middot; {{ $bookerCompany->name }}</span>
                                @endif
                            </dd>
                        </div>
                    @endif
                    @if($job->scheduled_date)
                    <div><dt class="text-gray-500">Scheduled</dt><dd>{{ $job->scheduled_date->format('d M Y') }}</dd></div>
                    @endif
                    @if($job->driver)
                    <div><dt class="text-gray-500">Driver</dt><dd>{{ $job->driver->name }}</dd></div>
                    @endif
                </dl>
            </div>
        </div>
    </div>

    {{-- Cancel Order Modal --}}
    @if($showCancelModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60" wire:click.self="closeCancelModal">
        <div class="relative w-full max-w-md mx-4 bg-white rounded-2xl shadow-2xl overflow-hidden">
            <div class="border-b border-gray-200 px-6 py-4">
                <h3 class="text-lg font-semibold text-gray-900">Cancel Order</h3>
                <p class="text-sm text-gray-500 mt-0.5">{{ $job->job_number }}</p>
            </div>
            <div class="px-6 py-5 space-y-4">
                <div class="rounded-lg bg-red-50 border border-red-200 p-4 text-sm text-red-800">
                    This action cannot be undone. The order will be permanently cancelled.
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Cancellation Reason</label>
                    <textarea wire:model="cancelReason" rows="3" placeholder="Reason for cancellation..."
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-red-500 focus:ring-red-500"></textarea>
                    @error('cancelReason') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="border-t border-gray-200 px-6 py-4 flex items-center justify-end gap-3 bg-gray-50">
                <button wire:click="closeCancelModal" class="rounded-lg px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 transition-colors">
                    Keep Order
                </button>
                <button wire:click="cancelOrder" class="rounded-lg bg-red-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-red-500 transition-colors">
                    Cancel Order
                </button>
            </div>
        </div>
    </div>
    @endif
</div>
