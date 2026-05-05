<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Driver petty cash / expense entry.
 *
 * Lifecycle:
 *   submitted ── ops approve ──▶ approved ── reimbursed ──▶ reimbursed
 *      │
 *      └── ops reject (with reason) ──▶ rejected
 *
 * The driver creates rows via the PWA at status=submitted with the
 * receipt photo attached as a JobDocument. Ops then triages them in
 * /admin/petty-cash. Reimbursement is a separate step — finance marks
 * approved rows as reimbursed once the EFT goes out.
 */
class PettyCashEntry extends Model
{
    use HasFactory;

    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_REIMBURSED = 'reimbursed';

    public const CATEGORY_FUEL = 'fuel_slip';
    public const CATEGORY_FOOD = 'food_slip';
    public const CATEGORY_TOLL = 'toll_slip';
    public const CATEGORY_PARKING = 'parking_slip';
    public const CATEGORY_ACCOMMODATION = 'accommodation_slip';
    public const CATEGORY_OTHER = 'other';

    protected $fillable = [
        'uuid',
        'job_id',
        'driver_user_id',
        'document_id',
        'category',
        'amount_cents',
        'currency',
        'merchant_name',
        'spent_at',
        'description',
        'status',
        'approved_by_user_id',
        'approved_at',
        'rejection_reason',
        'reimbursed_at',
        'reimbursement_reference',
        'ocr_amount_cents',
        'ocr_text',
        'ocr_confidence',
        'client_uuid',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $entry) {
            if (empty($entry->uuid)) {
                $entry->uuid = (string) Str::uuid();
            }
            if (empty($entry->currency)) {
                $entry->currency = 'ZAR';
            }
            if (empty($entry->status)) {
                $entry->status = self::STATUS_SUBMITTED;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'ocr_amount_cents' => 'integer',
            'ocr_confidence' => 'decimal:2',
            'spent_at' => 'date',
            'approved_at' => 'datetime',
            'reimbursed_at' => 'datetime',
        ];
    }

    public static function categories(): array
    {
        return [
            self::CATEGORY_FUEL => 'Fuel',
            self::CATEGORY_FOOD => 'Food',
            self::CATEGORY_TOLL => 'Toll',
            self::CATEGORY_PARKING => 'Parking',
            self::CATEGORY_ACCOMMODATION => 'Accommodation',
            self::CATEGORY_OTHER => 'Other',
        ];
    }

    public static function statusOptions(): array
    {
        return [
            self::STATUS_SUBMITTED => 'Pending review',
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_REJECTED => 'Rejected',
            self::STATUS_REIMBURSED => 'Reimbursed',
        ];
    }

    public function categoryLabel(): string
    {
        return self::categories()[$this->category] ?? ucfirst(str_replace('_', ' ', (string) $this->category));
    }

    public function statusLabel(): string
    {
        return self::statusOptions()[$this->status] ?? ucfirst(str_replace('_', ' ', (string) $this->status));
    }

    /**
     * Tailwind palette per status — used by both the driver and admin
     * lists so the colour story stays consistent.
     */
    public function statusBadgeClasses(): string
    {
        return match ($this->status) {
            self::STATUS_APPROVED => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            self::STATUS_REJECTED => 'bg-rose-50 text-rose-700 border-rose-200',
            self::STATUS_REIMBURSED => 'bg-blue-50 text-blue-700 border-blue-200',
            default => 'bg-amber-50 text-amber-700 border-amber-200',
        };
    }

    public function amountRand(): float
    {
        return round($this->amount_cents / 100, 2);
    }

    public function amountForDisplay(): string
    {
        return 'R ' . number_format($this->amountRand(), 2);
    }

    /**
     * Approve the entry. Idempotent: re-approving a row already approved
     * by the same actor is a no-op so a double-click on the button can't
     * corrupt the audit trail.
     */
    public function approve(User $actor): bool
    {
        if (!in_array($this->status, [self::STATUS_SUBMITTED], true)) {
            return false;
        }

        $this->status = self::STATUS_APPROVED;
        $this->approved_by_user_id = $actor->id;
        $this->approved_at = now();
        $this->rejection_reason = null;
        $this->save();

        return true;
    }

    public function reject(User $actor, string $reason): bool
    {
        if (!in_array($this->status, [self::STATUS_SUBMITTED, self::STATUS_APPROVED], true)) {
            return false;
        }

        $this->status = self::STATUS_REJECTED;
        $this->approved_by_user_id = $actor->id;
        $this->approved_at = now();
        $this->rejection_reason = $reason;
        $this->save();

        return true;
    }

    public function reimburse(User $actor, ?string $reference = null): bool
    {
        if ($this->status !== self::STATUS_APPROVED) {
            return false;
        }

        $this->status = self::STATUS_REIMBURSED;
        $this->reimbursed_at = now();
        $this->reimbursement_reference = $reference;
        $this->save();

        return true;
    }

    public function scopePending(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_SUBMITTED);
    }

    public function scopeForDriver(Builder $q, int|User $driver): Builder
    {
        return $q->where('driver_user_id', is_int($driver) ? $driver : $driver->id);
    }

    public function scopeBetween(Builder $q, $from, $to): Builder
    {
        return $q->whereBetween('created_at', [$from, $to]);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_user_id');
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(JobDocument::class, 'document_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
