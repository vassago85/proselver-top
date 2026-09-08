<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery SLA plumbing for the ops dashboard on-time panel.
 *
 * WHY THIS IS SEPARATE FROM `companies.collection_sla_days`
 * ---------------------------------------------------------
 * Collection SLA answers "how long does the CUSTOMER have to collect
 * from us" and belongs on the OEM row. The ops dashboard needs the
 * opposite question — "how long do WE have to deliver to the
 * customer once we've collected". The two figures come from
 * different clauses in different contracts, so they get their own
 * columns rather than one repurposed field.
 *
 * Resolution order for a job's promised delivery moment (implemented
 * in the OnTimeQuery, not here):
 *
 *   1. `transport_jobs.promised_delivery_at`    — explicit override
 *   2. `collected_at + transport_jobs.sla_hours` — per-job SLA
 *   3. `collected_at + companies.default_sla_hours` — per-customer
 *   4. null — coverage counts as "not measurable" and the panel
 *              shows the gap rather than a misleading %.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->timestamp('promised_delivery_at')->nullable()
                ->after('delivered_at')
                ->comment('Explicit committed delivery moment; overrides SLA-based derivation.');
            $table->unsignedSmallInteger('sla_hours')->nullable()
                ->after('promised_delivery_at')
                ->comment('Per-job delivery SLA in hours from collected_at. Overrides company default.');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedSmallInteger('default_sla_hours')->nullable()
                ->after('collection_sla_days')
                ->comment('Delivery SLA fallback for jobs this company is the customer on. Hours from collected_at.');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('default_sla_hours');
        });

        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->dropColumn(['promised_delivery_at', 'sla_hours']);
        });
    }
};
