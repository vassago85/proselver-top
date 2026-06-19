<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A user can belong to several companies (group-principal franchise CEOs
 * attached to every dealership in their group). Until now "which one is
 * their home company" was whatever companies()->first() happened to
 * return — non-deterministic, so the portal label / branding could flip
 * between requests. is_primary pins exactly one company per user as the
 * canonical home; User::company() now prefers it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_users', function (Blueprint $table) {
            $table->boolean('is_primary')->default(false)->after('user_id');
        });

        // Backfill: the lowest company_id per user becomes their primary,
        // matching the previous implicit ordering as closely as possible.
        // Done row-by-row in PHP so it runs identically on Postgres (prod)
        // and SQLite (tests) without window functions.
        $userIds = DB::table('company_users')->distinct()->pluck('user_id');

        foreach ($userIds as $userId) {
            $primaryPivotId = DB::table('company_users')
                ->where('user_id', $userId)
                ->orderBy('company_id')
                ->value('id');

            if ($primaryPivotId) {
                DB::table('company_users')->where('id', $primaryPivotId)->update(['is_primary' => true]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('company_users', function (Blueprint $table) {
            $table->dropColumn('is_primary');
        });
    }
};
