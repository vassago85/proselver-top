<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distinct "issued to driver" timestamp on transport_jobs.
 *
 * Lifecycle now has three discrete moments:
 *   1. advance_assigned_at  -- ops decided the breakdown + amount.
 *   2. advance_approved_at  -- owner signed off the plan it belongs to.
 *   3. advance_issued_at    -- ops physically handed the cash to the
 *      driver (or the bank-send EFT was confirmed).  This new column.
 *
 * Keeping these three separate matters: the audit trail needs to show
 * "owner approved but ops hasn't actually paid out yet" vs "paid out".
 * Without #3 we conflated "decision committed" with "money out".
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->timestampTz('advance_issued_at')->nullable()->after('advance_approved_at');
            $table->foreignId('advance_issued_by_user_id')
                ->nullable()
                ->after('advance_issued_at')
                ->constrained('users')
                ->nullOnDelete();
            // Bank-send reference, cash receipt number, or similar.
            // Free-text; not validated; surfaces on the audit trail and
            // the per-vehicle PDF so the paper trail is complete.
            $table->string('advance_issue_reference')->nullable()->after('advance_issued_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('advance_issued_by_user_id');
            $table->dropColumn(['advance_issued_at', 'advance_issue_reference']);
        });
    }
};
