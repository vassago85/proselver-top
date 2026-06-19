<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Dealer stock unit -- a single vehicle on a dealer's books.
 *
 * Populated by:
 *   - the spreadsheet import (DealerStockImporter)
 *   - the DealerStockMovementLinker observer (one-way watch of
 *     transport_jobs events; never modifies the job)
 *   - dealer staff actions in customer.stock.* Volt pages
 *
 * The dashboard cards read directly from this model via the
 * scopeAt* / scopeOn* / scopeRecentlyDelivered / scopeScheduledForMovement
 * scopes.  Every read is filtered through scopeVisibleTo() so a
 * franchise CEO sees stock across every dealership in their group.
 */
class DealerStock extends Model
{
    use HasFactory;

    protected $table = 'dealer_stock';

    /**
     * Physical bucket the vehicle is currently in.  Drives the
     * dashboard cards and the Where column on the stock list.
     */
    public const LOCATION_PREMISES     = 'premises';
    public const LOCATION_BODY_BUILDER = 'body_builder';
    public const LOCATION_STORAGE      = 'storage';
    public const LOCATION_IN_TRANSIT   = 'in_transit';
    public const LOCATION_ON_DEMO      = 'on_demo';
    public const LOCATION_DELIVERED    = 'delivered';

    public const LOCATION_TYPES = [
        self::LOCATION_PREMISES,
        self::LOCATION_BODY_BUILDER,
        self::LOCATION_STORAGE,
        self::LOCATION_IN_TRANSIT,
        self::LOCATION_ON_DEMO,
        self::LOCATION_DELIVERED,
    ];

    /**
     * Commercial status -- independent of physical location.  A
     * vehicle can sit on premises with status=available, or sit on
     * premises with status=sold (sale paperwork done, customer
     * hasn't collected yet).
     */
    public const STATUS_AVAILABLE = 'available';
    public const STATUS_RESERVED  = 'reserved';
    public const STATUS_SOLD      = 'sold';
    public const STATUS_DEMO      = 'demo';
    public const STATUS_ARCHIVED  = 'archived';

    public const STATUSES = [
        self::STATUS_AVAILABLE,
        self::STATUS_RESERVED,
        self::STATUS_SOLD,
        self::STATUS_DEMO,
        self::STATUS_ARCHIVED,
    ];

    /**
     * Window the dashboard "Recently delivered" card looks back over.
     * Constant so the dashboard test can override it via reflection
     * if needed and so the value lives in one place.
     */
    public const RECENT_DELIVERED_DAYS = 30;

    protected $fillable = [
        'uuid',
        'dealer_company_id',
        'vin',
        'engine_number',
        'registration',
        'brand_id',
        'model_name',
        'suffix',
        'variant',
        'description',
        'colour',
        'model_year',
        'current_location_type',
        'current_location_id',
        'current_job_id',
        'previous_location_type',
        'status',
        'salesperson_user_id',
        'sale_customer_name',
        'sale_customer_phone',
        'sale_customer_email',
        'sold_at',
        'demo_customer_name',
        'demo_customer_phone',
        'demo_customer_email',
        'demo_started_at',
        'demo_due_back_at',
        'delivered_at',
        'archived_at',
    ];

    protected $casts = [
        'sold_at'          => 'datetime',
        'demo_started_at'  => 'datetime',
        'demo_due_back_at' => 'datetime',
        'delivered_at'     => 'datetime',
        'archived_at'      => 'datetime',
        'model_year'       => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $stock) {
            if (empty($stock->uuid)) {
                $stock->uuid = (string) Str::uuid();
            }
            // Normalise the VIN early so the importer + linker
            // never have to think about case/whitespace.
            if (!empty($stock->vin)) {
                $stock->vin = strtoupper(trim($stock->vin));
            }
        });

        static::updating(function (self $stock) {
            if ($stock->isDirty('vin') && !empty($stock->vin)) {
                $stock->vin = strtoupper(trim($stock->vin));
            }
        });
    }

    // ----- Relationships --------------------------------------------

    public function dealerCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'dealer_company_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function currentLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'current_location_id');
    }

    public function currentJob(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'current_job_id');
    }

    public function salesperson(): BelongsTo
    {
        return $this->belongsTo(User::class, 'salesperson_user_id');
    }

    // ----- Scopes ---------------------------------------------------

    /**
     * Tenancy scope -- the single source of truth used by every
     * dealer-facing read.  Combines a directly-attached dealership
     * (operatingCompanyIds()) with sibling dealerships under the
     * same company_group for franchise CEOs.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if (!$user) {
            return $query->whereRaw('0 = 1');
        }

        return $query->whereIn('dealer_company_id', $user->visibleCompanyIds());
    }

    public function scopeAtPremises(Builder $query): Builder
    {
        return $query->where('current_location_type', self::LOCATION_PREMISES);
    }

    public function scopeAtBodyBuilder(Builder $query): Builder
    {
        return $query->where('current_location_type', self::LOCATION_BODY_BUILDER);
    }

    public function scopeAtStorage(Builder $query): Builder
    {
        return $query->where('current_location_type', self::LOCATION_STORAGE);
    }

    public function scopeInTransit(Builder $query): Builder
    {
        return $query->where('current_location_type', self::LOCATION_IN_TRANSIT);
    }

    public function scopeOnDemo(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_DEMO);
    }

    public function scopeRecentlyDelivered(Builder $query, int $days = self::RECENT_DELIVERED_DAYS): Builder
    {
        return $query
            ->where('status', self::STATUS_SOLD)
            ->where('sold_at', '>=', now()->subDays($days));
    }

    /**
     * "Scheduled for movement" -- a stock unit is scheduled when it
     * has a linked transport job that's been planned but the truck
     * hasn't picked the vehicle up yet.  Once the job moves into
     * collected/in-transit the linker swings the bucket to
     * current_location_type = in_transit and this scope stops
     * matching it.
     */
    public function scopeScheduledForMovement(Builder $query): Builder
    {
        return $query
            ->whereNotNull('current_job_id')
            ->where('current_location_type', '!=', self::LOCATION_IN_TRANSIT)
            ->whereHas('currentJob', function (Builder $q) {
                $q->whereIn('status', [
                    Job::STATUS_CONFIRMED,
                    Job::STATUS_PLANNED,
                    Job::STATUS_DRIVER_ASSIGNED,
                    Job::STATUS_READY_FOR_COLLECTION,
                ]);
            });
    }

    /**
     * Excludes archived rows by default -- archived stock is
     * book-keeping only and never wants to surface on the dashboard.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }
}
