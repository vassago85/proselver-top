<?php

namespace App\Models;

use App\Traits\Auditable;
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
        self::STATUS_PENDING_VERIFICATION => [self::STATUS_VERIFIED, self::STATUS_REJECTED, self::STATUS_CANCELLED],
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

    protected $fillable = [
        'uuid',
        'job_number',
        'job_type',
        'status',
        'company_id',
        'created_by_user_id',
        'driver_user_id',
        'transport_route_id',
        'pickup_location_id',
        'pickup_contact_name',
        'pickup_contact_phone',
        'delivery_location_id',
        'delivery_contact_name',
        'delivery_contact_phone',
        'vehicle_class_id',
        'brand_id',
        'model_name',
        'vin',
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
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Job $job) {
            if (empty($job->uuid)) {
                $job->uuid = (string) Str::uuid();
            }
        });

        static::saving(function (Job $job) {
            if ($job->vin) {
                $job->vin = strtoupper($job->vin);
            }
            if ($job->original_vin) {
                $job->original_vin = strtoupper($job->original_vin);
            }
        });
    }

    public function canTransitionTo(string $newStatus): bool
    {
        $allowed = self::ALLOWED_TRANSITIONS[$this->status] ?? [];

        return in_array($newStatus, $allowed);
    }

    public function transitionTo(string $newStatus): bool
    {
        if (!$this->canTransitionTo($newStatus)) {
            return false;
        }

        $this->status = $newStatus;

        $timestampMap = [
            self::STATUS_VERIFIED => 'verified_at',
            self::STATUS_APPROVED => 'approved_at',
            self::STATUS_ASSIGNED => 'assigned_at',
            self::STATUS_IN_PROGRESS => 'started_at',
            self::STATUS_COMPLETED => 'completed_at',
            self::STATUS_INVOICED => 'invoiced_at',
            self::STATUS_CANCELLED => 'cancelled_at',
        ];

        if (isset($timestampMap[$newStatus])) {
            $this->{$timestampMap[$newStatus]} = now();
        }

        return $this->save();
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
}
