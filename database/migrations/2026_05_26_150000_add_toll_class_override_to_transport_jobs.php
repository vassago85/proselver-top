<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * advance_toll_class_override: per-trip SANRAL toll-class override that
 * wins over the vehicle's configured toll_class when computing tolls.
 *
 * Why we need this: SANRAL bands by axle count, not tonnage.  Trident's
 * vehicle class is a coarse weight bucket (LCV / MCV / HCV / Extra Heavy)
 * and a real fleet has 2-axle HCVs that should be Class 2 alongside
 * 3-axle HCVs that should be Class 3.  Owner confirmed both happen in
 * the wild.  Ops sees the auto-suggested class on the advance modal
 * and overrides per trip when the truck on the road doesn't match the
 * default.
 *
 * Values are 1-4 (SANRAL bands).  NULL means "use the vehicle class
 * default" -- which is the current behaviour, so existing rows stay
 * untouched.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->unsignedTinyInteger('advance_toll_class_override')->nullable()->after('advance_toll_breakdown');
        });
    }

    public function down(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->dropColumn('advance_toll_class_override');
        });
    }
};
