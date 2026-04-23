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
        self::STATUS_CONFIRMATION_ISSUE => [self::STATUS_AWAITING_CUSTOMER_CONFIRMATION, self::STATUS_CANCELLED],
        self::STATUS_CONFIRMED => [self::STATUS_PLANNED, self::STATUS_CANCELLED],
        self::STATUS_PLANNED => [self::STATUS_DRIVER_ASSIGNED, self::STATUS_CANCELLED],
        // Once a driver is assigned the next event is the driver arriving at pickup
        // (which flips straight to COLLECTED). STATUS_READY_FOR_COLLECTION is kept
        // only as a legacy terminal-ish state for in-flight orders created before
        // the workflow was simplified — those rows still need to be able to move to
        // COLLECTED or CANCELLED, hence the entry below.
        self::STATUS_DRIVER_ASSIGNED => [self::STATUS_COLLECTED, self::STATUS_CANCELLED],
        self::STATUS_READY_FOR_COLLECTION => [self::STATUS_COLLECTED, self::STATUS_CANCELLED],
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
        self::STATUS_COLLECTED => 'Collected',
        self::STATUS_IN_TRANSIT => 'In Transit',
        self::STATUS_DELIVERED => 'Delivered',
        self::STATUS_COMPLETED => 'Completed',
        self::STATUS_CANCELLED => 'Cancelled',
    ];

    // Where the vehicle is going. Dealer + body_builder are both "delivered"
    // outcomes from an inventory perspective (see InventoryLifecycleService);
    // yard is a transit stop, other is a catch-all.
    const DESTINATION_DEALER = 'dealer';
    const DESTINATION_BODY_BUILDER = 'body_builder';
    const DESTINATION_YARD = 'yard';
    const DESTINATION_OTHER = 'other';

    const DESTINATION_TYPES = [
        self::DESTINATION_DEALER,
        self::DESTINATION_BODY_BUILDER,
        self::DESTINATION_YARD,
        self::DESTINATION_OTHER,
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
        'delay_minutes',
        'delay_reason',
        'delay_reason_type',
        'verified_at',
        'approved_at',
        'assigned_at',
        'started_at',
        'completed_at',
        'invoiced_at',
        'cancelled_at',
        'cancellation_reason',
        'distance_km',
        'estimated_duration_minutes',
        'is_round_trip',
        'estimated_toll_cost',
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
    ];

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date',
            'scheduled_ready_time' => 'datetime',
            'actual_ready_time' => 'datetime',
            'po_amount' => 'decimal:2',
            'po_verified' => 'boolean',
            'po_verified_at' => 'datetime',
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
            'cancelled_at' => 'datetime',
            'distance_km' => 'decimal:2',
            'estimated_duration_minutes' => 'integer',
            'is_round_trip' => 'boolean',
            'estimated_toll_cost' => 'decimal:2',
            'customer_confirmed_at' => 'datetime',
            'planned_at' => 'datetime',
            'ready_for_collection_at' => 'datetime',
            'collected_at' => 'datetime',
            'in_transit_at' => 'datetime',
            'delivered_at' => 'datetime',
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

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_user_id');
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
                ->orWhereIn('executing_company_id', $companyIds);
        });
    }
}
