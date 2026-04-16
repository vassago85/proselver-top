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
            ['name' => 'View Bookings', 'slug' => 'view_bookings', 'group' => 'Bookings', 'description' => 'View booking list and details (legacy)'],
            ['name' => 'View All Bookings', 'slug' => 'view_all_bookings', 'group' => 'Bookings', 'description' => 'View all company bookings'],
            ['name' => 'View Own Bookings', 'slug' => 'view_own_bookings', 'group' => 'Bookings', 'description' => 'View only bookings you created'],
            ['name' => 'Edit All Bookings', 'slug' => 'edit_all_bookings', 'group' => 'Bookings', 'description' => 'Edit or change any company booking'],
            ['name' => 'Edit Own Bookings', 'slug' => 'edit_own_bookings', 'group' => 'Bookings', 'description' => 'Edit or change only your own bookings'],
            ['name' => 'Submit Booking', 'slug' => 'submit_booking', 'group' => 'Bookings', 'description' => 'Create new booking requests'],
            ['name' => 'Approve Booking', 'slug' => 'approve_booking', 'group' => 'Bookings', 'description' => 'Approve or reject booking requests'],
            ['name' => 'Cancel Booking', 'slug' => 'cancel_booking', 'group' => 'Bookings', 'description' => 'Cancel existing bookings'],

            ['name' => 'Upload Documents', 'slug' => 'upload_documents', 'group' => 'Documents', 'description' => 'Upload POs and documents to bookings'],

            ['name' => 'View Stock', 'slug' => 'view_stock', 'group' => 'Stock & Movements', 'description' => 'View vehicle stock and status'],
            ['name' => 'Manage Movements', 'slug' => 'manage_movements', 'group' => 'Stock & Movements', 'description' => 'Create and manage vehicle movements'],
            ['name' => 'View Movement Overview', 'slug' => 'view_movement_overview', 'group' => 'Stock & Movements', 'description' => 'View all movements across the dealership'],

            ['name' => 'View Purchase Orders', 'slug' => 'view_po', 'group' => 'Purchase Orders', 'description' => 'View purchase orders'],
            ['name' => 'Generate Purchase Order', 'slug' => 'generate_po', 'group' => 'Purchase Orders', 'description' => 'Generate new purchase orders'],
            ['name' => 'Upload Purchase Order', 'slug' => 'upload_po', 'group' => 'Purchase Orders', 'description' => 'Upload purchase order documents'],

            ['name' => 'View Invoices', 'slug' => 'view_invoices', 'group' => 'Invoices', 'description' => 'View invoice list and details'],

            ['name' => 'View Performance', 'slug' => 'view_performance', 'group' => 'Performance', 'description' => 'View performance reports and metrics'],

            ['name' => 'Manage Users', 'slug' => 'manage_dealer_users', 'group' => 'Administration', 'description' => 'Add/edit users and manage roles within the company'],

            // Phase 1 permissions
            ['name' => 'Confirm Customer Order', 'slug' => 'confirm_customer_order', 'group' => 'Orders', 'description' => 'Confirm order readiness for FAW-type workflows'],
            ['name' => 'View Planning Queue', 'slug' => 'view_planning_queue', 'group' => 'Planning', 'description' => 'View the planning queue'],
            ['name' => 'Plan Orders', 'slug' => 'plan_orders', 'group' => 'Planning', 'description' => 'Plan confirmed orders for dispatch'],
            ['name' => 'Assign Drivers', 'slug' => 'assign_drivers', 'group' => 'Dispatch', 'description' => 'Assign drivers to planned orders'],
            ['name' => 'Generate Collection Note', 'slug' => 'generate_collection_note', 'group' => 'Documents', 'description' => 'Generate collection note PDFs'],
            ['name' => 'Manage Locations', 'slug' => 'manage_locations', 'group' => 'Locations', 'description' => 'Add/edit/deactivate locations'],
            ['name' => 'View Locations', 'slug' => 'view_locations', 'group' => 'Locations', 'description' => 'View location list'],
        ];

        foreach ($permissions as $perm) {
            Permission::updateOrCreate(['slug' => $perm['slug']], $perm);
        }

        $allPerms = Permission::pluck('id', 'slug');

        $fullAccess = [
            'view_all_bookings', 'view_own_bookings', 'edit_all_bookings', 'edit_own_bookings',
            'submit_booking', 'approve_booking', 'cancel_booking',
            'upload_documents',
            'view_stock', 'manage_movements', 'view_movement_overview',
            'view_po', 'generate_po', 'upload_po',
            'view_invoices', 'view_performance', 'manage_dealer_users',
        ];

        $rolePermissions = [
            'dealer_principal' => $fullAccess,
            'sales_manager_new' => ['view_all_bookings', 'view_own_bookings', 'edit_all_bookings', 'edit_own_bookings', 'submit_booking', 'approve_booking', 'cancel_booking', 'upload_documents', 'view_stock', 'generate_po', 'view_po', 'view_invoices', 'view_performance', 'manage_dealer_users'],
            'sales_manager_used' => ['view_all_bookings', 'view_own_bookings', 'edit_all_bookings', 'edit_own_bookings', 'submit_booking', 'approve_booking', 'cancel_booking', 'upload_documents', 'view_stock', 'generate_po', 'view_po', 'view_invoices', 'view_performance', 'manage_dealer_users'],
            'sales_person_new' => ['view_own_bookings', 'edit_own_bookings', 'submit_booking', 'upload_documents', 'view_stock'],
            'sales_person_used' => ['view_own_bookings', 'edit_own_bookings', 'submit_booking', 'upload_documents', 'view_stock'],
            'stock_controller' => ['view_all_bookings', 'view_own_bookings', 'view_stock', 'manage_movements', 'view_movement_overview', 'generate_po', 'upload_po', 'upload_documents', 'view_po'],
            'oem_admin' => $fullAccess,
            'oem_planner' => ['view_all_bookings', 'view_own_bookings', 'edit_all_bookings', 'edit_own_bookings', 'submit_booking', 'approve_booking', 'cancel_booking', 'upload_documents', 'view_stock', 'manage_movements', 'view_movement_overview', 'generate_po', 'upload_po', 'view_po', 'view_invoices'],
        ];

        // Phase 1 role-permission mappings
        $rolePermissions['customer_owner'] = $fullAccess;
        $rolePermissions['customer_admin'] = ['view_all_bookings', 'view_own_bookings', 'edit_all_bookings', 'submit_booking', 'cancel_booking', 'upload_documents', 'view_po', 'generate_po', 'upload_po', 'view_invoices', 'manage_dealer_users', 'manage_locations', 'view_locations'];
        $rolePermissions['customer_user'] = ['view_own_bookings', 'submit_booking', 'upload_documents', 'view_locations'];
        $rolePermissions['customer_dispatcher'] = ['view_all_bookings', 'view_own_bookings', 'confirm_customer_order', 'upload_documents', 'view_locations'];

        foreach ($rolePermissions as $roleSlug => $permSlugs) {
            $roles = Role::where('slug', $roleSlug)
                ->orWhere('slug', 'like', $roleSlug . '_company_%')
                ->get();

            foreach ($roles as $role) {
                $permIds = $allPerms->only($permSlugs)->values()->toArray();
                $role->permissions()->syncWithoutDetaching($permIds);
            }
        }
    }
}
