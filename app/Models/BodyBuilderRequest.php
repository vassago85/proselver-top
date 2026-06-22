<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dealer-initiated request to add a new body builder to the directory.
 *
 * Sits between "the dealer needs a fitment shop" and "ops creates the
 * Company".  Ops can approve (new BB), merge (use an existing BB the
 * dealer just couldn't find), or reject.  Approve / merge auto-creates
 * the dealer-BB link so the dealer is immediately authorised.
 */
class BodyBuilderRequest extends Model
{
    use HasFactory;

    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_MERGED   = 'merged';
    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_MERGED,
        self::STATUS_REJECTED,
    ];

    protected $fillable = [
        'dealer_company_id',
        'requested_by_user_id',
        'proposed_name',
        'proposed_address',
        'proposed_city',
        'proposed_province',
        'proposed_contact_name',
        'proposed_contact_phone',
        'proposed_contact_email',
        'dealer_notes',
        'status',
        'decided_by_user_id',
        'decided_at',
        'decision_notes',
        'resolved_body_builder_company_id',
    ];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
        ];
    }

    /**
     * Bust the cached "pending requests" badge count on every save --
     * the sidebar caches it for 15s but a fresh submit / decide should
     * be immediately visible.  Cheap key, cheap forget.
     */
    protected static function booted(): void
    {
        $bust = fn () => \Illuminate\Support\Facades\Cache::forget('admin.body_builder_requests.pending_count');
        static::saved($bust);
        static::deleted($bust);
    }

    public function dealer(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'dealer_company_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function resolvedBodyBuilder(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'resolved_body_builder_company_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isResolved(): bool
    {
        return in_array($this->status, [self::STATUS_APPROVED, self::STATUS_MERGED, self::STATUS_REJECTED], true);
    }

    /**
     * Human label for the status -- used by both dealer and ops UIs so
     * the wording is consistent (and changes here propagate everywhere).
     */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING  => 'Pending ops review',
            self::STATUS_APPROVED => 'Approved -- new body builder added',
            self::STATUS_MERGED   => 'Merged into existing body builder',
            self::STATUS_REJECTED => 'Rejected',
            default               => ucfirst((string) $this->status),
        };
    }

    public function scopePending(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_PENDING);
    }

    public function scopeResolved(Builder $q): Builder
    {
        return $q->whereIn('status', [self::STATUS_APPROVED, self::STATUS_MERGED, self::STATUS_REJECTED]);
    }
}
