<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Driver bus-ticket movement record.
 *
 * When ops books a bus seat to get a driver from (say) Johannesburg
 * to Port Elizabeth so they can collect a vehicle, this is where the
 * ticket is recorded.  The lifecycle captures the two things the
 * owner cares about:
 *
 *   issued ── driver boarded and collected  ──▶ used
 *          \─ driver no-show, ticket wasted ──▶ not_used
 *                                                  ├── voided          (company absorbs)
 *                                                  └── charged_to_driver (deducts on payslip)
 *
 * One row per driver per leg (no bus-manifest parent).  Linked to the
 * collection Job via transport_job_id so the payslip can show the
 * context ("to collect VIN XYZ in PE"); nullable so a ticket can be
 * recorded ahead of the job being created or without a specific job.
 */
class DriverBusTicket extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_ISSUED   = 'issued';
    public const STATUS_USED     = 'used';
    public const STATUS_NOT_USED = 'not_used';

    public const STATUSES = [
        self::STATUS_ISSUED,
        self::STATUS_USED,
        self::STATUS_NOT_USED,
    ];

    public const STATUS_LABELS = [
        self::STATUS_ISSUED   => 'Issued',
        self::STATUS_USED     => 'Used',
        self::STATUS_NOT_USED => 'Not used',
    ];

    public const OUTCOME_VOIDED             = 'voided';
    public const OUTCOME_CHARGED_TO_DRIVER  = 'charged_to_driver';

    public const OUTCOMES = [
        self::OUTCOME_VOIDED,
        self::OUTCOME_CHARGED_TO_DRIVER,
    ];

    public const OUTCOME_LABELS = [
        self::OUTCOME_VOIDED            => 'Voided (company absorbs)',
        self::OUTCOME_CHARGED_TO_DRIVER => 'Charged to driver',
    ];

    protected $fillable = [
        'uuid',
        'driver_user_id',
        'transport_job_id',
        'bus_company',
        'reference_number',
        'origin_location_id',
        'origin_label',
        'destination_location_id',
        'destination_label',
        'travel_date',
        'amount_cents',
        'status',
        'not_used_outcome',
        'not_used_reason',
        'resolved_by_user_id',
        'resolved_at',
        'created_by_user_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'travel_date'  => 'date',
            'amount_cents' => 'integer',
            'resolved_at'  => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $ticket) {
            if (empty($ticket->uuid)) {
                $ticket->uuid = (string) Str::uuid();
            }
            if (empty($ticket->status)) {
                $ticket->status = self::STATUS_ISSUED;
            }
        });
    }

    // -----------------------------------------------------------------
    // Relations
    // -----------------------------------------------------------------

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_user_id');
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'transport_job_id');
    }

    public function originLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'origin_location_id');
    }

    public function destinationLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'destination_location_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    public function scopeForDriver(Builder $q, int|User $driver): Builder
    {
        return $q->where('driver_user_id', is_int($driver) ? $driver : $driver->id);
    }

    /**
     * Rows whose travel_date falls inside the Carbon anchor's calendar
     * month.  The payslip is a month-end report, so travel_date (not
     * created_at) is the natural grouping key -- a ticket booked on
     * 30 Sep for travel on 2 Oct belongs to October.
     */
    public function scopeInMonth(Builder $q, Carbon $anchor): Builder
    {
        return $q->whereBetween('travel_date', [
            $anchor->copy()->startOfMonth(),
            $anchor->copy()->endOfMonth(),
        ]);
    }

    public function scopeChargedToDriver(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_NOT_USED)
            ->where('not_used_outcome', self::OUTCOME_CHARGED_TO_DRIVER);
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_ISSUED);
    }

    // -----------------------------------------------------------------
    // State transitions
    // -----------------------------------------------------------------

    /**
     * Driver boarded + collected the vehicle.  No payslip impact; the
     * movement will pay out through driver_pay_amount / rate_per_movement
     * on the linked job in the normal way.  Idempotent -- already-used
     * tickets are a no-op so a double-click can't rewrite history.
     */
    public function markUsed(User $actor): bool
    {
        if ($this->status === self::STATUS_USED) {
            return true;
        }
        if ($this->status !== self::STATUS_ISSUED) {
            return false;
        }

        $this->status              = self::STATUS_USED;
        $this->not_used_outcome    = null;
        $this->not_used_reason     = null;
        $this->resolved_by_user_id = $actor->id;
        $this->resolved_at         = now();

        return $this->save();
    }

    /**
     * Ticket wasted but the company absorbs the cost.  Reason is
     * required (what happened / why we're not charging the driver) so
     * the owner can audit the write-off later.
     */
    public function markVoided(User $actor, string $reason): bool
    {
        if ($this->status !== self::STATUS_ISSUED) {
            return false;
        }
        if (trim($reason) === '') {
            return false;
        }

        $this->status              = self::STATUS_NOT_USED;
        $this->not_used_outcome    = self::OUTCOME_VOIDED;
        $this->not_used_reason     = $reason;
        $this->resolved_by_user_id = $actor->id;
        $this->resolved_at         = now();

        return $this->save();
    }

    /**
     * Ticket wasted and the driver is on the hook for it -- this
     * amount surfaces as a deduction on the current month's payslip.
     */
    public function markChargedToDriver(User $actor, string $reason): bool
    {
        if ($this->status !== self::STATUS_ISSUED) {
            return false;
        }
        if (trim($reason) === '') {
            return false;
        }

        $this->status              = self::STATUS_NOT_USED;
        $this->not_used_outcome    = self::OUTCOME_CHARGED_TO_DRIVER;
        $this->not_used_reason     = $reason;
        $this->resolved_by_user_id = $actor->id;
        $this->resolved_at         = now();

        return $this->save();
    }

    // -----------------------------------------------------------------
    // Display helpers
    // -----------------------------------------------------------------

    public function amountRand(): float
    {
        return round($this->amount_cents / 100, 2);
    }

    public function amountForDisplay(): string
    {
        return 'R ' . number_format($this->amountRand(), 2);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? ucfirst(str_replace('_', ' ', (string) $this->status));
    }

    public function outcomeLabel(): ?string
    {
        if (!$this->not_used_outcome) {
            return null;
        }
        return self::OUTCOME_LABELS[$this->not_used_outcome]
            ?? ucfirst(str_replace('_', ' ', $this->not_used_outcome));
    }

    public function statusBadgeClasses(): string
    {
        return match (true) {
            $this->status === self::STATUS_USED
                => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            $this->status === self::STATUS_NOT_USED
                && $this->not_used_outcome === self::OUTCOME_CHARGED_TO_DRIVER
                => 'bg-rose-50 text-rose-700 border-rose-200',
            $this->status === self::STATUS_NOT_USED
                => 'bg-slate-100 text-slate-700 border-slate-200',
            default
                => 'bg-amber-50 text-amber-700 border-amber-200',
        };
    }

    public function originForDisplay(): string
    {
        if ($this->origin_label) {
            return $this->origin_label;
        }
        return $this->originLocation?->city
            ?? $this->originLocation?->company_name
            ?? '—';
    }

    public function destinationForDisplay(): string
    {
        if ($this->destination_label) {
            return $this->destination_label;
        }
        return $this->destinationLocation?->city
            ?? $this->destinationLocation?->company_name
            ?? '—';
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_ISSUED;
    }

    public function isResolved(): bool
    {
        return !$this->isOpen();
    }

    public function isChargedToDriver(): bool
    {
        return $this->status === self::STATUS_NOT_USED
            && $this->not_used_outcome === self::OUTCOME_CHARGED_TO_DRIVER;
    }
}
