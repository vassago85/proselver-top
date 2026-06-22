<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * OEM-direct arrivals at body-builders.
 *
 * Today every dealer_stock row has a mandatory dealer_company_id, but
 * the workflow we just learned about is: OEM dispatches a chassis
 * straight to a body builder; the BB receives the truck but doesn't
 * yet know which dealer it's destined for.  They need to be able to
 * record the arrival and assign it to a dealer later.
 *
 * Two changes:
 *
 *   1. dealer_company_id becomes nullable.  Existing indexes on
 *      (dealer_company_id, ...) still work -- they just don't help
 *      for unassigned rows (which is fine, the unassigned pool is
 *      small).
 *
 *   2. oem_company_id (nullable FK to companies) records who sent
 *      the chassis.  Lets the BB and ops see "this Isuzu turned up
 *      from PE plant" while the dealer is still being identified.
 *
 * No partial-unique index added: the application path
 * (DealerStock::createUnassigned + assignToDealer in the model) is
 * the single choke-point for unassigned rows, so we enforce
 * one-row-per-VIN there.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('dealer_stock', function (Blueprint $table) {
            // Laravel 12's Schema builder rebuilds SQLite tables
            // under the hood for ALTER COLUMN, so this works on both
            // the production Postgres and the SQLite test driver.
            $table->foreignId('dealer_company_id')->nullable()->change();
        });

        Schema::table('dealer_stock', function (Blueprint $table) {
            $table->foreignId('oem_company_id')
                ->nullable()
                ->after('dealer_company_id')
                ->constrained('companies')
                ->nullOnDelete();

            $table->index('oem_company_id');
        });
    }

    public function down(): void
    {
        Schema::table('dealer_stock', function (Blueprint $table) {
            $table->dropIndex(['oem_company_id']);
            $table->dropForeign(['oem_company_id']);
            $table->dropColumn('oem_company_id');
        });

        // Not reverting dealer_company_id back to NOT NULL on
        // rollback -- if you've shipped unassigned rows you can't
        // back-fill them without manual intervention.
    }
};
