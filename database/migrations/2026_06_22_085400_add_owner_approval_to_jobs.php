<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Owner-approval gate for jobs.
 *
 * Body builders can now place direct orders with Proselver (the BB is
 * the paying customer on the job).  When the VIN matches a vehicle on
 * an existing dealer's stock ledger, that dealer is the OWNER of the
 * vehicle and must be made aware -- and OK the move -- before
 * dispatch can roll.
 *
 *   - owner_company_id            -- the dealer whose vehicle this is
 *   - requires_owner_approval     -- gate flag; false for normal jobs
 *   - owner_approval_status       -- pending|approved|rejected
 *   - owner_approved_at / by      -- audit trail
 *   - owner_decision_notes        -- optional dealer comment on
 *                                    reject / approve
 *
 * The fields are nullable so the (much larger) population of normal
 * dealer / OEM bookings is untouched.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->foreignId('owner_company_id')->nullable()
                ->constrained('companies')->nullOnDelete();
            $table->boolean('requires_owner_approval')->default(false);
            // 'pending' on creation if requires_owner_approval is true;
            // 'approved' or 'rejected' once the owner has decided.  Null
            // for jobs that don't need approval at all.
            $table->string('owner_approval_status', 20)->nullable();
            $table->timestamp('owner_approved_at')->nullable();
            $table->foreignId('owner_approved_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->text('owner_decision_notes')->nullable();

            $table->index(['owner_company_id', 'owner_approval_status'], 'transport_jobs_owner_approval_idx');
        });
    }

    public function down(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->dropIndex('transport_jobs_owner_approval_idx');
            $table->dropConstrainedForeignId('owner_approved_by_user_id');
            $table->dropColumn(['owner_decision_notes', 'owner_approved_at', 'owner_approval_status', 'requires_owner_approval']);
            $table->dropConstrainedForeignId('owner_company_id');
        });
    }
};
