<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        $now = now();

        $newPerms = [
            ['name' => 'View All Bookings', 'slug' => 'view_all_bookings', 'group' => 'Bookings', 'description' => 'View all company bookings'],
            ['name' => 'View Own Bookings', 'slug' => 'view_own_bookings', 'group' => 'Bookings', 'description' => 'View only bookings you created'],
            ['name' => 'Edit All Bookings', 'slug' => 'edit_all_bookings', 'group' => 'Bookings', 'description' => 'Edit or change any company booking'],
            ['name' => 'Edit Own Bookings', 'slug' => 'edit_own_bookings', 'group' => 'Bookings', 'description' => 'Edit or change only your own bookings'],
            ['name' => 'Upload Documents', 'slug' => 'upload_documents', 'group' => 'Documents', 'description' => 'Upload POs and documents to bookings'],
        ];

        foreach ($newPerms as $perm) {
            DB::table('permissions')->insertOrIgnore(array_merge($perm, [
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }

        $viewBookingsPerm = DB::table('permissions')->where('slug', 'view_bookings')->first();
        $uploadPoPerm = DB::table('permissions')->where('slug', 'upload_po')->first();
        $viewAllId = DB::table('permissions')->where('slug', 'view_all_bookings')->value('id');
        $viewOwnId = DB::table('permissions')->where('slug', 'view_own_bookings')->value('id');
        $editAllId = DB::table('permissions')->where('slug', 'edit_all_bookings')->value('id');
        $editOwnId = DB::table('permissions')->where('slug', 'edit_own_bookings')->value('id');
        $uploadDocsId = DB::table('permissions')->where('slug', 'upload_documents')->value('id');

        if ($viewBookingsPerm) {
            $roleIds = DB::table('role_permissions')
                ->where('permission_id', $viewBookingsPerm->id)
                ->pluck('role_id');

            foreach ($roleIds as $roleId) {
                DB::table('role_permissions')->insertOrIgnore([
                    ['role_id' => $roleId, 'permission_id' => $viewAllId],
                    ['role_id' => $roleId, 'permission_id' => $viewOwnId],
                    ['role_id' => $roleId, 'permission_id' => $editAllId],
                    ['role_id' => $roleId, 'permission_id' => $editOwnId],
                ]);
            }
        }

        if ($uploadPoPerm && $uploadDocsId) {
            $roleIds = DB::table('role_permissions')
                ->where('permission_id', $uploadPoPerm->id)
                ->pluck('role_id');

            foreach ($roleIds as $roleId) {
                DB::table('role_permissions')->insertOrIgnore([
                    ['role_id' => $roleId, 'permission_id' => $uploadDocsId],
                ]);
            }
        }

        // Clone global dealer/OEM roles into each company that has users
        $dealerTiers = ['dealer', 'oem'];

        foreach ($dealerTiers as $tier) {
            $globalRoles = DB::table('roles')
                ->where('tier', $tier)
                ->whereNull('company_id')
                ->get();

            if ($globalRoles->isEmpty()) {
                continue;
            }

            $companyIds = DB::table('company_users')
                ->distinct()
                ->pluck('company_id');

            foreach ($companyIds as $companyId) {
                $company = DB::table('companies')->find($companyId);
                if (!$company) continue;

                $companyType = $company->type ?? null;
                if ($companyType && $companyType !== $tier) continue;

                foreach ($globalRoles as $globalRole) {
                    $newRole = DB::table('roles')->insertGetId([
                        'company_id' => $companyId,
                        'name' => $globalRole->name,
                        'slug' => $globalRole->slug . '_company_' . $companyId,
                        'tier' => $globalRole->tier,
                        'description' => $globalRole->description,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    $globalPermIds = DB::table('role_permissions')
                        ->where('role_id', $globalRole->id)
                        ->pluck('permission_id');

                    $permInserts = $globalPermIds->map(fn($pid) => [
                        'role_id' => $newRole,
                        'permission_id' => $pid,
                    ])->toArray();

                    if (!empty($permInserts)) {
                        DB::table('role_permissions')->insert($permInserts);
                    }

                    $companyUserIds = DB::table('company_users')
                        ->where('company_id', $companyId)
                        ->pluck('user_id');

                    if ($companyUserIds->isNotEmpty()) {
                        DB::table('user_roles')
                            ->where('role_id', $globalRole->id)
                            ->whereIn('user_id', $companyUserIds)
                            ->update(['role_id' => $newRole]);
                    }
                }
            }
        }
    }

    public function down(): void
    {
        $companyRoles = DB::table('roles')->whereNotNull('company_id')->get();

        foreach ($companyRoles as $role) {
            $originalSlug = preg_replace('/_company_\d+$/', '', $role->slug);
            $globalRole = DB::table('roles')
                ->where('slug', $originalSlug)
                ->whereNull('company_id')
                ->first();

            if ($globalRole) {
                DB::table('user_roles')
                    ->where('role_id', $role->id)
                    ->update(['role_id' => $globalRole->id]);
            }

            DB::table('role_permissions')->where('role_id', $role->id)->delete();
            DB::table('roles')->where('id', $role->id)->delete();
        }

        $newSlugs = ['view_all_bookings', 'view_own_bookings', 'edit_all_bookings', 'edit_own_bookings', 'upload_documents'];
        $newPermIds = DB::table('permissions')->whereIn('slug', $newSlugs)->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $newPermIds)->delete();
        DB::table('permissions')->whereIn('slug', $newSlugs)->delete();

        Schema::table('roles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
        });
    }
};
