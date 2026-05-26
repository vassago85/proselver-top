<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pre-issue petty-cash plan + owner sign-off workflow.
 *
 * Ops picks tomorrow's (or any) trips, system snapshots each trip's
 * computed petty-cash breakdown into a plan, plan goes to the owner
 * for sign-off, owner approves or rejects.  Approved trips unlock the
 * "Issue advance" button on the order page; unapproved trips can't
 * be issued without ops recording an explicit override reason.
 *
 * Why a separate plans table (not just a flag on transport_jobs):
 *   - One plan can bundle many trips; the owner signs the bundle, not
 *     each trip in isolation.
 *   - The plan carries an immutable snapshot of what the breakdown
 *     LOOKED LIKE at sign-off time, separately from the live values
 *     on transport_jobs (which ops can still tweak).  If ops bumps
 *     a trip's advance after sign-off, the audit trail shows the
 *     drift from what the owner approved.
 *
 * Item snapshots live in JSON to keep the schema simple.  Each item:
 *   { "job_id": N, "job_number": "...", "computed_total": X.XX,
 *     "tolls": X, "accommodation": X, "taxi": X, "food": X,
 *     "custom_items": [...] }
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('petty_cash_plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Human label.  Defaults to "Pay-run for {date}" but ops
            // can rename to anything they want (e.g. "Friday weekend
            // batch", "Wakkerstroom return run").
            $table->string('label');

            // draft (ops still editing) -> pending (sent to owner) ->
            // approved | rejected.  No "revoked" -- if ops needs to
            // unstick an approved plan they create a new one and the
            // audit log carries the trail.
            $table->string('status', 20)->default('draft');

            // Snapshot of the bundle's total at sign-off (sum of items
            // at the moment the plan was created / re-snapshotted).
            // Separately tracked from items_json so a sort-by-total
            // query doesn't need to scan JSON.
            $table->decimal('total_amount', 12, 2)->default(0);

            // Item snapshots -- see the docblock for the schema.
            $table->json('items_json')->nullable();

            // Who created vs who approved/rejected.  Two distinct
            // signatures for the audit trail.
            $table->foreignId('generated_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestampTz('generated_at');

            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->text('sign_off_notes')->nullable();  // owner's comment on approve/reject

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'generated_at']);
        });

        // Per-job approval stamp -- which plan approved this trip's
        // advance, and when.  Nullable so trips can still be issued
        // standalone via an explicit override (with a recorded reason).
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->foreignId('advance_plan_id')->nullable()->after('advance_assigned_at')
                ->constrained('petty_cash_plans')->nullOnDelete();
            $table->timestampTz('advance_approved_at')->nullable()->after('advance_plan_id');
            // When ops issues without a plan, this reason is required
            // and surfaces alongside the change reason in the audit log.
            $table->text('advance_override_reason')->nullable()->after('advance_approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('advance_plan_id');
            $table->dropColumn(['advance_approved_at', 'advance_override_reason']);
        });
        Schema::dropIfExists('petty_cash_plans');
    }
};
