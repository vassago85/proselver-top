<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Body-builder → dealer movement request.
 *
 * Workflow:
 *
 *   pending   → created by a BB user when they need a follow-up move
 *               (vehicle has to go to the crane shop / get collected)
 *   approved  → dealer planner OK'd it; service layer creates a real
 *               transport_jobs row and stores its FK on `created_job_id`
 *   rejected  → dealer planner declined; `decision_notes` carries the
 *               reason back to the BB
 *   cancelled → BB withdrew the request before the dealer responded
 *
 * The `source_job_id` is the job that delivered the vehicle to the BB
 * originally; we copy VIN / brand / vehicle class forward from it so
 * the BB doesn't have to re-type fields they don't really know.
 */
class MovementRequest extends Model
{
    use HasFactory;

    public const TYPE_NEXT_MOVE = 'next_move';
    public const TYPE_COLLECTION = 'collection';

    public const TYPES = [
        self::TYPE_NEXT_MOVE,
        self::TYPE_COLLECTION,
    ];

    public const TYPE_LABELS = [
        self::TYPE_NEXT_MOVE  => 'Next fitment',
        self::TYPE_COLLECTION => 'Collection — vehicle ready',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

    public const STATUS_LABELS = [
        self::STATUS_PENDING   => 'Pending',
        self::STATUS_APPROVED  => 'Approved',
        self::STATUS_REJECTED  => 'Rejected',
        self::STATUS_CANCELLED => 'Cancelled',
    ];

    protected $fillable = [
        'uuid',
        'requesting_company_id',
        'target_company_id',
        'requesting_user_id',
        'source_job_id',
        'request_type',
        'pickup_location_id',
        'delivery_location_id',
        'vehicle_class_id',
        'brand_id',
        'vin',
        'registration',
        'model_name',
        'requested_date',
        'notes',
        'status',
        'decided_by_user_id',
        'decided_at',
        'decision_notes',
        'created_job_id',
    ];

    protected $casts = [
        'requested_date' => 'date',
        'decided_at'     => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (MovementRequest $req) {
            if (empty($req->uuid)) {
                $req->uuid = (string) Str::uuid();
            }
            if (empty($req->status)) {
                $req->status = self::STATUS_PENDING;
            }
        });
    }

    // -----------------------------------------------------------------
    // Relations
    // -----------------------------------------------------------------

    public function requestingCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'requesting_company_id');
    }

    public function targetCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'target_company_id');
    }

    public function requestingUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requesting_user_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function sourceJob(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'source_job_id');
    }

    public function createdJob(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'created_job_id');
    }

    public function pickupLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'pickup_location_id');
    }

    public function deliveryLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'delivery_location_id');
    }

    public function vehicleClass(): BelongsTo
    {
        return $this->belongsTo(VehicleClass::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    public function isPending(): bool   { return $this->status === self::STATUS_PENDING; }
    public function isApproved(): bool  { return $this->status === self::STATUS_APPROVED; }
    public function isRejected(): bool  { return $this->status === self::STATUS_REJECTED; }
    public function isCancelled(): bool { return $this->status === self::STATUS_CANCELLED; }

    public function isNextMove(): bool   { return $this->request_type === self::TYPE_NEXT_MOVE; }
    public function isCollection(): bool { return $this->request_type === self::TYPE_COLLECTION; }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->request_type] ?? $this->request_type;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }
}
