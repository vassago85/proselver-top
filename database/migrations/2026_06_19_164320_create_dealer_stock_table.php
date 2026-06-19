<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dealer stock ledger.  Each row is a single vehicle on a dealer's
 * floor (or at a body builder / yard / on demo / sold).  Populated
 * by:
 *   - the dealer's spreadsheet import (DealerStockImporter)
 *   - the DealerStockMovementLinker observer that watches
 *     transport_jobs events one-way (no edits to jobs)
 *   - dealer staff actions on the customer.stock.* Volt pages
 *     (Mark-as-sold, Send-on-demo, Return-from-demo, Archive)
 *
 * Note: NIV == VIN.  Dealers call it NIV; the platform calls it
 * VIN.  Single column on the schema to keep matching with
 * transport_jobs.vin trivial.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dealer_stock', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Tenancy.  Every row belongs to exactly one dealer.  A
            // franchise CEO sees rows from every sibling dealership
            // via DealerStock::scopeVisibleTo() + User::visibleCompanyIds().
            $table->foreignId('dealer_company_id')->constrained('companies')->cascadeOnDelete();

            // Identity.  VIN is the natural key per dealer.
            // engine_number is captured for SAPS/insurance work,
            // registration is null for vehicles still on stock plates.
            $table->string('vin', 50);
            $table->string('engine_number', 50)->nullable();
            $table->string('registration', 20)->nullable();

            // Vehicle attributes (import columns).
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->string('model_name')->nullable();
            $table->string('suffix', 50)->nullable();
            $table->string('variant', 100)->nullable();
            $table->string('description')->nullable();
            $table->string('colour', 50)->nullable();
            $table->unsignedSmallInteger('model_year')->nullable();

            // Current physical location bucket -- drives the six
            // dashboard cards.  Mirrors Job::DESTINATION_* where it
            // makes sense, plus an 'on_demo' bucket for vehicles with
            // a prospect.
            // Values: premises | body_builder | storage | in_transit
            //         | on_demo | delivered
            $table->string('current_location_type', 20)->default('premises');
            $table->foreignId('current_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('current_job_id')->nullable()->constrained('transport_jobs')->nullOnDelete();
            // Snapshot of current_location_type the row was in before
            // a movement started -- lets the linker / "Return from
            // demo" action restore the right bucket without
            // re-deriving it.
            $table->string('previous_location_type', 20)->nullable();

            // Commercial status -- independent of physical location.
            // Values: available | reserved | sold | demo | archived
            $table->string('status', 20)->default('available');

            // Sale assignment (populated when status -> sold).
            $table->foreignId('salesperson_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('sale_customer_name')->nullable();
            $table->string('sale_customer_phone', 50)->nullable();
            $table->string('sale_customer_email')->nullable();
            $table->timestamp('sold_at')->nullable();

            // Demo assignment (populated when status -> demo).
            $table->string('demo_customer_name')->nullable();
            $table->string('demo_customer_phone', 50)->nullable();
            $table->string('demo_customer_email')->nullable();
            $table->timestamp('demo_started_at')->nullable();
            $table->timestamp('demo_due_back_at')->nullable();

            // Lifecycle timestamps.
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            // Indexes for the dashboard count queries -- every card
            // hits one of these.
            $table->index(['dealer_company_id', 'current_location_type']);
            $table->index(['dealer_company_id', 'status']);
            $table->index(['dealer_company_id', 'sold_at']);
            // Cross-dealer lookup used by the movement linker.
            $table->index(['vin']);
            // Natural key per dealer -- the importer relies on this
            // for idempotent upserts.
            $table->unique(['dealer_company_id', 'vin']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dealer_stock');
    }
};
