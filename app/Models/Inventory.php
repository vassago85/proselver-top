<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Inventory extends Model
{
    use Auditable, SoftDeletes;

    protected $table = 'inventory';

    public const STATUS_PRODUCED = 'produced';
    public const STATUS_AT_PLANT = 'at_plant';
    public const STATUS_AT_YARD = 'at_yard';
    public const STATUS_AT_STORAGE = 'at_storage';
    public const STATUS_IN_TRANSIT = 'in_transit';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [
        self::STATUS_PRODUCED,
        self::STATUS_AT_PLANT,
        self::STATUS_AT_YARD,
        self::STATUS_AT_STORAGE,
        self::STATUS_IN_TRANSIT,
        self::STATUS_DELIVERED,
        self::STATUS_ARCHIVED,
    ];

    public const ACTIVE_STATUSES = [
        self::STATUS_PRODUCED,
        self::STATUS_AT_PLANT,
        self::STATUS_AT_YARD,
        self::STATUS_AT_STORAGE,
        self::STATUS_IN_TRANSIT,
    ];

    protected $fillable = [
        'uuid',
        'owner_company_id',
        'chassis_number',
        'vin',
        'current_location_id',
        'brand_id',
        'model_name',
        'status',
        'delivered_at',
        'delivered_via_job_id',
    ];

    protected function casts(): array
    {
        return [
            'delivered_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Inventory $inventory) {
            if (empty($inventory->uuid)) {
                $inventory->uuid = (string) Str::uuid();
            }
        });
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'owner_company_id');
    }

    public function currentLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'current_location_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(Job::class, 'inventory_id');
    }

    public function deliveredViaJob(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'delivered_via_job_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }

    public function scopeDelivered(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_DELIVERED);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isInternal() || $user->belongsToPlatformOwner()) {
            return $query;
        }

        return $query->whereIn('owner_company_id', $user->operatingCompanyIds());
    }

    public function isActiveStock(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }
}
