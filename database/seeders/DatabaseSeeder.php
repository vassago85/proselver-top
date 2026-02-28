<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            PermissionSeeder::class,
            BrandSeeder::class,
            BodyTypeSeeder::class,
            VehicleClassSeeder::class,
            TollPlazaSeeder::class,
            SystemSettingSeeder::class,
            ZoneSeeder::class,
            SuperAdminSeeder::class,
        ]);

        // Booking cutoff defaults
        \App\Models\SystemSetting::set('collection_cutoff_mode', 'hours_before', 'string', 'Cutoff mode: hours_before or day_before_at_time');
        \App\Models\SystemSetting::set('collection_cutoff_hours', '24', 'integer', 'Hours before collection for cutoff');
        \App\Models\SystemSetting::set('collection_cutoff_days', '1', 'integer', 'Days before collection date for cutoff');
        \App\Models\SystemSetting::set('collection_cutoff_time', '15:00', 'string', 'Time on cutoff day');

        if (app()->environment('local', 'development', 'testing')) {
            $this->call(DemoSeeder::class);
        }
    }
}
