<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trip advance / petty-cash estimator foundations.
 *
 * Two pieces:
 *
 *  1. `route_estimates` — cache for Google Maps Directions responses,
 *     keyed by the pickup→delivery location pair.  The TripCostEstimator
 *     hits this table before calling Google so we don't re-bill the API
 *     for routes we've already calculated.  Tolls themselves are
 *     recomputed from current `toll_plazas` rows each time (fees change
 *     yearly), but the polyline + distance stay stable for the location
 *     pair.
 *
 *  2. Advance / petty-cash columns on `transport_jobs`.  Ops opens the
 *     "Petty Cash / Advance" panel on the order page, the estimator
 *     pre-fills toll cost from the detected plazas, ops types in
 *     accommodation / taxi / food quantities, and assigns the total as
 *     the driver's advance.  If the typed total is higher than the
 *     computed estimate, a reason is mandatory and an audit row goes in.
 *
 * The whole feature is optional in v1 — there's no NOT NULL constraint,
 * so jobs that ops never opens the panel for just don't get an advance.
 * The boss can later decide to mandate it.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('route_estimates', function (Blueprint $table) {
            $table->id();

            // The pickup/delivery pair this estimate is for. Cascading
            // delete is intentional — if either location is removed the
            // cached row is meaningless.
            $table->foreignId('pickup_location_id')->constrained('locations')->cascadeOnDelete();
            $table->foreignId('delivery_location_id')->constrained('locations')->cascadeOnDelete();

            $table->decimal('distance_km', 8, 2);
            $table->unsignedInteger('duration_minutes');

            // Encoded Google polyline. Used by RouteCalculationService::
            // detectTolls() to walk the route against toll_plazas; we
            // keep the encoded form rather than decoded points to save
            // space (a 600km trip is ~5KB encoded vs ~40KB decoded).
            $table->text('polyline');

            // Provenance — which engine produced this and when. Lets us
            // invalidate the cache later if we ever swap providers or
            // need to force a fresh fetch (e.g. road closures bake into
            // Google's response and we want yesterday's row dropped).
            $table->string('provider', 32)->default('google_maps');
            $table->timestampTz('calculated_at');

            $table->timestamps();

            // One canonical row per pair. Re-running for the same pair
            // updates in place instead of stacking duplicates.
            $table->unique(['pickup_location_id', 'delivery_location_id'], 'route_estimates_pair_unique');
        });

        Schema::table('transport_jobs', function (Blueprint $table) {
            // Computed-toll snapshot. We keep both the matched plaza list
            // (json) and the rand total at the moment the advance was set,
            // so the historical record survives plaza-fee changes — the
            // 2026 fees take effect, the 2025 advance stays accurate.
            $table->json('advance_toll_breakdown')->nullable()->after('estimated_toll_cost');
            $table->decimal('advance_tolls', 10, 2)->nullable()->after('advance_toll_breakdown');

            // Operator-typed quantities. No rate table in v1 — ops just
            // enters the rand amount they're handing the driver. Taxi
            // is the no-receipt-needed category (per ops policy); the
            // other two still expect slip evidence on reconciliation.
            $table->decimal('advance_accommodation', 10, 2)->nullable()->after('advance_tolls');
            $table->decimal('advance_taxi', 10, 2)->nullable()->after('advance_accommodation');
            $table->decimal('advance_food', 10, 2)->nullable()->after('advance_taxi');

            // The number ops actually committed as the driver advance.
            // Stored separately from the sum-of-parts so a future
            // "rounded up to R 2,000" lump-sum mode works without
            // restructuring the columns.
            $table->decimal('advance_total', 10, 2)->nullable()->after('advance_food');

            // If advance_total exceeds the auto-computed estimate
            // (tolls + accommodation + taxi + food), this reason is
            // required.  Free-text; the audit log carries the structured
            // before/after diff.
            $table->text('advance_increase_reason')->nullable()->after('advance_total');

            // Who/when assigned the advance. Distinct from created_by
            // because the advance is a separate operational decision and
            // we want it visible without joining the audit log.
            $table->foreignId('advance_assigned_by_user_id')
                ->nullable()
                ->after('advance_increase_reason')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestampTz('advance_assigned_at')->nullable()->after('advance_assigned_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('advance_assigned_by_user_id');
            $table->dropColumn([
                'advance_toll_breakdown',
                'advance_tolls',
                'advance_accommodation',
                'advance_taxi',
                'advance_food',
                'advance_total',
                'advance_increase_reason',
                'advance_assigned_at',
            ]);
        });

        Schema::dropIfExists('route_estimates');
    }
};
