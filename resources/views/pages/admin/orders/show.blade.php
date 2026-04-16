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

    public function markReady(): void
    {
        if (!$this->job->transitionTo(Job::STATUS_READY_FOR_COLLECTION)) {
            session()->flash('error', 'Cannot mark as ready.');
            return;
        }
        AuditService::log('ready_for_collection', 'job', $this->job->id);
        session()->flash('success', "Order {$this->job->job_number} ready for collection.");
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
                    <div><dt class="text-gray-500">VIN</dt><dd class="font-medium font-mono uppercase">{{ strtoupper($job->vin ?? '') ?: '—' }}</dd></div>
                    @if($job->registration)
                    <div><dt class="text-gray-500">Registration</dt><dd class="font-medium uppercase">{{ $job->registration }}</dd></div>
                    @endif
                    <div><dt class="text-gray-500">Scheduled Date</dt><dd class="font-medium">{{ $job->scheduled_date?->format('d M Y') ?? '—' }}</dd></div>
                </dl>
            </div>

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
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Assigned Driver</h3>
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                    <div><dt class="text-gray-500">Name</dt><dd class="font-medium">{{ $job->driver->name }}</dd></div>
                    @if($job->driver->phone)
                    <div><dt class="text-gray-500">Phone</dt><dd class="font-medium">{{ $job->driver->phone }}</dd></div>
                    @endif
                    @if($job->driver->driverProfile)
                        @if($job->driver->driverProfile->id_number)
                        <div><dt class="text-gray-500">ID Number</dt><dd class="font-medium">{{ $job->driver->driverProfile->id_number }}</dd></div>
                        @endif
                        @if($job->driver->driverProfile->cellphone)
                        <div><dt class="text-gray-500">Cellphone</dt><dd class="font-medium">{{ $job->driver->driverProfile->cellphone }}</dd></div>
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

        {{-- Right column: Actions --}}
        <div class="space-y-6">
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Actions</h3>
                <div class="space-y-3">

                    @if($job->status === 'received')
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
                    @endif

                    @if($job->status === 'awaiting_customer_confirmation')
                        <div class="rounded-lg bg-amber-50 border border-amber-200 p-4 text-sm text-amber-800">
                            <div class="flex items-start gap-2">
                                <svg class="h-5 w-5 text-amber-500 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                <span>Waiting for customer confirmation.</span>
                            </div>
                        </div>
                    @endif

                    @if($job->status === 'confirmed')
                        <button wire:click="planOrder" wire:confirm="Mark as planned?"
                            class="w-full rounded-lg bg-indigo-600 px-4 py-3 text-sm font-semibold text-white hover:bg-indigo-500 transition-colors">
                            Plan Order
                        </button>
                    @endif

                    @if($job->status === 'planned')
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
                    @endif

                    @if($job->status === 'driver_assigned')
                        <button wire:click="markReady" wire:confirm="Mark as ready for collection?"
                            class="w-full rounded-lg bg-cyan-600 px-4 py-3 text-sm font-semibold text-white hover:bg-cyan-500 transition-colors">
                            Ready for Collection
                        </button>
                    @endif

                    @if($job->status === 'ready_for_collection')
                        <button wire:click="markCollected" wire:confirm="Mark as collected?"
                            class="w-full rounded-lg bg-teal-600 px-4 py-3 text-sm font-semibold text-white hover:bg-teal-500 transition-colors">
                            Mark Collected
                        </button>
                    @endif

                    @if($job->status === 'collected')
                        <button wire:click="markInTransit" wire:confirm="Mark as in transit?"
                            class="w-full rounded-lg bg-orange-600 px-4 py-3 text-sm font-semibold text-white hover:bg-orange-500 transition-colors">
                            Mark In Transit
                        </button>
                    @endif

                    @if($job->status === 'in_transit')
                        <button wire:click="markDelivered" wire:confirm="Mark as delivered?"
                            class="w-full rounded-lg bg-emerald-600 px-4 py-3 text-sm font-semibold text-white hover:bg-emerald-500 transition-colors">
                            Mark Delivered
                        </button>
                    @endif

                    @if($job->status === 'delivered')
                        <button wire:click="completeOrder" wire:confirm="Complete this order?"
                            class="w-full rounded-lg bg-green-600 px-4 py-3 text-sm font-semibold text-white hover:bg-green-500 transition-colors">
                            Complete Order
                        </button>
                    @endif

                    @can('generateCollectionNote', $job)
                        <a href="{{ route('collection-note.download', $job) }}" target="_blank"
                            class="flex w-full items-center justify-center gap-2 rounded-lg border border-blue-600 bg-white px-4 py-3 text-sm font-semibold text-blue-700 hover:bg-blue-50 transition-colors">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                            Collection Note
                        </a>
                    @endcan

                    @if(!in_array($job->status, ['completed', 'cancelled']))
                        <button wire:click="openCancelModal"
                            class="w-full rounded-lg bg-red-600 px-4 py-3 text-sm font-semibold text-white hover:bg-red-500 transition-colors">
                            Cancel Order
                        </button>
                    @endif

                    @if(in_array($job->status, ['completed', 'cancelled']))
                        <p class="text-sm text-gray-400 text-center py-2">No actions available.</p>
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
