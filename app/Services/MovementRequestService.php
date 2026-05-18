<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Job;
use App\Models\Location;
use App\Models\MovementRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * Body-builder ↔ dealer movement-request workflow.
 *
 * The BB raises a request ("send this truck to the crane shop" or
 * "this one is ready, please collect"); the dealer either approves it
 * (in which case we materialise a real transport_jobs row via
 * BookingService) or rejects it with a reason. Both ends keep an
 * audit trail in `movement_requests`.
 *
 * This service is the single place that mutates a MovementRequest's
 * status — controllers / Volt components should always go through here
 * so the policy gates, the source-job link rule, and the booking
 * creation stay consistent.
 */
class MovementRequestService
{
    public function __construct(
        protected BookingService $booking,
    ) {}

    /**
     * Raise a "send the vehicle on to the next fitment" request from
     * the body builder.  The source job is required because we need to
     * know which dealer's inventory we're moving, which BB location is
     * the pickup, and what brand / class to copy onto the new job.
     */
    public function createNextMoveRequest(
        Job $sourceJob,
        User $requester,
        array $data,
    ): MovementRequest {
        return $this->createRequest($sourceJob, $requester, MovementRequest::TYPE_NEXT_MOVE, $data);
    }

    /**
     * Raise a "vehicle is ready, please collect" request. Delivery
     * defaults to the source job's original pickup (dealer's depot)
     * but the BB can override before submitting.
     */
    public function createCollectionRequest(
        Job $sourceJob,
        User $requester,
        array $data,
    ): MovementRequest {
        // Sensible defaults: send the vehicle back to where it came
        // from unless the BB specified otherwise. This is the 80%
        // case — dealer wants their unit back at the depot once the
        // fitment is signed off.
        $data['delivery_location_id'] = $data['delivery_location_id']
            ?? $sourceJob->pickup_location_id;

        return $this->createRequest($sourceJob, $requester, MovementRequest::TYPE_COLLECTION, $data);
    }

    /**
     * Dealer planner approves a pending request. Creates the new
     * transport_jobs row via BookingService and stamps the request
     * with the audit trail. Wrapped in a DB transaction so a booking
     * failure rolls the request back to pending.
     */
    public function approve(MovementRequest $req, User $decider, ?string $notes = null): MovementRequest
    {
        if (! $req->isPending()) {
            throw new RuntimeException("Movement request {$req->uuid} is not pending — cannot approve.");
        }

        if (empty($req->pickup_location_id) || empty($req->delivery_location_id)) {
            throw new InvalidArgumentException('Pickup and delivery locations must be set before approval.');
        }
        if (empty($req->vehicle_class_id)) {
            throw new InvalidArgumentException('Vehicle class must be set before approval.');
        }

        return DB::transaction(function () use ($req, $decider, $notes) {
            // Materialise the request into a real transport_jobs row.
            // Executor defaults to ProSelver so the dealer can flip
            // afterwards if they want their own driver to handle it —
            // we don't presume the dealer wants to spin up a tripped
            // internal driver job at the point of approval.
            $job = $this->booking->createTransportBooking([
                'pickup_location_id'   => $req->pickup_location_id,
                'delivery_location_id' => $req->delivery_location_id,
                'vehicle_class_id'     => $req->vehicle_class_id,
                'brand_id'             => $req->brand_id,
                'model_name'           => $req->model_name,
                'vin'                  => $req->vin ?: $req->sourceJob?->vin ?: 'BB-' . $req->uuid,
                'registration'         => $req->registration ?: $req->sourceJob?->registration,
                'scheduled_date'       => optional($req->requested_date)->toDateString() ?: now()->toDateString(),
                'company_id'           => $req->target_company_id,
                'created_by_user_id'   => $decider->id,
                'customer_notes'       => trim(
                    'BB request: ' . $req->typeLabel()
                    . ($req->notes ? ' — ' . $req->notes : '')
                ),
                'executor_type'        => Job::EXECUTOR_PROSELVER,
                // Collections are always "back to the dealer/customer"
                // → final delivery; next-move requests keep the vehicle
                // at another body builder / fitment shop so it stays
                // on Stock In Transit.
                'destination_type'     => $req->isCollection()
                    ? Job::DESTINATION_DEALER
                    : Job::DESTINATION_BODY_BUILDER,
                'is_round_trip'        => false,
            ]);

            // The booking comes back at STATUS_BOOKED by default; flip
            // to RECEIVED so it lands in the dealer's confirmation /
            // dispatch queue immediately (mirrors customer/orders/create).
            $job->status = Job::STATUS_RECEIVED;
            $job->save();

            $req->update([
                'status'             => MovementRequest::STATUS_APPROVED,
                'decided_by_user_id' => $decider->id,
                'decided_at'         => now(),
                'decision_notes'     => $notes,
                'created_job_id'     => $job->id,
            ]);

            Log::info('movement_request.approved', [
                'request_uuid' => $req->uuid,
                'job_id'       => $job->id,
                'job_number'   => $job->job_number,
                'decider_id'   => $decider->id,
            ]);

            return $req->fresh(['createdJob']);
        });
    }

    /**
     * Dealer planner rejects a pending request. Notes are required so
     * the BB has actionable feedback (the form validates this server-
     * side too — this layer just enforces presence at the model level).
     */
    public function reject(MovementRequest $req, User $decider, string $notes): MovementRequest
    {
        if (! $req->isPending()) {
            throw new RuntimeException("Movement request {$req->uuid} is not pending — cannot reject.");
        }
        if (trim($notes) === '') {
            throw new InvalidArgumentException('Rejection notes are required so the body builder knows why.');
        }

        $req->update([
            'status'             => MovementRequest::STATUS_REJECTED,
            'decided_by_user_id' => $decider->id,
            'decided_at'         => now(),
            'decision_notes'     => $notes,
        ]);

        Log::info('movement_request.rejected', [
            'request_uuid' => $req->uuid,
            'decider_id'   => $decider->id,
        ]);

        return $req->fresh();
    }

    /**
     * BB withdraws a pending request — e.g. they realise the unit
     * isn't actually ready yet. Only the originating BB tenant can
     * cancel (policy enforces this; service just validates lifecycle).
     */
    public function cancel(MovementRequest $req, User $actor, ?string $notes = null): MovementRequest
    {
        if (! $req->isPending()) {
            throw new RuntimeException("Movement request {$req->uuid} is not pending — cannot cancel.");
        }

        $req->update([
            'status'             => MovementRequest::STATUS_CANCELLED,
            'decided_by_user_id' => $actor->id,
            'decided_at'         => now(),
            'decision_notes'     => $notes,
        ]);

        return $req->fresh();
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    protected function createRequest(
        Job $sourceJob,
        User $requester,
        string $type,
        array $data,
    ): MovementRequest {
        if (! in_array($type, MovementRequest::TYPES, true)) {
            throw new InvalidArgumentException("Unknown request type: {$type}");
        }

        // Defensive guard — callers should already have policy-checked,
        // but a non-BB user reaching here would otherwise create a
        // request the dealer's queue can't categorise.
        $bbCompany = $requester->company();
        if (! $bbCompany || $bbCompany->type !== Company::TYPE_BODY_BUILDER) {
            throw new RuntimeException('Only body-builder users can raise movement requests.');
        }

        if (! $sourceJob->company_id) {
            throw new InvalidArgumentException('Source job has no owning dealer company.');
        }

        // The BB must be linked to the source job's dealer for this to
        // be a legitimate request. Policy layer also checks this, but
        // we enforce it at the service so direct calls (queue jobs,
        // tests) can't bypass.
        $linked = $bbCompany->linkedDealers()
            ->wherePivot('is_active', true)
            ->whereKey($sourceJob->company_id)
            ->exists();
        if (! $linked) {
            throw new RuntimeException(
                'Body builder is not linked to the dealer that owns this job — request blocked.'
            );
        }

        // Sensible defaults copied from the source job so the BB only
        // has to confirm them. Pickup is one of the BB's locations
        // (defaulting to the source job's delivery location, i.e. the
        // BB depot the truck arrived at).
        $pickupLocationId = $data['pickup_location_id'] ?? $sourceJob->delivery_location_id;

        return MovementRequest::create([
            'requesting_company_id' => $bbCompany->id,
            'target_company_id'     => $sourceJob->company_id,
            'requesting_user_id'    => $requester->id,
            'source_job_id'         => $sourceJob->id,
            'request_type'          => $type,
            'pickup_location_id'    => $pickupLocationId,
            'delivery_location_id'  => $data['delivery_location_id'] ?? null,
            'vehicle_class_id'      => $data['vehicle_class_id'] ?? $sourceJob->vehicle_class_id,
            'brand_id'              => $data['brand_id'] ?? $sourceJob->brand_id,
            'vin'                   => $data['vin'] ?? $sourceJob->vin,
            'registration'          => $data['registration'] ?? $sourceJob->registration,
            'model_name'            => $data['model_name'] ?? $sourceJob->model_name,
            'requested_date'        => $data['requested_date'] ?? null,
            'notes'                 => $data['notes'] ?? null,
            'status'                => MovementRequest::STATUS_PENDING,
        ]);
    }
}
