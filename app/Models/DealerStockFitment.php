<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single body-builder / fitment stop in a dealer stock unit's
 * build chain.  Many of these can be attached to one DealerStock,
 * ordered by `sequence`, representing the journey a chassis takes
 * through fitment partners (e.g. dropside body -> crane mount, or
 * fridge body -> fridge unit).
 *
 * Each row carries its own notes, fitment type, internal BB job
 * number, and dealer-controlled "share with this BB" payload so
 * the dealer can choose to share salesperson + end customer with
 * one BB but withhold it from another.
 */
class DealerStockFitment extends Model
{
    public const STATUS_PLANNED     = 'planned';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED   = 'completed';
    public const STATUS_CANCELLED   = 'cancelled';

    public const ALL_STATUSES = [
        self::STATUS_PLANNED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    /** Suggested fitment types -- not enforced, used as a datalist on
     *  the form so common operations (dropside, crane, fridge body
     *  etc.) are one click away while still allowing free text. */
    public const SUGGESTED_TYPES = [
        'Dropside body',
        'Crane mount',
        'Tipper body',
        'Fridge body',
        'Fridge unit',
        'Canopy',
        'Tow-bar / accessories',
        'Paint / wrap',
        'Final inspection',
    ];

    protected $table = 'dealer_stock_fitments';

    protected $fillable = [
        'dealer_stock_id',
        'body_builder_company_id',
        'sequence',
        'fitment_type',
        'status',
        'started_at',
        'completed_at',
        'internal_job_number',
        'share_with_bb',
        'share_salesperson',
        'share_end_customer',
        'notes',
    ];

    protected $casts = [
        'started_at'    => 'datetime',
        'completed_at'  => 'datetime',
        'share_with_bb' => 'boolean',
        'sequence'      => 'integer',
    ];

    public function stock(): BelongsTo
    {
        return $this->belongsTo(DealerStock::class, 'dealer_stock_id');
    }

    public function bodyBuilder(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'body_builder_company_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_IN_PROGRESS);
    }

    public function scopePlanned(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PLANNED);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeForBodyBuilder(Builder $query, int $companyId): Builder
    {
        return $query->where('body_builder_company_id', $companyId);
    }

    /** Human label for the current status, for UI badges. */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PLANNED     => 'Planned',
            self::STATUS_IN_PROGRESS => 'In progress',
            self::STATUS_COMPLETED   => 'Completed',
            self::STATUS_CANCELLED   => 'Cancelled',
            default                  => ucfirst((string) $this->status),
        };
    }
}
