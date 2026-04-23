<?php

use App\Models\Job;
use App\Models\Company;
use App\Models\JobDocument;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;

new #[Layout('components.layouts.app')] class extends Component {
    public Job $job;
    public bool $requiresConfirmation = false;

    public bool $showIssuePanel = false;
    public string $issueReason = '';
    public string $issueNote = '';

    public function mount(Job $job): void
    {
        $user = auth()->user();
        $company = $user->company();
        abort_unless($company && $job->company_id === $company->id, 403);

        // Location scoping for dispatcher-level customer users: mirror the
        // order list rule — you can only open an order that either collects
        // from *or* delivers to your assigned branch. Account-wide roles
        // (customer_owner / customer_admin) see every location.
        if ($user->isLocationRestricted()) {
            $locationId = $user->assignedLocationId();
            $matches = $job->pickup_location_id === $locationId
                || $job->delivery_location_id === $locationId;
            abort_unless($matches, 404);
        }

        $this->job = $job->load([
            'pickupLocation',
            'deliveryLocation',
            'brand:id,name',
            'driver:id,name,phone',
            'documents',
            'purchaseOrders',
        ]);

        $this->requiresConfirmation = $company->requiresExternalConfirmation();
    }

    public function confirmOrder(): void
    {
        $this->authorize('confirmCustomerOrder', $this->job);
        $this->job->confirmation_reason = null;
        $this->job->confirmation_note = null;
        $this->job->transitionTo(Job::STATUS_CONFIRMED);
        $this->job->refresh();
        session()->flash('success', 'Collection confirmed — TCDC operations will dispatch a driver.');
    }

    public function reportIssue(): void
    {
        $this->authorize('confirmCustomerOrder', $this->job);

        $this->validate([
            'issueReason' => 'required|in:' . implode(',', array_keys(Job::CONFIRMATION_ISSUE_REASONS)),
            'issueNote' => 'nullable|string|max:1000',
        ]);

        $this->job->reportConfirmationIssue($this->issueReason, $this->issueNote ?: null);
        $this->job->refresh();

        $this->showIssuePanel = false;
        $this->issueReason = '';
        $this->issueNote = '';

        session()->flash('success', 'Issue reported — TCDC operations has been notified.');
    }

    public function with(): array
    {
        $allDocuments = collect()
            ->merge($this->job->documents)
            ->merge(
                $this->job->purchaseOrders->map(fn ($po) => (object) [
                    'category' => 'purchase_order',
                    'original_filename' => $po->original_filename ?? $po->po_number,
                    'created_at' => $po->created_at,
                    'path' => $po->document_path,
                    'id' => $po->id,
                    'is_po' => true,
                ])
            );

        $phase1Statuses = Job::PHASE1_STATUSES;
        $currentIndex = array_search($this->job->status, $phase1Statuses);

        $canConfirm = auth()->user()->can('confirmCustomerOrder', $this->job)
            && $this->requiresConfirmation;

        return [
            'allDocuments' => $allDocuments,
            'phase1Statuses' => $phase1Statuses,
            'currentIndex' => $currentIndex !== false ? $currentIndex : -1,
            'canConfirm' => $canConfirm,
            'issueReasons' => Job::CONFIRMATION_ISSUE_REASONS,
        ];
    }
};

?>

<div>
    <x-slot:header>Order {{ $job->job_number ?? $job->uuid }}</x-slot:header>

    @if(session('success'))
        <div class="mb-4 rounded-lg bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800">{{ session('success') }}</div>
    @endif

    <div class="mb-4">
        <a href="{{ route('customer.orders.index') }}" class="inline-flex items-center gap-1 text-sm text-gray-500 hover:text-gray-700">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
            Back to Orders
        </a>
    </div>

    {{-- Status Timeline --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
        <h3 class="text-sm font-semibold text-gray-700 mb-4">Order Progress</h3>
        <div class="overflow-x-auto">
            <div class="flex items-center gap-0 min-w-max">
                @php
                    $displayStatuses = array_filter($phase1Statuses, fn($s) => !in_array($s, ['cancelled', 'confirmation_issue']));
                    $cancelledIndex = $job->status === 'cancelled';
                    $isIssue = $job->status === 'confirmation_issue';
                @endphp
                @foreach($displayStatuses as $idx => $status)
                    @php
                        $label = \App\Models\Job::PHASE1_STATUS_LABELS[$status] ?? ucfirst(str_replace('_', ' ', $status));
                        $isCurrent = $job->status === $status;
                        $isPast = !$cancelledIndex && !$isIssue && $currentIndex !== -1 && $idx < $currentIndex;
                        $isFuture = !$cancelledIndex && !$isIssue && $currentIndex !== -1 && $idx > $currentIndex;
                        if ($isIssue && $status === 'awaiting_customer_confirmation') {
                            $isCurrent = true;
                            $isPast = false;
                            $isFuture = false;
                        } elseif ($isIssue) {
                            $awaitIdx = array_search('awaiting_customer_confirmation', array_values($displayStatuses));
                            $thisIdx = array_search($status, array_values($displayStatuses));
                            $isPast = $thisIdx < $awaitIdx;
                            $isFuture = $thisIdx > $awaitIdx;
                        }
                    @endphp
                    <div class="flex items-center">
                        <div class="flex flex-col items-center">
                            <div class="flex items-center justify-center h-8 w-8 rounded-full text-xs font-bold
                                {{ $isCurrent && !$isIssue ? 'bg-blue-600 text-white ring-4 ring-blue-100' : '' }}
                                {{ $isCurrent && $isIssue ? 'bg-amber-500 text-white ring-4 ring-amber-100' : '' }}
                                {{ $isPast ? 'bg-green-500 text-white' : '' }}
                                {{ $isFuture ? 'bg-gray-200 text-gray-400' : '' }}
                            ">
                                @if($isPast)
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                                @elseif($isCurrent && $isIssue)
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                                @else
                                    {{ $idx + 1 }}
                                @endif
                            </div>
                            <span class="mt-1.5 text-[10px] font-medium text-center max-w-[80px] leading-tight
                                {{ $isCurrent && !$isIssue ? 'text-blue-700' : '' }}
                                {{ $isCurrent && $isIssue ? 'text-amber-700' : '' }}
                                {{ $isPast ? 'text-green-700' : '' }}
                                {{ $isFuture ? 'text-gray-400' : '' }}
                            ">{{ $isCurrent && $isIssue ? 'Issue Reported' : $label }}</span>
                        </div>
                        @if(!$loop->last)
                            <div class="h-0.5 w-8 mx-1 {{ $isPast ? 'bg-green-400' : 'bg-gray-200' }}"></div>
                        @endif
                    </div>
                @endforeach

                @if($cancelledIndex)
                    <div class="flex items-center ml-4">
                        <div class="flex flex-col items-center">
                            <div class="flex items-center justify-center h-8 w-8 rounded-full bg-red-500 text-white text-xs font-bold ring-4 ring-red-100">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                            </div>
                            <span class="mt-1.5 text-[10px] font-medium text-red-700">Cancelled</span>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Confirmation Issue Banner --}}
    @if($job->status === 'confirmation_issue')
        <div class="mb-6 rounded-xl border border-amber-300 bg-amber-50 p-5">
            <div class="flex items-start gap-4">
                <div class="flex-shrink-0">
                    <svg class="h-6 w-6 text-amber-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                </div>
                <div class="flex-1">
                    <h3 class="text-sm font-semibold text-amber-800">Issue Reported</h3>
                    <p class="mt-1 text-sm text-amber-700">
                        <strong>Reason:</strong> {{ \App\Models\Job::CONFIRMATION_ISSUE_REASONS[$job->confirmation_reason] ?? $job->confirmation_reason }}
                    </p>
                    @if($job->confirmation_note)
                        <p class="mt-1 text-sm text-amber-700"><strong>Details:</strong> {{ $job->confirmation_note }}</p>
                    @endif
                    <p class="mt-2 text-xs text-amber-600">TCDC operations has been notified and will follow up.</p>
                </div>
            </div>
        </div>
    @endif

    {{-- Confirm / Report Issue Panel --}}
    @if($canConfirm)
        <div class="mb-6 rounded-xl border border-yellow-300 bg-yellow-50 p-5">
            <div class="flex items-start gap-4">
                <div class="flex-shrink-0">
                    <svg class="h-6 w-6 text-yellow-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                </div>
                <div class="flex-1">
                    <h3 class="text-sm font-semibold text-yellow-800">Vehicle Verification Required</h3>
                    <p class="mt-1 text-sm text-yellow-700">Please verify: is the truck at your location and ready for collection?</p>

                    <div class="mt-4 flex flex-wrap gap-3">
                        <button wire:click="confirmOrder" wire:confirm="Confirm the truck is at your location and ready for collection?"
                            class="inline-flex items-center gap-2 rounded-lg bg-green-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-green-500 transition-colors">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                            Truck Is Here & Ready
                        </button>
                        <button wire:click="$toggle('showIssuePanel')"
                            class="inline-flex items-center gap-2 rounded-lg bg-amber-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-amber-500 transition-colors">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                            Report an Issue
                        </button>
                    </div>

                    @if($showIssuePanel)
                    <div class="mt-4 rounded-lg border border-amber-200 bg-white p-4">
                        <h4 class="text-sm font-semibold text-gray-800 mb-3">What's the issue?</h4>
                        <form wire:submit="reportIssue" class="space-y-3">
                            <div class="space-y-2">
                                @foreach($issueReasons as $key => $label)
                                <label class="flex items-start gap-3 rounded-lg border border-gray-200 px-3 py-2.5 cursor-pointer hover:bg-gray-50 transition-colors {{ $issueReason === $key ? 'border-amber-400 bg-amber-50' : '' }}">
                                    <input type="radio" wire:model="issueReason" value="{{ $key }}" class="mt-0.5 text-amber-600 focus:ring-amber-500">
                                    <span class="text-sm text-gray-700">{{ $label }}</span>
                                </label>
                                @endforeach
                            </div>
                            @error('issueReason') <p class="text-xs text-red-600">{{ $message }}</p> @enderror

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Additional details (optional)</label>
                                <textarea wire:model="issueNote" rows="3"
                                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-amber-500 focus:ring-amber-500"
                                    placeholder="Describe the issue in more detail..."></textarea>
                                @error('issueNote') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>

                            <div class="flex items-center gap-3">
                                <button type="submit" class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-500 transition-colors">
                                    Submit Issue Report
                                </button>
                                <button type="button" wire:click="$set('showIssuePanel', false)" class="text-sm text-gray-500 hover:text-gray-700">Cancel</button>
                            </div>
                        </form>
                    </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            {{-- Order Details --}}
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-900">Order Details</h3>
                    <x-status-badge :status="$job->status" />
                </div>
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                    <div>
                        <dt class="text-gray-500">Order Number</dt>
                        <dd class="font-medium text-gray-900">{{ $job->job_number ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Status</dt>
                        <dd class="font-medium text-gray-900">{{ $job->phase1StatusLabel() }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Make / Model</dt>
                        <dd class="font-medium text-gray-900">{{ $job->brand?->name }} {{ $job->model_name ?? '' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">VIN</dt>
                        <dd class="font-medium text-gray-900">{{ $job->vin ?: '—' }}</dd>
                    </div>
                    @if($job->registration)
                    <div>
                        <dt class="text-gray-500">Registration</dt>
                        <dd class="font-medium text-gray-900">{{ $job->registration }}</dd>
                    </div>
                    @endif
                    <div>
                        <dt class="text-gray-500">Scheduled Date</dt>
                        <dd class="font-medium text-gray-900">{{ $job->scheduled_date?->format('d M Y') ?? '—' }}</dd>
                    </div>
                </dl>
            </div>

            {{-- Special Instructions --}}
            @if(trim($job->customer_notes ?? '') !== '')
            <div class="bg-amber-50 rounded-xl shadow-sm border border-amber-200 p-5">
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
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <h3 class="text-sm font-semibold text-gray-700 mb-3">Pickup</h3>
                    <p class="text-sm font-medium text-gray-900">{{ $job->pickupLocation?->company_name ?? '—' }}</p>
                    @if($job->pickupLocation?->address)
                        <p class="text-sm text-gray-500 mt-1">{{ $job->pickupLocation->address }}</p>
                    @endif
                    @if($job->pickupLocation?->city)
                        <p class="text-sm text-gray-500">{{ $job->pickupLocation->city }}{{ $job->pickupLocation->province ? ', ' . $job->pickupLocation->province : '' }}</p>
                    @endif
                    @if($job->pickup_contact_name)
                        <p class="text-xs text-gray-400 mt-2">Contact: {{ $job->pickup_contact_name }} {{ $job->pickup_contact_phone ?? '' }}</p>
                    @endif
                </div>
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <h3 class="text-sm font-semibold text-gray-700 mb-3">Delivery</h3>
                    <p class="text-sm font-medium text-gray-900">{{ $job->deliveryLocation?->company_name ?? '—' }}</p>
                    @if($job->deliveryLocation?->address)
                        <p class="text-sm text-gray-500 mt-1">{{ $job->deliveryLocation->address }}</p>
                    @endif
                    @if($job->deliveryLocation?->city)
                        <p class="text-sm text-gray-500">{{ $job->deliveryLocation->city }}{{ $job->deliveryLocation->province ? ', ' . $job->deliveryLocation->province : '' }}</p>
                    @endif
                    @if($job->delivery_contact_name)
                        <p class="text-xs text-gray-400 mt-2">Contact: {{ $job->delivery_contact_name }} {{ $job->delivery_contact_phone ?? '' }}</p>
                    @endif
                </div>
            </div>

            {{-- Driver --}}
            @if($job->driver)
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-sm font-semibold text-gray-700 mb-3">Assigned Driver</h3>
                <div class="flex items-center gap-3">
                    <div class="flex items-center justify-center h-10 w-10 rounded-full bg-blue-100 text-blue-700 font-semibold text-sm">
                        {{ strtoupper(substr($job->driver->name, 0, 2)) }}
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-900">{{ $job->driver->name }}</p>
                        @if($job->driver->phone)
                            <p class="text-sm text-gray-500">{{ $job->driver->phone }}</p>
                        @endif
                    </div>
                </div>
            </div>
            @endif
        </div>

        {{-- Sidebar: Documents --}}
        <div class="space-y-6">
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Documents</h3>
                @if($allDocuments->isNotEmpty())
                    <ul class="space-y-3">
                        @foreach($allDocuments as $doc)
                        <li class="flex items-start justify-between gap-3 rounded-lg border border-gray-100 bg-gray-50 px-3 py-2.5">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-gray-900 truncate">{{ $doc->original_filename }}</p>
                                <p class="text-xs text-gray-500">{{ ucfirst(str_replace('_', ' ', $doc->category)) }}</p>
                                <p class="text-xs text-gray-400">{{ $doc->created_at?->format('d M Y') }}</p>
                            </div>
                            @if(!empty($doc->is_po) && $doc->is_po)
                                <a href="{{ route('po.preview', $doc->id) }}" target="_blank" class="shrink-0 rounded-md bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700 hover:bg-blue-100">
                                    View
                                </a>
                            @elseif($doc->path)
                                <a href="{{ Storage::url($doc->path) }}" target="_blank" class="shrink-0 rounded-md bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700 hover:bg-blue-100">
                                    Download
                                </a>
                            @endif
                        </li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-sm text-gray-500">No documents uploaded yet.</p>
                @endif
            </div>

            {{-- Key Dates --}}
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-sm font-semibold text-gray-700 mb-3">Key Dates</h3>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Created</dt>
                        <dd class="text-gray-900">{{ $job->created_at->format('d M Y') }}</dd>
                    </div>
                    @if($job->customer_confirmed_at)
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Confirmed</dt>
                        <dd class="text-gray-900">{{ $job->customer_confirmed_at->format('d M Y') }}</dd>
                    </div>
                    @endif
                    @if($job->collected_at)
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Arrived at Pickup</dt>
                        <dd class="text-gray-900">{{ $job->collected_at->format('d M Y') }}</dd>
                    </div>
                    @endif
                    @if($job->delivered_at)
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Delivered</dt>
                        <dd class="text-gray-900">{{ $job->delivered_at->format('d M Y') }}</dd>
                    </div>
                    @endif
                    @if($job->completed_at)
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Completed</dt>
                        <dd class="text-gray-900">{{ $job->completed_at->format('d M Y') }}</dd>
                    </div>
                    @endif
                </dl>
            </div>
        </div>
    </div>
</div>
