<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            // Internal (hardcoded)
            ['name' => 'Super Admin', 'slug' => 'super_admin', 'tier' => 'internal', 'description' => 'Full system control, pricing, overrides, margin dashboards'],
            ['name' => 'Ops Manager', 'slug' => 'ops_manager', 'tier' => 'internal', 'description' => 'Approve bookings, assign drivers, override delays, scheduling'],
            ['name' => 'Dispatcher', 'slug' => 'dispatcher', 'tier' => 'internal', 'description' => 'Assign drivers, update job status. No pricing control.'],
            ['name' => 'Accounts', 'slug' => 'accounts', 'tier' => 'internal', 'description' => 'Generate invoices, view financial dashboards, apply credit notes'],

            // Dealer (dynamic, managed via Settings > Roles & Permissions)
            ['name' => 'Dealer Principal', 'slug' => 'dealer_principal', 'tier' => 'dealer', 'description' => 'Full dealership access and user management'],
            ['name' => 'Sales Manager (New)', 'slug' => 'sales_manager_new', 'tier' => 'dealer', 'description' => 'Manages new vehicle sales team and bookings'],
            ['name' => 'Sales Manager (Used)', 'slug' => 'sales_manager_used', 'tier' => 'dealer', 'description' => 'Manages used vehicle sales team and bookings'],
            ['name' => 'Sales Person (New)', 'slug' => 'sales_person_new', 'tier' => 'dealer', 'description' => 'Submits new vehicle movement requests'],
            ['name' => 'Sales Person (Used)', 'slug' => 'sales_person_used', 'tier' => 'dealer', 'description' => 'Submits used vehicle movement requests'],
            ['name' => 'Stock Controller', 'slug' => 'stock_controller', 'tier' => 'dealer', 'description' => 'Oversees all vehicle movements and PO management'],

            // OEM (dynamic, managed via Settings > Roles & Permissions)
            ['name' => 'OEM Admin', 'slug' => 'oem_admin', 'tier' => 'oem', 'description' => 'Full OEM access, bookings, POs, scheduling, user management'],
            ['name' => 'OEM Planner', 'slug' => 'oem_planner', 'tier' => 'oem', 'description' => 'Plan and schedule vehicle movements, manage bookings and POs'],

            // Driver
            ['name' => 'Driver', 'slug' => 'driver', 'tier' => 'driver', 'description' => 'View assigned jobs, log events, upload documents, scan POD'],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(['slug' => $role['slug']], $role);
        }
    }
}
