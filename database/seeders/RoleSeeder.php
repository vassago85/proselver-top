<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            // Internal roles
            ['name' => 'Developer', 'slug' => 'developer', 'tier' => 'internal', 'description' => 'Full access, impersonation, debug tools, role preview'],
            ['name' => 'Owner', 'slug' => 'owner', 'tier' => 'internal', 'description' => 'High-level business visibility, read-heavy'],
            ['name' => 'Super Admin', 'slug' => 'super_admin', 'tier' => 'internal', 'description' => 'Full system control, pricing, overrides, margin dashboards'],
            ['name' => 'Operations Controller', 'slug' => 'operations_controller', 'tier' => 'internal', 'description' => 'Receives orders, confirms readiness, plans movements, allocates drivers, generates docs'],
            ['name' => 'Dispatcher', 'slug' => 'dispatcher', 'tier' => 'internal', 'description' => 'Updates statuses, uploads docs, supports operational movement handling'],

            // Legacy internal roles (kept for migration compatibility)
            ['name' => 'Ops Manager', 'slug' => 'ops_manager', 'tier' => 'internal', 'description' => '[Legacy] Approve bookings, assign drivers, override delays, scheduling'],
            ['name' => 'Accounts', 'slug' => 'accounts', 'tier' => 'internal', 'description' => '[Legacy] Generate invoices, view financial dashboards, apply credit notes'],

            // Customer roles (unified tier replacing dealer + OEM)
            ['name' => 'Customer Owner', 'slug' => 'customer_owner', 'tier' => 'customer', 'description' => 'Full company access'],
            ['name' => 'Customer Admin', 'slug' => 'customer_admin', 'tier' => 'customer', 'description' => 'Manage users, view all orders'],
            ['name' => 'Customer User', 'slug' => 'customer_user', 'tier' => 'customer', 'description' => 'Submit and view own orders'],
            ['name' => 'Customer Dispatcher', 'slug' => 'customer_dispatcher', 'tier' => 'customer', 'description' => 'Confirm readiness for FAW-type workflows'],

            // Body-builder roles (live on customer-tier so they can use
            // the same auth + tenancy plumbing).  A BB tenant is an
            // independent company that a dealer authorises via
            // body_builder_dealer_links; their users see only inbound
            // / on-site jobs from linked dealers and can raise movement
            // requests that the dealer must approve.
            ['name' => 'Body Builder Owner', 'slug' => 'body_builder_owner', 'tier' => 'customer', 'description' => 'Manage the body-builder company, locations, team, and confirm receipts / raise requests'],
            ['name' => 'Body Builder User',  'slug' => 'body_builder_user',  'tier' => 'customer', 'description' => 'Confirm vehicle receipts and raise next-move / collection requests'],

            // Legacy dealer roles (kept for migration compatibility)
            ['name' => 'Dealer Principal', 'slug' => 'dealer_principal', 'tier' => 'dealer', 'description' => '[Legacy] Full dealership access and user management'],
            ['name' => 'Sales Manager (New)', 'slug' => 'sales_manager_new', 'tier' => 'dealer', 'description' => '[Legacy] Manages new vehicle sales team and bookings'],
            ['name' => 'Sales Manager (Used)', 'slug' => 'sales_manager_used', 'tier' => 'dealer', 'description' => '[Legacy] Manages used vehicle sales team and bookings'],
            ['name' => 'Sales Person (New)', 'slug' => 'sales_person_new', 'tier' => 'dealer', 'description' => '[Legacy] Submits new vehicle movement requests'],
            ['name' => 'Sales Person (Used)', 'slug' => 'sales_person_used', 'tier' => 'dealer', 'description' => '[Legacy] Submits used vehicle movement requests'],
            ['name' => 'Stock Controller', 'slug' => 'stock_controller', 'tier' => 'dealer', 'description' => '[Legacy] Oversees all vehicle movements and PO management'],

            // Legacy OEM roles (kept for migration compatibility)
            ['name' => 'OEM Admin', 'slug' => 'oem_admin', 'tier' => 'oem', 'description' => '[Legacy] Full OEM access, bookings, POs, scheduling, user management'],
            ['name' => 'OEM Planner', 'slug' => 'oem_planner', 'tier' => 'oem', 'description' => '[Legacy] Plan and schedule vehicle movements, manage bookings and POs'],

            // Driver
            ['name' => 'Driver', 'slug' => 'driver', 'tier' => 'driver', 'description' => 'View assigned jobs, log events, upload documents'],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(['slug' => $role['slug']], $role);
        }
    }
}
