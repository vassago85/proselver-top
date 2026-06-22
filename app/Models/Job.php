<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Job extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $table = 'transport_jobs';

    const TYPE_TRANSPORT = 'transport';
    const TYPE_YARD_WORK = 'yard_work';

    // Legacy statuses (kept for backward compatibility)
    const STATUS_PENDING_VERIFICATION = 'pending_verification';
    const STATUS_VERIFIED = 'verified';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    const STATUS_ASSIGNED = 'assigned';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_COMPLETED = 'completed';
    const STATUS_READY_FOR_INVOICING = 'ready_for_invoicing';
    const STATUS_INVOICED = 'invoiced';
    const STATUS_CANCELLED = 'cancelled';

    // Phase 1 statuses
    const STATUS_RECEIVED = 'received';
    const STATUS_AWAITING_CUSTOMER_CONFIRMATION = 'awaiting_customer_confirmation';
    const STATUS_CONFIRMATION_ISSUE = 'confirmation_issue';
    const STATUS_CONFIRMED = 'confirmed';
    const STATUS_PLANNED = 'planned';
    const STATUS_DRIVER_ASSIGNED = 'driver_assigned';
    const STATUS_READY_FOR_COLLECTION = 'ready_for_collection';
    const STATUS_COLLECTED = 'collected';
    const STATUS_IN_TRANSIT = 'in_transit';
    const STATUS_DELIVERED = 'delivered';

    const TRANSPORT_STATUSES = [
        self::STATUS_PENDING_VERIFICATION,
        self::STATUS_VERIFIED,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_ASSIGNED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_COMPLETED,
        self::STATUS_READY_FOR_INVOICING,
        self::STATUS_INVOICED,
        self::STATUS_CANCELLED,
    ];

    const YARD_STATUSES = [
        self::STATUS_PENDING_VERIFICATION,
        self::STATUS_VERIFIED,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_ASSIGNED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_COMPLETED,
        self::STATUS_READY_FOR_INVOICING,
        self::STATUS_INVOICED,
        self::STATUS_CANCELLED,
    ];

    const ALLOWED_TRANSITIONS = [
        // STATUS_RECEIVED is the one-click "verify & move to Phase 1"
        // bridge. Ops does not want to click verify → approve → send to
        // customer → confirm to unblock a PO; a single Verify button on
        // the order page drops the job straight into the Phase 1 chain
        // at RECEIVED, where the rest of the UI takes over (send-to-
        // customer for FAW-style workflows, confirm for standard).
        self::STATUS_PENDING_VERIFICATION => [self::STATUS_VERIFIED, self::STATUS_RECEIVED, self::STATUS_REJECTED, self::STATUS_CANCELLED],
        self::STATUS_VERIFIED => [self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED],
        self::STATUS_APPROVED => [self::STATUS_ASSIGNED, self::STATUS_CANCELLED],
        self::STATUS_REJECTED => [self::STATUS_PENDING_VERIFICATION],
        self::STATUS_ASSIGNED => [self::STATUS_IN_PROGRESS, self::STATUS_CANCELLED],
        self::STATUS_IN_PROGRESS => [self::STATUS_COMPLETED, self::STATUS_CANCELLED],
        self::STATUS_COMPLETED => [self::STATUS_READY_FOR_INVOICING],
        self::STATUS_READY_FOR_INVOICING => [self::STATUS_INVOICED],
        self::STATUS_INVOICED => [],
        self::STATUS_CANCELLED => [],
    ];

    // Statuses that appear on the Phase 1 operational surfaces
    // (admin dashboard recent feed, /admin/orders table, status filter
    // pills). PENDING_VERIFICATION is the entry state for every dealer
    // and OEM booking created via BookingService, so it MUST live here —
    // otherwise brand-new bookings are invisible to ops until someone
    // manually nudges them forward.
    const PHASE1_STATUSES = [
        self::STATUS_PENDING_VERIFICATION,
        self::STATUS_RECEIVED,
        self::STATUS_AWAITING_CUSTOMER_CONFIRMATION,
        self::STATUS_CONFIRMATION_ISSUE,
        self::STATUS_CONFIRMED,
        self::STATUS_PLANNED,
        self::STATUS_DRIVER_ASSIGNED,
        self::STATUS_READY_FOR_COLLECTION,
        self::STATUS_COLLECTED,
        self::STATUS_IN_TRANSIT,
        self::STATUS_DELIVERED,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    const CONFIRMATION_ISSUE_REASONS = [
        'truck_not_at_location' => 'Truck has not arrived at this location',
        'truck_damaged' => 'Truck is here but has visible damage',
        'mechanical_issue' => 'Truck is here but has a mechanical issue (won\'t start, flat battery, etc.)',
        'parts_missing' => 'Truck is here but parts or accessories are missing',
        'documentation_issue' => 'Paperwork or documentation is incomplete',
        'loading_access_issue' => 'Truck blocked in, yard closed, or access problem',
        'other' => 'Other (see notes)',
    ];

    const PHASE1_TRANSITIONS = [
        self::STATUS_RECEIVED => [self::STATUS_AWAITING_CUSTOMER_CONFIRMATION, self::STATUS_CONFIRMED, self::STATUS_CANCELLED],
        self::STATUS_AWAITING_CUSTOMER_CONFIRMATION => [self::STATUS_CONFIRMED, self::STATUS_CONFIRMATION_ISSUE, self::STATUS_CANCELLED],
        // CONFIRMATION_ISSUE → CONFIRMED is the ops-override path: ops phones
        // the customer, resolves the issue verbally, and pushes through
        // (audit-logged in admin/orders/show.blade.php:confirmOrderOverride).
        self::STATUS_CONFIRMATION_ISSUE => [self::STATUS_AWAITING_CUSTOMER_CONFIRMATION, self::STATUS_CONFIRMED, self::STATUS_CANCELLED],
        self::STATUS_CONFIRMED => [self::STATUS_PLANNED, self::STATUS_CANCELLED],
        self::STATUS_PLANNED => [self::STATUS_DRIVER_ASSIGNED, self::STATUS_CANCELLED],
        // Once a driver is assigned the next event is the driver arriving at pickup
        // (which flips straight to COLLECTED). STATUS_READY_FOR_COLLECTION is kept
        // only as a legacy terminal-ish state for in-flight orders created before
        // the workflow was simplified — those rows still need to be able to move to
        // COLLECTED or CANCELLED, hence the entry below.
        //
        // PLANNED is also a legal target so ops can unassign / swap the driver
        // (e.g. they picked the wrong driver, or the driver is no longer
        // available). This is a soft rollback — the previous `assigned_at`
        // is preserved for audit, only `driver_user_id` is cleared.
        self::STATUS_DRIVER_ASSIGNED => [self::STATUS_COLLECTED, self::STATUS_CANCELLED, self::STATUS_PLANNED],
        self::STATUS_READY_FOR_COLLECTION => [self::STATUS_COLLECTED, self::STATUS_CANCELLED, self::STATUS_PLANNED],
        self::STATUS_COLLECTED => [self::STATUS_IN_TRANSIT],
        self::STATUS_IN_TRANSIT => [self::STATUS_DELIVERED],
        self::STATUS_DELIVERED => [self::STATUS_COMPLETED],
        self::STATUS_COMPLETED => [],
        self::STATUS_CANCELLED => [],
    ];

    const PHASE1_STATUS_LABELS = [
        self::STATUS_PENDING_VERIFICATION => 'Pending Verification',
        self::STATUS_RECEIVED => 'Received',
        self::STATUS_AWAITING_CUSTOMER_CONFIRMATION => 'Awaiting Confirmation',
        self::STATUS_CONFIRMATION_ISSUE => 'Confirmation Issue',
        self::STATUS_CONFIRMED => 'Collection Confirmed',
        self::STATUS_PLANNED => 'Planned',
        self::STATUS_DRIVER_ASSIGNED => 'Driver Assigned',
        self::STATUS_READY_FOR_COLLECTION => 'Collection Confirmed',
        self::STATUS_COLLECTED => 'Driver Arrived at Pickup Location',
        self::STATUS_IN_TRANSIT => 'In Transit',
        self::STATUS_DELIVERED => 'Delivered',
        self::STATUS_COMPLETED => 'Completed',
        self::STATUS_CANCELLED => 'Cancelled',
    ];

    // Where the vehicle is going. Dealer + body_builder are both "delivered"
    // outcomes from an inventory perspective (see InventoryLifecycleService);
    // yard is a transit stop, other is a catch-all.
    // DESTINATION_DEALER == "Delivery" in the user-facing copy — final
    // hand-over to another dealer or to an end customer. These are the
    // only rows that can be archived; every other destination type
    // means the vehicle is still in the dealer's stock somewhere off-
    // site, so it stays on the Stock In Transit view until a follow-up
    // movement takes it to a Delivery destination.
    //
    // DESTINATION_ROUND_TRIP = "Round Trip" — COF check, weighbridge
    // run, pre-delivery inspection, etc. The driver waits at the
    // destination and brings the vehicle straight back to pickup, so
    // once the job is delivered the vehicle is "back at base" and
    // drops out of stock views. Choosing this destination auto-sets
    // is_round_trip = true so the route distance is doubled for
    // reporting / pricing.
    //
    // DESTINATION_YARD remains the storage backing for "Other Storage
    // Facility" in the UI — generic non-final holding location, not
    // dealer, not body builder.
    //
    // DESTINATION_OTHER is kept ONLY for legacy rows; it is no longer
    // offered in the create-order picker. Treated identically to YARD
    // in stock / archive logic.
    const DESTINATION_DEALER = 'dealer';
    const DESTINATION_BODY_BUILDER = 'body_builder';
    const DESTINATION_ROUND_TRIP = 'round_trip';
    const DESTINATION_YARD = 'yard';
    const DESTINATION_OTHER = 'other';

    const DESTINATION_TYPES = [
        self::DESTINATION_DEALER,
        self::DESTINATION_BODY_BUILDER,
        self::DESTINATION_ROUND_TRIP,
        self::DESTINATION_YARD,
        self::DESTINATION_OTHER,
    ];

    // Destination types that keep the vehicle "in stock somewhere
    // off-site" — i.e. not a final delivery. Used by Stock In Transit
    // and by canArchive() (archive blocked for non-final types).
    const NON_FINAL_DESTINATION_TYPES = [
        self::DESTINATION_BODY_BUILDER,
        self::DESTINATION_ROUND_TRIP,
        self::DESTINATION_YARD,
        self::DESTINATION_OTHER,
    ];

    // Who is actually moving this vehicle. Four mutually-exclusive
    // options — see 2026_05_18_000000_add_executors_and_archive_to_transport_jobs.
    // PROSELVER is the default (and the only legal value pre-Phase-1 of
    // the dealer-executor feature). INTERNAL means the booking customer
    // is using their own driver; THIRD_PARTY = courier (light tracking
    // via three columns); SELF_COLLECT = end-customer pickup (no driver
    // at all, just contact / ID for the collector).
    const EXECUTOR_PROSELVER = 'proselver';
    const EXECUTOR_INTERNAL = 'internal';
    const EXECUTOR_THIRD_PARTY = 'third_party';
    const EXECUTOR_SELF_COLLECT = 'self_collect';

    const EXECUTOR_TYPES = [
        self::EXECUTOR_PROSELVER,
        self::EXECUTOR_INTERNAL,
        self::EXECUTOR_THIRD_PARTY,
        self::EXECUTOR_SELF_COLLECT,
    ];

    // Short, human-grade names for badges / form labels. Keep these
    // tight — they have to fit inside pill-shaped badges on the order
    // list and dashboard cards.
    const EXECUTOR_LABELS = [
        self::EXECUTOR_PROSELVER => 'ProSelver',
        self::EXECUTOR_INTERNAL => 'Internal Driver',
        self::EXECUTOR_THIRD_PARTY => '3rd-Party Transporter',
        self::EXECUTOR_SELF_COLLECT => 'Self-Collect',
    ];

    // Executors that need a driver_user_id (a User row in the system).
    // Anything outside this set does NOT — third-party / self-collect
    // carry their own light contact metadata instead.
    const DRIVER_REQUIRED_EXECUTORS = [
        self::EXECUTOR_PROSELVER,
        self::EXECUTOR_INTERNAL,
    ];

    /**
     * Decide where a newly-created booking should land in the workflow.
     *
     * Two axes drive this:
     *
     *   1. Workflow type. Companies on the 'faw'-style strict workflow
     *      have an ops-side PO-verification gate (STATUS_PENDING_VERIFICATION)
     *      that runs BEFORE anything else. That gate is only meaningful for
     *      bookings created by ops on the customer's behalf — when the
     *      customer themselves is the one typing in the booking through the
     *      portal, they ARE the verification step, so callers pass
     *      $bypassPoVerification=true to skip it.
     *
     *   2. Executor type. Historically every booking landed at RECEIVED so
     *      the dealer could click "Confirm Order" to flip it to CONFIRMED
     *      and release it for dispatch. That click is meaningful when
     *      ProSelver is the executor — it's the dealer→ops handshake that
     *      says "yes, please send a driver". For every other executor
     *      (Internal Driver, 3rd-Party Courier, Self-Collect) ProSelver
     *      isn't dispatching anything: the dealer already decided who's
     *      moving the truck when they picked the executor. In those cases
     *      the Confirm-Order step is pure paperwork, so we skip RECEIVED
     *      entirely and land straight on CONFIRMED.
     */
    public static function initialStatusFor(
        string $executor,
        ?string $workflowType = null,
        bool $bypassPoVerification = false,
    ): string {
        if ($workflowType === 'faw' && !$bypassPoVerification) {
            return self::STATUS_PENDING_VERIFICATION;
        }

        return $executor === self::EXECUTOR_PROSELVER
            ? self::STATUS_RECEIVED
            : self::STATUS_CONFIRMED;
    }

    // Statuses where flipping the executor is still safe. Once the
    // vehicle is in the truck (COLLECTED onward) we lock the executor:
    // changing it at that point would orphan the in-flight movement
    // and corrupt the audit trail.
    const EXECUTOR_CHANGEABLE_STATUSES = [
        self::STATUS_PENDING_VERIFICATION,
        self::STATUS_RECEIVED,
        self::STATUS_AWAITING_CUSTOMER_CONFIRMATION,
        self::STATUS_CONFIRMATION_ISSUE,
        self::STATUS_CONFIRMED,
        self::STATUS_PLANNED,
        self::STATUS_DRIVER_ASSIGNED,
        self::STATUS_READY_FOR_COLLECTION,
    ];

    protected $fillable = [
        'uuid',
        'job_number',
        'job_type',
        'status',
        'company_id',
        'executing_company_id',
        'created_by_user_id',
        'driver_user_id',
        'trip_id',
        'transport_route_id',
        'pickup_location_id',
        'pickup_contact_name',
        'pickup_contact_phone',
        'delivery_location_id',
        'destination_type',
        'delivery_contact_name',
        'delivery_contact_phone',
        'vehicle_class_id',
        'brand_id',
        'model_name',
        'vin',
        'inventory_id',
        'registration',
        'original_vin',
        'vehicle_reassigned_at',
        'vehicle_reassigned_by',
        'scheduled_date',
        'scheduled_ready_time',
        'actual_ready_time',
        'po_number',
        'po_amount',
        'po_verified',
        'po_verified_at',
        'po_verified_by',
        'yard_location_id',
        'drivers_required',
        'hours_required',
        'hourly_rate',
        'base_transport_price',
        'delivery_fuel_price',
        'penalty_amount',
        'credit_amount',
        'vat_amount',
        'total_sell_price',
        'cost_fuel',
        'cost_tolls',
        'cost_driver',
        'cost_accommodation',
        'cost_other',
        'total_cost',
        'gross_profit',
        'margin_percent',
        'is_emergency',
        'emergency_reason',
        'is_urgent',
        'urgent_reason',
        'urgent_marked_by_user_id',
        'urgent_marked_at',
        'delay_minutes',
        'delay_reason',
        'delay_reason_type',
        'verified_at',
        'approved_at',
        'assigned_at',
        'started_at',
        'completed_at',
        'invoiced_at',
        // Finance-capture fields filled in by accounts after delivery.
        // None compulsory; surface in the "Customer invoicing" page.
        'invoice_number',
        'invoice_amount',     // incl VAT
        'extras_amount',      // incl VAT
        'fuel_litres',
        'fuel_amount',        // excl VAT
        'invoicing_completed_at',
        'invoicing_completed_by_user_id',
        'invoicing_excluded_at',
        'invoicing_excluded_by_user_id',
        'invoicing_excluded_reason',
        'cancelled_at',
        'cancellation_reason',
        'recalled_at',
        'recalled_by_user_id',
        'recall_reason',
        'distance_km',
        'estimated_duration_minutes',
        'is_round_trip',
        'estimated_toll_cost',
        // Driver-advance / petty-cash estimator fields. Optional in v1 —
        // ops opens the "Petty Cash / Advance" panel on order detail to
        // populate these.  advance_toll_breakdown is a json snapshot of
        // the matched plazas at the moment the advance was set, so the
        // historical record survives plaza-fee changes.
        'advance_toll_breakdown',
        'advance_toll_class_override',
        'advance_tolls',
        'advance_accommodation',
        'advance_taxi',
        'advance_taxi_included',
        'advance_food',
        'advance_food_waived',
        'advance_custom_items',
        'advance_total',
        'advance_increase_reason',
        'advance_assigned_by_user_id',
        'advance_assigned_at',
        'advance_plan_id',
        'advance_approved_at',
        'advance_override_reason',
        'advance_issued_at',
        'advance_issued_by_user_id',
        'advance_issue_reference',
        'advance_removal_pending',
        'advance_removal_requested_at',
        'advance_removal_requested_by_user_id',
        'advance_removal_reason',
        'issued_cancellation_cleared_at',
        'issued_cancellation_cleared_by_user_id',
        'issued_cancellation_cleared_note',
        'customer_confirmed_at',
        'customer_confirmed_by',
        'planned_at',
        'ready_for_collection_at',
        'collected_at',
        'in_transit_at',
        'delivered_at',
        'confirmation_reason',
        'confirmation_note',
        'customer_notes',
        'damage_report_released_at',
        'damage_report_released_by',
        'damage_acknowledged_at',
        'damage_acknowledged_by',
        'executor_type',
        'third_party_courier_name',
        'third_party_waybill',
        'third_party_expected_date',
        'self_collect_name',
        'self_collect_phone',
        'self_collect_id_number',
        'archived_at',
        // BB direct-order owner-approval gate.  Set when a BB places a
        // movement on a vehicle that's on a dealer's stock ledger -- the
        // dealer (owner) has to approve before dispatch can roll.
        'owner_company_id',
        'requires_owner_approval',
        'owner_approval_status',
        'owner_approved_at',
        'owner_approved_by_user_id',
        'owner_decision_notes',
    ];

    public const OWNER_APPROVAL_PENDING  = 'pending';
    public const OWNER_APPROVAL_APPROVED = 'approved';
    public const OWNER_APPROVAL_REJECTED = 'rejected';

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date',
            'scheduled_ready_time' => 'datetime',
            'actual_ready_time' => 'datetime',
            'po_amount' => 'decimal:2',
            'po_verified' => 'boolean',
            'po_verified_at' => 'datetime',
            'requires_owner_approval' => 'boolean',
            'owner_approved_at' => 'datetime',
            'is_urgent' => 'boolean',
            'urgent_marked_at' => 'datetime',
            'recalled_at' => 'datetime',
            'vehicle_reassigned_at' => 'datetime',
            'hourly_rate' => 'decimal:2',
            'base_transport_price' => 'decimal:2',
            'delivery_fuel_price' => 'decimal:2',
            'penalty_amount' => 'decimal:2',
            'credit_amount' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total_sell_price' => 'decimal:2',
            'cost_fuel' => 'decimal:2',
            'cost_tolls' => 'decimal:2',
            'cost_driver' => 'decimal:2',
            'cost_accommodation' => 'decimal:2',
            'cost_other' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'gross_profit' => 'decimal:2',
            'margin_percent' => 'decimal:2',
            'is_emergency' => 'boolean',
            'drivers_required' => 'integer',
            'hours_required' => 'decimal:2',
            'delay_minutes' => 'integer',
            'verified_at' => 'datetime',
            'approved_at' => 'datetime',
            'assigned_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'invoiced_at' => 'datetime',
            'invoice_amount' => 'decimal:2',
            'extras_amount' => 'decimal:2',
            'fuel_litres' => 'decimal:2',
            'fuel_amount' => 'decimal:2',
            'invoicing_completed_at' => 'datetime',
            'invoicing_excluded_at' => 'datetime',
            'damage_report_released_at' => 'datetime',
            'damage_acknowledged_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'distance_km' => 'decimal:2',
            'estimated_duration_minutes' => 'integer',
            'is_round_trip' => 'boolean',
            'estimated_toll_cost' => 'decimal:2',
            'advance_toll_breakdown' => 'array',
            'advance_toll_class_override' => 'integer',
            'advance_tolls' => 'decimal:2',
            'advance_accommodation' => 'decimal:2',
            'advance_taxi' => 'decimal:2',
            'advance_taxi_included' => 'boolean',
            'advance_food' => 'decimal:2',
            'advance_food_waived' => 'boolean',
            'advance_custom_items' => 'array',
            'advance_total' => 'decimal:2',
            'advance_assigned_at' => 'datetime',
            'advance_approved_at' => 'datetime',
            'advance_issued_at' => 'datetime',
            'advance_removal_pending' => 'boolean',
            'advance_removal_requested_at' => 'datetime',
            'issued_cancellation_cleared_at' => 'datetime',
            'customer_confirmed_at' => 'datetime',
            'planned_at' => 'datetime',
            'ready_for_collection_at' => 'datetime',
            'collected_at' => 'datetime',
            'in_transit_at' => 'datetime',
            'delivered_at' => 'datetime',
            'third_party_expected_date' => 'date',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Job $job) {
            if (empty($job->uuid)) {
                $job->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * VIN, original VIN and registration are stored AND read as uppercase.
     * VINs have no lowercase form (ISO 3779) and number plates are universally
     * written in caps on vehicle paperwork, so this keeps search, display and
     * printing consistent regardless of how the operator typed them in. Uses
     * accessors (not a saving hook) so existing records that predate this
     * change also render uppercase on reads.
     */
    protected function vin(): Attribute
    {
        return Attribute::make(
            get: fn($v) => $v ? strtoupper($v) : $v,
            set: fn($v) => $v ? strtoupper(trim($v)) : $v,
        );
    }

    protected function originalVin(): Attribute
    {
        return Attribute::make(
            get: fn($v) => $v ? strtoupper($v) : $v,
            set: fn($v) => $v ? strtoupper(trim($v)) : $v,
        );
    }

    protected function registration(): Attribute
    {
        return Attribute::make(
            get: fn($v) => $v ? strtoupper($v) : $v,
            set: fn($v) => $v ? strtoupper(trim($v)) : $v,
        );
    }

    public function canTransitionTo(string $newStatus): bool
    {
        $allowed = self::ALLOWED_TRANSITIONS[$this->status]
            ?? self::PHASE1_TRANSITIONS[$this->status]
            ?? [];

        return in_array($newStatus, $allowed);
    }

    public function transitionTo(string $newStatus): bool
    {
        if (!$this->canTransitionTo($newStatus)) {
            return false;
        }

        $this->status = $newStatus;

        $timestampMap = [
            // Legacy
            self::STATUS_VERIFIED => 'verified_at',
            self::STATUS_APPROVED => 'approved_at',
            self::STATUS_ASSIGNED => 'assigned_at',
            self::STATUS_IN_PROGRESS => 'started_at',
            self::STATUS_INVOICED => 'invoiced_at',
            // Phase 1
            self::STATUS_CONFIRMED => 'customer_confirmed_at',
            self::STATUS_PLANNED => 'planned_at',
            self::STATUS_DRIVER_ASSIGNED => 'assigned_at',
            self::STATUS_READY_FOR_COLLECTION => 'ready_for_collection_at',
            self::STATUS_COLLECTED => 'collected_at',
            self::STATUS_IN_TRANSIT => 'in_transit_at',
            self::STATUS_DELIVERED => 'delivered_at',
            self::STATUS_COMPLETED => 'completed_at',
            self::STATUS_CANCELLED => 'cancelled_at',
        ];

        if (isset($timestampMap[$newStatus])) {
            $this->{$timestampMap[$newStatus]} = now();
        }

        return $this->save();
    }

    public function isPhase1Status(): bool
    {
        return in_array($this->status, self::PHASE1_STATUSES);
    }

    public function phase1StatusLabel(): string
    {
        return self::PHASE1_STATUS_LABELS[$this->status] ?? ucfirst(str_replace('_', ' ', $this->status));
    }

    public function reportConfirmationIssue(string $reason, ?string $note = null): bool
    {
        if (!$this->canTransitionTo(self::STATUS_CONFIRMATION_ISSUE)) {
            return false;
        }

        $this->confirmation_reason = $reason;
        $this->confirmation_note = $note;
        $this->status = self::STATUS_CONFIRMATION_ISSUE;

        return $this->save();
    }

    public function availablePhase1Transitions(): array
    {
        $transitions = self::PHASE1_TRANSITIONS[$this->status] ?? [];

        if ($this->status === self::STATUS_RECEIVED && !$this->company?->requiresExternalConfirmation()) {
            $transitions = array_values(array_diff($transitions, [self::STATUS_AWAITING_CUSTOMER_CONFIRMATION]));
        }

        return $transitions;
    }

    public function isTransport(): bool
    {
        return $this->job_type === self::TYPE_TRANSPORT;
    }

    public function isYardWork(): bool
    {
        return $this->job_type === self::TYPE_YARD_WORK;
    }

    public function calculateFinancials(): void
    {
        if ($this->isYardWork()) {
            $this->base_transport_price = ($this->drivers_required ?? 0) * ($this->hours_required ?? 0) * ($this->hourly_rate ?? 0);
        }

        $sellBeforeVat = ($this->base_transport_price ?? 0)
            + ($this->delivery_fuel_price ?? 0)
            + ($this->penalty_amount ?? 0)
            - ($this->credit_amount ?? 0);

        $this->vat_amount = round($sellBeforeVat * 0.15, 2);
        $this->total_sell_price = round($sellBeforeVat + $this->vat_amount, 2);

        $this->total_cost = ($this->cost_fuel ?? 0)
            + ($this->cost_tolls ?? 0)
            + ($this->cost_driver ?? 0)
            + ($this->cost_accommodation ?? 0)
            + ($this->cost_other ?? 0);

        $this->gross_profit = round($sellBeforeVat - $this->total_cost, 2);
        $this->margin_percent = $sellBeforeVat > 0
            ? round(($this->gross_profit / $sellBeforeVat) * 100, 2)
            : 0;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /* ----------------------------------------------------------------
     | Collection-SLA helpers
     |
     | The booking company (the OEM in most flows) can carry a
     | `collection_sla_days` value -- the contractual number of
     | calendar days from when the order lands until the vehicle
     | must be collected. Today's contracts:
     |   FAW   = 7 days
     |   Isuzu = 3 days
     |
     | These accessors stay null-safe so the UI can short-circuit
     | rendering of the "Day X / Y" pill and the deadline pill when
     | the company has no SLA configured (no badge, no overdue
     | trigger, no behavioural change vs pre-SLA jobs).
     |---------------------------------------------------------------*/

    /**
     * Calendar-day deadline by which collection must happen.
     * Null when the booking company has no SLA configured or the
     * job hasn't been created yet.
     */
    public function collectionDeadline(): ?\Carbon\Carbon
    {
        $sla = $this->company?->collection_sla_days;
        if (! $sla || ! $this->created_at) {
            return null;
        }
        return $this->created_at->copy()->addDays($sla)->endOfDay();
    }

    /**
     * 1-indexed day number into the collection window.
     *   Day 1 = the day the job was created.
     *   Day SLA = the deadline day.
     *   > SLA   = past deadline.
     * Null when no SLA / no created_at.
     */
    public function collectionWindowDay(): ?int
    {
        $sla = $this->company?->collection_sla_days;
        if (! $sla || ! $this->created_at) {
            return null;
        }
        return max(1, $this->created_at->copy()->startOfDay()->diffInDays(now()->startOfDay()) + 1);
    }

    /**
     * True when we're past the contractual collection deadline AND
     * the vehicle hasn't been picked up yet (status still in a
     * pre-collection phase).
     */
    public function pastCollectionDeadline(): bool
    {
        $deadline = $this->collectionDeadline();
        if (! $deadline) {
            return false;
        }
        // If the vehicle has already moved past the collection
        // phase (or been cancelled / rejected), the SLA is no
        // longer meaningful -- no overdue flag even if calendar-
        // wise we're past the deadline.
        $sttsPastCollection = [
            self::STATUS_COLLECTED,
            self::STATUS_IN_TRANSIT,
            self::STATUS_IN_PROGRESS,
            self::STATUS_DELIVERED,
            self::STATUS_COMPLETED,
            self::STATUS_CANCELLED,
            self::STATUS_REJECTED,
        ];
        if (in_array($this->status, $sttsPastCollection, true)) {
            return false;
        }
        return $deadline->lt(now());
    }

    /* ----------------------------------------------------------------
     | Urgent-collection flag
     |
     | Either an ops user (governed by JobPolicy::markUrgent) or the
     | booking customer can mark a job urgent with an optional reason
     | sentence. The flag is shown as a prominent URGENT badge on the
     | wallboard, sorts the job to the top of the Waiting / New
     | Orders lanes, and rolls into a headline counter.
     |
     | markUrgent() / clearUrgent() handle their own audit log
     | entries so callers don't have to remember to log -- centralises
     | the paper trail.
     |---------------------------------------------------------------*/

    public function urgentMarkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'urgent_marked_by_user_id');
    }

    /**
     * Mark the job urgent. Idempotent on the boolean flag but
     * always refreshes who/when so the latest marker is on record.
     * Reason is optional; an empty string is normalised to null.
     */
    public function markUrgent(User $by, ?string $reason = null): void
    {
        $reason = $reason !== null ? trim($reason) : null;
        if ($reason === '') {
            $reason = null;
        }

        $wasUrgent = (bool) $this->is_urgent;
        $before    = $wasUrgent
            ? ['is_urgent' => true, 'urgent_reason' => $this->urgent_reason]
            : ['is_urgent' => false];

        $this->forceFill([
            'is_urgent'                 => true,
            'urgent_reason'             => $reason,
            'urgent_marked_by_user_id'  => $by->id,
            'urgent_marked_at'          => now(),
        ])->save();

        \App\Services\AuditService::log(
            $wasUrgent ? 'urgent_updated' : 'urgent_marked',
            'job',
            $this->id,
            $before,
            ['is_urgent' => true, 'urgent_reason' => $reason, 'by' => $by->id],
            $reason
        );
    }

    public function clearUrgent(User $by): void
    {
        if (! $this->is_urgent) {
            return;
        }
        $previousReason = $this->urgent_reason;
        $this->forceFill([
            'is_urgent'                 => false,
            'urgent_reason'             => null,
            'urgent_marked_by_user_id'  => null,
            'urgent_marked_at'          => null,
        ])->save();

        \App\Services\AuditService::log(
            'urgent_cleared',
            'job',
            $this->id,
            ['is_urgent' => true, 'urgent_reason' => $previousReason],
            ['is_urgent' => false, 'by' => $by->id],
            $previousReason
        );
    }

    /* ----------------------------------------------------------------
     | Recall to planning
     |
     | Ops override that pulls a job back to STATUS_CONFIRMED from any
     | pre-delivery state -- including COLLECTED / IN_TRANSIT, where
     | the truck has physically left the depot and is being recalled.
     |
     | This deliberately bypasses transitionTo() because COLLECTED and
     | IN_TRANSIT have no formal backwards step in the workflow map by
     | design (regular transitions are forward-only); recall is an
     | admin action, audit-logged separately.  Clearing the driver and
     | scheduled date is what makes the job re-enter the planning queue
     | from scratch as the user requested ("full fresh start").
     |
     | URGENT, customer confirmation, PO data, locations and documents
     | are all preserved -- only the dispatch arrangements (driver +
     | schedule + collection timestamps) are reset.
     |---------------------------------------------------------------*/

    public function recalledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recalled_by_user_id');
    }

    /**
     * Statuses from which a recall makes operational sense.  Pre-
     * delivery only -- DELIVERED / COMPLETED / CANCELLED are
     * terminal physical states and never recallable.  Pre-planning
     * statuses (PENDING_VERIFICATION etc.) aren't included because
     * there's nothing dispatch-wise to undo.
     */
    public function isRecallable(): bool
    {
        return in_array($this->status, [
            self::STATUS_PLANNED,
            self::STATUS_DRIVER_ASSIGNED,
            self::STATUS_READY_FOR_COLLECTION,
            self::STATUS_COLLECTED,
            self::STATUS_IN_TRANSIT,
        ], true);
    }

    public function recallToPlanning(User $by, ?string $reason = null): void
    {
        if (! $this->isRecallable()) {
            throw new \RuntimeException(sprintf(
                'Job %s is not recallable from status %s.',
                $this->job_number ?? $this->id,
                $this->status
            ));
        }

        $reason = $reason !== null ? trim($reason) : null;
        if ($reason === '') {
            $reason = null;
        }

        $before = [
            'status'                 => $this->status,
            'driver_user_id'         => $this->driver_user_id,
            'scheduled_date'         => $this->scheduled_date?->toIso8601String(),
            'planned_at'             => $this->planned_at?->toIso8601String(),
            'collected_at'           => $this->collected_at?->toIso8601String(),
            'in_transit_at'          => $this->in_transit_at?->toIso8601String(),
        ];

        \Illuminate\Support\Facades\DB::transaction(function () use ($by, $reason) {
            $this->forceFill([
                'status'                   => self::STATUS_CONFIRMED,
                'driver_user_id'           => null,
                'scheduled_date'           => null,
                'scheduled_ready_time'     => null,
                'actual_ready_time'        => null,
                'planned_at'               => null,
                'ready_for_collection_at'  => null,
                'collected_at'             => null,
                'in_transit_at'            => null,
                'recalled_at'              => now(),
                'recalled_by_user_id'      => $by->id,
                'recall_reason'            => $reason,
            ])->save();

            $this->events()->create([
                'event_type' => 'recalled_to_planning',
                'event_at'   => now(),
                'user_id'    => $by->id,
                'notes'      => $reason,
            ]);
        });

        \App\Services\AuditService::log(
            'job_recalled',
            'job',
            $this->id,
            $before,
            [
                'status'                => self::STATUS_CONFIRMED,
                'recalled_by_user_id'   => $by->id,
                'recall_reason'         => $reason,
            ],
            $reason
        );
    }

    /**
     * Company actually moving the vehicle. NULL means the platform-owner
     * company (us) is executing internally — the default for every existing
     * and newly-booked job until 3PL transporters are onboarded.
     */
    public function executingCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'executing_company_id');
    }

    /**
     * Optional link to the per-chassis inventory row this job is moving.
     * Populated only when config('features.inventory_link') is on.
     */
    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class, 'inventory_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * The dealer who OWNS the vehicle this job is moving.  Distinct
     * from $this->company_id (the paying customer) -- only populated
     * when a non-owner tenant (e.g. a body builder) places the order
     * on a vehicle that's already on a dealer's stock ledger.
     */
    public function ownerCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'owner_company_id');
    }

    public function ownerApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_approved_by_user_id');
    }

    public function isPendingOwnerApproval(): bool
    {
        return $this->requires_owner_approval
            && $this->owner_approval_status === self::OWNER_APPROVAL_PENDING;
    }

    public function isOwnerApproved(): bool
    {
        return ! $this->requires_owner_approval
            || $this->owner_approval_status === self::OWNER_APPROVAL_APPROVED;
    }

    public function damageAcknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'damage_acknowledged_by');
    }

    public function damageReportReleasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'damage_report_released_by');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_user_id');
    }

    public function advanceAssignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'advance_assigned_by_user_id');
    }

    public function advancePlan(): BelongsTo
    {
        return $this->belongsTo(PettyCashPlan::class, 'advance_plan_id');
    }

    public function advanceIssuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'advance_issued_by_user_id');
    }

    public function advanceRemovalRequestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'advance_removal_requested_by_user_id');
    }

    public function issuedCancellationClearedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_cancellation_cleared_by_user_id');
    }

    /**
     * Cancelled trips where the advance had already been issued
     * (i.e. cash is out of the till) and Accounts/Owner has not yet
     * signed off an explanation. Drives the "Open queries" dashboard
     * widget and the inline banner on the order page.
     */
    public function scopeIssuedCancellationQueryOpen($q)
    {
        return $q->where('status', self::STATUS_CANCELLED)
            ->whereNotNull('advance_issued_at')
            ->whereNull('issued_cancellation_cleared_at');
    }

    public function hasOpenIssuedCancellationQuery(): bool
    {
        return $this->status === self::STATUS_CANCELLED
            && !is_null($this->advance_issued_at)
            && is_null($this->issued_cancellation_cleared_at);
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function tripStops(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(TripStop::class, 'transport_job_id')
            ->orderBy('sequence');
    }

    public function transportRoute(): BelongsTo
    {
        return $this->belongsTo(TransportRoute::class);
    }

    public function pickupLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'pickup_location_id');
    }

    public function deliveryLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'delivery_location_id');
    }

    public function yardLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'yard_location_id');
    }

    public function vehicleClass(): BelongsTo
    {
        return $this->belongsTo(VehicleClass::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(JobEvent::class, 'job_id')->orderBy('event_at');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(JobDocument::class, 'job_id');
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'job_id')->orderBy('created_at');
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class, 'job_id');
    }

    public function cancellation(): HasOne
    {
        return $this->hasOne(Cancellation::class, 'job_id');
    }

    public function changeRequests(): HasMany
    {
        return $this->hasMany(\App\Models\BookingChangeRequest::class, 'job_id');
    }

    /**
     * Opt-in visibility scope mirroring JobPolicy::view. Platform-owner /
     * internal users see everything; everyone else sees jobs where their
     * company is either the booking customer OR the executing transporter.
     *
     * This is NOT a global scope; apply it explicitly where it's wanted.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isInternal() || $user->belongsToPlatformOwner()) {
            return $query;
        }

        $companyIds = $user->operatingCompanyIds();
        if (empty($companyIds)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $q) use ($companyIds) {
            $q->whereIn('company_id', $companyIds)
                ->orWhereIn('executing_company_id', $companyIds)
                // Vehicle-owner visibility: a dealer must see jobs
                // placed against their stock by anyone else (notably
                // a body builder placing a direct order).  Without
                // this clause they wouldn't even know the vehicle was
                // moving until it disappeared from the BB's yard.
                ->orWhereIn('owner_company_id', $companyIds);
        });
    }

    /* ----------------------------------------------------------------
     | Executor (who is actually moving this vehicle)
     |-----------------------------------------------------------------*/

    /**
     * Display label for the current executor — used by badges and
     * status panels. Falls back to a humanised version of the raw
     * value if a new executor is added before EXECUTOR_LABELS is
     * extended (data should never be invisible to the operator).
     */
    public function executorLabel(): string
    {
        $type = $this->executor_type ?: self::EXECUTOR_PROSELVER;
        return self::EXECUTOR_LABELS[$type] ?? ucfirst(str_replace('_', ' ', $type));
    }

    /**
     * Does the current executor type need a driver_user_id? True for
     * proselver + internal (real User in the system); false for the
     * "external" executors where there is no user account to assign.
     */
    public function requiresDriverUser(): bool
    {
        $type = $this->executor_type ?: self::EXECUTOR_PROSELVER;
        return in_array($type, self::DRIVER_REQUIRED_EXECUTORS, true);
    }

    /**
     * True only while the vehicle is still on the dealer's side of the
     * fence. Once it's COLLECTED we lock the executor — swapping it
     * post-collection would leave an in-flight movement orphaned.
     */
    public function canChangeExecutor(): bool
    {
        return in_array($this->status, self::EXECUTOR_CHANGEABLE_STATUSES, true);
    }

    /**
     * Flip the executor to a different type. Clears any driver / meta
     * that doesn't apply to the new type and reverts the status to
     * PLANNED so the planner re-picks up the job. Audit-logged via
     * Auditable::auditCustom so we can trace every flip in the log.
     *
     * @param  array{
     *     third_party_courier_name?: ?string,
     *     third_party_waybill?: ?string,
     *     third_party_expected_date?: ?string,
     *     self_collect_name?: ?string,
     *     self_collect_phone?: ?string,
     *     self_collect_id_number?: ?string,
     *     driver_user_id?: ?int,
     * } $meta  Executor-specific extras
     */
    public function changeExecutor(string $newType, array $meta = []): bool
    {
        if (! in_array($newType, self::EXECUTOR_TYPES, true)) {
            return false;
        }
        if (! $this->canChangeExecutor()) {
            return false;
        }

        $before = [
            'executor_type' => $this->executor_type,
            'driver_user_id' => $this->driver_user_id,
            'third_party_courier_name' => $this->third_party_courier_name,
            'third_party_waybill' => $this->third_party_waybill,
            'third_party_expected_date' => $this->third_party_expected_date?->toDateString(),
            'self_collect_name' => $this->self_collect_name,
            'self_collect_phone' => $this->self_collect_phone,
            'self_collect_id_number' => $this->self_collect_id_number,
            'status' => $this->status,
        ];

        // Always start from a clean slate — drop every executor-specific
        // field, then re-populate only the ones that apply to $newType.
        $this->executor_type = $newType;
        $this->driver_user_id = null;
        $this->third_party_courier_name = null;
        $this->third_party_waybill = null;
        $this->third_party_expected_date = null;
        $this->self_collect_name = null;
        $this->self_collect_phone = null;
        $this->self_collect_id_number = null;

        if ($newType === self::EXECUTOR_THIRD_PARTY) {
            $this->third_party_courier_name = $meta['third_party_courier_name'] ?? null;
            $this->third_party_waybill = $meta['third_party_waybill'] ?? null;
            $this->third_party_expected_date = $meta['third_party_expected_date'] ?? null;
        } elseif ($newType === self::EXECUTOR_SELF_COLLECT) {
            $this->self_collect_name = $meta['self_collect_name'] ?? null;
            $this->self_collect_phone = $meta['self_collect_phone'] ?? null;
            $this->self_collect_id_number = $meta['self_collect_id_number'] ?? null;
        } elseif (in_array($newType, self::DRIVER_REQUIRED_EXECUTORS, true)) {
            // Driver may be supplied immediately (e.g. dealer flips to
            // internal AND picks the driver in one go); otherwise the
            // planner assigns later via the existing assignDriver path.
            $this->driver_user_id = $meta['driver_user_id'] ?? null;
        }

        // Reset to PLANNED so the planner picks the job back up — the
        // previous driver_assigned timestamp stays in assigned_at for
        // audit, but the status moves so it shows up in the right bucket.
        // Skip the reset for the pre-planning statuses so we don't
        // artificially leap-frog the booking-confirmation gate.
        $preplanStatuses = [
            self::STATUS_PENDING_VERIFICATION,
            self::STATUS_RECEIVED,
            self::STATUS_AWAITING_CUSTOMER_CONFIRMATION,
            self::STATUS_CONFIRMATION_ISSUE,
            self::STATUS_CONFIRMED,
            self::STATUS_PLANNED,
        ];
        if (! in_array($this->status, $preplanStatuses, true)) {
            $this->status = self::STATUS_PLANNED;
            $this->planned_at = $this->planned_at ?? now();
        }

        $saved = $this->save();

        if ($saved) {
            $this->auditCustom('executor_changed', $before, [
                'executor_type' => $this->executor_type,
                'driver_user_id' => $this->driver_user_id,
                'status' => $this->status,
            ]);
        }

        return $saved;
    }

    /* ----------------------------------------------------------------
     | Archival (final-delivery flag)
     |-----------------------------------------------------------------*/

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Eligible-to-archive == job has reached delivered/completed AND
     * the destination type is a final Delivery (dealer / customer). A
     * non-final destination (body builder, round trip, other storage)
     * means the vehicle is still in the dealer's stock somewhere, so
     * the row stays on the Stock In Transit view and the Archive
     * action is hidden in the customer UI. Ops can still override
     * from the admin surface if a row is genuinely stuck.
     */
    public function canArchive(bool $opsOverride = false): bool
    {
        if ($this->isArchived()) {
            return false;
        }
        if (! in_array($this->status, [self::STATUS_DELIVERED, self::STATUS_COMPLETED], true)) {
            return false;
        }
        if (in_array($this->destination_type, self::NON_FINAL_DESTINATION_TYPES, true) && ! $opsOverride) {
            return false;
        }
        return true;
    }

    public function archive(bool $opsOverride = false): bool
    {
        if (! $this->canArchive($opsOverride)) {
            return false;
        }

        $this->archived_at = now();
        $saved = $this->save();

        if ($saved) {
            $this->auditCustom('archived', null, ['archived_at' => $this->archived_at->toIso8601String()]);
        }

        return $saved;
    }

    /**
     * Body-builder-side "vehicle arrived at our workshop" action.
     *
     * Promotes the job from in-transit / out-for-delivery to delivered
     * with a BB-tagged audit entry so the dealer's order timeline
     * shows "Received at FAW Body Builders by Jane on 18 May 14:32"
     * rather than the generic STATUS change.  No-op if the job is
     * already delivered/completed — keeps the action idempotent
     * against double-click on a flaky mobile connection.
     */
    public function confirmReceiptAtBodyBuilder(User $bbUser): bool
    {
        if (in_array($this->status, [self::STATUS_DELIVERED, self::STATUS_COMPLETED], true)) {
            return false;
        }

        $before = ['status' => $this->status];
        $this->status = self::STATUS_DELIVERED;
        $saved = $this->save();

        if ($saved) {
            $this->auditCustom('bb_receipt_confirmed', $before, [
                'status'        => $this->status,
                'confirmed_by'  => $bbUser->id,
                'confirmed_at'  => now()->toIso8601String(),
                'bb_company_id' => $bbUser->company()?->id,
            ]);
        }

        return $saved;
    }

    public function unarchive(): bool
    {
        if (! $this->isArchived()) {
            return false;
        }

        $before = ['archived_at' => $this->archived_at?->toIso8601String()];
        $this->archived_at = null;
        $saved = $this->save();

        if ($saved) {
            $this->auditCustom('unarchived', $before, ['archived_at' => null]);
        }

        return $saved;
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }
}
