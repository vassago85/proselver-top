<?php

use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Migrations\Migration;

/*
 * Bolt the new bb_place_direct_order + owner_approve_movement
 * permissions (and their role wiring) onto every environment that
 * runs migrations.  Re-running the canonical PermissionSeeder is
 * safe: every insert is firstOrCreate, every role->permissions()
 * link uses syncWithoutDetaching.
 *
 * Without this migration the new permissions would only land on
 * environments where someone remembers to re-run db:seed --class=
 * PermissionSeeder, which is exactly the kind of step that gets
 * forgotten in a deploy.
 */
return new class extends Migration {
    public function up(): void
    {
        (new PermissionSeeder)->run();
    }

    public function down(): void
    {
        // Permissions are forward-only -- we never strip role grants
        // on a rollback because we'd be guessing what the operator's
        // baseline role config was before the migration ran.
    }
};
