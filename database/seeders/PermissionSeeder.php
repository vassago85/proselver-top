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

            // Body-builder portal + dealer-side body-builder admin perms.
            ['name' => 'Confirm BB Receipt', 'slug' => 'bb_confirm_receipt', 'group' => 'Body Builder', 'description' => 'Mark a vehicle as received at the body builder'],
            ['name' => 'Raise BB Movement Request', 'slug' => 'bb_request_movement', 'group' => 'Body Builder', 'description' => 'Raise a next-fitment or collection request back to the dealer'],
            ['name' => 'Approve BB Movement Requests', 'slug' => 'dealer_approve_bb_requests', 'group' => 'Body Builder', 'description' => 'Approve or reject body-builder movement requests against your inventory'],
            ['name' => 'Manage BB Links', 'slug' => 'manage_bb_links', 'group' => 'Body Builder', 'description' => 'Link / pause / unlink authorised body builders for your dealership'],
            ['name' => 'Place BB Direct Order', 'slug' => 'bb_place_direct_order', 'group' => 'Body Builder', 'description' => 'Place a direct movement order with Proselver (BB is the paying customer; vehicle owner must approve)'],
            ['name' => 'Approve Owner Movement', 'slug' => 'owner_approve_movement', 'group' => 'Body Builder', 'description' => 'Approve / reject a movement raised by another tenant against a vehicle on your stock ledger'],

            // Phase 1 dealer stock ledger.
            ['name' => 'View Dealer Stock', 'slug' => 'view_dealer_stock', 'group' => 'Dealer Stock', 'description' => 'View the dealer stock ledger'],
            ['name' => 'Manage Dealer Stock', 'slug' => 'manage_dealer_stock', 'group' => 'Dealer Stock', 'description' => 'Import, mark-sold, send-on-demo, archive dealer stock'],
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

        // Extras that the modern customer-tier owner / admin / dispatcher
        // roles carry on top of $fullAccess. Re-used below so the LEGACY
        // dealer-tier / oem-tier roles converge to the SAME capability as
        // their customer-tier equivalent — a dealer_principal must be able
        // to do exactly what a re-skinned customer_owner can, otherwise the
        // two ways of creating "a dealer owner" behave differently. (Seeder
        // uses syncWithoutDetaching: this only ADDS, never strips.)
        $tenantOwnerExtras = [
            'confirm_customer_order', 'manage_locations', 'view_locations',
            'dealer_approve_bb_requests', 'manage_bb_links',
            'view_dealer_stock', 'manage_dealer_stock',
        ];
        $tenantAdminExtras = [
            'manage_locations', 'view_locations',
            'dealer_approve_bb_requests', 'manage_bb_links',
            'view_dealer_stock', 'manage_dealer_stock',
        ];

        $rolePermissions = [
            // dealer_principal == customer_owner (legacy → modern parity)
            'dealer_principal' => array_merge($fullAccess, $tenantOwnerExtras),
            'sales_manager_new' => array_merge(['view_all_bookings', 'view_own_bookings', 'edit_all_bookings', 'edit_own_bookings', 'submit_booking', 'approve_booking', 'cancel_booking', 'upload_documents', 'view_stock', 'generate_po', 'view_po', 'view_invoices', 'view_performance', 'manage_dealer_users'], $tenantAdminExtras),
            'sales_manager_used' => array_merge(['view_all_bookings', 'view_own_bookings', 'edit_all_bookings', 'edit_own_bookings', 'submit_booking', 'approve_booking', 'cancel_booking', 'upload_documents', 'view_stock', 'generate_po', 'view_po', 'view_invoices', 'view_performance', 'manage_dealer_users'], $tenantAdminExtras),
            'sales_person_new' => ['view_own_bookings', 'edit_own_bookings', 'submit_booking', 'upload_documents', 'view_stock', 'view_locations', 'view_dealer_stock'],
            'sales_person_used' => ['view_own_bookings', 'edit_own_bookings', 'submit_booking', 'upload_documents', 'view_stock', 'view_locations', 'view_dealer_stock'],
            'stock_controller' => ['view_all_bookings', 'view_own_bookings', 'view_stock', 'manage_movements', 'view_movement_overview', 'generate_po', 'upload_po', 'upload_documents', 'view_po', 'view_locations', 'view_dealer_stock', 'manage_dealer_stock'],
            // oem_admin == customer_owner (legacy → modern parity)
            'oem_admin' => array_merge($fullAccess, $tenantOwnerExtras),
            'oem_planner' => array_merge(['view_all_bookings', 'view_own_bookings', 'edit_all_bookings', 'edit_own_bookings', 'submit_booking', 'approve_booking', 'cancel_booking', 'upload_documents', 'view_stock', 'manage_movements', 'view_movement_overview', 'generate_po', 'upload_po', 'view_po', 'view_invoices'], ['confirm_customer_order', 'view_locations', 'dealer_approve_bb_requests']),
        ];

        // Phase 1 role-permission mappings (customer tier)
        // Dealer-tier customer roles gain the dealer-side BB approval +
        // link-management perms so they can authorise BBs and respond
        // to their requests; rank-and-file customer_user keeps the
        // existing minimal set.
        $rolePermissions['customer_owner'] = array_merge($fullAccess, [
            'confirm_customer_order', 'manage_locations', 'view_locations',
            'dealer_approve_bb_requests', 'manage_bb_links',
            'view_dealer_stock', 'manage_dealer_stock',
        ]);
        $rolePermissions['customer_admin'] = [
            'view_all_bookings', 'view_own_bookings', 'edit_all_bookings', 'submit_booking', 'cancel_booking',
            'upload_documents', 'view_po', 'generate_po', 'upload_po', 'view_invoices',
            'manage_dealer_users', 'manage_locations', 'view_locations',
            'dealer_approve_bb_requests', 'manage_bb_links',
            'view_dealer_stock', 'manage_dealer_stock',
        ];
        $rolePermissions['customer_user'] = ['view_own_bookings', 'submit_booking', 'upload_documents', 'view_locations'];
        $rolePermissions['customer_dispatcher'] = ['view_all_bookings', 'view_own_bookings', 'confirm_customer_order', 'upload_documents', 'view_locations', 'dealer_approve_bb_requests', 'view_dealer_stock'];

        // Body-builder-tier roles.  Owner can do everything inside the
        // BB tenant (manage users + locations + raise requests); user
        // can confirm and request but not edit the tenant itself.
        $rolePermissions['body_builder_owner'] = [
            'view_all_bookings', 'view_own_bookings', 'view_stock',
            'manage_dealer_users', 'manage_locations', 'view_locations',
            'bb_confirm_receipt', 'bb_request_movement', 'bb_place_direct_order',
            // BB owners also need to be able to view + manage the BB's
            // own outgoing direct orders.
            'submit_booking', 'edit_own_bookings', 'cancel_booking', 'upload_documents',
        ];
        $rolePermissions['body_builder_user'] = [
            'view_own_bookings', 'view_stock', 'view_locations',
            'bb_confirm_receipt', 'bb_request_movement',
        ];

        // Dealer-side counterpart to BB direct-order: dealers need to
        // be able to approve / reject movements raised by a BB against
        // their stock.  Default the new perm onto every dealer role
        // that already approves BB requests today, so the existing
        // role mappings stay self-consistent.
        foreach ([
            'dealer_principal',
            'sales_manager_new',
            'sales_manager_used',
            'stock_controller',
            'customer_admin',
            'customer_dispatcher',
        ] as $dealerRole) {
            if (isset($rolePermissions[$dealerRole])) {
                $rolePermissions[$dealerRole] = array_unique(array_merge($rolePermissions[$dealerRole], ['owner_approve_movement']));
            }
        }

        // Phase 1 role-permission mappings (internal tier)
        // Super Admin and Developer bypass permission checks in HasRoles, but we still assign
        // DB-level permissions so permission-driven UI gating stays consistent.
        $opsControllerPerms = [
            'view_all_bookings', 'view_own_bookings', 'edit_all_bookings',
            'submit_booking', 'approve_booking', 'cancel_booking',
            'upload_documents', 'view_po', 'upload_po', 'generate_po',
            'view_planning_queue', 'plan_orders', 'assign_drivers',
            'generate_collection_note',
            'manage_locations', 'view_locations',
            'manage_movements', 'view_movement_overview',
            'view_performance',
        ];
        $dispatcherPerms = [
            'view_all_bookings', 'view_own_bookings',
            'upload_documents', 'view_po',
            'view_planning_queue', 'assign_drivers',
            'generate_collection_note',
            'view_locations',
        ];
        $ownerPerms = [
            'view_all_bookings', 'view_own_bookings',
            'view_po', 'view_invoices', 'view_performance',
            'view_planning_queue', 'view_locations',
            'view_movement_overview',
        ];

        $rolePermissions['super_admin'] = array_merge($fullAccess, [
            'confirm_customer_order', 'view_planning_queue', 'plan_orders', 'assign_drivers',
            'generate_collection_note', 'manage_locations', 'view_locations',
            'dealer_approve_bb_requests', 'manage_bb_links',
            'view_dealer_stock', 'manage_dealer_stock',
        ]);
        $rolePermissions['developer'] = $rolePermissions['super_admin'];
        $rolePermissions['operations_controller'] = $opsControllerPerms;
        $rolePermissions['dispatcher'] = $dispatcherPerms;
        $rolePermissions['owner'] = $ownerPerms;

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
