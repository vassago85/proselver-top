<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['name' => 'Super Admin', 'slug' => 'super_admin', 'tier' => 'internal', 'description' => 'Full system control, pricing, overrides, margin dashboards'],
            ['name' => 'Ops Manager', 'slug' => 'ops_manager', 'tier' => 'internal', 'description' => 'Approve bookings, assign drivers, override delays, scheduling'],
            ['name' => 'Dispatcher', 'slug' => 'dispatcher', 'tier' => 'internal', 'description' => 'Assign drivers, update job status. No pricing control.'],
            ['name' => 'Accounts', 'slug' => 'accounts', 'tier' => 'internal', 'description' => 'Generate invoices, view financial dashboards, apply credit notes'],
            ['name' => 'Dealer Admin', 'slug' => 'dealer_admin', 'tier' => 'dealer', 'description' => 'Full company access, book transport/yard, manage dealership users'],
            ['name' => 'Dealer Scheduler', 'slug' => 'dealer_scheduler', 'tier' => 'dealer', 'description' => 'Create/edit bookings, adjust scheduled ready time'],
            ['name' => 'Dealer Accounts', 'slug' => 'dealer_accounts', 'tier' => 'dealer', 'description' => 'View invoices, download POD'],
            ['name' => 'Dealer Viewer', 'slug' => 'dealer_viewer', 'tier' => 'dealer', 'description' => 'Read-only job and performance view'],
            ['name' => 'Driver', 'slug' => 'driver', 'tier' => 'driver', 'description' => 'View assigned jobs, log events, upload documents, scan POD'],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(['slug' => $role['slug']], $role);
        }
    }
}
