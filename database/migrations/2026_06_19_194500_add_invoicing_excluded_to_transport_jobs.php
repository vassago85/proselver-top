<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * "Not required to be invoiced" flag for the customer-invoicing page.
 *
 * Sometimes a delivered ProSelver movement should NEVER end up on a
 * customer invoice -- it might be an internal shuffle, a test run,
 * a goodwill move, or a write-off.  Owner/developer flips this and
 * the row drops out of the working list entirely + is excluded from
 * the FAW Excel export regardless of which view filter is active.
 *
 * Distinct from invoicing_completed_at which is "finance has finished
 * capturing this row" -- excluded rows are jobs we will never bill.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->timestampTz('invoicing_excluded_at')->nullable()->after('invoicing_completed_by_user_id');
            $table->foreignId('invoicing_excluded_by_user_id')
                ->nullable()
                ->after('invoicing_excluded_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->string('invoicing_excluded_reason', 255)->nullable()->after('invoicing_excluded_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invoicing_excluded_by_user_id');
            $table->dropColumn(['invoicing_excluded_at', 'invoicing_excluded_reason']);
        });
    }
};
