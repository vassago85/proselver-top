<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a per-completed-movement pay rate to driver profiles so
 * accounts can run the month-end salary calculation without a
 * side spreadsheet.  The value is stored in cents to match how
 * we already store money elsewhere (PettyCashEntry.amount_cents,
 * ProselverLicenceBilling rates).  Nullable — legacy drivers
 * whose pay isn't set here yet render as "—" on the pay report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->unsignedInteger('rate_per_movement_cents')->nullable()->after('base_location');
        });
    }

    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropColumn('rate_per_movement_cents');
        });
    }
};
