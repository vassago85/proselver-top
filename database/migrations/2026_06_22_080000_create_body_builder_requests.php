<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Dealer-initiated "add a new body builder" requests.
 *
 * Today only ops can create BB companies; a dealer who needs a new
 * fitment shop in the directory has to email / phone ProSelver and
 * wait for someone to add it.  This table holds the queue + audit
 * trail for that request:
 *
 *   1. Dealer submits a request via /customer/body-builders/requests/new
 *      with the BB's name + address + contact (and an optional note).
 *   2. The request lands in /admin/body-builder-requests in the
 *      "pending" pile.
 *   3. Ops decides: approve-as-new (mints a fresh body_builder Company
 *      + a body-builder Location seeded from the supplied address),
 *      merge-into-existing (points the dealer at an existing BB company
 *      that's clearly the same shop -- prevents another "Anchor Auto"
 *      / "ANCHOR AUTO BODY BUILDERS" duplicate), or reject.
 *   4. Approve | Merge auto-creates a body_builder_dealer_links row so
 *      the requesting dealer is immediately authorised against the BB
 *      without an extra round-trip.
 *
 * Soft delete kept off -- the resolved rows are useful audit and we
 * want them visible in the "Past requests" tab so dealers can see what
 * happened with their submission.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('body_builder_requests', function (Blueprint $table) {
            $table->id();

            // The dealer asking for the BB to exist.  CASCADE so cleaning
            // up a deleted dealer doesn't leave orphan request rows.
            $table->foreignId('dealer_company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            // Who at the dealer submitted it -- audit + so the dealer UI
            // can show "you submitted this" vs "your colleague did".
            $table->foreignId('requested_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // The BB the dealer is proposing.  These are TEXT fields,
            // not FK -- the BB doesn't exist as a Company yet.  Address
            // is the workshop premises, not billing.
            $table->string('proposed_name');
            $table->text('proposed_address')->nullable();
            $table->string('proposed_city', 120)->nullable();
            $table->string('proposed_province', 120)->nullable();
            $table->string('proposed_contact_name', 120)->nullable();
            $table->string('proposed_contact_phone', 60)->nullable();
            $table->string('proposed_contact_email', 160)->nullable();
            $table->text('dealer_notes')->nullable();

            // pending | approved | merged | rejected
            // (no draft state -- the form is simple enough that submit
            // is a single action.)
            $table->string('status', 24)->default('pending')->index();

            // Decision metadata.  Filled in by the ops UI.
            $table->foreignId('decided_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_notes')->nullable();

            // Set on `approved`: the Company the new BB was created as.
            // Set on `merged`:   the existing Company the request was
            // merged into.  Either way: this is the BB the dealer is
            // now linked to.
            $table->foreignId('resolved_body_builder_company_id')
                ->nullable()
                ->constrained('companies')
                ->nullOnDelete();

            $table->timestamps();

            // Frequent queries: ops queue is "pending across all
            // dealers"; dealer view is "everything I submitted".
            $table->index(['status', 'created_at']);
            $table->index(['dealer_company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('body_builder_requests');
    }
};
