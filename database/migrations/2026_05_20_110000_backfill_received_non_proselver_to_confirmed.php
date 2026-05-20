<?php

use App\Models\Job;
use App\Models\JobEvent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-off cleanup of orphaned RECEIVED non-Proselver orders.
 *
 * After this commit, new bookings whose executor is anything other
 * than ProSelver auto-land on STATUS_CONFIRMED -- the dealer's choice
 * of executor IS the dispatch decision, so the manual Confirm-Order
 * click is pure paperwork (see Job::initialStatusFor()). This
 * migration sweeps existing rows that pre-date the change so dealers
 * aren't left staring at a Confirm-Order button that asks them to
 * confirm a dispatch their own driver is going to handle.
 *
 * Scope is deliberately tight:
 *   - Only rows with status = RECEIVED at this moment in time.
 *   - Only rows whose executor is NOT proselver.
 *
 * Everything else (PENDING_VERIFICATION, AWAITING_CUSTOMER_CONFIRMATION,
 * already CONFIRMED, downstream statuses) stays untouched.
 *
 * Each touched row gets:
 *   - status flipped to CONFIRMED
 *   - customer_confirmed_at stamped with now() so the timeline doesn't
 *     show a confirmed order with no confirmation timestamp
 *   - a JobEvent breadcrumb so the order's audit trail explains the
 *     bulk flip rather than looking like a phantom status change
 */
return new class extends Migration
{
    public function up(): void
    {
        $jobIds = DB::table('transport_jobs')
            ->where('status', Job::STATUS_RECEIVED)
            ->whereIn('executor_type', [
                Job::EXECUTOR_INTERNAL,
                Job::EXECUTOR_THIRD_PARTY,
                Job::EXECUTOR_SELF_COLLECT,
            ])
            ->pluck('id');

        if ($jobIds->isEmpty()) {
            return;
        }

        $now = now();

        DB::table('transport_jobs')
            ->whereIn('id', $jobIds)
            ->update([
                'status'                => Job::STATUS_CONFIRMED,
                'customer_confirmed_at' => $now,
                'updated_at'            => $now,
            ]);

        // job_events.user_id is NOT NULL (->constrained() without
        // ->nullable()), so we need a real user for the breadcrumb
        // event. Pick the first super_admin / developer as the system
        // actor; if none exists (fresh install with only seed data),
        // fall back to the job's original creator. If even that is
        // null on a particular row, skip the event for that row --
        // the status flip is already persisted, the missing breadcrumb
        // is non-fatal and shouldn't block the rest of the migration.
        $systemActorId = DB::table('users')
            ->join('user_roles', 'users.id', '=', 'user_roles.user_id')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->whereIn('roles.slug', ['super_admin', 'developer'])
            ->where('users.is_active', true)
            ->value('users.id');

        $jobCreators = DB::table('transport_jobs')
            ->whereIn('id', $jobIds)
            ->pluck('created_by_user_id', 'id');

        $events = [];
        foreach ($jobIds as $id) {
            $actorId = $systemActorId ?? ($jobCreators[$id] ?? null);
            if ($actorId === null) {
                continue;
            }
            $events[] = [
                'job_id'     => $id,
                'event_type' => 'auto_confirmed_on_create',
                'event_at'   => $now,
                'user_id'    => $actorId,
                'notes'      => 'Backfilled: legacy RECEIVED order with non-ProSelver executor flipped to CONFIRMED by 2026_05_20 migration.',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Chunked insert so we don't blow past Postgres' parameter limit
        // if this migration ever runs against a large historical dataset.
        foreach (array_chunk($events, 500) as $chunk) {
            DB::table((new JobEvent)->getTable())->insert($chunk);
        }
    }

    public function down(): void
    {
        // Intentionally no-op. We can't reliably tell which CONFIRMED rows
        // came from this backfill vs. legitimate confirmations, and
        // reverting them en masse would re-introduce the very ghost
        // RECEIVED orders this migration cleans up.
    }
};
