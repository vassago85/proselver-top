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

    /*
     * Inline editing for the scheduled (collection) date. Ops needs this
     * because the bulk upload may set the wrong date, or operational
     * reasons (truck unavailable, customer reschedule, etc.) force a
     * change. We keep the edit modal-free for speed.
     */
    public bool $editingScheduledDate = false;
    public ?string $scheduledDateInput = null;

    // Executor change modal — ops can flip the executor on any job they
    // can see (subject to JobPolicy::changeExecutor). Resets driver and
    // executor-specific fields per Job::changeExecutor().
    public bool $showExecutorPanel = false;
    public string $newExecutorType = '';
    public ?int $newInternalDriverId = null;
    public string $newThirdPartyCourierName = '';
    public string $newThirdPartyWaybill = '';
    public string $newThirdPartyExpectedDate = '';
    public string $newSelfCollectName = '';
    public string $newSelfCollectPhone = '';
    public string $newSelfCollectIdNumber = '';

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
            'damageReportReleasedBy:id,name',
        ]);

        // Auto-acknowledge damage for the dashboard strip the first time an
        // internal operator opens this order while there's damage recorded.
        // They've seen it with their own eyes now; the dashboard nag can
        // stop. Release is still a separate, explicit action. External
        // users (dealer/customer on a shared link) never ack.
        $user = auth()->user();
        if ($user
            && $user->isInternal()
            && $this->job->damage_acknowledged_at === null
            && $this->job->documents
                ->where('category', \App\Models\JobDocument::CATEGORY_DAMAGE_PHOTO)
                ->isNotEmpty()
        ) {
            $this->job->forceFill([
                'damage_acknowledged_at' => now(),
                'damage_acknowledged_by' => $user->id,
            ])->saveQuietly();
        }
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
     * Operations override of the customer-confirmation gate.
     *
     * For OEM customers (e.g. FAW) we normally require a confirmation
     * tap from the customer side before ops can plan the job — that's
     * how we capture buy-in that the truck really is at the yard ready
     * to collect. While that buy-in flow is still being rolled out, ops
     * needs an escape hatch: pick up the phone, confirm verbally, and
     * push the job into "Collection Confirmed" without waiting for the
     * portal click. Every override is audit-logged with the prior
     * status and the operator's identity so the trail is unambiguous.
     */
    public function confirmOrderOverride(): void
    {
        $user = auth()->user();
        abort_unless($user && $user->isInternal(), 403, 'Only ops staff can override customer confirmation.');

        // Allowed entry points: a brand-new order in RECEIVED, or one
        // already sat AWAITING_CUSTOMER_CONFIRMATION because FAW hasn't
        // tapped through. Anything else is past this gate already.
        if (!in_array($this->job->status, [
            Job::STATUS_RECEIVED,
            Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION,
            Job::STATUS_CONFIRMATION_ISSUE,
        ], true)) {
            session()->flash('error', 'This order is past the customer-confirmation step already.');
            return;
        }

        $before = ['status' => $this->job->status];

        if (!$this->job->transitionTo(Job::STATUS_CONFIRMED)) {
            session()->flash('error', 'Cannot confirm this order in its current state.');
            return;
        }

        // Stamp the confirmation note so every downstream view (admin
        // detail, customer portal, audit dump) makes it obvious this was
        // an ops override rather than a customer tap.
        $this->job->confirmation_reason = null;
        $this->job->confirmation_note = 'Confirmed by ops on behalf of customer ('
            . $user->name . ' at ' . now()->toIso8601String() . ').';
        $this->job->save();

        AuditService::log('order_confirmed_override', 'job', $this->job->id, $before, [
            'status' => $this->job->status,
            'overridden_by' => $user->id,
            'note' => 'Ops override of customer confirmation gate',
        ]);

        session()->flash('warning', "Order {$this->job->job_number} confirmed without customer sign-off — make sure you've spoken to the customer.");
    }

    /**
     * One-click verification bridge out of the legacy PENDING_VERIFICATION
     * state into the Phase 1 chain at RECEIVED. Ops would previously have
     * had to walk the job through verified â†’ approved â†’ awaiting confirmation
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

    /*
     * Roll the order back to PLANNED so a different driver can be picked.
     * Allowed while the driver hasn't physically taken possession of the
     * vehicle yet (DRIVER_ASSIGNED / READY_FOR_COLLECTION). The previous
     * driver is recorded in the audit log so we keep a history of who
     * was on the job.
     */
    public function unassignDriver(): void
    {
        $previousDriverId = $this->job->driver_user_id;
        $previousDriverName = $this->job->driver?->name;

        $this->job->driver_user_id = null;
        $this->job->save();

        if (!$this->job->transitionTo(Job::STATUS_PLANNED)) {
            session()->flash('error', 'Cannot unassign the driver at this stage.');
            return;
        }

        AuditService::log('driver_unassigned', 'job', $this->job->id, null, [
            'previous_driver_id' => $previousDriverId,
            'previous_driver_name' => $previousDriverName,
            'reason' => 'Reverted to planning to reassign driver',
        ]);

        $this->driverId = null;
        $this->job->load(['driver', 'driver.driverProfile']);
        session()->flash('success', $previousDriverName
            ? "Driver {$previousDriverName} unassigned. Pick a new driver to continue."
            : 'Driver unassigned. Pick a new driver to continue.');
    }

    /*
     * Inline edit for the scheduled collection date.
     *   startEditScheduledDate()  – swap the read-only label for an input
     *   saveScheduledDate()       – validate, persist, audit log
     *   cancelEditScheduledDate() – discard
     *
     * Blocked once the order is in flight or terminal so we don't
     * accidentally rewrite history on completed jobs.
     */
    public function startEditScheduledDate(): void
    {
        if ($this->isScheduledDateLocked()) {
            session()->flash('error', 'Scheduled date cannot be changed at this stage.');
            return;
        }

        $this->scheduledDateInput = $this->job->scheduled_date?->format('Y-m-d');
        $this->editingScheduledDate = true;
    }

    public function cancelEditScheduledDate(): void
    {
        $this->editingScheduledDate = false;
        $this->scheduledDateInput = null;
    }

    public function saveScheduledDate(): void
    {
        if ($this->isScheduledDateLocked()) {
            session()->flash('error', 'Scheduled date cannot be changed at this stage.');
            $this->cancelEditScheduledDate();
            return;
        }

        $this->validate([
            'scheduledDateInput' => ['required', 'date'],
        ], [
            'scheduledDateInput.required' => 'Pick a date.',
            'scheduledDateInput.date'     => 'Enter a valid date.',
        ]);

        $oldDate = $this->job->scheduled_date?->format('Y-m-d');
        $newDate = $this->scheduledDateInput;

        if ($oldDate === $newDate) {
            $this->cancelEditScheduledDate();
            return;
        }

        $this->job->scheduled_date = $newDate;
        $this->job->save();

        AuditService::log('scheduled_date_changed', 'job', $this->job->id, null, [
            'from' => $oldDate,
            'to'   => $newDate,
        ]);

        $this->job->refresh();
        $this->cancelEditScheduledDate();
        session()->flash('success', "Collection date updated to " . $this->job->scheduled_date->format('d M Y') . ".");
    }

    /*
     * Lock the scheduled date once the vehicle is physically in the
     * pipeline — changing it after the driver has touched the asset
     * would invalidate POD / paperwork and timestamps. Public so the
     * blade template can call it to show/hide the Change button.
     */
    public function isScheduledDateLocked(): bool
    {
        return in_array($this->job->status, [
            Job::STATUS_COLLECTED,
            Job::STATUS_IN_TRANSIT,
            Job::STATUS_DELIVERED,
            Job::STATUS_COMPLETED,
            Job::STATUS_CANCELLED,
        ], true);
    }

    public function markCollected(): void
    {
        if (!$this->job->transitionTo(Job::STATUS_COLLECTED)) {
            session()->flash('error', 'Cannot mark as arrived at pickup.');
            return;
        }
        AuditService::log('collected', 'job', $this->job->id);
        session()->flash('success', "Order {$this->job->job_number} — driver arrived at pickup.");
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
        // Server-side guard. The UI hides the button when the user isn't
        // authorised, but we re-check here so a crafted Livewire request
        // (or stale session) can't bypass the owner's allow-list.
        $this->authorize('cancel', $this->job);

        $this->validate(['cancelReason' => 'required|min:5']);

        $this->job->cancellation_reason = $this->cancelReason;
        $this->job->save();

        if (!$this->job->transitionTo(Job::STATUS_CANCELLED)) {
            session()->flash('error', 'Cannot cancel this order.');
            return;
        }

        AuditService::log('order_cancelled', 'job', $this->job->id, null, [
            'reason' => $this->cancelReason,
            'cancelled_by_roles' => auth()->user()?->roles->pluck('slug')->values()->all() ?? [],
        ]);
        $this->showCancelModal = false;
        session()->flash('success', "Order {$this->job->job_number} cancelled.");
    }

    /**
     * Release the damage report to the customer. Gated by
     * JobPolicy::releaseDamageReport so only ops/owner/super can sign
     * it off — junior users see a disabled button (and the policy
     * check here also blocks a direct request).
     */
    public function releaseDamageReport(): void
    {
        $this->authorize('releaseDamageReport', $this->job);

        $this->job->damage_report_released_at = now();
        $this->job->damage_report_released_by = auth()->id();
        // Release implies review — stamp ack at the same time so the
        // dashboard strip clears even if the operator skipped the
        // view-the-order step and went straight to release.
        if ($this->job->damage_acknowledged_at === null) {
            $this->job->damage_acknowledged_at = now();
            $this->job->damage_acknowledged_by = auth()->id();
        }
        $this->job->save();

        AuditService::log('damage_report_released', 'job', $this->job->id, null, [
            'released_to_company_id' => $this->job->company_id,
            'photo_count' => $this->job->documents->where('category', \App\Models\JobDocument::CATEGORY_DAMAGE_PHOTO)->count(),
        ]);

        $this->job->refresh()->load(['damageReportReleasedBy', 'documents']);
        session()->flash('success', 'Damage report released to customer.');
    }

    /**
     * Revoke the release. Useful when an operator ticks "release" by
     * mistake or a new photo needs to be reviewed before the customer
     * downloads the updated report.
     */
    public function revokeDamageReport(): void
    {
        $this->authorize('releaseDamageReport', $this->job);

        $previousReleaser = $this->job->damage_report_released_by;
        $previousAt       = $this->job->damage_report_released_at?->toIso8601String();

        $this->job->damage_report_released_at = null;
        $this->job->damage_report_released_by = null;
        $this->job->save();

        AuditService::log('damage_report_revoked', 'job', $this->job->id, null, [
            'previous_released_by' => $previousReleaser,
            'previous_released_at' => $previousAt,
        ]);

        $this->job->refresh()->load(['damageReportReleasedBy', 'documents']);
        session()->flash('success', 'Damage report access revoked from customer.');
    }

    /* ----------------------------------------------------------------
     | Executor change & archive — ops-side mirrors of the customer
     | actions, with the looser "ops can override" rules from
     | JobPolicy.
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

        if (! $this->job->changeExecutor($this->newExecutorType, $meta)) {
            session()->flash('error', 'Could not change the executor — the job may already be in transit.');
            return;
        }

        AuditService::log('executor_changed_by_ops', 'job', $this->job->id, null, [
            'executor_type' => $this->job->executor_type,
        ]);

        $this->job->refresh()->load(['driver', 'driver.driverProfile']);
        $this->driverId = null;
        $this->showExecutorPanel = false;
        session()->flash('success', 'Executor updated to ' . $this->job->executorLabel() . '.');
    }

    public function archiveJob(): void
    {
        $this->authorize('archive', $this->job);
        if ($this->job->archive(opsOverride: true)) {
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

    public function with(): array
    {
        // Driver dropdown SOURCE switches with executor_type. For
        // ProSelver-executed jobs we hand ops the platform driver pool;
        // for internal-executed jobs we narrow to the dealer's own
        // drivers so ops doesn't accidentally assign a platform driver
        // to a job the dealer is supposed to be running themselves.
        // For 3rd-party / self-collect the dropdown is irrelevant (no
        // driver), but we still ship an empty options array so the
        // Blade template's wire:model binding stays valid.
        $executorType = $this->job->executor_type ?: Job::EXECUTOR_PROSELVER;

        $drivers = match ($executorType) {
            Job::EXECUTOR_INTERNAL => User::query()
                ->driversForCompany($this->job->company_id)
                ->orderBy('name')
                ->get(['id', 'name']),
            Job::EXECUTOR_PROSELVER => User::query()
                ->platformDrivers()
                ->orderBy('name')
                ->get(['id', 'name']),
            default => collect(),
        };

        $driverOptions = $drivers->map(fn ($d) => [
            'value' => (string) $d->id,
            'label' => $d->name,
        ])->values()->all();

        // Same scoping for the executor-change inline driver picker.
        $internalDrivers = User::query()
            ->driversForCompany($this->job->company_id)
            ->orderBy('name')
            ->get(['id', 'name']);
        $internalDriverOptions = $internalDrivers->map(fn ($d) => [
            'value' => (string) $d->id,
            'label' => $d->name,
        ])->values()->all();

        $user = auth()->user();

        return [
            'drivers' => $drivers,
            'driverOptions' => $driverOptions,
            'driverPoolLabel' => match ($executorType) {
                Job::EXECUTOR_INTERNAL => $this->job->company?->name . ' drivers',
                Job::EXECUTOR_PROSELVER => 'ProSelver drivers',
                default => null,
            },
            'internalDriverOptions' => $internalDriverOptions,
            'canChangeExecutor' => $user->can('changeExecutor', $this->job),
            'canArchive' => $user->can('archive', $this->job),
            'canUnarchive' => $user->can('unarchive', $this->job),
            'executorChoices' => Job::EXECUTOR_LABELS,
        ];
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

    @php
        // -------------------------------------------------------------
        // Next-step resolver
        // -------------------------------------------------------------
        // Single source of truth for the hero. Each entry maps a status
        // to (title, description, variant). The actual buttons are
        // rendered below — this table is purely the wording ops sees so
        // they never have to guess what's next.
        // -------------------------------------------------------------
        $isTerminal    = in_array($job->status, [Job::STATUS_COMPLETED, Job::STATUS_CANCELLED], true);
        $isPreRelease  = in_array($job->status, [Job::STATUS_DRIVER_ASSIGNED, Job::STATUS_READY_FOR_COLLECTION], true);
        $isInFlight    = in_array($job->status, [Job::STATUS_COLLECTED, Job::STATUS_IN_TRANSIT, Job::STATUS_IN_PROGRESS], true);
        $isCancellable = in_array($job->status, [
            Job::STATUS_RECEIVED,
            Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION,
            Job::STATUS_CONFIRMED,
            Job::STATUS_PLANNED,
            Job::STATUS_DRIVER_ASSIGNED,
            Job::STATUS_READY_FOR_COLLECTION,
        ], true);

        $nextStep = match ($job->status) {
            Job::STATUS_PENDING_VERIFICATION => [
                'title'   => 'Verify this booking',
                'body'    => 'Confirm the PO and vehicle details are correct. Verification releases the booking into the active queue.',
                'variant' => 'amber',
            ],
            Job::STATUS_RECEIVED => $job->company?->requiresExternalConfirmation()
                ? ['title' => 'Send to customer for confirmation', 'body' => 'The customer will receive a confirmation link to review the booking.', 'variant' => 'amber']
                : ['title' => 'Confirm the order',                   'body' => 'Lock in the booking so it can be scheduled for dispatch.',               'variant' => 'blue'],
            Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION => [
                'title'   => 'Waiting for the customer',
                'body'    => 'We\'ve emailed the confirmation link. Nothing for you to do until they respond.',
                'variant' => 'slate',
            ],
            Job::STATUS_CONFIRMED => [
                'title'   => 'Plan the order',
                'body'    => 'Mark the order as planned so it appears on the planning queue.',
                'variant' => 'indigo',
            ],
            Job::STATUS_PLANNED => [
                'title'   => 'Assign a driver',
                'body'    => 'Pick the driver who\'ll collect this vehicle and assign the job.',
                'variant' => 'purple',
            ],
            Job::STATUS_DRIVER_ASSIGNED, Job::STATUS_READY_FOR_COLLECTION => [
                'title'   => 'Print the delivery paperwork',
                'body'    => 'Collection Note + Manual Inspection + POD (5 pages). Print double-sided and hand to the driver before the vehicle leaves. Then mark the driver arrived at pickup.',
                'variant' => 'green',
            ],
            Job::STATUS_COLLECTED => [
                'title'   => 'Mark in transit once the driver departs',
                'body'    => 'Driver has taken possession. Flip to In Transit the moment they pull out.',
                'variant' => 'orange',
            ],
            Job::STATUS_IN_TRANSIT => [
                'title'   => 'Mark as delivered when the driver arrives',
                'body'    => 'Update the status the moment the vehicle is handed over at the destination.',
                'variant' => 'emerald',
            ],
            Job::STATUS_DELIVERED => [
                'title'   => 'Complete the order',
                'body'    => 'Final confirmation that the customer has signed for the vehicle.',
                'variant' => 'green',
            ],
            Job::STATUS_COMPLETED => [
                'title'   => 'Order complete',
                'body'    => 'No further action needed. Paperwork and photos are archived below.',
                'variant' => 'done',
            ],
            Job::STATUS_CANCELLED => [
                'title'   => 'Order cancelled',
                'body'    => 'This order was cancelled. No further action needed.',
                'variant' => 'cancelled',
            ],
            default => [
                'title'   => 'Review and progress this order',
                'body'    => 'No automated next step defined for this status.',
                'variant' => 'slate',
            ],
        };

        // Tailwind class bank keyed by variant so the hero stays tidy.
        $variantClasses = [
            'amber'     => ['ring' => 'border-amber-200',   'bg' => 'bg-amber-50/60',     'pill' => 'bg-amber-100 text-amber-800',     'dot' => 'bg-amber-500'],
            'blue'      => ['ring' => 'border-blue-200',    'bg' => 'bg-blue-50/60',      'pill' => 'bg-blue-100 text-blue-800',       'dot' => 'bg-blue-500'],
            'indigo'    => ['ring' => 'border-indigo-200',  'bg' => 'bg-indigo-50/60',    'pill' => 'bg-indigo-100 text-indigo-800',   'dot' => 'bg-indigo-500'],
            'purple'    => ['ring' => 'border-purple-200',  'bg' => 'bg-purple-50/60',    'pill' => 'bg-purple-100 text-purple-800',   'dot' => 'bg-purple-500'],
            'green'     => ['ring' => 'border-green-200',   'bg' => 'bg-green-50/60',     'pill' => 'bg-green-100 text-green-800',     'dot' => 'bg-green-500'],
            'orange'    => ['ring' => 'border-orange-200',  'bg' => 'bg-orange-50/60',    'pill' => 'bg-orange-100 text-orange-800',   'dot' => 'bg-orange-500'],
            'emerald'   => ['ring' => 'border-emerald-200', 'bg' => 'bg-emerald-50/60',   'pill' => 'bg-emerald-100 text-emerald-800', 'dot' => 'bg-emerald-500'],
            'slate'     => ['ring' => 'border-slate-200',   'bg' => 'bg-slate-50/60',     'pill' => 'bg-slate-100 text-slate-700',     'dot' => 'bg-slate-400'],
            'done'      => ['ring' => 'border-emerald-200', 'bg' => 'bg-emerald-50/40',   'pill' => 'bg-emerald-100 text-emerald-800', 'dot' => 'bg-emerald-500'],
            'cancelled' => ['ring' => 'border-slate-200',   'bg' => 'bg-slate-50/60',     'pill' => 'bg-slate-100 text-slate-700',     'dot' => 'bg-slate-400'],
        ];
        $v = $variantClasses[$nextStep['variant']];
    @endphp

    <div class="space-y-6">

        {{-- Status & Next Step — the page's single source of directive.
             Merges the old "header card" and the old right-hand Actions
             panel into one hero so ops never has to choose between two
             primary buttons; the next move is always one click from the
             top of the page.

             NOTE: overflow MUST be visible so the searchable-select
             dropdown used by "Assign driver" isn't clipped by the
             card's rounded box. Inner sections carry their own
             border-radius so visual rounding is preserved. --}}
        <div class="rounded-2xl border {{ $v['ring'] }} bg-white shadow-sm overflow-visible">
            <div class="flex flex-wrap items-start justify-between gap-4 px-6 py-5 border-b border-gray-100 rounded-t-2xl">
                <div class="min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <h2 class="text-xl font-semibold text-gray-900 tracking-tight">{{ $job->job_number ?? $job->uuid }}</h2>
                        <x-status-badge :status="$job->status" />
                        @php
                            $execBadgeClass = match ($job->executor_type) {
                                \App\Models\Job::EXECUTOR_INTERNAL => 'bg-emerald-100 text-emerald-800 border-emerald-200',
                                \App\Models\Job::EXECUTOR_THIRD_PARTY => 'bg-purple-100 text-purple-800 border-purple-200',
                                \App\Models\Job::EXECUTOR_SELF_COLLECT => 'bg-amber-100 text-amber-800 border-amber-200',
                                default => 'bg-blue-100 text-blue-800 border-blue-200',
                            };
                        @endphp
                        <span class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-semibold {{ $execBadgeClass }}">
                            Executor: {{ $job->executorLabel() }}
                        </span>
                        @if($job->isArchived())
                            <span class="inline-flex items-center gap-1 rounded-full border border-gray-300 bg-gray-100 px-2 py-0.5 text-[11px] font-semibold text-gray-700">Archived</span>
                        @endif
                    </div>
                    <p class="mt-1 text-sm text-gray-500 truncate">
                        {{ $job->company?->name ?? '—' }}
                        @if($job->brand || $job->model_name)
                            &middot; {{ trim(($job->brand?->name ?? '') . ' ' . ($job->model_name ?? '')) }}
                        @endif
                        @if($job->scheduled_date)
                            &middot; {{ $job->scheduled_date->format('d M Y') }}
                        @endif
                    </p>
                </div>
                @if($job->driver)
                    <div class="text-right">
                        <p class="text-[10px] font-semibold uppercase tracking-[0.15em] text-gray-400">Driver</p>
                        <p class="text-sm font-medium text-gray-900">{{ $job->driver->name }}</p>
                    </div>
                @endif
            </div>

            <div class="{{ $v['bg'] }} px-6 py-5 rounded-b-2xl">
                {{-- Ops-only badge: if this order was confirmed via the
                     manual override (we stamp confirmation_note when
                     ops bypass the customer portal sign-off) make it
                     unmistakable to the next operator who opens it. --}}
                @if($job->confirmation_note && $job->confirmation_reason === null && str_starts_with($job->confirmation_note, 'Confirmed by ops'))
                    <div class="mb-4 flex items-start gap-3 rounded-lg border-2 border-orange-300 bg-orange-50 px-4 py-3 text-sm text-orange-900">
                        <svg class="h-5 w-5 mt-0.5 shrink-0 text-orange-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                        <div class="flex-1">
                            <p class="font-semibold">Confirmed via ops override</p>
                            <p class="mt-0.5 text-xs text-orange-800">{{ $job->confirmation_note }}</p>
                        </div>
                    </div>
                @endif
                <div class="flex items-start gap-3">
                    <span class="mt-1 inline-flex h-2 w-2 shrink-0 rounded-full {{ $v['dot'] }} {{ !$isTerminal ? 'node-pulse' : '' }}"></span>
                    <div class="min-w-0 flex-1">
                        <p class="text-[10px] font-semibold uppercase tracking-[0.2em] text-gray-500">Next step</p>
                        <h3 class="mt-0.5 text-base font-semibold text-gray-900">{{ $nextStep['title'] }}</h3>
                        <p class="mt-1 text-sm text-gray-600 leading-relaxed">{{ $nextStep['body'] }}</p>
                    </div>
                </div>

                {{-- Primary CTA — resolves per status. Only one primary
                     button is ever shown; secondary actions are rendered
                     in the row below to keep the focal point clean. --}}
                <div class="mt-4 flex flex-wrap items-center gap-2">
                    @if($job->status === Job::STATUS_PENDING_VERIFICATION)
                        @can('verify', $job)
                            <button wire:click="verifyBooking" wire:confirm="Verify this booking?"
                                class="inline-flex items-center gap-2 rounded-lg bg-amber-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-amber-500 shadow-sm transition-colors">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.25" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/></svg>
                                Verify &amp; Release to Queue
                            </button>
                        @else
                            <span class="text-xs text-gray-500">Waiting for ops to verify.</span>
                        @endcan
                    @elseif($job->status === Job::STATUS_RECEIVED)
                        @if($job->company?->requiresExternalConfirmation())
                            <button wire:click="sendToCustomer" wire:confirm="Send to customer for confirmation?"
                                class="inline-flex items-center gap-2 rounded-lg bg-amber-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-amber-500 shadow-sm transition-colors">
                                Send to Customer for Confirmation
                            </button>
                            {{-- Interim escape hatch while OEM customers (e.g. FAW)
                                 are still being onboarded onto the customer-portal
                                 confirmation flow. Ops phones the customer, gets
                                 verbal sign-off, and pushes the order to "Collection
                                 Confirmed" without waiting for the portal tap.
                                 Audit-logged in confirmOrderOverride(). --}}
                            <button wire:click="confirmOrderOverride"
                                wire:confirm="Override the customer confirmation?\n\nThis will mark the order ready for collection WITHOUT the customer's portal sign-off. Only do this if you've confirmed verbally with the customer that the truck is at the yard."
                                class="inline-flex items-center gap-2 rounded-lg border-2 border-orange-400 bg-orange-50 px-4 py-2.5 text-sm font-semibold text-orange-800 hover:bg-orange-100 shadow-sm transition-colors">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                                Override: Mark Ready for Collection
                            </button>
                        @else
                            <button wire:click="confirmOrder" wire:confirm="Confirm this order?"
                                class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-500 shadow-sm transition-colors">
                                Confirm Order
                            </button>
                        @endif
                    @elseif(in_array($job->status, [Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION, Job::STATUS_CONFIRMATION_ISSUE], true))
                        {{-- Order has been sent to the customer but they haven't
                             tapped through yet. Ops can chase them, OR if it's
                             dragging on, override and push it to Collection
                             Confirmed manually. --}}
                        <span class="inline-flex items-center gap-2 rounded-lg bg-amber-50 border border-amber-200 px-3 py-2 text-sm text-amber-800">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            {{ $job->status === Job::STATUS_CONFIRMATION_ISSUE ? 'Customer reported an issue' : 'Waiting for customer confirmation' }}
                        </span>
                        <button wire:click="confirmOrderOverride"
                            wire:confirm="Override the customer confirmation?\n\nThis will mark the order ready for collection WITHOUT waiting for the customer's portal sign-off. Only do this if you've confirmed verbally with the customer that the truck is at the yard."
                            class="inline-flex items-center gap-2 rounded-lg border-2 border-orange-400 bg-orange-50 px-4 py-2.5 text-sm font-semibold text-orange-800 hover:bg-orange-100 shadow-sm transition-colors">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                            Override: Mark Ready for Collection
                        </button>
                    @elseif($job->status === Job::STATUS_CONFIRMED)
                        <button wire:click="planOrder" wire:confirm="Mark as planned?"
                            class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500 shadow-sm transition-colors">
                            Plan Order
                        </button>
                    @elseif($job->status === Job::STATUS_PLANNED)
                        @if($job->requiresDriverUser())
                            <div class="flex flex-wrap items-center gap-2 w-full sm:w-auto">
                                <div class="w-64">
                                    <x-searchable-select
                                        wire:model="driverId"
                                        :options="$driverOptions"
                                        placeholder="{{ $driverPoolLabel ? 'Select from ' . $driverPoolLabel . '…' : 'Select driver…' }}"
                                        search-placeholder="Search drivers…"
                                    />
                                    @if($driverPoolLabel)
                                        <p class="mt-1 text-[11px] text-gray-500">Pool: {{ $driverPoolLabel }}</p>
                                    @endif
                                </div>
                                <button wire:click="assignDriver"
                                    class="inline-flex items-center gap-2 rounded-lg bg-purple-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-purple-500 shadow-sm transition-colors">
                                    Assign Driver
                                </button>
                            </div>
                        @else
                            <span class="inline-flex items-center gap-2 rounded-lg bg-purple-50 border border-purple-200 px-3 py-2 text-sm text-purple-800">
                                No driver to assign — executor is {{ $job->executorLabel() }}.
                            </span>
                        @endif
                    @elseif($isPreRelease)
                        {{-- Paperwork-then-collect is a two-step operation; the
                             hero shows both as peers, Print first (literal next
                             step) then Mark Arrived as a secondary CTA once the
                             paper has been handed over. --}}
                        @can('generateCollectionNote', $job)
                            <a href="{{ route('collection-note.download', $job) }}" target="_blank"
                                class="inline-flex items-center gap-2 rounded-lg bg-green-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-green-500 shadow-sm transition-colors">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                                Print Paperwork
                            </a>
                        @endcan
                        <button wire:click="markCollected" wire:confirm="Has the driver arrived at the pickup location with the paperwork signed?"
                            class="inline-flex items-center gap-2 rounded-lg border border-teal-600 bg-white px-4 py-2.5 text-sm font-semibold text-teal-700 hover:bg-teal-50 transition-colors">
                            Mark Driver Arrived at Pickup
                        </button>
                        {{-- Safety net: ops can roll back to PLANNED to pick a
                             different driver if the wrong one was assigned.
                             Only available before the driver has physically
                             collected the vehicle. --}}
                        <button wire:click="unassignDriver"
                            wire:confirm="Unassign {{ $job->driver?->name ?? 'the current driver' }} and send this order back to the planning queue so a different driver can be picked?"
                            class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50 transition-colors">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg>
                            Change Driver
                        </button>
                    @elseif($job->status === Job::STATUS_COLLECTED)
                        <button wire:click="markInTransit" wire:confirm="Has the driver departed with the vehicle? Mark as in transit?"
                            class="inline-flex items-center gap-2 rounded-lg bg-orange-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-orange-500 shadow-sm transition-colors">
                            Mark In Transit (Departed)
                        </button>
                    @elseif($job->status === Job::STATUS_IN_TRANSIT)
                        <button wire:click="markDelivered" wire:confirm="Mark as delivered?"
                            class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-500 shadow-sm transition-colors">
                            Mark Delivered
                        </button>
                    @elseif($job->status === Job::STATUS_DELIVERED)
                        <button wire:click="completeOrder" wire:confirm="Complete this order?"
                            class="inline-flex items-center gap-2 rounded-lg bg-green-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-green-500 shadow-sm transition-colors">
                            Complete Order
                        </button>
                    @elseif($isTerminal)
                        <span class="inline-flex items-center gap-2 text-sm font-medium text-emerald-700">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                            {{ $job->status === Job::STATUS_CANCELLED ? 'Cancelled' : 'All done' }}
                        </span>
                    @endif

                    {{-- Subtle secondary actions on the same row; kept
                         visually quieter than the primary CTA by using
                         outline + ghost treatments. --}}
                    @can('generateCollectionNote', $job)
                        @if(!$isPreRelease)
                            <a href="{{ route('collection-note.download', $job) }}" target="_blank"
                                class="inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-3.5 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                                Collection Note
                            </a>
                        @endif
                    @endcan

                    @if($isCancellable)
                        @can('cancel', $job)
                            <button wire:click="openCancelModal"
                                class="inline-flex items-center gap-2 rounded-lg border border-red-200 bg-white px-3.5 py-2.5 text-sm font-medium text-red-700 hover:bg-red-50 transition-colors">
                                Cancel Order
                            </button>
                        @endcan
                    @endif

                    @if($canChangeExecutor)
                        <button wire:click="openExecutorPanel"
                            class="inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-3.5 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                            Change Executor
                        </button>
                    @endif

                    @if($canArchive)
                        <button wire:click="archiveJob" wire:confirm="Archive this order? It will be hidden from active lists but stays in reports."
                            class="inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-3.5 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="5"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><line x1="10" x2="14" y1="12" y2="12"/></svg>
                            Archive
                        </button>
                    @endif
                    @if($canUnarchive)
                        <button wire:click="unarchiveJob"
                            class="inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-3.5 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors">
                            Restore from Archive
                        </button>
                    @endif
                </div>

                {{-- Inline executor change panel for ops --}}
                @if($showExecutorPanel)
                    <div class="mt-4 rounded-lg border border-gray-300 bg-white p-4">
                        <h4 class="text-sm font-semibold text-gray-800 mb-3">Switch executor</h4>
                        <p class="text-xs text-gray-500 mb-3">This resets the driver and any executor-specific info, and moves the order back to Planned.</p>

                        <div class="grid grid-cols-1 sm:grid-cols-4 gap-2 mb-3">
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
                                <label class="block text-xs font-medium text-gray-700 mb-1">Dealer driver (optional)</label>
                                @if(empty($internalDriverOptions))
                                    <p class="text-xs text-gray-500">No internal drivers for this dealer yet.</p>
                                @else
                                    <x-searchable-select
                                        wire:model="newInternalDriverId"
                                        :options="$internalDriverOptions"
                                        placeholder="Leave blank for the dealer to assign"
                                        search-placeholder="Search dealer drivers…"
                                    />
                                @endif
                            </div>
                        @elseif($newExecutorType === \App\Models\Job::EXECUTOR_THIRD_PARTY)
                            <div class="mt-2 grid grid-cols-1 sm:grid-cols-3 gap-2">
                                <div class="sm:col-span-2">
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Courier</label>
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
        </div>

            {{-- Damage banner — compact single-card layout.
                 Header row carries title, photo count and release
                 status all inline. A horizontal thumbnail strip
                 replaces the old grid so the card never dominates
                 the page, and notes + action buttons sit directly
                 below without their own sub-panels. --}}
            @php
                $damageDocs = $job->documents->where('category', \App\Models\JobDocument::CATEGORY_DAMAGE_PHOTO)->values();
            @endphp
            @if($damageDocs->isNotEmpty())
                @php
                    $isReleased = $job->damage_report_released_at !== null;
                    $damageNotes = $damageDocs
                        ->map(fn($d) => [
                            'text' => (is_string($d->notes) && !str_starts_with($d->notes, 'slot:')) ? trim($d->notes) : null,
                            'at'   => $d->captured_at ?? $d->created_at,
                        ])
                        ->filter(fn($row) => !empty($row['text']))
                        ->values();
                @endphp
                <details class="group rounded-xl border border-rose-200 bg-rose-50 shadow-sm overflow-hidden" id="damage-section" open>
                    <style>.damage-summary::-webkit-details-marker { display: none; } .damage-summary { list-style: none; }</style>
                    <summary class="damage-summary flex items-center gap-3 px-4 py-3 cursor-pointer select-none">
                        <svg class="h-5 w-5 shrink-0 text-rose-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                            <path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/>
                            <path d="M12 9v4"/><path d="M12 17h.01"/>
                        </svg>
                        <div class="min-w-0 flex-1 flex flex-wrap items-center gap-x-2 gap-y-1">
                            <span class="font-semibold text-rose-900">Damage reported</span>
                            <span class="inline-flex items-center rounded-full bg-rose-100 border border-rose-200 px-1.5 py-0.5 text-[10px] font-semibold text-rose-800">
                                {{ $damageDocs->count() }} {{ $damageDocs->count() === 1 ? 'photo' : 'photos' }}
                            </span>
                            @if($isReleased)
                                <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 border border-emerald-200 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-800">
                                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                                    Released {{ $job->damage_report_released_at->format('d M') }}
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 border border-amber-200 px-1.5 py-0.5 text-[10px] font-semibold text-amber-800">
                                    Pending review
                                </span>
                            @endif
                        </div>
                        <svg class="h-4 w-4 shrink-0 text-rose-400 transition-transform group-open:rotate-180"
                             fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                            <path d="m6 9 6 6 6-6"/>
                        </svg>
                    </summary>

                    <div class="px-4 pb-4 pt-1 space-y-3">
                        {{-- Horizontal thumbnail strip — fixed height so a
                             single photo doesn't balloon to a half screen. --}}
                        <div class="flex gap-2 overflow-x-auto pb-1 -mx-0.5 px-0.5">
                            @foreach($damageDocs as $dmg)
                                @can('view', $dmg)
                                <a href="{{ route('documents.view', $dmg) }}" target="_blank" rel="noopener"
                                   class="group/thumb relative block h-20 w-20 shrink-0 rounded-md overflow-hidden border border-rose-200 bg-white hover:border-rose-400 hover:shadow transition">
                                    @if(str_starts_with((string) $dmg->mime_type, 'image/'))
                                        <img src="{{ route('documents.view', $dmg) }}"
                                             alt="Damage photo"
                                             class="h-full w-full object-cover group-hover/thumb:scale-105 transition-transform"
                                             loading="lazy">
                                    @else
                                        <div class="h-full w-full flex items-center justify-center bg-rose-50 text-rose-400 text-[9px] text-center p-1">file</div>
                                    @endif
                                    @if($dmg->captured_at)
                                        <span class="absolute bottom-0 inset-x-0 bg-gradient-to-t from-black/70 to-transparent px-1 py-0.5 text-[9px] font-medium text-white">
                                            {{ $dmg->captured_at->format('d M H:i') }}
                                        </span>
                                    @endif
                                </a>
                                @endcan
                            @endforeach
                        </div>

                        @if($damageNotes->isNotEmpty())
                            <div class="text-sm text-rose-900 space-y-1">
                                @foreach($damageNotes as $note)
                                    <p class="flex gap-2">
                                        <span class="text-rose-400 shrink-0">&ldquo;</span>
                                        <span class="min-w-0 flex-1">
                                            <span class="italic">{{ $note['text'] }}</span>
                                            @if($note['at'])
                                                <span class="text-[11px] text-rose-700/70 not-italic ml-1">— {{ $note['at']->format('d M H:i') }}</span>
                                            @endif
                                        </span>
                                    </p>
                                @endforeach
                            </div>
                        @endif

                        @if($isReleased && $job->damageReportReleasedBy)
                            <p class="text-[11px] text-emerald-800/80">
                                Released to customer by {{ $job->damageReportReleasedBy->name }} on {{ $job->damage_report_released_at->format('d M Y · H:i') }}.
                            </p>
                        @elseif(!$isReleased)
                            <p class="text-[11px] text-amber-800/90">
                                The customer won't see this report until you release it.
                            </p>
                        @endif

                        <div class="flex flex-wrap items-center gap-2 pt-1">
                            <a href="{{ route('damage-report.download', $job) }}" target="_blank" rel="noopener"
                               class="inline-flex items-center gap-1.5 rounded-lg bg-rose-600 px-3 py-2 text-xs font-semibold text-white hover:bg-rose-500 transition-colors">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                                {{ $isReleased ? 'Download report' : 'Preview (ops)' }}
                            </a>

                            @can('releaseDamageReport', $job)
                                @if(!$isReleased)
                                    <button wire:click="releaseDamageReport"
                                            wire:confirm="Release this damage report to the customer? They will be able to download the PDF immediately."
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-emerald-600 bg-white px-3 py-2 text-xs font-semibold text-emerald-700 hover:bg-emerald-50 transition-colors">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                                        <span wire:loading.remove wire:target="releaseDamageReport">Release to customer</span>
                                        <span wire:loading wire:target="releaseDamageReport">Releasing…</span>
                                    </button>
                                @else
                                    <button wire:click="revokeDamageReport"
                                            wire:confirm="Revoke customer access to this damage report? They will no longer be able to download it until you release it again."
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-50 transition-colors">
                                        Revoke access
                                    </button>
                                @endif
                            @else
                                @if(!$isReleased)
                                    <span class="text-[11px] text-slate-500">
                                        Release requires ops manager or owner.
                                    </span>
                                @endif
                            @endcan
                        </div>
                    </div>
                </details>
            @endif

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
                    <div>
                        <dt class="text-gray-500">Scheduled Date</dt>
                        <dd class="font-medium">
                            @if($editingScheduledDate)
                                <form wire:submit.prevent="saveScheduledDate" class="flex items-center gap-2">
                                    <input
                                        type="date"
                                        wire:model="scheduledDateInput"
                                        class="rounded-md border-gray-300 px-2 py-1 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                        autofocus
                                    />
                                    <button type="submit" class="rounded-md bg-blue-600 px-2.5 py-1 text-xs font-semibold text-white hover:bg-blue-500">Save</button>
                                    <button type="button" wire:click="cancelEditScheduledDate" class="text-xs font-medium text-gray-500 hover:text-gray-800">Cancel</button>
                                </form>
                                @error('scheduledDateInput')
                                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                @enderror
                            @else
                                <span>{{ $job->scheduled_date?->format('d M Y') ?? '—' }}</span>
                                @if(! $this->isScheduledDateLocked())
                                    <button type="button"
                                        wire:click="startEditScheduledDate"
                                        class="ml-2 inline-flex items-center gap-1 text-xs font-medium text-blue-600 hover:text-blue-800"
                                        title="Change collection date">
                                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 1 1 3 3L7 19l-4 1 1-4 12.5-12.5Z"/></svg>
                                        Change
                                    </button>
                                @endif
                            @endif
                        </dd>
                    </div>
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

            {{-- Documents & Purchase Orders
                 The documents list itself is collapsed by default (via the
                 <x-documents-list> component) so a busy job doesn't bury
                 the rest of the page under a wall of thumbnails. Purchase
                 orders get their own small panel above the list — they're
                 the one piece of paperwork ops reaches for first.
                 Internal-only categories (fuel/food/toll/parking slips)
                 stay visible to admins via hideInternalOnly=false.
            --}}
            @if($job->documents->isNotEmpty() || $job->purchaseOrders->isNotEmpty())
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 space-y-5">

                @if($job->purchaseOrders->isNotEmpty())
                <div>
                    <h4 class="text-sm font-semibold text-gray-700 mb-2">Purchase Orders</h4>
                    <ul class="divide-y divide-gray-100 rounded-lg border border-gray-100">
                        @foreach($job->purchaseOrders as $po)
                        <li class="py-2 px-3 flex items-center justify-between">
                            <div class="text-sm">
                                <span class="font-medium text-gray-900">{{ $po->po_number }}</span>
                                <span class="text-gray-500 ml-2">R{{ number_format($po->po_amount, 2) }}</span>
                            </div>
                            @if($po->document_path)
                            <div class="flex items-center gap-3 text-xs font-medium">
                                <a href="{{ route('po.preview', $po->id) }}" target="_blank" rel="noopener" class="text-blue-600 hover:text-blue-800">View</a>
                                <a href="{{ route('po.preview', $po->id) }}?download=1" class="text-gray-600 hover:text-gray-900">Download</a>
                            </div>
                            @endif
                        </li>
                        @endforeach
                    </ul>
                </div>
                @endif

                @if($job->documents->isNotEmpty())
                    <x-documents-list
                        :documents="$job->documents"
                        :hideInternalOnly="false"
                        title="Documents"
                        :recentDays="3" />
                @endif
            </div>
            @endif

            {{-- Timeline — collapsed by default. On a long-running job
                 this list grows and buries the rest of the page; clicking
                 the summary expands it in place. --}}
            @if($job->events->isNotEmpty())
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <style>.timeline-summary::-webkit-details-marker { display: none; } .timeline-summary { list-style: none; }</style>
                <details class="group">
                    <summary class="timeline-summary flex cursor-pointer items-center justify-between gap-3 select-none">
                        <div class="min-w-0">
                            <h3 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                                Timeline
                                <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-600">
                                    {{ $job->events->count() }}
                                </span>
                            </h3>
                            <p class="text-xs text-gray-500 mt-0.5">
                                {{ $job->events->last()?->event_at?->diffForHumans() ?? '—' }} · latest event
                            </p>
                        </div>
                        <svg class="h-5 w-5 shrink-0 text-gray-400 transition-transform group-open:rotate-180"
                             fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                            <path d="m6 9 6 6 6-6"/>
                        </svg>
                    </summary>
                    <ol class="relative border-l border-gray-200 ml-3 space-y-6 mt-4">
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
                </details>
            </div>
            @endif

            {{-- Compact meta strip. Anything ops might occasionally want
                 (UUID, booked-by, created timestamp) lives down here as a
                 quiet footer rather than as its own panel — that info is
                 rarely the thing they actually came here for. --}}
            <div class="rounded-xl border border-gray-200 bg-gray-50/60 px-5 py-3">
                <dl class="flex flex-wrap gap-x-6 gap-y-2 text-xs text-gray-600">
                    <div class="flex items-center gap-1.5">
                        <dt class="text-gray-400">Order</dt>
                        <dd class="font-mono font-medium text-gray-700">{{ $job->job_number ?? '—' }}</dd>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <dt class="text-gray-400">Created</dt>
                        <dd>{{ $job->created_at->format('d M Y H:i') }}</dd>
                    </div>
                    @if($job->createdBy)
                        @php $bookerCompany = $job->createdBy->companies->first(); @endphp
                        <div class="flex items-center gap-1.5">
                            <dt class="text-gray-400">Booked by</dt>
                            <dd class="text-gray-700">
                                <span class="font-medium">{{ $job->createdBy->name }}</span>
                                @if($bookerCompany)
                                    <span class="text-gray-400"> · {{ $bookerCompany->name }}</span>
                                @endif
                            </dd>
                        </div>
                    @endif
                    <div class="flex items-center gap-1.5">
                        <dt class="text-gray-400">UUID</dt>
                        <dd class="font-mono text-[10px] text-gray-500 break-all">{{ $job->uuid }}</dd>
                    </div>
                </dl>
            </div>

        </div>

        {{-- Everything below is intentionally terminated; the old
             two-column grid with a right-hand Actions panel is gone.
             All CTAs live in the Next-step hero at the top of the
             page and all document actions live inside
             <x-documents-list>. --}}

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
