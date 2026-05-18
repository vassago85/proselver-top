<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Body-builder portal schema.
 *
 * Two new tables:
 *
 *   1. `body_builder_dealer_links` — many-to-many between dealer
 *      companies and body-builder companies.  A dealer can authorise
 *      one or more BB companies to confirm receipts and raise movement
 *      requests against the dealer's vehicles.  A BB can serve many
 *      dealers.  Soft is_active flag instead of hard delete so we can
 *      reactivate without losing historical request audit.
 *
 *   2. `movement_requests` — a BB-initiated request for a follow-up
 *      movement (next fitment or collection-back).  Created in
 *      `pending`, decided by a dealer planner, and when approved the
 *      service layer mints a real transport_jobs row and stores the
 *      FK on `created_job_id` so the request is permanently linked
 *      back to the job it created.
 *
 * No backfill: BBs only exist once a dealer links one, and only then
 * can requests appear.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('body_builder_dealer_links', function (Blueprint $table) {
            $table->id();

            $table->foreignId('dealer_company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            $table->foreignId('body_builder_company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            // Who at the dealer side initiated the link — useful for
            // audit / "who added X to our approved BB list?" without
            // needing a separate audit table for this lookup.
            $table->foreignId('linked_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Soft toggle. A dealer can pause / resume the link without
            // losing history of past requests.  We do NOT use SoftDeletes
            // here because we want to *display* deactivated links in the
            // dealer UI ("paused — click to reactivate").
            $table->boolean('is_active')->default(true);

            $table->text('notes')->nullable();

            $table->timestamps();

            // One dealer can only link a given BB once. The is_active
            // flag is the toggle; re-adding a deactivated link should
            // reactivate it, not create a duplicate row.
            $table->unique(['dealer_company_id', 'body_builder_company_id'], 'bb_dealer_link_unique');
            $table->index(['body_builder_company_id', 'is_active'], 'bb_dealer_link_bb_active_idx');
            $table->index(['dealer_company_id', 'is_active'], 'bb_dealer_link_dealer_active_idx');
        });

        Schema::create('movement_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Who is asking (the BB) and who is being asked (the dealer
            // that owns the inventory).  Both are companies, both
            // required — a request is a triangle: BB → dealer → new job.
            $table->foreignId('requesting_company_id')
                ->constrained('companies')
                ->cascadeOnDelete();
            $table->foreignId('target_company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            // The BB user who raised the request.  Nullable so requests
            // survive their author leaving the BB.
            $table->foreignId('requesting_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // The job that delivered the vehicle to the BB in the first
            // place — required for normal flows so we can copy the VIN
            // / brand / class / original-pickup forward, but kept
            // nullable for one-off "we have a unit here, please collect"
            // requests where the source job isn't in TRIDENT.
            $table->foreignId('source_job_id')
                ->nullable()
                ->constrained('transport_jobs')
                ->nullOnDelete();

            // next_move = BB → another fitment / facility.
            // collection = BB → back to dealer / customer.
            $table->string('request_type', 32);

            // Movement details the BB is requesting.  The dealer can
            // edit before approving, so these are best-effort defaults.
            // Pickup is normally the BB's location; delivery is what
            // the BB selected from the picker.
            $table->foreignId('pickup_location_id')
                ->nullable()
                ->constrained('locations')
                ->nullOnDelete();
            $table->foreignId('delivery_location_id')
                ->nullable()
                ->constrained('locations')
                ->nullOnDelete();

            $table->foreignId('vehicle_class_id')
                ->nullable()
                ->constrained('vehicle_classes')
                ->nullOnDelete();
            $table->foreignId('brand_id')
                ->nullable()
                ->constrained('brands')
                ->nullOnDelete();

            $table->string('vin', 50)->nullable();
            $table->string('registration', 20)->nullable();
            $table->string('model_name', 255)->nullable();

            $table->date('requested_date')->nullable();
            $table->text('notes')->nullable();

            // pending → approved | rejected | cancelled.  String column
            // (not enum) for the same forward-compat reason as
            // job.executor_type — adds a new state without ALTER TABLE.
            $table->string('status', 32)->default('pending');

            // Decision metadata: dealer planner who decided, when, why.
            $table->foreignId('decided_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_notes')->nullable();

            // Set on approval — the FK to the transport_jobs row this
            // request created.  Reading the request page later shows
            // "approved, became JOB-12345" with a click-through link.
            $table->foreignId('created_job_id')
                ->nullable()
                ->constrained('transport_jobs')
                ->nullOnDelete();

            $table->timestamps();

            // Indexes drive the dealer's pending-requests queue, the
            // BB's "my requests" page, and the source-job sidebar link
            // ("this job has 1 follow-up request").
            $table->index(['target_company_id', 'status'], 'mvreq_dealer_status_idx');
            $table->index(['requesting_company_id', 'status'], 'mvreq_bb_status_idx');
            $table->index(['source_job_id'], 'mvreq_source_job_idx');
            $table->index(['status', 'created_at'], 'mvreq_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movement_requests');
        Schema::dropIfExists('body_builder_dealer_links');
    }
};
