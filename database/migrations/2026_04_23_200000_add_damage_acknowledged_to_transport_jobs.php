<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dashboard "I've seen this" flag for damage incidents.
 *
 * Separate from damage_report_released_at (which is the customer-visibility
 * gate). Ack is set the first time an operator opens the order page for a
 * damaged job, or when they click the inline "Dismiss" on the dashboard.
 * Release implies ack too; the admin release action stamps both at once.
 *
 * Purpose: keeps the "Recent damage incidents" strip and the Damage Reports
 * tile clear of rows ops already has eyes on, without forcing them to hit
 * "Release to customer" before they're ready.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('transport_jobs')) {
            return;
        }

        Schema::table('transport_jobs', function (Blueprint $table) {
            if (!Schema::hasColumn('transport_jobs', 'damage_acknowledged_at')) {
                $table->timestamp('damage_acknowledged_at')->nullable()->after('damage_report_released_by');
            }
            if (!Schema::hasColumn('transport_jobs', 'damage_acknowledged_by')) {
                $table->foreignId('damage_acknowledged_by')
                    ->nullable()
                    ->after('damage_acknowledged_at')
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
            if (Schema::hasColumn('transport_jobs', 'damage_acknowledged_by')) {
                $table->dropConstrainedForeignId('damage_acknowledged_by');
            }
            if (Schema::hasColumn('transport_jobs', 'damage_acknowledged_at')) {
                $table->dropColumn('damage_acknowledged_at');
            }
        });
    }
};
