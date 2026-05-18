<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

/**
 * One row in a `trips.stops` sequence. Each stop is one of:
 *
 *   - job_pickup           : driver collects a vehicle for the linked job
 *   - job_dropoff          : driver delivers the vehicle for the linked job
 *   - positioning          : driver moves between two locations without a
 *                            job vehicle (often as a passenger so they
 *                            can drive the next job's vehicle home)
 *   - waypoint_cof         : roadworthy check stop
 *   - waypoint_weighbridge : weighbridge stop
 *   - waypoint_fuel        : fuel stop
 *   - waypoint_other       : free-form waypoint (planner notes describe it)
 *
 * Pickup/dropoff stops link to a `transport_job`; everything else uses
 * `location_id` alone. `markArrived()` / `markDeparted()` are the only
 * status-changing entry points the driver-facing "My day" page uses,
 * and they cascade the relevant `Job::transitionTo` call so the planner
 * doesn't need to know about workflow state.
 */
class TripStop extends Model
{
    use HasFactory;

    public const TYPE_JOB_PICKUP           = 'job_pickup';
    public const TYPE_JOB_DROPOFF          = 'job_dropoff';
    public const TYPE_POSITIONING          = 'positioning';
    public const TYPE_WAYPOINT_COF         = 'waypoint_cof';
    public const TYPE_WAYPOINT_WEIGHBRIDGE = 'waypoint_weighbridge';
    public const TYPE_WAYPOINT_FUEL        = 'waypoint_fuel';
    public const TYPE_WAYPOINT_OTHER       = 'waypoint_other';

    public const TYPES = [
        self::TYPE_JOB_PICKUP,
        self::TYPE_JOB_DROPOFF,
        self::TYPE_POSITIONING,
        self::TYPE_WAYPOINT_COF,
        self::TYPE_WAYPOINT_WEIGHBRIDGE,
        self::TYPE_WAYPOINT_FUEL,
        self::TYPE_WAYPOINT_OTHER,
    ];

    public const TYPE_LABELS = [
        self::TYPE_JOB_PICKUP           => 'Pickup',
        self::TYPE_JOB_DROPOFF          => 'Drop-off',
        self::TYPE_POSITIONING          => 'Positioning',
        self::TYPE_WAYPOINT_COF         => 'COF check',
        self::TYPE_WAYPOINT_WEIGHBRIDGE => 'Weighbridge',
        self::TYPE_WAYPOINT_FUEL        => 'Fuel stop',
        self::TYPE_WAYPOINT_OTHER       => 'Waypoint',
    ];

    /**
     * Stop types whose location must come from the linked job (not a
     * free-text location pick). Used by the planner to refuse a manual
     * location swap on a pickup/dropoff stop.
     */
    public const JOB_LINKED_TYPES = [
        self::TYPE_JOB_PICKUP,
        self::TYPE_JOB_DROPOFF,
    ];

    protected $fillable = [
        'trip_id',
        'sequence',
        'stop_type',
        'transport_job_id',
        'location_id',
        'expected_at',
        'arrived_at',
        'departed_at',
        'notes',
    ];

    protected $casts = [
        'sequence'    => 'integer',
        'expected_at' => 'datetime',
        'arrived_at'  => 'datetime',
        'departed_at' => 'datetime',
    ];

    // -----------------------------------------------------------------
    // Relations
    // -----------------------------------------------------------------

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'transport_job_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    // -----------------------------------------------------------------
    // Predicates
    // -----------------------------------------------------------------

    public function isJobLinked(): bool
    {
        return in_array($this->stop_type, self::JOB_LINKED_TYPES, true);
    }

    public function isPickup(): bool
    {
        return $this->stop_type === self::TYPE_JOB_PICKUP;
    }

    public function isDropoff(): bool
    {
        return $this->stop_type === self::TYPE_JOB_DROPOFF;
    }

    public function isWaypoint(): bool
    {
        return str_starts_with($this->stop_type, 'waypoint_');
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->stop_type] ?? ucfirst($this->stop_type);
    }

    // -----------------------------------------------------------------
    // State transitions
    // -----------------------------------------------------------------

    /**
     * Driver-facing "Arrived" button. Timestamps the stop and, when the
     * stop is linked to a job, advances the job's workflow state to
     * reflect the action (pickup → collected, dropoff → delivered).
     * We swallow failed transitions rather than aborting the arrival —
     * the driver's "I'm here" signal should never be lost just because
     * the job is in an unexpected state.
     */
    public function markArrived(?\DateTimeInterface $when = null): bool
    {
        $this->arrived_at = $when ?? now();
        $saved = $this->save();

        $this->ensureTripInProgress();

        if ($this->isPickup() && $this->job) {
            // Pickup arrival = driver is at the vehicle, ready to load
            // it. Some workflows have STATUS_READY_FOR_COLLECTION as an
            // intermediate step; only flip to COLLECTED on departure.
            // Nothing to do here beyond the timestamp.
        }

        return $saved;
    }

    /**
     * Driver-facing "Departed" button. For job stops this is where the
     * meaningful workflow transition happens: leaving a pickup means
     * the vehicle has been collected; leaving a dropoff means it's been
     * delivered.
     */
    public function markDeparted(?\DateTimeInterface $when = null): bool
    {
        $this->departed_at = $when ?? now();
        if (!$this->arrived_at) {
            // A driver tapping "Departed" without first tapping
            // "Arrived" is a real-world edge case (drove off in a hurry,
            // app was offline) — backfill the arrival so the audit
            // trail isn't missing one half of the pair.
            $this->arrived_at = $this->departed_at;
        }
        $saved = $this->save();

        $this->ensureTripInProgress();

        if (!$this->job) {
            return $saved;
        }

        try {
            if ($this->isPickup()) {
                $this->advanceJobOnPickupDeparture();
            } elseif ($this->isDropoff()) {
                $this->advanceJobOnDropoffDeparture();
            }
        } catch (\Throwable $e) {
            Log::warning('TripStop departure failed to advance job', [
                'trip_stop_id' => $this->id,
                'job_id'       => $this->transport_job_id,
                'stop_type'    => $this->stop_type,
                'error'        => $e->getMessage(),
            ]);
        }

        return $saved;
    }

    /**
     * Convenience for the planner UI: arrived AND departed in one tap
     * (e.g. quick fuel stop, COF where the driver just stamps it done).
     */
    public function markCompleted(?\DateTimeInterface $when = null): bool
    {
        $this->markArrived($when);
        return $this->markDeparted($when);
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    private function ensureTripInProgress(): void
    {
        $trip = $this->trip;
        if ($trip && $trip->isPlanned()) {
            $trip->start();
        }
    }

    private function advanceJobOnPickupDeparture(): void
    {
        $job = $this->job;
        if (!$job) {
            return;
        }

        // Walk the workflow forward until we hit COLLECTED. Most jobs
        // arrive here in PLANNED / DRIVER_ASSIGNED, so we may need
        // intermediate steps (READY_FOR_COLLECTION) depending on the
        // company's workflow config.
        $path = [
            Job::STATUS_READY_FOR_COLLECTION,
            Job::STATUS_COLLECTED,
        ];

        foreach ($path as $target) {
            if ($job->status === $target) {
                continue;
            }
            if ($job->canTransitionTo($target)) {
                $job->transitionTo($target);
                $job->refresh();
            }
        }
    }

    private function advanceJobOnDropoffDeparture(): void
    {
        $job = $this->job;
        if (!$job) {
            return;
        }

        $path = [
            Job::STATUS_IN_TRANSIT,
            Job::STATUS_DELIVERED,
        ];

        foreach ($path as $target) {
            if ($job->status === $target) {
                continue;
            }
            if ($job->canTransitionTo($target)) {
                $job->transitionTo($target);
                $job->refresh();
            }
        }
    }
}
