<?php

namespace App\Services;

use App\Models\Company;
use App\Models\DealerStock;
use App\Models\Job;
use App\Models\JobEvent;
use App\Models\Location;
use App\Models\SystemSetting;
use App\Models\TransportRoute;
use App\Models\ZoneRate;
use Carbon\Carbon;

class BookingService
{
    public function __construct(
        protected JobNumberGenerator $numberGenerator,
    ) {}

    public function canBookForDate(Carbon $date): bool
    {
        $cutoffTime = SystemSetting::get('next_day_cutoff_time', '16:00');
        $workingDays = SystemSetting::get('working_days', json_encode([1, 2, 3, 4, 5]));
        if (is_string($workingDays)) {
            $workingDays = json_decode($workingDays, true);
        }

        if ($date->isToday() || $date->isPast()) {
            return false;
        }

        if ($date->isTomorrow()) {
            $cutoff = now()->setTimeFromTimeString($cutoffTime);
            return now()->isBefore($cutoff);
        }

        return true;
    }

    public function createTransportBooking(array $data): Job
    {
        $route = TransportRoute::firstOrCreate([
            'origin_location_id' => $data['pickup_location_id'],
            'destination_location_id' => $data['delivery_location_id'],
            'vehicle_class_id' => $data['vehicle_class_id'],
        ], [
            'base_price' => 0,
        ]);

        // Normalise + validate the executor. Default to ProSelver so any
        // existing caller that doesn't pass executor_type behaves
        // identically to before this column existed. An unknown value
        // collapses to ProSelver too — defensive, in case a stale form
        // posts an invalid string.
        $executorType = $data['executor_type'] ?? Job::EXECUTOR_PROSELVER;
        if (! in_array($executorType, Job::EXECUTOR_TYPES, true)) {
            $executorType = Job::EXECUTOR_PROSELVER;
        }

        // Workflow + executor jointly decide the landing status. See
        // Job::initialStatusFor() for the full rationale; in short:
        //   - 'faw'-style workflow + ops-created booking  -> PENDING_VERIFICATION
        //   - ProSelver-executor                          -> RECEIVED (dealer must click Confirm)
        //   - Internal / 3rd-party / Self-collect         -> CONFIRMED (no Proselver handshake)
        $initialStatus = Job::initialStatusFor(
            $executorType,
            $this->companyWorkflowType($data['company_id'] ?? null),
            (bool) ($data['bypass_po_verification'] ?? false),
        );

        // Owner-approval gate: when a body builder (or any other
        // tenant that isn't the dealer) places a movement on a VIN
        // sitting on a dealer's stock ledger, the dealer MUST sign
        // off before dispatch can roll.  We pre-compute these fields
        // here so they get stamped at insert time -- backfilling them
        // post-create would leave a brief window where the gate
        // doesn't apply.
        $ownerFields = $this->resolveOwnerApprovalFields(
            customerCompanyId: (int) ($data['company_id'] ?? 0),
            vin: (string) ($data['vin'] ?? ''),
            registration: (string) ($data['registration'] ?? ''),
            explicitOwnerCompanyId: $data['owner_company_id'] ?? null,
        );

        $job = Job::create([
            'job_number' => $this->numberGenerator->generate(),
            'job_type' => Job::TYPE_TRANSPORT,
            'status' => $initialStatus,
            // Stamp the customer-confirmation columns when we auto-skip
            // the manual Confirm-Order step so the order timeline still
            // shows WHO acknowledged the booking and WHEN. Without this
            // the audit trail would show a CONFIRMED order with a null
            // customer_confirmed_at, which looks like a bug.
            'customer_confirmed_at' => $initialStatus === Job::STATUS_CONFIRMED ? now() : null,
            'customer_confirmed_by' => $initialStatus === Job::STATUS_CONFIRMED
                ? ($data['created_by_user_id'] ?? null)
                : null,
            'company_id' => $data['company_id'],
            'created_by_user_id' => $data['created_by_user_id'],
            'transport_route_id' => $route->id,
            'pickup_location_id' => $data['pickup_location_id'],
            'pickup_contact_name' => $data['pickup_contact_name'] ?? null,
            'pickup_contact_phone' => $data['pickup_contact_phone'] ?? null,
            'delivery_location_id' => $data['delivery_location_id'],
            'destination_type' => $data['destination_type'] ?? null,
            'delivery_contact_name' => $data['delivery_contact_name'] ?? null,
            'delivery_contact_phone' => $data['delivery_contact_phone'] ?? null,
            'vehicle_class_id' => $data['vehicle_class_id'],
            'brand_id' => $data['brand_id'] ?? null,
            'model_name' => $data['model_name'] ?? null,
            // Either identifier is now sufficient -- capture forms
            // and the bulk importer are responsible for making sure
            // at least one is present.  A registration-only booking
            // is a valid state; downstream code (linker, stock
            // lookup, dedup) matches on either column.
            'vin' => $data['vin'] ?? null,
            'registration' => $data['registration'] ?? null,
            'scheduled_date' => $data['scheduled_date'] ?? now()->toDateString(),
            'scheduled_ready_time' => $data['scheduled_ready_time'] ?? null,
            'po_number' => $data['po_number'] ?? null,
            'po_amount' => $data['po_amount'] ?? null,
            'is_emergency' => $data['is_emergency'] ?? false,
            'emergency_reason' => $data['emergency_reason'] ?? null,
            'is_round_trip' => $data['is_round_trip'] ?? false,
            'customer_notes' => $data['customer_notes'] ?? null,
            // Executor + meta. Only the columns relevant to the chosen
            // type are written; the others stay NULL so we don't carry
            // ghost data after a later executor flip.
            'executor_type' => $executorType,
            'driver_user_id' => in_array($executorType, Job::DRIVER_REQUIRED_EXECUTORS, true)
                ? ($data['driver_user_id'] ?? null)
                : null,
            'third_party_courier_name' => $executorType === Job::EXECUTOR_THIRD_PARTY
                ? ($data['third_party_courier_name'] ?? null)
                : null,
            'third_party_waybill' => $executorType === Job::EXECUTOR_THIRD_PARTY
                ? ($data['third_party_waybill'] ?? null)
                : null,
            'third_party_expected_date' => $executorType === Job::EXECUTOR_THIRD_PARTY
                ? ($data['third_party_expected_date'] ?? null)
                : null,
            'self_collect_name' => $executorType === Job::EXECUTOR_SELF_COLLECT
                ? ($data['self_collect_name'] ?? null)
                : null,
            'self_collect_phone' => $executorType === Job::EXECUTOR_SELF_COLLECT
                ? ($data['self_collect_phone'] ?? null)
                : null,
            'self_collect_id_number' => $executorType === Job::EXECUTOR_SELF_COLLECT
                ? ($data['self_collect_id_number'] ?? null)
                : null,
            'owner_company_id' => $ownerFields['owner_company_id'],
            'requires_owner_approval' => $ownerFields['requires_owner_approval'],
            'owner_approval_status' => $ownerFields['owner_approval_status'],
        ]);

        // Zone-rate pricing is ProSelver's commercial pricing — it
        // only applies when ProSelver is actually executing the move.
        // Internal / 3rd-party / self-collect skip pricing entirely
        // (the dealer's own driver / courier / end-customer isn't
        // being billed by us); distance_km still gets set for the
        // benefit of dealer-side reports that show route distance.
        if ($executorType === Job::EXECUTOR_PROSELVER) {
            $this->calculateAndStoreRoute($job);
        } else {
            $this->calculateAndStoreDistanceOnly($job);
        }

        // When we auto-skipped the manual Confirm step, leave a breadcrumb
        // on the order's event timeline so anyone reviewing the audit
        // trail later can see the order didn't sit at RECEIVED waiting
        // for a click that never came — it was always meant to land at
        // CONFIRMED. The text mirrors what the order page would show.
        //
        // job_events.user_id is NOT NULL (->constrained() without
        // ->nullable()), so we only write the breadcrumb when we have
        // a real actor. Every legitimate booking path passes
        // created_by_user_id, so this is defensive against an unknown
        // caller — better to lose the audit row than 500 the booking.
        if ($initialStatus === Job::STATUS_CONFIRMED && !empty($data['created_by_user_id'])) {
            JobEvent::create([
                'job_id'     => $job->id,
                'event_type' => 'auto_confirmed_on_create',
                'event_at'   => now(),
                'user_id'    => $data['created_by_user_id'],
                'notes'      => sprintf(
                    'Auto-confirmed: %s executor — no ProSelver dispatch handshake needed.',
                    Job::EXECUTOR_LABELS[$executorType] ?? $executorType,
                ),
            ]);
        }

        return $job;
    }

    /**
     * Create multiple transport bookings sharing the same route, date, and customer notes.
     * $vehicles is an array of ['vin' => ..., 'model_name' => ..., 'brand_id' => ..., 'registration' => ...]
     * Returns a collection of the created Jobs.
     */
    public function createTransportBookingBatch(array $common, array $vehicles): \Illuminate\Support\Collection
    {
        $jobs = collect();

        foreach ($vehicles as $vehicle) {
            $payload = array_merge($common, [
                'vin' => $vehicle['vin'],
                'model_name' => $vehicle['model_name'] ?? null,
                'brand_id' => $vehicle['brand_id'] ?? ($common['brand_id'] ?? null),
                'registration' => $vehicle['registration'] ?? null,
            ]);

            $jobs->push($this->createTransportBooking($payload));
        }

        return $jobs;
    }

    public function createYardBooking(array $data): Job
    {
        $hourlyRate = SystemSetting::get('yard_hourly_rate', 250);

        $job = Job::create([
            'job_number' => $this->numberGenerator->generate(),
            'job_type' => Job::TYPE_YARD_WORK,
            // Yard work is always Proselver crew on Proselver premises — no
            // non-Proselver executor option exists for it — so it always
            // routes via the standard RECEIVED → CONFIRMED flow.
            'status' => Job::initialStatusFor(
                Job::EXECUTOR_PROSELVER,
                $this->companyWorkflowType($data['company_id'] ?? null),
                (bool) ($data['bypass_po_verification'] ?? false),
            ),
            'company_id' => $data['company_id'],
            'created_by_user_id' => $data['created_by_user_id'],
            'yard_location_id' => $data['yard_location_id'],
            'scheduled_date' => $data['scheduled_date'] ?? now()->toDateString(),
            'drivers_required' => $data['drivers_required'],
            'hours_required' => $data['hours_required'],
            'hourly_rate' => $data['hourly_rate'] ?? $hourlyRate,
            'po_number' => $data['po_number'] ?? null,
            'po_amount' => $data['po_amount'] ?? null,
        ]);

        $job->calculateFinancials();
        $job->save();

        return $job;
    }

    /**
     * Look up a company's workflow_type, or NULL if the booking isn't
     * scoped to a company yet. Extracted so both Transport and Yard
     * creation paths share the same lookup and Job::initialStatusFor()
     * gets a consistent input.
     */
    protected function companyWorkflowType(?int $companyId): ?string
    {
        return $companyId
            ? Company::whereKey($companyId)->value('workflow_type')
            : null;
    }

    /**
     * Decide whether this booking needs the vehicle-owner's blessing
     * before it can dispatch -- and if so, who that owner is.
     *
     * Triggered when ANYONE other than the dealer that owns the VIN
     * places the order (typically a body builder placing a direct
     * order with Proselver to ship a finished build elsewhere).  The
     * owner_company_id is taken from the dealer_stock row that the
     * VIN matches; if no match exists the gate is off (it's a brand
     * new vehicle that the platform hasn't seen before, e.g. an
     * OEM-direct supply order placed by the BB).
     *
     * Callers can also pass an explicit owner_company_id to override
     * the VIN lookup (used by direct admin edits / scripts).
     *
     * @return array{owner_company_id: ?int, requires_owner_approval: bool, owner_approval_status: ?string}
     */
    protected function resolveOwnerApprovalFields(
        int $customerCompanyId,
        string $vin,
        string $registration = '',
        ?int $explicitOwnerCompanyId = null,
    ): array {
        $ownerCompanyId = $explicitOwnerCompanyId;

        // Match on EITHER identifier so a reg-only booking still
        // trips the gate.  We try VIN first (it's the canonical
        // matching key on dealer_stock and its unique index makes
        // the lookup cheapest), then fall back to registration.
        if ($ownerCompanyId === null && $vin !== '') {
            $ownerCompanyId = DealerStock::query()
                ->whereRaw('UPPER(vin) = ?', [strtoupper(trim($vin))])
                ->whereNotNull('dealer_company_id')
                ->whereNull('archived_at')
                ->value('dealer_company_id');
        }
        if ($ownerCompanyId === null && $registration !== '') {
            $ownerCompanyId = DealerStock::query()
                ->whereRaw('UPPER(COALESCE(registration, \'\')) = ?', [strtoupper(trim($registration))])
                ->whereNotNull('dealer_company_id')
                ->whereNull('archived_at')
                ->value('dealer_company_id');
        }

        // Same tenant placing the order on their own vehicle -- no
        // owner gate (they ARE the owner).
        if ($ownerCompanyId === null || (int) $ownerCompanyId === $customerCompanyId) {
            return [
                'owner_company_id' => null,
                'requires_owner_approval' => false,
                'owner_approval_status' => null,
            ];
        }

        return [
            'owner_company_id' => (int) $ownerCompanyId,
            'requires_owner_approval' => true,
            'owner_approval_status' => Job::OWNER_APPROVAL_PENDING,
        ];
    }

    protected function calculateAndStoreRoute(Job $job): void
    {
        $pickup = Location::find($job->pickup_location_id);
        $delivery = Location::find($job->delivery_location_id);

        if (!$pickup?->zone_id || !$delivery?->zone_id || !$job->vehicle_class_id) {
            return;
        }

        $rate = ZoneRate::findRate($pickup->zone_id, $delivery->zone_id, $job->vehicle_class_id);
        if (!$rate) {
            return;
        }

        $multiplier = $job->is_round_trip ? 2 : 1;

        $job->distance_km = round($rate->distance_km * $multiplier, 2);
        $job->save();
    }

    /**
     * Stamp distance_km for jobs ProSelver isn't pricing. The dealer
     * still wants to see route length in their stock + reports views
     * even when their own driver / courier is handling the move, so
     * we honour the same zone-rate lookup but stop short of writing
     * any of the financial columns.
     */
    protected function calculateAndStoreDistanceOnly(Job $job): void
    {
        $pickup = Location::find($job->pickup_location_id);
        $delivery = Location::find($job->delivery_location_id);

        if (!$pickup?->zone_id || !$delivery?->zone_id || !$job->vehicle_class_id) {
            return;
        }

        $rate = ZoneRate::findRate($pickup->zone_id, $delivery->zone_id, $job->vehicle_class_id);
        if (!$rate) {
            return;
        }

        $multiplier = $job->is_round_trip ? 2 : 1;

        $job->distance_km = round($rate->distance_km * $multiplier, 2);
        $job->save();
    }

    /**
     * Preview route distance/price from zone rates (for booking form preview).
     */
    public static function previewRoute(int $pickupId, int $deliveryId, int $vehicleClassId, bool $isRoundTrip = false): ?array
    {
        $pickup = Location::find($pickupId);
        $delivery = Location::find($deliveryId);

        if (!$pickup?->zone_id || !$delivery?->zone_id) {
            return null;
        }

        $rate = ZoneRate::findRate($pickup->zone_id, $delivery->zone_id, $vehicleClassId);
        if (!$rate) {
            return null;
        }

        $multiplier = $isRoundTrip ? 2 : 1;

        return [
            'distance_km' => round($rate->distance_km * $multiplier, 2),
            'price' => round($rate->price * $multiplier, 2),
            'origin_zone' => $rate->originZone->name,
            'destination_zone' => $rate->destinationZone->name,
        ];
    }
}
