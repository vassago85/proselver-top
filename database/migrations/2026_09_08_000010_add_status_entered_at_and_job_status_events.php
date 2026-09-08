<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ops dashboard Phase 1: dwell-time clock + status history.
 *
 * WHY THIS MIGRATION EXISTS
 * -------------------------
 * The exception buckets on the ops dashboard have been aging jobs on
 * `updated_at`, which is bumped by every save (invoice capture, note,
 * cost update). That resets the clock every time an ops or finance
 * user touches the row, so a job that has been stuck at
 * `dispatched_not_collected` for three days shows up as "1 minute
 * old" the moment somebody adjusts a cost line.
 *
 * `status_entered_at` fixes this: it is only bumped when the status
 * itself changes. The Job model has an observer that sets it on both
 * `creating` and `updating` when `isDirty('status')`.
 *
 * `job_status_events` is the audit-quality history of that same
 * transition, so the CFD chart and dwell distribution can be built
 * from ground truth instead of scraping per-status *_at columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->timestamp('status_entered_at')
                ->nullable()
                ->after('status')
                ->comment('When the current status was entered. Only bumped on status change.');
        });

        Schema::create('job_status_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')
                ->constrained('transport_jobs')
                ->cascadeOnDelete();
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40);
            $table->timestamp('entered_at')->useCurrent();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['job_id', 'entered_at']);
            $table->index(['to_status', 'entered_at']);
        });

        // Backfill status_entered_at from the best-known per-status
        // timestamp on the row, falling back to updated_at when the
        // matching column is null (rows created before the phase-1
        // restructure). This means dwell metrics have real data from
        // the day the migration ships, without having to wait for
        // every job to transition again.
        $backfill = <<<SQL
UPDATE transport_jobs
SET status_entered_at = COALESCE(
    CASE status
        WHEN 'delivered'                     THEN delivered_at
        WHEN 'in_transit'                    THEN in_transit_at
        WHEN 'collected'                     THEN collected_at
        WHEN 'ready_for_collection'          THEN ready_for_collection_at
        WHEN 'driver_assigned'               THEN assigned_at
        WHEN 'planned'                       THEN planned_at
        WHEN 'confirmed'                     THEN customer_confirmed_at
        WHEN 'completed'                     THEN completed_at
        WHEN 'cancelled'                     THEN cancelled_at
        WHEN 'in_progress'                   THEN started_at
        WHEN 'approved'                      THEN approved_at
        WHEN 'verified'                      THEN verified_at
        WHEN 'assigned'                      THEN assigned_at
        WHEN 'invoiced'                      THEN invoiced_at
    END,
    updated_at,
    created_at
);
SQL;

        DB::statement($backfill);

        // Partial index on Postgres for the hot dashboard query.
        // Other drivers (SQLite in tests, MySQL) get a plain index —
        // the partial predicate is a Postgres-only optimisation.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE INDEX IF NOT EXISTS transport_jobs_status_entered_idx '
                . 'ON transport_jobs (status, status_entered_at) '
                . 'WHERE deleted_at IS NULL'
            );
        } else {
            Schema::table('transport_jobs', function (Blueprint $table) {
                $table->index(['status', 'status_entered_at'], 'transport_jobs_status_entered_idx');
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS transport_jobs_status_entered_idx');
        } else {
            Schema::table('transport_jobs', function (Blueprint $table) {
                $table->dropIndex('transport_jobs_status_entered_idx');
            });
        }

        Schema::dropIfExists('job_status_events');

        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->dropColumn('status_entered_at');
        });
    }
};
