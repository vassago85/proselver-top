<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Driver bus-ticket movements.
 *
 * When ops books a bus seat to get a driver from (say) Johannesburg
 * to Port Elizabeth to collect a vehicle, this is where the ticket is
 * recorded.  Lifecycle:
 *
 *   issued ─ driver boards + collects ─▶ used          (no payslip impact)
 *          \                            \
 *           \── driver no-show ─▶ not_used (voided          -- company absorbs
 *                                           | charged_to_driver -- deduct on payslip)
 *
 * The ticket is one row per driver per leg (no shared "bus manifest"
 * parent).  transport_job_id is optional and points at the collection
 * job the driver was being sent to, so the payslip can line the two
 * up and so operations can jump from the job back to the ticket.
 *
 * Monetary stored in cents to match PettyCashEntry.amount_cents --
 * the payslip reads both and adds them side-by-side.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_bus_tickets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('driver_user_id')->constrained('users')->cascadeOnDelete();
            // Optional link to the collection job this ticket was issued for.
            // nullOnDelete so a cancelled / purged job doesn't take the
            // ticket history down with it.
            $table->foreignId('transport_job_id')
                ->nullable()
                ->constrained('transport_jobs')
                ->nullOnDelete();

            $table->string('bus_company', 120)->nullable();
            $table->string('reference_number', 120)->nullable();

            // Origin / destination.  We allow EITHER a Location FK (so
            // the picker can seed the proper lat/long) OR a plain
            // string label -- not every bus stop in SA will be in the
            // locations table and we don't want ops blocked.
            $table->foreignId('origin_location_id')
                ->nullable()
                ->constrained('locations')
                ->nullOnDelete();
            $table->string('origin_label', 160)->nullable();
            $table->foreignId('destination_location_id')
                ->nullable()
                ->constrained('locations')
                ->nullOnDelete();
            $table->string('destination_label', 160)->nullable();

            $table->date('travel_date');
            $table->unsignedInteger('amount_cents')->default(0);

            // Lifecycle.  We use plain strings not database enums so
            // adding a new outcome in the future doesn't require an
            // ALTER TYPE on Postgres.
            $table->string('status', 20)->default('issued');
            $table->string('not_used_outcome', 32)->nullable();
            $table->text('not_used_reason')->nullable();

            $table->foreignId('resolved_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();

            $table->foreignId('created_by_user_id')
                ->constrained('users');

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Payslip reads by (driver, month).  Status index drives the
            // "open tickets to resolve" filter on the management page.
            $table->index(['driver_user_id', 'travel_date'], 'bus_tickets_driver_date_idx');
            $table->index('status', 'bus_tickets_status_idx');
            $table->index('transport_job_id', 'bus_tickets_job_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_bus_tickets');
    }
};
