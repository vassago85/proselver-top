<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Damage reports are no longer available to customers the second a
 * driver uploads a damage photo. An operator (ops manager, owner, etc.)
 * must review the photos + driver note and explicitly release the
 * report before the customer can download it.
 *
 * damage_report_released_at  — null while pending review; stamped at
 *                              the moment ops clicked "Release to
 *                              customer". Nullable so old jobs with no
 *                              damage stay untouched.
 * damage_report_released_by  — FK on users so audit trail names the
 *                              operator who authorised the release.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->timestamp('damage_report_released_at')->nullable()->after('invoiced_at');
            $table->foreignId('damage_report_released_by')
                ->nullable()
                ->after('damage_report_released_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('damage_report_released_by');
            $table->dropColumn('damage_report_released_at');
        });
    }
};
