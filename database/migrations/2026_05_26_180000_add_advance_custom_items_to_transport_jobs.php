<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * advance_custom_items: free-form petty-cash line items beyond the
 * four predefined buckets.
 *
 * Why: the four built-in buckets (tolls / accommodation / taxi / food)
 * don't cover every cash item a driver might face -- bridge fees,
 * customs clearance, escort fees, permits, vehicle wash, depot
 * parking, etc.  Rather than expand the schema with a column per
 * type, we let ops type custom line items per trip.
 *
 * Stored shape (one row per item, json array on the job):
 *   [
 *     { "label": "Customs clearance", "amount": 250.00, "needs_slip": true },
 *     { "label": "Escort fee",        "amount": 800.00, "needs_slip": true }
 *   ]
 *
 * Labels are remembered per customer company (in
 * companies.movement_csv_mapping['custom_petty_cash_labels']) so a
 * repeat trip for the same customer can auto-suggest previously used
 * labels.  Reconciliation rolls these into the variance table.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->json('advance_custom_items')->nullable()->after('advance_food_waived');
        });
    }

    public function down(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->dropColumn('advance_custom_items');
        });
    }
};
