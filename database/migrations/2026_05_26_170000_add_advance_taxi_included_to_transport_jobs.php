<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * advance_taxi_included: opt-in flag for the taxi allowance.
 *
 * Owner clarified the taxi line isn't a given -- sometimes the driver
 * takes a company shuttle (no out-of-pocket) instead of a taxi.  Ops
 * ticks the box when the driver actually needs cash for one.
 *
 * Default false (opt-in).  The modal pre-fills R50 only when the box
 * is ticked; otherwise the taxi line is R0.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->boolean('advance_taxi_included')->default(false)->after('advance_taxi');
        });
    }

    public function down(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->dropColumn('advance_taxi_included');
        });
    }
};
