<?php

use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Migrations\Migration;

/*
 * owner_approve_movement was wired to dealer_principal / customer_admin
 * and friends when BB direct-order shipped, but customer_owner and
 * oem_admin were missed. That left a modern dealer owner unable to
 * approve a movement raised by a body builder against their own stock
 * while the legacy dealer_principal it replaces, and the subordinate
 * customer_admin, both could.
 *
 * The earlier seed migration has already run everywhere, so it won't
 * pick the corrected mapping up. Re-run the canonical seeder: every
 * insert is firstOrCreate and every role link uses
 * syncWithoutDetaching, so this only ever adds.
 */
return new class extends Migration {
    public function up(): void
    {
        (new PermissionSeeder)->run();
    }

    public function down(): void
    {
        // Permissions are forward-only -- we never strip role grants on a
        // rollback because we'd be guessing what the operator's baseline
        // role config was before the migration ran.
    }
};
