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
    /**
     * Idempotent — this migration was originally written against a
     * non-existent `jobs` table. The real model table is
     * `transport_jobs`, so on prod it was logged as "migrated" without
     * actually adding the columns. The hasColumn guards below mean
     * re-running this (or running it for the first time after the fix)
     * is safe on every environment.
     */
    public function up(): void
    {
        if (!Schema::hasTable('transport_jobs')) {
            return;
        }

        Schema::table('transport_jobs', function (Blueprint $table) {
            if (!Schema::hasColumn('transport_jobs', 'damage_report_released_at')) {
                $table->timestamp('damage_report_released_at')->nullable()->after('invoiced_at');
            }
            if (!Schema::hasColumn('transport_jobs', 'damage_report_released_by')) {
                $table->foreignId('damage_report_released_by')
                    ->nullable()
                    ->after('damage_report_released_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('transport_jobs')) {
            return;
        }
        Schema::table('transport_jobs', function (Blueprint $table) {
            if (Schema::hasColumn('transport_jobs', 'damage_report_released_by')) {
                $table->dropConstrainedForeignId('damage_report_released_by');
            }
            if (Schema::hasColumn('transport_jobs', 'damage_report_released_at')) {
                $table->dropColumn('damage_report_released_at');
            }
        });
    }
};
