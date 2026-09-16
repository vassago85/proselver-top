<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stale-action snooze columns for the ops login gate.
 *
 * The ops-landing modal (`StaleActionJobsQuery` + operations dashboard)
 * blocks the user's own jobs when they have been sitting in the same
 * stage for `trident.stale_action_days` (7). "Wait longer" writes
 * `stale_action_snoozed_until = now() + trident.stale_action_snooze_days`
 * so the job drops off the must-action list for a further week; the
 * companion `_comment`, `_by_user_id`, `_at` columns capture WHY and
 * WHO for the audit trail on the order timeline.
 *
 * Any real status transition clears every one of these fields via the
 * `Job::booted()` updating hook, so a snooze can never survive a
 * genuine move and re-appear later as ghost state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->timestamp('stale_action_snoozed_until')->nullable()
                ->after('archived_at')
                ->comment('Ops login gate: hide from must-action list until this moment.');
            $table->text('stale_action_snooze_comment')->nullable()
                ->after('stale_action_snoozed_until')
                ->comment('Reason ops gave for waiting longer on this job.');
            $table->foreignId('stale_action_snoozed_by_user_id')->nullable()
                ->after('stale_action_snooze_comment')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('stale_action_snoozed_at')->nullable()
                ->after('stale_action_snoozed_by_user_id')
                ->comment('When the snooze was recorded (audit).');
        });

        // The gate query filters on `stale_action_snoozed_until IS NULL
        // OR stale_action_snoozed_until < NOW()`, joined with the active
        // status set. A plain index on the snooze column is enough — the
        // status index from the phase-1 dwell migration already covers
        // the hot half of the predicate.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE INDEX IF NOT EXISTS transport_jobs_stale_snoozed_idx '
                . 'ON transport_jobs (stale_action_snoozed_until) '
                . 'WHERE deleted_at IS NULL'
            );
        } else {
            Schema::table('transport_jobs', function (Blueprint $table) {
                $table->index('stale_action_snoozed_until', 'transport_jobs_stale_snoozed_idx');
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS transport_jobs_stale_snoozed_idx');
        } else {
            Schema::table('transport_jobs', function (Blueprint $table) {
                $table->dropIndex('transport_jobs_stale_snoozed_idx');
            });
        }

        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stale_action_snoozed_by_user_id');
            $table->dropColumn([
                'stale_action_snoozed_until',
                'stale_action_snooze_comment',
                'stale_action_snoozed_at',
            ]);
        });
    }
};
