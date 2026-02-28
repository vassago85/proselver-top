<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            ['name' => 'View Bookings', 'slug' => 'view_bookings', 'group' => 'Bookings', 'description' => 'View booking list and details'],
            ['name' => 'Submit Booking', 'slug' => 'submit_booking', 'group' => 'Bookings', 'description' => 'Create new booking requests'],
            ['name' => 'Approve Booking', 'slug' => 'approve_booking', 'group' => 'Bookings', 'description' => 'Approve or reject booking requests'],
            ['name' => 'Cancel Booking', 'slug' => 'cancel_booking', 'group' => 'Bookings', 'description' => 'Cancel existing bookings'],

            ['name' => 'View Stock', 'slug' => 'view_stock', 'group' => 'Stock & Movements', 'description' => 'View vehicle stock and status'],
            ['name' => 'Manage Movements', 'slug' => 'manage_movements', 'group' => 'Stock & Movements', 'description' => 'Create and manage vehicle movements'],
            ['name' => 'View Movement Overview', 'slug' => 'view_movement_overview', 'group' => 'Stock & Movements', 'description' => 'View all movements across the dealership'],

            ['name' => 'View Purchase Orders', 'slug' => 'view_po', 'group' => 'Purchase Orders', 'description' => 'View purchase orders'],
            ['name' => 'Generate Purchase Order', 'slug' => 'generate_po', 'group' => 'Purchase Orders', 'description' => 'Generate new purchase orders'],
            ['name' => 'Upload Purchase Order', 'slug' => 'upload_po', 'group' => 'Purchase Orders', 'description' => 'Upload purchase order documents'],

            ['name' => 'View Invoices', 'slug' => 'view_invoices', 'group' => 'Invoices', 'description' => 'View invoice list and details'],

            ['name' => 'View Performance', 'slug' => 'view_performance', 'group' => 'Performance', 'description' => 'View performance reports and metrics'],

            ['name' => 'Manage Dealer Users', 'slug' => 'manage_dealer_users', 'group' => 'Administration', 'description' => 'Add and manage users within the dealership'],
        ];

        foreach ($permissions as $perm) {
            Permission::updateOrCreate(['slug' => $perm['slug']], $perm);
        }

        $allPerms = Permission::pluck('id', 'slug');

        $rolePermissions = [
            'dealer_principal' => $allPerms->keys()->toArray(),
            'sales_manager_new' => ['view_bookings', 'submit_booking', 'approve_booking', 'cancel_booking', 'view_stock', 'generate_po', 'view_po', 'view_invoices', 'view_performance', 'manage_dealer_users'],
            'sales_manager_used' => ['view_bookings', 'submit_booking', 'approve_booking', 'cancel_booking', 'view_stock', 'generate_po', 'view_po', 'view_invoices', 'view_performance', 'manage_dealer_users'],
            'sales_person_new' => ['view_bookings', 'submit_booking', 'view_stock'],
            'sales_person_used' => ['view_bookings', 'submit_booking', 'view_stock'],
            'stock_controller' => ['view_bookings', 'view_stock', 'manage_movements', 'view_movement_overview', 'generate_po', 'upload_po', 'view_po'],
            'oem_admin' => $allPerms->keys()->toArray(),
            'oem_planner' => ['view_bookings', 'submit_booking', 'approve_booking', 'cancel_booking', 'view_stock', 'manage_movements', 'view_movement_overview', 'generate_po', 'upload_po', 'view_po', 'view_invoices'],
        ];

        foreach ($rolePermissions as $roleSlug => $permSlugs) {
            $role = Role::where('slug', $roleSlug)->first();
            if ($role) {
                $permIds = $allPerms->only($permSlugs)->values()->toArray();
                $role->permissions()->sync($permIds);
            }
        }
    }
}
