<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Adds the "executor" model to every job: who is actually moving the
 * vehicle? Four mutually-exclusive options:
 *
 *   - proselver    (default for every pre-existing row — backfilled below)
 *   - internal     (the booking customer is using their own driver)
 *   - third_party  (the booking customer engaged a courier; light tracking)
 *   - self_collect (the end-customer is collecting; no driver involved)
 *
 * The driver_user_id column already exists and stays the source of truth
 * for "who is driving" when the executor is proselver or internal. The
 * third_party_* and self_collect_* columns capture the light-tracking
 * info we agreed to keep (courier name + waybill + expected date for
 * third-party; collector name + phone + ID for self-collect).
 *
 * archived_at is the "vehicle has left the dealer's books" flag — once
 * a final delivery is done the dealer can archive it and hide it from
 * the active orders list while keeping the row visible to reports.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            // String column rather than a Postgres ENUM type. Postgres
            // enums are a pain to alter (drop+recreate cascade) and we
            // expect to extend this set over time (e.g. add 'auction'
            // or 'manufacturer' executors later). Plain VARCHAR with a
            // default + app-side validation gives us the same data
            // hygiene without the schema-migration tax.
            $table->string('executor_type', 20)
                ->default('proselver')
                ->after('executing_company_id');

            $table->string('third_party_courier_name')->nullable()->after('executor_type');
            $table->string('third_party_waybill')->nullable()->after('third_party_courier_name');
            $table->date('third_party_expected_date')->nullable()->after('third_party_waybill');

            $table->string('self_collect_name')->nullable()->after('third_party_expected_date');
            $table->string('self_collect_phone', 50)->nullable()->after('self_collect_name');
            $table->string('self_collect_id_number', 50)->nullable()->after('self_collect_phone');

            $table->timestamp('archived_at')->nullable()->after('cancelled_at');

            // Indexes that match the queries the new dealer surfaces will run:
            //  - "show my dealer's jobs by executor type" (executors list,
            //    External Executors dashboard card, executor filters)
            //  - "show my dealer's archived (or non-archived) jobs"
            $table->index(['company_id', 'executor_type']);
            $table->index(['company_id', 'archived_at']);
        });

        // Backfill: every existing row predates the executor model so it
        // must be a proselver-executed job. The column default handles
        // future inserts, but explicit UPDATE keeps the data consistent
        // even on databases where the default was added after the row
        // (none today, but cheap insurance).
        DB::table('transport_jobs')
            ->whereNull('executor_type')
            ->orWhere('executor_type', '')
            ->update(['executor_type' => 'proselver']);
    }

    public function down(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'archived_at']);
            $table->dropIndex(['company_id', 'executor_type']);

            $table->dropColumn([
                'archived_at',
                'self_collect_id_number',
                'self_collect_phone',
                'self_collect_name',
                'third_party_expected_date',
                'third_party_waybill',
                'third_party_courier_name',
                'executor_type',
            ]);
        });
    }
};
