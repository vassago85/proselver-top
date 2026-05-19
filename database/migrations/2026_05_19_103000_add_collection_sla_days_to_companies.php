<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-OEM collection SLA: how many calendar days a job has from
 * created_at until the dealer / transporter must collect the vehicle.
 *
 * Real-world contracts in use today (May 2026):
 *   FAW   = 7 days
 *   Isuzu = 3 days
 *
 * Stored on the OEM's `companies` row so the rule lives with the
 * counterparty. Job::collectionDeadline() reads it via the booking
 * company. Null = no SLA (no badge, no overdue trigger).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedSmallInteger('collection_sla_days')
                ->nullable()
                ->after('workflow_type');
        });

        // Backfill the known contracts.  Matched on normalized_name
        // LIKE so "FAW South Africa", "FAW SA", "FAW South Africa Pty
        // Ltd" all pick up the same SLA without needing the exact
        // company name on file.
        DB::table('companies')
            ->where('normalized_name', 'like', '%faw%')
            ->where('type', 'oem')
            ->update(['collection_sla_days' => 7]);

        DB::table('companies')
            ->where('normalized_name', 'like', '%isuzu%')
            ->where('type', 'oem')
            ->update(['collection_sla_days' => 3]);
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('collection_sla_days');
        });
    }
};
