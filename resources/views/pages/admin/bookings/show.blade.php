<?php

use App\Models\Job;
use App\Models\User;
use App\Models\JobDocument;
use App\Services\AuditService;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;

new #[Layout('components.layouts.app')] class extends Component {
    public Job $job;
    public bool $showVerifyPanel = false;
    public array $verificationChecklist = [];
    public string $rejectionReason = '';
    public ?int $assignDriverId = null;

    public function mount(Job $job): void
    {
        $this->job = $job->load(['company', 'fromHub', 'toHub', 'yardHub', 'brand', 'driver', 'createdBy', 'documents', 'events']);
    }

    public function verify(): void
    {
        $this->authorize('verify', $this->job);
        $this->job->transitionTo(Job::STATUS_VERIFIED);
        $this->job->po_verified = true;
        $this->job->po_verified_at = now();
        $this->job->po_verified_by = auth()->id();
        $this->job->save();
        AuditService::log('po_verified', 'job', $this->job->id, null, ['status' => 'verified']);
        session()->flash('success', 'Booking verified successfully.');
    }

    public function approve(): void
    {
        $this->authorize('approve', $this->job);
        $this->job->transitionTo(Job::STATUS_APPROVED);
        AuditService::log('approved', 'job', $this->job->id);
        session()->flash('success', 'Booking approved.');
    }

    public function reject(): void
    {
        $this->authorize('verify', $this->job);
        $this->validate(['rejectionReason' => 'required|min:10']);
        $before = ['status' => $this->job->status];
        $this->job->status = Job::STATUS_REJECTED;
        $this->job->save();
        AuditService::log('rejected', 'job', $this->job->id, $before, ['status' => 'rejected'], $this->rejectionReason);
        session()->flash('success', 'Booking rejected.');
    }

    public function assignDriver(): void
    {
        $this->authorize('assignDriver', $this->job);
        $this->validate(['assignDriverId' => 'required|exists:users,id']);

        $driver = User::findOrFail($this->assignDriverId);
        $this->job->driver_user_id = $driver->id;

        if ($this->job->status === Job::STATUS_APPROVED) {
            $this->job->transitionTo(Job::STATUS_ASSIGNED);
        } else {
            $this->job->save();
        }

        AuditService::log('driver_assigned', 'job', $this->job->id, null, ['driver_id' => $driver->id, 'driver_name' => $driver->name]);
        session()->flash('success', "Driver {$driver->name} assigned.");
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
        Job {{ $job->job_number ?? $job->uuid }}
    </x-slot:header>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Left: Job details --}}
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-900">Booking Details</h3>
                    <x-status-badge :status="$job->status" />
                </div>

                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                    <div><dt class="text-gray-500">Type</dt><dd class="font-medium">{{ $job->isTransport() ? 'Transport' : 'Yard Work' }}</dd></div>
                    <div><dt class="text-gray-500">Company</dt><dd class="font-medium">{{ $job->company?->name }}</dd></div>
                    <div><dt class="text-gray-500">Created By</dt><dd class="font-medium">{{ $job->createdBy?->name }}</dd></div>
                    <div><dt class="text-gray-500">Scheduled Date</dt><dd class="font-medium">{{ $job->scheduled_date?->format('d M Y') }}</dd></div>

                    @if($job->isTransport())
                        <div><dt class="text-gray-500">From</dt><dd class="font-medium">{{ $job->fromHub?->name }}</dd></div>
                        <div><dt class="text-gray-500">To</dt><dd class="font-medium">{{ $job->toHub?->name }}</dd></div>
                        <div><dt class="text-gray-500">Brand</dt><dd class="font-medium">{{ $job->brand?->name }}</dd></div>
                        <div><dt class="text-gray-500">Model</dt><dd class="font-medium">{{ $job->model_name ?? '—' }}</dd></div>
                        <div><dt class="text-gray-500">VIN</dt><dd class="font-medium font-mono">{{ $job->vin ?? '—' }}</dd></div>
                        <div><dt class="text-gray-500">Ready Time</dt><dd class="font-medium">{{ $job->scheduled_ready_time?->format('H:i') ?? '—' }}</dd></div>
                    @else
                        <div><dt class="text-gray-500">Yard</dt><dd class="font-medium">{{ $job->yardHub?->name }}</dd></div>
                        <div><dt class="text-gray-500">Drivers Required</dt><dd class="font-medium">{{ $job->drivers_required }}</dd></div>
                        <div><dt class="text-gray-500">Hours Required</dt><dd class="font-medium">{{ $job->hours_required }}</dd></div>
                        <div><dt class="text-gray-500">Hourly Rate</dt><dd class="font-medium">R{{ number_format($job->hourly_rate, 2) }}</dd></div>
                    @endif

                    <div><dt class="text-gray-500">PO Number</dt><dd class="font-medium">{{ $job->po_number ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">PO Amount</dt><dd class="font-medium">R{{ number_format($job->po_amount ?? 0, 2) }}</dd></div>
                </dl>
            </div>

            {{-- Documents --}}
            @if($job->documents->isNotEmpty())
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Documents</h3>
                <ul class="divide-y divide-gray-200">
                    @foreach($job->documents as $doc)
                    <li class="py-3 flex justify-between items-center">
                        <div>
                            <p class="text-sm font-medium text-gray-900">{{ $doc->original_filename }}</p>
                            <p class="text-xs text-gray-500">{{ ucfirst(str_replace('_', ' ', $doc->category)) }} &middot; {{ number_format($doc->size_bytes / 1024, 1) }} KB</p>
                        </div>
                    </li>
                    @endforeach
                </ul>
            </div>
            @endif

            {{-- Timeline --}}
            @if($job->events->isNotEmpty())
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Job Timeline</h3>
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

        {{-- Right: Actions --}}
        <div class="space-y-6">
            {{-- Actions card --}}
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Actions</h3>
                <div class="space-y-3">
                    @if($job->status === 'pending_verification' && auth()->user()->canApproveBookings())
                        <button wire:click="verify" wire:confirm="Verify this booking and PO?" class="w-full rounded-lg bg-blue-600 px-4 py-3 text-sm font-semibold text-white hover:bg-blue-500 transition-colors">
                            Verify & Approve PO
                        </button>
                    @endif

                    @if($job->status === 'verified' && auth()->user()->canApproveBookings())
                        <button wire:click="approve" wire:confirm="Approve this booking?" class="w-full rounded-lg bg-green-600 px-4 py-3 text-sm font-semibold text-white hover:bg-green-500 transition-colors">
                            Approve Booking
                        </button>
                    @endif

                    @if(in_array($job->status, ['pending_verification', 'verified']) && auth()->user()->canApproveBookings())
                        <div>
                            <textarea wire:model="rejectionReason" rows="2" placeholder="Rejection reason..." class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"></textarea>
                            <button wire:click="reject" class="mt-2 w-full rounded-lg bg-red-600 px-4 py-3 text-sm font-semibold text-white hover:bg-red-500 transition-colors">
                                Reject
                            </button>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Driver Assignment --}}
            @if(in_array($job->status, ['approved', 'assigned']) && auth()->user()->canAssignDrivers())
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Assign Driver</h3>
                @if($job->driver)
                    <p class="text-sm text-gray-600 mb-3">Currently: <strong>{{ $job->driver->name }}</strong></p>
                @endif
                <select wire:model="assignDriverId" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm mb-3">
                    <option value="">Select driver...</option>
                    @foreach($drivers as $d)
                        <option value="{{ $d->id }}">{{ $d->name }}</option>
                    @endforeach
                </select>
                <button wire:click="assignDriver" class="w-full rounded-lg bg-purple-600 px-4 py-3 text-sm font-semibold text-white hover:bg-purple-500 transition-colors">
                    Assign Driver
                </button>
            </div>
            @endif

            {{-- Job Info --}}
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Info</h3>
                <dl class="space-y-3 text-sm">
                    <div><dt class="text-gray-500">Job Number</dt><dd class="font-mono">{{ $job->job_number ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">UUID</dt><dd class="font-mono text-xs break-all">{{ $job->uuid }}</dd></div>
                    <div><dt class="text-gray-500">Created</dt><dd>{{ $job->created_at->format('d M Y H:i') }}</dd></div>
                    @if($job->driver)
                    <div><dt class="text-gray-500">Driver</dt><dd>{{ $job->driver->name }}</dd></div>
                    @endif
                    @if($job->po_verified)
                    <div><dt class="text-gray-500">PO Verified</dt><dd>{{ $job->po_verified_at?->format('d M Y H:i') }}</dd></div>
                    @endif
                </dl>
            </div>
        </div>
    </div>
</div>
