<?php

use App\Models\Job;
use App\Models\ModelTollClassHint;
use App\Models\PettyCashPlan;
use App\Models\RouteTollPlazaHint;
use App\Models\TollPlaza;
use App\Models\User;
use App\Services\AuditService;
use App\Services\TripCostEstimator;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;

new #[Layout('components.layouts.app')] class extends Component {
    public Job $job;
    public ?int $driverId = null;
    public string $cancelReason = '';
    public bool $showCancelModal = false;

    // Urgent collection toggle.  Either ops (any internal user) or
    // the booking customer can mark a job urgent with an optional
    // reason. See JobPolicy::markUrgent for the auth rules.
    public bool $showUrgentModal = false;
    public string $urgentReason = '';

    // Recall to planning. Ops-only override that pulls the job back
    // to STATUS_CONFIRMED, clearing the driver and schedule so it
    // re-enters the planning queue from scratch.  Valid from any
    // pre-delivery status; see JobPolicy::recallToPlanning.
    public bool $showRecallModal = false;
    public string $recallReason = '';

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

    // Petty cash / driver advance panel.  Optional in v1 — opens only
    // when ops clicks the "Petty Cash / Advance" button.  Tolls are
    // auto-computed by TripCostEstimator; accommodation/taxi/food are
    // ops-typed.  See saveAdvance() for the audit-on-increase rule.
    public bool $showAdvancePanel = false;
    public array $advanceTollResult = [
        'status' => 'idle',
        'plazas' => [],
        'toll_total' => 0.0,
    ];
    public ?float $advanceAccommodation = null;
    public ?float $advanceTaxi = null;
    // Taxi is opt-in.  When unticked the taxi field is forced to R0
    // (driver got a shuttle, no cash needed); when ticked we pre-fill
    // the configured standard amount.  Persisted in advance_taxi_included.
    public bool $advanceTaxiIncluded = false;
    public ?float $advanceFood = null;
    public bool $advanceFoodWaived = false;
    public ?float $advanceTotal = null;
    public string $advanceIncreaseReason = '';
    // Change reason on re-issue.  Required whenever ops is changing an
    // already-issued advance -- audit row carries it for the boss's
    // review.  Empty on first issue (initial state is captured by the
    // before/after JSON diff alone).
    public string $advanceChangeReason = '';
    // Override reason for issuing without an approved plan.  Required
    // when ops chooses to bypass the sign-off workflow (e.g. emergency
    // collection after hours).  Surfaces in the audit log so the owner
    // sees who bypassed and why.
    public string $advanceOverrideReason = '';

    // "Issue to driver" modal -- the moment the cash physically goes
    // out (or the bank-send EFT is confirmed).  Distinct from saving
    // the breakdown (which only commits the AMOUNT decision).
    public bool $showIssueModal = false;
    public string $advanceIssueReference = '';

    // "Remove advance" flow.  Ops can wipe an unapproved advance
    // immediately; an APPROVED advance needs owner sign-off for the
    // removal -- ops files the request here, owner accepts or rejects
    // it on the order page.  removalReason captures why.
    public bool $showRemoveModal = false;
    public string $advanceRemovalReason = '';
    // Per-trip SANRAL toll-class override (1-4, or null to use vehicle
    // class default).  Picked from the dropdown on the modal -- the
    // estimator re-runs whenever this changes so the toll list updates
    // live.
    public ?int $advanceTollClassOverride = null;
    // Manual toll override.  When ops types a value here it wins over
    // the auto-detected plaza total -- belt-and-braces for the cases
    // where Google's polyline doesn't pass close enough to a plaza
    // coordinate, or we just don't have the plaza seeded.  Empty/null
    // = use the auto-detected subtotal.
    public ?float $advanceTollsManual = null;

    // Free-form custom petty-cash line items.  Each entry is
    // ['label' => string, 'amount' => float, 'needs_slip' => bool].
    // Persisted to advance_custom_items (JSON) on save.  Labels are
    // remembered per customer company so future trips auto-suggest.
    public array $advanceCustomItems = [];

    // Picker state for "Add gate" -- the toll_plaza_id selected in
    // the dropdown next to the toll table.  Cleared after each add.
    // The act of adding writes a RouteTollPlazaHint for the lane so
    // the same plaza re-applies on every future trip of this
    // (pickup, delivery) pair.  See addTollGate() / removeTollGate().
    public ?int $advanceAddPlazaId = null;

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
            'recalledBy:id,name',
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

    /* ----------------------------------------------------------------
     | Urgent collection toggle
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
        session()->flash('success', 'Marked URGENT — wallboard will reflect immediately.');
    }

    public function clearUrgent(): void
    {
        $this->authorize('markUrgent', $this->job);
        $this->job->clearUrgent(auth()->user());
        $this->job->load('urgentMarkedBy:id,name');
        session()->flash('success', 'Urgent flag cleared.');
    }

    /* ----------------------------------------------------------------
     | Recall to planning
     |---------------------------------------------------------------*/

    public function openRecallModal(): void
    {
        $this->authorize('recallToPlanning', $this->job);
        $this->recallReason = '';
        $this->showRecallModal = true;
    }

    public function closeRecallModal(): void
    {
        $this->showRecallModal = false;
        $this->recallReason = '';
    }

    public function recallToPlanning(): void
    {
        $this->authorize('recallToPlanning', $this->job);
        $this->validate(['recallReason' => 'nullable|string|max:500']);

        $this->job->recallToPlanning(auth()->user(), $this->recallReason);
        $this->job->refresh()->load('recalledBy:id,name');

        $this->showRecallModal = false;
        $this->recallReason = '';
        session()->flash('success', 'Order sent back to planning — driver cleared, schedule reset.');
    }

    /* ----------------------------------------------------------------
     | Petty cash / driver advance
     |
     | Optional ops workflow.  Tolls auto-compute from the route polyline
     | (cached per pickup→delivery pair in route_estimates) crossed with
     | the vehicle's toll class.  Accommodation/taxi/food are typed by
     | ops -- v1 deliberately has no rate table so we don't block the
     | boss demo on a settings page that may never get used.
     |
     | The advance_total can be set higher than the computed sum, but
     | requires a free-text reason that goes into the audit log.  That
     | covers the "blocked route forced an extra night" scenario the
     | user flagged.
     |---------------------------------------------------------------*/

    public function openAdvancePanel(TripCostEstimator $estimator): void
    {
        // Internal-only -- customers and dealers must never see driver
        // advance numbers.  Same posture as the petty cash review page.
        if (!auth()->user()?->isInternal()) {
            abort(403);
        }

        // Seed the form with whatever was previously saved on the job so
        // re-opening shows the last committed advance rather than zeroes.
        $this->advanceAccommodation = $this->job->advance_accommodation !== null
            ? (float) $this->job->advance_accommodation
            : null;
        $this->advanceTotal = $this->job->advance_total !== null
            ? (float) $this->job->advance_total
            : null;
        $this->advanceIncreaseReason = (string) ($this->job->advance_increase_reason ?? '');
        $this->advanceChangeReason = '';  // always blank on open -- ops re-types each time
        $this->advanceOverrideReason = '';
        $this->advanceFoodWaived = (bool) $this->job->advance_food_waived;
        // Toll class override: prefer the value previously committed
        // on this job; otherwise see if we have a remembered correction
        // for the model name (e.g. "Powerstar VX4035 → Class 3").  Only
        // applies when the job has no per-trip value of its own -- ops
        // edits on the current job always win.
        if ($this->job->advance_toll_class_override) {
            $this->advanceTollClassOverride = (int) $this->job->advance_toll_class_override;
        } else {
            // Hint from past trips of the same model -- seeds the
            // dropdown but is NOT stamped onto the in-memory job
            // (Livewire would discard the mutation across the request
            // boundary anyway; the estimator parameter is the single
            // source of truth from here on).
            $this->advanceTollClassOverride = ModelTollClassHint::classFor($this->job->model_name);
        }

        // Same shape for the manual toll override: re-open shows the
        // committed value (null = use auto-detected subtotal).
        $this->advanceTollsManual = $this->job->advance_tolls !== null && $this->job->advance_toll_breakdown
            ? null  // had an auto-detected breakdown last time -- start fresh on the override
            : ($this->job->advance_tolls !== null ? (float) $this->job->advance_tolls : null);

        // Custom petty-cash line items.  Normalise on load so missing
        // keys don't blow up the render.
        $this->advanceCustomItems = collect($this->job->advance_custom_items ?? [])
            ->map(fn ($item) => [
                'label' => (string) ($item['label'] ?? ''),
                'amount' => (float) ($item['amount'] ?? 0),
                'needs_slip' => (bool) ($item['needs_slip'] ?? true),
            ])
            ->values()
            ->all();

        $this->advanceTollResult = $estimator->estimateTolls($this->job, $this->advanceTollClassOverride);

        // Taxi: opt-in.  If this trip had taxi previously enabled keep
        // it on (and keep the saved value); if it's a fresh trip start
        // unticked (R0) so ops makes a deliberate choice.
        $this->advanceTaxiIncluded = (bool) $this->job->advance_taxi_included;
        if ($this->advanceTaxiIncluded) {
            $this->advanceTaxi = $this->job->advance_taxi !== null
                ? (float) $this->job->advance_taxi
                : (float) ($this->advanceTollResult['suggested_taxi'] ?? 50);
        } else {
            $this->advanceTaxi = 0.0;
        }

        // Food: <4h → R0, 4-9h → R150, ≥9h → R300.  Saved value wins
        // on re-open.  If ops has waived food, force the field to 0
        // regardless of what the suggestion says.
        if ($this->advanceFoodWaived) {
            $this->advanceFood = 0.0;
        } else {
            $this->advanceFood = $this->job->advance_food !== null
                ? (float) $this->job->advance_food
                : (float) ($this->advanceTollResult['suggested_food'] ?? 0);
        }

        $this->showAdvancePanel = true;
    }

    public function closeAdvancePanel(): void
    {
        $this->showAdvancePanel = false;
    }

    /**
     * Custom petty-cash line items.  Free-form rows for things outside
     * the predefined buckets (bridge fee, customs, escort, permit,
     * vehicle wash, depot parking, etc.).  Labels are remembered per
     * customer company on save so the next trip for the same customer
     * auto-suggests previously used labels.
     */
    public function addCustomItem(): void
    {
        if (!auth()->user()?->isInternal()) abort(403);
        $this->advanceCustomItems[] = [
            'label' => '',
            'amount' => 0,
            'needs_slip' => true,
        ];
    }

    public function removeCustomItem(int $index): void
    {
        if (!auth()->user()?->isInternal()) abort(403);
        if (isset($this->advanceCustomItems[$index])) {
            unset($this->advanceCustomItems[$index]);
            $this->advanceCustomItems = array_values($this->advanceCustomItems);
        }
    }

    public function recalculateRoute(TripCostEstimator $estimator): void
    {
        if (!auth()->user()?->isInternal()) {
            abort(403);
        }
        $estimator->invalidateRoute($this->job);
        $this->advanceTollResult = $estimator->estimateTolls($this->job, $this->advanceTollClassOverride);
        session()->flash('success', 'Route recalculated.');
    }

    /**
     * Attach a specific seeded toll plaza to this lane.  Used when
     * Google's polyline misses a real booth -- adding it here also
     * teaches the per-lane memory (RouteTollPlazaHint), so the same
     * plaza re-applies on every future trip of this (pickup, delivery)
     * pair without ops touching it.
     *
     * Refuses if the plaza is already in the current list (auto-detected
     * or already remembered) -- avoids accidental double-counting.
     */
    public function addTollGate(TripCostEstimator $estimator): void
    {
        if (!auth()->user()?->isInternal()) {
            abort(403);
        }
        $plazaId = $this->advanceAddPlazaId ? (int) $this->advanceAddPlazaId : null;
        if (!$plazaId) {
            return;
        }
        if (!$this->job->pickup_location_id || !$this->job->delivery_location_id) {
            session()->flash('error', 'Cannot remember a toll gate without both pickup and delivery locations set.');
            return;
        }
        if (!TollPlaza::active()->whereKey($plazaId)->exists()) {
            session()->flash('error', 'That toll plaza is not active.');
            return;
        }

        RouteTollPlazaHint::remember(
            (int) $this->job->pickup_location_id,
            (int) $this->job->delivery_location_id,
            $plazaId,
            auth()->id(),
        );

        $this->advanceAddPlazaId = null;
        $this->advanceTollResult = $estimator->estimateTolls($this->job, $this->advanceTollClassOverride);
    }

    /**
     * Drop a remembered gate from this lane.  Only valid for entries
     * with source === 'remembered' -- auto-detected plazas are not
     * removable (clear them by deactivating the plaza or correcting
     * its coordinates).  Re-estimates so the toll subtotal updates.
     */
    public function removeTollGate(int $plazaId, TripCostEstimator $estimator): void
    {
        if (!auth()->user()?->isInternal()) {
            abort(403);
        }
        if (!$this->job->pickup_location_id || !$this->job->delivery_location_id) {
            return;
        }

        RouteTollPlazaHint::forget(
            (int) $this->job->pickup_location_id,
            (int) $this->job->delivery_location_id,
            $plazaId,
        );

        $this->advanceTollResult = $estimator->estimateTolls($this->job, $this->advanceTollClassOverride);
    }

    /**
     * Re-run the toll-plaza calc with the chosen override class.  The
     * dropdown is wire:model.live on advanceTollClassOverride; Livewire
     * fires this hook on every change so the plaza list + subtotal
     * update without leaving the modal.  We don't persist the override
     * here -- only on Issue Advance -- to keep "look but don't commit"
     * cheap.
     *
     * NOTE: Livewire 3's updated{Property} hook passes the new value as
     * the first positional arg.  If we type-hinted a service here it
     * would be clobbered.  Resolve via the container instead.
     */
    /**
     * Toggling the taxi opt-in checkbox flips the amount field.  When
     * ops ticks it we pre-fill the configured standard (R50 by default);
     * when they untick we force the amount to zero so the running total
     * reflects "no taxi this trip".  The amount can still be edited
     * after ticking.
     */
    public function updatedAdvanceTaxiIncluded(): void
    {
        if (!auth()->user()?->isInternal()) {
            abort(403);
        }
        if ($this->advanceTaxiIncluded) {
            // Restore the saved value if it exists, otherwise the
            // standard.  Don't clobber a previously typed override.
            $standard = (float) ($this->advanceTollResult['suggested_taxi'] ?? 50);
            $this->advanceTaxi = $this->job->advance_taxi !== null
                ? (float) $this->job->advance_taxi
                : $standard;
            if ($this->advanceTaxi <= 0) {
                $this->advanceTaxi = $standard;
            }
        } else {
            $this->advanceTaxi = 0.0;
        }
    }

    public function updatedAdvanceTollClassOverride(): void
    {
        if (!auth()->user()?->isInternal()) {
            abort(403);
        }
        // Empty string from <option value=""> means "use vehicle class
        // default".  Anything else gets cast to int and used as the
        // per-trip override.
        $override = $this->advanceTollClassOverride;
        $this->advanceTollClassOverride = ($override === '' || $override === null) ? null : (int) $override;

        // Pass the chosen class explicitly to the estimator so it
        // doesn't depend on any in-memory mutation of $this->job (which
        // Livewire discards across the request boundary).  Until ops
        // clicks Issue Advance, the chosen class lives only in this
        // public property and is passed in by every call site.
        $this->advanceTollResult = app(TripCostEstimator::class)
            ->estimateTolls($this->job, $this->advanceTollClassOverride);
    }

    public function saveAdvance(): void
    {
        if (!auth()->user()?->isInternal()) {
            abort(403);
        }

        $this->validate([
            'advanceAccommodation'       => 'nullable|numeric|min:0|max:1000000',
            'advanceTaxi'                => 'nullable|numeric|min:0|max:1000000',
            'advanceFood'                => 'nullable|numeric|min:0|max:1000000',
            'advanceFoodWaived'          => 'boolean',
            'advanceTotal'               => 'nullable|numeric|min:0|max:1000000',
            'advanceIncreaseReason'      => 'nullable|string|max:500',
            'advanceTollClassOverride'   => 'nullable|integer|min:1|max:4',
            'advanceTollsManual'         => 'nullable|numeric|min:0|max:1000000',
            'advanceChangeReason'        => 'nullable|string|max:500',
            'advanceCustomItems'             => 'array|max:30',
            'advanceCustomItems.*.label'     => 'nullable|string|max:120',
            'advanceCustomItems.*.amount'    => 'nullable|numeric|min:0|max:1000000',
            'advanceCustomItems.*.needs_slip'=> 'boolean',
        ]);

        // Was this job already issued?  If so the audit trail demands a
        // reason for the change so the owner can see why ops touched
        // it.  First-time issues skip this -- the JSON diff alone tells
        // the whole story when there was no prior value.
        $isReissue = $this->job->advance_total !== null;

        // Computed estimate the panel is showing.  Manual toll override
        // (typed by ops) wins over the auto-detected plaza subtotal --
        // covers routes Google misses or plazas we haven't seeded.
        // Snapshot still records the auto-detected breakdown so the
        // historical record shows what we computed AND what ops decided.
        // Waiver forces food to 0 -- checkbox is an audit-grade signal
        // that ops decided this trip doesn't qualify, separate from
        // "typed zero by accident".
        $autoTolls     = (float) ($this->advanceTollResult['toll_total'] ?? 0);
        $tolls         = $this->advanceTollsManual !== null ? round((float) $this->advanceTollsManual, 2) : $autoTolls;
        $accommodation = (float) ($this->advanceAccommodation ?? 0);
        // Opt-in: when the taxi box is unticked we force R0 here too
        // (defence-in-depth -- the field is already disabled in the UI
        // but a stale typed value could otherwise survive a flip).
        $taxi          = $this->advanceTaxiIncluded ? (float) ($this->advanceTaxi ?? 0) : 0.0;
        $food          = $this->advanceFoodWaived ? 0.0 : (float) ($this->advanceFood ?? 0);
        // Normalise custom items: drop empty rows (no label or zero amount),
        // round amounts.  Empty rows can survive when ops added a row but
        // didn't fill it in -- we don't persist or count those.
        $customItems = [];
        $customTotal = 0.0;
        foreach ($this->advanceCustomItems as $item) {
            $label = trim((string) ($item['label'] ?? ''));
            $amount = round((float) ($item['amount'] ?? 0), 2);
            if ($label === '' || $amount <= 0) continue;
            $customItems[] = [
                'label' => $label,
                'amount' => $amount,
                'needs_slip' => (bool) ($item['needs_slip'] ?? true),
            ];
            $customTotal += $amount;
        }
        $computed      = round($tolls + $accommodation + $taxi + $food + $customTotal, 2);

        // Owner rule (Milton SK, 2026-05-26): cash advances are paid in
        // round amounts -- driver draws bills, not coins.  Round the
        // computed total UP to the nearest configured multiple (R10 by
        // default).  Applied only when ops hasn't typed a manual total;
        // a typed amount wins exactly as entered.  Line items keep
        // their exact values so slip reconciliation stays clean.
        $roundUpTo = (int) \App\Models\SystemSetting::get('advance_round_up_to_multiple', 10);
        $computedRoundedUp = $roundUpTo > 0
            ? (float) (ceil($computed / $roundUpTo) * $roundUpTo)
            : $computed;
        $total = $this->advanceTotal !== null ? round((float) $this->advanceTotal, 2) : $computedRoundedUp;

        // Audit-on-overage rule: any number above the rounded computed
        // estimate demands a written reason.  We compare against the
        // ROUNDED value rather than the raw computed so a routine
        // round-up-to-R10 doesn't trip the rule.  Half-rand tolerance
        // avoids tripping on decimal noise.
        if ($total > $computedRoundedUp + 0.5 && trim($this->advanceIncreaseReason) === '') {
            $this->addError('advanceIncreaseReason', 'A reason is required when the advance is higher than the computed estimate.');
            return;
        }

        // Audit-on-reissue rule: any change to an already-issued advance
        // requires a change reason -- the boss sees that note in the
        // audit history on the order page.  Only enforced when something
        // actually changed (re-clicking with no edits is a no-op).
        $beforeForDiff = $this->job->only([
            'advance_tolls', 'advance_accommodation', 'advance_taxi',
            'advance_food', 'advance_food_waived', 'advance_total',
            'advance_toll_class_override',
        ]);
        $afterForDiff = [
            'advance_tolls' => $tolls,
            'advance_accommodation' => $accommodation,
            'advance_taxi' => $taxi,
            'advance_food' => $food,
            'advance_food_waived' => $this->advanceFoodWaived,
            'advance_total' => $total,
            'advance_toll_class_override' => $this->advanceTollClassOverride,
        ];
        $actuallyChanged = false;
        foreach ($afterForDiff as $k => $v) {
            $b = $beforeForDiff[$k] ?? null;
            // Loose compare on numbers (decimal cast vs float) but
            // strict on the boolean so an unchanged tick doesn't flag.
            if (is_bool($v)) {
                if ((bool) $b !== $v) { $actuallyChanged = true; break; }
            } else {
                if ((float) $b !== (float) $v) { $actuallyChanged = true; break; }
            }
        }

        if ($isReissue && $actuallyChanged && trim($this->advanceChangeReason) === '') {
            $this->addError('advanceChangeReason', 'A reason is required when changing an already-issued advance. The owner sees this note.');
            return;
        }

        // Sign-off awareness, not enforcement.  Owner asked for "just
        // warnings for now, we can put locks in place later" -- so we
        // surface that the trip is unsigned but don't block save.
        // advance_override_reason still captures whatever ops typed
        // (if anything) so the audit trail has it.
        $hasApproval = $this->job->advance_approved_at !== null;
        $isNewIssue = !$isReissue;

        $before = $this->job->only([
            'advance_tolls', 'advance_accommodation', 'advance_taxi',
            'advance_food', 'advance_total', 'advance_increase_reason',
        ]);

        $this->job->forceFill([
            'advance_toll_breakdown'        => $this->advanceTollResult['plazas'] ?? [],
            'advance_toll_class_override'   => $this->advanceTollClassOverride,
            'advance_tolls'                 => $tolls,
            'advance_accommodation'         => $accommodation,
            'advance_taxi'                  => $this->advanceTaxiIncluded ? $taxi : 0,
            'advance_taxi_included'         => $this->advanceTaxiIncluded,
            'advance_food'                  => $food,
            'advance_food_waived'           => $this->advanceFoodWaived,
            'advance_custom_items'          => $customItems,
            'advance_total'                 => $total,
            'advance_increase_reason'       => $total > $computed + 0.5 ? trim($this->advanceIncreaseReason) : null,
            'advance_assigned_by_user_id'   => auth()->id(),
            'advance_assigned_at'           => now(),
            'advance_override_reason'       => (!$hasApproval && $isNewIssue) ? trim($this->advanceOverrideReason) : $this->job->advance_override_reason,
        ])->save();

        // Record any sign-off bypass to the audit log distinctly so
        // the owner can filter on overrides specifically.
        if ($isNewIssue && !$hasApproval) {
            AuditService::log(
                'advance_override_used',
                'job',
                $this->job->id,
                null,
                ['amount' => (float) $total],
                trim($this->advanceOverrideReason),
            );
        }

        // The audit row carries both the diff AND the change reason
        // (when present).  action_type distinguishes the first issue
        // from a re-issue so the boss can filter on "edits" specifically.
        AuditService::log(
            $isReissue ? 'order_advance_reissued' : 'order_advance_issued',
            'job',
            $this->job->id,
            $before,
            $this->job->fresh()->only([
                'advance_tolls', 'advance_accommodation', 'advance_taxi',
                'advance_food', 'advance_food_waived', 'advance_total',
                'advance_increase_reason', 'advance_toll_class_override',
            ]),
            $isReissue ? trim($this->advanceChangeReason) ?: null : null,
        );

        // Teach the global model -> toll-class memory.  When ops set a
        // per-trip class override and the order has a model_name, we
        // remember that mapping for next time -- so the Powerstar VX4035
        // example (an 8x4 that defaults to Class 2 from its HCV/Extra
        // Heavy class) only needs to be corrected once.  Lookups are
        // applied on openAdvancePanel().
        if ($this->advanceTollClassOverride && $this->job->model_name) {
            ModelTollClassHint::remember(
                $this->job->model_name,
                (int) $this->advanceTollClassOverride,
                auth()->id(),
            );
        }

        // Bump last_used_at on every remembered toll-gate for this
        // lane.  use_count is bumped by addTollGate() so it counts
        // "ops re-affirmed the gate"; markUsed here is the "this lane
        // actually carried a trip" signal -- useful when curating
        // which lane corrections are still active routes.
        RouteTollPlazaHint::markUsed(
            (int) $this->job->pickup_location_id,
            (int) $this->job->delivery_location_id,
        );

        // Per-customer-company memory of custom petty-cash labels.
        // Subsequent trips for the same company will auto-suggest the
        // labels ops used on previous trips.  Stored on
        // companies.movement_csv_mapping['custom_petty_cash_labels'] as
        // an array of strings; deduplicated, capped at 50 entries so
        // a typo storm can't unbound-grow the JSON column.
        if ($this->job->company && !empty($customItems)) {
            $mapping = (array) ($this->job->company->movement_csv_mapping ?? []);
            $existing = (array) ($mapping['custom_petty_cash_labels'] ?? []);
            $newLabels = collect($customItems)->pluck('label')->all();
            $merged = collect(array_merge($existing, $newLabels))
                ->filter()
                ->unique()
                ->take(-50)
                ->values()
                ->all();
            if ($merged !== $existing) {
                $mapping['custom_petty_cash_labels'] = $merged;
                $this->job->company->forceFill(['movement_csv_mapping' => $mapping])->save();
            }
        }

        // Auto-add to a Petty-Cash Plan as a side effect of saving.
        // Owner stated: "save must add it to petty cash plan".  We
        // build the item snapshot from the values we just persisted so
        // the plan reflects exactly what ops decided, and the plan
        // total recalcs to include the new (or refreshed) item.
        $planLabel = $this->autoAddToPettyCashPlan([
            'tolls' => (float) $tolls,
            'accommodation' => (float) $accommodation,
            'taxi' => (float) $taxi,
            'food' => (float) $food,
            'custom_items' => $customItems,
            'computed_total' => (float) $total,
        ]);

        $this->showAdvancePanel = false;
        session()->flash('success', 'Driver advance saved: R ' . number_format($total, 2) . ($planLabel ? ' · added to ' . $planLabel : '') . '.');
    }

    /**
     * Snapshot this job into the most recent draft Petty-Cash Plan, or
     * spin up a fresh one if no current draft exists.  Idempotent: if
     * the job is already in an active (pending / approved) plan we
     * REFRESH that plan's snapshot rather than creating a duplicate
     * entry in a new plan.
     *
     * Returns the plan label so the saveAdvance flash can name it.
     */
    private function autoAddToPettyCashPlan(array $breakdown): ?string
    {
        // Already tagged to an active (non-rejected) plan?  Refresh
        // that plan's item snapshot instead of creating a new draft.
        if ($this->job->advance_plan_id) {
            $existing = PettyCashPlan::find($this->job->advance_plan_id);
            if ($existing && $existing->status !== PettyCashPlan::STATUS_REJECTED) {
                $this->updatePlanItemForCurrentJob($existing, $breakdown);
                return $existing->label;
            }
        }

        // Find today's draft (so a busy ops session lands every
        // trip into one bundle for the owner instead of a forest of
        // single-trip plans).  generated_at is timestampTz; cast to
        // date for the day match.
        $draft = PettyCashPlan::query()
            ->where('status', PettyCashPlan::STATUS_DRAFT)
            ->whereDate('generated_at', now()->toDateString())
            ->where('generated_by_user_id', auth()->id())
            ->latest('generated_at')
            ->first();

        if (!$draft) {
            $draft = PettyCashPlan::create([
                'label' => 'Pay-run ' . now()->format('D d M Y'),
                'status' => PettyCashPlan::STATUS_DRAFT,
                'total_amount' => 0,
                'items_json' => [],
                'generated_by_user_id' => auth()->id(),
                'generated_at' => now(),
            ]);
            AuditService::log('petty_cash_plan_created', 'petty_cash_plan', $draft->id, null, ['auto' => true]);
        }

        $this->updatePlanItemForCurrentJob($draft, $breakdown);
        return $draft->label;
    }

    /**
     * Add or replace the current job's entry in the given plan's
     * items_json and recompute the total.  Also stamps the job's
     * advance_plan_id so the order detail "approved by plan #N"
     * banner / Issue gate know which plan to wait on.
     */
    private function updatePlanItemForCurrentJob(PettyCashPlan $plan, array $breakdown): void
    {
        $items = collect($plan->items_json ?? [])
            ->reject(fn ($i) => (int) ($i['job_id'] ?? 0) === $this->job->id)
            ->values()
            ->all();

        $this->job->refresh();  // make sure relations are fresh after the forceFill above
        $items[] = [
            'job_id'          => $this->job->id,
            'job_number'      => $this->job->job_number,
            'company'         => $this->job->company?->name,
            'route'           => trim(($this->job->pickupLocation?->company_name ?? '') . ' → ' . ($this->job->deliveryLocation?->company_name ?? '')),
            'scheduled_date'  => $this->job->scheduled_date?->toDateString(),
            'vehicle_class'   => $this->job->vehicleClass?->name,
            'toll_class'      => (int) ($this->advanceTollResult['toll_class'] ?? $this->job->vehicleClass?->toll_class ?? 0),
            'tolls'           => round((float) $breakdown['tolls'], 2),
            'accommodation'   => round((float) $breakdown['accommodation'], 2),
            'taxi'            => round((float) $breakdown['taxi'], 2),
            'food'            => round((float) $breakdown['food'], 2),
            'custom_items'    => $breakdown['custom_items'] ?? [],
            'computed_total'  => round((float) $breakdown['computed_total'], 2),
        ];

        $plan->forceFill([
            'items_json' => $items,
            'total_amount' => round(collect($items)->sum('computed_total'), 2),
        ])->save();

        // Link the job to the plan so the order detail page shows
        // "added to plan #N" and the Issue gate knows what to check.
        // If the plan is still in draft, advance_approved_at stays
        // null until the owner signs the plan off -- that's exactly
        // the warnings-not-locks behaviour the owner asked for.
        if ($this->job->advance_plan_id !== $plan->id) {
            $this->job->forceFill(['advance_plan_id' => $plan->id])->save();
        }
    }

    /* ----------------------------------------------------------------
     | Issue to driver -- the moment the cash physically goes out.
     |
     | Distinct from saveAdvance (which commits the amount decision).
     | Enabled only when:
     |   (a) an advance has been saved (advance_total is set), AND
     |   (b) the trip has owner sign-off (advance_approved_at) OR an
     |       override reason was recorded on save.
     | Records advance_issued_at, advance_issued_by_user_id, and an
     | optional issue reference (bank-send ref / cash receipt #) for
     | the audit trail.
     |---------------------------------------------------------------*/

    public function openIssueModal(): void
    {
        if (!auth()->user()?->isInternal()) abort(403);
        if ($this->job->advance_total === null) {
            session()->flash('error', 'No advance has been saved for this trip yet. Use Petty Cash / Advance first.');
            return;
        }
        // Driver gate -- same rule as markAsIssued.  Belt-and-braces:
        // the button is disabled in the UI when no driver, but a
        // crafted Livewire call would bypass that.  Block server-side.
        if (!$this->job->driver_user_id) {
            session()->flash('error', 'Assign a driver before issuing the advance. The cash routes to their cellphone.');
            return;
        }
        $this->advanceIssueReference = (string) ($this->job->advance_issue_reference ?? '');
        $this->showIssueModal = true;
    }

    public function closeIssueModal(): void
    {
        $this->showIssueModal = false;
    }

    public function markAsIssued(): void
    {
        if (!auth()->user()?->isInternal()) abort(403);
        if ($this->job->advance_total === null) {
            session()->flash('error', 'No advance to issue.');
            return;
        }

        // Driver gate: the cash routes to the driver's cellphone via
        // bank-send, so there has to be a driver attached.  Planning /
        // sign-off can run without one (we just don't know yet WHO will
        // do the trip); issue is the moment of payment so the recipient
        // must be known.
        if (!$this->job->driver_user_id) {
            session()->flash('error', 'A driver must be assigned to this order before the advance can be issued -- they\'re the recipient of the bank-send.');
            return;
        }

        $this->validate([
            'advanceIssueReference' => 'nullable|string|max:255',
        ]);

        $alreadyIssued = $this->job->advance_issued_at !== null;
        $before = $this->job->only(['advance_issued_at', 'advance_issued_by_user_id', 'advance_issue_reference']);

        $this->job->forceFill([
            'advance_issued_at' => now(),
            'advance_issued_by_user_id' => auth()->id(),
            'advance_issue_reference' => trim($this->advanceIssueReference) ?: null,
        ])->save();

        AuditService::log(
            $alreadyIssued ? 'advance_re_issued_to_driver' : 'advance_issued_to_driver',
            'job',
            $this->job->id,
            $before,
            $this->job->fresh()->only(['advance_issued_at', 'advance_issued_by_user_id', 'advance_issue_reference']),
        );

        $this->showIssueModal = false;
        $this->job->refresh();
        session()->flash('success', 'Advance issued to driver: R ' . number_format((float) $this->job->advance_total, 2) . ($this->advanceIssueReference ? ' · ref ' . trim($this->advanceIssueReference) : '') . '.');
    }

    /* ----------------------------------------------------------------
     | Remove advance.
     |
     | Two states, owner-stated rule:
     |   - Not approved yet -> ops can wipe immediately.  No fields are
     |     locked; the trip just goes back to "no advance" and slides
     |     out of any draft plan it was in.
     |   - Approved by owner -> ops files a removal request; owner has
     |     to second-sign before the wipe applies.  Until then, the
     |     advance stays exactly as it was.
     |---------------------------------------------------------------*/

    public function openRemoveModal(): void
    {
        if (!auth()->user()?->isInternal()) abort(403);
        if ($this->job->advance_total === null) {
            session()->flash('error', 'No advance on this trip to remove.');
            return;
        }
        $this->advanceRemovalReason = '';
        $this->showRemoveModal = true;
    }

    public function closeRemoveModal(): void
    {
        $this->showRemoveModal = false;
        $this->advanceRemovalReason = '';
    }

    /**
     * Ops action: wipe (unapproved) OR file a removal request (approved).
     * The dispatch on advance_approved_at is the gate.
     */
    public function submitRemovalRequest(): void
    {
        if (!auth()->user()?->isInternal()) abort(403);
        if ($this->job->advance_total === null) {
            session()->flash('error', 'No advance on this trip to remove.');
            return;
        }

        $this->validate([
            'advanceRemovalReason' => 'nullable|string|max:500',
        ]);

        if ($this->job->advance_approved_at) {
            // Owner-approved advance -- can't be wiped without a second
            // sign-off.  Stamp the pending state and bail; the actual
            // wipe happens on confirmRemoval() when an owner/dev
            // accepts the request.
            if (trim($this->advanceRemovalReason) === '') {
                $this->addError('advanceRemovalReason', 'A reason is required to request removal of an already-approved advance.');
                return;
            }
            $this->job->forceFill([
                'advance_removal_pending' => true,
                'advance_removal_requested_at' => now(),
                'advance_removal_requested_by_user_id' => auth()->id(),
                'advance_removal_reason' => trim($this->advanceRemovalReason),
            ])->save();

            AuditService::log(
                'advance_removal_requested',
                'job',
                $this->job->id,
                null,
                ['amount' => (float) $this->job->advance_total, 'plan_id' => $this->job->advance_plan_id],
                trim($this->advanceRemovalReason),
            );

            $this->showRemoveModal = false;
            $this->job->refresh();
            session()->flash('success', 'Removal request submitted. Awaiting Accounts sign-off (owner fallback).');
            return;
        }

        // Unapproved -- ops can wipe immediately.
        $this->wipeAdvanceState('advance_removed_unapproved', trim($this->advanceRemovalReason) ?: null);
        $this->showRemoveModal = false;
        $this->job->refresh();
        session()->flash('success', 'Advance removed.');
    }

    /**
     * Accounts / owner / developer action: accept a pending removal request.
     * Wipes the advance fields and clears the pending flag.
     */
    public function confirmRemoval(): void
    {
        $u = auth()->user();
        if (!$u || (!$u->isAccounts() && !$u->isOwner() && !$u->isDeveloper())) abort(403);
        if (!$this->job->advance_removal_pending) {
            session()->flash('error', 'No removal request pending on this trip.');
            return;
        }

        $reason = $this->job->advance_removal_reason ?? '';
        $this->wipeAdvanceState('advance_removed_after_signoff', $reason);
        $this->job->refresh();
        session()->flash('success', 'Removal approved. The advance has been cleared.');
    }

    /**
     * Accounts / owner / developer action: reject a pending removal request.
     * Clears the pending flag and leaves the advance untouched.
     */
    public function rejectRemoval(): void
    {
        $u = auth()->user();
        if (!$u || (!$u->isAccounts() && !$u->isOwner() && !$u->isDeveloper())) abort(403);
        if (!$this->job->advance_removal_pending) {
            session()->flash('error', 'No removal request pending on this trip.');
            return;
        }

        $reason = $this->job->advance_removal_reason ?? '';
        $this->job->forceFill([
            'advance_removal_pending' => false,
            'advance_removal_requested_at' => null,
            'advance_removal_requested_by_user_id' => null,
            'advance_removal_reason' => null,
        ])->save();

        AuditService::log(
            'advance_removal_rejected',
            'job',
            $this->job->id,
            ['removal_reason' => $reason],
            null,
        );

        $this->job->refresh();
        session()->flash('success', 'Removal request rejected. Advance is unchanged.');
    }

    /**
     * Shared wipe: zeros out every advance_* field on the job,
     * detaches from any plan, and records the action in the audit
     * log.  Used by both the unapproved-wipe path and the owner-
     * confirmed-wipe path.
     */
    private function wipeAdvanceState(string $auditAction, ?string $reason): void
    {
        $before = $this->job->only([
            'advance_total', 'advance_tolls', 'advance_accommodation',
            'advance_taxi', 'advance_food', 'advance_food_waived',
            'advance_taxi_included', 'advance_custom_items',
            'advance_toll_breakdown', 'advance_toll_class_override',
            'advance_increase_reason', 'advance_assigned_by_user_id',
            'advance_assigned_at', 'advance_plan_id',
            'advance_approved_at', 'advance_override_reason',
        ]);

        // If the trip was linked to a draft plan, snip its item out
        // of items_json so the plan total reflects the removal too.
        if ($this->job->advance_plan_id) {
            $plan = PettyCashPlan::find($this->job->advance_plan_id);
            if ($plan && $plan->status === PettyCashPlan::STATUS_DRAFT) {
                $items = collect($plan->items_json ?? [])
                    ->reject(fn ($i) => (int) ($i['job_id'] ?? 0) === $this->job->id)
                    ->values()
                    ->all();
                if (empty($items)) {
                    $plan->delete();
                } else {
                    $plan->forceFill([
                        'items_json' => $items,
                        'total_amount' => round((float) collect($items)->sum('computed_total'), 2),
                    ])->save();
                }
            }
        }

        $this->job->forceFill([
            'advance_total' => null,
            'advance_tolls' => null,
            'advance_accommodation' => null,
            'advance_taxi' => null,
            'advance_taxi_included' => false,
            'advance_food' => null,
            'advance_food_waived' => false,
            'advance_custom_items' => null,
            'advance_toll_breakdown' => null,
            'advance_toll_class_override' => null,
            'advance_increase_reason' => null,
            'advance_assigned_by_user_id' => null,
            'advance_assigned_at' => null,
            'advance_plan_id' => null,
            'advance_approved_at' => null,
            'advance_override_reason' => null,
            'advance_removal_pending' => false,
            'advance_removal_requested_at' => null,
            'advance_removal_requested_by_user_id' => null,
            'advance_removal_reason' => null,
        ])->save();

        AuditService::log(
            $auditAction,
            'job',
            $this->job->id,
            $before,
            null,
            $reason,
        );
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
            // Surface the financial query the cancellation just opened so
            // the audit trail makes the reconciliation obligation obvious
            // at the moment of cancellation, not only when Accounts clears it.
            'advance_was_issued' => !is_null($this->job->advance_issued_at),
            'advance_total' => (float) ($this->job->advance_total ?? 0),
        ]);

        if (!is_null($this->job->advance_issued_at) && (float) ($this->job->advance_total ?? 0) > 0) {
            AuditService::log('issued_cancellation_query_opened', 'job', $this->job->id, null, [
                'advance_total' => (float) $this->job->advance_total,
                'advance_issued_at' => $this->job->advance_issued_at?->toIso8601String(),
                'cancellation_reason' => $this->cancelReason,
            ]);
        }

        $this->showCancelModal = false;
        session()->flash('success', "Order {$this->job->job_number} cancelled.");
    }

    /**
     * Accounts/Owner signs off the "advance was issued and then the trip
     * got cancelled" reconciliation query, explaining how the money was
     * recovered (driver returned cash, applied to swap trip, deducted
     * from next slip, written off, etc.). Recorded on the job itself so
     * the dashboard query drops off and the explanation lives with the
     * order forever.
     */
    public string $clearIssuedCancellationNote = '';
    public bool $showClearIssuedCancellationModal = false;

    public function openClearIssuedCancellation(): void
    {
        $u = auth()->user();
        if (!$u || (!$u->isAccounts() && !$u->isOwner() && !$u->isDeveloper())) {
            abort(403);
        }
        $this->clearIssuedCancellationNote = '';
        $this->showClearIssuedCancellationModal = true;
    }

    public function cancelClearIssuedCancellation(): void
    {
        $this->showClearIssuedCancellationModal = false;
        $this->clearIssuedCancellationNote = '';
    }

    public function clearIssuedCancellationQuery(): void
    {
        $u = auth()->user();
        if (!$u || (!$u->isAccounts() && !$u->isOwner() && !$u->isDeveloper())) {
            abort(403);
        }

        if (!$this->job->hasOpenIssuedCancellationQuery()) {
            session()->flash('error', 'No open issued-on-cancelled query on this order.');
            $this->showClearIssuedCancellationModal = false;
            return;
        }

        $this->validate([
            'clearIssuedCancellationNote' => 'required|string|min:5|max:2000',
        ], [
            'clearIssuedCancellationNote.required' => 'Please describe how the cash was reconciled.',
            'clearIssuedCancellationNote.min' => 'Explanation needs to be at least 5 characters so the audit trail makes sense.',
        ]);

        $this->job->issued_cancellation_cleared_at = now();
        $this->job->issued_cancellation_cleared_by_user_id = $u->id;
        $this->job->issued_cancellation_cleared_note = trim($this->clearIssuedCancellationNote);
        $this->job->save();

        AuditService::log('issued_cancellation_query_cleared', 'job', $this->job->id, null, [
            'note' => $this->job->issued_cancellation_cleared_note,
            'cleared_by_roles' => $u->roles->pluck('slug')->values()->all(),
            'advance_total' => (float) ($this->job->advance_total ?? 0),
        ]);

        $this->showClearIssuedCancellationModal = false;
        $this->clearIssuedCancellationNote = '';
        session()->flash('success', 'Reconciliation query cleared.');
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

        // Fall-back for installs where no company has been flagged as
        // platform-owner (or drivers haven't been pivoted to it).
        // Without this fallback the assign-driver dropdown comes up
        // empty even though dispatch board shows the same drivers --
        // dispatch uses a loose all-drivers query, so order detail
        // looked broken in comparison.  Matches the dispatch query so
        // the two pages agree on who can be assigned.
        if ($executorType === Job::EXECUTOR_PROSELVER && $drivers->isEmpty()) {
            $drivers = User::query()
                ->where('is_active', true)
                ->whereHas('roles', fn ($q) => $q->where('slug', 'driver'))
                ->orderBy('name')
                ->get(['id', 'name']);
        }

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

        // Advance audit trail.  Pulls every "issued / reissued" entry
        // for this job so the owner can see the full history of
        // changes -- each row shows who, when, the change reason
        // (mandatory on reissue), and a diff of the amounts.
        $advanceAudit = \App\Models\AuditLog::query()
            ->whereIn('action_type', ['order_advance_issued', 'order_advance_reissued', 'order_advance_assigned'])
            ->where('entity_type', 'job')
            ->where('entity_id', $this->job->id)
            ->with('actor:id,name')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

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
            'canMarkUrgent' => $user->can('markUrgent', $this->job),
            'canRecall' => $user->can('recallToPlanning', $this->job),
            'canArchive' => $user->can('archive', $this->job),
            'canUnarchive' => $user->can('unarchive', $this->job),
            'executorChoices' => Job::EXECUTOR_LABELS,
            'advanceAudit' => $advanceAudit,
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

    {{-- Recall banner: only renders when the job has been recalled at
         least once.  Stays visible even after the order has been re-
         planned so ops can see at a glance why it came back through
         the queue (and who decided it).  Cleared automatically when
         the order finally completes (recalled_at preserved in audit
         log either way). --}}
    @if($job->recalled_at)
        <div class="mb-4 rounded-xl border-2 border-amber-300 bg-amber-50 px-4 py-3 flex items-start gap-3">
            <svg class="h-5 w-5 mt-0.5 shrink-0 text-amber-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/></svg>
            <div class="min-w-0">
                <div class="text-sm font-bold uppercase tracking-wider text-amber-900">Recalled to planning</div>
                @if($job->recall_reason)
                    <div class="mt-0.5 text-sm text-amber-900/90">{{ $job->recall_reason }}</div>
                @endif
                <div class="mt-0.5 text-[11px] text-amber-700">
                    @if($job->recalledBy)
                        Recalled by {{ $job->recalledBy->name }} ·
                    @endif
                    {{ $job->recalled_at->diffForHumans() }} · {{ $job->recalled_at->format('D d M Y H:i') }}
                </div>
            </div>
        </div>
    @endif

    {{-- Advance removal request -- pending accounts sign-off (owner
         fallback).  Renders to internal staff as an informational banner.
         Accounts/owner/dev get
         Accept / Reject buttons inline. --}}
    @if($job->advance_removal_pending && auth()->user()?->isInternal())
        <div class="mb-4 rounded-xl border-2 border-amber-300 bg-amber-50 px-4 py-3">
            <div class="flex items-start gap-3 flex-wrap">
                <svg class="h-5 w-5 mt-0.5 shrink-0 text-amber-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                <div class="min-w-0 flex-1">
                    <div class="text-sm font-bold uppercase tracking-wider text-amber-900">Advance removal — pending sign-off</div>
                    <div class="mt-0.5 text-sm text-amber-900/90">
                        <strong>{{ $job->advanceRemovalRequestedBy?->name ?? 'Ops' }}</strong>
                        requested removal of <strong>R {{ number_format((float) $job->advance_total, 2) }}</strong>
                        {{ $job->advance_removal_requested_at?->diffForHumans() }}.
                    </div>
                    @if($job->advance_removal_reason)
                        <div class="mt-1 text-sm text-amber-900/80"><strong>Reason:</strong> {{ $job->advance_removal_reason }}</div>
                    @endif
                </div>
                @if(auth()->user()?->isAccounts() || auth()->user()?->isOwner() || auth()->user()?->isDeveloper())
                    <div class="flex items-center gap-2 shrink-0">
                        <button wire:click="rejectRemoval"
                            wire:confirm="Reject the removal request? The existing advance will remain in place."
                            class="rounded-lg border border-amber-400 bg-white px-3.5 py-2 text-xs font-semibold text-amber-800 hover:bg-amber-100 transition-colors">
                            Reject removal
                        </button>
                        <button wire:click="confirmRemoval"
                            wire:confirm="Approve removal and wipe the advance for this trip? This is irreversible."
                            class="rounded-lg bg-rose-600 hover:bg-rose-500 px-4 py-2 text-xs font-semibold text-white transition-colors">
                            Approve removal
                        </button>
                    </div>
                @else
                    <span class="text-[11px] text-amber-700/80 italic shrink-0">Waiting on Accounts (owner fallback).</span>
                @endif
            </div>
        </div>
    @endif

    {{-- ──────────────────────────────────────────────────────────────
         Issued-on-cancelled reconciliation query

         Surfaces whenever the trip is cancelled AND an advance was
         already issued (cash out of the till). Accounts/Owner must
         attach a written explanation — driver returned cash, applied
         to swap trip, deducted from next slip, etc. — before this
         row clears from the owner dashboard.
         ────────────────────────────────────────────────────────────── --}}
    @if($job->hasOpenIssuedCancellationQuery() && auth()->user()?->isInternal())
        <div class="mb-4 rounded-xl border-2 border-rose-300 bg-rose-50 px-4 py-3">
            <div class="flex items-start gap-3 flex-wrap">
                <svg class="h-5 w-5 mt-0.5 shrink-0 text-rose-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
                <div class="min-w-0 flex-1">
                    <div class="text-sm font-bold uppercase tracking-wider text-rose-900">Reconciliation query — advance issued, trip cancelled</div>
                    <div class="mt-0.5 text-sm text-rose-900/90">
                        <strong>R {{ number_format((float) $job->advance_total, 2) }}</strong>
                        was issued
                        @if($job->advance_issued_at)
                            {{ $job->advance_issued_at->diffForHumans() }}
                        @endif
                        @if($job->advanceIssuedBy)
                            by <strong>{{ $job->advanceIssuedBy->name }}</strong>
                        @endif
                        and the trip was then cancelled. Accounts/Owner needs to record how the cash was reconciled before the dashboard query clears.
                    </div>
                    @if($job->cancellation_reason)
                        <div class="mt-1 text-sm text-rose-900/80"><strong>Cancellation reason:</strong> {{ $job->cancellation_reason }}</div>
                    @endif
                </div>
                @if(auth()->user()?->isAccounts() || auth()->user()?->isOwner() || auth()->user()?->isDeveloper())
                    <div class="flex items-center gap-2 shrink-0">
                        <button wire:click="openClearIssuedCancellation" type="button"
                            class="rounded-lg bg-rose-600 hover:bg-rose-500 px-4 py-2 text-xs font-semibold text-white transition-colors">
                            Clear with explanation
                        </button>
                    </div>
                @else
                    <span class="text-[11px] text-rose-700/80 italic shrink-0">Waiting on Accounts (owner fallback).</span>
                @endif
            </div>
        </div>
    @endif

    {{-- Cleared note (audit trail, sticks around forever once signed off). --}}
    @if($job->issued_cancellation_cleared_at && auth()->user()?->isInternal())
        <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50/60 px-4 py-2.5">
            <div class="flex items-start gap-2.5">
                <svg class="h-4 w-4 mt-0.5 shrink-0 text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                <div class="min-w-0 flex-1 text-xs text-emerald-900/90">
                    <span class="font-semibold">Reconciliation cleared</span>
                    @if($job->issuedCancellationClearedBy)
                        by <strong>{{ $job->issuedCancellationClearedBy->name }}</strong>
                    @endif
                    {{ $job->issued_cancellation_cleared_at->diffForHumans() }}.
                    @if($job->issued_cancellation_cleared_note)
                        <div class="mt-0.5 text-emerald-900/80"><strong>Note:</strong> {{ $job->issued_cancellation_cleared_note }}</div>
                    @endif
                </div>
            </div>
        </div>
    @endif

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
                        @if($job->is_round_trip)
                            <span class="inline-flex items-center gap-1 rounded-full border border-indigo-200 bg-indigo-50 px-2 py-0.5 text-[11px] font-semibold text-indigo-700" title="Driver waits at the destination and returns on the same trip (e.g. COF, weighbridge)">
                                <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
                                Round trip
                            </span>
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

                    {{-- Trip petty-cash report.  Internal-only -- the PDF
                         carries driver cellphone numbers and the advance
                         reconciliation, so we hide it from external users.
                         Always available (even on terminal jobs) because
                         finance pulls it after delivery for paper-trail. --}}
                    @if(auth()->user()?->isInternal())
                        <a href="{{ route('trip-report.download', $job) }}" target="_blank"
                            class="inline-flex items-center gap-2 rounded-lg border border-emerald-200 bg-white px-3.5 py-2.5 text-sm font-medium text-emerald-800 hover:bg-emerald-50 transition-colors"
                            title="Per-vehicle petty cash report with slip images.">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="14" x2="15" y2="14"/><line x1="9" y1="18" x2="15" y2="18"/></svg>
                            Trip Report (PDF)
                        </a>
                    @endif

                    @if($isCancellable)
                        @can('cancel', $job)
                            <button wire:click="openCancelModal"
                                class="inline-flex items-center gap-2 rounded-lg border border-red-200 bg-white px-3.5 py-2.5 text-sm font-medium text-red-700 hover:bg-red-50 transition-colors">
                                Cancel Order
                            </button>
                        @endcan
                    @endif

                    @if($canRecall)
                        <button wire:click="openRecallModal"
                            class="inline-flex items-center gap-2 rounded-lg border border-amber-300 bg-white px-3.5 py-2.5 text-sm font-medium text-amber-800 hover:bg-amber-50 transition-colors"
                            title="Reset driver + schedule and push the order back into the planning queue.">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/></svg>
                            Send back to planning
                        </button>
                    @endif

                    @if($canMarkUrgent)
                        @if($job->is_urgent)
                            <button wire:click="clearUrgent" wire:confirm="Clear the URGENT flag on this order?"
                                class="inline-flex items-center gap-2 rounded-lg border border-fuchsia-300 bg-fuchsia-50 px-3.5 py-2.5 text-sm font-semibold text-fuchsia-800 hover:bg-fuchsia-100 transition-colors">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                                Clear URGENT
                            </button>
                        @else
                            <button wire:click="openUrgentModal"
                                class="inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-3.5 py-2.5 text-sm font-medium text-gray-700 hover:bg-fuchsia-50 hover:border-fuchsia-200 hover:text-fuchsia-800 transition-colors">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                                Mark Urgent
                            </button>
                        @endif
                    @endif

                    @if($canChangeExecutor)
                        <button wire:click="openExecutorPanel"
                            class="inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-3.5 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                            Change Executor
                        </button>
                    @endif

                    {{-- Petty Cash / Driver Advance -- manages the
                         breakdown (toll class, custom items, override
                         reasons).  This is the "decide the amounts"
                         step; physical hand-over is the separate
                         "Issue to driver" button below. --}}
                    @if(auth()->user()?->isInternal() && !$isTerminal)
                        <button wire:click="openAdvancePanel"
                            class="inline-flex items-center gap-2 rounded-lg border px-3.5 py-2.5 text-sm font-medium transition-colors
                                {{ $job->advance_total !== null
                                    ? 'border-emerald-300 bg-emerald-50 text-emerald-800 hover:bg-emerald-100'
                                    : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50' }}"
                            title="Compute tolls and decide the driver's advance for this trip.">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 12h.01M18 12h.01"/></svg>
                            @if($job->advance_total !== null)
                                Petty Cash · R {{ number_format((float) $job->advance_total, 2) }}
                            @else
                                Petty Cash / Advance
                            @endif
                        </button>
                    @endif

                    {{-- Remove advance.  Visible whenever an advance
                         exists.  Two flows handled server-side:
                           - unapproved -> ops can wipe immediately
                           - approved -> ops files a removal request
                             that owner has to second-sign before the
                             actual wipe applies.  Pending removals
                             change this button into a "pending" pill.
                         --}}
                    @if(auth()->user()?->isInternal() && !$isTerminal && $job->advance_total !== null)
                        @if($job->advance_removal_pending)
                            <span
                                class="inline-flex items-center gap-2 rounded-lg border border-amber-300 bg-amber-50 px-3.5 py-2.5 text-sm font-medium text-amber-800"
                                title="Removal requested by {{ $job->advanceRemovalRequestedBy?->name }} {{ $job->advance_removal_requested_at?->diffForHumans() }}">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                Removal pending sign-off
                            </span>
                        @else
                            <button wire:click="openRemoveModal"
                                class="inline-flex items-center gap-2 rounded-lg border border-rose-200 bg-white px-3.5 py-2.5 text-sm font-medium text-rose-700 hover:bg-rose-50 transition-colors"
                                title="@if($job->advance_approved_at) Removal requires owner sign-off (advance was already approved). @else Wipe the saved advance for this trip. @endif">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                Remove advance
                            </button>
                        @endif
                    @endif

                    {{-- Issue to Driver -- the moment cash physically
                         goes out.  Distinct from saving the breakdown.
                         Disabled until an advance amount has been saved
                         AND a driver is assigned (the cash routes to
                         their cellphone, so we need to know who).
                         Visually changes once issued so ops sees the
                         status at a glance. --}}
                    @if(auth()->user()?->isInternal() && !$isTerminal && $job->advance_total !== null)
                        @if($job->advance_issued_at)
                            <button wire:click="openIssueModal"
                                class="inline-flex items-center gap-2 rounded-lg border border-blue-300 bg-blue-50 px-3.5 py-2.5 text-sm font-medium text-blue-800 hover:bg-blue-100 transition-colors"
                                title="Already issued {{ $job->advance_issued_at->diffForHumans() }}. Click to re-issue or update the reference.">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                Issued {{ $job->advance_issued_at->format('d M') }}
                            </button>
                        @elseif(!$job->driver_user_id)
                            <button type="button" disabled
                                class="inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-gray-100 px-3.5 py-2.5 text-sm font-medium text-gray-400 cursor-not-allowed"
                                title="Assign a driver before you can issue the advance -- the cash routes to their cellphone.">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                                Issue to Driver
                                <span class="text-[10px] uppercase tracking-wide text-amber-600 font-semibold">assign driver first</span>
                            </button>
                        @else
                            <button wire:click="openIssueModal"
                                class="inline-flex items-center gap-2 rounded-lg border border-blue-300 bg-blue-600 px-3.5 py-2.5 text-sm font-semibold text-white hover:bg-blue-500 transition-colors"
                                title="Record that the cash has been handed to {{ $job->driver?->name }} / EFT sent.">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                                Issue to Driver
                            </button>
                        @endif
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

            {{-- Pickup & Delivery -- full address + lat/lng status.  The
                 "missing coords" amber pill is a load-bearing signal:
                 if it's there, the trip-cost estimator won't be able to
                 detect tolls until the address is geocoded (run
                 `php artisan locations:geocode` or edit the location). --}}
            @if($job->isTransport())
            @php
                $pickupHasCoords = $job->pickupLocation && $job->pickupLocation->latitude && $job->pickupLocation->longitude;
                $deliveryHasCoords = $job->deliveryLocation && $job->deliveryLocation->latitude && $job->deliveryLocation->longitude;
            @endphp
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <h4 class="text-sm font-semibold text-gray-700 mb-3 flex items-center gap-2">
                        <svg class="h-4 w-4 text-green-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                        Pickup
                        @if($job->pickupLocation && !$pickupHasCoords)
                            <a href="{{ route('admin.settings.locations') }}?focus={{ $job->pickup_location_id }}" target="_blank"
                                class="inline-flex items-center gap-1 rounded-full bg-amber-100 text-amber-800 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider hover:bg-amber-200"
                                title="No latitude/longitude — toll auto-detection won't work for this order until geocoded.">
                                <svg class="h-2.5 w-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M12 9v2m0 4h.01"/><circle cx="12" cy="12" r="10"/></svg>
                                no coords
                            </a>
                        @endif
                        @if($job->pickupLocation)
                            <a href="{{ route('admin.settings.locations') }}?focus={{ $job->pickup_location_id }}" target="_blank"
                                class="ml-auto inline-flex items-center gap-1 text-[11px] font-medium text-blue-600 hover:text-blue-800"
                                title="Open this location in the address book to correct the address or coordinates.">
                                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                                Edit
                            </a>
                        @endif
                    </h4>
                    <p class="text-sm font-semibold text-gray-900">{{ $job->pickupLocation?->company_name ?? '—' }}</p>
                    @if($job->pickupLocation?->address && strcasecmp(trim($job->pickupLocation->address), trim((string) $job->pickupLocation->company_name)) !== 0)
                        <p class="text-sm text-gray-600 mt-0.5">{{ $job->pickupLocation->address }}</p>
                    @endif
                    @php $pickupTail = trim(implode(', ', array_filter([$job->pickupLocation?->city, $job->pickupLocation?->province]))); @endphp
                    @if($pickupTail !== '')
                        <p class="text-xs text-gray-500 mt-0.5">{{ $pickupTail }}</p>
                    @endif
                    @if($job->pickup_contact_name || $job->pickup_contact_phone)
                        <div class="mt-3 pt-3 border-t border-gray-100">
                            @if($job->pickup_contact_name)
                                <p class="text-sm text-gray-700">{{ $job->pickup_contact_name }}</p>
                            @endif
                            @if($job->pickup_contact_phone)
                                <p class="text-xs text-gray-500 font-mono">{{ $job->pickup_contact_phone }}</p>
                            @endif
                        </div>
                    @endif
                </div>
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <h4 class="text-sm font-semibold text-gray-700 mb-3 flex items-center gap-2">
                        <svg class="h-4 w-4 text-red-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                        Delivery
                        @if($job->deliveryLocation && !$deliveryHasCoords)
                            <a href="{{ route('admin.settings.locations') }}?focus={{ $job->delivery_location_id }}" target="_blank"
                                class="inline-flex items-center gap-1 rounded-full bg-amber-100 text-amber-800 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider hover:bg-amber-200"
                                title="No latitude/longitude — toll auto-detection won't work for this order until geocoded.">
                                <svg class="h-2.5 w-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M12 9v2m0 4h.01"/><circle cx="12" cy="12" r="10"/></svg>
                                no coords
                            </a>
                        @endif
                        @if($job->deliveryLocation)
                            <a href="{{ route('admin.settings.locations') }}?focus={{ $job->delivery_location_id }}" target="_blank"
                                class="ml-auto inline-flex items-center gap-1 text-[11px] font-medium text-blue-600 hover:text-blue-800"
                                title="Open this location in the address book to correct the address or coordinates.">
                                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                                Edit
                            </a>
                        @endif
                    </h4>
                    <p class="text-sm font-semibold text-gray-900">{{ $job->deliveryLocation?->company_name ?? '—' }}</p>
                    @if($job->deliveryLocation?->address && strcasecmp(trim($job->deliveryLocation->address), trim((string) $job->deliveryLocation->company_name)) !== 0)
                        <p class="text-sm text-gray-600 mt-0.5">{{ $job->deliveryLocation->address }}</p>
                    @endif
                    @php $deliveryTail = trim(implode(', ', array_filter([$job->deliveryLocation?->city, $job->deliveryLocation?->province]))); @endphp
                    @if($deliveryTail !== '')
                        <p class="text-xs text-gray-500 mt-0.5">{{ $deliveryTail }}</p>
                    @endif
                    @if($job->delivery_contact_name || $job->delivery_contact_phone)
                        <div class="mt-3 pt-3 border-t border-gray-100">
                            @if($job->delivery_contact_name)
                                <p class="text-sm text-gray-700">{{ $job->delivery_contact_name }}</p>
                            @endif
                            @if($job->delivery_contact_phone)
                                <p class="text-xs text-gray-500 font-mono">{{ $job->delivery_contact_phone }}</p>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
            @endif

            {{-- Petty cash / advance audit trail.  Visible to anyone who
                 can see the order; meant for the owner to review every
                 change ops makes to an issued advance.  Each entry shows
                 who, when, the change reason (mandatory on reissue),
                 and the rand delta against the prior state. --}}
            @if(auth()->user()?->isInternal() && $advanceAudit->isNotEmpty())
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <h4 class="text-sm font-semibold text-gray-700 mb-3 flex items-center gap-2">
                        <svg class="h-4 w-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8v4l3 3"/><circle cx="12" cy="12" r="10"/></svg>
                        Advance audit trail
                        <span class="ml-auto text-[10px] uppercase tracking-wide text-gray-400 font-normal">For owner review</span>
                    </h4>
                    <ul class="divide-y divide-gray-100">
                        @foreach($advanceAudit as $entry)
                            @php
                                $before = is_array($entry->before_json) ? $entry->before_json : [];
                                $after  = is_array($entry->after_json) ? $entry->after_json : [];
                                $beforeTotal = $before['advance_total'] ?? null;
                                $afterTotal  = $after['advance_total'] ?? null;
                                $isReissueAction = $entry->action_type === 'order_advance_reissued';
                            @endphp
                            <li class="py-3 first:pt-0 last:pb-0">
                                <div class="flex items-start gap-3">
                                    <span class="mt-0.5 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[10px] font-bold
                                        {{ $isReissueAction ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800' }}">
                                        {{ $isReissueAction ? 'EDIT' : 'NEW' }}
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-semibold text-gray-900">
                                            @if($isReissueAction) Re-issued @else Issued @endif
                                            @if($afterTotal !== null)
                                                <span class="ml-1 font-mono">R {{ number_format((float) $afterTotal, 2) }}</span>
                                            @endif
                                            @if($isReissueAction && $beforeTotal !== null && $afterTotal !== null)
                                                @php $diff = round((float) $afterTotal - (float) $beforeTotal, 2); @endphp
                                                <span class="ml-2 text-[11px] font-semibold tabular-nums {{ $diff > 0 ? 'text-rose-600' : ($diff < 0 ? 'text-emerald-600' : 'text-gray-500') }}">
                                                    ({{ $diff >= 0 ? '+' : '' }}R {{ number_format($diff, 2) }} from R {{ number_format((float) $beforeTotal, 2) }})
                                                </span>
                                            @endif
                                        </p>
                                        @if($entry->reason)
                                            <p class="mt-0.5 text-xs text-gray-700"><span class="font-semibold">Reason:</span> {{ $entry->reason }}</p>
                                        @elseif($isReissueAction)
                                            <p class="mt-0.5 text-[11px] italic text-rose-600">No reason recorded — pre-dates the audit requirement.</p>
                                        @endif
                                        <p class="mt-0.5 text-[11px] text-gray-500">
                                            {{ $entry->actor?->name ?? 'Unknown' }}
                                            @if($entry->actor_roles_snapshot)
                                                <span class="text-gray-400">· {{ $entry->actor_roles_snapshot }}</span>
                                            @endif
                                            · {{ $entry->created_at?->format('D d M Y · H:i') }}
                                            <span class="text-gray-400">({{ $entry->created_at?->diffForHumans() }})</span>
                                        </p>

                                        {{-- Per-category diff for re-issues, compact one-line.
                                             Only render fields where the value actually
                                             changed so the row stays readable. --}}
                                        @if($isReissueAction)
                                            @php
                                                $cats = [
                                                    'advance_tolls' => 'Tolls',
                                                    'advance_accommodation' => 'Accommodation',
                                                    'advance_taxi' => 'Taxi',
                                                    'advance_food' => 'Food',
                                                ];
                                                $deltas = [];
                                                foreach ($cats as $k => $lbl) {
                                                    $b = (float) ($before[$k] ?? 0);
                                                    $a = (float) ($after[$k] ?? 0);
                                                    if (abs($a - $b) > 0.5) {
                                                        $sign = $a > $b ? '+' : '−';
                                                        $deltas[] = $lbl . ' ' . $sign . 'R ' . number_format(abs($a - $b), 2);
                                                    }
                                                }
                                                $bWaived = (bool) ($before['advance_food_waived'] ?? false);
                                                $aWaived = (bool) ($after['advance_food_waived'] ?? false);
                                                if ($bWaived !== $aWaived) {
                                                    $deltas[] = $aWaived ? 'Food waived' : 'Food unwaived';
                                                }
                                                $bTcl = (int) ($before['advance_toll_class_override'] ?? 0);
                                                $aTcl = (int) ($after['advance_toll_class_override'] ?? 0);
                                                if ($bTcl !== $aTcl) {
                                                    $deltas[] = 'Toll class ' . ($bTcl ?: 'auto') . ' → ' . ($aTcl ?: 'auto');
                                                }
                                            @endphp
                                            @if(!empty($deltas))
                                                <p class="mt-1 text-[11px] text-gray-600">{{ implode(' · ', $deltas) }}</p>
                                            @endif
                                        @endif
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
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

    {{-- Mark Urgent Modal --}}
    @if($showUrgentModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60" wire:click.self="closeUrgentModal">
        <div class="relative w-full max-w-md mx-4 bg-white rounded-2xl shadow-2xl overflow-hidden">
            <div class="border-b border-gray-200 px-6 py-4 bg-fuchsia-50">
                <div class="flex items-center gap-2">
                    <svg class="h-5 w-5 text-fuchsia-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                    <h3 class="text-lg font-semibold text-fuchsia-900">Mark as Urgent</h3>
                </div>
                <p class="text-sm text-fuchsia-700 mt-0.5">{{ $job->job_number }}</p>
            </div>
            <div class="px-6 py-5 space-y-4">
                <div class="rounded-lg bg-fuchsia-50 border border-fuchsia-200 p-4 text-sm text-fuchsia-900">
                    This order will be flagged URGENT on the live wallboard, sorted to the top of its lane, and counted on the Urgent headline.
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Reason <span class="text-gray-400 font-normal">(optional, shown on hover)</span></label>
                    <textarea wire:model="urgentReason" rows="3" maxlength="500" placeholder="e.g. Customer collecting tomorrow morning, must be at depot by 5pm…"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fuchsia-500 focus:ring-fuchsia-500"></textarea>
                    @error('urgentReason') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="border-t border-gray-200 px-6 py-4 flex items-center justify-end gap-3 bg-gray-50">
                <button wire:click="closeUrgentModal" type="button" class="rounded-lg px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 transition-colors">
                    Cancel
                </button>
                <button wire:click="saveUrgent" class="rounded-lg bg-fuchsia-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-fuchsia-500 transition-colors">
                    Mark URGENT
                </button>
            </div>
        </div>
    </div>
    @endif

    {{-- Recall to Planning Modal --}}
    @if($showRecallModal)
    @php
        $recallOnRoad = in_array($job->status, [Job::STATUS_COLLECTED, Job::STATUS_IN_TRANSIT], true);
    @endphp
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60" wire:click.self="closeRecallModal">
        <div class="relative w-full max-w-md mx-4 bg-white rounded-2xl shadow-2xl overflow-hidden">
            <div class="border-b border-gray-200 px-6 py-4 bg-amber-50">
                <div class="flex items-center gap-2">
                    <svg class="h-5 w-5 text-amber-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/></svg>
                    <h3 class="text-lg font-semibold text-amber-900">Send back to planning</h3>
                </div>
                <p class="text-sm text-amber-700 mt-0.5">{{ $job->job_number }}</p>
            </div>
            <div class="px-6 py-5 space-y-4">
                <div @class([
                        'rounded-lg p-4 text-sm border',
                        'bg-red-50 border-red-200 text-red-900'        => $recallOnRoad,
                        'bg-amber-50 border-amber-200 text-amber-900'  => !$recallOnRoad,
                ])>
                    @if($recallOnRoad)
                        <strong>The truck has already left the depot.</strong>
                        Confirm only if the vehicle is being recalled to the yard. The driver will need to be contacted out-of-band to return.
                    @else
                        The assigned driver and scheduled date will be cleared. The order returns to <em>Collection Confirmed</em> at the top of the planning queue.
                    @endif
                </div>
                <ul class="text-xs text-gray-600 space-y-1 pl-4 list-disc">
                    <li>Driver assignment cleared</li>
                    <li>Scheduled date &amp; ready-time cleared</li>
                    <li>Collection / in-transit timestamps wiped</li>
                    <li>URGENT flag, customer confirmation, PO data and documents preserved</li>
                </ul>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Reason <span class="text-gray-400 font-normal">(optional)</span></label>
                    <textarea wire:model="recallReason" rows="3" maxlength="500" placeholder="e.g. Driver unavailable, vehicle not ready, customer rescheduled…"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-amber-500 focus:ring-amber-500"></textarea>
                    @error('recallReason') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="border-t border-gray-200 px-6 py-4 flex items-center justify-end gap-3 bg-gray-50">
                <button wire:click="closeRecallModal" type="button" class="rounded-lg px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 transition-colors">
                    Keep as is
                </button>
                <button wire:click="recallToPlanning" @class([
                        'rounded-lg px-5 py-2.5 text-sm font-semibold text-white transition-colors',
                        'bg-red-600 hover:bg-red-500'     => $recallOnRoad,
                        'bg-amber-600 hover:bg-amber-500' => !$recallOnRoad,
                ])>
                    Send back to planning
                </button>
            </div>
        </div>
    </div>
    @endif

    {{-- Clear Issued-on-Cancelled Reconciliation Query Modal --}}
    @if($showClearIssuedCancellationModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60" wire:click.self="cancelClearIssuedCancellation">
        <div class="relative w-full max-w-md mx-4 bg-white rounded-2xl shadow-2xl overflow-hidden">
            <div class="border-b border-gray-200 px-6 py-4">
                <h3 class="text-lg font-semibold text-gray-900">Clear Reconciliation Query</h3>
                <p class="text-sm text-gray-500 mt-0.5">{{ $job->job_number }} — R {{ number_format((float) $job->advance_total, 2) }} issued before cancellation</p>
            </div>
            <div class="px-6 py-5 space-y-4">
                <div class="rounded-lg bg-rose-50 border border-rose-200 p-3 text-sm text-rose-900">
                    Describe how the cash was reconciled (driver returned cash, applied to swap trip <em>NNNNN</em>, deducted from next slip, written off, etc.). This explanation is permanent and shows on the audit log.
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Explanation</label>
                    <textarea wire:model="clearIssuedCancellationNote" rows="4"
                        placeholder="e.g. Driver returned R 438.00 cash to ops on 27 May. Booked back into petty cash float."
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-rose-500 focus:ring-rose-500"></textarea>
                    @error('clearIssuedCancellationNote') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="border-t border-gray-200 px-6 py-4 flex items-center justify-end gap-3 bg-gray-50">
                <button wire:click="cancelClearIssuedCancellation" type="button" class="rounded-lg px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 transition-colors">
                    Cancel
                </button>
                <button wire:click="clearIssuedCancellationQuery" type="button" class="rounded-lg bg-rose-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-rose-500 transition-colors">
                    Clear query
                </button>
            </div>
        </div>
    </div>
    @endif

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

    {{-- Remove advance modal.  Branches on advance_approved_at:
         unapproved -> immediate wipe with audit; approved -> filed
         as a removal request awaiting accounts sign-off (owner fallback). Reason is
         required when the advance is approved, optional otherwise. --}}
    @if($showRemoveModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60" wire:click.self="closeRemoveModal">
        <div class="relative w-full max-w-md mx-4 bg-white rounded-2xl shadow-2xl overflow-hidden">
            <div class="border-b border-gray-200 px-6 py-4 bg-rose-50">
                <div class="flex items-center gap-2">
                    <svg class="h-5 w-5 text-rose-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                    <h3 class="text-lg font-semibold text-rose-900">Remove advance</h3>
                </div>
                <p class="text-sm text-rose-800/80 mt-0.5">{{ $job->job_number }} · R {{ number_format((float) $job->advance_total, 2) }}</p>
            </div>
            <div class="px-6 py-5 space-y-4">
                @if($job->advance_approved_at)
                    <div class="rounded-lg bg-amber-50 border border-amber-200 px-3 py-3 text-xs text-amber-800">
                        <p class="font-semibold mb-1">⚠ This advance was already approved.</p>
                        <p>Removing it requires a second sign-off by Accounts (owner fallback). The request will be filed and the existing advance stays in place until it is accepted or rejected.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">Reason for removal <span class="text-rose-600">*</span></label>
                        <textarea wire:model="advanceRemovalReason" rows="3" maxlength="500"
                            placeholder="e.g. trip cancelled, driver swap, customer rebooked next week…"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-rose-500 focus:ring-rose-500"></textarea>
                        @error('advanceRemovalReason') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                @else
                    <div class="rounded-lg bg-rose-50 border border-rose-200 px-3 py-2 text-sm text-rose-800">
                        This advance hasn't been approved yet — removing wipes it immediately and pulls the trip out of any draft plan.
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">Reason <span class="text-gray-400 font-normal">(optional)</span></label>
                        <textarea wire:model="advanceRemovalReason" rows="2" maxlength="500"
                            placeholder="(optional)"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-rose-500 focus:ring-rose-500"></textarea>
                    </div>
                @endif
            </div>
            <div class="border-t border-gray-200 px-6 py-4 flex items-center justify-end gap-3 bg-gray-50">
                <button wire:click="closeRemoveModal" type="button"
                    class="rounded-lg px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 transition-colors">
                    Cancel
                </button>
                <button wire:click="submitRemovalRequest" type="button"
                    wire:confirm="@if($job->advance_approved_at) Submit removal request for Accounts sign-off (owner fallback)? @else Wipe the advance for this trip? @endif"
                    class="rounded-lg bg-rose-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-rose-500 transition-colors">
                    @if($job->advance_approved_at) Submit removal request @else Remove advance @endif
                </button>
            </div>
        </div>
    </div>
    @endif

    {{-- Issue to Driver modal -- compact confirmation; records the
         moment cash physically went out and lets ops drop in a
         bank-send reference / receipt # for the paper trail. --}}
    @if($showIssueModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60" wire:click.self="closeIssueModal">
        <div class="relative w-full max-w-md mx-4 bg-white rounded-2xl shadow-2xl overflow-hidden">
            <div class="border-b border-gray-200 px-6 py-4 bg-blue-50">
                <div class="flex items-center gap-2">
                    <svg class="h-5 w-5 text-blue-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                    <h3 class="text-lg font-semibold text-blue-900">Issue to driver</h3>
                </div>
                <p class="text-sm text-blue-800/80 mt-0.5">{{ $job->job_number }} · {{ $job->driver?->name ?? 'no driver yet' }}</p>
            </div>
            <div class="px-6 py-5 space-y-4">
                <div class="rounded-lg bg-blue-50 border border-blue-200 p-3 text-sm text-blue-900">
                    Confirming this records <strong>R {{ number_format((float) $job->advance_total, 2) }}</strong>
                    as physically handed to the driver
                    @php
                        $phone = $job->driver?->phone ?: $job->driver?->driverProfile?->cellphone;
                    @endphp
                    @if($phone)
                        (bank-send: <span class="font-mono">{{ $phone }}</span>)
                    @endif
                    at <strong>{{ now()->format('H:i') }}</strong>.
                </div>

                @if(!$job->advance_approved_at)
                    <div class="rounded-lg bg-amber-50 border border-amber-200 px-3 py-2 text-xs text-amber-800">
                        ⚠ Heads up: this trip hasn't been signed off via a Petty-cash Plan yet.
                        @if($job->advance_plan_id)
                            It's on <a href="{{ route('admin.petty-cash.plans', ['tab' => 'drafts']) }}" target="_blank" class="font-semibold underline">draft plan #{{ $job->advance_plan_id }}</a> waiting for owner approval.
                        @endif
                        Issuing now will record an audit entry for the owner to review.
                    </div>
                @endif

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">
                        Reference <span class="text-gray-400 font-normal">(optional — bank-send ref, cash receipt #, etc.)</span>
                    </label>
                    <input wire:model="advanceIssueReference" type="text" maxlength="255"
                        placeholder="e.g. CASHSEND-A4B7C9 or RCT-2026-0142"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                    @error('advanceIssueReference') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                @if($job->advance_issued_at)
                    <p class="text-[11px] text-gray-500">
                        Previously issued {{ $job->advance_issued_at->diffForHumans() }}
                        @if($job->advanceIssuedBy) by {{ $job->advanceIssuedBy->name }} @endif
                        @if($job->advance_issue_reference) · ref <span class="font-mono">{{ $job->advance_issue_reference }}</span> @endif
                    </p>
                @endif
            </div>
            <div class="border-t border-gray-200 px-6 py-4 flex items-center justify-end gap-3 bg-gray-50">
                <button wire:click="closeIssueModal" type="button"
                    class="rounded-lg px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 transition-colors">
                    Cancel
                </button>
                <button wire:click="markAsIssued" type="button"
                    wire:confirm="Record this advance as physically issued to the driver?"
                    class="rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-500 transition-colors">
                    @if($job->advance_issued_at) Re-issue & update ref @else Confirm issued @endif
                </button>
            </div>
        </div>
    </div>
    @endif

    {{-- Petty Cash / Driver Advance panel.  Optional ops workflow; opens
         on demand via the button above the modal stack.  Three sections:
         (1) auto-computed toll plaza list, (2) typed accommodation/taxi/
         food, (3) total + audit-on-overage. --}}
    @if($showAdvancePanel)
    @php
        $tollsRand     = $advanceTollsManual !== null ? (float) $advanceTollsManual : (float) ($advanceTollResult['toll_total'] ?? 0);
        $accomRand     = (float) ($advanceAccommodation ?? 0);
        $taxiRand      = $advanceTaxiIncluded ? (float) ($advanceTaxi ?? 0) : 0.0;
        $foodRand      = $advanceFoodWaived ? 0.0 : (float) ($advanceFood ?? 0);
        // Same normalisation as saveAdvance() so the live "Computed
        // estimate" matches the value that will actually persist.
        $customTotalRand = 0.0;
        foreach ($advanceCustomItems as $ci) {
            $lbl = trim((string) ($ci['label'] ?? ''));
            $amt = (float) ($ci['amount'] ?? 0);
            if ($lbl !== '' && $amt > 0) $customTotalRand += round($amt, 2);
        }
        $computedRand = round($tollsRand + $accomRand + $taxiRand + $foodRand + $customTotalRand, 2);
        // Match the saveAdvance round-up rule so the visible "Computed
        // estimate" and the value that will actually be persisted agree.
        $roundUpTo = (int) \App\Models\SystemSetting::get('advance_round_up_to_multiple', 10);
        $computedRoundedRand = $roundUpTo > 0 ? (float) (ceil($computedRand / $roundUpTo) * $roundUpTo) : $computedRand;
        $displayTotal = $advanceTotal !== null ? (float) $advanceTotal : $computedRoundedRand;
        $isOverage    = $displayTotal > $computedRoundedRand + 0.5;
    @endphp
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 overflow-y-auto py-8" wire:click.self="closeAdvancePanel">
        <div class="relative w-full max-w-3xl mx-4 bg-white rounded-2xl shadow-2xl overflow-hidden">
            <div class="border-b border-gray-200 px-6 py-4 bg-emerald-50">
                <div class="flex items-center gap-2">
                    <svg class="h-5 w-5 text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 12h.01M18 12h.01"/></svg>
                    <h3 class="text-lg font-semibold text-emerald-900">Petty Cash / Driver Advance</h3>
                </div>
                <p class="text-sm text-emerald-800/80 mt-0.5">{{ $job->job_number }} · {{ $job->pickupLocation?->company_name ?? '—' }} → {{ $job->deliveryLocation?->company_name ?? '—' }}</p>
            </div>

            <div class="px-6 py-5 space-y-5 max-h-[70vh] overflow-y-auto">

                {{-- Route summary + toll breakdown --}}
                <section>
                    <div class="flex items-center justify-between mb-2 flex-wrap gap-2">
                        <h4 class="text-sm font-semibold text-gray-800">Tolls along route</h4>
                        <div class="flex items-center gap-2">
                            {{-- SANRAL toll-class override.  Vehicle class
                                 default is shown as "Auto (Class N)"; the
                                 four bands are spelled out so ops doesn't
                                 need to remember the axle rules. --}}
                            <label class="flex items-center gap-1.5 text-xs">
                                <span class="text-gray-600">Toll class:</span>
                                <select wire:model.live="advanceTollClassOverride"
                                    class="rounded-md border border-gray-300 bg-white px-2 py-1 text-xs focus:border-emerald-500 focus:ring-emerald-500">
                                    <option value="">Auto ({{ $job->vehicleClass?->name ?? 'unset' }} — Class {{ $job->vehicleClass?->toll_class ?? '?' }})</option>
                                    <option value="1">Class 1 — light vehicle (cars / bakkies)</option>
                                    <option value="2">Class 2 — 2-axle truck / bus</option>
                                    <option value="3">Class 3 — 3-4 axle</option>
                                    <option value="4">Class 4 — 5+ axle (articulated)</option>
                                </select>
                            </label>
                            <button wire:click="recalculateRoute" type="button"
                                class="text-xs rounded-md border border-gray-200 bg-white px-2.5 py-1 text-gray-700 hover:bg-gray-50">
                                Recalculate route
                            </button>
                        </div>
                    </div>

                    @if($advanceTollResult['status'] === 'ok')
                        @php
                            $rememberedHint = $job->model_name ? \App\Models\ModelTollClassHint::classFor($job->model_name) : null;
                        @endphp
                        <p class="text-xs text-gray-500 mb-2">
                            {{ number_format((float) $advanceTollResult['distance_km'], 1) }} km ·
                            {{ (int) floor($advanceTollResult['duration_minutes'] / 60) }}h {{ $advanceTollResult['duration_minutes'] % 60 }}m ·
                            toll class {{ $advanceTollResult['toll_class'] }}
                            @if($advanceTollResult['cached'])
                                <span class="ml-1 text-gray-400">(cached)</span>
                            @endif
                            @if($rememberedHint && (int) $rememberedHint === (int) $advanceTollResult['toll_class'])
                                <span class="ml-1 inline-flex items-center gap-1 text-emerald-700 font-semibold" title="Remembered from a previous trip with the same model.">
                                    <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8"/><polyline points="21 3 21 8 16 8"/></svg>
                                    remembered
                                </span>
                            @endif
                        </p>

                        @php
                            // Plaza ids already on this trip (auto or
                            // remembered).  Used to filter the picker so
                            // ops can't add a duplicate.
                            $existingPlazaIds = collect($advanceTollResult['plazas'] ?? [])
                                ->pluck('toll_plaza_id')
                                ->filter()
                                ->map(fn ($id) => (int) $id)
                                ->all();
                            $pickerOptions = \App\Models\TollPlaza::active()
                                ->orderBy('road_name')
                                ->orderBy('plaza_name')
                                ->get(['id', 'road_name', 'plaza_name', 'plaza_type'])
                                ->reject(fn ($p) => in_array((int) $p->id, $existingPlazaIds, true));
                        @endphp

                        @if(empty($advanceTollResult['plazas']))
                            <div class="rounded-lg bg-gray-50 border border-gray-200 px-3 py-2 text-xs text-gray-600">
                                No toll plazas detected along this route. Add a gate below if Google's route is missing one, or type the rand value further down.
                            </div>
                        @else
                            <div class="rounded-lg border border-gray-200 overflow-hidden">
                                <table class="w-full text-xs">
                                    <thead class="bg-gray-50 text-gray-500 uppercase tracking-wide">
                                        <tr>
                                            <th class="text-left px-3 py-1.5 font-medium">Plaza</th>
                                            <th class="text-left px-3 py-1.5 font-medium">Road</th>
                                            <th class="text-left px-3 py-1.5 font-medium">Type</th>
                                            <th class="text-right px-3 py-1.5 font-medium">Fee (R)</th>
                                            <th class="w-6"></th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        @foreach($advanceTollResult['plazas'] as $plaza)
                                            @php $isRemembered = ($plaza['source'] ?? 'auto') === 'remembered'; @endphp
                                            <tr class="{{ $isRemembered ? 'bg-emerald-50/40' : '' }}">
                                                <td class="px-3 py-1.5 text-gray-900">
                                                    {{ $plaza['plaza_name'] }}
                                                    @if($isRemembered)
                                                        <span class="ml-1 inline-flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wide text-emerald-700" title="Manually added to this lane. Auto-applies on future trips with the same pickup and delivery.">
                                                            <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8"/><polyline points="21 3 21 8 16 8"/></svg>
                                                            remembered
                                                        </span>
                                                    @endif
                                                </td>
                                                <td class="px-3 py-1.5 text-gray-600">{{ $plaza['road_name'] }}</td>
                                                <td class="px-3 py-1.5 text-gray-500">{{ $plaza['plaza_type'] }}</td>
                                                <td class="px-3 py-1.5 text-right font-mono text-gray-900">{{ number_format((float) $plaza['fee'], 2) }}</td>
                                                <td class="px-2 py-1.5 text-right">
                                                    @if($isRemembered && !empty($plaza['toll_plaza_id']))
                                                        <button type="button"
                                                            wire:click="removeTollGate({{ (int) $plaza['toll_plaza_id'] }})"
                                                            wire:loading.attr="disabled"
                                                            class="text-gray-400 hover:text-rose-600 disabled:opacity-50"
                                                            title="Remove this gate from the lane memory.">
                                                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                        </button>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                        <tr class="bg-gray-50">
                                            <td colspan="3" class="px-3 py-1.5 text-right font-semibold text-gray-700">Toll subtotal</td>
                                            <td class="px-3 py-1.5 text-right font-mono font-bold text-emerald-700">R {{ number_format($tollsRand, 2) }}</td>
                                            <td></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        @endif

                        {{-- Add a missing gate.  Writes a per-lane
                             RouteTollPlazaHint, so the next trip on the
                             same (pickup, delivery) pair will include it
                             automatically.  Hidden when both endpoint
                             ids aren't set (estimator already shows a
                             warning above in that case). --}}
                        @if($job->pickup_location_id && $job->delivery_location_id)
                            <div class="mt-2 flex flex-wrap items-end gap-2 rounded-lg border border-dashed border-emerald-300 bg-emerald-50/40 px-3 py-2">
                                <label class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 flex flex-col gap-1 flex-1 min-w-[240px]">
                                    Add a toll gate (remembered for this lane)
                                    <select wire:model.live="advanceAddPlazaId"
                                        class="rounded-md border border-gray-300 bg-white px-2 py-1.5 text-sm focus:border-emerald-500 focus:ring-emerald-500 normal-case tracking-normal">
                                        <option value="">Choose a plaza…</option>
                                        @foreach($pickerOptions as $opt)
                                            <option value="{{ $opt->id }}">{{ $opt->road_name }} — {{ $opt->plaza_name }} ({{ $opt->plaza_type }})</option>
                                        @endforeach
                                    </select>
                                </label>
                                <button wire:click="addTollGate" type="button"
                                    wire:loading.attr="disabled"
                                    @if(!$advanceAddPlazaId) disabled @endif
                                    class="rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50">
                                    Add gate
                                </button>
                                <span class="basis-full text-[10px] text-gray-500 leading-snug">
                                    Use this when Google's route skips a real booth (e.g. an inland bypass alternative). It'll re-apply on every future trip with the same pickup and delivery.
                                </span>
                            </div>
                        @endif
                    @else
                        <div class="rounded-lg bg-amber-50 border border-amber-200 px-3 py-3 text-xs text-amber-800 space-y-1">
                            <p>{{ $advanceTollResult['message'] }}</p>
                            @if(($advanceTollResult['status'] ?? '') === 'missing_coords')
                                <p class="text-[11px] text-amber-700/80">
                                    Fix from the address book:
                                    @if($advanceTollResult['missing_pickup_coords'] ?? false)
                                        <a href="{{ route('admin.settings.locations') }}?focus={{ $advanceTollResult['pickup_location_id'] ?? '' }}" target="_blank" class="font-semibold underline hover:no-underline">Edit pickup ({{ $job->pickupLocation?->company_name }})</a>
                                    @endif
                                    @if(($advanceTollResult['missing_pickup_coords'] ?? false) && ($advanceTollResult['missing_delivery_coords'] ?? false))
                                        ·
                                    @endif
                                    @if($advanceTollResult['missing_delivery_coords'] ?? false)
                                        <a href="{{ route('admin.settings.locations') }}?focus={{ $advanceTollResult['delivery_location_id'] ?? '' }}" target="_blank" class="font-semibold underline hover:no-underline">Edit delivery ({{ $job->deliveryLocation?->company_name }})</a>
                                    @endif
                                </p>
                                <p class="text-[10px] text-amber-700/70">Or run <code class="bg-amber-100 px-1 rounded">php artisan locations:geocode</code> on the server to backfill all in one shot.</p>
                            @endif
                        </div>
                    @endif

                    {{-- Manual toll override.  Always available -- ops
                         can type a rand value here to win over the
                         auto-detected subtotal.  Useful when (a) the
                         route runs through a plaza we haven't seeded,
                         (b) Directions API is disabled, or (c) the
                         polyline-to-plaza haversine match missed.
                         Empty = use auto-detected. --}}
                    <div class="mt-3 flex flex-wrap items-end gap-2 rounded-lg border border-dashed border-gray-300 px-3 py-2">
                        <label class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 flex flex-col gap-1">
                            Override toll subtotal (R)
                            <input wire:model.live.debounce.300ms="advanceTollsManual" type="number" min="0" step="0.01"
                                placeholder="auto: {{ number_format((float) ($advanceTollResult['toll_total'] ?? 0), 2) }}"
                                class="rounded-md border border-gray-300 px-3 py-1.5 text-sm w-40 focus:border-emerald-500 focus:ring-emerald-500 normal-case tracking-normal">
                        </label>
                        <span class="text-[10px] text-gray-500 leading-snug max-w-md">
                            Auto-detected is <strong>R {{ number_format((float) ($advanceTollResult['toll_total'] ?? 0), 2) }}</strong>. Leave blank to use it, or type a value to override.
                        </span>
                    </div>
                </section>

                {{-- Manual quantities --}}
                <section>
                    <h4 class="text-sm font-semibold text-gray-800 mb-2">Other expenses</h4>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <label class="block">
                            <span class="block text-[11px] uppercase tracking-wide text-gray-500 mb-1">Accommodation (R)</span>
                            <input wire:model.live.debounce.300ms="advanceAccommodation" type="number" min="0" step="0.01" placeholder="0.00"
                                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                        </label>
                        <div>
                            <label class="flex items-center gap-1.5 text-[11px] uppercase tracking-wide text-gray-500 mb-1 cursor-pointer">
                                <input type="checkbox" wire:model.live="advanceTaxiIncluded" class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                                Taxi (R)
                                @if($advanceTaxiIncluded)
                                    <span class="ml-1 font-semibold text-emerald-700 normal-case tracking-normal">· standard R{{ number_format((float) ($advanceTollResult['suggested_taxi'] ?? 50), 0) }} (no slip)</span>
                                @else
                                    <span class="ml-1 font-semibold text-gray-400 normal-case tracking-normal">· shuttle (no cash)</span>
                                @endif
                            </label>
                            <input wire:model.live.debounce.300ms="advanceTaxi" type="number" min="0" step="0.01" placeholder="0.00"
                                @if(!$advanceTaxiIncluded) disabled @endif
                                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-emerald-500 focus:ring-emerald-500 {{ !$advanceTaxiIncluded ? 'bg-gray-100 text-gray-400' : '' }}">
                        </div>
                        <label class="block">
                            <span class="block text-[11px] uppercase tracking-wide text-gray-500 mb-1">
                                Food (R)
                                @if($advanceFoodWaived)
                                    <span class="ml-1 font-semibold text-rose-700 normal-case tracking-normal">· waived by ops</span>
                                @elseif(($advanceTollResult['days_count'] ?? 0) === 2)
                                    <span class="ml-1 font-semibold text-emerald-700 normal-case tracking-normal">· 2-day trip (auto)</span>
                                @elseif(($advanceTollResult['days_count'] ?? 0) === 1)
                                    <span class="ml-1 font-semibold text-emerald-700 normal-case tracking-normal">· single-day (auto)</span>
                                @elseif(($advanceTollResult['status'] ?? '') === 'ok')
                                    <span class="ml-1 font-semibold text-gray-400 normal-case tracking-normal">· under {{ (int) ($advanceTollResult['food_minimum_hours'] ?? 4) }}h, no food</span>
                                @endif
                            </span>
                            <input wire:model.live.debounce.300ms="advanceFood" type="number" min="0" step="0.01" placeholder="0.00"
                                @if($advanceFoodWaived) disabled @endif
                                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-emerald-500 focus:ring-emerald-500 {{ $advanceFoodWaived ? 'bg-gray-100 text-gray-400' : '' }}">
                            <label class="mt-1.5 flex items-center gap-1.5 text-[11px] text-gray-600 cursor-pointer">
                                <input type="checkbox" wire:model.live="advanceFoodWaived" class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                                Trip does not qualify for food allowance
                            </label>
                            <span class="mt-1 block text-[10px] text-gray-400">
                                Rule: R{{ number_format((float) ($advanceTollResult['food_rate_per_day'] ?? 150), 0) }}/day.
                                Under {{ (int) ($advanceTollResult['food_minimum_hours'] ?? 4) }}h = none;
                                {{ (int) ($advanceTollResult['food_minimum_hours'] ?? 4) }}–{{ (int) ($advanceTollResult['food_threshold_hours'] ?? 9) }}h = 1 day;
                                ≥{{ (int) ($advanceTollResult['food_threshold_hours'] ?? 9) }}h = 2 days.
                            </span>
                        </label>
                    </div>
                </section>

                {{-- Custom petty-cash line items.  Free-form rows for
                     items outside the 4 predefined buckets -- bridge
                     fees, customs clearance, escorts, permits, wash etc.
                     Labels are remembered per customer company. --}}
                @php
                    $rememberedLabels = (array) ($job->company?->movement_csv_mapping['custom_petty_cash_labels'] ?? []);
                    $datalistId = 'custom-labels-' . $job->id;
                @endphp
                <section>
                    <div class="flex items-center justify-between mb-2">
                        <h4 class="text-sm font-semibold text-gray-800">Custom line items</h4>
                        <button wire:click="addCustomItem" type="button"
                            class="inline-flex items-center gap-1 text-xs rounded-md border border-gray-200 bg-white px-2.5 py-1 text-gray-700 hover:bg-gray-50">
                            <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                            Add line
                        </button>
                    </div>

                    @if(!empty($rememberedLabels))
                        <datalist id="{{ $datalistId }}">
                            @foreach($rememberedLabels as $label)
                                <option value="{{ $label }}"></option>
                            @endforeach
                        </datalist>
                    @endif

                    @if(empty($advanceCustomItems))
                        <p class="text-[11px] text-gray-400 italic">No custom items. Click "Add line" for things like customs clearance, bridge fee, escort, permit, vehicle wash.</p>
                    @else
                        <div class="space-y-1.5">
                            @foreach($advanceCustomItems as $idx => $item)
                                <div class="flex items-start gap-2" wire:key="custom-{{ $idx }}">
                                    <input wire:model="advanceCustomItems.{{ $idx }}.label"
                                        type="text"
                                        list="{{ $datalistId }}"
                                        maxlength="120"
                                        placeholder="Label (e.g. customs clearance)"
                                        class="flex-1 min-w-0 rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                                    <input wire:model.live.debounce.500ms="advanceCustomItems.{{ $idx }}.amount"
                                        type="number" min="0" step="0.01"
                                        placeholder="R 0.00"
                                        class="w-28 shrink-0 rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                                    <label class="flex items-center gap-1 text-[10px] text-gray-500 cursor-pointer pt-2 shrink-0">
                                        <input type="checkbox" wire:model.live="advanceCustomItems.{{ $idx }}.needs_slip"
                                            class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                                        slip
                                    </label>
                                    <button wire:click="removeCustomItem({{ $idx }})" type="button"
                                        class="shrink-0 rounded-md p-1.5 text-gray-400 hover:text-rose-600 hover:bg-rose-50"
                                        title="Remove this line">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                                    </button>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </section>

                {{-- Total + audit-on-overage --}}
                <section>
                    <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 space-y-1">
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-semibold text-gray-800">Computed estimate</span>
                            <span class="font-mono text-base font-bold text-gray-900">R {{ number_format($computedRand, 2) }}</span>
                        </div>
                        @if($roundUpTo > 0 && abs($computedRoundedRand - $computedRand) > 0.001)
                            <div class="flex items-center justify-between text-xs">
                                <span class="text-emerald-700 font-semibold">Rounded up to nearest R{{ $roundUpTo }} (cash draw)</span>
                                <span class="font-mono font-bold text-emerald-700">R {{ number_format($computedRoundedRand, 2) }}</span>
                            </div>
                        @endif
                    </div>

                    <div class="mt-3">
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">
                            Advance to assign (R)
                            <span class="ml-1 text-xs text-gray-400">leave blank to use the rounded estimate</span>
                        </label>
                        <input wire:model.live.debounce.300ms="advanceTotal" type="number" min="0" step="0.01" placeholder="{{ number_format($computedRoundedRand, 2, '.', '') }}"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                    </div>

                    @if($isOverage)
                        <div class="mt-3 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3">
                            <p class="text-xs font-semibold text-amber-900 mb-1">Above the computed estimate (+R {{ number_format($displayTotal - $computedRand, 2) }}) — reason required</p>
                            <textarea wire:model="advanceIncreaseReason" rows="2" maxlength="500"
                                placeholder="e.g. blocked route via N3 (detour through N11), extra night in Harrismith…"
                                class="mt-1 w-full rounded-lg border border-amber-300 px-3 py-2 text-sm focus:border-amber-500 focus:ring-amber-500"></textarea>
                            @error('advanceIncreaseReason') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    {{-- Sign-off status banner.  Approved trips show a
                         green "approved by plan #N" badge.  Unapproved
                         trips show an informational amber banner --
                         saving auto-adds the trip to a draft plan, so
                         this is just a "heads up, no owner sign-off
                         yet" note.  No required input; ops can type an
                         optional note if they want it in the audit log. --}}
                    @if($job->advance_approved_at && $job->advance_plan_id)
                        <div class="mt-3 rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-2 flex items-center gap-2">
                            <svg class="h-4 w-4 text-emerald-700 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                            <p class="text-xs text-emerald-900">
                                Approved by <a href="{{ route('admin.petty-cash.plans', ['tab' => 'approved']) }}" target="_blank" class="font-semibold underline">plan #{{ $job->advance_plan_id }}</a>
                                · {{ $job->advance_approved_at->diffForHumans() }}
                            </p>
                        </div>
                    @elseif($job->advance_plan_id)
                        <div class="mt-3 rounded-lg border border-amber-300 bg-amber-50 px-4 py-2 flex items-center gap-2">
                            <svg class="h-4 w-4 text-amber-700 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
                            <p class="text-xs text-amber-900">
                                On <a href="{{ route('admin.petty-cash.plans', ['tab' => 'drafts']) }}" target="_blank" class="font-semibold underline">draft plan #{{ $job->advance_plan_id }}</a>
                                · awaiting owner sign-off
                            </p>
                        </div>
                    @else
                        <div class="mt-3 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3">
                            <p class="text-xs font-semibold text-amber-900 mb-1">No owner sign-off yet</p>
                            <p class="text-[11px] text-amber-800/80 mb-2">
                                Saving will automatically add this trip to today's Petty-cash Plan for owner sign-off.
                                Optionally type a note for the audit log if there's anything the owner should know.
                            </p>
                            <textarea wire:model="advanceOverrideReason" rows="2" maxlength="500"
                                placeholder="(optional) e.g. emergency after-hours collection, customer changed schedule…"
                                class="w-full rounded-lg border border-amber-300 px-3 py-2 text-sm focus:border-amber-500 focus:ring-amber-500"></textarea>
                        </div>
                    @endif

                    {{-- Re-issue audit reason.  Required when this order
                         already had an advance issued and ops is now
                         changing it.  The boss reads this on the audit
                         trail panel below to know what changed and why. --}}
                    @if($job->advance_total !== null)
                        <div class="mt-3 rounded-lg border border-blue-300 bg-blue-50 px-4 py-3">
                            <p class="text-xs font-semibold text-blue-900 mb-1">Change reason (required if you change any amount)</p>
                            <textarea wire:model="advanceChangeReason" rows="2" maxlength="500"
                                placeholder="e.g. driver lost the original advance, route extended, customer added a stop…"
                                class="mt-1 w-full rounded-lg border border-blue-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
                            @error('advanceChangeReason') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            <p class="mt-1 text-[10px] text-blue-700/80">Every re-issue is recorded in the audit trail with who, when, and what changed. The owner sees it on this order.</p>
                        </div>
                    @endif

                    @if($job->advance_assigned_at && $job->advanceAssignedBy)
                        <p class="mt-3 text-[11px] text-gray-500">
                            Issued by {{ $job->advanceAssignedBy->name }} · {{ $job->advance_assigned_at->diffForHumans() }}
                            @if($job->advance_increase_reason)
                                · reason: <span class="italic">{{ $job->advance_increase_reason }}</span>
                            @endif
                        </p>
                    @endif
                </section>
            </div>

            <div class="border-t border-gray-200 px-6 py-4 flex items-center justify-between gap-3 bg-gray-50">
                <p class="text-[11px] text-gray-500 leading-snug">
                    Issuing locks the amount as the driver's advance and stamps who/when. The driver reconciles against this on return.
                </p>
                <div class="flex items-center gap-3">
                    <button wire:click="closeAdvancePanel" type="button"
                        class="rounded-lg px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 transition-colors">
                        Close
                    </button>
                    <button wire:click="saveAdvance" type="button"
                        wire:confirm="Save this advance amount? Issuing the cash to the driver is a separate step done from the order page."
                        class="rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-500 transition-colors">
                        @if($job->advance_total !== null) Save changes @else Save advance @endif
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif
</div>
