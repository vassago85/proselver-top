<?php

namespace App\Services;

use App\Models\Job;
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

        $job = Job::create([
            'job_number' => $this->numberGenerator->generate(),
            'job_type' => Job::TYPE_TRANSPORT,
            'status' => Job::STATUS_PENDING_VERIFICATION,
            'company_id' => $data['company_id'],
            'created_by_user_id' => $data['created_by_user_id'],
            'transport_route_id' => $route->id,
            'pickup_location_id' => $data['pickup_location_id'],
            'pickup_contact_name' => $data['pickup_contact_name'] ?? null,
            'pickup_contact_phone' => $data['pickup_contact_phone'] ?? null,
            'delivery_location_id' => $data['delivery_location_id'],
            'delivery_contact_name' => $data['delivery_contact_name'] ?? null,
            'delivery_contact_phone' => $data['delivery_contact_phone'] ?? null,
            'vehicle_class_id' => $data['vehicle_class_id'],
            'brand_id' => $data['brand_id'] ?? null,
            'model_name' => $data['model_name'] ?? null,
            'vin' => $data['vin'],
            'registration' => $data['registration'] ?? null,
            'scheduled_date' => $data['scheduled_date'] ?? now()->toDateString(),
            'scheduled_ready_time' => $data['scheduled_ready_time'] ?? null,
            'po_number' => $data['po_number'] ?? null,
            'po_amount' => $data['po_amount'] ?? null,
            'is_emergency' => $data['is_emergency'] ?? false,
            'emergency_reason' => $data['emergency_reason'] ?? null,
            'is_round_trip' => $data['is_round_trip'] ?? false,
        ]);

        $this->calculateAndStoreRoute($job);

        return $job;
    }

    public function createYardBooking(array $data): Job
    {
        $hourlyRate = SystemSetting::get('yard_hourly_rate', 250);

        $job = Job::create([
            'job_number' => $this->numberGenerator->generate(),
            'job_type' => Job::TYPE_YARD_WORK,
            'status' => Job::STATUS_PENDING_VERIFICATION,
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
