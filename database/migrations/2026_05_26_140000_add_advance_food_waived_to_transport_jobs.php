<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * advance_food_waived: ops explicitly removed the food allowance for
 * this trip.  Distinct from "typed zero" because the waiver is the
 * deliberate signal we audit ("this trip didn't qualify -- driver was
 * home for lunch"), whereas a zero-typed value just means ops hadn't
 * filled it in yet.
 *
 * Default false so every existing job carries the original behaviour
 * forward (food = days × rate).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->boolean('advance_food_waived')->default(false)->after('advance_food');
        });
    }

    public function down(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->dropColumn('advance_food_waived');
        });
    }
};
