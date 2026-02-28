<?php

namespace App\Services;

use App\Models\Job;
use App\Models\SystemSetting;
use App\Models\TransportRoute;
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
            'scheduled_date' => now()->toDateString(),
            'scheduled_ready_time' => $data['scheduled_ready_time'] ?? null,
            'po_number' => $data['po_number'] ?? null,
            'po_amount' => $data['po_amount'] ?? null,
            'is_emergency' => $data['is_emergency'] ?? false,
            'emergency_reason' => $data['emergency_reason'] ?? null,
        ]);

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
            'scheduled_date' => now()->toDateString(),
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
}
