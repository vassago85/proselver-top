<?php

namespace App\Services;

use App\Models\Job;
use App\Models\Trip;
use App\Models\TripStop;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Centralises every mutation that touches `trips` + `trip_stops`.
 *
 * Why a service and not just calls on Trip / TripStop?
 *
 * - Several actions touch multiple rows (attach a job = create two
 *   stops + flip the job's trip_id + driver), and we want those wrapped
 *   in one transaction.
 * - We enforce business rules in one place: a pickup must precede its
 *   matching dropoff; a job can only belong to one trip at a time; the
 *   linked job's driver is force-synced to the trip's driver; a
 *   completed trip can't be edited.
 * - The Volt planner page (drag-drop reorder, "insert waypoint",
 *   "add positioning") and the My-Day driver page both call into this
 *   service rather than re-implementing the rules.
 */
class TripPlanner
{
    /**
     * Attach a transport job to a trip. Creates a pickup and a dropoff
     * stop at the end of the sequence, sets the job's trip_id, and
     * force-aligns the job's driver to the trip's driver.
     *
     * Returns the two created stops keyed by their type for callers
     * that want to immediately reposition them.
     *
     * @return array{pickup: TripStop, dropoff: TripStop}
     */
    public function attachJob(Trip $trip, Job $job): array
    {
        $this->guardTripEditable($trip);

        if ($job->trip_id && (int) $job->trip_id !== (int) $trip->id) {
            throw new InvalidArgumentException(
                "Job #{$job->id} is already on trip #{$job->trip_id}. Detach it first."
            );
        }

        if ((int) $job->company_id !== (int) $trip->company_id) {
            // A trip for company A shouldn't be able to grab a job from
            // company B. ProSelver ops still works because trips for
            // dealer-A jobs are created against dealer-A's company.
            throw new InvalidArgumentException(
                'Job belongs to a different company than the trip.'
            );
        }

        return DB::transaction(function () use ($trip, $job) {
            $baseSeq = $trip->nextSequence();

            $pickup = TripStop::create([
                'trip_id'          => $trip->id,
                'sequence'         => $baseSeq,
                'stop_type'        => TripStop::TYPE_JOB_PICKUP,
                'transport_job_id' => $job->id,
                'location_id'      => $job->pickup_location_id,
                'expected_at'      => $job->scheduled_date
                    ? Carbon::parse($job->scheduled_date)->setTime(8, 0)
                    : null,
            ]);

            $dropoff = TripStop::create([
                'trip_id'          => $trip->id,
                'sequence'         => $baseSeq + 1,
                'stop_type'        => TripStop::TYPE_JOB_DROPOFF,
                'transport_job_id' => $job->id,
                'location_id'      => $job->delivery_location_id,
                'expected_at'      => null,
            ]);

            $job->trip_id = $trip->id;
            // A trip implies a driver, so the job must end up with one
            // regardless of executor type. We deliberately don't touch
            // executor_type — a dealer planning a trip with their own
            // driver should already have set executor_type=internal at
            // booking time; a ProSelver trip moves ProSelver-executor
            // jobs and leaving the field alone preserves that
            // distinction in dispatch reports.
            $job->driver_user_id = $trip->driver_user_id;
            $job->save();

            return ['pickup' => $pickup, 'dropoff' => $dropoff];
        });
    }

    /**
     * Detach a job from its trip. Removes the linked pickup + dropoff
     * stops, clears the job's trip_id, and (optionally) clears the
     * driver so it can be re-assigned by the planner.
     */
    public function detachJob(Trip $trip, Job $job, bool $clearDriver = true): void
    {
        $this->guardTripEditable($trip);

        if ((int) $job->trip_id !== (int) $trip->id) {
            throw new InvalidArgumentException("Job #{$job->id} isn't on trip #{$trip->id}.");
        }

        DB::transaction(function () use ($trip, $job, $clearDriver) {
            TripStop::query()
                ->where('trip_id', $trip->id)
                ->where('transport_job_id', $job->id)
                ->delete();

            $job->trip_id = null;
            if ($clearDriver) {
                $job->driver_user_id = null;
            }
            $job->save();

            $this->renumberStops($trip);
        });
    }

    /**
     * Insert a non-job stop (waypoint or positioning leg). Returns the
     * created stop. `$afterSequence` puts the new stop immediately
     * after that sequence number; pass `null` to append.
     */
    public function insertWaypoint(
        Trip $trip,
        string $stopType,
        ?int $locationId = null,
        ?int $afterSequence = null,
        ?string $notes = null,
        ?\DateTimeInterface $expectedAt = null,
    ): TripStop {
        $this->guardTripEditable($trip);

        if (!in_array($stopType, TripStop::TYPES, true) || in_array($stopType, TripStop::JOB_LINKED_TYPES, true)) {
            throw new InvalidArgumentException("Stop type {$stopType} can't be added via insertWaypoint().");
        }

        return DB::transaction(function () use ($trip, $stopType, $locationId, $afterSequence, $notes, $expectedAt) {
            $insertAt = $afterSequence === null
                ? $trip->nextSequence()
                : ($afterSequence + 1);

            // Make room for the new sequence number by bumping every
            // stop at-or-after the insert position by one. Cheap with
            // ~20 stops per trip; no need for fractional ordering.
            TripStop::query()
                ->where('trip_id', $trip->id)
                ->where('sequence', '>=', $insertAt)
                ->orderByDesc('sequence')
                ->get()
                ->each->update(['sequence' => DB::raw('sequence + 1')]);

            return TripStop::create([
                'trip_id'     => $trip->id,
                'sequence'    => $insertAt,
                'stop_type'   => $stopType,
                'location_id' => $locationId,
                'expected_at' => $expectedAt,
                'notes'       => $notes,
            ]);
        });
    }

    /**
     * Reorder stops by passing the desired sequence as an array of
     * `[stop_id => new_sequence]`. The planner UI calls this after a
     * drag-and-drop. Validates pickup-before-dropoff for every job
     * linked to the trip before persisting; refuses the whole
     * operation rather than half-applying it.
     */
    public function reorderStops(Trip $trip, array $sequenceMap): void
    {
        $this->guardTripEditable($trip);

        DB::transaction(function () use ($trip, $sequenceMap) {
            $stops = $trip->stops()->get()->keyBy('id');

            foreach ($sequenceMap as $stopId => $newSeq) {
                $stop = $stops->get((int) $stopId);
                if (!$stop) {
                    continue;
                }
                $stop->sequence = (int) $newSeq;
                $stop->save();
            }

            $this->renumberStops($trip);
            $this->assertPickupBeforeDropoff($trip);
        });
    }

    /**
     * Remove a single stop. If it's a job-linked stop, detach the job
     * entirely (both halves of the pair are removed — keeping only the
     * pickup or only the dropoff is never useful).
     */
    public function removeStop(TripStop $stop): void
    {
        $trip = $stop->trip;
        $this->guardTripEditable($trip);

        DB::transaction(function () use ($stop, $trip) {
            if ($stop->isJobLinked() && $stop->job) {
                $this->detachJob($trip, $stop->job);
                return;
            }
            $stop->delete();
            $this->renumberStops($trip);
        });
    }

    /**
     * Change the trip's assigned driver. Force-syncs every linked job's
     * driver to match so the dispatch dashboard stays consistent.
     */
    public function changeDriver(Trip $trip, int $newDriverUserId): void
    {
        $this->guardTripEditable($trip);

        DB::transaction(function () use ($trip, $newDriverUserId) {
            $this->assertDriverHasNoConflictingTrip($newDriverUserId, $trip->trip_date, $trip->id);
            $trip->driver_user_id = $newDriverUserId;
            $trip->save();
            $trip->syncJobDrivers();
        });
    }

    /**
     * Refuse to plan two simultaneous trips for the same driver on the
     * same date. Used both on create and on driver swap.
     */
    public function assertDriverHasNoConflictingTrip(int $driverUserId, $date, ?int $ignoreTripId = null): void
    {
        $exists = Trip::query()
            ->where('driver_user_id', $driverUserId)
            ->whereDate('trip_date', $date)
            ->whereIn('status', [Trip::STATUS_PLANNED, Trip::STATUS_IN_PROGRESS])
            ->when($ignoreTripId, fn ($q) => $q->where('id', '!=', $ignoreTripId))
            ->exists();

        if ($exists) {
            throw new RuntimeException('This driver already has an active trip on that date.');
        }
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Re-pack sequence numbers to a dense 1..N range after an insert /
     * delete / drag-drop. Keeps the planner UI's "stop 1 of 7" labels
     * tidy and means we never run into surprise gaps.
     */
    private function renumberStops(Trip $trip): void
    {
        $stops = $trip->stops()->orderBy('sequence')->orderBy('id')->get();
        foreach ($stops as $i => $stop) {
            $expected = $i + 1;
            if ((int) $stop->sequence !== $expected) {
                $stop->sequence = $expected;
                $stop->save();
            }
        }
    }

    /**
     * For every job referenced on this trip, the pickup stop's sequence
     * must come strictly before its matching dropoff stop's sequence.
     * Thrown errors roll back the surrounding transaction.
     */
    private function assertPickupBeforeDropoff(Trip $trip): void
    {
        $stops = $trip->stops()->get();
        $byJob = [];

        foreach ($stops as $stop) {
            if (!$stop->isJobLinked() || !$stop->transport_job_id) {
                continue;
            }
            $byJob[$stop->transport_job_id][$stop->stop_type] = $stop->sequence;
        }

        foreach ($byJob as $jobId => $seqs) {
            $pickupSeq  = $seqs[TripStop::TYPE_JOB_PICKUP] ?? null;
            $dropoffSeq = $seqs[TripStop::TYPE_JOB_DROPOFF] ?? null;
            if ($pickupSeq === null || $dropoffSeq === null) {
                continue;
            }
            if ($pickupSeq >= $dropoffSeq) {
                throw new RuntimeException(
                    "Job #{$jobId}: pickup (stop {$pickupSeq}) must come before dropoff (stop {$dropoffSeq})."
                );
            }
        }
    }

    /**
     * Completed / cancelled trips are immutable — refuse mutation
     * loudly so the UI can surface the message to the planner.
     */
    private function guardTripEditable(Trip $trip): void
    {
        if (in_array($trip->status, [Trip::STATUS_COMPLETED, Trip::STATUS_CANCELLED], true)) {
            throw new RuntimeException("Trip is {$trip->status} and can no longer be edited.");
        }
    }
}
