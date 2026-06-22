<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Per-VIN body-builder metadata on dealer_stock.
 *
 * `bb_share_salesperson`, `bb_share_end_customer`, `bb_build_notes` are
 * dealer-controlled opt-ins.  Dealer toggles `bb_share_with_body_builder`
 * on the stock card; if true, the BB sees the metadata when the vehicle
 * arrives at their workshop.  Default false -- dealers are NOT forced
 * to share customer info with every BB.
 *
 * `bb_internal_job_number` is the OPPOSITE direction -- the BB writes
 * their own job number against the vehicle, and the dealer reads it.
 * One column per VIN (decision: option "stock"), so it persists across
 * future movements to the same BB.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('dealer_stock', function (Blueprint $table) {
            // Dealer share opt-in -- the dealer decides per VIN whether
            // the BB should see who sold it / who's buying it / build notes.
            $table->boolean('bb_share_with_body_builder')->default(false)->after('archived_at');

            // Salesperson + end customer can already live in the
            // sale_* columns, but those are commercial fields the
            // dealer keeps regardless of BB share.  The bb_share_*
            // columns are the snapshot the BB sees if the toggle is on,
            // letting the dealer tweak the display without overwriting
            // the commercial record.
            $table->string('bb_share_salesperson', 120)->nullable()->after('bb_share_with_body_builder');
            $table->string('bb_share_end_customer', 200)->nullable()->after('bb_share_salesperson');
            $table->text('bb_build_notes')->nullable()->after('bb_share_end_customer');

            // BB's own internal job number for this vehicle.  BB only
            // can edit; dealer can read.  Indexed because BBs may want
            // to search by their own number from the yard UI.
            $table->string('bb_internal_job_number', 80)->nullable()->after('bb_build_notes');
            $table->index('bb_internal_job_number');
        });
    }

    public function down(): void
    {
        Schema::table('dealer_stock', function (Blueprint $table) {
            $table->dropIndex(['bb_internal_job_number']);
            $table->dropColumn([
                'bb_share_with_body_builder',
                'bb_share_salesperson',
                'bb_share_end_customer',
                'bb_build_notes',
                'bb_internal_job_number',
            ]);
        });
    }
};
