<?php

use App\Models\Job;
use App\Models\Company;
use App\Models\JobDocument;
use App\Models\User;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;

new #[Layout('components.layouts.app')] class extends Component {
    public Job $job;
    public bool $requiresConfirmation = false;

    /**
     * True when the viewing tenant is the vehicle OWNER on this job
     * but NOT the customer who placed (and is paying for) the order.
     * Surfaces in the view so price-bearing panels (PO amounts, sell
     * prices, invoices) are hidden -- the owner gets visibility +
     * approval rights, not commercial detail.
     */
    public bool $isOwnerOnlyView = false;

    // Owner-approval decision form state (only used when the viewing
    // tenant is the vehicle owner AND the job is pending sign-off).
    public bool $showOwnerDecisionPanel = false;
    public string $ownerDecisionNotes = '';

    public bool $showIssuePanel = false;
    public string $issueReason = '';
    public string $issueNote = '';

    // Urgent collection toggle (customer self-service).  Gated by
    // JobPolicy::markUrgent -- only the booking company while the
    // vehicle hasn't moved yet.
    public bool $showUrgentModal = false;
    public string $urgentReason = '';

    // Executor change modal state
    public bool $showExecutorPanel = false;
    public string $newExecutorType = '';
    public ?int $newInternalDriverId = null;
    public string $newThirdPartyCourierName = '';
    public string $newThirdPartyWaybill = '';
    public string $newThirdPartyExpectedDate = '';
    public string $newSelfCollectName = '';
    public string $newSelfCollectPhone = '';
    public string $newSelfCollectIdNumber = '';

    // Inline driver assignment for internal-executor jobs
    public bool $showAssignDriverPanel = false;
    public ?int $assignDriverId = null;

    public function mount(Job $job): void
    {
        $user = auth()->user();
        $company = $user->company();
        // Customer (paid for it) OR vehicle owner (BB placed a direct
        // order against their stock) can both open this page.
        $isCustomer = $company && $job->company_id === $company->id;
        $isOwner    = $company && $job->owner_company_id && $job->owner_company_id === $company->id;
        abort_unless($isCustomer || $isOwner, 403);
        $this->isOwnerOnlyView = $isOwner && !$isCustomer;

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
            'ownerCompany:id,name',
            'ownerApprovedBy:id,name',
            'company:id,name',
        ]);

        $this->requiresConfirmation = $company->requiresExternalConfirmation();
        $this->newExecutorType = $job->executor_type ?: Job::EXECUTOR_PROSELVER;
    }

    /* ----------------------------------------------------------------
     | Owner approval -- the dealer who owns the VIN approves /
     | rejects a movement raised by someone else (typically a BB
     | placing a direct order with Proselver against their stock).
     |-----------------------------------------------------------------*/

    public function openOwnerDecisionPanel(): void
    {
        $this->showOwnerDecisionPanel = true;
        $this->ownerDecisionNotes = '';
    }

    public function closeOwnerDecisionPanel(): void
    {
        $this->showOwnerDecisionPanel = false;
        $this->ownerDecisionNotes = '';
    }

    public function approveAsOwner(): void
    {
        $this->guardOwnerDecision();

        $this->job->update([
            'owner_approval_status'     => Job::OWNER_APPROVAL_APPROVED,
            'owner_approved_at'         => now(),
            'owner_approved_by_user_id' => auth()->id(),
            'owner_decision_notes'      => $this->ownerDecisionNotes ?: null,
        ]);

        \App\Models\JobEvent::create([
            'job_id'     => $this->job->id,
            'event_type' => 'owner_approved_movement',
            'event_at'   => now(),
            'user_id'    => auth()->id(),
            'notes'      => $this->ownerDecisionNotes ?: 'Movement approved by vehicle owner.',
        ]);

        $this->showOwnerDecisionPanel = false;
        $this->ownerDecisionNotes = '';
        session()->flash('success', 'Movement approved.');
        $this->job->refresh()->load(['ownerCompany:id,name', 'ownerApprovedBy:id,name']);
    }

    public function rejectAsOwner(): void
    {
        $this->guardOwnerDecision();

        $this->job->update([
            'owner_approval_status'     => Job::OWNER_APPROVAL_REJECTED,
            'owner_approved_at'         => now(),
            'owner_approved_by_user_id' => auth()->id(),
            'owner_decision_notes'      => $this->ownerDecisionNotes ?: null,
            // Reject = the BB can't move our vehicle.  We cancel the
            // job so Proselver ops sees it gone from the queue.
            'status'                    => Job::STATUS_CANCELLED,
            'cancelled_at'              => now(),
            'cancellation_reason'       => 'Rejected by vehicle owner: ' . ($this->ownerDecisionNotes ?: 'no reason given.'),
        ]);

        \App\Models\JobEvent::create([
            'job_id'     => $this->job->id,
            'event_type' => 'owner_rejected_movement',
            'event_at'   => now(),
            'user_id'    => auth()->id(),
            'notes'      => $this->ownerDecisionNotes ?: 'Movement rejected by vehicle owner.',
        ]);

        $this->showOwnerDecisionPanel = false;
        $this->ownerDecisionNotes = '';
        session()->flash('success', 'Movement rejected -- Proselver has been notified.');
        $this->job->refresh()->load(['ownerCompany:id,name', 'ownerApprovedBy:id,name']);
    }

    /**
     * Centralise the owner-decision gate so approve / reject share
     * the same permission and state checks.  Authorised callers are:
     *   - users whose company is the vehicle owner on this job,
     *   - holding the owner_approve_movement permission,
     *   - on a job that's actually pending an owner decision.
     */
    protected function guardOwnerDecision(): void
    {
        $user = auth()->user();
        abort_unless($user && $user->hasPermission('owner_approve_movement'), 403);
        abort_unless($this->job->owner_company_id === $user->company()?->id, 403);
        abort_unless($this->job->isPendingOwnerApproval(), 422, 'This movement is no longer awaiting your approval.');
    }

    /* ----------------------------------------------------------------
     | Executor change — flip who is moving this vehicle. Resets driver
     | / meta fields per Job::changeExecutor() and audit-logs the flip.
     |-----------------------------------------------------------------*/

    public function openExecutorPanel(): void
    {
        $this->authorize('changeExecutor', $this->job);
        $this->newExecutorType = $this->job->executor_type ?: Job::EXECUTOR_PROSELVER;
        $this->newInternalDriverId = null;
        $this->newThirdPartyCourierName = (string) ($this->job->third_party_courier_name ?? '');
        $this->newThirdPartyWaybill = (string) ($this->job->third_party_waybill ?? '');
        $this->newThirdPartyExpectedDate = $this->job->third_party_expected_date?->toDateString() ?? '';
        $this->newSelfCollectName = (string) ($this->job->self_collect_name ?? '');
        $this->newSelfCollectPhone = (string) ($this->job->self_collect_phone ?? '');
        $this->newSelfCollectIdNumber = (string) ($this->job->self_collect_id_number ?? '');
        $this->showExecutorPanel = true;
    }

    public function cancelExecutorPanel(): void
    {
        $this->showExecutorPanel = false;
    }

    public function saveExecutor(): void
    {
        $this->authorize('changeExecutor', $this->job);

        $rules = ['newExecutorType' => 'required|in:' . implode(',', Job::EXECUTOR_TYPES)];
        if ($this->newExecutorType === Job::EXECUTOR_THIRD_PARTY) {
            $rules['newThirdPartyCourierName'] = 'required|string|max:255';
            $rules['newThirdPartyExpectedDate'] = 'nullable|date';
        }
        if ($this->newExecutorType === Job::EXECUTOR_SELF_COLLECT) {
            $rules['newSelfCollectName'] = 'required|string|max:255';
            $rules['newSelfCollectPhone'] = 'required|string|max:50';
        }
        $this->validate($rules);

        $meta = [];
        if ($this->newExecutorType === Job::EXECUTOR_INTERNAL && $this->newInternalDriverId) {
            $meta['driver_user_id'] = $this->newInternalDriverId;
        }
        if ($this->newExecutorType === Job::EXECUTOR_THIRD_PARTY) {
            $meta['third_party_courier_name'] = $this->newThirdPartyCourierName ?: null;
            $meta['third_party_waybill'] = $this->newThirdPartyWaybill ?: null;
            $meta['third_party_expected_date'] = $this->newThirdPartyExpectedDate ?: null;
        }
        if ($this->newExecutorType === Job::EXECUTOR_SELF_COLLECT) {
            $meta['self_collect_name'] = $this->newSelfCollectName ?: null;
            $meta['self_collect_phone'] = $this->newSelfCollectPhone ?: null;
            $meta['self_collect_id_number'] = $this->newSelfCollectIdNumber ?: null;
        }

        $ok = $this->job->changeExecutor($this->newExecutorType, $meta);
        if (! $ok) {
            session()->flash('error', 'Could not change the executor — the job may already be in transit.');
            return;
        }

        $this->job->refresh()->load('driver:id,name,phone');
        $this->showExecutorPanel = false;
        session()->flash('success', 'Executor updated — ' . $this->job->executorLabel() . '.');
    }

    /* ----------------------------------------------------------------
     | Inline driver assignment for internal-executor jobs. The dealer
     | picks one of their own drivers from the pool; status flips to
     | DRIVER_ASSIGNED so it shows up on the driver's "My Day" view.
     |-----------------------------------------------------------------*/

    public function openAssignDriverPanel(): void
    {
        $this->authorize('assignDriver', $this->job);
        $this->assignDriverId = $this->job->driver_user_id;
        $this->showAssignDriverPanel = true;
    }

    public function cancelAssignDriverPanel(): void
    {
        $this->showAssignDriverPanel = false;
    }

    public function saveDriverAssignment(): void
    {
        $this->authorize('assignDriver', $this->job);

        $this->validate([
            'assignDriverId' => [
                'required', 'integer',
                function (string $attribute, $value, \Closure $fail) {
                    $exists = User::query()
                        ->driversForCompany($this->job->company_id)
                        ->whereKey((int) $value)
                        ->exists();
                    if (! $exists) {
                        $fail('Selected driver is not in your driver pool.');
                    }
                },
            ],
        ]);

        $this->job->driver_user_id = $this->assignDriverId;
        if ($this->job->status === Job::STATUS_PLANNED) {
            $this->job->transitionTo(Job::STATUS_DRIVER_ASSIGNED);
        } else {
            $this->job->save();
        }
        $this->job->refresh()->load('driver:id,name,phone');
        $this->showAssignDriverPanel = false;
        session()->flash('success', 'Driver assigned.');
    }

    /* ----------------------------------------------------------------
     | Archive / unarchive — moves final deliveries out of the active
     | order list (they stay queryable in reports).
     |-----------------------------------------------------------------*/

    public function archiveJob(): void
    {
        $this->authorize('archive', $this->job);
        if ($this->job->archive()) {
            $this->job->refresh();
            session()->flash('success', 'Order archived.');
        } else {
            session()->flash('error', 'This order cannot be archived yet.');
        }
    }

    public function unarchiveJob(): void
    {
        $this->authorize('unarchive', $this->job);
        if ($this->job->unarchive()) {
            $this->job->refresh();
            session()->flash('success', 'Order restored to active list.');
        }
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

    /* ----------------------------------------------------------------
     | Urgent collection toggle (customer self-service)
     |---------------------------------------------------------------*/

    public function openUrgentModal(): void
    {
        $this->authorize('markUrgent', $this->job);
        $this->urgentReason = $this->job->urgent_reason ?? '';
        $this->showUrgentModal = true;
    }

    public function closeUrgentModal(): void
    {
        $this->showUrgentModal = false;
        $this->urgentReason = '';
    }

    public function saveUrgent(): void
    {
        $this->authorize('markUrgent', $this->job);
        $this->validate(['urgentReason' => 'nullable|string|max:500']);

        $this->job->markUrgent(auth()->user(), $this->urgentReason);
        $this->job->load('urgentMarkedBy:id,name');

        $this->showUrgentModal = false;
        $this->urgentReason = '';
        session()->flash('success', 'Flagged URGENT — TCDC operations has been notified.');
    }

    public function clearUrgent(): void
    {
        $this->authorize('markUrgent', $this->job);
        $this->job->clearUrgent(auth()->user());
        $this->job->load('urgentMarkedBy:id,name');
        session()->flash('success', 'Urgent flag cleared.');
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

        $user = auth()->user();
        $canConfirm = $user->can('confirmCustomerOrder', $this->job)
            && $this->requiresConfirmation;

        // Dealer's internal driver pool — used by the inline assign-driver
        // panel and the executor-change modal. Empty when the dealer
        // hasn't onboarded any drivers yet; the UI nudges them to
        // /customer/drivers in that case.
        $internalDrivers = User::query()
            ->driversForCompany($this->job->company_id)
            ->orderBy('name')
            ->get(['id', 'name']);

        $internalDriverOptions = $internalDrivers->map(fn ($d) => [
            'value' => (string) $d->id,
            'label' => $d->name,
        ])->values()->all();

        return [
            'allDocuments' => $allDocuments,
            'phase1Statuses' => $phase1Statuses,
            'currentIndex' => $currentIndex !== false ? $currentIndex : -1,
            'canConfirm' => $canConfirm,
            'issueReasons' => Job::CONFIRMATION_ISSUE_REASONS,
            'canChangeExecutor' => $user->can('changeExecutor', $this->job),
            'canAssignDriver' => $user->can('assignDriver', $this->job)
                && $this->job->executor_type === Job::EXECUTOR_INTERNAL,
            'canMarkUrgent' => $user->can('markUrgent', $this->job),
            'canArchive' => $user->can('archive', $this->job),
            'canUnarchive' => $user->can('unarchive', $this->job),
            // Delivery Note / Collection Note PDF. The same gate
            // (JobPolicy::generateCollectionNote) covers ProSelver
            // ops, dealer planners on their own non-ProSelver jobs,
            // and the assigned driver. The UI label switches based
            // on executor — ProSelver jobs stay "Collection Note",
            // dealer-issued paperwork reads "Delivery Note".
            'canGenerateDeliveryNote' => $user->can('generateCollectionNote', $this->job),
            'internalDrivers' => $internalDrivers,
            'internalDriverOptions' => $internalDriverOptions,
            'executorChoices' => Job::EXECUTOR_LABELS,
        ];
    }
};

?>

<div>
    <x-slot:header>Order {{ $job->job_number ?? $job->uuid }}</x-slot:header>

    @if(session('success'))
        <div class="mb-4 rounded-lg bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-4 rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800">{{ session('error') }}</div>
    @endif
    @if($job->isArchived())
        <div class="mb-4 rounded-lg bg-gray-100 border border-gray-200 px-4 py-3 text-sm text-gray-700 flex items-center gap-2">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="5"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><line x1="10" x2="14" y1="12" y2="12"/></svg>
            Archived on {{ $job->archived_at->format('d M Y') }} — hidden from the active order list.
        </div>
    @endif

    {{-- Owner-approval banner.

         Shown when the viewing user's tenant is the vehicle owner on
         this job but didn't place it (BB placed a direct order against
         their stock).  Three states:

           - pending : approve / reject buttons, plus a notes textarea.
           - approved: green confirmation strip with timestamp.
           - rejected: red strip; the job is already cancelled.

         Commercial values (sell price, PO amounts) are masked everywhere
         else on this page when $isOwnerOnlyView is true. --}}
    @if($job->requires_owner_approval && $job->owner_company_id === auth()->user()?->company()?->id)
        @if($job->isPendingOwnerApproval())
            <div class="mb-4 rounded-xl border-2 border-amber-300 bg-amber-50 p-4">
                <div class="flex items-start gap-3">
                    <svg class="h-6 w-6 mt-0.5 shrink-0 text-amber-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>
                    <div class="flex-1 min-w-0">
                        <h2 class="text-sm font-bold text-amber-900">
                            {{ $job->company?->name }} wants to move your vehicle
                        </h2>
                        <p class="mt-1 text-sm text-amber-800">
                            VIN <span class="font-mono font-semibold">{{ $job->vin }}</span>
                            is on your stock ledger.  {{ $job->company?->name }} has placed a direct order with
                            Proselver to move it from {{ $job->pickupLocation?->company_name }} to
                            {{ $job->deliveryLocation?->company_name }} on {{ $job->scheduled_date?->format('d M Y') }}.
                        </p>
                        <p class="mt-2 text-xs text-amber-700">
                            Proselver will only dispatch once you've approved.  The price is between Proselver and
                            {{ $job->company?->name }} -- you won't see commercial detail on this page.
                        </p>

                        @if($showOwnerDecisionPanel)
                            <div class="mt-3 space-y-2">
                                <label class="block text-xs font-semibold text-amber-900">Notes (optional)</label>
                                <textarea wire:model="ownerDecisionNotes" rows="2"
                                    class="w-full rounded-lg border border-amber-300 bg-white px-3 py-2 text-sm focus:border-amber-500 focus:ring-amber-500"
                                    placeholder="e.g. OK to move on Tuesday; please confirm with our service manager first."></textarea>
                                <div class="flex flex-wrap gap-2">
                                    <button wire:click="approveAsOwner" type="button"
                                        class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-500">
                                        Approve movement
                                    </button>
                                    <button wire:click="rejectAsOwner"
                                        wire:confirm="Reject this movement?  The order will be cancelled."
                                        type="button"
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-rose-300 bg-white px-3 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50">
                                        Reject
                                    </button>
                                    <button wire:click="closeOwnerDecisionPanel" type="button"
                                        class="text-xs font-semibold text-amber-800 hover:text-amber-900 px-2">Cancel</button>
                                </div>
                            </div>
                        @else
                            <button wire:click="openOwnerDecisionPanel" type="button"
                                class="mt-3 inline-flex items-center gap-1.5 rounded-lg bg-amber-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-amber-500">
                                Review &amp; decide
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        @elseif($job->owner_approval_status === \App\Models\Job::OWNER_APPROVAL_APPROVED)
            <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800 flex items-center gap-2">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                You approved this movement on {{ $job->owner_approved_at?->format('d M Y H:i') }}{{ $job->ownerApprovedBy ? ' (' . $job->ownerApprovedBy->name . ')' : '' }}.
            </div>
        @elseif($job->owner_approval_status === \App\Models\Job::OWNER_APPROVAL_REJECTED)
            <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                <div class="font-semibold">You rejected this movement on {{ $job->owner_approved_at?->format('d M Y H:i') }}.</div>
                @if($job->owner_decision_notes)
                    <div class="mt-1 italic">"{{ $job->owner_decision_notes }}"</div>
                @endif
            </div>
        @endif
    @endif

    <div class="mb-4 flex items-center justify-between gap-2">
        <a href="{{ route('customer.orders.index') }}" class="inline-flex items-center gap-1 text-sm text-gray-500 hover:text-gray-700">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
            Back to Orders
        </a>
        @if($canMarkUrgent && !$job->is_urgent)
            <button wire:click="openUrgentModal"
                class="inline-flex items-center gap-1.5 rounded-md border border-fuchsia-200 bg-white px-3 py-1.5 text-xs font-semibold text-fuchsia-700 hover:bg-fuchsia-50 transition-colors">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                Mark URGENT
            </button>
        @endif
    </div>

    {{-- URGENT banner: only when flag is set. Shows the reason so any
         user opening the order sees why ops prioritised it, plus a
         clear button (gated by markUrgent policy) so the customer or
         ops can drop the flag once the urgency has passed. --}}
    @if($job->is_urgent)
        <div class="mb-4 rounded-xl border-2 border-fuchsia-300 bg-fuchsia-50 px-4 py-3 flex items-start justify-between gap-3">
            <div class="flex items-start gap-3 min-w-0">
                <svg class="h-5 w-5 mt-0.5 shrink-0 text-fuchsia-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                <div class="min-w-0">
                    <div class="text-sm font-bold uppercase tracking-wider text-fuchsia-900">URGENT collection</div>
                    @if($job->urgent_reason)
                        <div class="mt-0.5 text-sm text-fuchsia-900/90">{{ $job->urgent_reason }}</div>
                    @endif
                    @if($job->urgentMarkedBy)
                        <div class="mt-0.5 text-[11px] text-fuchsia-700">
                            Flagged by {{ $job->urgentMarkedBy->name }} · {{ $job->urgent_marked_at?->diffForHumans() }}
                        </div>
                    @endif
                </div>
            </div>
            @if($canMarkUrgent)
                <button wire:click="clearUrgent" wire:confirm="Clear the URGENT flag on this order?"
                    class="shrink-0 inline-flex items-center gap-1 rounded-md border border-fuchsia-300 bg-white px-3 py-1.5 text-xs font-semibold text-fuchsia-800 hover:bg-fuchsia-100 transition-colors">
                    Clear
                </button>
            @endif
        </div>
    @endif

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

    {{-- ──────────────────────────────────────────────────────────────
         Advance & toll estimate (read-only for dealers).

         Surfaces the toll estimate and any advance plan attached to
         the trip.  Designed for dealers who run executor=internal
         jobs and need to see what cash their own driver is expected
         to need; the phone pill on the bottom is the driver's
         cellphone for bank-send.

         NEVER shown when ProSelver is the executor -- in that case
         the advance / toll budget is ProSelver's internal operational
         data (driver cellphones, expense categories, route budget).
         The dealer paid a quoted line haul to ProSelver and has no
         business with the driver-cash breakdown.  Same for the
         driver-phone allocate-cash pill -- those are ProSelver staff
         personal numbers and stay internal.

         Hidden when nothing has been calculated yet.
         ────────────────────────────────────────────────────────────── --}}
    @php
        $isProselverExecuted = $job->executor_type === \App\Models\Job::EXECUTOR_PROSELVER;
        $hasAdvance = !$isProselverExecuted && (float) ($job->advance_total ?? 0) > 0;
        $hasTollEstimate = !$isProselverExecuted && (float) ($job->estimated_toll_cost ?? 0) > 0;
        $driverPhone = $isProselverExecuted ? null : $job->driver?->phone;
    @endphp
    @if($hasAdvance || $hasTollEstimate)
        <section class="mb-6 rounded-xl bg-white border border-slate-200 p-5">
            <div class="flex items-center justify-between gap-3 mb-3">
                <h3 class="text-base font-semibold text-slate-900">Driver advance & tolls</h3>
                @if(auth()->user()?->canPlanMovements())
                    <a href="{{ route('customer.petty-cash.index') }}" class="text-xs font-semibold text-blue-700 hover:underline">Petty cash queue →</a>
                @endif
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 text-sm">
                @if($hasTollEstimate)
                    <div class="rounded-lg bg-slate-50 border border-slate-200 p-3">
                        <div class="flex items-center gap-1.5 text-slate-500">
                            {{-- Lucide route --}}
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="19" r="3"/><path d="M9 19h8.5a3.5 3.5 0 0 0 0-7h-11a3.5 3.5 0 0 1 0-7H15"/><circle cx="18" cy="5" r="3"/></svg>
                            <p class="text-[10px] uppercase tracking-wide font-semibold">Toll estimate</p>
                        </div>
                        <p class="mt-1 text-lg font-bold tabular-nums text-slate-900">R {{ number_format((float) $job->estimated_toll_cost, 2) }}</p>
                        @if($job->distance_km)
                            <p class="text-[11px] text-slate-500 mt-0.5">{{ number_format((float) $job->distance_km, 0) }} km · main-highway routing</p>
                        @endif
                    </div>
                @endif
                @if($hasAdvance)
                    <div class="rounded-lg bg-emerald-50 border border-emerald-200 p-3">
                        <div class="flex items-center gap-1.5 text-emerald-800">
                            {{-- Lucide wallet --}}
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 7V5a2 2 0 0 0-2-2H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/></svg>
                            <p class="text-[10px] uppercase tracking-wide font-semibold">Advance total</p>
                        </div>
                        <p class="mt-1 text-lg font-bold tabular-nums text-emerald-900">R {{ number_format((float) $job->advance_total, 2) }}</p>
                        <p class="text-[11px] text-emerald-700/80 mt-0.5">
                            @if($job->advance_issued_at)
                                Issued {{ $job->advance_issued_at->format('d M Y') }}
                            @elseif($job->advance_approved_at)
                                Approved, not yet paid
                            @elseif($job->advance_assigned_at)
                                Proposed, awaiting approval
                            @endif
                        </p>
                    </div>
                    @if((float) ($job->advance_tolls ?? 0) > 0)
                        <div class="rounded-lg bg-slate-50 border border-slate-200 p-3">
                            <div class="flex items-center gap-1.5 text-slate-500">
                                {{-- Lucide landmark --}}
                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" x2="21" y1="22" y2="22"/><line x1="6" x2="6" y1="18" y2="11"/><line x1="10" x2="10" y1="18" y2="11"/><line x1="14" x2="14" y1="18" y2="11"/><line x1="18" x2="18" y1="18" y2="11"/><polygon points="12 2 20 7 4 7"/></svg>
                                <p class="text-[10px] uppercase tracking-wide font-semibold">For tolls</p>
                            </div>
                            <p class="mt-1 text-lg font-bold tabular-nums text-slate-900">R {{ number_format((float) $job->advance_tolls, 2) }}</p>
                        </div>
                    @endif
                    @if((float) ($job->advance_accommodation ?? 0) > 0)
                        <div class="rounded-lg bg-slate-50 border border-slate-200 p-3">
                            <div class="flex items-center gap-1.5 text-slate-500">
                                {{-- Lucide bed --}}
                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 4v16"/><path d="M2 8h18a2 2 0 0 1 2 2v10"/><path d="M2 17h20"/><path d="M6 8v9"/></svg>
                                <p class="text-[10px] uppercase tracking-wide font-semibold">Accommodation</p>
                            </div>
                            <p class="mt-1 text-lg font-bold tabular-nums text-slate-900">R {{ number_format((float) $job->advance_accommodation, 2) }}</p>
                        </div>
                    @endif
                    @if((float) ($job->advance_food ?? 0) > 0)
                        <div class="rounded-lg bg-slate-50 border border-slate-200 p-3">
                            <div class="flex items-center gap-1.5 text-slate-500">
                                {{-- Lucide utensils --}}
                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2"/><path d="M7 2v20"/><path d="M21 15V2a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3Zm0 0v7"/></svg>
                                <p class="text-[10px] uppercase tracking-wide font-semibold">Food</p>
                            </div>
                            <p class="mt-1 text-lg font-bold tabular-nums text-slate-900">R {{ number_format((float) $job->advance_food, 2) }}</p>
                        </div>
                    @endif
                    @if((float) ($job->advance_taxi ?? 0) > 0)
                        <div class="rounded-lg bg-slate-50 border border-slate-200 p-3">
                            <div class="flex items-center gap-1.5 text-slate-500">
                                {{-- Lucide car-front --}}
                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8 19 6c-.3-.4-.7-.6-1.2-.6h-11.6c-.5 0-.9.2-1.2.6L3 8"/><path d="M7 14h.01"/><path d="M17 14h.01"/><rect width="18" height="8" x="3" y="10" rx="2"/><path d="M5 18v2"/><path d="M19 18v2"/></svg>
                                <p class="text-[10px] uppercase tracking-wide font-semibold">Taxi / parking</p>
                            </div>
                            <p class="mt-1 text-lg font-bold tabular-nums text-slate-900">R {{ number_format((float) $job->advance_taxi, 2) }}</p>
                        </div>
                    @endif
                @endif
            </div>

            @if($job->driver && $driverPhone)
                <div class="mt-3 flex flex-wrap items-center gap-2 text-xs text-slate-600">
                    <span>Allocate cash to</span>
                    <strong class="text-slate-900">{{ $job->driver->name }}</strong>
                    <span class="inline-flex items-center gap-1 rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[12px] font-semibold text-slate-900 select-all" title="Cellphone for bank-send">
                        <svg class="h-3 w-3 text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.37 1.9.72 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.35 1.85.59 2.81.72A2 2 0 0 1 22 16.92z"/></svg>
                        {{ $driverPhone }}
                    </span>
                </div>
            @endif
        </section>
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
                        <dd class="font-medium text-gray-900 font-mono">
                            {{ $job->vin ?: '—' }}
                            @if(!$job->vin && $job->registration)
                                <span class="ml-1 text-xs font-sans font-normal text-amber-700">VIN not captured</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Registration</dt>
                        <dd class="font-medium text-gray-900 font-mono">{{ $job->registration ?: '—' }}</dd>
                    </div>
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

            {{-- Executor (who is moving this vehicle) --}}
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <div class="flex items-start justify-between mb-3">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-700">Executor</h3>
                        <p class="text-xs text-gray-500">Who is moving this vehicle</p>
                    </div>
                    @if($canChangeExecutor)
                        <button wire:click="openExecutorPanel"
                            class="inline-flex items-center gap-1 rounded-md bg-white border border-gray-200 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50">
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                            Change Executor
                        </button>
                    @endif
                </div>

                @php
                    $executorBadgeClass = match ($job->executor_type) {
                        \App\Models\Job::EXECUTOR_INTERNAL => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                        \App\Models\Job::EXECUTOR_THIRD_PARTY => 'bg-purple-50 text-purple-700 border-purple-200',
                        \App\Models\Job::EXECUTOR_SELF_COLLECT => 'bg-amber-50 text-amber-700 border-amber-200',
                        default => 'bg-blue-50 text-blue-700 border-blue-200',
                    };
                @endphp

                <span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-semibold {{ $executorBadgeClass }}">
                    {{ $job->executorLabel() }}
                </span>

                @if($canGenerateDeliveryNote)
                    @php
                        $isProselver = $job->executor_type === \App\Models\Job::EXECUTOR_PROSELVER;
                        $noteLabel = $isProselver ? 'Collection Note' : 'Delivery Note';
                        $noteHint  = $isProselver
                            ? 'Standard ProSelver paperwork (5 pages: collection note, manual inspection, two PODs).'
                            : 'Delivery paperwork for your driver to take with them — 5 pages: delivery note, manual inspection, two PODs. Branded with your company name, not ProSelver.';
                    @endphp
                    <a href="{{ route('collection-note.download', $job) }}" target="_blank"
                        class="ml-1 inline-flex items-center gap-1.5 rounded-full border border-blue-300 bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-800 hover:bg-blue-100"
                        title="{{ $noteHint }}">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        {{ $noteLabel }} PDF
                    </a>
                @endif

                @if($job->is_round_trip)
                    <span class="ml-1 inline-flex items-center gap-1 rounded-full border border-indigo-200 bg-indigo-50 px-2 py-0.5 text-[11px] font-semibold text-indigo-700" title="Driver waits at the destination and returns on the same trip (e.g. COF, weighbridge)">
                        <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
                        Round trip
                    </span>
                @endif

                {{-- Per-executor meta --}}
                @if($job->executor_type === \App\Models\Job::EXECUTOR_THIRD_PARTY)
                    <dl class="mt-3 grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm">
                        <div>
                            <dt class="text-xs text-gray-500">Transporter / Carrier</dt>
                            <dd class="font-medium text-gray-900">{{ $job->third_party_courier_name ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-500">Waybill</dt>
                            <dd class="font-medium text-gray-900">{{ $job->third_party_waybill ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-500">Expected Delivery</dt>
                            <dd class="font-medium text-gray-900">{{ $job->third_party_expected_date?->format('d M Y') ?: '—' }}</dd>
                        </div>
                    </dl>
                @elseif($job->executor_type === \App\Models\Job::EXECUTOR_SELF_COLLECT)
                    <dl class="mt-3 grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm">
                        <div>
                            <dt class="text-xs text-gray-500">Collector</dt>
                            <dd class="font-medium text-gray-900">{{ $job->self_collect_name ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-500">Phone</dt>
                            <dd class="font-medium text-gray-900">{{ $job->self_collect_phone ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-500">ID / Licence</dt>
                            <dd class="font-medium text-gray-900">{{ $job->self_collect_id_number ?: '—' }}</dd>
                        </div>
                    </dl>
                @elseif($job->requiresDriverUser())
                    {{-- ProSelver or Internal: show the driver if one is set, otherwise (for internal only) offer to assign. --}}
                    @if($job->driver)
                        <div class="mt-3 flex items-center gap-3">
                            <div class="flex items-center justify-center h-10 w-10 rounded-full bg-blue-100 text-blue-700 font-semibold text-sm">
                                {{ strtoupper(substr($job->driver->name, 0, 2)) }}
                            </div>
                            <div class="flex-1">
                                <p class="text-sm font-medium text-gray-900">{{ $job->driver->name }}</p>
                                @if($job->driver->phone)
                                    <p class="text-sm text-gray-500">{{ $job->driver->phone }}</p>
                                @endif
                            </div>
                            @if($canAssignDriver)
                                <button wire:click="openAssignDriverPanel"
                                    class="text-xs font-medium text-blue-600 hover:text-blue-700">Change</button>
                            @endif
                        </div>
                    @else
                        @if($canAssignDriver)
                            <div class="mt-3">
                                <p class="text-sm text-gray-500 mb-2">No driver assigned yet.</p>
                                <button wire:click="openAssignDriverPanel"
                                    class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-500">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" x2="19" y1="8" y2="14"/><line x1="22" x2="16" y1="11" y2="11"/></svg>
                                    Assign Driver
                                </button>
                            </div>
                        @else
                            <p class="mt-3 text-sm text-gray-500">No driver assigned yet — waiting on dispatch.</p>
                        @endif
                    @endif
                @endif

                {{-- Inline assign-driver panel --}}
                @if($showAssignDriverPanel)
                    <div class="mt-4 rounded-lg border border-blue-200 bg-blue-50 p-4">
                        <h4 class="text-sm font-semibold text-gray-800 mb-2">Assign internal driver</h4>
                        @if(empty($internalDriverOptions))
                            <p class="text-sm text-gray-600">No internal drivers yet. Add one in <a class="font-medium text-blue-600 hover:underline" href="{{ route('customer.drivers.index') }}">Drivers</a>.</p>
                        @else
                            <x-searchable-select
                                wire:model="assignDriverId"
                                :options="$internalDriverOptions"
                                placeholder="Pick a driver"
                                search-placeholder="Search drivers…"
                            />
                            @error('assignDriverId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            <div class="mt-3 flex items-center gap-2">
                                <button wire:click="saveDriverAssignment" class="rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-500">Save</button>
                                <button wire:click="cancelAssignDriverPanel" type="button" class="text-sm text-gray-500 hover:text-gray-700">Cancel</button>
                            </div>
                        @endif
                    </div>
                @endif

                {{-- Inline executor-change panel --}}
                @if($showExecutorPanel)
                    <div class="mt-4 rounded-lg border border-gray-300 bg-gray-50 p-4">
                        <h4 class="text-sm font-semibold text-gray-800 mb-3">Switch executor</h4>
                        <p class="text-xs text-gray-500 mb-3">Changing the executor resets the driver and any executor-specific info, and moves the order back to <strong>Planned</strong>.</p>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 mb-3">
                            @foreach($executorChoices as $value => $label)
                                <label class="cursor-pointer rounded-lg border-2 px-3 py-2 transition-colors hover:border-blue-300
                                    {{ $newExecutorType === $value ? 'border-blue-500 bg-blue-50' : 'border-gray-200 bg-white' }}">
                                    <input type="radio" wire:model.live="newExecutorType" value="{{ $value }}" class="sr-only">
                                    <span class="text-sm font-semibold text-gray-900">{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error('newExecutorType') <p class="text-xs text-red-600">{{ $message }}</p> @enderror

                        @if($newExecutorType === \App\Models\Job::EXECUTOR_INTERNAL)
                            <div class="mt-2">
                                <label class="block text-xs font-medium text-gray-700 mb-1">Driver (optional)</label>
                                @if(empty($internalDriverOptions))
                                    <p class="text-xs text-gray-500">No internal drivers — assign later from <a class="text-blue-600 hover:underline" href="{{ route('customer.drivers.index') }}">Drivers</a>.</p>
                                @else
                                    <x-searchable-select
                                        wire:model="newInternalDriverId"
                                        :options="$internalDriverOptions"
                                        placeholder="Leave blank to assign later"
                                        search-placeholder="Search drivers…"
                                    />
                                @endif
                            </div>
                        @elseif($newExecutorType === \App\Models\Job::EXECUTOR_THIRD_PARTY)
                            <div class="mt-2 grid grid-cols-1 sm:grid-cols-3 gap-2">
                                <div class="sm:col-span-2">
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Transporter / Carrier</label>
                                    <input wire:model="newThirdPartyCourierName" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                                    @error('newThirdPartyCourierName') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Expected</label>
                                    <input wire:model="newThirdPartyExpectedDate" type="date" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                                </div>
                                <div class="sm:col-span-3">
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Waybill</label>
                                    <input wire:model="newThirdPartyWaybill" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                                </div>
                            </div>
                        @elseif($newExecutorType === \App\Models\Job::EXECUTOR_SELF_COLLECT)
                            <div class="mt-2 grid grid-cols-1 sm:grid-cols-3 gap-2">
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Collector Name</label>
                                    <input wire:model="newSelfCollectName" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                                    @error('newSelfCollectName') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Phone</label>
                                    <input wire:model="newSelfCollectPhone" type="tel" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                                    @error('newSelfCollectPhone') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">ID / Licence</label>
                                    <input wire:model="newSelfCollectIdNumber" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                                </div>
                            </div>
                        @endif

                        <div class="mt-3 flex items-center gap-2">
                            <button wire:click="saveExecutor" class="rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-500">Save</button>
                            <button wire:click="cancelExecutorPanel" type="button" class="text-sm text-gray-500 hover:text-gray-700">Cancel</button>
                        </div>
                    </div>
                @endif
            </div>

            {{-- Archive / Unarchive — only surfaces once the job has reached delivered/completed. --}}
            @if($canArchive || $canUnarchive)
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <h3 class="text-sm font-semibold text-gray-700 mb-2">Archive</h3>
                    @if($job->isArchived())
                        <p class="text-sm text-gray-500 mb-3">This order is archived and hidden from your active orders list. It still appears in reports.</p>
                        @if($canUnarchive)
                            <button wire:click="unarchiveJob"
                                class="inline-flex items-center gap-2 rounded-lg bg-white border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>
                                Restore to Active List
                            </button>
                        @endif
                    @else
                        <p class="text-sm text-gray-500 mb-3">This delivery is complete. Archive it to remove it from your active orders list — it stays available in your reports.</p>
                        <button wire:click="archiveJob" wire:confirm="Archive this order? It will be hidden from the active list."
                            class="inline-flex items-center gap-2 rounded-lg bg-gray-700 px-3 py-2 text-sm font-semibold text-white hover:bg-gray-600">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="5"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><line x1="10" x2="14" y1="12" y2="12"/></svg>
                            Archive Order
                        </button>
                    @endif
                </div>
            @endif
        </div>

        {{-- Sidebar: Documents --}}
        <div class="space-y-6">
            {{-- Damage report CTA — only surfaces when there is damage to
                 report. We keep this above the generic Documents list so
                 customers running an insurance claim can grab the PDF
                 without scrolling through every collection photo. --}}
            @php
                $damageDocCount = $job->documents->where('category', \App\Models\JobDocument::CATEGORY_DAMAGE_PHOTO)->count();
            @endphp
            @if($damageDocCount > 0)
                @php $damageReleased = $job->damage_report_released_at !== null; @endphp
                @if($damageReleased)
                    {{-- Released: customer sees the CTA. --}}
                    <div class="bg-rose-50 rounded-xl shadow-sm border border-rose-200 p-6">
                        <div class="flex items-start gap-3">
                            <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-rose-100 text-rose-700 shrink-0">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                            </div>
                            <div class="min-w-0 flex-1">
                                <h3 class="text-base font-semibold text-rose-900">Damage report available</h3>
                                <p class="mt-1 text-sm text-rose-900/80">{{ $damageDocCount }} {{ $damageDocCount === 1 ? 'photograph was' : 'photographs were' }} captured against this movement. The report has been reviewed and released by our operations team.</p>
                                @can('generateDamageReport', $job)
                                <a href="{{ route('damage-report.download', $job) }}" target="_blank" rel="noopener"
                                   class="mt-3 inline-flex items-center gap-2 rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-500">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                                    Download Damage Report (PDF)
                                </a>
                                @endcan
                            </div>
                        </div>
                    </div>
                @else
                    {{-- Pending review: no download link, soft "under review" notice.
                         We deliberately do not show photo thumbnails or counts here
                         either — the customer gets the full picture once ops has
                         reviewed and released the report. --}}
                    <div class="bg-amber-50 rounded-xl shadow-sm border border-amber-200 p-6">
                        <div class="flex items-start gap-3">
                            <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-amber-100 text-amber-700 shrink-0">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            </div>
                            <div class="min-w-0 flex-1">
                                <h3 class="text-base font-semibold text-amber-900">Damage report under review</h3>
                                <p class="mt-1 text-sm text-amber-900/80 leading-relaxed">
                                    The driver has flagged possible damage against this movement. Our operations team is reviewing the evidence and will release the full report to you once it has been verified. We'll notify you the moment it's available.
                                </p>
                            </div>
                        </div>
                    </div>
                @endif
            @endif

            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                {{-- Purchase orders live on their own route (signed uploads
                     have a different preview endpoint), so they're rendered
                     above the driver-captured paperwork.

                     Owner-only viewers (a dealer looking at a job placed
                     by a BB against their stock) DON'T see PO amounts --
                     the BB is the paying customer here, not the dealer,
                     so commercial detail is masked. --}}
                @if($job->purchaseOrders->isNotEmpty() && !$isOwnerOnlyView)
                    <h3 class="text-lg font-semibold text-gray-900 mb-3">Purchase orders</h3>
                    <ul class="space-y-2 mb-5">
                        @foreach($job->purchaseOrders as $po)
                            <li class="flex items-center gap-3 rounded-lg border border-gray-100 bg-gray-50 hover:bg-white hover:border-gray-300 px-3 py-2.5 transition-colors">
                                <div class="flex h-11 w-11 items-center justify-center rounded-md bg-white border border-gray-200 text-gray-500 shrink-0">
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium text-gray-900 truncate">{{ $po->po_number }}</p>
                                    <p class="text-xs text-gray-500 truncate">
                                        R{{ number_format((float) $po->po_amount, 2) }}
                                        @if($po->created_at)
                                            &middot; {{ $po->created_at->format('d M Y') }}
                                        @endif
                                    </p>
                                </div>
                                @if($po->document_path)
                                    <a href="{{ route('po.preview', $po->id) }}"
                                       target="_blank" rel="noopener"
                                       class="inline-flex items-center gap-1 rounded-md bg-white border border-gray-200 px-2 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50 shrink-0">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>
                                        View
                                    </a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif

                <x-documents-list :documents="$job->documents" title="Documents" />
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
                        <dt class="text-gray-500">Driver Arrived at Pickup</dt>
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

    {{-- Mark Urgent Modal --}}
    @if($showUrgentModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60" wire:click.self="closeUrgentModal">
        <div class="relative w-full max-w-md mx-4 bg-white rounded-2xl shadow-2xl overflow-hidden">
            <div class="border-b border-gray-200 px-6 py-4 bg-fuchsia-50">
                <div class="flex items-center gap-2">
                    <svg class="h-5 w-5 text-fuchsia-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                    <h3 class="text-lg font-semibold text-fuchsia-900">Flag as Urgent</h3>
                </div>
                <p class="text-sm text-fuchsia-700 mt-0.5">{{ $job->job_number }}</p>
            </div>
            <div class="px-6 py-5 space-y-4">
                <div class="rounded-lg bg-fuchsia-50 border border-fuchsia-200 p-4 text-sm text-fuchsia-900">
                    TCDC operations will see this order flagged URGENT on their live wallboard and sort it to the top of their planning queue.
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Reason <span class="text-gray-400 font-normal">(optional)</span></label>
                    <textarea wire:model="urgentReason" rows="3" maxlength="500" placeholder="e.g. Customer collecting tomorrow morning…"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fuchsia-500 focus:ring-fuchsia-500"></textarea>
                    @error('urgentReason') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="border-t border-gray-200 px-6 py-4 flex items-center justify-end gap-3 bg-gray-50">
                <button wire:click="closeUrgentModal" type="button" class="rounded-lg px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 transition-colors">
                    Cancel
                </button>
                <button wire:click="saveUrgent" class="rounded-lg bg-fuchsia-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-fuchsia-500 transition-colors">
                    Flag URGENT
                </button>
            </div>
        </div>
    </div>
    @endif
</div>
