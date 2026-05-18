<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One driver's day plan. A trip holds an ordered list of `trip_stops`
 * (job pickups / dropoffs, positioning legs, COF / weighbridge / fuel
 * waypoints) plus pointer to the depot the driver starts and ends at.
 *
 * Lifecycle:
 *
 *   planned -> in_progress -> completed
 *                          \-> cancelled (any pre-completion state)
 *
 * Driver-job link rule: every `transport_job` referenced by a `job_pickup`
 * or `job_dropoff` stop has its `driver_user_id` forced to equal
 * `trips.driver_user_id`. The TripPlanner service enforces this — Trip
 * itself just exposes `syncJobDrivers()` as a helper for the few flows
 * that touch stops directly.
 */
class Trip extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_PLANNED = 'planned';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PLANNED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    public const STATUS_LABELS = [
        self::STATUS_PLANNED     => 'Planned',
        self::STATUS_IN_PROGRESS => 'In progress',
        self::STATUS_COMPLETED   => 'Completed',
        self::STATUS_CANCELLED   => 'Cancelled',
    ];

    /**
     * Statuses a trip can change to from each state. Keeps lifecycle
     * mutations in one place so we don't sprinkle if-chains across
     * service / controller code.
     */
    public const ALLOWED_TRANSITIONS = [
        self::STATUS_PLANNED     => [self::STATUS_IN_PROGRESS, self::STATUS_CANCELLED],
        self::STATUS_IN_PROGRESS => [self::STATUS_COMPLETED, self::STATUS_CANCELLED],
        self::STATUS_COMPLETED   => [],
        self::STATUS_CANCELLED   => [],
    ];

    protected $fillable = [
        'company_id',
        'driver_user_id',
        'trip_date',
        'status',
        'start_location_id',
        'end_location_id',
        'started_at',
        'completed_at',
        'notes',
        'created_by_user_id',
    ];

    protected $casts = [
        'trip_date'    => 'date',
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];

    // -----------------------------------------------------------------
    // Relations
    // -----------------------------------------------------------------

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function startLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'start_location_id');
    }

    public function endLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'end_location_id');
    }

    public function stops(): HasMany
    {
        return $this->hasMany(TripStop::class)->orderBy('sequence');
    }

    /**
     * Direct shortcut to the transport jobs linked through any of this
     * trip's stops. Deduped because a job typically appears twice (a
     * pickup and a dropoff stop).
     */
    public function jobs()
    {
        return $this->hasMany(Job::class, 'trip_id');
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    public function scopeForCompany(Builder $q, int $companyId): Builder
    {
        return $q->where('company_id', $companyId);
    }

    public function scopeForDriver(Builder $q, int $driverId): Builder
    {
        return $q->where('driver_user_id', $driverId);
    }

    public function scopeOnDate(Builder $q, $date): Builder
    {
        return $q->whereDate('trip_date', $date);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereIn('status', [self::STATUS_PLANNED, self::STATUS_IN_PROGRESS]);
    }

    // -----------------------------------------------------------------
    // Status lifecycle
    // -----------------------------------------------------------------

    public function canTransitionTo(string $newStatus): bool
    {
        return in_array($newStatus, self::ALLOWED_TRANSITIONS[$this->status] ?? [], true);
    }

    public function start(): bool
    {
        if (!$this->canTransitionTo(self::STATUS_IN_PROGRESS)) {
            return false;
        }
        $this->status = self::STATUS_IN_PROGRESS;
        $this->started_at = $this->started_at ?? now();
        return $this->save();
    }

    public function complete(): bool
    {
        if (!$this->canTransitionTo(self::STATUS_COMPLETED)) {
            return false;
        }
        $this->status = self::STATUS_COMPLETED;
        $this->completed_at = now();
        return $this->save();
    }

    public function cancel(?string $reason = null): bool
    {
        if (!$this->canTransitionTo(self::STATUS_CANCELLED)) {
            return false;
        }
        $this->status = self::STATUS_CANCELLED;
        if ($reason) {
            $this->notes = trim(($this->notes ? $this->notes . "\n" : '') . 'Cancelled: ' . $reason);
        }
        return $this->save();
    }

    public function isPlanned(): bool
    {
        return $this->status === self::STATUS_PLANNED;
    }

    public function isInProgress(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? ucfirst($this->status);
    }

    // -----------------------------------------------------------------
    // Stop helpers
    // -----------------------------------------------------------------

    /**
     * Next free sequence number, used when appending a stop to the end
     * of the trip. Existing sequences are renumbered by the planner on
     * reorder so we don't need fractional indexes here.
     */
    public function nextSequence(): int
    {
        return ((int) $this->stops()->max('sequence')) + 1;
    }

    /**
     * Force-set every linked job's driver to this trip's driver. Used
     * after attaching jobs or after a driver swap on the trip itself.
     */
    public function syncJobDrivers(): void
    {
        Job::query()
            ->where('trip_id', $this->id)
            ->update(['driver_user_id' => $this->driver_user_id]);
    }
}
