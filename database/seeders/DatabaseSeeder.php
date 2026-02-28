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
            SystemSettingSeeder::class,
            SuperAdminSeeder::class,
        ]);

        if (app()->environment('local', 'development', 'testing')) {
            $this->call(DemoSeeder::class);
        }
    }
}
