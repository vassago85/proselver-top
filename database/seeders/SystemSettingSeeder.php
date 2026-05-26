<?php

namespace Database\Seeders;

use App\Models\SystemSetting;
use Illuminate\Database\Seeder;

class SystemSettingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            ['key' => 'next_day_cutoff_time', 'value' => '16:00', 'type' => 'string', 'description' => 'Cut-off time for next-day bookings (HH:MM)'],
            ['key' => 'cancellation_cutoff_time', 'value' => '16:00', 'type' => 'string', 'description' => 'Cut-off time for free cancellation (HH:MM, day before)'],
            ['key' => 'working_days', 'value' => '[1,2,3,4,5]', 'type' => 'json', 'description' => 'Working days (ISO: 1=Mon, 7=Sun)'],
            ['key' => 'yard_hourly_rate', 'value' => '250', 'type' => 'float', 'description' => 'Default yard work hourly rate (ZAR)'],
            ['key' => 'vat_rate', 'value' => '15', 'type' => 'float', 'description' => 'VAT rate percentage'],
            ['key' => 'min_monthly_jobs_for_discount', 'value' => '10', 'type' => 'integer', 'description' => 'Minimum eligible jobs per month for performance credit'],
            ['key' => 'min_accuracy_for_credit', 'value' => '90', 'type' => 'float', 'description' => 'Minimum accuracy percentage for performance credit'],
            ['key' => 'performance_credit_percent', 'value' => '3', 'type' => 'float', 'description' => 'Performance credit note percentage'],

            // Driver expiry notification settings
            ['key' => 'driver_license_expiry_warn_months', 'value' => '3', 'type' => 'integer', 'description' => 'Months before license expiry to start warnings'],
            ['key' => 'driver_pdp_expiry_warn_months', 'value' => '3', 'type' => 'integer', 'description' => 'Months before PDP expiry to start warnings'],
            ['key' => 'driver_expiry_notify_roles', 'value' => 'operations_controller,super_admin', 'type' => 'string', 'description' => 'Role slugs to notify on driver document expiries (comma-separated)'],

            // Driver advance / petty cash defaults.  Owner sets these.
            // Food allowance ladder (owner-stated, 2026-05-26):
            //   duration < food_minimum_hours        -> R 0
            //   food_minimum_hours .. threshold      -> 1 × per_day rate
            //   duration ≥ food_two_day_threshold    -> 2 × per_day rate
            // Ops can also waive food per trip via the advance modal.
            // Defaults: minimum 4h, 2-day threshold 9h, rate R150/day.
            // All three are read by App\Services\TripCostEstimator.
            ['key' => 'food_allowance_per_day', 'value' => '150', 'type' => 'float', 'description' => 'Daily food allowance per driver (ZAR).'],
            ['key' => 'food_minimum_hours', 'value' => '4', 'type' => 'integer', 'description' => 'Minimum trip duration (hours) below which no food allowance is paid.'],
            ['key' => 'food_two_day_threshold_hours', 'value' => '9', 'type' => 'integer', 'description' => 'Trip duration (hours) at or above which the trip counts as 2 days for food.'],
            // Standard taxi per trip (no slip required).  Applies to every
            // trip regardless of duration; ops can edit it down (or up)
            // in the advance modal.  Accommodation deliberately has NO
            // default -- it's irregular and owner explicitly rejected one.
            ['key' => 'taxi_allowance_per_trip', 'value' => '50', 'type' => 'float', 'description' => 'Standard taxi allowance per trip (ZAR). Receipt-not-required category.'],

            // Round-up rule: cash advances are paid in physical bills, so
            // the computed total gets rounded UP to the nearest multiple.
            // Owner stated R10 (Milton SK confirmed via WhatsApp 2026-05-26
            // "Close to the 10").  R73 -> R80, R437 -> R440.  Applied to
            // the FINAL total only; individual line items keep their
            // exact values so reconciliation against slips stays clean.
            ['key' => 'advance_round_up_to_multiple', 'value' => '10', 'type' => 'integer', 'description' => 'Round the computed advance UP to the nearest multiple of this ZAR amount (default 10). Drivers draw round amounts in cash.'],

            // Truck-speed adjustment for the Google Maps duration.
            // Google estimates car travel time (max ~120 km/h on freeway);
            // SA legal speeds are MCV 100 km/h, HCV/EHCV 80 km/h, so a
            // truck on the same route takes longer.  Multipliers are
            // applied to duration_minutes per toll class so the food
            // band (4h/9h thresholds) lands on the right rule.  Class 1
            // = light vehicle, no adjustment.
            ['key' => 'route_duration_multiplier_class_1', 'value' => '1.00', 'type' => 'float', 'description' => 'Multiplier applied to Google-estimated duration for SANRAL Class 1 (light) vehicles.'],
            ['key' => 'route_duration_multiplier_class_2', 'value' => '1.10', 'type' => 'float', 'description' => 'Multiplier for SANRAL Class 2 (2-axle truck / bus, 100 km/h limit).'],
            ['key' => 'route_duration_multiplier_class_3', 'value' => '1.25', 'type' => 'float', 'description' => 'Multiplier for SANRAL Class 3 (3-4 axle, 80 km/h limit).'],
            ['key' => 'route_duration_multiplier_class_4', 'value' => '1.30', 'type' => 'float', 'description' => 'Multiplier for SANRAL Class 4 (5+ axle / articulated, 80 km/h limit).'],

            // Slip-scan incentive — the owner pays drivers R N for every
            // approved petty-cash slip they submit, to encourage receipt
            // capture (and the VAT recovery that goes with it).  Default
            // is a displayed earnings counter; payroll acts on it manually
            // each pay cycle.  Only owner role may edit the rate.
            ['key' => 'slip_scan_incentive_enabled', 'value' => '1', 'type' => 'boolean', 'description' => 'Whether the slip-scan incentive scheme is active (drivers earn R per approved slip).'],
            ['key' => 'slip_scan_incentive_amount', 'value' => '5', 'type' => 'float', 'description' => 'ZAR earned per approved petty-cash slip. Owner only.'],
        ];

        foreach ($settings as $setting) {
            SystemSetting::updateOrCreate(['key' => $setting['key']], $setting);
        }
    }
}
