<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A bundle of trip advances ops has assembled for owner sign-off.
 *
 * Lifecycle: draft -> pending -> approved | rejected.
 * Approval stamps the linked transport_jobs with advance_plan_id +
 * advance_approved_at, which is the gate the order detail page checks
 * before letting ops click "Issue Advance".
 *
 * Items live in items_json (a snapshot of the breakdown at sign-off
 * time).  The live transport_jobs row can drift after approval; the
 * audit log captures that drift -- the plan itself stays as an
 * immutable record of what the owner approved.
 */
class PettyCashPlan extends Model
{
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
    ];

    protected $fillable = [
        'uuid',
        'label',
        'status',
        'total_amount',
        'items_json',
        'generated_by_user_id',
        'generated_at',
        'approved_by_user_id',
        'approved_at',
        'sign_off_notes',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'items_json' => 'array',
            'generated_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PettyCashPlan $plan) {
            if (empty($plan->uuid)) {
                $plan->uuid = (string) Str::uuid();
            }
        });
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /**
     * Jobs that ended up linked to this plan (post-approval).  This is
     * different from items_json (the SNAPSHOT) -- items_json says what
     * was approved; this relation says what's actually tagged.  Useful
     * for "show me the live state of every trip the owner signed off".
     */
    public function jobs(): HasMany
    {
        return $this->hasMany(Job::class, 'advance_plan_id');
    }

    public function statusBadgeClasses(): string
    {
        return match ($this->status) {
            self::STATUS_APPROVED => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            self::STATUS_REJECTED => 'bg-rose-50 text-rose-700 border-rose-200',
            self::STATUS_PENDING  => 'bg-amber-50 text-amber-700 border-amber-200',
            default               => 'bg-slate-50 text-slate-600 border-slate-200',
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_REJECTED => 'Rejected',
            self::STATUS_PENDING  => 'Pending sign-off',
            default               => 'Draft',
        };
    }

    /** True if ops can still edit the plan (only drafts and rejected). */
    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_REJECTED], true);
    }

    public function isAwaitingSignOff(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
