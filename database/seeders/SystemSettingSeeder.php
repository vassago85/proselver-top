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
        ];

        foreach ($settings as $setting) {
            SystemSetting::updateOrCreate(['key' => $setting['key']], $setting);
        }
    }
}
