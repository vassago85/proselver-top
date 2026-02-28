<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $dealerRoles = [
            ['name' => 'Dealer Principal', 'slug' => 'dealer_principal', 'tier' => 'dealer', 'description' => 'Full dealership access and user management'],
            ['name' => 'Sales Manager (New)', 'slug' => 'sales_manager_new', 'tier' => 'dealer', 'description' => 'Manages new vehicle sales team and bookings'],
            ['name' => 'Sales Manager (Used)', 'slug' => 'sales_manager_used', 'tier' => 'dealer', 'description' => 'Manages used vehicle sales team and bookings'],
            ['name' => 'Sales Person (New)', 'slug' => 'sales_person_new', 'tier' => 'dealer', 'description' => 'Submits new vehicle movement requests'],
            ['name' => 'Sales Person (Used)', 'slug' => 'sales_person_used', 'tier' => 'dealer', 'description' => 'Submits used vehicle movement requests'],
            ['name' => 'Stock Controller', 'slug' => 'stock_controller', 'tier' => 'dealer', 'description' => 'Oversees all vehicle movements and PO management'],
        ];

        $now = now();

        foreach ($dealerRoles as $role) {
            DB::table('roles')->updateOrInsert(
                ['slug' => $role['slug']],
                array_merge($role, ['created_at' => $now, 'updated_at' => $now])
            );
        }

        $oldSlugs = ['dealer_admin', 'dealer_scheduler', 'dealer_accounts', 'dealer_viewer'];
        $existingOld = DB::table('roles')->whereIn('slug', $oldSlugs)->pluck('id', 'slug');

        if ($existingOld->isNotEmpty()) {
            $principalRole = DB::table('roles')->where('slug', 'dealer_principal')->first();

            if ($principalRole) {
                $userIds = DB::table('user_roles')
                    ->whereIn('role_id', $existingOld->values())
                    ->pluck('user_id')
                    ->unique();

                foreach ($userIds as $userId) {
                    DB::table('user_roles')->updateOrInsert(
                        ['user_id' => $userId, 'role_id' => $principalRole->id],
                        ['created_at' => $now, 'updated_at' => $now]
                    );
                }

                DB::table('user_roles')->whereIn('role_id', $existingOld->values())->delete();
            }

            DB::table('roles')->whereIn('slug', $oldSlugs)->delete();
        }
    }

    public function down(): void
    {
        $now = now();

        $oldRoles = [
            ['name' => 'Dealer Admin', 'slug' => 'dealer_admin', 'tier' => 'dealer', 'description' => 'Full dealer access'],
            ['name' => 'Dealer Scheduler', 'slug' => 'dealer_scheduler', 'tier' => 'dealer', 'description' => 'Can schedule bookings'],
            ['name' => 'Dealer Accounts', 'slug' => 'dealer_accounts', 'tier' => 'dealer', 'description' => 'View invoices and financials'],
            ['name' => 'Dealer Viewer', 'slug' => 'dealer_viewer', 'tier' => 'dealer', 'description' => 'Read-only dealer access'],
        ];

        foreach ($oldRoles as $role) {
            DB::table('roles')->updateOrInsert(
                ['slug' => $role['slug']],
                array_merge($role, ['created_at' => $now, 'updated_at' => $now])
            );
        }

        $newSlugs = ['dealer_principal', 'sales_manager_new', 'sales_manager_used', 'sales_person_new', 'sales_person_used', 'stock_controller'];
        DB::table('roles')->whereIn('slug', $newSlugs)->delete();
    }
};
